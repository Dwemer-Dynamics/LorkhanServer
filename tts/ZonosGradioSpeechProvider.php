<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Security\OutboundUrlPolicy;
use CURLFile;
use InvalidArgumentException;
use RuntimeException;

/** Runs the Zonos Gradio upload, queued generation, and WAV download flow for one voice sample. */
final class ZonosGradioSpeechProvider implements SpeechProvider
{
    private readonly string $baseUrl;
    private readonly string $host;
    private readonly bool $allowLoopbackHttp;

    public function __construct(
        string $endpoint,
        private readonly string $voice,
        private readonly string $language,
        private readonly string $model,
        private readonly string $voiceRoot,
        private readonly array $options = [],
        private readonly int $timeoutMs = 30_000,
    ) {
        $this->baseUrl = rtrim($endpoint, '/');
        $parts = parse_url($this->baseUrl);
        $this->host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $this->allowLoopbackHttp = is_array($parts) && ($parts['scheme'] ?? null) === 'http';
        if ($this->host === '' || $voice === '' || strlen($voice) > 128 || $language === '' || strlen($language) > 35
            || strlen($model) > 256 || $timeoutMs < 1000 || $timeoutMs > 120_000
            || ($options !== [] && array_is_list($options)) || !is_dir($voiceRoot)) {
            throw new InvalidArgumentException('invalid_zonos_gradio_configuration');
        }
        OutboundUrlPolicy::validate($this->baseUrl, [$this->host], $this->allowLoopbackHttp);
    }

    public function synthesize(string $text, CancellationToken $cancellation, array $context = []): array
    {
        $cancellation->throwIfCancellationRequested();
        $text = trim($text);
        $voice = trim((string) ($context['voice'] ?? $this->voice));
        $language = trim((string) ($context['language'] ?? $this->language));
        if ($text === '' || mb_strlen($text) > 4096 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_. -]{0,127}$/D', $voice) !== 1
            || $language === '' || strlen($language) > 35) throw new RuntimeException('provider_invalid_input');
        $samplePath = $this->voiceRoot . DIRECTORY_SEPARATOR . $voice . (str_ends_with(strtolower($voice), '.wav') ? '' : '.wav');
        $realRoot = realpath($this->voiceRoot);
        $realSample = realpath($samplePath);
        if (!is_string($realRoot) || !is_string($realSample) || !str_starts_with($realSample, $realRoot . DIRECTORY_SEPARATOR)
            || !is_file($realSample) || filesize($realSample) < 44 || filesize($realSample) > 16_777_216) {
            throw new RuntimeException('provider_voice_sample_missing');
        }
        $upload = $this->request('/gradio_api/upload', ['files' => new CURLFile($realSample, 'audio/wav', basename($realSample))], [], $cancellation);
        $uploaded = json_decode($upload, true);
        $remotePath = is_array($uploaded) && is_string($uploaded[0] ?? null) ? $uploaded[0] : '';
        if ($remotePath === '' || strlen($remotePath) > 2048 || str_contains($remotePath, "\0")) throw new RuntimeException('provider_invalid_output');

        $emotions = array_fill(0, 8, 0.05);
        $data = [$this->model !== '' ? $this->model : 'Zyphra/Zonos-v0.1-hybrid', $text, $language,
            ['meta' => ['_type' => 'gradio.FileData'], 'mime_type' => 'audio/wav', 'orig_name' => basename($realSample),
                'path' => $remotePath, 'url' => $this->baseUrl . '/gradio_api/file=' . rawurlencode($remotePath)], null,
            ...$emotions, 0.7, 24000, $this->number('pitch_std', 45, 0, 200),
            $this->number('speaking_rate', 14.6, 1, 40), 4, false,
            $this->number('cfg_scale', 4.5, 0, 20), 0, 0, 0, 0.5, 0.4, 0, 420, true, ['emotion']];
        $queued = $this->request('/gradio_api/call/generate_audio', json_encode(['data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json', 'Accept: application/json'], $cancellation);
        $queueData = json_decode($queued, true);
        $eventId = is_array($queueData) ? (string) ($queueData['event_id'] ?? '') : '';
        if (preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $eventId) !== 1) throw new RuntimeException('provider_invalid_output');
        $result = $this->request('/gradio_api/call/generate_audio/' . rawurlencode($eventId), null,
            ['Accept: text/event-stream'], $cancellation);
        $generatedPath = '';
        foreach (preg_split('/\R/', $result) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data:')) $line = trim(substr($line, 5));
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $generatedPath = self::findPath($decoded) ?? $generatedPath;
            }
        }
        if ($generatedPath === '' && preg_match('/"path"\s*:\s*"([^"\\]*(?:\\.[^"\\]*)*)"/', $result, $match) === 1) {
            $generatedPath = (string) json_decode('"' . $match[1] . '"');
        }
        if ($generatedPath === '' || strlen($generatedPath) > 2048 || str_contains($generatedPath, "\0")) {
            throw new RuntimeException('provider_invalid_output');
        }
        $bytes = $this->request('/gradio_api/file=' . rawurlencode($generatedPath), null, ['Accept: audio/wav'], $cancellation);
        $duration = OpenAiCompatibleSpeechProvider::wavDurationMs($bytes);
        return ['bytes' => $bytes, 'codec' => 'wav', 'mime_type' => 'audio/wav', 'duration_ms' => $duration];
    }

    /** Execute one same-origin Gradio request with bounded redirects, output, timeout, and cancellation. */
    private function request(string $path, array|string|null $body, array $headers, CancellationToken $cancellation): string
    {
        $url = OutboundUrlPolicy::validate($this->baseUrl . $path, [$this->host], $this->allowLoopbackHttp);
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('provider_unavailable');
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->timeoutMs), CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded): int =>
                $cancellation->isCancellationRequested() ? 1 : 0]);
        if ($body !== null) curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
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

    /** Find the first Gradio file path in a nested result. */
    private static function findPath(array $value): ?string
    {
        if (is_string($value['path'] ?? null) && $value['path'] !== '') return $value['path'];
        foreach ($value as $child) if (is_array($child) && ($path = self::findPath($child)) !== null) return $path;
        return null;
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
