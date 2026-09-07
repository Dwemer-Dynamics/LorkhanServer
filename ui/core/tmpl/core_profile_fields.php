<?php
declare(strict_types=1);

use LorkhanServer\Application\DiaryGenerationPolicy;
use LorkhanServer\Application\EffectiveSettingsResolver;

$routing=is_array($content['routing']??null)?$content['routing']:[];
$overrides=is_array($content['settings_overrides']??null)?$content['settings_overrides']:[];
$evolution=EffectiveSettingsResolver::profileEvolutionDefaults($overrides['profile_evolution']??null);
$profileMeta=is_array($profileMeta??null)?$profileMeta:[];$creatingProfile=($coreProfileMode??'edit')==='create';
$profileIsDefault=filter_var($profileMeta['default_npc']??false,FILTER_VALIDATE_BOOL);$diaryDefaults=DiaryGenerationPolicy::defaults();
$values=['max_words'=>(int)($overrides['response']['max_words']??0),
    'rechat'=>($overrides['behavior']['rechat']??false)===true,'rechat_max_depth'=>(int)($overrides['behavior']['rechat_max_depth']??2),
    'rechat_probability_percent'=>(int)($overrides['behavior']['rechat_probability_percent']??50),
    'rechat_allow_actions'=>($overrides['behavior']['rechat_allow_actions']??false)===true,
    'recent_turn_limit'=>(int)($overrides['memory']['recent_turn_limit']??20),'diary_enabled'=>($overrides['diary']['enabled']??false)===true,
    'diary_automatic_enabled'=>($overrides['diary']['automatic_enabled']??false)===true,
    'diary_automatic_wait_enabled'=>($overrides['diary']['automatic_wait_enabled']??false)===true,
    'diary_automatic_interval_seconds'=>(int)($overrides['diary']['automatic_interval_seconds']??$diaryDefaults['automatic_interval_seconds']),
    'diary_include_in_context'=>($overrides['diary']['include_in_context']??true)===true,
    'diary_context_turn_limit'=>(int)($overrides['diary']['context_turn_limit']??$diaryDefaults['context_turn_limit']),
    'diary_prompt'=>(string)($overrides['diary']['prompt']??$diaryDefaults['prompt'])];

$routeSelect=static function(string$name,string$label,string$icon,string$description,array$rows,string$blankLabel='Disabled')use($routing):void{
    $current=(string)($routing[$name]??'');if($current!==''&&!in_array($current,array_column($rows,'configuration_id'),true))$rows[]=['configuration_id'=>$current,'name'=>'Unavailable connector']; ?>
    <div class="connector-option-card"><div class="setting-key"><span class="setting-icon"><?php echo$icon; ?></span><span id="<?php echo lorkhan_ui_h($name); ?>-label"><?php echo lorkhan_ui_h($label); ?></span></div><div class="setting-desc"><?php echo lorkhan_ui_h($description); ?></div><div class="setting-control"><select name="<?php echo lorkhan_ui_h($name); ?>" aria-labelledby="<?php echo lorkhan_ui_h($name); ?>-label"><option value=""><?php echo lorkhan_ui_h($blankLabel); ?></option><?php foreach($rows as$row):$id=(string)$row['configuration_id']; ?><option value="<?php echo lorkhan_ui_h($id); ?>"<?php echo$current===$id?' selected':''; ?>><?php echo lorkhan_ui_h($row['name']); ?></option><?php endforeach; ?></select></div></div>
<?php };
$toggleCard=static function(string$name,string$icon,string$title,string$description,bool$enabled):void{ ?>
    <label class="profile-toggle-card"><span class="profile-toggle-heading"><span><?php echo$icon.' '.lorkhan_ui_h($title); ?></span><span class="profile-toggle-control"><input type="checkbox" name="<?php echo lorkhan_ui_h($name); ?>" value="1"<?php echo$enabled?' checked':''; ?>><span class="toggle-text"><?php echo$enabled?'On':'Off'; ?></span></span></span><span class="profile-toggle-description"><?php echo lorkhan_ui_h($description); ?></span></label>
<?php };
$copyButton=static function(string$name,string$label)use($creatingProfile):void{
    if($creatingProfile)return;
    $fields=['setting_response_max_words'=>'response.max_words','setting_behavior_rechat_max_depth'=>'behavior.rechat_max_depth',
        'setting_behavior_rechat_probability_percent'=>'behavior.rechat_probability_percent','setting_behavior_rechat_allow_actions'=>'behavior.rechat_allow_actions',
        'setting_memory_recent_turn_limit'=>'memory.recent_turn_limit','setting_diary_context_turn_limit'=>'diary.context_turn_limit',
        'setting_diary_automatic_interval_seconds'=>'diary.automatic_interval_seconds','setting_diary_prompt'=>'diary.prompt']; ?>
    <button type="button" class="profile-setting-sync-btn" data-profile-copy-setting="<?php echo lorkhan_ui_h($fields[$name]); ?>" data-profile-copy-control="<?php echo lorkhan_ui_h($name); ?>" data-profile-copy-label="<?php echo lorkhan_ui_h($label); ?>" aria-label="Copy <?php echo lorkhan_ui_h($label); ?> to all profiles">Copy to all</button>
<?php };
$numberField=static function(string$name,string$label,string$description,int$value,int$min,int$max)use($copyButton):void{ ?>
    <div class="setting-row"><div><div class="setting-key"><label for="<?php echo lorkhan_ui_h($name); ?>"><?php echo lorkhan_ui_h($label); ?></label> <?php $copyButton($name,$label); ?></div><div class="setting-desc"><?php echo lorkhan_ui_h($description); ?></div></div>
        <div class="setting-control<?php echo $name === 'setting_response_max_words' ? '' : ' range-pair'; ?>">
            <?php if ($name !== 'setting_response_max_words'): ?><input type="range" min="<?php echo $min; ?>" max="<?php echo $max; ?>" step="1" value="<?php echo $value; ?>" data-range-for="<?php echo lorkhan_ui_h($name); ?>" aria-label="<?php echo lorkhan_ui_h($label); ?> slider"><?php endif; ?>
            <input id="<?php echo lorkhan_ui_h($name); ?>" type="number" min="<?php echo$min; ?>" max="<?php echo$max; ?>" name="<?php echo lorkhan_ui_h($name); ?>" value="<?php echo$value; ?>" required>
        </div>
    </div>
<?php }; ?>

