<?php
declare(strict_types=1);
// Current rows and modal structure derive from Herika's NPC override_editor.php.
// New NPC drafts have no persisted profile yet; use the runtime defaults, not guessed booleans/zeros.
if ($effectiveSettings === []) $effectiveSettings = (new \LorkhanServer\Application\EffectiveSettingsResolver())->resolve([], [], []);
$contextSelectionGroups=require __DIR__.'/context_selection_groups.php';
$overrideLabels = [
    'profile_management.autofill_custom_profiles'=>'Autofill Custom Profiles',
    'profile_management.autofill_custom_profiles_trigger'=>'Autofill Custom Profiles Trigger',
    'context.sections'=>'Context Sections','context.details'=>'Context Details',
    'context.event_types'=>'Event Type Filter','prompt.emote_moods'=>'Emote Moods',
    'context.location_blacklist'=>'Location Blacklist','context.item_blacklist'=>'Item Blacklist','context.magic_effects_blacklist'=>'Magic Effect Blacklist',
    'quest_comments.enabled'=>'Quest Comment', 'quest_comments.chance_percent'=>'Quest Comment Chance',
    'bored_event.chance_percent'=>'Bored Event Chance',
    'behavior.rechat'=>'Rechat', 'behavior.rechat_max_depth'=>'Rechat Rounds',
    'behavior.rechat_probability_percent'=>'Rechat Probability', 'behavior.rechat_allow_actions'=>'Rechat Actions',
    'memory.recent_turn_limit'=>'Context History', 'memory.short_term_enabled'=>'Short Term Memory',
    'memory.mid_term_enabled'=>'Middle Term Memory', 'memory.long_term_enabled'=>'Long Term Memory',
    'response.max_words'=>'Maximum Response Words',
    'memory.short_term_max_summaries'=>'Max Summaries',
    'response.core_lang'=>'Core Language', 'response.lang_llm_xtts'=>'LLM Output Language',
    'diary.prompt'=>'Diary Prompt', 'diary.automatic_interval_seconds'=>'Diary Cooldown',
    'diary.context_turn_limit'=>'Context History Diary Event Count',
    'profile_evolution.history_limit'=>'Context History Dynamic Profile Event Count',
    'behavior.rechat_mode'=>'Rechat Mode', 'relationship.enabled'=>'Relationship System Enabled',
    'behavior.rechat_strict_targeting'=>'Strict Rechat Targeting','behavior.open_rechat'=>'Open Rechat',
    'behavior.end_conversation_cooldown_seconds'=>'End Conversation Cooldown',
    'behavior.combat_bark_period_seconds'=>'Combat Bark Cooldown',
    'relationship.update_chance_percent'=>'Relationship Update Chance',
    'context.prompt_timestamp'=>'Prompt Timestamp', 'context.ground_items_descriptions_only'=>'Ground Items Descriptions Only',
    'context.inventory_items_descriptions_only'=>'Inventory Items Descriptions Only', 'context.power_awareness_enabled'=>'Power Awareness Enabled',
    'context.short_term_in_compact_chat'=>'Short Term Memory in Compact Chat',
    'context.detect_magic_events'=>'Detect Magic Events',
    'context.transformation_detection'=>'Transformation Detection', 'context.hide_ambient_combat'=>'Hide Ambient Combat', 'prompt.prompt_head'=>'Prompt Head',
    'oghma.location_context_enabled'=>'Force Location Oghma', 'oghma.topic_count'=>'Oghma Articles Amount',
    'oghma.extractor_fallback_enabled'=>'Oghma Extractor Fallback', 'oghma.extractor_timeout_ms'=>'Oghma Extractor Timeout',
    'oghma.enabled'=>'Oghma Infinium', 'oghma.result_limit'=>'Oghma Result Limit', 'oghma.racial_context_enabled'=>'Force Racial Oghma',
];
$help=[
    'context.detect_magic_events'=>'Include observed successful spell casts in this NPC’s conversation context. The Infoaction event filter and Magic Effect Blacklist still apply; original event records are retained.',
    'behavior.combat_bark_period_seconds'=>'Cooldown in seconds between combat barks (5–600). Does not enable combat barks. The active actor’s effective setting is sent to the game controls.',
    'profile_management.autofill_custom_profiles'=>'Automatically fill this NPC’s empty, unlocked profile after enough witnessed dialogue. Dynamic Profile updates remain separate.',
    'profile_management.autofill_custom_profiles_trigger'=>'Witnessed dialogue records required before automatic profile backfill (10–100).',
            'behavior.rechat_strict_targeting'=>'Require this responder to address the previous speaker directly. Captured when the chain starts.',
            'behavior.open_rechat'=>'Allow nearby participants in new chains started by this NPC. Off keeps new chains listener-only; existing chains retain their mode.',
            'behavior.end_conversation_cooldown_seconds'=>'Seconds this NPC refuses AI conversation after a successful End Conversation action (0–300). Zero removes the cooldown; ordinary Rechat completion does not trigger it.',
            'relationship.update_chance_percent'=>'Percent chance that an eligible completed response queues a relationship update (0–100). Zero stops automatic updates; saved relationships remain in context. Relationship System must be enabled.',
            'response.core_lang'=>'Language of built-in roleplay instructions. Blank uses English; custom prompts and output translation are unchanged.',
            'response.lang_llm_xtts'=>'Ask the LLM for the spoken language and use it for XTTS/Chatterbox. Missing or unsupported codes keep the configured voice language.',
            'memory.short_term_max_summaries'=>'Maximum past-scene summaries included in a response (1–50). Used only when Short Term Memory is enabled.',
            'diary.prompt'=>'Instructions for this NPC’s diary generation. Keep between 1 and 8192 UTF-8 bytes; this does not enable automatic diaries.',
            'diary.automatic_interval_seconds'=>'Minimum seconds between this NPC’s automatic diary requests, including timer, sleep and wait triggers (10–86400).',
            'diary.context_turn_limit'=>'Witnessed history records included when generating this NPC’s diary (0–400). Zero uses the regular Context History limit.',
            'profile_evolution.history_limit'=>'History records included in this NPC’s automatic profile updates (0–400). Zero uses the regular Context History limit; this does not enable Dynamic Profile.',
        ];
