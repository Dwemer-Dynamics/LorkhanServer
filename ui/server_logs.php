<?php

declare(strict_types=1);

$pageTitle = 'Server Logs';
$topNavSection = 'control';
$BODY_CLASS = 'configuration-resource view-server-logs';
require __DIR__ . '/ui_bootstrap.php';

/** Read only the bounded tail of an allowlisted local service log. */
function lorkhan_ui_log_tail(string $path, int $maxBytes = 262144, int $maxLines = 200): ?string
{
    if (!is_file($path) || !is_readable($path)) return null;
    $handle = @fopen($path, 'rb');
    if ($handle === false) return null;
    try {
        $size = @filesize($path);
        if (is_int($size) && $size > $maxBytes) @fseek($handle, -$maxBytes, SEEK_END);
        $text = stream_get_contents($handle, $maxBytes);
    } finally {
        fclose($handle);
    }
    if (!is_string($text)) return null;
    $lines = preg_split('/\R/u', $text) ?: [];
    if (count($lines) > $maxLines) $lines = array_slice($lines, -$maxLines);
    return implode("\n", $lines);
}

/** Redact common credential forms before operational text reaches the browser. */
function lorkhan_ui_redact_log(string $text): string
{
    $text = preg_replace('/(?i)\b(authorization|api[_-]?key|access[_-]?token|pairing[_-]?token|secret|password)(\s*[:=]\s*)([^\s,;]+)/', '$1$2[REDACTED]', $text) ?? $text;
    return preg_replace('/(?i)([?&](?:key|token|secret|password)=)[^&\s]+/', '$1[REDACTED]', $text) ?? $text;
}

$logSources = [
    ['Worker', '/var/log/lorkhanserver/worker.log'],
    ['Apache / PHP errors', '/var/log/apache2/lorkhanserver-error.log'],
    ['Apache requests', '/var/log/apache2/lorkhanserver-access.log'],
];

include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="lorkhan-page control-specialist-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <header class="lorkhan-page-header lorkhan-page-head"><h1 class="lorkhan-page-head-title">Server Logs</h1><p class="lorkhan-page-head-note">Inspect the latest bounded, redacted output from the LORKHAN worker and local Apache service.</p>
        <a class="btn-base control-refresh-link" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/server_logs.php<?php echo $embedded ? '?embed=1' : ''; ?>">Refresh logs</a>
    </header>
    <div class="log-grid">
        <?php foreach ($logSources as [$label, $path]): ?>
            <?php $contents = lorkhan_ui_log_tail($path); ?>
            <section class="widget widget-wide control-log-card">
                <div class="widget-header"><h3><?php echo lorkhan_ui_h($label); ?></h3></div>
                <div class="widget-content">
                    <?php if ($contents === null || trim($contents) === ''): ?>
                        <p class="empty-state">No readable log output is available.</p>
                    <?php else: ?>
                        <pre class="server-log" tabindex="0"><?php echo lorkhan_ui_h(lorkhan_ui_redact_log($contents)); ?></pre>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