<div class="connector-card profile-core-card">
    <div class="connector-title">Profile Core</div><div class="connector-subtitle">Response and diary defaults for every NPC assigned to this profile.</div>
    <div class="profile-core-grid"><div class="profile-core-compact-field"><label for="profile-label">Name</label><input id="profile-label" name="label" required maxlength="256" value="<?php echo lorkhan_ui_h($profileMeta['label']??''); ?>"></div><div class="profile-core-compact-field"><label for="profile-slot">Slot</label><select id="profile-slot" name="slot"><option value="">&mdash;</option><?php foreach(range(1,4)as$slot):$owner=(string)($usedProfileSlots[$slot]??'');$unavailable=$owner!==''&&$owner!==(string)($profileMeta['core_profile_id']??''); ?><option value="<?php echo$slot; ?>"<?php echo(int)($profileMeta['slot']??0)===$slot?' selected':''; ?><?php echo$unavailable?' disabled':''; ?>><?php echo$slot; ?></option><?php endforeach; ?></select></div>
        <label class="profile-default-card<?php echo!$creatingProfile&&$profileIsDefault?' is-readonly':''; ?>"><?php if(!$creatingProfile&&$profileIsDefault): ?><input type="hidden" name="default_npc" value="1"><?php endif; ?><span class="profile-toggle-heading"><span>&#x1F464; Default NPC</span><span class="profile-toggle-control"><input type="checkbox"<?php echo!$creatingProfile&&$profileIsDefault?' disabled aria-disabled="true"':' name="default_npc" value="1"'; ?><?php echo$profileIsDefault?' checked':''; ?>><span class="toggle-text"><?php echo$profileIsDefault?'On':'Off'; ?></span></span></span><span class="profile-toggle-description">Use for newly discovered NPCs.</span></label></div>
    <div class="profile-prompt-field"><label for="profile-prompt">Profile Prompt</label><textarea id="profile-prompt" name="prompt" maxlength="65536"><?php echo lorkhan_ui_h($content['prompt']??''); ?></textarea><small class="hint">Instructions shared by NPCs using this profile.</small></div>
    <div class="profile-toggle-groups">
