<?php
// Herika NPC Info placement and editing semantics, scoped to the OpenMW playthrough.
if($creating)return;
$digestPlaythroughs=is_array($playthroughOptions[$installationId]??null)?$playthroughOptions[$installationId]:[];
$digestSelected=$relPlaythrough??(string)(array_key_first($digestPlaythroughs)??'');
?>
<div class="form-item span-2" data-npc-memory-editor>
<?php if(count($digestPlaythroughs)>1): ?>
<label for="<?= lorkhan_ui_h($formId) ?>-memory-playthrough">Memory playthrough</label>
<select id="<?= lorkhan_ui_h($formId) ?>-memory-playthrough" data-memory-playthrough>
<?php foreach($digestPlaythroughs as $digestPlaythrough=>$digestLabel): ?>
<option value="<?= lorkhan_ui_h($digestPlaythrough) ?>"<?= $digestPlaythrough===$digestSelected?' selected':'' ?>><?= lorkhan_ui_h($digestLabel) ?></option>
<?php endforeach; ?>
</select>
<?php endif; ?>
<?php foreach($digestPlaythroughs as $digestPlaythrough=>$digestLabel):
$digestEditor=(new \LorkhanServer\Infrastructure\NpcMemoryDigestRepository($GLOBALS['database']))->editor($installationId,$digestPlaythrough,$profileId);
$digestId=$formId.'-middle-term-'.$digestPlaythrough;
?>
<div class="form-item" data-memory-panel="<?= lorkhan_ui_h($digestPlaythrough) ?>"<?= $digestPlaythrough!==$digestSelected?' hidden':'' ?>>
<label for="<?= lorkhan_ui_h($digestId) ?>">Recent Middle Term Memory</label>
<textarea id="<?= lorkhan_ui_h($digestId) ?>" form="<?= lorkhan_ui_h($formId) ?>" name="npc_memory_edits[<?= lorkhan_ui_h($digestPlaythrough) ?>][content]" data-memory-content placeholder="No middle term memory yet."><?= lorkhan_ui_h($digestEditor['content']) ?></textarea>
<input type="hidden" form="<?= lorkhan_ui_h($formId) ?>" name="npc_memory_edits[<?= lorkhan_ui_h($digestPlaythrough) ?>][revision]" value="<?= $digestEditor['revision'] ?>" data-memory-revision>
</div>
<?php endforeach; ?>
<?php if($digestPlaythroughs===[]): ?><label>Recent Middle Term Memory</label><p class="hint">No playthrough is available yet.</p><?php endif; ?>
<small class="hint">Manual edits save to the latest middle-term memory entry. Future auto-generated summaries continue appending after your edit.</small>
</div>
