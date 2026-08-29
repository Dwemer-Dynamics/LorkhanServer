<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use InvalidArgumentException;

/** Server-only opt-in policy; it is never part of the client settings protocol. */
final class MemorySummaryPolicy
{
    public static function validate(array $content): array
    {
        $keys = array_keys($content); sort($keys);
        if ($keys !== ['enabled', 'provider_configuration_id', 'schema']
            || ($content['schema'] ?? null) !== 'almsivi.memory-policy.v1'
            || !is_bool($content['enabled'] ?? null)
            || !is_string($content['provider_configuration_id'] ?? null)) {
            throw new InvalidArgumentException('invalid_memory_policy');
        }
        $id = $content['provider_configuration_id'];
        if (($id === '' && $content['enabled']) || ($id !== ''
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id) !== 1)) {
            throw new InvalidArgumentException('invalid_memory_provider');
        }
        return $content;
    }

    public static function summary(array $output): string
    {
        $text = $output['summary'] ?? null;
        if (array_keys($output) !== ['summary'] || !is_string($text) || trim($text) === ''
            || strlen($text) > 4096 || str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('invalid_memory_summary');
        }
        return trim($text);
    }
}
