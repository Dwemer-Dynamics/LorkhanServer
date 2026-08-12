<?php

declare(strict_types=1);

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Action Editor';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page action-editor-shell' . ($embedded ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';
$actions = $uiRepository->rows('actions');
$policies = $uiRepository->rows('action_policies');
$installations = $uiRepository->rows('installations');
$profiles = $uiRepository->rows('characters');
$enabledCount = count(array_filter($actions, static fn(array $row): bool => (bool) ($row['enabled'] ?? false)));
$additionalStylesheets = ['herika-action-editor.css?v=' . (string) filemtime(__DIR__ . '/css/herika-action-editor.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';

$policyFields = static function (array $content, array $actions): void { ?>
    <div class="policy-form-grid">
        <label class="checkbox-line"><input type="checkbox" name="enabled" value="1"<?php echo ($content['enabled'] ?? true) === true ? ' checked' : ''; ?>> Enable this policy</label>
        <label>Maximum action tier<select name="max_tier"><?php for ($tier = 0; $tier <= 3; $tier++): ?><option value="<?php echo $tier; ?>"<?php echo (int) ($content['max_tier'] ?? 1) === $tier ? ' selected' : ''; ?>>Tier <?php echo $tier; ?></option><?php endfor; ?></select></label>
        <fieldset class="allowed-actions"><legend>Allowed OpenMW actions</legend><?php foreach ($actions as $action): $name = (string) $action['action_name']; $checked = isset($content['actions']) ? ($content['actions'][$name] ?? false) === true : (bool) $action['enabled']; ?><label><input type="checkbox" name="allowed_actions[]" value="<?php echo almsivi_ui_h($name); ?>"<?php echo $checked ? ' checked' : ''; ?>> <strong><?php echo almsivi_ui_h($name); ?></strong><small>Tier <?php echo almsivi_ui_h($action['tier']); ?> &mdash; <?php echo almsivi_ui_h($action['description']); ?></small></label><?php endforeach; ?></fieldset>
    </div>
<?php };
?>
<main class="action-editor-page">
    <div id="toast" class="toast-notification" role="status" aria-live="polite"><span class="message"></span></div>
    <header class="page-header"><h1>Action Editor</h1><p>Configure available actions exposed to AI prompting and execution</p></header>
    <?php if (isset($_GET['status'])): ?><div class="action-notice">Action policy saved.</div><?php endif; ?>

    <section class="summary-section">
        <div class="section-header"><h2>Action Summary</h2><div class="summary-actions"><button type="button" class="action-button" data-active-actions>View Active Actions</button><button type="button" class="action-button primary" data-policy-create>Create Action Policy</button></div></div>
        <div class="summary-grid"><article><span>Total Actions</span><strong><?php echo count($actions); ?></strong></article><article><span>Enabled</span><strong><?php echo $enabledCount; ?></strong></article><article><span>Disabled</span><strong><?php echo count($actions) - $enabledCount; ?></strong></article><article><span>Policies</span><strong><?php echo count($policies); ?></strong></article></div>
    </section>

    <section class="how-section"><h2>How It Works</h2><p>Edit the action name or description, then press <strong>Save</strong>. Use <strong>Enable</strong> or <strong>Disable</strong> to control whether the AI may use it. Confirmation rules, return messages, parameters, and technical settings are available under <strong>Advanced Options</strong>.</p><p class="replacement-note">ALMSIVI keeps the negotiated OpenMW action catalogue immutable. Use scoped Action Policies to enable actions by installation or NPC profile. <?php echo almsivi_ui_feature_badge('config.actions.direct-edit', true); ?></p></section>

    <section class="filter-toolbar" id="entries">
        <div class="filter-toolbar-top"><label class="search-field"><span class="sr-only">Search</span><input type="search" placeholder="Search actions..." data-action-search></label><span class="visible-count"><strong data-action-visible><?php echo count($actions); ?></strong> of <?php echo count($actions); ?> shown</span><button type="button" class="action-button secondary" data-action-reset>Reset Filters</button></div>
        <div class="filter-toolbar-bottom">
            <?php foreach ([['state','State',['all'=>'All','enabled'=>'Enabled','disabled'=>'Disabled']],['scope','Scope',['all'=>'All','npc'=>'NPC','followers'=>'Followers','narrator'=>'Narrator','dynamic'=>'Dynamic']],['dispatch','Dispatch',['all'=>'All','game'=>'Game','server'=>'Server']],['source','Source',['all'=>'All','base'=>'Base','custom'=>'Custom']]] as [$name,$label,$options]): ?><fieldset><legend><?php echo $label; ?></legend><div class="radio-group"><?php foreach ($options as $value => $text): ?><label><input type="radio" name="live-<?php echo $name; ?>" value="<?php echo $value; ?>"<?php echo $value === 'all' ? ' checked' : ''; ?>><span><?php echo $text; ?></span></label><?php endforeach; ?></div></fieldset><?php endforeach; ?>
        </div>
    </section>

    <section class="action-table-section"><div class="table-wrap"><table><thead><tr><th>Name</th><th>Description</th><th>Action</th></tr></thead><tbody><?php foreach ($actions as $action): $name = (string) $action['action_name']; $enabled = (bool) $action['enabled']; $tier = (int) $action['tier']; ?><tr class="action-row" data-search="<?php echo almsivi_ui_h(strtolower($name . ' ' . (string) $action['description'])); ?>" data-state="<?php echo $enabled ? 'enabled' : 'disabled'; ?>" data-scope="npc followers narrator dynamic" data-dispatch="game" data-source="base"><td><label class="sr-only" for="name-<?php echo almsivi_ui_h($name); ?>">Action name for <?php echo almsivi_ui_h($name); ?></label><input id="name-<?php echo almsivi_ui_h($name); ?>" value="<?php echo almsivi_ui_h($name); ?>" disabled><div class="row-meta"><span class="status-pill <?php echo $enabled ? 'enabled' : 'disabled'; ?>"><?php echo $enabled ? 'Enabled' : 'Disabled'; ?></span><code><?php echo almsivi_ui_h($name); ?></code><span class="status-pill scope">Tier <?php echo $tier; ?></span></div></td><td><label class="sr-only" for="description-<?php echo almsivi_ui_h($name); ?>">Action description for <?php echo almsivi_ui_h($name); ?></label><textarea id="description-<?php echo almsivi_ui_h($name); ?>" rows="3" disabled><?php echo almsivi_ui_h($action['description']); ?></textarea></td><td><div class="action-row-buttons"><span class="status-control"><button type="button" class="btn-save" disabled>Save</button><?php echo almsivi_ui_feature_badge('config.actions.direct-edit', true); ?></span><span class="status-control"><button type="button" class="<?php echo $enabled ? 'btn-danger' : 'btn-save'; ?>" disabled><?php echo $enabled ? 'Disable' : 'Enable'; ?></button><?php echo almsivi_ui_feature_badge('config.actions.direct-edit', true); ?></span><span class="status-control"><button type="button" class="action-button secondary" disabled>Advanced Options</button><?php echo almsivi_ui_feature_badge('config.actions.advanced', true); ?></span></div></td></tr><?php endforeach; ?></tbody></table><div class="empty-filter-state" data-action-empty hidden>No actions match the current filters.</div></div></section>

    <section class="policy-panel" data-policy-panel hidden><div class="section-header"><div><h2>Create action policy</h2><p>Apply bounded OpenMW action permissions globally or to one NPC profile.</p></div><button type="button" class="action-button secondary" data-policy-close>Close</button></div><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/action-policy-controls-create"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><div class="policy-form-grid policy-identity"><label>Installation<select name="installation_id"><?php foreach ($installations as $row): ?><option value="<?php echo almsivi_ui_h($row['installation_id']); ?>"><?php echo almsivi_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label><label>Policy name<input name="name" required maxlength="128"></label><label>NPC profile (optional)<select name="profile_id"><option value="">Installation-wide</option><?php foreach ($profiles as $row): ?><option value="<?php echo almsivi_ui_h($row['profile_id']); ?>"><?php echo almsivi_ui_h($row['name']); ?></option><?php endforeach; ?></select></label></div><?php $policyFields([], $actions); ?><button type="submit" class="action-button primary">Create Policy</button></form></section>

    <section class="policy-list"><h2>Action Policies</h2><?php foreach ($policies as $row): $content = is_array($row['content']) ? $row['content'] : []; ?><article class="policy-card"><div class="section-header"><div><h3><?php echo almsivi_ui_h($row['name']); ?></h3><p><?php echo almsivi_ui_h($row['profile_name'] ?? 'Installation-wide'); ?></p></div><span class="status-pill scope">Revision <?php echo almsivi_ui_h($row['current_revision']); ?></span></div><details><summary>Edit policy</summary><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/action-policy-controls-revise"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($row['configuration_id']); ?>"><?php $policyFields($content, $actions); ?><label>Revision note<input name="change_reason" required value="Management action policy update"></label><button type="submit" class="action-button primary">Save Revision</button></form></details></article><?php endforeach; ?><?php if ($policies === []): ?><p class="empty-policy">No custom action policies exist yet.</p><?php endif; ?></section>

    <div class="action-modal" data-active-modal hidden><div class="action-modal-panel" role="dialog" aria-modal="true" aria-labelledby="active-actions-title"><div class="section-header"><h2 id="active-actions-title">Active Actions</h2><button type="button" class="action-modal-close" data-active-close aria-label="Close active actions">&times;</button></div><div class="active-action-grid"><?php foreach ($actions as $action): if (!(bool) $action['enabled']) continue; ?><article><strong><?php echo almsivi_ui_h($action['action_name']); ?></strong><span>Tier <?php echo almsivi_ui_h($action['tier']); ?></span></article><?php endforeach; ?></div></div></div>
</main>
<script defer src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/action-editor.js?v=<?php echo almsivi_ui_h((string) filemtime(__DIR__ . '/js/action-editor.js')); ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
