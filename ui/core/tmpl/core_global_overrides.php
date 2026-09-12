<?php
declare(strict_types=1);
// Herika's Global Settings Overrides disclosure, limited to additional runtime-backed Core leaves.
$coreOverrideCatalog = [];
foreach (['location_context_enabled'=>'Force Location Oghma', 'topic_count'=>'Oghma Topic Count',
    'extractor_fallback_enabled'=>'Oghma Extractor Fallback', 'extractor_timeout_ms'=>'Oghma Extractor Timeout',
    'enabled'=>'Enable Oghma', 'result_limit'=>'Oghma Result Limit', 'racial_context_enabled'=>'Force Racial Oghma'] as $key=>$label) {
    $default = \LorkhanServer\Application\SettingsCatalog::oghmaDefaults()[$key];
    $coreOverrideCatalog['oghma.'.$key] = ['label'=>$label, 'type'=>is_bool($default)?'boolean':'integer',
        'value'=>$default, 'range'=>match($key){'topic_count'=>[1,3],'result_limit'=>[1,5],default=>[250,3000]}];
}
$coreOverrideCatalog['memory.oghma_knowledge_tags'] = ['label'=>'Oghma Knowledge Tags', 'type'=>'string', 'value'=>'', 'maxBytes'=>4096];
foreach (['hide_ambient_combat'=>'Hide Ambient Combat','prompt_timestamp'=>'Prompt Timestamp','ground_items_descriptions_only'=>'Ground Items Descriptions Only','inventory_items_descriptions_only'=>'Inventory Items Descriptions Only'] as $key=>$label)
    $coreOverrideCatalog['context.'.$key]=['label'=>$label,'type'=>'boolean','value'=>false];
