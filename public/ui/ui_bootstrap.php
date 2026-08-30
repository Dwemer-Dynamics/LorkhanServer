<?php

declare(strict_types=1);

use LORKHANserver\Infrastructure\Connection;
use LORKHANserver\Infrastructure\ManagementRepository;
use LORKHANserver\Infrastructure\ManagementUiRepository;
use LORKHANserver\Infrastructure\ProductRepository;
use LORKHANserver\Security\BrowserSession;

$applicationRoot = dirname(__DIR__, 2);
require_once $applicationRoot . '/src/Autoload.php';

$pageTitle = isset($pageTitle) ? (string) $pageTitle : 'LORKHAN';
$topNavSection = isset($topNavSection) ? (string) $topNavSection : '';
$embedded = isset($_GET['embed']) && $_GET['embed'] === '1';
$uiAssetVersion = (string) max(
    (int) @filemtime(__DIR__ . '/js/lorkhan-management.js'),
    (int) @filemtime(__DIR__ . '/js/resource-page.js')
);

try {
    $configFile = getenv('LORKHAN_CONFIG') ?: $applicationRoot . '/config/server.php';
    if (!is_file($configFile)) throw new RuntimeException('Server configuration is unavailable.');
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Server configuration is invalid.');
    $config['credential_storage_path'] ??= '/var/lib/lorkhanserver/credentials/provider-keys.json';
    $config['database_password'] = getenv('LORKHAN_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');

    $database = Connection::open($config);
    $managementRepository = new ManagementRepository($database);
    $uiRepository = new ManagementUiRepository($database);
    $productRepository = new ProductRepository($database);
    $managementBasePath = rtrim((string) ($config['management_base_path'] ?? '/LORKHANserver/manage'), '/');
    $webRoot = preg_replace('#/manage$#', '', $managementBasePath) ?: '/LORKHANserver';
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
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
} catch (Throwable) {
    // This can fail before the configuration is read, so $webRoot may never have
    // been assigned. Derive the asset root from the request path instead; every
    // UI entry point lives under <root>/ui/.
    $scriptPath = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $uiOffset = strpos($scriptPath, '/ui/');
    $assetRoot = htmlspecialchars(
        $uiOffset === false ? '/LORKHANserver' : substr($scriptPath, 0, $uiOffset),
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
    echo '<h1>LORKHANserver is unavailable</h1>';
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
    $sourceLabels = ['default' => 'Built-in default', 'global' => 'Global', 'core_profile' => 'Core Profile', 'npc' => 'NPC override'];
    echo '<details class="effective-settings-summary"' . ($open ? ' open' : '') . '><summary>' . lorkhan_ui_h($title) . '</summary>';
    echo '<p>Resolution order: NPC override &gt; assigned Core Profile &gt; Global &gt; built-in default.</p><div class="effective-settings-grid">';
    foreach ($sources as $path => $source) {
        if (!is_string($path) || (!str_starts_with($path, 'settings.memory.') && !str_starts_with($path, 'settings.narrator.')
            && !str_starts_with($path, 'settings.relationship.') && !str_starts_with($path, 'settings.safety.') && !str_starts_with($path, 'routing.'))) continue;
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

/** Render a bounded repository result using the common sibling-server table structure. */
function lorkhan_ui_table(array $rows, string $emptyMessage = 'No records are available yet.'): void
{
    if ($rows === []) {
        echo '<p class="empty-state">' . lorkhan_ui_h($emptyMessage) . '</p>';
        return;
    }
    $columns = array_keys($rows[0]);
    echo '<div class="table-responsive"><table class="table table-dark table-hover align-middle"><thead><tr>';
    foreach ($columns as $column) echo '<th scope="col">' . lorkhan_ui_h(ucwords(str_replace('_', ' ', $column))) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $column) echo '<td>' . lorkhan_ui_value($row[$column] ?? null) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

require_once __DIR__ . '/ui_features.php';
