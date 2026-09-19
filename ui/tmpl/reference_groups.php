<?php
/** Explicit alternate references, kept beside the NPCs they standardize. */
if(!isset($productRepository,$installationOptions,$managementBasePath,$csrf))return;
?>
<div class="npc-modal-overlay" id="reference-groups" data-npc-modal hidden>
<section class="npc-modal" role="dialog" aria-modal="true" aria-labelledby="reference-groups-title">
    <header><h2 id="reference-groups-title">Reference Groups</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header>
    <div class="npc-modal-body">
    <p>Share a profile between selected references or actors with an exact name. Other actors keep separate profiles.</p>
    <p>Changes apply to future conversations. Existing histories are not merged.</p>
    <?php foreach($installationOptions as $groupInstallation=>$groupInstallationLabel): ?>
        <?php if(count($installationOptions)>1): ?><h3><?= lorkhan_ui_h($groupInstallationLabel) ?></h3><?php endif; ?>
        <?php $referenceGroups=$productRepository->referenceGroups($groupInstallation);$referenceGroups[]=['group_key'=>'','name'=>'','canonical_ref'=>'','aliases'=>[],'enabled'=>true]; ?>
        <?php foreach($referenceGroups as $referenceGroup): $isNew=$referenceGroup['group_key']===''; ?>
            <details class="management-section">
                <summary><?= $isNew?'Add Reference Group':lorkhan_ui_h($referenceGroup['name']).($referenceGroup['enabled']?'':' (disabled)') ?></summary>
                <form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/reference-group-save') ?>" class="management-form npc-reference-form">
                    <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                    <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($groupInstallation) ?>">
                    <input type="hidden" name="group_key" value="<?= lorkhan_ui_h($referenceGroup['group_key']) ?>">
                    <label>Group name <input name="name" required maxlength="160" value="<?= lorkhan_ui_h($referenceGroup['name']) ?>" placeholder="Vivec"></label>
                    <label>Match NPC name <input name="match_name" maxlength="160" value="<?= lorkhan_ui_h($referenceGroup['match_name']??'') ?>" placeholder="Vivec"></label>
                    <p>Optional exact name, ignoring capitalization. All actors with this name will share the group’s profile. Leave blank to match references only.</p>
                    <label>Canonical reference <input name="canonical_ref" maxlength="210" value="<?= lorkhan_ui_h($referenceGroup['canonical_ref']) ?>" placeholder="Automatic from the matched name"></label>
                    <p>Leave blank to use an existing actor with the matched name. Manual format: <code>morrowind.esm|258093</code>.</p>
                    <label>Alternate references (one per line)<textarea name="aliases" rows="3" maxlength="14000"><?= lorkhan_ui_h(implode("\n",$referenceGroup['aliases'])) ?></textarea></label>
                    <label><input name="enabled" type="checkbox" value="1" <?= $referenceGroup['enabled']?'checked':'' ?>> Enabled</label>
                    <button type="submit" class="btn-save">Save Group</button>
                </form>
                <?php if(!$isNew): ?>
                <form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/reference-group-delete') ?>" data-confirm="Remove this group? Future conversations will use separate reference profiles. Existing profiles will remain.">
                    <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                    <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($groupInstallation) ?>">
                    <input type="hidden" name="group_key" value="<?= lorkhan_ui_h($referenceGroup['group_key']) ?>">
                    <button type="submit" class="btn-base btn-danger">Delete Group</button>
                </form>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </div>
</section>
</div>
