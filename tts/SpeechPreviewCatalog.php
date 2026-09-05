<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

/**
 * Build the connector and installed-voice choices offered by the TTS Studio pronunciation
 * preview. The management page and the preview endpoint read the same list, so a selection
 * the browser can make is exactly a selection the server will accept.
 */
final class SpeechPreviewCatalog
{
    /** Previews speak one dictionary term or one spoken form, never a dialogue line. */
    public const MAX_TEXT_LENGTH = 240;
    private const MAX_CONNECTORS = 100;
    private const MAX_VOICES = 500;
    private const MAX_VOICE_LENGTH = 512;

    /**
     * @param list<array<string,mixed>> $presets saved `tts_provider` rows keyed by `configuration_id` or `id`
     * @param list<array<string,mixed>> $catalogVoices explicitly discovered provider voices
     * @param string $narratorVoice the configured narrator voice id of this installation, if any
     * @param string $narratorConnectorId the narrator's configured TTS connector, if any
     * @return array{connectors:list<array{id:string,label:string,driver:string,voices:list<string>}>,
     *     voices:list<string>,default_connector_id:string,default_voice:string}
     */
    public static function options(array $presets, array $catalogVoices, string $voiceRoot,
        string $preferredConnectorId = '', string $narratorVoice = '', string $narratorConnectorId = ''): array
    {
        $localSamples = self::localSamples($voiceRoot);
        $connectors = [];
        foreach ($presets as $preset) {
            if (!is_array($preset)) continue;
            $id = trim((string) ($preset['configuration_id'] ?? $preset['id'] ?? ''));
            $content = self::content($preset);
            $driver = trim((string) ($content['driver'] ?? ''));
            if ($id === '' || $driver === '' || isset($connectors[$id])) continue;
            try {
                $driverLabel = (string) ConnectorCatalog::definition('tts_provider', $driver)['label'];
            } catch (InvalidArgumentException) {
                // A connector whose driver the runtime cannot build is never previewable.
                continue;
            }
            // Local samples first: they are the voices this server owns rather than ones a
            // provider merely reported during an explicit discovery run. They only belong to
            // drivers that read this server's sample library; every other driver needs the
            // provider's own voice id, so offering a sample name there would always fail.
            $voices = [];
            if (in_array($driver, ConnectorCatalog::SAMPLE_LIBRARY_TTS_DRIVERS, true)) {
                foreach ($localSamples as $voice) self::collect($voices, $voice);
            }
            self::collectForDriver($voices, (string) ($content['voice'] ?? ''), $driver);
            foreach ($catalogVoices as $row) {
                if (!is_array($row)) continue;
                if (trim((string) ($row['configuration_id'] ?? '')) !== $id) continue;
                self::collectForDriver($voices, (string) ($row['id'] ?? ''), $driver);
            }
            // Without a voice this connector has nothing to speak with, so it is not offered.
            if ($voices === []) continue;
            natcasesort($voices);
            $name = trim((string) ($preset['name'] ?? ''));
            $connectors[$id] = ['id' => $id, 'label' => ($name === '' ? $driverLabel : $name . ' (' . $driverLabel . ')'),
                'driver' => $driver, 'voices' => array_slice(array_values($voices), 0, self::MAX_VOICES)];
            if (count($connectors) >= self::MAX_CONNECTORS) break;
        }

        // Without a connector there is nothing to speak through, so no voice is offered either.
        if ($connectors === []) return ['connectors' => [], 'voices' => [], 'default_connector_id' => '', 'default_voice' => ''];

        $defaultConnectorId = isset($connectors[$narratorConnectorId]) ? $narratorConnectorId
            : (isset($connectors[$preferredConnectorId]) ? $preferredConnectorId : (string) array_key_first($connectors));
        $voices = $connectors[$defaultConnectorId]['voices'];
        // The narrator voice makes the page testable with the voice LORKHAN narrates in, but only
        // this connector's own catalog may be selected for it, so an unknown narrator voice falls
        // back to the connector default and then to its first valid voice.
        $defaultVoice = self::match($voices, $narratorVoice);
        if ($defaultVoice === '') $defaultVoice = self::match($voices, self::voiceFor($presets, $defaultConnectorId));
        if ($defaultVoice === '') $defaultVoice = (string) $voices[0];

        return ['connectors' => array_values($connectors), 'voices' => $voices,
            'default_connector_id' => $defaultConnectorId, 'default_voice' => $defaultVoice];
    }

