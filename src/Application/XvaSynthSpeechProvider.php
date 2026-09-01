<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Security\OutboundUrlPolicy;
use InvalidArgumentException;
use RuntimeException;

/** Bridges xVASynth's load-model and shared-output-file API into an in-memory LORKHAN WAV result. */
final class XvaSynthSpeechProvider implements SpeechProvider
{
    private readonly string $baseUrl;
    private readonly string $host;
    private readonly bool $allowLoopbackHttp;

    public function __construct(
        string $endpoint,
        private readonly string $voice,
        private readonly string $language,
        private readonly array $options = [],
        private readonly int $timeoutMs = 30_000,
    ) {
        $this->baseUrl = rtrim($endpoint, '/');
        $parts = parse_url($this->baseUrl);
        $this->host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $this->allowLoopbackHttp = is_array($parts) && ($parts['scheme'] ?? null) === 'http';
        if ($this->host === '' || $voice === '' || strlen($voice) > 128 || $language === '' || strlen($language) > 35
            || $timeoutMs < 1000 || $timeoutMs > 120_000 || ($options !== [] && array_is_list($options))) {
            throw new InvalidArgumentException('invalid_xvasynth_configuration');
        }
        OutboundUrlPolicy::validate($this->baseUrl, [$this->host], $this->allowLoopbackHttp);
    }

    public function synthesize(string $text, CancellationToken $cancellation, array $context = []): array
    {
        $cancellation->throwIfCancellationRequested();
        $text = trim($text);
        $voice = trim((string) ($context['voice'] ?? $this->voice));
        $language = trim((string) ($context['language'] ?? $this->language));
        if ($text === '' || mb_strlen($text) > 4096 || preg_match('/^[A-Za-z0-9_.+-]{1,128}$/D', $voice) !== 1
            || $language === '' || strlen($language) > 35) throw new RuntimeException('provider_invalid_input');
        $game = $this->option('game', 'morrowind', '/^[A-Za-z0-9_.-]{1,64}$/D');
        $prefix = $this->option('voice_prefix', 'mw_', '/^[A-Za-z0-9_+-]{0,16}$/D');
        if (!preg_match('/^[A-Za-z]+_/', $voice)) $voice = $prefix . $voice;
        $modelType = $this->option('model_type', 'xVAPitch', '/^[A-Za-z0-9_.-]{1,64}$/D');
        $version = $this->option('version', '3.0', '/^[A-Za-z0-9_.-]{1,32}$/D');
        $distro = $this->option('distro', 'DwemerAI4Skyrim3', '/^[A-Za-z0-9_.-]{1,64}$/D');
        $modelPath = 'resources/app/models/' . $game . '/' . $voice;
        $this->post('/loadModel', ['outputs' => '', 'model' => $modelPath, 'modelType' => $modelType,
            'version' => $version, 'base_lang' => $language, 'pluginsContext' => '{}'], $cancellation);

        $localPath = tempnam(sys_get_temp_dir(), 'lorkhan-xva-');
        if (!is_string($localPath)) throw new RuntimeException('provider_unavailable');
        @unlink($localPath);
        $windowsPath = '\\\\wsl.localhost\\' . $distro . str_replace('/', '\\', $localPath) . '.wav';
        $localPath .= '.wav';
        try {
            $response = $this->post('/synthesize', ['sequence' => $text, 'editorStyles' => (object) [],
                'pace' => $this->number('pace', 1.0, 0.25, 4.0), 'base_lang' => $language, 'base_emb' => [],
                'modelType' => $modelType, 'useSR' => false, 'useCleanup' => false, 'outfile' => $windowsPath,
                'pluginsContext' => '{}', 'vocoder' => (string) ($this->options['vocoder'] ?? ''),
                'waveglowPath' => (string) ($this->options['waveglow_path'] ?? ''), 'model' => $modelPath], $cancellation);
            if (substr($response, 0, 4) === 'RIFF') {
                $bytes = $response;
            } else {
                $deadline = microtime(true) + ($this->timeoutMs / 1000);
                do {
                    $cancellation->throwIfCancellationRequested();
                    clearstatcache(true, $localPath);
                    if (is_file($localPath) && ($size = filesize($localPath)) !== false && $size >= 44) break;
                    usleep(50_000);
                } while (microtime(true) < $deadline);
                if (!is_file($localPath) || filesize($localPath) === false || filesize($localPath) > 33_554_432) {
                    throw new RuntimeException('provider_unavailable');
                }
                $bytes = file_get_contents($localPath);
                if (!is_string($bytes)) throw new RuntimeException('provider_unavailable');
            }
        } finally {
            @unlink($localPath);
        }
        $duration = OpenAiCompatibleSpeechProvider::wavDurationMs($bytes);
        return ['bytes' => $bytes, 'codec' => 'wav', 'mime_type' => 'audio/wav', 'duration_ms' => $duration];
    }

    /** Send one same-origin xVASynth JSON command with cancellation and bounded output. */
    private function post(string $path, array $payload, CancellationToken $cancellation): string
    {
        $url = OutboundUrlPolicy::validate($this->baseUrl . $path, [$this->host], $this->allowLoopbackHttp);
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('provider_unavailable');
        curl_setopt_array($handle, [CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->timeoutMs), CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_HTTPHEADER => ['Content-Type: text/plain;charset=UTF-8', 'Accept: application/json, audio/wav'],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded): int =>
                $cancellation->isCancellationRequested() ? 1 : 0]);
        try {
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($cancellation->isCancellationRequested()) throw new OperationCancelled('operation_cancelled');
            if (!is_string($response) || $status < 200 || $status >= 300 || strlen($response) > 33_554_432) {
                throw new RuntimeException('provider_unavailable');
            }
            return $response;
        } finally {
            curl_close($handle);
        }
    }

    /** Read one short option whose value is constrained before joining it into xVASynth paths. */
    private function option(string $key, string $default, string $pattern): string
    {
        $value = (string) ($this->options[$key] ?? $default);
        if (preg_match($pattern, $value) !== 1) throw new RuntimeException('provider_invalid_input');
        return $value;
    }

    /** Read one bounded numeric generation option. */
    private function number(string $key, int|float $default, int|float $minimum, int|float $maximum): int|float
    {
        $value = $this->options[$key] ?? $default;
        if ((!is_int($value) && !is_float($value)) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException('provider_invalid_input');
        }
        return $value;
    }
}