$overrideCatalog=[];$overrideValues=[];
foreach (\LorkhanServer\Application\SettingsCatalog::npcOverrideFields() as $section=>$fields) {
    foreach ($fields as $key) {
        $path=$section.'.'.$key;
        $range=\LorkhanServer\Application\SettingsCatalog::ranges()[$path] ?? ($path==='response.max_words'?[0,10000]:null);
        if($path==='memory.short_term_max_summaries')$range=[1,50];
        if($path==='diary.automatic_interval_seconds')$range=[10,86400];
        if($path==='diary.context_turn_limit')$range=[0,400];
        if($path==='profile_evolution.history_limit')$range=[0,400];
        if($path==='profile_management.autofill_custom_profiles_trigger')$range=[10,100];
        if($section==='oghma')$range=match($key){'topic_count'=>[1,3],'result_limit'=>[1,5],'extractor_timeout_ms'=>[250,3000],default=>null};
        $default=$effectiveSettings['settings'][$section][$key]
            ?? \LorkhanServer\Application\SettingsCatalog::clientDefaults()[$section][$key] ?? ($range?0:true);
        if($path==='response.core_lang')$default=$effectiveSettings['settings']['response']['core_lang']??'';
        if($path==='response.lang_llm_xtts')$default=$effectiveSettings['settings']['response']['lang_llm_xtts']??false;
        $overrideCatalog[$path]=['label'=>$overrideLabels[$path],'type'=>$range?'integer':'boolean','range'=>$range,'value'=>$default];
        if($path==='quest_comments.chance_percent')$overrideCatalog[$path]=[
            'label'=>$overrideLabels[$path],'type'=>'choice','choices'=>[10,25,50,75,100],'suffix'=>'%','value'=>$default];
        if($path==='response.core_lang')$overrideCatalog[$path]=[
            'label'=>$overrideLabels[$path],'type'=>'choice',
            'choices'=>array_keys(\LorkhanServer\Application\CoreProfileLanguage::LABELS),
            'labels'=>\LorkhanServer\Application\CoreProfileLanguage::LABELS,'value'=>$default];
        if(in_array($section,['context','prompt'],true))$default=$effectiveSettings[$section][$key]??($section==='prompt'?'':false);
        if($path==='relationship.enabled')$default=($effectiveSettings['settings']['relationship']['enabled']??false)===true;
        if($section==='context'||$section==='relationship')$overrideCatalog[$path]['value']=$default;
        if(in_array($key,['location_blacklist','item_blacklist','magic_effects_blacklist'],true))$overrideCatalog[$path]=[
            'label'=>$overrideLabels[$path],'type'=>'textlist','maxBytes'=>65792,'allowEmpty'=>true,'value'=>$effectiveSettings['context'][$key]??[],
            'help'=>'One entry per line, at most 256 entries and 256 UTF-8 bytes each. Matching context is hidden from prompts, not deleted from history. Blank clears the inherited blacklist; remove the override to inherit.'];
        if($path==='behavior.rechat_mode')$overrideCatalog[$path]=['label'=>$overrideLabels[$path],'type'=>'choice','choices'=>['tight','conversational','group','random'],'value'=>$default];
        if($path==='prompt.prompt_head')$overrideCatalog[$path]=['label'=>$overrideLabels[$path],'type'=>'string','maxBytes'=>8192,'allowEmpty'=>true,'value'=>$default,'help'=>'System roleplay instructions for this NPC. Blank uses the built-in prompt; removing the override restores Core or global inheritance.'];
        if($path==='prompt.emote_moods')$overrideCatalog[$path]=['label'=>$overrideLabels[$path],'type'=>'string','maxBytes'=>4096,'allowEmpty'=>true,'value'=>$default,'help'=>'Moods and emotes offered when this NPC has no custom mood list. Blank removes inherited suggestions; remove the override to inherit.'];
        if($path==='context.event_types')$overrideCatalog[$path]=['label'=>$overrideLabels[$path],'type'=>'textlist','maxBytes'=>4096,'allowEmpty'=>true,'value'=>$effectiveSettings['context']['event_types']??\LorkhanServer\Application\SettingsCatalog::eventTypes(),'choices'=>\LorkhanServer\Application\SettingsCatalog::eventTypes(),'help'=>'One included event type per line: '.implode(', ',\LorkhanServer\Application\SettingsCatalog::eventTypes()).'. Blank excludes event history from context without deleting it. Remove the override to inherit.'];
        if($path==='diary.prompt')$overrideCatalog[$path]=[
            'label'=>$overrideLabels[$path],'type'=>'string','maxBytes'=>8192,
            'value'=>$effectiveSettings['settings']['diary']['prompt']??\LorkhanServer\Application\DiaryGenerationPolicy::defaults()['prompt']];
        if($section==='context'&&in_array($key,['sections','details'],true)){
            $choices=[];foreach($contextSelectionGroups as[$group,$items])if($group===$key)foreach($items as$name=>$info)$choices[$name]=$info[0];
            $overrideCatalog[$path]=['label'=>$overrideLabels[$path],'type'=>'booleanmap','choices'=>$choices,'value'=>$effectiveSettings['context'][$key]??\LorkhanServer\Application\SettingsCatalog::globalDefaults()['context'][$key],
                'help'=>'Select optional context to include. All unchecked excludes this group; remove the override to inherit. Required speaker and action instructions remain. Details require their containing section.'];
        }
        if(isset($help[$path]))$overrideCatalog[$path]['help']=$help[$path].' Remove this override to restore inheritance.';
        $overrideCatalog[$path]['category']=match($section){
            'behavior'=>'Rechat','memory'=>'Memory','oghma'=>'Oghma',
            'context','relationship'=>'Context','prompt'=>'Prompt',default=>'Misc'
        };
        if(in_array($path,['context.prompt_timestamp','context.location_blacklist','context.item_blacklist',
            'context.magic_effects_blacklist','context.event_types','relationship.update_chance_percent'],true))
            $overrideCatalog[$path]['category']='Prompt';
        if(in_array($path,['behavior.rechat_strict_targeting','behavior.open_rechat','behavior.end_conversation_cooldown_seconds','behavior.combat_bark_period_seconds'],true))
            $overrideCatalog[$path]['category']='Misc';
        if($path==='context.short_term_in_compact_chat')$overrideCatalog[$path]['category']='Memory';
        if($section==='profile_management')$overrideCatalog[$path]['category']='Misc';
        if(array_key_exists($key,$content['settings_overrides'][$section]??[]))$overrideValues[$section][$key]=$content['settings_overrides'][$section][$key];
        if($section==='diary'&&array_key_exists($key,$content['diary']??[]))$overrideValues[$section][$key]=$content['diary'][$key];
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
                <textarea id="<?= lorkhan_ui_h($overrideId) ?>-text" rows="6" data-npc-override-text></textarea>
                <fieldset class="context-selection-options" data-npc-override-map hidden></fieldset>
                <p data-npc-override-help></p>
            </div>
        </div>
        <footer><button type="button" data-npc-override-cancel>Cancel</button><button type="button" class="npc-ovr-add" data-npc-override-save hidden>Apply to draft</button></footer>
    </dialog>
</div>
