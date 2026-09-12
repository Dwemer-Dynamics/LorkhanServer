<?php
declare(strict_types=1);

use LorkhanServer\Application\DiaryGenerationPolicy;
use LorkhanServer\Application\EffectiveSettingsResolver;

$routing=is_array($content['routing']??null)?$content['routing']:[];
$overrides=is_array($content['settings_overrides']??null)?$content['settings_overrides']:[];
$evolution=EffectiveSettingsResolver::profileEvolutionDefaults($overrides['profile_evolution']??null);
$rpgComments=$effectiveCoreSettings['settings']['rpg_comments']??array_replace(\LorkhanServer\Application\SettingsCatalog::globalDefaults()['rpg_comments'],$overrides['rpg_comments']??[]);
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

$routeSelect=static function(string$name,string$label,string$icon,string$description,array$rows,string$blankLabel='Disabled')use($routing,$webRoot,$installationId):void{
    $current=(string)($routing[$name]??'');if($current!==''&&!in_array($current,array_column($rows,'configuration_id'),true))$rows[]=['configuration_id'=>$current,'name'=>'Unavailable connector']; ?>
    <div class="connector-option-card"><div class="setting-key"><span class="setting-icon"><?php echo$icon; ?></span><span id="<?php echo lorkhan_ui_h($name); ?>-label"><?php echo lorkhan_ui_h($label); ?></span></div><div class="setting-desc"><?php echo lorkhan_ui_h($description); ?></div><div class="setting-control"><select name="<?php echo lorkhan_ui_h($name); ?>" aria-labelledby="<?php echo lorkhan_ui_h($name); ?>-label"><option value=""><?php echo lorkhan_ui_h($blankLabel); ?></option><?php foreach($rows as$row):$id=(string)$row['configuration_id']; ?><option value="<?php echo lorkhan_ui_h($id); ?>"<?php echo$current===$id?' selected':''; ?>><?php echo lorkhan_ui_h($row['name']); ?></option><?php endforeach; ?></select></div>
    <?php $editorPage=match($name){'prompt_configuration_id'=>'prompts_manager.php','tts_configuration_id'=>'core/tts_connectors.php',default=>'core/llm_connectors.php'}; ?>
        <button type="button" class="btn-base profile-connector-edit" data-connector-edit data-editor-url="<?php echo lorkhan_ui_h($webRoot.'/ui/'.$editorPage.'?'.http_build_query(['installation_id'=>$installationId,'embed'=>'1','partial'=>'editor'])); ?>" aria-expanded="false" aria-controls="editor-<?php echo lorkhan_ui_h($name); ?>"<?php echo $current===''?' disabled':''; ?>>Edit <?php echo lorkhan_ui_h($label); ?></button>
        <div class="profile-connector-editor" id="editor-<?php echo lorkhan_ui_h($name); ?>" hidden>
            <p class="hint">Save All includes open connector and prompt changes. Changes also affect other profiles using the same document.</p>
            <iframe title="<?php echo lorkhan_ui_h($label); ?> editor" data-connector-frame></iframe>
            <button type="button" class="btn-base" data-connector-close>Close editor</button>
        </div>
    </div>
<?php };
$toggleCard=static function(string$name,string$icon,string$title,string$description,bool$enabled):void{ ?>
    <label class="profile-toggle-card"><span class="profile-toggle-heading"><span><?php echo$icon.' '.lorkhan_ui_h($title); ?></span><span class="profile-toggle-control"><input type="checkbox" name="<?php echo lorkhan_ui_h($name); ?>" value="1"<?php echo$enabled?' checked':''; ?>><span class="toggle-text"><?php echo$enabled?'On':'Off'; ?></span></span></span><span class="profile-toggle-description"><?php echo lorkhan_ui_h($description); ?></span></label>
<?php };
$copyButton=static function(string$name,string$label)use($creatingProfile):void{
    if($creatingProfile)return;
    $fields=['setting_quest_comments_enabled'=>'quest_comments.enabled','setting_quest_comments_chance_percent'=>'quest_comments.chance_percent','setting_bored_event_chance_percent'=>'bored_event.chance_percent','setting_response_lang_llm_xtts'=>'response.lang_llm_xtts','setting_response_core_lang'=>'response.core_lang','setting_response_max_words'=>'response.max_words','setting_behavior_rechat_max_depth'=>'behavior.rechat_max_depth',
        'setting_behavior_combat_bark_period_seconds'=>'behavior.combat_bark_period_seconds','setting_behavior_rechat_probability_percent'=>'behavior.rechat_probability_percent','setting_behavior_rechat_allow_actions'=>'behavior.rechat_allow_actions',
        'setting_profile_evolution_history_limit'=>'profile_evolution.history_limit','setting_memory_recent_turn_limit'=>'memory.recent_turn_limit','setting_diary_context_turn_limit'=>'diary.context_turn_limit',
        'setting_diary_automatic_interval_seconds'=>'diary.automatic_interval_seconds','setting_diary_prompt'=>'diary.prompt']; ?>
    <button type="button" class="profile-setting-sync-btn" data-profile-copy-setting="<?php echo lorkhan_ui_h($fields[$name]); ?>" data-profile-copy-control="<?php echo lorkhan_ui_h($name); ?>" data-profile-copy-label="<?php echo lorkhan_ui_h($label); ?>" aria-label="Copy <?php echo lorkhan_ui_h($label); ?> to all profiles">Copy to all</button>
<?php };
$numberField=static function(string$name,string$label,string$description,int$value,int$min,int$max)use($copyButton):void{
    $icon=str_contains($name,'combat')?'&#x2694;&#xFE0F;':(str_contains($name,'diary')?'&#x1F4D9;':(str_contains($name,'rechat')?'&#x1F501;':(str_contains($name,'history')||str_contains($name,'recent_turn')?'&#x1F9E0;':'&#x2699;&#xFE0F;'))); ?>
    <div class="setting-row"><div><div class="setting-key"><span class="setting-icon" aria-hidden="true"><?php echo $icon; ?></span><label for="<?php echo lorkhan_ui_h($name); ?>"><?php echo lorkhan_ui_h($label); ?></label> <?php $copyButton($name,$label); ?></div><div class="setting-desc"><?php echo lorkhan_ui_h($description); ?></div></div>
        <div class="setting-control<?php echo $name === 'setting_response_max_words' ? '' : ' range-pair'; ?>">
            <?php if ($name !== 'setting_response_max_words'): ?><input type="range" min="<?php echo $min; ?>" max="<?php echo $max; ?>" step="1" value="<?php echo $value; ?>" data-range-for="<?php echo lorkhan_ui_h($name); ?>" aria-label="<?php echo lorkhan_ui_h($label); ?> slider"><?php endif; ?>
            <input id="<?php echo lorkhan_ui_h($name); ?>" type="number" min="<?php echo$min; ?>" max="<?php echo$max; ?>" name="<?php echo lorkhan_ui_h($name); ?>" value="<?php echo$value; ?>" required>
        </div>
    </div>
<?php }; ?>

