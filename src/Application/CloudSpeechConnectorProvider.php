<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Security\OutboundUrlPolicy;
use InvalidArgumentException;
use RuntimeException;

/** Adapts WAV-capable cloud CHIM TTS services without storing their credentials in LORKHAN data. */
final class CloudSpeechConnectorProvider implements SpeechProvider
{
    private const DRIVERS = ['11labs', 'azure', 'cartesia', 'convai', 'coqui-ai', 'deepgram', 'gcp', 'inworld'];

    private readonly string $host;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $driver,
        private readonly string $model,
        private readonly string $voice,
        private readonly string $language = 'en-US',
        private readonly array $options = [],
        private readonly string $apiKey = '',
        private readonly int $timeoutMs = 30_000,
    ) {
        $parts = parse_url($endpoint);
        $this->host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (!in_array($driver, self::DRIVERS, true) || $this->host === '' || $voice === ''
            || strlen($voice) > 512 || strlen($model) > 256 || $language === '' || strlen($language) > 35
            || $timeoutMs < 1000 || $timeoutMs > 120_000 || ($options !== [] && array_is_list($options))) {
            throw new InvalidArgumentException('invalid_cloud_speech_connector_configuration');
        }
        OutboundUrlPolicy::validate($endpoint, [$this->host]);
    }

    public function synthesize(string $text, CancellationToken $cancellation, array $context = []): array
    {
        $cancellation->throwIfCancellationRequested();
        $text = trim($text);
        $voice = trim((string) ($context['voice'] ?? $this->voice));
        $language = trim((string) ($context['language'] ?? $this->language));
        if ($text === '' || mb_strlen($text) > 4096 || $voice === '' || strlen($voice) > 512
            || $language === '' || strlen($language) > 35 || $this->apiKey === '') {
            throw new RuntimeException('provider_invalid_input');
        }
        [$url, $body, $headers] = $this->request($text, $voice, $language);
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
            $bytes = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($cancellation->isCancellationRequested()) throw new OperationCancelled('operation_cancelled');
            if (!is_string($bytes) || $status < 200 || $status >= 300 || strlen($bytes) > 33_554_432) {
                throw new RuntimeException('provider_unavailable');
            }
        } finally {
            curl_close($handle);
        }
        $bytes = $this->audio($bytes);
        return ['bytes' => $bytes, 'codec' => 'wav', 'mime_type' => 'audio/wav',
            'duration_ms' => OpenAiCompatibleSpeechProvider::wavDurationMs($bytes)];
    }

    /** Build only the documented request shape for the selected cloud connector. */
    private function request(string $text, string $voice, string $language): array
    {
        $base = rtrim($this->endpoint, '/');
        if ($this->driver === '11labs') {
            $url = str_contains($base, '/v1/text-to-speech/') ? $base
                : (str_ends_with($base, '/v1/text-to-speech') ? $base : $base . '/v1/text-to-speech') . '/' . rawurlencode($voice);
            $url .= (str_contains($url, '?') ? '&' : '?') . 'output_format=wav_22050';
            $payload = ['text' => $text, 'model_id' => $this->model !== '' ? $this->model : 'eleven_multilingual_v2'];
            $code = strtolower(substr($language, 0, 2));
            if (preg_match('/^[a-z]{2}$/D', $code) === 1) $payload['language_code'] = $code;
            $settings = [];
            foreach (['stability', 'similarity_boost', 'style'] as $key) {
                if (isset($this->options[$key]) && (is_int($this->options[$key]) || is_float($this->options[$key]))) {
                    $settings[$key] = max(0.0, min(1.0, (float) $this->options[$key]));
                }
            }
            if (isset($this->options['use_speaker_boost']) && is_bool($this->options['use_speaker_boost'])) {
                $settings['use_speaker_boost'] = $this->options['use_speaker_boost'];
            }
            if ($settings !== []) $payload['voice_settings'] = $settings;
            return [$url, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ['xi-api-key: ' . $this->apiKey, 'Content-Type: application/json', 'Accept: audio/wav']];
        }
        if ($this->driver === 'deepgram') {
            $url = str_contains($base, '/v1/speak') ? $base : $base . '/v1/speak';
            $query = ['model' => $voice, 'encoding' => 'linear16', 'container' => 'wav'];
            return [$url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
                json_encode(['text' => $text], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ['Authorization: Token ' . $this->apiKey, 'Content-Type: application/json', 'Accept: audio/wav']];
        }
        if ($this->driver === 'cartesia') {
            $url = str_contains($base, '/tts/bytes') ? $base : $base . '/tts/bytes';
            $speed = $this->options['speed'] ?? 'normal';
            if (!is_string($speed) || strlen($speed) > 32) throw new RuntimeException('provider_invalid_input');
            $payload = ['model_id' => $this->model !== '' ? $this->model : 'sonic-3', 'transcript' => $text,
                'voice' => ['mode' => 'id', 'id' => $voice], 'language' => strtolower(substr($language, 0, 2)),
                'output_format' => ['container' => 'wav', 'encoding' => 'pcm_s16le', 'sample_rate' => 22050],
                'speed' => $speed];
            return [$url, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ['X-API-Key: ' . $this->apiKey, 'Cartesia-Version: 2024-11-13',
                    'Content-Type: application/json', 'Accept: audio/wav']];
        }
        if ($this->driver === 'convai') {
            $payload = ['transcript' => $text, 'voice' => $voice, 'filename' => 'lorkhan.wav',
                'encoding' => 'wav', 'language' => $language];
            return [$base, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ['CONVAI-API-KEY: ' . $this->apiKey, 'Content-Type: application/json', 'Accept: audio/wav']];
        }
        if ($this->driver === 'coqui-ai') {
            $url = str_contains($base, '/samples/xtts/stream') ? $base : $base . '/api/v2/samples/xtts/stream';
            $speed = $this->options['speed'] ?? 1.0;
            if (!is_int($speed) && !is_float($speed)) throw new RuntimeException('provider_invalid_input');
            $payload = ['text' => $text, 'speed' => max(0.25, min(4.0, (float) $speed)),
                'voice_id' => $voice, 'language' => strtolower(substr($language, 0, 2))];
            return [$url, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json', 'Accept: audio/wav']];
        }
        if ($this->driver === 'gcp') {
            $url = str_contains($base, 'text:synthesize') ? $base : $base . '/v1/text:synthesize';
            $payload = ['input' => ['text' => $text], 'voice' => ['languageCode' => $language, 'name' => $voice],
                'audioConfig' => ['audioEncoding' => 'LINEAR16']];
            return [$url, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ['x-goog-api-key: ' . $this->apiKey, 'Content-Type: application/json', 'Accept: application/json']];
        }
        if ($this->driver === 'inworld') {
            $url = str_contains($base, '/tts/v1/voice:stream') ? $base : $base . '/tts/v1/voice:stream';
            $speed = $this->options['speed'] ?? 1.0;
            $temperature = $this->options['temperature'] ?? 1.0;
            if ((!is_int($speed) && !is_float($speed)) || (!is_int($temperature) && !is_float($temperature))) {
                throw new RuntimeException('provider_invalid_input');
            }
            $payload = ['text' => $text, 'voiceId' => $voice, 'modelId' => $this->model !== '' ? $this->model : 'inworld-tts-2',
                'language' => $language, 'audioConfig' => ['audioEncoding' => 'LINEAR16', 'sampleRateHertz' => 22050,
                    'speakingRate' => max(0.5, min(1.5, (float) $speed))],
                'temperature' => max(0.0, min(2.0, (float) $temperature))];
            return [$url, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ['Authorization: Basic ' . $this->apiKey, 'Content-Type: application/json', 'Accept: text/event-stream']];
        }
        $url = str_contains($base, '/cognitiveservices/v1') ? $base : $base . '/cognitiveservices/v1';
        $xml = '<speak version="1.0" xml:lang="' . htmlspecialchars($language, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '"><voice name="' . htmlspecialchars($voice, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</voice></speak>';
        return [$url, $xml, ['Ocp-Apim-Subscription-Key: ' . $this->apiKey,
            'Content-Type: application/ssml+xml', 'X-Microsoft-OutputFormat: riff-24khz-16bit-mono-pcm',
            'User-Agent: LorkhanServer', 'Accept: audio/wav']];
    }

    /** Decode connectors that envelope audio and retain validated WAV bytes for every driver. */
    private function audio(string $response): string
    {
        if ($this->driver === 'gcp') {
            $decoded = json_decode($response, true);
            $response = is_array($decoded) ? (string) base64_decode((string) ($decoded['audioContent'] ?? ''), true) : '';
            if ($response !== '' && substr($response, 0, 4) !== 'RIFF') $response = self::wav($response, 24000);
        } elseif ($this->driver === 'inworld') {
            $pcm = '';
            foreach (preg_split('/\R/', $response) ?: [] as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'data:')) $line = trim(substr($line, 5));
                if ($line === '' || str_starts_with($line, 'event:') || $line === ':') continue;
                $chunk = json_decode($line, true);
                $audio = is_array($chunk) ? base64_decode((string) ($chunk['result']['audioContent'] ?? ''), true) : false;
                if (!is_string($audio) || $audio === '') continue;
                $pcm .= substr($audio, 0, 4) === 'RIFF' ? self::pcm($audio) : $audio;
            }
            $response = $pcm === '' ? '' : self::wav($pcm, 22050);
        }
        if ($response === '' || strlen($response) > 33_554_432) throw new RuntimeException('provider_invalid_audio');
        OpenAiCompatibleSpeechProvider::wavDurationMs($response);
        return $response;
    }

    /** Extract PCM data from a bounded RIFF/WAVE chunk. */
    private static function pcm(string $wav): string
    {
        $offset = 12;
        while ($offset + 8 <= strlen($wav)) {
            $id = substr($wav, $offset, 4);
            $size = unpack('V', substr($wav, $offset + 4, 4))[1];
            $offset += 8;
            if ($size < 0 || $offset + $size > strlen($wav)) break;
            if ($id === 'data') return substr($wav, $offset, $size);
            $offset += $size + ($size % 2);
        }
        throw new RuntimeException('provider_invalid_audio');
    }

    /** Wrap signed 16-bit mono PCM in a deterministic WAV container. */
    private static function wav(string $pcm, int $sampleRate): string
    {
        $dataSize = strlen($pcm);
        if ($dataSize < 2 || $dataSize > 33_554_388 || $dataSize % 2 !== 0) throw new RuntimeException('provider_invalid_audio');
        return 'RIFF' . pack('V', 36 + $dataSize) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1,
            $sampleRate, $sampleRate * 2, 2, 16) . 'data' . pack('V', $dataSize) . $pcm;
    }
}
