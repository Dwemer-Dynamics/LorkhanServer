<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Security\OutboundUrlPolicy;
use Closure;
use RuntimeException;

/** Try the saved PocketTTS endpoint first, then compatible known ports on the same host. */
final class PocketTtsSpeechProvider implements SpeechProvider
{
    private readonly string $host;
    private readonly bool $allowLoopbackHttp;
    private readonly Closure $providerFactory;
    private readonly Closure $modeDetector;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $model,
        private readonly string $voice,
        private readonly string $language = 'en',
        private readonly array $options = [],
        private readonly string $apiKey = '',
        private readonly int $timeoutMs = 30_000,
        private readonly ?string $voiceReferenceRoot = null,
        ?callable $providerFactory = null,
        ?callable $modeDetector = null,
    ) {
        $parts = parse_url($endpoint);
        $this->host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $this->allowLoopbackHttp = is_array($parts) && ($parts['scheme'] ?? null) === 'http';
        if ($this->host === '' || $model === '' || strlen($model) > 200 || $voice === '' || strlen($voice) > 512
            || $language === '' || strlen($language) > 35 || $timeoutMs < 1000 || $timeoutMs > 120_000
            || ($options !== [] && array_is_list($options))) {
            throw new \InvalidArgumentException('invalid_pockettts_configuration');
        }
        OutboundUrlPolicy::validate($endpoint, [$this->host], $this->allowLoopbackHttp);
        $this->providerFactory = $providerFactory === null
            ? fn(string $candidate, string $mode): SpeechProvider => $this->buildProvider($candidate, $mode)
            : Closure::fromCallable($providerFactory);
        $this->modeDetector = $modeDetector === null
            ? fn(string $candidate, CancellationToken $cancellation): string => $this->detectMode($candidate, $cancellation)
            : Closure::fromCallable($modeDetector);
    }

    public function synthesize(string $text, CancellationToken $cancellation, array $context = []): array
    {
        $configuredMode = $this->configuredMode($this->endpoint);
        try {
            return $this->provider($this->endpoint, $configuredMode)->synthesize($text, $cancellation, $context);
        } catch (OperationCancelled $error) {
            throw $error;
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'provider_unavailable') throw $error;
            $fallbacks = $this->fallbackEndpoints();
            if ($fallbacks === []) throw $error;
            $cancellation->throwIfCancellationRequested();
            if (($this->modeDetector)($this->endpoint, $cancellation) !== '') throw $error;
            foreach ($fallbacks as $fallback) {
                $cancellation->throwIfCancellationRequested();
                $mode = ($this->modeDetector)($fallback, $cancellation);
                if (!in_array($mode, ['audio_cpp', 'standard'], true)) continue;
                return $this->provider($fallback, $mode)->synthesize($text, $cancellation, $context);
            }
            throw $error;
        }
    }

    /** Build one existing bounded adapter for the detected PocketTTS API shape. */
    private function buildProvider(string $endpoint, string $mode): SpeechProvider
    {
        if ($mode === 'audio_cpp') {
            $url = rtrim($endpoint, '/');
            if (!str_ends_with($url, '/v1/audio/speech')) $url .= '/v1/audio/speech';
            return new OpenAiCompatibleSpeechProvider($url, [$this->host], $this->model, $this->voice,
                $this->apiKey, $this->timeoutMs, $this->allowLoopbackHttp, $this->voiceReferenceRoot, $this->language);
        }
        if ($mode === 'standard') return new XttsCompatibleSpeechProvider($endpoint, 'pockettts', $this->voice,
            $this->language, $this->options, $this->apiKey, $this->timeoutMs);
        throw new \InvalidArgumentException('invalid_pockettts_mode');
    }

    private function provider(string $endpoint, string $mode): SpeechProvider
    {
        $provider = ($this->providerFactory)($endpoint, $mode);
        if (!$provider instanceof SpeechProvider) throw new RuntimeException('provider_unavailable');
        return $provider;
    }

    /** Return same-host candidates only for the three known PocketTTS runtime ports. */
    private function fallbackEndpoints(): array
    {
        $parts = parse_url($this->endpoint);
        $configuredPort = is_array($parts) ? (int) ($parts['port'] ?? 0) : 0;
        if (!in_array($configuredPort, [8020, 8024, 8086], true) || !is_array($parts)) return [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) return [];
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') return [];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) $host = '[' . $host . ']';
        $path = preg_replace('#/(?:v1/audio/speech|tts_to_audio)/?$#', '', (string) ($parts['path'] ?? ''));
        $path = $path === null || $path === '/' ? '' : rtrim($path, '/');
        $candidates = [];
        foreach ([8086, 8024, 8020] as $port) {
            if ($port !== $configuredPort) $candidates[] = $scheme . '://' . $host . ':' . $port . $path;
        }
        return $candidates;
    }

    private function configuredMode(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $port = is_array($parts) ? (int) ($parts['port'] ?? 0) : 0;
        $path = is_array($parts) ? rtrim((string) ($parts['path'] ?? ''), '/') : '';
        return $port === 8086 || str_ends_with($path, '/v1/audio/speech') ? 'audio_cpp' : 'standard';
    }

    /** Identify PocketTTS without treating an XTTS service on legacy port 8020 as compatible. */
    private function detectMode(string $endpoint, CancellationToken $cancellation): string
    {
        $base = preg_replace('#/(?:v1/audio/speech|tts_to_audio)/?$#', '', rtrim($endpoint, '/'));
        if (!is_string($base) || $base === '') return '';
        $health = $this->probeJson($base . '/health', $cancellation);
        if ($health['ok']) {
            $models = $this->probeJson($base . '/v1/models', $cancellation);
            if ($models['ok']) {
                foreach ((array) ($models['decoded']['data'] ?? []) as $model) {
                    if (!is_array($model)) continue;
                    $id = strtolower(trim((string) ($model['id'] ?? '')));
                    $family = strtolower(trim((string) ($model['family'] ?? '')));
                    if ($id === 'pocket-tts' || $family === 'pocket_tts') return 'audio_cpp';
                }
            }
        }
        $providerInfo = $this->probeJson($base . '/provider_info', $cancellation);
        $provider = strtolower(trim((string) ($providerInfo['decoded']['provider'] ?? '')));
        if ($providerInfo['ok'] && in_array($provider, ['pockettts', 'pocket_tts', 'pocket-tts'], true)) return 'standard';
        $openApi = $this->probeJson($base . '/openapi.json', $cancellation);
        if (!$openApi['ok'] || !is_array($openApi['decoded'])) return '';
        $paths = array_keys(is_array($openApi['decoded']['paths'] ?? null) ? $openApi['decoded']['paths'] : []);
        if (in_array('/languages', $paths, true) || in_array('/get_models_list', $paths, true)) return '';
        return in_array('/tts_to_audio_form', $paths, true)
            || (in_array('/tts_to_audio', $paths, true) && in_array('/voices/{voice_id}', $paths, true)) ? 'standard' : '';
    }

    /** Fetch at most 1 MiB of local capability metadata with a one-second ceiling. */
    private function probeJson(string $url, CancellationToken $cancellation): array
    {
        $cancellation->throwIfCancellationRequested();
        $url = OutboundUrlPolicy::validate($url, [$this->host], $this->allowLoopbackHttp);
        $handle = curl_init($url);
        if ($handle === false) return ['ok' => false, 'decoded' => null];
        $body = '';
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 350,
            CURLOPT_TIMEOUT_MS => 1000,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded): int =>
                $cancellation->isCancellationRequested() ? 1 : 0,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1_048_576) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($cancellation->isCancellationRequested()) throw new OperationCancelled('operation_cancelled');
        } finally {
            curl_close($handle);
        }
        $decoded = $ok === true && $status >= 200 && $status < 300 ? json_decode($body, true) : null;
        return ['ok' => is_array($decoded), 'decoded' => $decoded];
    }
}
