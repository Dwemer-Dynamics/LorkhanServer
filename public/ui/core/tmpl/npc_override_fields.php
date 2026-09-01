<?php
declare(strict_types=1);
$routing=is_array($content['routing']??null)?$content['routing']:[];$overrides=is_array($content['settings_overrides']??null)?$content['settings_overrides']:[];
$npcRoute=static function(string$name,string$label,array$rows)use($routing):void{$present=array_key_exists($name,$routing);$current=$routing[$name]??null; ?>
    <label><?php echo lorkhan_ui_h($label); ?><select name="<?php echo lorkhan_ui_h($name); ?>"><option value=""<?php echo !$present?' selected':''; ?>>Inherit Core Profile</option><option value="__disabled__"<?php echo $present&&$current===''?' selected':''; ?>>Disabled for this NPC</option><?php foreach($rows as$row):$id=(string)$row['configuration_id']; ?><option value="<?php echo lorkhan_ui_h($id); ?>"<?php echo $current===$id?' selected':''; ?>><?php echo lorkhan_ui_h($row['name']); ?></option><?php endforeach; ?></select></label>
<?php };
$npcBool=static function(string$name,string$label,mixed$value):void{ ?>
    <label><?php echo lorkhan_ui_h($label); ?><select name="<?php echo lorkhan_ui_h($name); ?>"><option value="inherit"<?php echo $value===null?' selected':''; ?>>Inherit</option><option value="1"<?php echo $value===true?' selected':''; ?>>Enabled</option><option value="0"<?php echo $value===false?' selected':''; ?>>Disabled</option></select></label>
<?php };
$npcNumber=static function(string$section,string$field,string$label,int$min,int$max)use($overrides):void{$value=$overrides[$section][$field]??''; ?>
    <label><?php echo lorkhan_ui_h($label); ?><input type="number" min="<?php echo $min; ?>" max="<?php echo $max; ?>" name="setting_<?php echo lorkhan_ui_h($section.'_'.$field); ?>" value="<?php echo lorkhan_ui_h($value); ?>" placeholder="Inherit"></label>
<?php };
$npcMode=static function(mixed$value):void{ ?>
    <label>Rechat mode<select name="setting_behavior_rechat_mode"><option value="">Inherit Core Profile</option><?php foreach(['tight'=>'Tight','conversational'=>'Conversational','group'=>'Group','random'=>'Random']as$key=>$label): ?><option value="<?php echo $key; ?>"<?php echo $value===$key?' selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
<?php };
?>
<fieldset class="lorkhan-group"><legend>NPC connector overrides</legend><div class="lorkhan-form-grid">
    <?php $npcRoute('prompt_configuration_id','Dialogue prompt',$prompts);$npcRoute('oghma_configuration_id','Oghma extractor',$llm); ?>
</div></fieldset>
<fieldset class="lorkhan-group"><legend>NPC behavior overrides</legend><div class="lorkhan-form-grid">
    <?php $npcBool('setting_behavior_rechat','Rechat',$overrides['behavior']['rechat']??null); ?>
    <?php $npcNumber('behavior','rechat_max_depth','Rechat rounds',1,20);$npcNumber('behavior','rechat_probability_percent','Rechat probability',0,100); ?>
    <?php $npcMode($overrides['behavior']['rechat_mode']??'');$npcBool('setting_behavior_rechat_strict_targeting','Strict targeting',$overrides['behavior']['rechat_strict_targeting']??null);$npcBool('setting_behavior_open_rechat','Open rechat',$overrides['behavior']['open_rechat']??null);$npcNumber('behavior','end_conversation_cooldown_seconds','End cooldown',0,300); ?>
    <?php $npcNumber('memory','recent_turn_limit','Recent turns',1,100); ?>
    <label>Oghma knowledge tags<input name="setting_memory_oghma_knowledge_tags" maxlength="4096" value="<?php echo lorkhan_ui_h($overrides['memory']['oghma_knowledge_tags']??''); ?>" placeholder="Inherit Core Profile"></label>
    <?php $npcBool('setting_oghma_enabled','Oghma enabled',$overrides['oghma']['enabled']??null);$npcNumber('oghma','topic_count','Oghma topics',1,3);$npcNumber('oghma','result_limit','Oghma results',1,5); ?>
    <?php $npcBool('setting_oghma_racial_context_enabled','Oghma racial context',$overrides['oghma']['racial_context_enabled']??null);$npcBool('setting_oghma_location_context_enabled','Oghma location context',$overrides['oghma']['location_context_enabled']??null); ?>
    <?php $npcBool('setting_oghma_extractor_fallback_enabled','Oghma extractor fallback',$overrides['oghma']['extractor_fallback_enabled']??null);$npcNumber('oghma','extractor_timeout_ms','Oghma extractor timeout',250,3000); ?>
</div></fieldset>
