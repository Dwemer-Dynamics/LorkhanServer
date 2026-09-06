<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

final class BackgroundWorkerStatus
{
    /** Inspect the supervisor only; opening a page never starts or restarts workers. */
    public static function read(string $runRoot = '/run', string $procRoot = '/proc', ?callable $systemdQuery = null): string
    {
        if (!is_dir($procRoot)) return 'Unavailable';
        if (trim((string) @file_get_contents($procRoot.'/1/comm', false, null, 0, 32)) === 'systemd') {
            $units = ($systemdQuery ?? self::systemdUnits(...))();
            if (!is_array($units)) return 'Unavailable';
            $service = $units['lorkhanserver-worker.service'] ?? [];
            $timer = $units['lorkhanserver-worker.timer'] ?? [];
            if (in_array($service['ActiveState'] ?? '', ['active', 'activating'], true)) return 'Running';
            if (($service['ActiveState'] ?? '') === 'failed') return 'Failed';
            if (($timer['ActiveState'] ?? '') === 'active') return 'Waiting (timer active)';
            return ($service['LoadState'] ?? '') === 'loaded' || ($timer['LoadState'] ?? '') === 'loaded' ? 'Stopped' : 'Unavailable';
        }

        $pidFile = $runRoot.'/lorkhanserver-worker.pid';
        if (!is_file($pidFile)) return 'Stopped';
        $pid = trim((string) @file_get_contents($pidFile, false, null, 0, 32));
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $pid) !== 1) return 'Unavailable';
        if (!is_dir($procRoot.'/'.$pid)) return 'Stopped';
        $command = @file_get_contents($procRoot.'/'.$pid.'/cmdline', false, null, 0, 4096);
        if ($command === false) return 'Unavailable';
        // A stale PID can belong to another process. Require the installed supervisor's exact argument.
        return in_array('/usr/local/libexec/lorkhanserver-worker-loop', explode("\0", $command), true) ? 'Running' : 'Stopped';
    }

    /** Query fixed systemd units with no shell and a short observation timeout. */
    private static function systemdUnits(): ?array
    {
        if (!function_exists('proc_open') || !is_executable('/usr/bin/systemctl')) return null;
        $process = @proc_open(['/usr/bin/systemctl', 'show', '--no-pager', '--property=Id,ActiveState,LoadState',
            'lorkhanserver-worker.service', 'lorkhanserver-worker.timer'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) return null;
        stream_set_blocking($pipes[1], false);
        $output = ''; $deadline = hrtime(true) + 300_000_000;
        do {
            $output .= (string) stream_get_contents($pipes[1], max(1, 4096 - strlen($output)));
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(10_000);
        } while (hrtime(true) < $deadline && strlen($output) < 4096);
        if ($status['running']) {
            proc_terminate($process, 9);
            fclose($pipes[1]); proc_close($process);
            return null;
        }
        $output .= (string) stream_get_contents($pipes[1], max(1, 4096 - strlen($output)));
        fclose($pipes[1]); proc_close($process);
        $units = [];
        foreach (preg_split('/\n\s*\n/', trim($output)) ?: [] as $block) {
            $properties = [];
            foreach (explode("\n", $block) as $line) {
                $pair = explode('=', $line, 2);
                if (count($pair) === 2) $properties[$pair[0]] = $pair[1];
            }
            if (isset($properties['Id'])) $units[$properties['Id']] = $properties;
        }
        return $units;
    }
}