<div class="connector-card profile-core-card">
    <div class="connector-title">Profile Core</div><div class="connector-subtitle">&#x24D8; Core identity and runtime options for this profile.</div>
    <div class="profile-core-grid"><div class="profile-core-compact-field"><label for="profile-label">Name</label><input id="profile-label" name="label" required maxlength="256" value="<?php echo lorkhan_ui_h($profileMeta['label']??''); ?>"><small class="hint">Name shown when assigning this profile.</small></div><div class="profile-core-compact-field"><label for="profile-slot" title="Reserve one of the four Core Profile slots.">Slot <span aria-hidden="true">&#x24D8;</span></label><select id="profile-slot" name="slot"><option value="">&mdash;</option><?php foreach(range(1,4)as$slot):$owner=(string)($usedProfileSlots[$slot]??'');$unavailable=$owner!==''&&$owner!==(string)($profileMeta['core_profile_id']??''); ?><option value="<?php echo$slot; ?>"<?php echo(int)($profileMeta['slot']??0)===$slot?' selected':''; ?><?php echo$unavailable?' disabled':''; ?>><?php echo$slot; ?></option><?php endforeach; ?></select><small class="hint">Optional slot 1–4.</small></div>
        <label class="profile-default-card<?php echo!$creatingProfile&&$profileIsDefault?' is-readonly':''; ?>"><?php if(!$creatingProfile&&$profileIsDefault): ?><input type="hidden" name="default_npc" value="1"><?php endif; ?><span class="profile-toggle-heading"><span>&#x1F464; Default NPC</span><span class="profile-toggle-control"><input type="checkbox"<?php echo!$creatingProfile&&$profileIsDefault?' disabled aria-disabled="true"':' name="default_npc" value="1"'; ?><?php echo$profileIsDefault?' checked':''; ?>><span class="toggle-text"><?php echo$profileIsDefault?'On':'Off'; ?></span></span></span><span class="profile-toggle-description">Use for newly discovered NPCs.</span></label></div>
    <div class="profile-prompt-field"><label for="profile-prompt">Profile Prompt</label><textarea id="profile-prompt" name="prompt" maxlength="65536"><?php echo lorkhan_ui_h($content['prompt']??''); ?></textarea><small class="hint">Optional profile-specific system instructions appended to requests.</small></div>
    <div class="profile-toggle-groups">
