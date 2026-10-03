<?php
/** Explicit alternate references, kept beside the NPCs they standardize. */
if(!isset($productRepository,$installationOptions,$managementBasePath,$csrf))return;
// Guard codes come from a fixed server allowlist; unknown values fall back to generic text.
$referenceGroupError=match((string)($_GET['group_error']??'')){
    ''=>null,
    'name_already_in_group'=>'Another group already uses this NPC name rule.',
    'reference_already_in_group'=>'That reference already belongs to another enabled group. Remove it there first.',
    'reference_group_name_not_observed'=>'No actor with this exact name has been met in game yet. Talk to one first, or enter a canonical reference.',
    'reference_group_revision_conflict'=>'This group changed after the page loaded. Reload the page and try again.',
    'reference_group_keeper_unlink'=>'The canonical reference owns the shared profile and cannot be unlinked. Edit or delete the group instead.',
    'reference_group_member_not_found'=>'That reference is not a member of this group.',
    'reference_group_member_not_unlinked'=>'That reference is not unlinked from this group.',
    'reference_group_not_found'=>'This group no longer exists. Reload the page.',
    'invalid_actor_reference'=>'Use a reference such as morrowind.esm|258093.',
    default=>'Check the group name, NPC name and references. Up to 64 alternate references are allowed.',
};
?>
<div class="npc-modal-overlay" id="reference-groups" data-npc-modal hidden>
<section class="npc-modal" role="dialog" aria-modal="true" aria-labelledby="reference-groups-title">
    <header><h2 id="reference-groups-title">Reference Groups</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header>
    <div class="npc-modal-body">
    <?php if($referenceGroupError!==null): ?><p class="page-error" role="alert"><?= lorkhan_ui_h($referenceGroupError) ?></p><?php endif; ?>
    <p>Linked actors share one character: memories, diaries and relationships. Each event still records the actor who was there, and commands reach that actor.</p>
    <p>Unlink a member to return it to its own profile. It stays unlinked, even if met again, until you relink it.</p>
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
                    <?php if(!$isNew): ?><input type="hidden" name="revision" value="<?= (int)($referenceGroup['revision']??0) ?>"><?php endif; ?>
                    <label>Group name <input name="name" required maxlength="160" value="<?= lorkhan_ui_h($referenceGroup['name']) ?>" placeholder="Vivec"></label>
                    <label>Match NPC name <input name="match_name" maxlength="160" value="<?= lorkhan_ui_h($referenceGroup['match_name']??'') ?>" placeholder="Vivec"></label>
                    <p>Optional exact name, ignoring capitalization. Actors with this name join when met in game. Leave blank to match references only.</p>
                    <label>Canonical reference <input name="canonical_ref" maxlength="210" value="<?= lorkhan_ui_h($referenceGroup['canonical_ref']) ?>" placeholder="Automatic from the matched name"></label>
                    <p>Leave blank to use an actor with the matched name already met in game. Manual format: <code>morrowind.esm|258093</code>.</p>
                    <label>Alternate references (one per line)<textarea name="aliases" rows="3" maxlength="14000"><?= lorkhan_ui_h(implode("\n",$referenceGroup['aliases'])) ?></textarea></label>
                    <label><input name="enabled" type="checkbox" value="1" <?= $referenceGroup['enabled']?'checked':'' ?>> Enabled</label>
                    <button type="submit" class="btn-save">Save Group</button>
                </form>
                <?php if(!$isNew):
                    $unlinkedReferences=$referenceGroup['unlinked']??[];
                    $memberReferences=[];
                    foreach($referenceGroup['aliases'] as $memberReference)$memberReferences[$memberReference]='Alternate reference';
                    foreach($referenceGroup['observed']??[] as $memberReference)$memberReferences[$memberReference]??='Name match';
                    unset($memberReferences[$referenceGroup['canonical_ref']]);
                ?>
                <div class="npc-reference-members">
                    <h4>Members</h4>
                    <ul>
                        <li><code><?= lorkhan_ui_h($referenceGroup['canonical_ref']) ?></code> <span>Canonical</span></li>
                        <?php foreach($memberReferences as $memberReference=>$memberSource): if(in_array($memberReference,$unlinkedReferences,true))continue; ?>
                        <li><code><?= lorkhan_ui_h($memberReference) ?></code> <span><?= lorkhan_ui_h($memberSource) ?></span>
                            <form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/reference-group-unlink') ?>" data-confirm="Unlink this actor? It returns to its own profile and stays unlinked until you relink it.">
                                <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                                <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($groupInstallation) ?>">
                                <input type="hidden" name="group_key" value="<?= lorkhan_ui_h($referenceGroup['group_key']) ?>">
                                <input type="hidden" name="member_ref" value="<?= lorkhan_ui_h($memberReference) ?>">
                                <button type="submit" class="btn-base" aria-label="<?= lorkhan_ui_h('Unlink '.$memberReference) ?>">Unlink</button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if($unlinkedReferences!==[]): ?>
                    <h4>Unlinked</h4>
                    <ul>
                        <?php foreach($unlinkedReferences as $memberReference): ?>
                        <li><code><?= lorkhan_ui_h($memberReference) ?></code> <span>Own profile</span>
                            <form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/reference-group-relink') ?>">
                                <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                                <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($groupInstallation) ?>">
                                <input type="hidden" name="group_key" value="<?= lorkhan_ui_h($referenceGroup['group_key']) ?>">
                                <input type="hidden" name="member_ref" value="<?= lorkhan_ui_h($memberReference) ?>">
                                <button type="submit" class="btn-base" aria-label="<?= lorkhan_ui_h('Relink '.$memberReference) ?>">Relink</button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/reference-group-delete') ?>" data-confirm="Remove this group? Each member returns to its own profile. Shared history stays with the canonical profile.">
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
