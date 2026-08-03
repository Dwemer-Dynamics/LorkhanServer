<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Security\OutboundUrlPolicy;
use InvalidArgumentException;
use RuntimeException;

/** Adapts the simple WAV-returning local CHIM TTS services to the ALMSIVI speech contract. */
final class LocalSpeechConnectorProvider implements SpeechProvider
{
    private const DRIVERS = ['melotts', 'mimic3', 'piper-tts', 'stylettsv2'];

    private readonly string $host;
    private readonly bool $allowLoopbackHttp;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $driver,
        private readonly string $voice,
        private readonly string $language = 'en',
        private readonly array $options = [],
        private readonly string $apiKey = '',
        private readonly int $timeoutMs = 30_000,
    ) {
        $parts = parse_url($endpoint);
        $this->host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $this->allowLoopbackHttp = is_array($parts) && ($parts['scheme'] ?? null) === 'http';
        if (!in_array($driver, self::DRIVERS, true) || $this->host === '' || $voice === ''
            || strlen($voice) > 512 || $language === '' || strlen($language) > 35
            || $timeoutMs < 1000 || $timeoutMs > 120_000 || ($options !== [] && array_is_list($options))) {
            throw new InvalidArgumentException('invalid_local_speech_connector_configuration');
        }
        OutboundUrlPolicy::validate($endpoint, [$this->host], $this->allowLoopbackHttp);
    }

    public function synthesize(string $text, CancellationToken $cancellation, array $context = []): array
    {
        $cancellation->throwIfCancellationRequested();
        $text = trim($text);
        $voice = trim((string) ($context['voice'] ?? $this->voice));
        $language = trim((string) ($context['language'] ?? $this->language));
        if ($text === '' || mb_strlen($text) > 4096 || $voice === '' || strlen($voice) > 512
            || $language === '' || strlen($language) > 35) {
            throw new RuntimeException('provider_invalid_input');
        }

        [$url, $body] = $this->request($text, $voice, $language);
        $url = OutboundUrlPolicy::validate($url, [$this->host], $this->allowLoopbackHttp);
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('provider_unavailable');
        $headers = ['Accept: audio/wav'];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        if ($this->apiKey !== '') $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->timeoutMs),
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded): int =>
                $cancellation->isCancellationRequested() ? 1 : 0,
        ]);
        if ($body !== null) curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
        try {
            $bytes = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($cancellation->isCancellationRequested()) throw new OperationCancelled('operation_cancelled');
            if (!is_string($bytes) || $status < 200 || $status >= 300 || strlen($bytes) > 33_554_432) {
                throw new RuntimeException('provider_unavailable');
            }
        } finally {
            curl_close($handle);
        }
        return ['bytes' => $bytes, 'codec' => 'wav', 'mime_type' => 'audio/wav',
            'duration_ms' => OpenAiCompatibleSpeechProvider::wavDurationMs($bytes)];
    }

    /** Build the connector-specific URL and JSON body without exposing arbitrary HTTP controls. */
    private function request(string $text, string $voice, string $language): array
    {
        $base = rtrim($this->endpoint, '/');
        if ($this->driver === 'mimic3') {
            $url = str_ends_with($base, '/api/tts') ? $base : $base . '/api/tts';
            $query = ['text' => $text, 'voice' => $voice, 'noiseScale' => 0.667, 'noiseW' => 0.8,
                'lengthScale' => $this->number('rate', 1.0, 0.2, 4.0), 'ssml' => 'false', 'audioTarget' => 'client'];
            return [$url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), null];
        }
        if ($this->driver === 'melotts') {
            return [str_ends_with($base, '/tts') ? $base : $base . '/tts', json_encode([
                'speaker' => $voice, 'text' => $text, 'language' => strtoupper(substr($language, 0, 2)),
                'speed' => $this->number('speed', 1.0, 0.25, 4.0),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        }
        if ($this->driver === 'piper-tts') {
            $payload = ['text' => $text, 'voice' => $voice,
                'length_scale' => $this->number('length_scale', 1.0, 0.2, 4.0)];
            foreach (['noise_scale', 'noise_w_scale'] as $key) {
                if (array_key_exists($key, $this->options)) $payload[$key] = $this->number($key, 1.0, 0.0, 2.0);
            }
            if (isset($this->options['speaker']) && is_string($this->options['speaker']) && strlen($this->options['speaker']) <= 256) {
                $payload['speaker'] = $this->options['speaker'];
            }
            if (isset($this->options['speaker_id'])) $payload['speaker_id'] = max(0, (int) $this->options['speaker_id']);
            return [$base, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        }
        return [str_ends_with($base, '/tts') ? $base : $base . '/tts', json_encode([
            'text' => $text,
            'alpha' => $this->number('alpha', 0.3, 0.0, 1.0),
            'beta' => $this->number('beta', 0.7, 0.0, 1.0),
            'diffusion_steps' => (int) $this->number('diffusion_steps', 5, 1, 100),
            'embedding_scale' => $this->number('embedding_scale', 1.0, 0.0, 10.0),
            'sessionId' => (int) $this->number('session_id', 12345, 1, 2_147_483_647),
            'voice' => $voice,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    /** Read one bounded numeric connector option. */
    private function number(string $key, int|float $default, int|float $minimum, int|float $maximum): int|float
    {
        $value = $this->options[$key] ?? $default;
        if (!is_int($value) && !is_float($value)) throw new RuntimeException('provider_invalid_input');
        if ($value < $minimum || $value > $maximum) throw new RuntimeException('provider_invalid_input');
        return $value;
    }
}
