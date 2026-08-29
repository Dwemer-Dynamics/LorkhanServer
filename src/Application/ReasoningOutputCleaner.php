<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

/** Remove one opted-in reasoning preamble without changing the JSON payload itself. */
final class ReasoningOutputCleaner
{
    public static function clean(string $content, bool $enabled): string
    {
        if (!$enabled) return $content;
        $start = strspn($content, " \t\r\n");
        $candidate = substr($content, $start);
        foreach (['think', 'thinking', 'reasoning'] as $tag) {
            $opening = '<' . $tag . '>';
            if (strncasecmp($candidate, $opening, strlen($opening)) !== 0) continue;
            $closing = '</' . $tag . '>';
            $end = stripos($candidate, $closing, strlen($opening));
            return $end === false ? $content : substr($candidate, $end + strlen($closing));
        }
        return $content;
    }
}
