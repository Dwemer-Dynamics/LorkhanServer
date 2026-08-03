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
?>
<input type="hidden" name="llm_routing_fields" value="1">
<fieldset class="almsivi-group"><legend>NPC connector overrides</legend><div class="almsivi-form-grid">
    <?php $npcRoute('prompt_configuration_id','Dialogue prompt',$prompts);$npcRoute('llm_configuration_id','Standard LLM',$llm);$npcRoute('llm_fast_configuration_id','Fast LLM',$llm);$npcRoute('llm_powerful_configuration_id','Powerful LLM',$llm);$npcRoute('llm_experimental_configuration_id','Experimental LLM',$llm);$npcRoute('llm_fallback_configuration_id','Fallback LLM',$llm);$npcRoute('tts_configuration_id','TTS connector',$tts); ?>
    <?php $npcBool('llm_randomizer_enabled','LLM randomizer',$routing['llm_randomizer_enabled']??null);$npcBool('llm_fallback_enabled','Fallback retry',$routing['llm_fallback_enabled']??null); ?>
</div></fieldset>
<fieldset class="almsivi-group"><legend>NPC behavior overrides</legend><div class="almsivi-form-grid">
    <?php foreach(['auto_greeting'=>'Automatic greeting','rechat'=>'Rechat','boredom'=>'Boredom events','combat_barks'=>'Combat barks']as$field=>$label)$npcBool('setting_behavior_'.$field,$label,$overrides['behavior'][$field]??null); ?>
    <?php $npcNumber('behavior','rechat_delay_seconds','Rechat delay',30,3600);$npcNumber('behavior','rechat_max_depth','Rechat depth',1,20);$npcNumber('behavior','boredom_delay_seconds','Boredom delay',30,86400);$npcNumber('behavior','combat_bark_period_seconds','Combat bark period',5,300); ?>
    <?php $npcNumber('memory','recent_turn_limit','Recent turns',1,100);$npcNumber('memory','knowledge_limit','Knowledge results',0,20); ?>
    <?php $npcBool('setting_presentation_show_status_hud','Show status HUD',$overrides['presentation']['show_status_hud']??null);$npcNumber('presentation','transcript_rows','Transcript rows',2,20);$npcNumber('presentation','tts_volume_boost','TTS volume boost',1,4); ?>
    <?php foreach(['actions_enabled'=>'Negotiated actions','allow_hostile'=>'Hostile targets','allow_creatures'=>'Creature targets']as$field=>$label)$npcBool('setting_safety_'.$field,$label,$overrides['safety'][$field]??null); ?>
</div></fieldset>
