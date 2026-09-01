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
     * @return array{connectors:list<array{id:string,label:string,driver:string}>,voices:list<string>,
     *     default_connector_id:string,default_voice:string}
     */
    public static function options(array $presets, array $catalogVoices, string $voiceRoot, string $preferredConnectorId = ''): array
    {
        $connectors = [];
        $presetVoices = [];
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
            $name = trim((string) ($preset['name'] ?? ''));
            $connectors[$id] = ['id' => $id, 'label' => ($name === '' ? $driverLabel : $name . ' (' . $driverLabel . ')'),
                'driver' => $driver];
            $presetVoices[] = (string) ($content['voice'] ?? '');
            if (count($connectors) >= self::MAX_CONNECTORS) break;
        }

        // Without a connector there is nothing to speak through, so no voice is offered either.
        if ($connectors === []) return ['connectors' => [], 'voices' => [], 'default_connector_id' => '', 'default_voice' => ''];

        // Local samples first: they are the voices this server owns rather than ones a
        // provider merely reported during an explicit discovery run.
        $voices = [];
        foreach (self::localSamples($voiceRoot) as $voice) self::collect($voices, $voice);
        foreach ($presetVoices as $voice) self::collect($voices, $voice);
        foreach ($catalogVoices as $row) {
            if (!is_array($row)) continue;
            if (!isset($connectors[trim((string) ($row['configuration_id'] ?? ''))])) continue;
            self::collect($voices, (string) ($row['id'] ?? ''));
        }
        natcasesort($voices);
        $voices = array_slice(array_values($voices), 0, self::MAX_VOICES);

        $defaultConnectorId = isset($connectors[$preferredConnectorId]) ? $preferredConnectorId : (string) (array_key_first($connectors) ?? '');
        $defaultVoice = '';
        $preferredVoice = trim(self::voiceFor($presets, $defaultConnectorId));
        foreach ($voices as $voice) {
            if (strcasecmp($voice, $preferredVoice) === 0) { $defaultVoice = $voice; break; }
        }
        if ($defaultVoice === '' && $voices !== []) $defaultVoice = (string) $voices[0];

        return ['connectors' => array_values($connectors), 'voices' => $voices,
            'default_connector_id' => $defaultConnectorId, 'default_voice' => $defaultVoice];
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
}
