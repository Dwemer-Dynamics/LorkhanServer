<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Security\OutboundUrlPolicy;
use RuntimeException;

/** OpenAI-compatible audio-speech adapter restricted to PCM WAV output. */
final class OpenAiCompatibleSpeechProvider implements SpeechProvider
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $allowedHosts,
        private readonly string $model,
        private readonly string $voice,
        private readonly string $apiKey = '',
        private readonly int $timeoutMs = 30_000,
        private readonly bool $allowLoopbackHttp = false,
    ) {
        OutboundUrlPolicy::validate($endpoint, $allowedHosts, $allowLoopbackHttp);
        if ($model === '' || strlen($model) > 200 || $voice === '' || strlen($voice) > 200
            || $timeoutMs < 1000 || $timeoutMs > 120_000) {
            throw new \InvalidArgumentException('invalid_openai_compatible_speech_configuration');
        }
    }

    public function synthesize(string $text, CancellationToken $cancellation, array $context = []): array
    {
        $cancellation->throwIfCancellationRequested();
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4096) throw new RuntimeException('provider_invalid_input');
        $voice = trim((string) ($context['voice'] ?? $this->voice));
        if ($voice === '' || strlen($voice) > 512) throw new RuntimeException('provider_invalid_input');
        $body = json_encode(['model' => $this->model, 'voice' => $voice, 'input' => $text,
            'response_format' => 'wav'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = curl_init(OutboundUrlPolicy::validate($this->endpoint, $this->allowedHosts, $this->allowLoopbackHttp));
        if ($handle === false) throw new RuntimeException('provider_unavailable');
        $headers = ['Content-Type: application/json', 'Accept: audio/wav'];
        if ($this->apiKey !== '') $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->timeoutMs),
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function ($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($cancellation): int {
                unset($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded);
                return $cancellation->isCancellationRequested() ? 1 : 0;
            },
        ]);
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
        $duration = self::wavDurationMs($bytes);
        return ['bytes' => $bytes, 'codec' => 'wav', 'mime_type' => 'audio/wav', 'duration_ms' => $duration];
    }

    /** Validate a RIFF/WAVE response and calculate duration without trusting provider metadata. */
    public static function wavDurationMs(string $bytes): int
    {
        if (strlen($bytes) < 44 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            throw new RuntimeException('provider_invalid_audio');
        }
        $offset = 12;
        $byteRate = null;
        $dataBytes = null;
        while ($offset + 8 <= strlen($bytes)) {
            $id = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $offset += 8;
            if ($size < 0 || $offset + $size > strlen($bytes)) throw new RuntimeException('provider_invalid_audio');
            if ($id === 'fmt ' && $size >= 16) $byteRate = unpack('V', substr($bytes, $offset + 8, 4))[1];
            if ($id === 'data') $dataBytes = $size;
            $offset += $size + ($size % 2);
        }
        if (!is_int($byteRate) || $byteRate < 1 || !is_int($dataBytes) || $dataBytes < 1) {
            throw new RuntimeException('provider_invalid_audio');
        }
        return max(1, (int) ceil(($dataBytes * 1000) / $byteRate));
    }
}
