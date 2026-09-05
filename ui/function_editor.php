<?php

declare(strict_types=1);

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Action Editor';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page action-editor-shell' . ($embedded ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';

$installations = array_map(static fn(array $row): array => [
    'installation_id' => (string) $row['installation_id'],
    'display_name' => (string) ($row['display_name'] ?? $row['installation_id']),
], $uiRepository->rows('installations'));
$profiles = array_map(static fn(array $row): array => [
    'profile_id' => (string) $row['profile_id'],
    'installation_id' => (string) $row['installation_id'],
    'name' => (string) $row['name'],
], $uiRepository->rows('characters'));
$bootstrap = [
    'api_base' => $managementBasePath . '/api/v1',
    'csrf' => $csrf,
    'installations' => $installations,
    'profiles' => $profiles,
];
$additionalStylesheets = ['herika-action-editor.css?v=' . (string) filemtime(__DIR__ . '/css/herika-action-editor.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="action-editor-page" data-action-editor>
  <div class="action-toast" data-action-toast role="status" aria-live="polite" hidden></div>

  <header class="page-header lorkhan-page-head">
    <h1 class="lorkhan-page-head-title">Action Editor</h1>
    <p class="lorkhan-page-head-note">Configure available actions exposed to AI prompting and execution</p>
  </header>

  <?php if ($installations === []): ?>
    <section class="action-empty-state"><h2>Action Catalog Unavailable</h2><p>Launch LORKHAN once, then reload this page.</p></section>
  <?php else: ?>
    <div class="content-grid">
      <section class="content-section">
        <div class="section-header">
          <h2>Action Summary</h2>
          <button type="button" class="action-button secondary" data-view-active>View Active Actions</button>
        </div>
        <div class="summary-grid">
          <div class="summary-card"><span>Total Actions</span><strong data-summary-total>0</strong></div>
          <div class="summary-card"><span>Enabled</span><strong class="enabled" data-summary-enabled>0</strong></div>
          <div class="summary-card"><span>Disabled</span><strong class="disabled" data-summary-disabled>0</strong></div>
        </div>
      </section>
      <section class="content-section how-it-works">
        <h2>How It Works</h2>
        <p>Edit an action name or description, then press <strong>Save</strong>. Use <strong>Enable</strong> or <strong>Disable</strong> to control whether the AI may use it. Behavior changes can be saved together with <strong>Save all changes</strong>. OpenMW parameters and safety settings are available under <strong>Advanced Options</strong>.</p>
      </section>
    </div>

    <section class="editor-controls" aria-label="Action configuration scope">
      <label>Installation<select data-installation><?php foreach ($installations as $row): ?><option value="<?= lorkhan_ui_h($row['installation_id']) ?>"><?= lorkhan_ui_h($row['display_name']) ?></option><?php endforeach; ?></select></label>
      <label>Apply to<select data-profile><option value="">All NPCs</option></select></label>
      <label class="compact-check"><input type="checkbox" data-policy-enabled checked> Actions enabled</label>
      <div class="scope-state"><span data-scope-label>Installation defaults</span><small data-revision-label>Not saved yet</small></div>
      <input type="hidden" data-max-tier value="3">
    </section>

    <section class="editor-toolbar" aria-label="Action filters">
      <div class="filter-toolbar-top">
        <div class="live-search-wrap">
          <label class="sr-only" for="action-live-search">Search actions</label>
          <input id="action-live-search" type="search" data-search placeholder="Search">
          <div class="filter-summary"><span data-visible-count>0</span> shown</div>
        </div>
        <div class="filter-actions">
          <span class="behavior-dirty-summary" data-dirty-count role="status" aria-live="polite">No unsaved changes</span>
          <button type="button" class="action-button primary" data-save-all disabled>Save all changes</button>
          <button type="button" class="action-button secondary" data-reset-filters>Reset Filters</button>
        </div>
      </div>
      <div class="filter-groups">
        <fieldset class="filter-group" data-filter-group="state"><legend>State</legend><div class="filter-chip-row">
          <input type="radio" name="action-state" id="action-state-all" value="all" checked><label for="action-state-all">All</label>
          <input type="radio" name="action-state" id="action-state-enabled" value="enabled"><label for="action-state-enabled">Enabled</label>
          <input type="radio" name="action-state" id="action-state-disabled" value="disabled"><label for="action-state-disabled">Disabled</label>
        </div></fieldset>
        <fieldset class="filter-group" data-filter-group="scope"><legend>Scope</legend><div class="filter-chip-row">
          <input type="radio" name="action-scope" id="action-scope-all" value="all" checked><label for="action-scope-all">All</label>
          <input type="radio" name="action-scope" id="action-scope-npc" value="npc"><label for="action-scope-npc">NPC</label>
          <input type="radio" name="action-scope" id="action-scope-followers" value="followers"><label for="action-scope-followers">Followers</label>
          <input type="radio" name="action-scope" id="action-scope-narrator" value="narrator"><label for="action-scope-narrator">Narrator</label>
          <input type="radio" name="action-scope" id="action-scope-dynamic" value="dynamic"><label for="action-scope-dynamic">Dynamic</label>
        </div></fieldset>
        <fieldset class="filter-group" data-filter-group="dispatch"><legend>Dispatch</legend><div class="filter-chip-row">
          <input type="radio" name="action-dispatch" id="action-dispatch-all" value="all" checked><label for="action-dispatch-all">All</label>
          <input type="radio" name="action-dispatch" id="action-dispatch-game" value="game"><label for="action-dispatch-game">Game</label>
          <input type="radio" name="action-dispatch" id="action-dispatch-server" value="server"><label for="action-dispatch-server">Server</label>
        </div></fieldset>
        <fieldset class="filter-group" data-filter-group="source"><legend>Source</legend><div class="filter-chip-row">
          <input type="radio" name="action-source" id="action-source-all" value="all" checked><label for="action-source-all">All</label>
          <input type="radio" name="action-source" id="action-source-base" value="base"><label for="action-source-base">Base</label>
          <input type="radio" name="action-source" id="action-source-custom" value="custom"><label for="action-source-custom">Custom</label>
        </div></fieldset>
      </div>
    </section>

    <section class="action-table-section" aria-busy="true" data-editor-table>
      <div class="table-wrap"><table>
        <colgroup><col class="action-name-column"><col class="action-description-column"><col class="action-behavior-column"><col class="action-controls-column"></colgroup>
        <thead><tr><th>Name</th><th>Description</th><th>Behavior</th><th>Action</th></tr></thead>
        <tbody data-action-rows></tbody>
      </table></div>
      <div class="action-empty-state" data-no-actions hidden>No actions found.</div>
    </section>

    <footer class="editor-footer">
      <label>Revision note<input data-change-reason maxlength="512" value="Action Editor update"></label>
      <span data-footer-scope>Installation defaults</span>
      <button type="button" class="action-button primary" data-save-all disabled>Save all changes</button>
    </footer>

    <dialog class="action-dialog" data-active-dialog aria-labelledby="active-actions-title">
      <form method="dialog"><header><div><h2 id="active-actions-title">Currently Active Actions</h2><p>Enabled actions grouped by scope.</p></div><button value="close" aria-label="Close active actions">&times;</button></header>
      <div class="dialog-body active-actions-body" data-active-groups></div>
      <footer><button value="close" class="action-button primary">Done</button></footer></form>
    </dialog>

    <dialog class="action-dialog" data-action-dialog aria-labelledby="action-dialog-title">
      <form method="dialog"><header><div><h2 id="action-dialog-title" data-dialog-title>Advanced Options</h2><p data-dialog-wire></p></div><button value="close" aria-label="Close advanced options">&times;</button></header>
      <div class="dialog-body"><section><h3>Response and Behavior</h3><label>Return Message<textarea rows="3" maxlength="2048" data-dialog-return></textarea></label><p class="field-help">The default event text associated with this action.</p><label>Follow-up Prompt<textarea rows="4" maxlength="2048" data-dialog-followup-prompt></textarea></label><p class="field-help">Instruction used when the game reports this action's completed result.</p><label>Cooldown (seconds)<input type="number" min="0" max="86400" step="1" data-dialog-cooldown></label><label class="switch-line"><input type="checkbox" data-dialog-chain> Allow one follow-up action</label><p class="field-help">A follow-up result may select one additional safe action. Every normal OpenMW check is applied again.</p></section><section><h3>OpenMW Contract</h3><dl data-dialog-contract></dl></section></div>
      <footer><button type="button" class="action-button secondary" data-dialog-reset>Reset to Base</button><button value="close" class="action-button primary">Done</button></footer></form>
    </dialog>
  <?php endif; ?>
</main>
<script type="application/json" id="action-editor-bootstrap"><?= json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/action-editor.js?v=<?= lorkhan_ui_h((string) filemtime(__DIR__ . '/js/action-editor.js')) ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
