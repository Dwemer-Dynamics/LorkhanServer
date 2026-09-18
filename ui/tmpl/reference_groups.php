<?php
/** Explicit alternate references, kept beside the NPCs they standardize. */
if(!isset($productRepository,$installationOptions,$managementBasePath,$csrf))return;
?>
<details class="npc-observed-picker" id="reference-groups" <?= ($_GET['tab']??'')==='reference-groups'?'open':'' ?>>
    <summary>Reference Groups</summary>
    <p>List alternate references for the same character. Other NPCs keep separate profiles, even when their names match.</p>
    <p>Reference format: <code>content filename|local reference number</code> (decimal). Changes apply to future conversations; existing histories are not merged.</p>
    <?php foreach($installationOptions as $groupInstallation=>$groupInstallationLabel): ?>
        <?php if(count($installationOptions)>1): ?><h3><?= lorkhan_ui_h($groupInstallationLabel) ?></h3><?php endif; ?>
        <?php $referenceGroups=$productRepository->referenceGroups($groupInstallation);$referenceGroups[]=['group_key'=>'','name'=>'','canonical_ref'=>'','aliases'=>[],'enabled'=>true]; ?>
        <?php foreach($referenceGroups as $referenceGroup): $isNew=$referenceGroup['group_key']===''; ?>
            <details class="management-section">
                <summary><?= $isNew?'Add Reference Group':lorkhan_ui_h($referenceGroup['name']).($referenceGroup['enabled']?'':' (disabled)') ?></summary>
                <form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/reference-group-save') ?>" class="management-form npc-reference-form">
                    <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                    <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($groupInstallation) ?>">
                    <label>Group key <input name="group_key" required pattern="[a-z0-9][a-z0-9_-]{0,79}" maxlength="80" value="<?= lorkhan_ui_h($referenceGroup['group_key']) ?>" <?= $isNew?'':'readonly' ?> placeholder="dagoth-ur"></label>
                    <label>Name <input name="name" required maxlength="160" value="<?= lorkhan_ui_h($referenceGroup['name']) ?>"></label>
                    <label>Canonical reference <input name="canonical_ref" required maxlength="210" value="<?= lorkhan_ui_h($referenceGroup['canonical_ref']) ?>" placeholder="morrowind.esm|258093"></label>
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
</details>
