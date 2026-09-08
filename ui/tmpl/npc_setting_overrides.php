<?php
declare(strict_types=1);
// Current rows and modal structure derive from Herika's NPC override_editor.php.
$overrideLabels = [
    'behavior.rechat'=>'Rechat', 'behavior.rechat_max_depth'=>'Rechat Rounds',
    'behavior.rechat_probability_percent'=>'Rechat Probability', 'behavior.rechat_allow_actions'=>'Rechat Actions',
    'memory.recent_turn_limit'=>'Context History', 'memory.short_term_enabled'=>'Short Term Memory',
    'memory.mid_term_enabled'=>'Middle Term Memory', 'memory.long_term_enabled'=>'Long Term Memory',
    'response.max_words'=>'Maximum Response Words',
];
$overrideCatalog=[];$overrideValues=[];
foreach (\LorkhanServer\Application\SettingsCatalog::npcOverrideFields() as $section=>$fields) {
    foreach ($fields as $key) {
        $path=$section.'.'.$key;
        $range=\LorkhanServer\Application\SettingsCatalog::ranges()[$path] ?? ($path==='response.max_words'?[0,10000]:null);
        $default=$effectiveSettings['settings'][$section][$key]
            ?? \LorkhanServer\Application\SettingsCatalog::clientDefaults()[$section][$key] ?? ($range?0:true);
        $overrideCatalog[$path]=['label'=>$overrideLabels[$path],'type'=>$range?'integer':'boolean','range'=>$range,'value'=>$default];
        if(array_key_exists($key,$content['settings_overrides'][$section]??[]))$overrideValues[$section][$key]=$content['settings_overrides'][$section][$key];
    }
}
$overrideId=$formId.'-overrides';
?>
<div class="form-item span-2 npc-setting-overrides" data-npc-overrides data-form="<?= lorkhan_ui_h($formId) ?>" data-catalog="<?= lorkhan_ui_h(json_encode($overrideCatalog)) ?>">
    <label>Setting Overrides</label>
    <small class="hint">Override supported global and profile settings for this NPC. Removing an override restores inheritance. Changes take effect when you save the NPC.</small>
    <div class="npc-ovr-container">
        <strong class="npc-ovr-title">Current Overrides</strong>
        <label hidden for="<?= lorkhan_ui_h($overrideId) ?>-value">Submitted NPC setting overrides</label>
        <textarea id="<?= lorkhan_ui_h($overrideId) ?>-value" hidden name="npc_settings_overrides_json" form="<?= lorkhan_ui_h($formId) ?>" data-npc-override-value><?= lorkhan_ui_h(json_encode($overrideValues?:new stdClass())) ?></textarea>
        <div class="npc-ovr-list" data-npc-override-list></div>
        <button type="button" class="npc-ovr-add" data-npc-override-add>+ Add Override</button>
        <details><summary>Advanced: Raw JSON Editor</summary>
            <label for="<?= lorkhan_ui_h($overrideId) ?>-json">NPC setting overrides (JSON)</label>
            <textarea id="<?= lorkhan_ui_h($overrideId) ?>-json" data-npc-override-json spellcheck="false"></textarea>
            <button type="button" data-npc-override-apply>Apply JSON to draft</button>
        </details>
        <p role="status" aria-live="polite" data-npc-override-status></p>
        <noscript>Enable JavaScript to edit setting overrides. Existing overrides are preserved.</noscript>
    </div>
    <dialog class="npc-ovr-dialog" aria-labelledby="<?= lorkhan_ui_h($overrideId) ?>-title">
        <header><strong id="<?= lorkhan_ui_h($overrideId) ?>-title" data-npc-override-title>Add Override</strong><button type="button" data-npc-override-close>Close</button></header>
        <div class="npc-ovr-body">
            <div data-npc-override-picker>
                <label for="<?= lorkhan_ui_h($overrideId) ?>-search">Filter settings</label>
                <input type="search" id="<?= lorkhan_ui_h($overrideId) ?>-search" placeholder="Filter settings..." data-npc-override-search>
                <p>Select a setting to override:</p><div class="npc-ovr-options" data-npc-override-options></div>
            </div>
            <div data-npc-override-edit hidden>
                <label for="<?= lorkhan_ui_h($overrideId) ?>-bool" data-npc-override-label></label>
                <select id="<?= lorkhan_ui_h($overrideId) ?>-bool" data-npc-override-bool><option value="true">On</option><option value="false">Off</option></select>
                <input type="number" id="<?= lorkhan_ui_h($overrideId) ?>-number" step="1" data-npc-override-number>
                <p data-npc-override-help></p>
            </div>
        </div>
        <footer><button type="button" data-npc-override-cancel>Cancel</button><button type="button" class="npc-ovr-add" data-npc-override-save hidden>Apply to draft</button></footer>
    </dialog>
</div>
