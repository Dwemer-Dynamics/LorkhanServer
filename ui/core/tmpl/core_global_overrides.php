<?php
declare(strict_types=1);
// Herika's Global Settings Overrides disclosure, limited to additional runtime-backed Core leaves.
$coreOverrideCatalog = [
    'profile_management.autofill_custom_profiles'=>['label'=>'Autofill Custom Profiles','type'=>'boolean','value'=>true],
    'profile_management.autofill_custom_profiles_trigger'=>['label'=>'Autofill Custom Profiles Trigger','type'=>'integer','value'=>40,'range'=>[10,100]],
];
foreach (['location_context_enabled'=>'Force Location Oghma', 'topic_count'=>'Oghma Articles Amount',
    'extractor_fallback_enabled'=>'Oghma Extractor Fallback', 'extractor_timeout_ms'=>'Oghma Extractor Timeout',
    'enabled'=>'Oghma Infinium', 'result_limit'=>'Oghma Result Limit', 'racial_context_enabled'=>'Force Racial Oghma'] as $key=>$label) {
    $default = \LorkhanServer\Application\SettingsCatalog::oghmaDefaults()[$key];
    $coreOverrideCatalog['oghma.'.$key] = ['label'=>$label, 'type'=>is_bool($default)?'boolean':'integer',
        'value'=>$default, 'range'=>match($key){'topic_count'=>[1,3],'result_limit'=>[1,5],default=>[250,3000]}];
}
$coreOverrideCatalog['memory.oghma_knowledge_tags'] = ['label'=>'Oghma Knowledge Tags', 'type'=>'string', 'value'=>'', 'maxBytes'=>4096];
foreach (['location_blacklist'=>'Location Blacklist','item_blacklist'=>'Item Blacklist','magic_effects_blacklist'=>'Magic Effect Blacklist'] as $key=>$label)
    $coreOverrideCatalog['context.'.$key]=['label'=>$label,'type'=>'textlist','value'=>[],'maxBytes'=>65792,'multiline'=>true];
foreach (['hide_ambient_combat'=>'Hide Ambient Combat','prompt_timestamp'=>'Prompt Timestamp','ground_items_descriptions_only'=>'Ground Items Descriptions Only','inventory_items_descriptions_only'=>'Inventory Items Descriptions Only'] as $key=>$label)
    $coreOverrideCatalog['context.'.$key]=['label'=>$label,'type'=>'boolean','value'=>false];
