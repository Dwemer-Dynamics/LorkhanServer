<?php
declare(strict_types=1);
// Disclosure order and compact value panels derive from Herika's NPC Info tab.
$observation=is_array($row['observed_state']??null)?$row['observed_state']:[];
$state=$observation['state']??[];
$skillLabels=['mediumarmor'=>'Medium Armor','heavyarmor'=>'Heavy Armor','bluntweapon'=>'Blunt Weapon','longblade'=>'Long Blade',
    'lightarmor'=>'Light Armor','shortblade'=>'Short Blade','handtohand'=>'Hand-to-Hand'];
?>
<div class="form-item span-2 npc-observation-note">
<?php if($observation!==[]): ?>
    <small class="hint">Last recorded target observation: <?= lorkhan_ui_h($observation['observed_at']) ?> · Playthrough: <?= lorkhan_ui_h($observation['playthrough_name']) ?>. These are recorded values, not live game state.</small>
<?php else: ?><small class="hint">No target-state observation has been recorded for this exact NPC reference.</small><?php endif; ?>
</div>
<div class="form-item span-2 npc-observed-state"><details><summary>Skills</summary><div class="npc-observed-body">
<?php if(($state['skills']??[])===[]): ?><p>No in-game skills found in metadata.</p>
<?php else: ?><div class="npc-observed-grid"><?php foreach($state['skills'] as$key=>$values): ?>
    <div class="npc-observed-value"><span><?= lorkhan_ui_h($skillLabels[$key]??ucfirst($key)) ?></span><strong title="<?= lorkhan_ui_h('Recorded values: '.json_encode($values)) ?>"><?= lorkhan_ui_h((string)($values['modified']??$values['base']??'—')) ?></strong></div>
<?php endforeach; ?></div><small class="hint">Modified value when recorded, otherwise base. Hover a value for its recorded components.</small><?php endif; ?>
</div></details></div>
<div class="form-item span-2 npc-observed-state"><details><summary>Current Equipment</summary><div class="npc-observed-body">
    <small class="hint">Equipment from the recorded target observation.</small>
<?php if(($state['equipment']??[])===[]): ?><p>No equipment data found in metadata.</p>
<?php else: ?><div class="npc-observed-grid"><?php foreach($state['equipment'] as$item): ?>
    <div class="npc-observed-value"><span><?= lorkhan_ui_h(ucwords(str_replace('_',' ',$item['slot']??'Recorded slot'))) ?></span><strong><?= lorkhan_ui_h($item['display_name']??$item['record_id']??'—') ?></strong></div>
<?php endforeach; ?></div><?php endif; ?>
</div></details></div>
<div class="form-item span-2 npc-observed-state"><details><summary>Character Stats</summary><div class="npc-observed-body">
<?php if(($state['stats']??[])===[]&&($state['attributes']??[])===[]): ?><p>No stats data found in metadata.</p>
<?php else: ?><div class="npc-observed-grid">
<?php foreach(['level'=>'Level','encumbrance'=>'Encumbrance','capacity'=>'Capacity','dead'=>'Dead']as$key=>$label): if(!array_key_exists($key,$state['stats']??[]))continue; ?>
    <div class="npc-observed-value"><span><?= $label ?></span><strong><?= lorkhan_ui_h(is_bool($state['stats'][$key])?($state['stats'][$key]?'Yes':'No'):(string)$state['stats'][$key]) ?></strong></div>
<?php endforeach; ?>
<?php foreach(['health'=>'❤️ Health','magicka'=>'💧 Magicka','fatigue'=>'⚡ Fatigue']as$key=>$label): if(!isset($state['stats'][$key]))continue;$stat=$state['stats'][$key]; ?>
    <div class="npc-observed-value"><span><?= $label ?></span><strong><?= lorkhan_ui_h((string)($stat['current']??'—').' / '.(string)($stat['base']??'—')) ?><small> Current / Base</small></strong></div>
<?php endforeach; ?>
<?php foreach($state['attributes']??[]as$key=>$stat): ?>
    <div class="npc-observed-value"><span><?= lorkhan_ui_h(ucfirst($key)) ?></span><strong><?= lorkhan_ui_h((string)($stat['modified']??$stat['base']??'—')) ?></strong></div>
<?php endforeach; ?></div><?php endif; ?>
</div></details></div>
<div class="form-item span-2 npc-observed-state"><details><summary>Inventory</summary><div class="npc-observed-body">
<?php if(!array_key_exists('inventory',$state)): ?><p>No NPC inventory was recorded in this observation. Player inventory is not shown here.</p>
<?php elseif($state['inventory']===[]): ?><p>No inventory items found in metadata.</p>
<?php else: ?><div class="npc-observed-table"><table><thead><tr><th>Item</th><th>Count</th></tr></thead><tbody>
<?php foreach($state['inventory']as$item): ?><tr><td><?= lorkhan_ui_h($item['display_name']??$item['record_id']??'—') ?></td><td><?= lorkhan_ui_h((string)($item['count']??'—')) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</div></details></div>
<div class="form-item span-2 npc-observed-state"><details><summary>Spells</summary><div class="npc-observed-body">
<?php if(($state['spells']??[])===[]): ?><p>No spell data found in metadata.</p>
<?php else: ?><div class="npc-observed-table"><table><thead><tr><th>Spell</th><th>Record ID</th></tr></thead><tbody>
<?php foreach($state['spells']as$item): ?><tr><td><?= lorkhan_ui_h($item['name']??$item['display_name']??$item['id']??$item['record_id']??'—') ?></td><td><?= lorkhan_ui_h($item['id']??$item['record_id']??'—') ?></td></tr><?php endforeach; ?>
</tbody></table></div><small class="hint">Recorded spells: <?= count($state['spells']) ?>. Up to 128 observed rows are displayed.</small><?php endif; ?>
</div></details></div>
<div class="form-item span-2 npc-observed-state"><details><summary>Metadata (JSON)</summary><div class="npc-observed-body">
    <small class="hint">Read-only projection of the recorded actor state. Raw turn context and private fields are excluded.</small>
    <pre><?= lorkhan_ui_h(json_encode($observation?:new stdClass(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?></pre>
</div></details></div>
