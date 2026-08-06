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
        private readonly bool $allowLoopbackHttp = false,
        private readonly string $fileField = 'file',
        private readonly bool $includeOpenAiFields = true,
        private readonly string $prompt = '',
        private readonly bool $includeLanguage = true,
    ) {
        OutboundUrlPolicy::validate($endpoint, $allowedHosts, $allowLoopbackHttp);
        if ($model === '' || strlen($model) > 200 || $timeoutMs < 1000 || $timeoutMs > 120_000
            || !in_array($fileField, ['file', 'audio_file'], true)) {
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
        $status = 0;
        $contentType = '';
        try {
            if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('provider_unavailable');
            $fields = $this->multipartFields($path, $language);
            $handle = curl_init(OutboundUrlPolicy::validate($this->endpoint, $this->allowedHosts, $this->allowLoopbackHttp));
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
                $contentType = (string) (curl_getinfo($handle, CURLINFO_CONTENT_TYPE) ?: '');
                if ($cancellation->isCancellationRequested()) throw new OperationCancelled('operation_cancelled');
                if (!is_string($response) || strlen($response) > 2_097_152) throw new RuntimeException('provider_invalid_output');
                if ($status === 408 || $status === 429 || $status >= 500) throw new RuntimeException('provider_timeout');
                if ($status < 200 || $status >= 300) {
                    error_log(sprintf('[ALMSIVI] STT provider rejected request: status=%d content_type=%s response_bytes=%d',
                        $status, $contentType !== '' ? $contentType : 'unknown', strlen($response)));
                    throw new RuntimeException('provider_unavailable');
                }
            } finally {
                curl_close($handle);
            }
        } finally {
            @unlink($path);
        }
        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            error_log(sprintf('[ALMSIVI] STT provider invalid output: reason=invalid_json status=%d content_type=%s response_bytes=%d',
                $status, $contentType !== '' ? $contentType : 'unknown', strlen($response)));
            throw new RuntimeException('provider_invalid_output');
        }
        $text = trim((string) ($decoded['text'] ?? $decoded['transcription'] ?? ''));
        if ($text === '' || mb_strlen($text) > 4096) {
            error_log(sprintf('[ALMSIVI] STT provider invalid output: reason=%s status=%d content_type=%s response_bytes=%d response_keys=%s',
                $text === '' ? 'empty_transcript' : 'transcript_too_long', $status,
                $contentType !== '' ? $contentType : 'unknown', strlen($response),
                is_array($decoded) ? implode(',', array_slice(array_keys($decoded), 0, 12)) : 'non_object'));
            throw new RuntimeException('provider_invalid_output');
        }
        $detectedLanguage = trim((string) ($decoded['language'] ?? $language));
        if ($detectedLanguage === '' || strlen($detectedLanguage) > 35) $detectedLanguage = $language;
        error_log(sprintf('[ALMSIVI] STT provider accepted transcript: status=%d content_type=%s response_bytes=%d transcript_chars=%d language=%s',
            $status, $contentType !== '' ? $contentType : 'unknown', strlen($response), mb_strlen($text), $detectedLanguage));
        return ['text' => $text, 'language' => $detectedLanguage];
    }

    /** Build the CHIM-compatible multipart contract separately so provider wire shapes remain testable. */
    private function multipartFields(string $path, string $language): array
    {
        $fields = [$this->fileField => new CURLFile($path, 'audio/wav', 'recording.wav')];
        if (!$this->includeOpenAiFields) return $fields;
        $fields['model'] = $this->model;
        if ($this->prompt !== '') $fields['prompt'] = $this->prompt;
        $normalizedLanguage = strtolower(substr(trim($language), 0, 2));
        if ($this->includeLanguage && preg_match('/^[a-z]{2}$/D', $normalizedLanguage) === 1) {
            $fields['language'] = $normalizedLanguage;
        }
        return $fields;
    }
}
