<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Validate server-owned LLM slots without resolving hosts or reading credentials during CRUD. */
final class LlmConnector
{
    public const CREDENTIALS = [
        'none' => '',
        'default' => 'LORKHAN_LLM_API_KEY',
        'openai' => 'LORKHAN_LLM_OPENAI_API_KEY',
        'openrouter' => 'LORKHAN_LLM_OPENROUTER_API_KEY',
        'custom' => 'LORKHAN_LLM_CUSTOM_API_KEY',
    ];

    public const OPTION_RULES = [
        'temperature' => ['type' => 'number', 'minimum' => 0, 'maximum' => 2],
        'max_tokens' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 32768],
        'max_completion_tokens' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 32768],
        'top_p' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        'top_k' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
        'min_p' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        'top_a' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        'frequency_penalty' => ['type' => 'number', 'minimum' => -2, 'maximum' => 2],
        'presence_penalty' => ['type' => 'number', 'minimum' => -2, 'maximum' => 2],
        'repetition_penalty' => ['type' => 'number', 'minimum' => 0, 'maximum' => 2],
        'stream' => ['type' => 'boolean'],
        'json_mode' => ['type' => 'boolean'],
        'disable_reasoning' => ['type' => 'boolean'],
        'reasoning_model' => ['type' => 'boolean'],
    ];

    public static function validate(array $content): array
    {
        $driver = $content['driver'] ?? null;
        if (!in_array($driver, ['configured', 'mock', 'openai-compatible'], true)) {
            throw new InvalidArgumentException('invalid_provider_driver');
        }
        $model = $content['model'] ?? ($driver === 'mock' ? 'deterministic-mock-v1' : null);
        if (!is_string($model) || trim($model) === '' || strlen($model) > 256 || !mb_check_encoding($model, 'UTF-8')) {
            throw new InvalidArgumentException('invalid_provider_model');
        }
        $allowed = ['driver', 'model', 'timeout_ms'];
        if ($driver === 'mock') $allowed[] = 'mock_prefix';
        else $allowed[] = 'options';
        if ($driver === 'openai-compatible') $allowed = array_merge($allowed, ['endpoint', 'credential']);
        if (array_diff(array_keys($content), $allowed) !== []) throw new InvalidArgumentException('invalid_provider_content');
        $result = ['driver' => $driver, 'model' => $model];
        if ($driver === 'mock') {
            $prefix = $content['mock_prefix'] ?? '';
            if (!is_string($prefix) || strlen($prefix) > 256) throw new InvalidArgumentException('invalid_provider_content');
            return $result + ['mock_prefix' => $prefix];
        }
        if (array_key_exists('timeout_ms', $content)) {
            $timeout = $content['timeout_ms'];
            if (!is_int($timeout) || $timeout < 1000 || $timeout > 120000) throw new InvalidArgumentException('invalid_provider_timeout');
            $result['timeout_ms'] = $timeout;
        }
        if (array_key_exists('options', $content)) {
            if (!is_array($content['options'])) throw new InvalidArgumentException('invalid_provider_options');
            $options = self::validateOptions($content['options']);
            if ($options !== []) $result['options'] = $options;
        }
        if ($driver === 'openai-compatible') {
            $endpoint = $content['endpoint'] ?? null;
            if (!is_string($endpoint) || strlen($endpoint) > 2048 || preg_match('/[\x00-\x20\x7f]/', $endpoint)) {
                throw new InvalidArgumentException('invalid_provider_endpoint');
            }
            $parts = parse_url($endpoint);
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
                || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                throw new InvalidArgumentException('invalid_provider_endpoint');
            }
            $host = strtolower(rtrim($parts['host'], '.'));
            $loopback = $host === 'localhost' || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.'));
            if ($parts['scheme'] === 'http' && !$loopback) throw new InvalidArgumentException('invalid_provider_endpoint');
            $credential = $content['credential'] ?? 'none';
            if (!is_string($credential) || !array_key_exists($credential, self::CREDENTIALS)) {
                throw new InvalidArgumentException('invalid_provider_credential');
            }
            $result += ['endpoint' => $endpoint, 'credential' => $credential, 'timeout_ms' => 30000];
        }
        return $result;
    }

    /** Reject unknown transport keys and retain explicit zero/false overrides. */
    public static function validateOptions(array $options): array
    {
        foreach ($options as $name => $value) {
            $rule = self::OPTION_RULES[$name] ?? null;
            if ($rule === null) throw new InvalidArgumentException('invalid_provider_options');
            if ($rule['type'] === 'boolean') {
                if (!is_bool($value)) throw new InvalidArgumentException('invalid_provider_option_' . $name);
            } elseif (($rule['type'] === 'integer' && !is_int($value)) || (!is_int($value) && !is_float($value))
                || !is_finite((float) $value) || $value < $rule['minimum'] || $value > $rule['maximum']) {
                throw new InvalidArgumentException('invalid_provider_option_' . $name);
            }
        }
        if (isset($options['max_tokens'], $options['max_completion_tokens'])) throw new InvalidArgumentException('conflicting_provider_token_limits');
        return $options;
    }

    /** Share bounded sampling/JSON hints across adapters while leaving their output contracts strict. */
    public static function requestOptions(array $options, ?float $defaultTemperature, bool $disableReasoning): array
    {
        $options = self::validateOptions($options);
        $request = [];
        if ($defaultTemperature !== null) $request['temperature'] = $defaultTemperature;
        foreach ($options as $name => $value) if (self::OPTION_RULES[$name]['type'] !== 'boolean') $request[$name] = $value;
        if ($options['json_mode'] ?? true) $request['response_format'] = ['type' => 'json_object'];
        if ($options['disable_reasoning'] ?? $disableReasoning) $request['reasoning'] = ['exclude' => true, 'enabled' => false];
        return $request;
    }
}
