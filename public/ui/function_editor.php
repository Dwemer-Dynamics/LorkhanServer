<?php

declare(strict_types=1);

$embedded=(string)($_GET['embed']??'')==='1';
$pageTitle='Action Editor';
$topNavSection='configuration';
$BODY_CLASS='hub-page action-editor-shell'.($embedded?' embedded-page':'');
require __DIR__.'/ui_bootstrap.php';
$installations=array_map(static fn(array $row):array=>[
    'installation_id'=>(string)$row['installation_id'],
    'display_name'=>(string)($row['display_name']??$row['installation_id']),
],$uiRepository->rows('installations'));
$profiles=array_map(static fn(array $row):array=>[
    'profile_id'=>(string)$row['profile_id'],
    'installation_id'=>(string)$row['installation_id'],
    'name'=>(string)$row['name'],
],$uiRepository->rows('characters'));
$bootstrap=['api_base'=>$managementBasePath.'/api/v1','csrf'=>$csrf,'installations'=>$installations,'profiles'=>$profiles];
$additionalStylesheets=['herika-action-editor.css?v='.(string)filemtime(__DIR__.'/css/herika-action-editor.css')];
include __DIR__.'/tmpl/head.html';
if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="action-editor-page" data-action-editor>
  <div class="action-toast" data-action-toast role="status" aria-live="polite" hidden></div>
  <header class="page-header lorkhan-page-head">
    <div><h1 class="lorkhan-page-head-title">Action Editor</h1><p class="lorkhan-page-head-note">Configure the OpenMW actions available to an installation or one NPC profile.</p></div>
    <div class="editor-save-summary"><strong data-dirty-count>0 unsaved</strong><button type="button" class="action-button primary" data-save-all disabled>Save All</button></div>
  </header>

  <?php if($installations===[]): ?>
    <section class="action-empty-state"><h2>No LORKHAN installation found</h2><p>Launch LORKHAN once, then return here to create an action policy.</p></section>
  <?php else: ?>
  <section class="editor-controls" aria-label="Action policy scope">
    <label>Installation<select data-installation><?php foreach($installations as $row): ?><option value="<?=lorkhan_ui_h($row['installation_id'])?>"><?=lorkhan_ui_h($row['display_name'])?></option><?php endforeach; ?></select></label>
    <label>NPC scope<select data-profile><option value="">Installation-wide</option></select></label>
    <label>Policy<select data-policy><option value="">New policy</option></select></label>
    <label>Policy name<input data-policy-name maxlength="128" value="Action policy"></label>
    <label>Maximum tier<select data-max-tier><?php for($tier=0;$tier<=3;$tier++): ?><option value="<?=$tier?>"<?=$tier===3?' selected':''?>>Tier <?=$tier?></option><?php endfor; ?></select></label>
    <label class="compact-check"><input type="checkbox" data-policy-enabled checked> Policy enabled</label>
  </section>

  <section class="editor-toolbar" aria-label="Action filters and bulk editing">
    <div class="toolbar-search"><label><span class="sr-only">Search actions</span><input type="search" data-search placeholder="Search actions, labels or capabilities..."></label><label>Tier<select data-tier-filter><option value="all">All tiers</option><option value="0">Tier 0</option><option value="1">Tier 1</option><option value="2">Tier 2</option><option value="3">Tier 3</option></select></label><output data-visible-count>0 shown</output></div>
    <div class="bulk-actions" data-bulk-actions>
      <span><strong data-selected-count>0</strong> selected</span>
      <button type="button" data-bulk="select">Select visible</button><button type="button" data-bulk="clear">Clear</button>
      <button type="button" data-bulk="enable">Enable</button><button type="button" data-bulk="disable">Disable</button>
      <button type="button" data-bulk="confirm-on">Confirm on</button><button type="button" data-bulk="confirm-off">Confirm off</button>
      <button type="button" data-bulk="followup-on">Follow-up on</button><button type="button" data-bulk="followup-off">Follow-up off</button>
    </div>
  </section>

  <section class="action-table-section" aria-busy="true" data-editor-table>
    <div class="table-wrap"><table><thead><tr><th class="select-column"><span class="sr-only">Select</span></th><th>Action</th><th>Display and prompt text</th><th>Runtime behavior</th></tr></thead><tbody data-action-rows></tbody></table></div>
    <div class="action-empty-state" data-no-actions hidden>No actions match the current filters.</div>
  </section>

  <footer class="editor-footer">
    <label>Revision note<input data-change-reason maxlength="512" value="Action Editor update"></label>
    <span data-revision-label>New policy</span>
    <button type="button" class="action-button primary" data-save-all disabled>Save All</button>
  </footer>
  <?php endif; ?>
</main>
<script type="application/json" id="action-editor-bootstrap"><?=json_encode($bootstrap,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script defer src="<?=lorkhan_ui_h($webRoot)?>/ui/js/action-editor.js?v=<?=lorkhan_ui_h((string)filemtime(__DIR__.'/js/action-editor.js'))?>"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
