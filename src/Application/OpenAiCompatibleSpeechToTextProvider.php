<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Security\OutboundUrlPolicy;
use CURLFile;
use RuntimeException;

/** OpenAI-compatible multipart audio-transcription adapter. */
final class OpenAiCompatibleSpeechToTextProvider implements SpeechToTextProvider
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $allowedHosts,
        private readonly string $model,
        private readonly string $apiKey = '',
        private readonly int $timeoutMs = 30_000,
    ) {
        OutboundUrlPolicy::validate($endpoint, $allowedHosts);
        if ($model === '' || strlen($model) > 200 || $timeoutMs < 1000 || $timeoutMs > 120_000) {
            throw new \InvalidArgumentException('invalid_openai_compatible_stt_configuration');
        }
    }

    public function transcribe(string $bytes, string $codec, string $language, CancellationToken $cancellation): array
    {
        $cancellation->throwIfCancellationRequested();
        if ($codec !== 'wav' || strlen($bytes) < 44 || strlen($bytes) > 33_554_432
            || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            throw new RuntimeException('invalid_audio');
        }
        $path = tempnam(sys_get_temp_dir(), 'almsivi-stt-');
        if (!is_string($path)) throw new RuntimeException('provider_unavailable');
        try {
            if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('provider_unavailable');
            $fields = ['model' => $this->model, 'response_format' => 'json',
                'file' => new CURLFile($path, 'audio/wav', 'recording.wav')];
            $normalizedLanguage = strtolower(substr(trim($language), 0, 2));
            if (preg_match('/^[a-z]{2}$/D', $normalizedLanguage) === 1) $fields['language'] = $normalizedLanguage;
            $handle = curl_init(OutboundUrlPolicy::validate($this->endpoint, $this->allowedHosts));
            if ($handle === false) throw new RuntimeException('provider_unavailable');
            $headers = ['Accept: application/json'];
            if ($this->apiKey !== '') $headers[] = 'Authorization: Bearer ' . $this->apiKey;
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $fields,
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
                $response = curl_exec($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                if ($cancellation->isCancellationRequested()) throw new OperationCancelled('operation_cancelled');
                if (!is_string($response) || $status < 200 || $status >= 300 || strlen($response) > 2_097_152) {
                    throw new RuntimeException('provider_unavailable');
                }
            } finally {
                curl_close($handle);
            }
        } finally {
            @unlink($path);
        }
        $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        $text = trim((string) ($decoded['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 4096) throw new RuntimeException('provider_invalid_output');
        $detectedLanguage = trim((string) ($decoded['language'] ?? $language));
        if ($detectedLanguage === '' || strlen($detectedLanguage) > 35) $detectedLanguage = $language;
        return ['text' => $text, 'language' => $detectedLanguage];
    }
}
