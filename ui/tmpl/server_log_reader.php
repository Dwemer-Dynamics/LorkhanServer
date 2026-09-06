<?php
declare(strict_types=1);

/** Read a bounded tail without exposing an incomplete first line or invalid UTF-8. */
function lorkhan_ui_log_tail(string $path, int $maxBytes = 262144, int $maxLines = 200): ?string
{
    if (!is_file($path) || !is_readable($path)) return null;
    $handle = @fopen($path, 'rb');
    if ($handle === false) return null;
    try {
        $size = fstat($handle)['size'] ?? 0;
        $truncated = $size > $maxBytes;
        if ($truncated) fseek($handle, -$maxBytes, SEEK_END);
        $text = stream_get_contents($handle, $maxBytes);
    } finally { fclose($handle); }
    if (!is_string($text)) return null;
    if ($truncated) $text = str_contains($text, "\n") ? substr($text, strpos($text, "\n") + 1) : '';
    $lines = preg_split('/\R/u', mb_scrub(rtrim($text, "\r\n"), 'UTF-8')) ?: [];
    return implode("\n", array_slice($lines, -$maxLines));
}

/** Remove credential forms before text is rendered, searched, expanded or downloaded. */
function lorkhan_ui_redact_log(string $text): string
{
    $text = (string) \LorkhanServer\Security\Redactor::value($text);
    $keys = 'authorization|(?:provider[_-]?)?api[_-]?key|provider[_-]?key|access[_-]?token|pairing[_-]?token|client[_-]?secret|secret|password|token|cookie|set-cookie';
    $text = preg_replace_callback('/((?:"?(?:'.$keys.')"?)\s*[:=]\s*)("(?:\\\\.|[^"\\\\])*"|\x27[^\x27]*\x27|(?:Bearer|Basic)\s+[^\s,;]+|[^\s,;]+)/i',
        static fn(array $match): string => $match[1].(str_starts_with($match[2], '"') ? '"[REDACTED]"' : '[REDACTED]'), $text) ?? $text;
    return preg_replace('/([?&](?:key|'.$keys.')=)[^&\s"\x27]+/i', '$1[REDACTED]', $text) ?? $text;
}

/** Project worker JSON and Apache/PHP lines into the debugger's timestamp/level/message rows. */
function lorkhan_ui_log_entries(string $text, bool $raw = false): array
{
    $entries = [];
    $levels = ['warning'=>'warn','warn'=>'warn','error'=>'error','critical'=>'error','fatal'=>'error','alert'=>'error',
        'emerg'=>'error','notice'=>'info','info'=>'info','debug'=>'debug','trace'=>'trace'];
    foreach (preg_split('/\R/u', lorkhan_ui_redact_log($text)) ?: [] as $line) {
        if (trim($line) === '') continue;
        $entry = ['timestamp'=>'','iso'=>'','level'=>'','message'=>$line];
        if (!$raw) {
            $json = json_decode($line, true);
            if (is_array($json) && !array_is_list($json)) {
                $time = $json['timestamp'] ?? $json['time'] ?? $json['ts'] ?? '';
                $entry['timestamp'] = is_string($time) ? $time : '';
                $level = $json['level'] ?? $json['severity'] ?? '';
                $entry['level'] = is_string($level) ? ($levels[strtolower($level)] ?? '') : '';
                unset($json['timestamp'], $json['time'], $json['ts'], $json['level'], $json['severity']);
                $entry['message'] = (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif (preg_match('/^\[([^\]]+)\]\s+\[(?:[^:\]]+:)?([a-z]+)\]\s*(.*)$/i', $line, $match)) {
                $entry['timestamp']=$match[1]; $entry['level']=$levels[strtolower($match[2])]??''; $entry['message']=$match[3];
            } elseif (preg_match('/^\[([^\]]+)\]\s+PHP\s+([^:]+):\s*(.*)$/i', $line, $match)) {
                $entry['timestamp']=$match[1]; $severity=strtolower($match[2]);
                $entry['level']=str_contains($severity,'error')?'error':(str_contains($severity,'warn')?'warn':'info');
                $entry['message']='PHP '.$match[2].': '.$match[3];
            } elseif (preg_match('/\b(TRACE|DEBUG|INFO|WARN(?:ING)?|ERROR|CRITICAL|FATAL)\b/i', $line, $match)) {
                $entry['level']=$levels[strtolower($match[1])]??'';
            }
            // A timezone-less Apache timestamp must not be misrepresented as UTC.
            if ($entry['timestamp'] !== '' && preg_match('/(?:Z|UTC|[+-]\d{2}:?\d{2})$/i', $entry['timestamp'])) {
                try { $entry['iso']=(new DateTimeImmutable($entry['timestamp']))->format(DATE_ATOM); }
                catch (Exception) { /* Preserve the recorded timestamp when it cannot be parsed. */ }
            }
            $last=count($entries)-1;
            if (!is_array($json) && $entry['timestamp']==='' && $entry['level']==='' && $last>=0
                && ($entries[$last]['timestamp']!=='' || $entries[$last]['level']!=='')) {
                $entries[$last]['message'].="\n".$entry['message'];
                continue;
            }
        }
        $entries[]=$entry;
    }
    return array_reverse($entries);
}
