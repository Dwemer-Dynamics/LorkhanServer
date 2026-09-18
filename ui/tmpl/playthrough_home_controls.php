<?php
// CHIM's Home toolbar, using Lorkhan's saved-character association instead of replacing a live database.
$homeManagerUrl = $webRoot.'/ui/playthrough_manager.php?'.http_build_query(['installation_id'=>$installation]);
$homeCurrentName = 'No saved character connected';
$homeTargets = [];
$homeProtected = array_column($homeCharacterState['bindings'], 'playthrough_id');
foreach ($homeCharacterState['pending_associations'] ?? [] as $pending) {
    $homeProtected[] = $pending['from_playthrough_id'];
    $homeProtected[] = $pending['to_playthrough_id'];
}
foreach ($homePlaythroughs as $world) {
    if ($world['playthrough_id'] === ($dashboard['current']['playthrough_id'] ?? null)) $homeCurrentName = $world['playthrough'];
    if (!in_array($world['playthrough_id'], $homeProtected, true) && $world['playthrough_id'] !== ($dashboard['current']['playthrough_id'] ?? null)) $homeTargets[] = $world;
}
?>
<section class="pth-home" aria-label="Playthrough Saves">
    <div class="pth-row"><strong>Playthrough Saves</strong><span class="pth-current"><?= lorkhan_ui_h($homeCurrentName) ?></span>
        <button type="button" data-pth-open="pth-picker" aria-haspopup="dialog" aria-controls="pth-picker">Switch playthrough</button>
        <button type="button" data-pth-open="pth-new" aria-haspopup="dialog" aria-controls="pth-new"<?= $installation===''?' disabled':'' ?>>New playthrough</button>
        <a href="<?= lorkhan_ui_h($homeManagerUrl) ?>">Manage saves</a>
    </div>
    <p>Saved characters switch automatically when loaded. Changes made here apply on the next matching save load; the running game is unchanged.</p>
    <?php if (($homeCharacterState['pending_associations']??[])!==[]): ?><p role="status">A playthrough association is waiting for its character's next load. <a href="<?= lorkhan_ui_h($homeManagerUrl) ?>#pending-associations-title">Review or cancel</a></p><?php endif; ?>
    <noscript>Enable JavaScript for these controls, or open Manage saves.</noscript>
</section>
<dialog id="pth-picker" class="pth-dialog" aria-labelledby="pth-picker-title">
    <header><h2 id="pth-picker-title">Switch playthrough</h2><button type="button" data-pth-close>Close</button></header>
    <p>For another character, simply load their game save. To use an inactive copy with a saved character, queue its association below, then load a matching save.</p>
    <?php if ($homeTargets!==[] && $homeCharacterState['bindings']!==[]): ?>
    <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-association" data-playthrough-association>
        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($installation) ?>"><input type="hidden" name="operation" value="queue">
        <label for="pth-character">Saved character</label><select id="pth-character" name="character_id"><?php foreach($homeCharacterState['bindings'] as $binding): ?><option value="<?= lorkhan_ui_h($binding['character_id']) ?>" data-playthrough-id="<?= lorkhan_ui_h($binding['playthrough_id']) ?>"><?= lorkhan_ui_h($binding['name']) ?></option><?php endforeach; ?></select>
        <label for="pth-target">Inactive playthrough</label><select id="pth-target" name="playthrough_id"><?php foreach($homeTargets as $world): ?><option value="<?= lorkhan_ui_h($world['playthrough_id']) ?>"><?= lorkhan_ui_h($world['playthrough']) ?></option><?php endforeach; ?></select>
        <button type="submit">Associate on Next Load</button><p role="status" aria-live="polite"></p>
    </form>
    <?php else: ?><p>No unlinked playthrough is available to associate. Create a new playthrough or copy an existing one in Manage saves.</p><?php endif; ?>
    <a href="<?= lorkhan_ui_h($homeManagerUrl) ?>">Manage all saves and imported copies</a>
</dialog>
<dialog id="pth-new" class="pth-dialog" aria-labelledby="pth-new-title">
    <header><h2 id="pth-new-title">New playthrough</h2><button type="button" data-pth-close>Close</button></header>
    <p>Create an empty history with separate Player and NPC profiles. Shared settings and Narrator stay unchanged. This does not start a new game.</p>
    <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-manage" data-playthrough-manage data-success-url="<?= lorkhan_ui_h($homeManagerUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($installation) ?>"><input type="hidden" name="operation" value="create">
        <label for="pth-name">Playthrough name</label><input id="pth-name" name="name" maxlength="256" required autocomplete="off">
        <button type="submit">Create Playthrough</button><p role="status" aria-live="polite"></p>
    </form>
</dialog>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/playthrough-snapshots.js?v=<?= filemtime(__DIR__.'/../js/playthrough-snapshots.js') ?>"></script>