<section class="profile-toggle-group"><h3 class="profile-toggle-group-title">Profiles &amp; Memories</h3><input type="hidden" name="memory_switches_present" value="1"><div class="profile-toggle-grid">
<?php $toggleCard('profile_evolution_enabled','&#x1F504;','Dynamic Profile','Enable automatic profile updates for newly discovered NPCs. Existing NPC choices are unchanged.',$evolution['enabled']);
foreach (['mid_term_enabled'=>'Middle Term Memory','short_term_enabled'=>'Short Term Memory','long_term_enabled'=>'Long Term Memory'] as $field=>$label) {
    $toggleCard('setting_memory_'.$field,'&#x1F9E0;',$label,'Include this memory tier in dialogue context.',($overrides['memory'][$field]??true)===true);
} ?></div></section><section class="profile-toggle-group"><h3 class="profile-toggle-group-title">Conversation</h3><div class="profile-toggle-grid"><?php $toggleCard('setting_behavior_rechat','&#x1F501;','Rechat','Continue eligible conversations after the spoken queue finishes.',$values['rechat']); ?></div></section><section class="profile-toggle-group"><h3 class="profile-toggle-group-title">Diary</h3><div class="profile-toggle-grid"><?php $toggleCard('setting_diary_enabled','&#x1F4D3;','Diary Generation','Allow requested and automatic diary generation for this profile.',$values['diary_enabled']);$toggleCard('setting_diary_automatic_enabled','&#x23F1;&#xFE0F;','Automatic Diary','Generate diaries on the timer and after sleeping.',$values['diary_automatic_enabled']);$toggleCard('setting_diary_automatic_wait_enabled','&#x1F6CF;&#xFE0F;','Diary After Waiting','Also generate an automatic diary after waiting.',$values['diary_automatic_wait_enabled']);$toggleCard('setting_diary_latest_entry_in_context','&#x1F4D6;','Include Latest Diary Entry',"Include the NPC's latest diary entry in response context.",($overrides['diary']['latest_entry_in_context']??false)===true);$toggleCard('setting_diary_include_in_context','&#x1F4D6;','Diary In Context','Include saved diary narratives in roleplay context.',$values['diary_include_in_context']); ?></div></section><section class="profile-toggle-group"><h3 class="profile-toggle-group-title">LLM</h3><div class="profile-toggle-grid"><?php $toggleCard('llm_randomizer_enabled','&#x1F3B2;','LLM Randomizer','Rotate among the four response models.',($routing['llm_randomizer_enabled']??false)===true);$toggleCard('llm_fallback_enabled','&#x1F504;','LLM Fallback','Retry with the fallback model.',($routing['llm_fallback_enabled']??false)===true); ?></div></section></div>
</div>

<div class="connector-card profile-connectors-card"><div class="connector-title">Connector Selection</div><div class="connector-subtitle">Choose response, voice, prompt, fallback, and diary connectors.</div><div class="connector-selection-grid"><section class="connector-group-card"><h3 class="connector-group-title">Response Connectors</h3><div class="connector-group-fields">
    <?php $routeSelect('llm_configuration_id','Standard LLM','&#x1F579;&#xFE0F;','Default model for normal dialogue.',$llm,'Use server runtime');$routeSelect('llm_fast_configuration_id','Fast LLM','&#x1F3C3;','Lower-latency model for quick reactions.',$llm);$routeSelect('llm_powerful_configuration_id','Powerful LLM','&#x1F4AA;','Higher-quality model for deeper responses.',$llm);$routeSelect('llm_experimental_configuration_id','Experimental LLM','&#x1F9EA;','Optional model for testing.',$llm); ?>
    </div></section><section class="connector-group-card"><h3 class="connector-group-title">Other Connectors</h3><div class="connector-group-fields"><?php $routeSelect('tts_configuration_id','TTS Connector','&#x1F50A;','Voice synthesis used for spoken NPC output.',$tts,'Use selected TTS runtime');$routeSelect('prompt_configuration_id','Dialogue Prompt','&#x1F4AC;','Prompt template used by assigned NPCs.',$prompts,'Use first available prompt');$routeSelect('llm_fallback_configuration_id','Fallback LLM','&#x1F504;','Backup model when fallback is enabled.',$llm);$routeSelect('diary_generation_configuration_id','Diary LLM','&#x1F4D3;','Model used for requested and automatic diaries.',$llm); ?></div></section></div></div>

