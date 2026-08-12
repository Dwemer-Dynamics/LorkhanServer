<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

/** Extracts safe visible text growth from a streamed structured response. */
final class StreamingDialogueText
{
    private string $content = '';
    private string $visible = '';
    private string $emitted = '';

    /** @return list<string> */
    public function push(string $contentDelta, bool $final = false): array
    {
        $this->content .= $contentDelta;
        $next = $this->extractVisibleText($this->content);
        if (str_starts_with($next, $this->visible)) {
            $this->visible = $next;
        }

        $pending = substr($this->visible, strlen($this->emitted));
        if ($pending === '') return [];
        $flushBytes = 0;
        if ($final) {
            $flushBytes = strlen($pending);
        } elseif (strlen($pending) >= 24) {
            $window = substr($pending, 0, 128);
            if (preg_match_all('/[.!?](?:\s|$)|\s+/u', $window, $matches, PREG_OFFSET_CAPTURE)) {
                $last = end($matches[0]);
                if (is_array($last)) $flushBytes = $last[1] + strlen($last[0]);
            }
            if ($flushBytes === 0 && strlen($pending) >= 128) $flushBytes = 128;
        }
        if ($flushBytes === 0) return [];

        $chunk = substr($pending, 0, $flushBytes);
        if (!mb_check_encoding($chunk, 'UTF-8')) return [];
        $this->emitted .= $chunk;
        return [$chunk];
    }

    private function extractVisibleText(string $json): string
    {
        $visible = '';
        $offset = 0;
        while (preg_match('/(?<!\\\\)"text"\s*:\s*"/u', $json, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1] + strlen($match[0][0]);
            $index = $start;
            $escaped = false;
            while ($index < strlen($json)) {
                $character = $json[$index];
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    break;
                }
                ++$index;
            }
            $raw = substr($json, $start, $index - $start);
            while ($raw !== '' && preg_match('/\\\\(?:u[0-9a-fA-F]{0,3})?$/D', $raw) === 1) {
                $raw = substr($raw, 0, -1);
            }
            try {
                $decoded = json_decode('"' . $raw . '"', true, 8, JSON_THROW_ON_ERROR);
                if (is_string($decoded)) $visible .= ($visible === '' ? '' : "\n") . $decoded;
            } catch (\JsonException) {
                // Wait for a later chunk that completes the JSON escape or string.
            }
            if ($index >= strlen($json)) break;
            $offset = $index + 1;
        }
        return $visible;
    }
}
