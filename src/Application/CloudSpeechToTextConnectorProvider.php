<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Security\OutboundUrlPolicy;
use InvalidArgumentException;
use RuntimeException;

/** Adapts the Azure, Deepgram, Gemini, and Inworld CHIM STT request/response contracts. */
final class CloudSpeechToTextConnectorProvider implements SpeechToTextProvider
{
    private const DRIVERS = ['azure', 'deepgram', 'gemini', 'inworld'];

    private readonly string $host;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $driver,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly array $options = [],
        private readonly int $timeoutMs = 30_000,
    ) {
        $parts = parse_url($endpoint);
        $this->host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (!in_array($driver, self::DRIVERS, true) || $this->host === '' || strlen($model) > 256
            || $timeoutMs < 1000 || $timeoutMs > 120_000 || ($options !== [] && array_is_list($options))) {
            throw new InvalidArgumentException('invalid_cloud_stt_connector_configuration');
        }
        OutboundUrlPolicy::validate($endpoint, [$this->host]);
    }

    public function transcribe(string $bytes, string $codec, string $language, CancellationToken $cancellation): array
    {
        $cancellation->throwIfCancellationRequested();
        if ($codec !== 'wav' || strlen($bytes) < 44 || strlen($bytes) > 33_554_432
            || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE'
            || $this->apiKey === '' || $language === '' || strlen($language) > 35) {
            throw new RuntimeException('invalid_audio');
        }
        [$url, $body, $headers] = $this->request($bytes, $language);
        $handle = curl_init(OutboundUrlPolicy::validate($url, [$this->host]));
        if ($handle === false) throw new RuntimeException('provider_unavailable');
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
            CURLOPT_XFERINFOFUNCTION => static fn($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded): int =>
                $cancellation->isCancellationRequested() ? 1 : 0,
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
        return ['text' => $this->text($response), 'language' => $language];
    }

    /** Build only the documented request shape for the selected cloud transcription service. */
    private function request(string $bytes, string $language): array
    {
        $base = rtrim($this->endpoint, '/');
        if ($this->driver === 'azure') {
            $url = str_contains($base, '/speech/recognition/') ? $base
                : $base . '/speech/recognition/conversation/cognitiveservices/v1';
            $query = ['language' => $language, 'profanity' => (string) ($this->options['profanity'] ?? 'masked')];
            return [$url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
                $bytes, ['Ocp-Apim-Subscription-Key: ' . $this->apiKey, 'Content-Type: audio/wav', 'Accept: application/json']];
        }
        if ($this->driver === 'deepgram') {
            $url = str_contains($base, '/v1/listen') ? $base : $base . '/v1/listen';
            $query = ['punctuate' => 'true', 'utterances' => 'true', 'language' => $language,
                'model' => $this->model !== '' ? $this->model : 'nova-3'];
            return [$url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
                $bytes, ['Authorization: Token ' . $this->apiKey, 'Content-Type: audio/wav', 'Accept: application/json']];
        }
        if ($this->driver === 'inworld') {
            $url = str_contains($base, '/stt/v1/transcribe') ? $base : $base . '/stt/v1/transcribe';
            $payload = ['transcribeConfig' => ['modelId' => $this->model !== '' ? $this->model : 'groq/whisper-large-v3',
                'audioEncoding' => 'AUTO_DETECT', 'sampleRateHertz' => 16000, 'numberOfChannels' => 1,
                'language' => $language], 'audioData' => ['content' => base64_encode($bytes)]];
            return [$url, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ['Authorization: Basic ' . $this->apiKey, 'Content-Type: application/json', 'Accept: application/json']];
        }
        $model = $this->model !== '' ? $this->model : 'gemini-2.5-flash';
        $url = str_contains($base, ':generateContent') ? $base
            : (str_contains($base, '/v1beta/models/') ? $base : $base . '/v1beta/models/' . rawurlencode($model)) . ':generateContent';
        $prompt = 'Transcribe this RPG player audio accurately. Return only JSON with fields transcript and tone. '
            . 'Use tone neutral when no clear vocal emotion is audible. Language: ' . $language;
        $payload = ['contents' => [['parts' => [['text' => $prompt], ['inline_data' => [
            'mime_type' => 'audio/wav', 'data' => base64_encode($bytes)]]]]], 'generationConfig' => [
                'temperature' => 0.1, 'maxOutputTokens' => 1024, 'responseMimeType' => 'application/json']];
        return [$url, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ['x-goog-api-key: ' . $this->apiKey, 'Content-Type: application/json', 'Accept: application/json']];
    }

    /** Extract and bound the transcript from each provider's documented JSON response. */
    private function text(string $response): string
    {
        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('provider_invalid_output');
        }
        if (!is_array($decoded)) throw new RuntimeException('provider_invalid_output');
        $text = '';
        if ($this->driver === 'azure') $text = (string) ($decoded['DisplayText'] ?? '');
        elseif ($this->driver === 'deepgram') $text = (string) ($decoded['results']['channels'][0]['alternatives'][0]['transcript'] ?? '');
        elseif ($this->driver === 'inworld') $text = (string) ($decoded['transcription']['transcript'] ?? '');
        else {
            $generated = trim((string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
            if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $generated, $match) === 1) $generated = $match[1];
            $result = json_decode($generated, true);
            if (!is_array($result)) throw new RuntimeException('provider_invalid_output');
            $text = trim((string) ($result['transcript'] ?? ''));
            $tone = trim((string) ($result['tone'] ?? 'neutral'));
            if (($this->options['include_tone'] ?? true) === true && $text !== '' && $tone !== '' && strtolower($tone) !== 'neutral'
                && mb_strlen($tone) <= 40) $text = '(' . $tone . ') ' . $text;
        }
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4096) throw new RuntimeException('provider_invalid_output');
        return $text;
    }
}
