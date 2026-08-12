<?php
declare(strict_types=1);
$routing=is_array($content['routing']??null)?$content['routing']:[];$overrides=is_array($content['settings_overrides']??null)?$content['settings_overrides']:[];
$npcRoute=static function(string$name,string$label,array$rows)use($routing):void{$present=array_key_exists($name,$routing);$current=$routing[$name]??null; ?>
    <label><?php echo almsivi_ui_h($label); ?><select name="<?php echo almsivi_ui_h($name); ?>"><option value=""<?php echo !$present?' selected':''; ?>>Inherit Core Profile</option><option value="__disabled__"<?php echo $present&&$current===''?' selected':''; ?>>Disabled for this NPC</option><?php foreach($rows as$row):$id=(string)$row['configuration_id']; ?><option value="<?php echo almsivi_ui_h($id); ?>"<?php echo $current===$id?' selected':''; ?>><?php echo almsivi_ui_h($row['name']); ?></option><?php endforeach; ?></select></label>
<?php };
$npcBool=static function(string$name,string$label,mixed$value):void{ ?>
    <label><?php echo almsivi_ui_h($label); ?><select name="<?php echo almsivi_ui_h($name); ?>"><option value="inherit"<?php echo $value===null?' selected':''; ?>>Inherit</option><option value="1"<?php echo $value===true?' selected':''; ?>>Enabled</option><option value="0"<?php echo $value===false?' selected':''; ?>>Disabled</option></select></label>
<?php };
$npcNumber=static function(string$section,string$field,string$label,int$min,int$max)use($overrides):void{$value=$overrides[$section][$field]??''; ?>
    <label><?php echo almsivi_ui_h($label); ?><input type="number" min="<?php echo $min; ?>" max="<?php echo $max; ?>" name="setting_<?php echo almsivi_ui_h($section.'_'.$field); ?>" value="<?php echo almsivi_ui_h($value); ?>" placeholder="Inherit"></label>
<?php };
$npcDisabledBool=static function(string$label,mixed$value,string$featureId):void{$display=$value===true?'Enabled':($value===false?'Disabled':'Inherit'); ?>
    <label class="feature-placeholder-card"><?php echo almsivi_ui_h($label); ?> <?php echo almsivi_ui_feature_badge($featureId,true); ?><select disabled aria-disabled="true"><option><?php echo almsivi_ui_h($display); ?></option></select></label>
<?php };
$npcDisabledNumber=static function(string$section,string$field,string$label,string$featureId)use($overrides):void{$value=$overrides[$section][$field]??''; ?>
    <label class="feature-placeholder-card"><?php echo almsivi_ui_h($label); ?> <?php echo almsivi_ui_feature_badge($featureId,true); ?><input type="text" value="<?php echo almsivi_ui_h($value===''?'Inherit':$value); ?>" disabled aria-disabled="true"></label>
<?php };
?>
<input type="hidden" name="llm_routing_fields" value="1">
<fieldset class="almsivi-group"><legend>NPC connector overrides</legend><div class="almsivi-form-grid">
    <?php $npcRoute('prompt_configuration_id','Dialogue prompt',$prompts);$npcRoute('llm_configuration_id','Standard LLM',$llm);$npcRoute('llm_fast_configuration_id','Fast LLM',$llm);$npcRoute('llm_powerful_configuration_id','Powerful LLM',$llm);$npcRoute('llm_experimental_configuration_id','Experimental LLM',$llm);$npcRoute('llm_fallback_configuration_id','Fallback LLM',$llm);$npcRoute('tts_configuration_id','TTS connector',$tts); ?>
    <label>Diary LLM <?php echo almsivi_ui_feature_badge('config.profiles.diary-llm',true); ?><select disabled aria-disabled="true"><option>Active narrative pipeline</option></select></label>
    <label>Formatter LLM <?php echo almsivi_ui_feature_badge('config.profiles.formatter-llm',true); ?><select disabled aria-disabled="true"><option>Not configured</option></select></label>
    <?php $npcBool('llm_randomizer_enabled','LLM randomizer',$routing['llm_randomizer_enabled']??null);$npcBool('llm_fallback_enabled','Fallback retry',$routing['llm_fallback_enabled']??null); ?>
</div></fieldset>
<fieldset class="almsivi-group"><legend>NPC behavior overrides</legend><div class="almsivi-form-grid">
    <?php $npcDisabledBool('Automatic greeting',$overrides['behavior']['auto_greeting']??null,'autonomy');$npcBool('setting_behavior_rechat','Rechat',$overrides['behavior']['rechat']??null);$npcDisabledBool('Boredom events',$overrides['behavior']['boredom']??null,'autonomy');$npcDisabledBool('Combat barks',$overrides['behavior']['combat_barks']??null,'autonomy'); ?>
    <?php $npcDisabledNumber('behavior','rechat_delay_seconds','Rechat delay','autonomy');$npcNumber('behavior','rechat_max_depth','Rechat rounds',1,20);$npcNumber('behavior','rechat_probability_percent','Rechat probability',0,100);$npcDisabledNumber('behavior','boredom_delay_seconds','Boredom delay','autonomy');$npcDisabledNumber('behavior','combat_bark_period_seconds','Combat bark period','autonomy'); ?>
    <?php $npcNumber('memory','recent_turn_limit','Recent turns',1,100);$npcNumber('memory','knowledge_limit','Knowledge results',0,20); ?>
    <label>Oghma knowledge tags<input name="setting_memory_oghma_knowledge_tags" maxlength="4096" value="<?php echo almsivi_ui_h($overrides['memory']['oghma_knowledge_tags']??''); ?>" placeholder="Inherit Core Profile"></label>
    <?php $npcDisabledBool('Show status HUD',$overrides['presentation']['show_status_hud']??null,'presentation.local');$npcDisabledNumber('presentation','transcript_rows','Transcript rows','presentation.local');$npcDisabledNumber('presentation','tts_volume_boost','TTS volume boost','presentation.local'); ?>
    <?php foreach(['actions_enabled'=>'Negotiated actions','allow_hostile'=>'Hostile targets','allow_creatures'=>'Creature targets']as$field=>$label)$npcBool('setting_safety_'.$field,$label,$overrides['safety'][$field]??null); ?>
</div></fieldset>
