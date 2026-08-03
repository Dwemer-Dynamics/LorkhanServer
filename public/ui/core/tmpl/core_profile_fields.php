<?php
declare(strict_types=1);
$routing=is_array($content['routing']??null)?$content['routing']:[];
$overrides=is_array($content['settings_overrides']??null)?$content['settings_overrides']:[];
$routeSelect=static function(string$name,string$label,array$rows)use($routing):void{ ?>
    <label><?php echo almsivi_ui_h($label); ?><select name="<?php echo almsivi_ui_h($name); ?>"><option value="">None / inherit</option><?php foreach($rows as$row): $id=(string)$row['configuration_id']; ?><option value="<?php echo almsivi_ui_h($id); ?>"<?php echo ($routing[$name]??null)===$id?' selected':''; ?>><?php echo almsivi_ui_h($row['name']); ?></option><?php endforeach; ?></select></label>
<?php };
$inheritBool=static function(string$name,string$label,mixed$value):void{ ?>
    <label><?php echo almsivi_ui_h($label); ?><select name="<?php echo almsivi_ui_h($name); ?>"><option value="inherit"<?php echo $value===null?' selected':''; ?>>Inherit</option><option value="1"<?php echo $value===true?' selected':''; ?>>Enabled</option><option value="0"<?php echo $value===false?' selected':''; ?>>Disabled</option></select></label>
<?php };
$numberOverride=static function(string$section,string$field,string$label,int$min,int$max)use($overrides):void{$value=$overrides[$section][$field]??''; ?>
    <label><?php echo almsivi_ui_h($label); ?><input type="number" min="<?php echo $min; ?>" max="<?php echo $max; ?>" name="setting_<?php echo almsivi_ui_h($section.'_'.$field); ?>" value="<?php echo almsivi_ui_h($value); ?>" placeholder="Inherit"></label>
<?php };
?>
<div class="almsivi-form-grid">
    <label class="wide">Core roleplay instruction<textarea name="prompt" maxlength="65536" placeholder="Shared instruction for every NPC using this Core Profile."><?php echo almsivi_ui_h($content['prompt']??''); ?></textarea></label>
    <fieldset class="almsivi-group wide"><legend>Prompt and model routing</legend><div class="almsivi-form-grid">
        <?php $routeSelect('prompt_configuration_id','Dialogue prompt',$prompts); ?>
        <?php $routeSelect('llm_configuration_id','Standard LLM',$llm); ?>
        <?php $routeSelect('llm_fast_configuration_id','Fast LLM',$llm); ?>
        <?php $routeSelect('llm_powerful_configuration_id','Powerful LLM',$llm); ?>
        <?php $routeSelect('llm_experimental_configuration_id','Experimental LLM',$llm); ?>
        <?php $routeSelect('llm_fallback_configuration_id','Fallback LLM',$llm); ?>
        <?php $routeSelect('tts_configuration_id','TTS connector',$tts); ?>
        <?php $inheritBool('llm_randomizer_enabled','LLM randomizer',$routing['llm_randomizer_enabled']??null); ?>
        <?php $inheritBool('llm_fallback_enabled','Fallback retry',$routing['llm_fallback_enabled']??null); ?>
    </div></fieldset>
    <fieldset class="almsivi-group"><legend>Conversation overrides</legend>
        <?php foreach(['auto_greeting'=>'Automatic greeting','rechat'=>'Rechat','boredom'=>'Boredom events','combat_barks'=>'Combat barks']as$field=>$label)$inheritBool('setting_behavior_'.$field,$label,$overrides['behavior'][$field]??null); ?>
        <?php $numberOverride('behavior','rechat_delay_seconds','Rechat delay',30,3600); ?>
        <?php $numberOverride('behavior','rechat_max_depth','Rechat depth',1,20); ?>
        <?php $numberOverride('behavior','boredom_delay_seconds','Boredom delay',30,86400); ?>
        <?php $numberOverride('behavior','combat_bark_period_seconds','Combat bark period',5,300); ?>
    </fieldset>
    <fieldset class="almsivi-group"><legend>Memory and presentation overrides</legend>
        <?php $numberOverride('memory','recent_turn_limit','Recent turns',1,100); ?>
        <?php $numberOverride('memory','knowledge_limit','Knowledge results',0,20); ?>
        <?php $inheritBool('setting_presentation_show_status_hud','Show status HUD',$overrides['presentation']['show_status_hud']??null); ?>
        <?php $numberOverride('presentation','transcript_rows','Transcript rows',2,20); ?>
        <?php $numberOverride('presentation','tts_volume_boost','TTS volume boost',1,4); ?>
    </fieldset>
    <fieldset class="almsivi-group"><legend>Narrator overrides</legend>
        <?php foreach(['enabled'=>'Enable narrator','context_visibility'=>'Narrator context','welcome_events'=>'Welcome events','random_events'=>'Random events','quest_events'=>'Quest events','book_events'=>'Book events']as$field=>$label)$inheritBool('setting_narrator_'.$field,$label,$overrides['narrator'][$field]??null); ?>
        <label>Narrator name<input name="setting_narrator_name" maxlength="128" value="<?php echo almsivi_ui_h($overrides['narrator']['name']??''); ?>" placeholder="Inherit"></label>
        <label>Inline mode<select name="setting_narrator_inline_mode"><option value="">Inherit</option><?php foreach(['Disabled','Narrator','NPC','Text Only']as$mode): ?><option<?php echo ($overrides['narrator']['inline_mode']??null)===$mode?' selected':''; ?>><?php echo almsivi_ui_h($mode); ?></option><?php endforeach; ?></select></label>
    </fieldset>
    <fieldset class="almsivi-group"><legend>Safety overrides</legend>
        <?php foreach(['actions_enabled'=>'Negotiated actions','allow_hostile'=>'Hostile NPC targets','allow_creatures'=>'Creature targets']as$field=>$label)$inheritBool('setting_safety_'.$field,$label,$overrides['safety'][$field]??null); ?>
    </fieldset>
</div>