    /**
     * Resolve the configured narrator voice of one installation from its narrator profile
     * document, which stores it as `voice.id` beside the persona fields.
     */
    public static function narratorVoice(?array $narratorProfile): string
    {
        $content = is_array($narratorProfile['content'] ?? null) ? $narratorProfile['content'] : [];
        $voice = is_array($content['voice'] ?? null) ? $content['voice'] : [];
        return trim((string) ($voice['id'] ?? ''));
    }

    /** Resolve the narrator's explicit TTS connector without inventing a separate preview setting. */
    public static function narratorConnector(?array $narratorProfile): string
    {
        $content = is_array($narratorProfile['content'] ?? null) ? $narratorProfile['content'] : [];
        $routing = is_array($content['routing'] ?? null) ? $content['routing'] : [];
        return trim((string) ($routing['tts_configuration_id'] ?? ''));
    }

    /** Return the offered spelling of one wanted voice, or an empty string when it is not offered. */
    private static function match(array $voices, string $wanted): string
    {
        $wanted = trim($wanted);
        if ($wanted === '') return '';
        foreach ($voices as $voice) if (strcasecmp((string) $voice, $wanted) === 0) return (string) $voice;
        return '';
    }

    /** Resolve the configured default voice of one saved connector. */
    private static function voiceFor(array $presets, string $configurationId): string
    {
        if ($configurationId === '') return '';
        foreach ($presets as $preset) {
            if (!is_array($preset)) continue;
            if (trim((string) ($preset['configuration_id'] ?? $preset['id'] ?? '')) !== $configurationId) continue;
            $content = self::content($preset);
            return (string) ($content['voice'] ?? '');
        }
        return '';
    }

    /** Normalize the decoded UI rows and JSON-backed product repository rows to one shape. */
    private static function content(array $preset): array
    {
        $content = $preset['content'] ?? null;
        if (is_array($content)) return $content;
        if (!is_string($content) || $content === '') return [];
        $decoded = json_decode($content, true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private static function localSamples(string $voiceRoot): array
    {
        $voiceRoot = rtrim(trim($voiceRoot), DIRECTORY_SEPARATOR . '/');
        if ($voiceRoot === '' || !is_dir($voiceRoot)) return [];
        $names = [];
        foreach (glob($voiceRoot . DIRECTORY_SEPARATOR . '*.wav') ?: [] as $path) $names[] = pathinfo($path, PATHINFO_FILENAME);
        return $names;
    }

    /** Keep one bounded, printable spelling per voice so the select and the allowlist agree. */
    private static function collect(array &$voices, string $voice): void
    {
        $voice = trim($voice);
        if ($voice === '' || strlen($voice) > self::MAX_VOICE_LENGTH || !mb_check_encoding($voice, 'UTF-8')) return;
        if (preg_match('/[\x00-\x1F\x7F]/', $voice) === 1) return;
        $key = mb_strtolower($voice, 'UTF-8');
        if (!isset($voices[$key]) && count($voices) < self::MAX_VOICES) $voices[$key] = $voice;
    }

    /** Inworld accepts workspace-qualified provider ids; raw game/sample labels always fail. */
    private static function collectForDriver(array &$voices, string $voice, string $driver): void
    {
        $voice = trim($voice);
        if ($driver === 'inworld' && !str_contains($voice, '__')) return;
        self::collect($voices, $voice);
    }
}
