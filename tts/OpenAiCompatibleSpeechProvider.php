<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Security\OutboundUrlPolicy;
use RuntimeException;

/** OpenAI-compatible audio-speech adapter restricted to PCM WAV output. */
final class OpenAiCompatibleSpeechProvider implements SpeechProvider
{
    private readonly ?string $voiceReferenceRoot;

    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $allowedHosts,
        private readonly string $model,
        private readonly string $voice,
        private readonly string $apiKey = '',
        private readonly int $timeoutMs = 30_000,
        private readonly bool $allowLoopbackHttp = false,
        ?string $voiceReferenceRoot = null,
        private readonly ?string $language = null,
        private readonly array $options = [],
    ) {
        OutboundUrlPolicy::validate($endpoint, $allowedHosts, $allowLoopbackHttp);
        if ($model === '' || strlen($model) > 200 || $voice === '' || strlen($voice) > 200
            || $timeoutMs < 1000 || $timeoutMs > 120_000
            || ($language !== null && ($language === '' || strlen($language) > 35))) {
            throw new \InvalidArgumentException('invalid_openai_compatible_speech_configuration');
        }
        if ($voiceReferenceRoot === null) {
            $this->voiceReferenceRoot = null;
        } else {
            $root = realpath($voiceReferenceRoot);
            if (!is_string($root) || !is_dir($root)) {
                throw new \InvalidArgumentException('invalid_voice_reference_root');
            }
            $this->voiceReferenceRoot = rtrim($root, DIRECTORY_SEPARATOR);
        }
    }

    public function synthesize(string $text, CancellationToken $cancellation, array $context = []): array
    {
        $cancellation->throwIfCancellationRequested();
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4096) throw new RuntimeException('provider_invalid_input');
        $voice = trim((string) ($context['voice'] ?? $this->voice));
        if ($voice === '' || strlen($voice) > 512) throw new RuntimeException('provider_invalid_input');
        $payload = $this->requestPayload($text, $voice);
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

    /** Build the WAV request with only supported editor controls, never arbitrary option passthrough. */
    private function requestPayload(string $text, string $voice): array
    {
        $payload = ['model' => $this->model, 'input' => $text, 'response_format' => 'wav'];
        $voiceReference = $this->voiceReference($voice);
        if ($voiceReference === null) $payload['voice'] = $voice;
        else $payload['voice_ref'] = $voiceReference;
        if ($this->language !== null) $payload['language'] = $this->language;
        if (isset($this->options['speed'])) {
            $speed = $this->options['speed'];
            if ((!is_int($speed) && !is_float($speed)) || !is_finite((float)$speed) || $speed < 0.25 || $speed > 4) throw new RuntimeException('provider_invalid_input');
            $payload['speed'] = $speed;
        }
        if ($this->model === 'gpt-4o-mini-tts' && isset($this->options['instructions'])) {
            $instructions = $this->options['instructions'];
            if (!is_string($instructions) || strlen($instructions) > 4096 || !mb_check_encoding($instructions,'UTF-8')) throw new RuntimeException('provider_invalid_input');
            if (trim($instructions) !== '') $payload['instructions'] = $instructions;
        }
        return $payload;
    }

    /** Resolve a connector-owned voice ID to a readable sample without accepting arbitrary paths. */
    private function voiceReference(string $voice): ?string
    {
        if ($this->voiceReferenceRoot === null || preg_match('/^[a-zA-Z0-9_.-]{1,200}$/D', $voice) !== 1) return null;
        $name = str_ends_with(strtolower($voice), '.wav') ? substr($voice, 0, -4) : $voice;
        $candidate = realpath($this->voiceReferenceRoot . DIRECTORY_SEPARATOR . $name . '.wav');
        $prefix = $this->voiceReferenceRoot . DIRECTORY_SEPARATOR;
        return is_string($candidate) && str_starts_with($candidate, $prefix) && is_file($candidate) && is_readable($candidate)
            ? $candidate : null;
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