<section class="profile-toggle-group"><h3 class="profile-toggle-group-title">Profiles &amp; Memories</h3><input type="hidden" name="memory_switches_present" value="1"><div class="profile-toggle-grid">
<?php $toggleCard('profile_evolution_enabled','&#x267B;&#xFE0F;','Dynamic Profile','Enable automatic profile updates for newly discovered NPCs. Existing NPC choices are unchanged.',$evolution['enabled']);
foreach (['mid_term_enabled'=>'Middle Term Memory','short_term_enabled'=>'Short Term Memory'] as $field=>$label) {
    $toggleCard('setting_memory_'.$field,$field==='mid_term_enabled'?'&#x1F4C3;':'&#x1F5C2;&#xFE0F;',$label,'Include this memory tier in dialogue context.',($overrides['memory'][$field]??true)===true);
} ?></div></section><section class="profile-toggle-group"><h3 class="profile-toggle-group-title">Diary</h3><div class="profile-toggle-grid"><?php $toggleCard('setting_diary_automatic_enabled','&#x1F4D9;','Auto Diary','Generate diaries on the timer and after sleeping.',$values['diary_automatic_enabled']);$toggleCard('setting_diary_automatic_wait_enabled','&#x23F3;','Auto Diary Wait','Also generate an automatic diary after waiting.',$values['diary_automatic_wait_enabled']);$toggleCard('setting_diary_latest_entry_in_context','&#x1F4D6;','Include Latest Diary Entry',"Include the NPC's latest diary entry in response context.",($overrides['diary']['latest_entry_in_context']??false)===true); ?></div></section><section class="profile-toggle-group"><h3 class="profile-toggle-group-title">LLM</h3><div class="profile-toggle-grid"><?php $toggleCard('llm_randomizer_enabled','&#x1F3B2;','LLM Randomizer','Rotate among the four response models.',($routing['llm_randomizer_enabled']??false)===true);$toggleCard('llm_fallback_enabled','&#x1F504;','LLM Fallback','Retry with the fallback model.',($routing['llm_fallback_enabled']??false)===true); ?></div></section></div>
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
</div>
<div class="provider-card profile-rpg-comments">
    <div class="provider-head"><div class="provider-title"><div class="provider-icon" aria-hidden="true">&#x1F3B2;</div><div>RPG Comments</div></div></div>
    <div class="provider-body profile-provider-body">
        <div class="setting-row">
            <div><div class="setting-key" id="rpg-comment-types-label"><span class="setting-icon" aria-hidden="true">&#x1F3B2;</span><span>Comment Types</span></div><div class="setting-desc">Pick which comments you want a chance to trigger when one of these ingame events happens.</div></div>
            <div class="setting-control setting-control-wide">
                <input type="hidden" name="rpg_comments_present" value="1">
                <div class="profile-setting-chips" role="group" aria-labelledby="rpg-comment-types-label">
                <?php foreach (['levelup','combat_end','sleep','wait'] as $event): ?>
                    <label class="profile-setting-chip"><input type="checkbox" name="profile_rpg_events[]" value="<?php echo lorkhan_ui_h($event); ?>"<?php echo in_array($event,$rpgComments['events'],true)?' checked':''; ?>> <span><?php echo lorkhan_ui_h($event); ?></span></label>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="setting-row">
            <div><div class="setting-key"><span class="setting-icon" aria-hidden="true">&#x1F501;</span><label for="setting_rpg_comments_chance_percent">RPG Comment Trigger Chance</label></div><div class="setting-desc">Probability that enabled RPG comments trigger when their conditions are met. 0 = Never | 50 = 50% | 100 = Always. Hard cooldown: 60 seconds between RPG comment events.</div></div>
            <div class="setting-control"><div class="range-pair">
                <input type="range" min="0" max="100" step="1" value="<?php echo (int)$rpgComments['chance_percent']; ?>" aria-label="RPG Comment Trigger Chance slider" data-range-for="setting_rpg_comments_chance_percent">
                <input type="number" id="setting_rpg_comments_chance_percent" name="setting_rpg_comments_chance_percent" min="0" max="100" step="1" value="<?php echo (int)$rpgComments['chance_percent']; ?>">
            </div></div>
        </div>
    </div>