$coreOverrideCatalog['prompt.prompt_head']=['label'=>'Prompt Head','type'=>'string','value'=>'','maxBytes'=>8192,'multiline'=>true];
$coreOverrideCatalog['prompt.emote_moods']=['label'=>'Emote Moods','type'=>'string','value'=>'','maxBytes'=>4096,'multiline'=>true];
$coreOverrideCatalog['context.event_types']=['label'=>'Event Type Filter','type'=>'textlist','value'=>\LorkhanServer\Application\SettingsCatalog::eventTypes(),'choices'=>\LorkhanServer\Application\SettingsCatalog::eventTypes(),'maxBytes'=>4096,'multiline'=>true];
$coreOverrideCatalog['behavior.rechat_mode']=['label'=>'Rechat Mode','type'=>'choice','value'=>'random','choices'=>['tight','conversational','group','random']];
$coreOverrideCatalog['behavior.rechat_strict_targeting']=['label'=>'Strict Rechat Targeting','type'=>'boolean','value'=>false];
$coreOverrideCatalog['behavior.open_rechat']=['label'=>'Open Rechat','type'=>'boolean','value'=>true];
$coreOverrideCatalog['behavior.end_conversation_cooldown_seconds']=['label'=>'End Conversation Cooldown','type'=>'integer','value'=>60,'range'=>[0,300]];
$coreOverrideCatalog['relationship.enabled']=['label'=>'Relationship System Enabled','type'=>'boolean','value'=>($globalContent['relationship']['enabled']??false)===true];
$coreOverrideCatalog['relationship.update_chance_percent']=['label'=>'Relationship Update Chance','type'=>'integer','value'=>50,'range'=>[0,100]];
$coreOverrideCatalog['context.short_term_in_compact_chat']=['label'=>'Short Term Memory in Compact Chat','type'=>'boolean','value'=>true];
$coreOverrideCatalog['context.transformation_detection']=['label'=>'Transformation Detection','type'=>'boolean','value'=>true];
$coreOverrideCatalog['context.detect_magic_events']=['label'=>'Detect Magic Events','type'=>'boolean','value'=>true];
$coreOverrideCatalog['context.item_pickup_min_value']=['label'=>'Item Pickup Detection Value','type'=>'integer','value'=>500,'range'=>[0,2147483647]];
$coreOverrideCatalog['context.power_awareness_enabled']=['label'=>'Power Awareness Enabled','type'=>'boolean','value'=>false];
$contextSelectionGroups=require dirname(__DIR__,2).'/tmpl/context_selection_groups.php';
foreach(['sections'=>'Context Sections','details'=>'Context Details']as$key=>$label){
    $choices=[];foreach($contextSelectionGroups as[$group,$items])if($group===$key)foreach($items as$name=>$info)$choices[$name]=$info[0];
    $coreOverrideCatalog['context.'.$key]=['label'=>$label,'type'=>'booleanmap','choices'=>$choices,
        'value'=>$key==='sections'?\LorkhanServer\Application\SettingsCatalog::contextSectionDefaults():\LorkhanServer\Application\SettingsCatalog::contextDetailDefaults()];
}
$coreOverrideHelp = [
    'context.sections'=>'Select optional prompt sections. Uncheck all to exclude them; turn off Override to inherit. Required speaker and action instructions remain.',
    'context.details'=>'Select optional character, state and nearby details. The containing section must also be enabled. Turn off Override to inherit.',
    'prompt.emote_moods'=>'Moods and emotes offered in the prompt when the NPC has no custom mood list. Blank removes inherited suggestions; turn off Override to inherit. Maximum 4096 UTF-8 bytes.',
    'context.event_types'=>'One included event type per line: '.implode(', ',\LorkhanServer\Application\SettingsCatalog::eventTypes()).'. Blank excludes event history from this profile’s context without deleting it; turn off Override to inherit.',
    'profile_management.autofill_custom_profiles'=>'Automatically fill an empty, unlocked NPC profile after enough witnessed dialogue. Does not enable periodic Dynamic Profile updates.',
    'profile_management.autofill_custom_profiles_trigger'=>'Witnessed dialogue records required before automatic profile backfill (10–100).',
    'context.location_blacklist'=>'One location per line. These locations are omitted from prompt context. Blank clears the inherited blacklist; turn off Override to inherit. Maximum 256 entries, 256 UTF-8 bytes each.',
    'context.item_blacklist'=>'One item record ID or name per line. Matching items are omitted from prompt context. Blank clears the inherited blacklist; turn off Override to inherit. Maximum 256 entries, 256 UTF-8 bytes each.',
    'context.magic_effects_blacklist'=>'One magic effect per line. Matching effects are omitted from prompt context. Blank clears the inherited blacklist; turn off Override to inherit. Maximum 256 entries, 256 UTF-8 bytes each.',
    'context.hide_ambient_combat'=>'Hide ambient death events containing has killed from conversation context. Other death events and the stored event log are retained.',
    'context.short_term_in_compact_chat'=>'Keep past-scene summaries in compact chat. Off omits summaries without hiding original dialogue; Short Term Memory must also be enabled.',
    'context.transformation_detection'=>'Include the observed werewolf form in player and NPC current-state context.',
    'context.detect_magic_events'=>'Include observed successful spell casts in this profile’s conversation context. The Infoaction event filter and Magic Effect Blacklist still apply; original event records are retained.',
    'context.item_pickup_min_value'=>'Minimum total gold value (quantity times item value) for observed pickups in conversation context. Zero includes all pickups; original event records are retained.',
    'context.power_awareness_enabled'=>'Compare observed character levels so NPCs can assess relative threats. The Nearby Actor Details Power selection must also be enabled. Missing levels produce no assessment.',
    'behavior.rechat_mode'=>'Tight uses the listener; Conversational prefers the current partner; Group rotates nearby NPCs; Random chooses a mode at the start of each chain. Existing chains retain their starting mode.',
    'behavior.rechat_strict_targeting'=>'Require the chosen responder to address the previous speaker directly. The selected responder’s setting is captured when the chain starts.',
    'behavior.open_rechat'=>'Allow nearby scene participants to become the next responder. When off, a new chain started by this NPC stays listener-only; existing chains retain their starting mode.',
    'behavior.end_conversation_cooldown_seconds'=>'Seconds this NPC refuses AI conversation after successfully using End Conversation (0–300). Zero removes the cooldown. Ordinary Rechat completion does not start it.',
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
    $definition['category'] = match ($section) {
        'profile_management' => 'Misc',
        'oghma', 'memory' => 'Oghma', 'prompt' => 'Prompt', 'behavior' => 'Rechat', default => 'Context'
    };
    if($path==='context.short_term_in_compact_chat')$definition['category']='Memory';
    if (in_array($path, ['context.prompt_timestamp','context.location_blacklist','context.item_blacklist',
        'context.magic_effects_blacklist','context.event_types','relationship.update_chance_percent'], true))
        $definition['category'] = 'Prompt';
    if (in_array($path, ['behavior.rechat_strict_targeting','behavior.open_rechat','behavior.end_conversation_cooldown_seconds'], true))
        $definition['category'] = 'Misc';
    $definition['icon'] = in_array($path, ['oghma.enabled','oghma.topic_count','oghma.result_limit','oghma.extractor_fallback_enabled','oghma.extractor_timeout_ms','memory.oghma_knowledge_tags'], true) ? '🧾' : ($path === 'behavior.rechat_mode' ? '🔁' : '⚙️');
    $definition['value'] = $inheritedCoreOverrides['settings'][$section][$key] ?? $inheritedCoreOverrides[$section][$key] ?? $definition['value'];
}
unset($definition);
?>
<details class="provider-card profile-global-overrides core-global-overrides" data-core-overrides data-catalog="<?= lorkhan_ui_h(json_encode($coreOverrideCatalog)) ?>">
    <summary class="provider-head">
        <div class="provider-title"><div class="provider-icon" aria-hidden="true">&#x1F310;</div><div>Global Settings Overrides</div></div>
    </summary>
    <div class="provider-body profile-provider-body">
    <small class="core-override-intro">Override global settings for this profile. Changes here take precedence over global configurations.</small>
    <div class="prof-ovr-list">
    <?php foreach (['Misc','Context','Memory','Oghma','Prompt','Rechat'] as $category): ?>
    <section class="prof-ovr-category"><h3 class="prof-ovr-category-title"><?= lorkhan_ui_h($category) ?></h3><div class="prof-ovr-category-settings">
    <?php foreach ($coreOverrideCatalog as $path=>$definition): [$section,$key]=explode('.', $path); if ($definition['category'] !== $category) continue; $enabled=array_key_exists($key,$overrides[$section]??[]); $value=$enabled?$overrides[$section][$key]:$definition['value']; $id='core-override-'.str_replace('.','-',$path); $globalPreview=is_array($definition['value'])?implode(', ',$definition['type']==='booleanmap'?array_values(array_intersect_key($definition['choices'],array_filter($definition['value']))):$definition['value']):(is_bool($definition['value'])?($definition['value']?'true':'false'):($definition['value']===''?'Not set':(string)$definition['value'])); $globalPreview=mb_strlen($globalPreview)>180?mb_substr($globalPreview,0,177).'…':$globalPreview; ?>
        <div class="prof-ovr-inline-item<?= $enabled?' enabled':'' ?>" data-path="<?= lorkhan_ui_h($path) ?>">
            <div class="prof-ovr-inline-info"><div class="prof-ovr-inline-name"><span aria-hidden="true"><?= lorkhan_ui_h($definition['icon']) ?></span><label for="<?= lorkhan_ui_h($id) ?>"><?= lorkhan_ui_h($definition['label']) ?></label></div>
                <div class="prof-ovr-inline-description"><?= lorkhan_ui_h($coreOverrideHelp[$path]) ?></div>
                <div class="prof-ovr-inherited-value">Global value: <?= lorkhan_ui_h($globalPreview) ?></div>
            </div>
            <div class="prof-ovr-inline-controls"><label class="prof-ovr-toggle"><input type="checkbox" data-core-override-enabled<?= $enabled?' checked':'' ?> aria-label="<?= lorkhan_ui_h('Override '.$definition['label']) ?>"> Override</label>
                <?php if ($definition['type'] === 'booleanmap'): ?>
                <fieldset id="<?= lorkhan_ui_h($id) ?>" class="context-selection-options" data-core-override-input<?= $enabled?'':' disabled' ?>><legend class="visually-hidden"><?= lorkhan_ui_h($definition['label']) ?></legend><?php foreach($definition['choices'] as $choice=>$label): ?><label><input type="checkbox" data-context-key="<?= lorkhan_ui_h($choice) ?>"<?= $value[$choice]?' checked':'' ?>> <?= lorkhan_ui_h($label) ?></label><?php endforeach; ?></fieldset>
                <?php elseif ($definition['type'] === 'choice'): ?>
                <select id="<?= lorkhan_ui_h($id) ?>" class="prof-ovr-inline-input" data-core-override-input<?= $enabled?'':' disabled' ?>><?php foreach ($definition['choices'] as $choice): ?><option value="<?= lorkhan_ui_h($choice) ?>"<?= $value===$choice?' selected':'' ?>><?= lorkhan_ui_h(ucfirst($choice)) ?></option><?php endforeach; ?></select>
                <?php elseif ($definition['multiline'] ?? false): ?>
                <textarea id="<?= lorkhan_ui_h($id) ?>" class="prof-ovr-inline-input" data-core-override-input rows="3" maxlength="<?= $definition['maxBytes'] ?>"<?= $enabled?'':' disabled' ?>><?= lorkhan_ui_h(is_array($value)?implode("\n",$value):(string)$value) ?></textarea>
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
    </div>
</details>
