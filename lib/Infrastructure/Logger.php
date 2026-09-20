<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Security\Redactor;

/** CHIM log formats, with private storage and best-effort writes for the web/worker runtime. */
final class Logger
{
    public const FILES = ['lorkhan.log', 'context_sent_to_llm.log', 'context_sent_to_llm_fast.log',
        'output_from_llm.log', 'output_from_llm_fast.log', 'output_to_plugin.log', 'stt.log'];

    public static function info(string $message, string $file = 'lorkhan.log'): void { self::log('info', $message, $file); }
    public static function warn(string $message, string $file = 'lorkhan.log'): void { self::log('warn', $message, $file); }
    public static function error(string $message, string $file = 'lorkhan.log'): void { self::log('error', $message, $file); }

    private static function log(string $level, string $message, string $file): void
    {
        $message = Redactor::value($message);
        self::write($file, '['.date('Y-m-d\TH:i:sP')."] [{$level}] {$message}\n");
        if (in_array($level, ['warn', 'error'], true)) {
            try { @error_log("[{$level}] {$message}"); } catch (\Throwable) {}
        }
    }

    public static function context(array $request, string $apiKey = '', bool $fast = false): void
    {
        self::write($fast ? 'context_sent_to_llm_fast.log' : 'context_sent_to_llm.log',
            date(DATE_ATOM)."\n=\n".var_export(Redactor::value($request), true)."\n=\n", $apiKey);
    }

    public static function output(string $content, string $apiKey = '', bool $fast = false, ?string $started = null): void
    {
        if ($content === '') return;
        self::write($fast ? 'output_from_llm_fast.log' : 'output_from_llm.log', $fast
            ? date(DATE_ATOM)."\n=\n".$content."\n=\n"
            : "\n== ".($started ?? date(DATE_ATOM))." START\n\n".$content."\n\n== ".date(DATE_ATOM)." END\n\n", $apiKey);
    }

    /** Keep complete CHIM blocks together; trim at CHIM's 25 MiB threshold without replacing the inode. */
    public static function write(string $file, string $text, string $apiKey = ''): void
    {
        $handle = false;
        try {
            if (!in_array($file, self::FILES, true) || strlen($text) > 2_200_000) return;
            $text = Redactor::value($text);
            if ($apiKey !== '') $text = str_replace([$apiKey, substr(var_export($apiKey, true), 1, -1)], '[REDACTED]', $text);
            $root = getenv('LORKHAN_LOG_DIR') ?: '/var/log/lorkhanserver';
            $path = rtrim($root, '/\\').'/'.$file;
            // Deployment creates shared files. Never create a root-owned file or follow a symlink here.
            if (!is_file($path) || is_link($path)) return;
            $handle = @fopen($path, 'r+b');
            if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) return;
            if ((fstat($handle)['size'] ?? 0) + strlen($text) > 26_214_400 && !@ftruncate($handle, 0)) return;
            if (@fseek($handle, 0, SEEK_END) !== 0) return;
            $offset = 0;
            while ($offset < strlen($text)) {
                $written = @fwrite($handle, substr($text, $offset));
                if ($written === false || $written === 0) break;
                $offset += $written;
            }
        } catch (\Throwable) {
            // Diagnostic storage must never fail or delay an AI request.
        } finally {
            if (is_resource($handle)) { @flock($handle, LOCK_UN); @fclose($handle); }
        }
    }
}