</div>
<div class="provider-card profile-short-term-memory">
    <div class="provider-head"><div class="provider-title"><div class="provider-icon" aria-hidden="true">&#x1F5C2;&#xFE0F;</div><div>Short Term Memory</div></div></div>
    <div class="provider-body profile-provider-body">
        <?php $stmMax=(int)($overrides['memory']['short_term_max_summaries']??10); ?>
        <div class="setting-row">
            <div><div class="setting-key"><span class="setting-icon" aria-hidden="true">&#x1F5C2;&#xFE0F;</span><label for="setting_memory_short_term_max_summaries">Max Summaries</label></div><div class="setting-desc">How many past-scene summaries may be injected in one response. Higher = deeper memory and more tokens. Only used when Short Term Memory is enabled above.</div></div>
            <div class="setting-control"><div class="range-pair">
                <input type="range" min="1" max="50" step="1" value="<?php echo $stmMax; ?>" data-range-for="setting_memory_short_term_max_summaries" aria-label="Max Summaries slider">
                <input type="number" id="setting_memory_short_term_max_summaries" name="setting_memory_short_term_max_summaries" min="1" max="50" step="1" value="<?php echo $stmMax; ?>" required>
            </div></div>
        </div>
    </div>
</div></div>
<div class="content-section profile-settings-content"><div class="profile-settings-columns"><section class="profile-settings-group"><h2 class="profile-settings-heading">Language</h2><div class="provider-card profile-settings-group-card">
<div class="setting-row"><div><div class="setting-key"><span class="setting-icon" aria-hidden="true">&#x1F310;</span><label for="setting_response_core_lang">Core Lang</label> <?php $copyButton('setting_response_core_lang','Core Lang'); ?></div><div class="setting-desc">Language of the built-in roleplay instructions. Leave blank for English. Custom prompts and output translation are unchanged.</div></div><div class="setting-control"><select id="setting_response_core_lang" name="setting_response_core_lang"><?php foreach (\LorkhanServer\Application\CoreProfileLanguage::LABELS as $code=>$label): ?><option value="<?php echo lorkhan_ui_h($code); ?>"<?php echo ($overrides['response']['core_lang']??'')===$code?' selected':''; ?>><?php echo lorkhan_ui_h($label); ?></option><?php endforeach; ?></select></div></div><div class="setting-row"><div><div class="setting-key"><span class="setting-icon" aria-hidden="true">&#x1F310;</span><label for="setting_response_lang_llm_xtts">Lang Llm Xtts</label> <?php $copyButton('setting_response_lang_llm_xtts','Lang Llm Xtts'); ?></div><div class="setting-desc">Ask the LLM for the spoken language and use it for XTTS/Chatterbox. Missing or unsupported codes keep the configured voice language.</div></div><label class="profile-inline-toggle"><input id="setting_response_lang_llm_xtts" type="checkbox" name="setting_response_lang_llm_xtts" value="1"<?php echo ($overrides['response']['lang_llm_xtts']??false)===true?' checked':''; ?>><span class="toggle-text"><?php echo ($overrides['response']['lang_llm_xtts']??false)===true?'On':'Off'; ?></span></label></div><?php $numberField('setting_response_max_words','Max Words Limit','Attempt to enforce a word limit for AI responses. Leave as 0 to have no limit.',$values['max_words'],0,10000); ?></div></section><section class="profile-settings-group"><h2 class="profile-settings-heading">Rechat</h2><div class="profile-toggle-grid"><?php $toggleCard('setting_behavior_rechat','&#x1F501;','Rechat','Continue eligible conversations after the spoken queue finishes.',$values['rechat']); ?></div>
<div class="rechat-calculator"><div class="rechat-calculator-title" tabindex="0" aria-describedby="rechat-calculator-help"><span aria-hidden="true">&#x1F501;</span><span>Rechat Response Calculator</span><span id="rechat-calculator-help" role="tooltip">Includes the initial reply and up to the configured number of NPC continuation rounds. Chances assume Rechat is enabled and a responder remains eligible.</span></div>
<div id="rechat-calc-output" aria-live="polite" aria-atomic="true"></div>
</div><div class="provider-card profile-settings-group-card">
<?php $numberField('setting_behavior_rechat_max_depth','Rechat Response Rounds','Rechat Responses. Higher values increase how many times AI NPCs continue a conversation. 1 = 1 continuation | 2 = 2 continuations | 3 = 3 continuations etc',$values['rechat_max_depth'],1,20);$numberField('setting_behavior_rechat_probability_percent','Rechat Probability','Rechat Probability. Chance that an AI NPC will continue an ongoing conversation. 0 = Never | 50 = 50% | 100 = Always',$values['rechat_probability_percent'],0,100); ?><div class="setting-row"><div><div class="setting-key"><span class="setting-icon" aria-hidden="true">&#x1F501;</span>Rechat Allow Actions <?php $copyButton('setting_behavior_rechat_allow_actions','Rechat Allow Actions'); ?></div><div class="setting-desc">Allow AI NPCs to trigger actions between each other during Rechat. This can cause some chaos...</div></div><label class="profile-inline-toggle"><input type="checkbox" name="setting_behavior_rechat_allow_actions" aria-label="Rechat Allow Actions" value="1"<?php echo $values['rechat_allow_actions']?' checked':''; ?>> <span class="toggle-text"><?php echo $values['rechat_allow_actions']?'On':'Off'; ?></span></label></div><?php  ?></div></section><section class="profile-settings-group"><h2 class="profile-settings-heading">Bored Event</h2><div class="provider-card profile-settings-group-card">
<?php $boredChance=(int)($effectiveCoreSettings['settings']['bored_event']['chance_percent']??$overrides['bored_event']['chance_percent']??50);
$numberField('setting_bored_event_chance_percent','Bored Event Chance','Bored Event Probability. Chance of an AI NPC starting a random conversation every couple of minutes. 0 = Never | 50 = 50% | 100 = Always.',$boredChance,0,100); ?>
</div></section><section class="profile-settings-group"><h2 class="profile-settings-heading">Context</h2><div class="provider-card profile-settings-group-card"><?php $toggleCard('setting_memory_long_term_enabled','&#x1F9E0;','Long Term Memory','Include this memory tier in dialogue context.',($overrides['memory']['long_term_enabled']??true)===true);$numberField('setting_memory_recent_turn_limit','Context History Event Count','Maximum recent conversation turns in context.',$values['recent_turn_limit'],1,100);$numberField('setting_diary_context_turn_limit','Context History Diary Event Count','Maximum witnessed turns in a diary. Set 0 to use regular Context History.',$values['diary_context_turn_limit'],0,400);$numberField('setting_profile_evolution_history_limit','Context History Dynamic Profile Event Count','Maximum recent completed turns used by automatic NPC and Narrator profile updates. Set 0 to use regular Context History.',(int)($evolution['history_limit']??50),0,400); ?></div></section><section class="profile-settings-group profile-diary-settings"><h2 class="profile-settings-heading">Diary</h2><div class="provider-card profile-settings-group-card"><?php $toggleCard('setting_diary_include_in_context','&#x1F4D6;','Diary In Context','Include saved diary narratives in roleplay context.',$values['diary_include_in_context']);$toggleCard('setting_diary_enabled','&#x1F4D3;','Diary Generation','Allow requested and automatic diary generation for this profile.',$values['diary_enabled']); ?><div class="setting-row profile-setting-stacked"><div><div class="setting-key"><span class="setting-icon" aria-hidden="true">&#x1F4D9;</span><label for="profile-diary-prompt">Diary Prompt</label> <?php $copyButton('setting_diary_prompt','Diary Prompt'); ?></div><div class="setting-desc">Instructions for generating diary entries for this profile.</div></div><div class="setting-control"><textarea id="profile-diary-prompt" name="setting_diary_prompt" rows="4" maxlength="8192" placeholder="Enter value"><?php echo lorkhan_ui_h($values['diary_prompt']); ?></textarea></div></div><?php $numberField('setting_diary_automatic_interval_seconds','Diary Cooldown','Cooldown in seconds between automatic diary entries for each NPC. Existing intervals above 1200 seconds are preserved.',$values['diary_automatic_interval_seconds'],10,max(1200,$values['diary_automatic_interval_seconds'])); ?></div></section>
<section class="profile-settings-group"><h2 class="profile-settings-heading">Combat</h2><div class="provider-card profile-settings-group-card">
<?php $combatCooldown=(int)($effectiveCoreSettings['settings']['behavior']['combat_bark_period_seconds']??$overrides['behavior']['combat_bark_period_seconds']??\LorkhanServer\Application\SettingsCatalog::clientDefaults()['behavior']['combat_bark_period_seconds']);
$numberField('setting_behavior_combat_bark_period_seconds','Combat Bark Cooldown','Cooldown period in seconds between combat barks to prevent spam during combat. This cooldown is global across all NPCs in the party.',$combatCooldown,min(10,$combatCooldown),600); ?>
</div></section><section class="profile-settings-group profile-quest-settings"><h2 class="profile-settings-heading">Quest</h2><div class="provider-card profile-settings-group-card">
<?php $questPolicy=$effectiveCoreSettings['settings']['quest_comments']??array_replace(['enabled'=>false,'chance_percent'=>10],$overrides['quest_comments']??[]); ?>
<input type="hidden" name="quest_comments_present" value="1">
<div class="setting-row"><div><div class="setting-key"><span class="setting-icon" aria-hidden="true">&#x1F9ED;</span><label for="setting_quest_comments_enabled">Quest Comment</label> <?php $copyButton('setting_quest_comments_enabled','Quest Comment'); ?></div><div class="setting-desc">Allow this NPC to comment on new journal objectives. Leave disabled while setting up a new character. Narrator quest events use Narrator settings.</div></div><div class="setting-control"><label class="profile-inline-toggle"><input id="setting_quest_comments_enabled" type="checkbox" name="setting_quest_comments_enabled" value="1"<?php echo $questPolicy['enabled']?' checked':''; ?>><span class="toggle-text"><?php echo $questPolicy['enabled']?'On':'Off'; ?></span></label></div></div>
<div class="setting-row"><div><div class="setting-key"><span class="setting-icon" aria-hidden="true">&#x1F9ED;</span><label for="setting_quest_comments_chance_percent">Quest Comment Chance</label> <?php $copyButton('setting_quest_comments_chance_percent','Quest Comment Chance'); ?></div><div class="setting-desc">Chance that an AI Quest Comment will happen every time a quest updates.</div></div><div class="setting-control"><select id="setting_quest_comments_chance_percent" name="setting_quest_comments_chance_percent" data-value-type="integer"><?php foreach([10,25,50,75,100] as $chance): ?><option value="<?php echo $chance; ?>"<?php echo $questPolicy['chance_percent']===$chance?' selected':''; ?>><?php echo $chance; ?>%</option><?php endforeach; ?></select></div></div>
</div></section></div></div>
<div class="profile-settings-footer"><button type="button" class="btn-primary" data-profile-back-top title="Scroll to top">Back to top</button></div>
</div>
<?php include __DIR__.'/core_global_overrides.php'; ?>
<details class="connector-card profile-metadata" id="metadata_section" data-profile-json-editor>
    <summary>Metadata (Advanced JSON)</summary>
    <p class="hint" id="core-metadata-help">Supported Core Profile setting overrides, including Oghma settings and memory.oghma_knowledge_tags. Visible controls take precedence when saving. Remove an advanced override to restore inheritance. Existing compatibility values may be retained or removed, but unsupported new overrides are rejected. Connector routing and profile identity use their separate controls.</p>
    <div data-json-editor-target></div>
    <label for="core-settings-overrides-json">Settings overrides</label>
    <textarea id="core-settings-overrides-json" data-json-editor-source name="core_settings_overrides_json" rows="16" spellcheck="false" aria-describedby="core-metadata-help"><?= lorkhan_ui_h(json_encode($overrides ?: new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
    <p role="status" data-json-editor-status></p>
    <?php if (!$creatingProfile): ?><div class="profile-revision-card"><label>Change reason<input name="change_reason" required maxlength="512" value="Management Core Profile update"></label></div><?php endif; ?>
</details>
