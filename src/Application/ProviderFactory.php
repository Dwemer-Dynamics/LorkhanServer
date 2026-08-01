<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use RuntimeException;

final class ProviderFactory
{
    /** Build the configured dialogue provider for both HTTP fallback work and background workers. */
    public static function dialogue(array $config): Provider
    {
        $provider = self::section($config, 'provider');
        return match ((string) ($provider['driver'] ?? 'mock')) {
            'mock' => new MockProvider((string) ($provider['mock_prefix'] ?? '')),
            'openai-compatible' => new OpenAiCompatibleProvider(
                (string) ($provider['endpoint'] ?? ''),
                self::hosts($provider),
                (string) ($provider['model'] ?? ''),
                self::apiKey($provider, 'ALMSIVI_LLM_API_KEY'),
                (int) ($provider['timeout_ms'] ?? 30_000),
            ),
            default => throw new RuntimeException('Unsupported dialogue provider driver.'),
        };
    }

    /** Build optional text-to-speech service configuration. */
    public static function speech(array $config): ?SpeechProvider
    {
        $provider = self::section($config, 'speech_provider');
        return match ((string) ($provider['driver'] ?? 'mock')) {
            'disabled' => null,
            'mock' => new MockSpeechProvider(),
            'openai-compatible' => new OpenAiCompatibleSpeechProvider(
                (string) ($provider['endpoint'] ?? ''),
                self::hosts($provider),
                (string) ($provider['model'] ?? ''),
                (string) ($provider['voice'] ?? ''),
                self::apiKey($provider, 'ALMSIVI_TTS_API_KEY'),
                (int) ($provider['timeout_ms'] ?? 30_000),
            ),
            default => throw new RuntimeException('Unsupported speech provider driver.'),
        };
    }

    /** Build optional speech-to-text service configuration. */
    public static function speechToText(array $config): ?SpeechToTextProvider
    {
        $provider = self::section($config, 'stt_provider');
        return match ((string) ($provider['driver'] ?? 'mock')) {
            'disabled' => null,
            'mock' => new MockSpeechToTextProvider(),
            'openai-compatible' => new OpenAiCompatibleSpeechToTextProvider(
                (string) ($provider['endpoint'] ?? ''),
                self::hosts($provider),
                (string) ($provider['model'] ?? ''),
                self::apiKey($provider, 'ALMSIVI_STT_API_KEY'),
                (int) ($provider['timeout_ms'] ?? 30_000),
            ),
            default => throw new RuntimeException('Unsupported speech-to-text provider driver.'),
        };
    }

    /** @return array<string,mixed> */
    private static function section(array $config, string $key): array
    {
        $section = $config[$key] ?? [];
        if (!is_array($section)) throw new RuntimeException($key . ' configuration is invalid.');
        return $section;
    }

    /** @param array<string,mixed> $provider @return list<string> */
    private static function hosts(array $provider): array
    {
        $hosts = $provider['allowed_hosts'] ?? [];
        return is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : [];
    }

    /** @param array<string,mixed> $provider */
    private static function apiKey(array $provider, string $defaultVariable): string
    {
        $variable = (string) ($provider['api_key_env'] ?? $defaultVariable);
        if ($variable === '' || preg_match('/^[A-Z_][A-Z0-9_]*$/D', $variable) !== 1) {
            throw new RuntimeException('Provider API key environment variable is invalid.');
        }
        $value = getenv($variable);
        return is_string($value) ? $value : '';
    }
}