<div class="connector-card profile-settings-card"><div class="connector-title">Profile Settings</div>
<input type="hidden" name="profile_evolution_present" value="1">
<div class="profile-feature-grid"><div class="provider-card">
    <div class="provider-head"><div class="provider-title"><div class="provider-icon">&#x1F6E0;&#xFE0F;</div><div>Dynamic Profile Fields</div></div></div>
    <div class="provider-body"><div class="setting-row">
        <div><div class="setting-key" id="dynamic-profile-fields-label"><span class="setting-icon">&#x1F6E0;&#xFE0F;</span> Editable Fields</div><div class="setting-desc">Choose which fields automatic updates may rewrite for newly discovered NPCs. Existing NPC choices are unchanged.</div></div>
        <div class="setting-control setting-control-wide"><div class="profile-setting-chips" role="group" aria-labelledby="dynamic-profile-fields-label">
            <?php foreach(EffectiveSettingsResolver::DYNAMIC_PROFILE_FIELDS as $field): ?>
            <label class="profile-setting-chip"><input type="checkbox" name="profile_evolution_fields[]" value="<?php echo lorkhan_ui_h($field); ?>"<?php echo in_array($field,$evolution['fields'],true)?' checked':''; ?>><span><?php echo lorkhan_ui_h($field==='speech_style'?'speechstyle':$field); ?></span></label>
            <?php endforeach; ?>
        </div></div>
    </div></div>
</div></div>
<div class="profile-settings-columns"><section class="profile-settings-group"><h3 class="profile-settings-heading">LLM</h3><div class="provider-card"><?php $numberField('setting_response_max_words','Max Words Limit','Maximum words requested across the complete response. Set 0 for no additional word limit. This does not buffer or truncate streamed speech.',$values['max_words'],0,10000); ?></div></section><section class="profile-settings-group"><h3 class="profile-settings-heading">Rechat</h3><div class="provider-card">
<div class="rechat-calculator"><div class="rechat-calculator-title"><span>&#x1F501;</span><span>Rechat Response Calculator</span></div>
<div id="rechat-calc-output" aria-live="polite" aria-atomic="true"></div>
<div class="setting-desc">Includes the initial reply and up to the configured number of NPC continuation rounds. Chances assume Rechat is enabled and a responder remains eligible.</div></div>
<?php $numberField('setting_behavior_rechat_max_depth','Rechat Rounds','Maximum NPC continuation rounds.',$values['rechat_max_depth'],1,20);$numberField('setting_behavior_rechat_probability_percent','Rechat Probability','Chance from 0 to 100 that the conversation continues.',$values['rechat_probability_percent'],0,100); ?><div class="setting-row"><div><div class="setting-key">Rechat Allow Actions <?php $copyButton('setting_behavior_rechat_allow_actions','Rechat Allow Actions'); ?></div><div class="setting-desc">Allow policy-checked actions during Rechat.</div></div><label class="profile-inline-toggle"><input type="checkbox" name="setting_behavior_rechat_allow_actions" aria-label="Rechat Allow Actions" value="1"<?php echo $values['rechat_allow_actions']?' checked':''; ?>> Enabled</label></div><?php  ?></div></section><section class="profile-settings-group"><h3 class="profile-settings-heading">Context</h3><div class="provider-card"><?php $numberField('setting_memory_recent_turn_limit','Context History','Maximum recent conversation turns in context.',$values['recent_turn_limit'],1,100);$numberField('setting_diary_context_turn_limit','Context History Diary','Maximum witnessed turns in a diary.',$values['diary_context_turn_limit'],1,100); ?></div></section><section class="profile-settings-group profile-diary-settings"><h3 class="profile-settings-heading">Diary</h3><div class="provider-card"><?php  ?><div class="setting-row profile-setting-stacked"><div><div class="setting-key"><label for="profile-diary-prompt">Diary Prompt</label> <?php $copyButton('setting_diary_prompt','Diary Prompt'); ?></div><div class="setting-desc">Instruction used for requested and automatic diaries.</div></div><div class="setting-control"><textarea id="profile-diary-prompt" name="setting_diary_prompt" rows="3" maxlength="8192"><?php echo lorkhan_ui_h($values['diary_prompt']); ?></textarea></div></div><?php $numberField('setting_diary_automatic_interval_seconds','Automatic Diary Cooldown','Minimum real-time seconds between automatic diaries for each profile.',$values['diary_automatic_interval_seconds'],30,86400); ?></div></section></div></div>
