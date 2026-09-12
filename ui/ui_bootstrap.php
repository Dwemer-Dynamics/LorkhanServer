<?php

declare(strict_types=1);

use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\ManagementRepository;
use LorkhanServer\Infrastructure\ManagementUiRepository;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Security\BrowserSession;

$applicationRoot = dirname(__DIR__);
require_once $applicationRoot . '/lib/Autoload.php';

$pageTitle = isset($pageTitle) ? (string) $pageTitle : 'LORKHAN';
$topNavSection = isset($topNavSection) ? (string) $topNavSection : '';
$embedded = isset($_GET['embed']) && $_GET['embed'] === '1';
$uiAssetVersion = (string) max(
    (int) @filemtime(__DIR__ . '/js/lorkhan-management.js'),
    (int) @filemtime(__DIR__ . '/js/resource-page.js')
);

try {
    $configFile = getenv('LORKHAN_CONFIG') ?: $applicationRoot . '/conf/server.php';
    if (!is_file($configFile)) throw new RuntimeException('Server configuration is unavailable.');
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Server configuration is invalid.');
    $config['credential_storage_path'] ??= '/var/lib/lorkhanserver/credentials/provider-keys.json';
    $config['database_password'] = getenv('LORKHAN_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');

    $database = Connection::open($config);
    $managementRepository = new ManagementRepository($database);
    $uiRepository = new ManagementUiRepository($database);
    $productRepository = new ProductRepository($database);
    $managementBasePath = rtrim((string) ($config['management_base_path'] ?? '/LorkhanServer/manage'), '/');
    $webRoot = preg_replace('#/manage$#', '', $managementBasePath) ?: '/LorkhanServer';
    $sessionTtl = (int) ($config['browser_session_ttl_seconds'] ?? 3600);

    $cookieHeader = $_SERVER['HTTP_COOKIE'] ?? null;
    $browserSession = BrowserSession::parse(is_string($cookieHeader) ? $cookieHeader : null);
    $csrf = BrowserSession::parseCsrf(is_string($cookieHeader) ? $cookieHeader : null);
    if ($browserSession === null || $csrf === null || !$managementRepository->validate($browserSession, $csrf)) {
        $created = $managementRepository->createSession($sessionTtl);
        $browserSession = $created['session'];
        $csrf = $created['csrf'];
        header('Set-Cookie: ' . BrowserSession::cookie($browserSession, $sessionTtl, $webRoot), false);
        header('Set-Cookie: ' . BrowserSession::csrfCookie($csrf, $sessionTtl, $webRoot), false);
    }

    // Retire the previous narrow-path cookies so /manage writes receive one unambiguous token pair.
    header('Set-Cookie: lorkhan_management=; Path=' . $managementBasePath . '; Max-Age=0; HttpOnly; SameSite=Strict', false);
    header('Set-Cookie: lorkhan_csrf=; Path=' . $managementBasePath . '; Max-Age=0; SameSite=Strict', false);

    header('Content-Type: text/html; charset=utf-8');
    // media-src covers audio the page builds in memory, such as the TTS Studio pronunciation
    // preview, which fetches bytes over connect-src and plays them from a blob: object URL.
    $uiStyleNonce=base64_encode(random_bytes(18));
    header("Content-Security-Policy: default-src 'none'; style-src 'self' 'nonce-{$uiStyleNonce}'; script-src 'self'; font-src 'self'; img-src 'self' data:; media-src 'self' blob:; connect-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
} catch (Throwable) {
    // This can fail before the configuration is read, so $webRoot may never have
    // been assigned. Derive the asset root from the request path instead; every
    // UI entry point lives under <root>/ui/.
    $scriptPath = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $uiOffset = strpos($scriptPath, '/ui/');
    $assetRoot = htmlspecialchars(
        $uiOffset === false ? '/LorkhanServer' : substr($scriptPath, 0, $uiOffset),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    // Same-origin stylesheet, font, and mark only. Script, connect, frame, and
    // form remain denied by default-src 'none'.
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    echo '<!doctype html><html lang="en" data-bs-theme="dark"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>LORKHAN unavailable</title>';
    echo '<link rel="icon" type="image/x-icon" href="' . $assetRoot . '/ui/images/favicon.ico">';
    echo '<link rel="stylesheet" href="' . $assetRoot . '/ui/css/style_new.css">';
    echo '<link rel="stylesheet" href="' . $assetRoot . '/ui/css/chim-theme.css">';
    echo '</head><body class="lorkhan-outage"><main class="lorkhan-outage-panel">';
    echo '<img class="lorkhan-outage-mark" src="' . $assetRoot . '/ui/images/lorkhan-logo.png" width="512" height="512" alt="" aria-hidden="true">';
    echo '<h1>LorkhanServer is unavailable</h1>';
    echo '<p>Check the local server configuration and database service.</p>';
    echo '</main></body></html>';
    exit;
}

/** Escape text for safe use in server-rendered management HTML. */
function lorkhan_ui_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Render structured values compactly without exposing HTML from stored records. */
function lorkhan_ui_value(mixed $value): string
{
    if (is_array($value)) {
        return lorkhan_ui_h(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if ($value === null || $value === '') return '—';
    return lorkhan_ui_h($value);
}

/** Show the resolved Global -> Core Profile -> NPC value and source for each applicable setting. */
function lorkhan_ui_effective_settings_summary(array $effective, string $title = 'Effective settings and inheritance', bool $open = false): void
{
    $sources = is_array($effective['sources'] ?? null) ? $effective['sources'] : [];
    if ($sources === []) return;
    $sourceLabels = ['default' => 'Built-in default', 'global' => 'Global Settings', 'core_profile' => 'Core Profile', 'npc' => 'Character profile'];
    $activeBehaviorPaths = array_fill_keys([
        'settings.behavior.rechat', 'settings.behavior.rechat_max_depth', 'settings.behavior.rechat_probability_percent',
        'settings.behavior.rechat_mode', 'settings.behavior.rechat_strict_targeting', 'settings.behavior.open_rechat',
        'settings.behavior.rechat_allow_actions',
        'settings.behavior.end_conversation_cooldown_seconds',
    ], true);
    echo '<details class="effective-settings-summary"' . ($open ? ' open' : '') . '><summary>' . lorkhan_ui_h($title) . '</summary>';
    echo '<p>Resolution order: character voice or knowledge &gt; assigned Core Profile &gt; Global Settings &gt; built-in default.</p><div class="effective-settings-grid">';
    foreach ($sources as $path => $source) {
        if (!is_string($path) || ($source === 'excluded') || $path === 'settings.memory.knowledge_limit'
            || (str_starts_with($path, 'settings.behavior.') && !isset($activeBehaviorPaths[$path]))
            || (!str_starts_with($path, 'settings.behavior.') && !str_starts_with($path, 'settings.memory.')
                && !str_starts_with($path, 'settings.relationship.') && !str_starts_with($path, 'settings.diary.')
                && !str_starts_with($path, 'settings.oghma.') && !str_starts_with($path, 'routing.'))) continue;
        $value = $effective;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) { $value = null; break; }
            $value = $value[$segment];
        }
        if (is_array($value)) continue;
        $label = ucwords(str_replace('_', ' ', str_replace(['settings.', '.'], ['', ' / '], $path)));
        echo '<article><span>' . lorkhan_ui_h($label) . '</span><strong>' . lorkhan_ui_value($value) . '</strong>';
        echo '<small data-effective-source="' . lorkhan_ui_h($source) . '">' . lorkhan_ui_h($sourceLabels[$source] ?? (string) $source) . '</small></article>';
    }
    echo '</div></details>';
}

/** Render a table value with the optional compact monitoring presentation requested by a page. */
function lorkhan_ui_table_value(mixed $value, string $formatter = ''): string
{
    if ($formatter === '') return lorkhan_ui_value($value);

    $raw = is_array($value)
        ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : (string) $value;

    if ($formatter === 'status') {
        if ($value === null || $raw === '') return '<span class="lorkhan-state-pill is-neutral">Unknown</span>';
        $label = ucwords(str_replace(['_', '-'], ' ', $raw));
        $normalized = strtolower($raw);
        $tone = 'is-neutral';
        if (preg_match('/fail|failed|error|expired|dead|cancel|interrupted|denied|unhealthy|invalid/', $normalized)) {
            $tone = 'is-danger';
        } elseif (preg_match('/pending|queued|processing|retry|unlinked|partial|warning|stale/', $normalized)) {
            $tone = 'is-warning';
        } elseif (preg_match('/success|succeeded|complete|completed|ready|healthy|current|spoken|delivered|linked|active|enabled|valid/', $normalized)) {
            $tone = 'is-success';
        }
        return '<span class="lorkhan-state-pill ' . $tone . '">' . lorkhan_ui_h($label) . '</span>';
    }

    if ($formatter === 'timestamp') {
        if ($value === null || trim($raw) === '') return '—';
        try {
            $time = new DateTimeImmutable($raw);
            return '<time datetime="' . lorkhan_ui_h($time->format(DateTimeInterface::ATOM)) . '" title="' . lorkhan_ui_h($raw) . '">'
                . lorkhan_ui_h($time->format('Y-m-d H:i:s P')) . '</time>';
        } catch (Throwable) {
            return lorkhan_ui_h($raw);
        }
    }

    if ($formatter === 'duration') {
        if (!is_numeric($value)) return lorkhan_ui_value($value);
        $milliseconds = max(0.0, (float) $value);
        if ($milliseconds < 1000) return lorkhan_ui_h(number_format($milliseconds, 0) . ' ms');
        if ($milliseconds < 60000) return lorkhan_ui_h(number_format($milliseconds / 1000, 2) . ' s');
        return lorkhan_ui_h(number_format($milliseconds / 60000, 1) . ' min');
    }

    if ($formatter === 'bytes') {
        if (!is_numeric($value)) return lorkhan_ui_value($value);
        $bytes = max(0.0, (float) $value);
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $unit = 0;
        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }
        $precision = $unit === 0 ? 0 : ($bytes >= 100 ? 0 : 1);
        return lorkhan_ui_h(number_format($bytes, $precision) . ' ' . $units[$unit]);
    }

    if ($formatter === 'compact_id') {
        if ($value === null || $raw === '') return '—';
        $compact = mb_strlen($raw) > 18 ? mb_substr($raw, 0, 8) . '…' . mb_substr($raw, -4) : $raw;
        return '<code class="control-compact-id" title="' . lorkhan_ui_h($raw) . '">' . lorkhan_ui_h($compact) . '</code>';
    }

    if ($formatter === 'payload') {
        if ($value === null || $raw === '') return '—';
        $preview = preg_replace('/\s+/', ' ', $raw) ?: $raw;
        $preview = mb_strimwidth($preview, 0, 90, '…');
        return '<div class="control-payload"><span class="control-payload-preview">' . lorkhan_ui_h($preview)
            . '</span><details><summary>View details</summary><pre>' . lorkhan_ui_h($raw) . '</pre></details></div>';
    }

    return lorkhan_ui_value($value);
}

/** Render a bounded repository result using the common sibling-server table structure. */
function lorkhan_ui_table(array $rows, string $emptyMessage = 'No records are available yet.', array $options = []): void
{
    if ($rows === []) {
        $monitoringClass = !empty($options['monitoring']) ? ' control-zero-state' : '';
        echo '<p class="empty-state' . $monitoringClass . '" role="status">' . lorkhan_ui_h($emptyMessage) . '</p>';
        return;
    }
    $columns = array_keys($rows[0]);
    $labels = is_array($options['labels'] ?? null) ? $options['labels'] : [];
    $formatters = is_array($options['formatters'] ?? null) ? $options['formatters'] : [];
    $monitoring = !empty($options['monitoring']);
    $countLabel = trim((string) ($options['count_label'] ?? 'records')) ?: 'records';
    $regionAttributes = $monitoring
        ? ' control-table-region" data-control-table-region data-count-label="' . lorkhan_ui_h($countLabel) . '"'
        : '"';
    $tableAttributes = $monitoring
        ? ' control-data-table" data-control-table aria-label="' . lorkhan_ui_h(ucfirst($countLabel)) . '"'
        : '"';
    echo '<div class="table-responsive' . $regionAttributes . '><table class="table table-dark table-hover align-middle' . $tableAttributes . '><thead><tr>';
    foreach ($columns as $column) {
        $label = isset($labels[$column]) ? (string) $labels[$column] : ucwords(str_replace('_', ' ', $column));
        echo '<th scope="col">' . lorkhan_ui_h($label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $column) {
            $label = isset($labels[$column]) ? (string) $labels[$column] : ucwords(str_replace('_', ' ', $column));
            $formatter = isset($formatters[$column]) ? (string) $formatters[$column] : '';
            echo '<td data-label="' . lorkhan_ui_h($label) . '">' . lorkhan_ui_table_value($row[$column] ?? null, $formatter) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

require_once __DIR__ . '/ui_features.php';
