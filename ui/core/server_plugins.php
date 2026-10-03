<?php

declare(strict_types=1);

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Server Plugins';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page server-plugins-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

// Only active installations are offered; a requested ID outside that list falls back to the first one.
$installations = $uiRepository->rows('installations');
$installationId = '';
foreach ($installations as $installation) {
    if (($installation['installation_id'] ?? '') === (string) ($_GET['installation_id'] ?? '')) $installationId = (string) $installation['installation_id'];
}
if ($installationId === '' && isset($installations[0])) $installationId = (string) $installations[0]['installation_id'];

$additionalStylesheets = ['server-plugins.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/server-plugins.css')];
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="server-plugins-page<?php echo $embedded ? ' embedded' : ''; ?>" data-server-plugins
      data-api="<?php echo lorkhan_ui_h($managementBasePath . '/api/v1'); ?>" data-csrf="<?php echo lorkhan_ui_h($csrf); ?>">
 <header class="plugins-header">
  <div>
   <h1 id="server-plugins-title">Server Plugins</h1>
   <p><?php echo lorkhan_ui_h(lorkhan_ui_feature('config.plugins')['description']); ?></p>
  </div>
  <?php if (count($installations) > 1): ?>
  <label class="plugins-context">Installation
   <select data-plugin-installation><?php foreach ($installations as $installation): ?><option value="<?php echo lorkhan_ui_h($installation['installation_id']); ?>"<?php echo $installation['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($installation['display_name']); ?></option><?php endforeach; ?></select>
  </label>
  <?php elseif ($installationId !== ''): ?>
  <input type="hidden" data-plugin-installation value="<?php echo lorkhan_ui_h($installationId); ?>">
  <?php endif; ?>
 </header>
 <div class="plugins-status" data-plugin-status role="status" aria-live="polite"></div>
 <?php if ($installationId === ''): ?>
 <p class="plugins-empty">Connect OpenMW with LORKHAN once before installing server plugins.</p>
 <?php else: ?>
 <section class="plugins-panel" aria-labelledby="plugins-installed-title">
  <div class="plugins-panel-head"><h2 id="plugins-installed-title" tabindex="-1">Installed</h2>
   <button type="button" class="btn-secondary" data-plugin-refresh>Refresh</button></div>
  <div data-plugin-list aria-busy="true"><p class="plugins-empty">Loading plugins…</p></div>
 </section>
 <section class="plugins-panel" aria-labelledby="plugins-file-title">
  <h2 id="plugins-file-title">Install from file</h2>
  <p class="plugins-note">Server plugins run trusted PHP on this server. Install only packages you trust. Packages over 64 MiB, older versions and client files are refused.</p>
  <div class="plugins-file-row">
   <label class="plugins-file-label">Package (.dwpkg)<input type="file" accept=".dwpkg" data-plugin-file></label>
   <button type="button" class="btn-primary" data-plugin-file-install disabled>Install</button>
  </div>
  <p class="plugins-file-summary" data-plugin-file-summary></p>
  <progress max="100" value="0" data-plugin-progress hidden aria-label="Upload progress"></progress>
 </section>
 <section class="plugins-panel" aria-labelledby="plugins-catalog-title">
  <div class="plugins-panel-head"><h2 id="plugins-catalog-title">Catalog</h2></div>
  <div data-plugin-catalog aria-busy="true"><p class="plugins-empty">Loading catalog…</p></div>
 </section>
 <?php endif; ?>
 <dialog class="plugins-dialog" data-plugin-confirm aria-labelledby="plugins-confirm-title" aria-describedby="plugins-confirm-text">
  <form method="dialog">
   <h2 id="plugins-confirm-title">Remove plugin?</h2>
   <p id="plugins-confirm-text"></p>
   <div class="plugins-dialog-actions">
    <button type="submit" value="cancel" class="btn-secondary" autofocus>Cancel</button>
    <button type="submit" value="confirm" class="btn-danger">Remove</button>
   </div>
  </form>
 </dialog>
</main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/server-plugins.js?v=<?php echo lorkhan_ui_h((string) filemtime(dirname(__DIR__) . '/js/server-plugins.js')); ?>"></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