$coreOverrideCatalog['prompt.prompt_head']=['label'=>'Prompt Head','type'=>'string','value'=>'','maxBytes'=>8192,'multiline'=>true];
$coreOverrideCatalog['behavior.rechat_mode']=['label'=>'Rechat Mode','type'=>'choice','value'=>'random','choices'=>['tight','conversational','group','random']];
$coreOverrideCatalog['relationship.enabled']=['label'=>'Relationship System Enabled','type'=>'boolean','value'=>($globalContent['relationship']['enabled']??false)===true];
$coreOverrideCatalog['relationship.update_chance_percent']=['label'=>'Relationship Update Chance','type'=>'integer','value'=>50,'range'=>[0,100]];
$coreOverrideCatalog['context.power_awareness_enabled']=['label'=>'Power Awareness Enabled','type'=>'boolean','value'=>false];
$coreOverrideHelp = [
    'context.hide_ambient_combat'=>'Hide ambient death events containing has killed from conversation context. Other death events and the stored event log are retained.',
    'context.power_awareness_enabled'=>'Compare observed character levels so NPCs can assess relative threats. The Nearby Actor Details Power selection must also be enabled. Missing levels produce no assessment.',
    'behavior.rechat_mode'=>'Tight uses the listener; Conversational prefers the current partner; Group rotates nearby NPCs; Random chooses a mode at the start of each chain. Existing chains retain their starting mode.',
    'relationship.enabled'=>'Enable relationship evaluation for this profile. The effective update chance and global Relationship Management connector still apply.',
    'relationship.update_chance_percent'=>'Percent chance that an eligible completed response queues a relationship update. Zero stops automatic updates without removing saved relationships. Relationship System must be enabled.',

    'context.prompt_timestamp'=>'Add rough timestamp dividers to event context to help NPCs understand when events happened.',
    'context.ground_items_descriptions_only'=>'Include only nearby items that have a record description.',
    'context.inventory_items_descriptions_only'=>'Include only inventory items that have a record description.',
    'prompt.prompt_head'=>'System prompt defining the rules of the roleplay. Blank uses the built-in prompt; turn off Override to inherit the global prompt.',

    'oghma.location_context_enabled'=>'Inject matching lore for the current OpenMW location. Existing knowledge permissions still apply.',
    'oghma.topic_count'=>'Number of Oghma articles to extract for each response. More article extraction can increase response time.',
    'oghma.extractor_fallback_enabled'=>'Allow one bounded connector fallback for explicit unresolved Oghma requests.',
    'oghma.extractor_timeout_ms'=>'Hard timeout in milliseconds for the optional Oghma connector fallback.',
    'oghma.enabled'=>'Add relevant Tamriel lore to response context.',
    'oghma.result_limit'=>'Maximum Oghma articles included in one prompt after forced and conversational selection.',
    'oghma.racial_context_enabled'=>'Inject matching race lore. Existing knowledge permissions still apply.',
    'memory.oghma_knowledge_tags'=>'Comma-separated knowledge tags. Blank clears inherited tags; turn off Override to inherit. Maximum 4096 UTF-8 bytes.',
];
$inheritedCoreOverrides = (new \LorkhanServer\Application\EffectiveSettingsResolver())->resolve($globalContent ?? [], [], []);
foreach ($coreOverrideCatalog as $path=>&$definition) {
    [$section,$key] = explode('.', $path);
    $definition['value'] = $inheritedCoreOverrides['settings'][$section][$key] ?? $inheritedCoreOverrides[$section][$key] ?? $definition['value'];
}
unset($definition);
?>
<details class="connector-card core-global-overrides" data-core-overrides data-catalog="<?= lorkhan_ui_h(json_encode($coreOverrideCatalog)) ?>">
    <summary>🌐 Global Settings Overrides</summary>
    <p class="hint">Override global settings for this profile. Changes here take precedence over global configurations. Other profile settings use the controls above.</p>
    <div class="prof-ovr-list">
    <?php foreach (['Context'=>['context','relationship'],'Oghma'=>['oghma','memory'],'Prompt'=>['prompt'],'Rechat'=>['behavior']] as $category=>$sections): ?>
    <section class="prof-ovr-category"><h3 class="prof-ovr-category-title"><?= lorkhan_ui_h($category) ?></h3><div class="prof-ovr-category-settings">
    <?php foreach ($coreOverrideCatalog as $path=>$definition): [$section,$key]=explode('.', $path); if (!in_array($section,$sections,true)) continue; $enabled=array_key_exists($key,$overrides[$section]??[]); $value=$enabled?$overrides[$section][$key]:$definition['value']; $id='core-override-'.str_replace('.','-',$path); $globalPreview=is_bool($definition['value'])?($definition['value']?'true':'false'):($definition['value']===''?'Not set':(string)$definition['value']); $globalPreview=mb_strlen($globalPreview)>180?mb_substr($globalPreview,0,177).'…':$globalPreview; ?>
        <div class="prof-ovr-inline-item<?= $enabled?' enabled':'' ?>" data-path="<?= lorkhan_ui_h($path) ?>">
            <div class="prof-ovr-inline-info"><div class="prof-ovr-inline-name"><span>🧾</span><label for="<?= lorkhan_ui_h($id) ?>"><?= lorkhan_ui_h($definition['label']) ?></label></div>
                <div class="prof-ovr-inline-description"><?= lorkhan_ui_h($coreOverrideHelp[$path]) ?></div>
                <div class="prof-ovr-inherited-value">Global value: <?= lorkhan_ui_h($globalPreview) ?></div>
            </div>
            <div class="prof-ovr-inline-controls"><label class="prof-ovr-toggle"><input type="checkbox" data-core-override-enabled<?= $enabled?' checked':'' ?> aria-label="<?= lorkhan_ui_h('Override '.$definition['label']) ?>"> Override</label>
                <?php if ($definition['type'] === 'choice'): ?>
                <select id="<?= lorkhan_ui_h($id) ?>" class="prof-ovr-inline-input" data-core-override-input<?= $enabled?'':' disabled' ?>><?php foreach ($definition['choices'] as $choice): ?><option value="<?= lorkhan_ui_h($choice) ?>"<?= $value===$choice?' selected':'' ?>><?= lorkhan_ui_h(ucfirst($choice)) ?></option><?php endforeach; ?></select>
                <?php elseif ($definition['multiline'] ?? false): ?>
                <textarea id="<?= lorkhan_ui_h($id) ?>" class="prof-ovr-inline-input" data-core-override-input rows="3" maxlength="<?= $definition['maxBytes'] ?>"<?= $enabled?'':' disabled' ?>><?= lorkhan_ui_h((string)$value) ?></textarea>
                <?php else: ?>
                <input id="<?= lorkhan_ui_h($id) ?>" class="prof-ovr-inline-input" data-core-override-input type="<?= $definition['type']==='boolean'?'checkbox':($definition['type']==='integer'?'number':'text') ?>"<?= $definition['type']==='boolean'?($value?' checked':''):' value="'.lorkhan_ui_h((string)$value).'"' ?><?= $definition['type']==='integer'?' step="1" required min="'.$definition['range'][0].'" max="'.$definition['range'][1].'"':'' ?><?= $definition['type']==='string'?' maxlength="4096"':'' ?><?= $enabled?'':' disabled' ?>>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div></section>
    <?php endforeach; ?>
    </div>
    <p role="status" aria-live="polite" data-core-override-status></p>
    <noscript><style>.core-global-overrides .prof-ovr-list{display:none}</style>Use Metadata (Advanced JSON) below to edit overrides without JavaScript.</noscript>
</details>
