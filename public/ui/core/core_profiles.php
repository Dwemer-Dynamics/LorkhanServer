<?php
declare(strict_types=1);
$pageTitle='Core Profiles';$topNavSection='configuration';$BODY_CLASS='hub-page';
require dirname(__DIR__).'/ui_bootstrap.php';
$installations=$uiRepository->rows('installations');$requested=trim((string)($_GET['installation_id']??''));$installationId='';
foreach($installations as$row)if($requested!==''&&hash_equals((string)$row['installation_id'],$requested))$installationId=$requested;
if($installationId===''&&isset($installations[0]))$installationId=(string)$installations[0]['installation_id'];
if($installationId!=='')$productRepository->defaultCoreProfileForInstallation($installationId,gmdate('Y-m-d\TH:i:s\Z'),true);
$filter=static fn(array$rows):array=>array_values(array_filter($rows,static fn(array$row):bool=>(string)$row['installation_id']===$installationId));
$profiles=$filter($uiRepository->rows('core_profiles'));$llm=$filter($uiRepository->rows('llm'));$tts=$filter($uiRepository->rows('tts'));$prompts=$filter($uiRepository->rows('prompts'));
$embedded=isset($_GET['embed'])&&$_GET['embed']==='1';include dirname(__DIR__).'/tmpl/head.html';if(!$embedded)include dirname(__DIR__).'/tmpl/navbar.php';
?>
<main class="almsivi-page<?php echo $embedded?' embedded':''; ?>">
    <header class="almsivi-page-header"><div><h1>Core Profiles</h1><p>Shared roleplay, connector, and behavior settings inherited by assigned Morrowind NPCs.</p></div><div class="almsivi-badges"><span class="almsivi-badge">Revisioned</span><span class="almsivi-badge default">Middle layer</span></div></header>
    <?php if(isset($_GET['status'])): ?><div class="almsivi-status">Core Profile change saved.</div><?php endif; ?>
    <?php if($installations===[]): ?><section class="almsivi-card almsivi-empty">Connect OpenMW once before creating Core Profiles.</section><?php else: ?>
    <div class="almsivi-toolbar"><label>Installation<select data-installation-select><?php foreach($installations as$row): ?><option value="<?php echo almsivi_ui_h($row['installation_id']); ?>"<?php echo $row['installation_id']===$installationId?' selected':''; ?>><?php echo almsivi_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label><span class="almsivi-muted"><?php echo count($profiles); ?> profiles</span></div>
    <div class="almsivi-inheritance"><span>Global defaults</span><span class="active">Core Profile</span><span>NPC overrides</span></div>
    <section class="almsivi-card mb-3"><div class="almsivi-card-header"><div><h2>Create Core Profile</h2><p class="almsivi-muted">New profiles inherit every Global Setting until you choose an override.</p></div></div>
        <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/core-profile-create">
            <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>">
            <div class="almsivi-form-grid"><label>Profile name<input name="label" required maxlength="256"></label><label>Quick slot<select name="slot"><option value="">No slot</option><?php foreach(range(1,4)as$slot): ?><option value="<?php echo $slot; ?>">Slot <?php echo $slot; ?></option><?php endforeach; ?></select></label><label><span><input type="checkbox" name="default_npc" value="1"> Default for newly created NPCs</span></label></div>
            <details><summary>Configure initial overrides</summary><?php $content=['schema'=>'almsivi.core-profile.v1','prompt'=>'','routing'=>[],'settings_overrides'=>[]];include __DIR__.'/tmpl/core_profile_fields.php'; ?></details>
            <div class="almsivi-actions"><button type="submit">Create Core Profile</button></div>
        </form>
    </section>
    <section class="almsivi-grid">
    <?php foreach($profiles as$profile): $content=is_array($profile['content'])?$profile['content']:[]; ?>
        <article class="almsivi-card"><div class="almsivi-card-header"><div><h2><?php echo almsivi_ui_h($profile['label']); ?></h2><p class="almsivi-muted">Revision <?php echo almsivi_ui_h($profile['current_revision']); ?> · <?php echo almsivi_ui_h($profile['profile_usage']); ?> assigned profiles</p></div><div class="almsivi-badges"><?php if(filter_var($profile['default_npc'],FILTER_VALIDATE_BOOL)): ?><span class="almsivi-badge default">Default NPC</span><?php endif; ?><?php if($profile['slot']!==null): ?><span class="almsivi-badge">Slot <?php echo almsivi_ui_h($profile['slot']); ?></span><?php endif; ?></div></div>
            <p><?php echo trim((string)($content['prompt']??''))===''?'<span class="almsivi-muted">No shared roleplay instruction.</span>':nl2br(almsivi_ui_h(mb_strimwidth((string)$content['prompt'],0,280,'…'))); ?></p>
            <details><summary>Edit profile and overrides</summary><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/core-profile-revise">
                <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="core_profile_id" value="<?php echo almsivi_ui_h($profile['core_profile_id']); ?>">
                <?php include __DIR__.'/tmpl/core_profile_fields.php'; ?><label class="mt-2">Revision note<input name="change_reason" required maxlength="512" value="Management Core Profile update"></label><div class="almsivi-actions"><button type="submit">Save New Revision</button></div>
            </form></details>
            <details><summary>Revision history</summary><?php $history=is_array($profile['revisions'])?$profile['revisions']:[];almsivi_ui_table($history,'No revisions.'); ?><?php $earlier=array_values(array_filter($history,static fn(array$r):bool=>(int)($r['revision']??0)!==(int)$profile['current_revision']));if($earlier!==[]): ?><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/core-profile-rollback"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="core_profile_id" value="<?php echo almsivi_ui_h($profile['core_profile_id']); ?>"><label>Restore revision<select name="revision"><?php foreach($earlier as$revision): ?><option value="<?php echo almsivi_ui_h($revision['revision']); ?>">Revision <?php echo almsivi_ui_h($revision['revision']); ?> — <?php echo almsivi_ui_h($revision['reason']); ?></option><?php endforeach; ?></select></label><button class="secondary" type="submit">Restore Earlier Revision</button></form><?php endif; ?></details>
            <div class="almsivi-actions"><?php if(!filter_var($profile['default_npc'],FILTER_VALIDATE_BOOL)): ?><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/core-profile-default"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="core_profile_id" value="<?php echo almsivi_ui_h($profile['core_profile_id']); ?>"><button class="secondary" type="submit">Make Default</button></form><?php endif; ?>
                <?php if(!filter_var($profile['default_npc'],FILTER_VALIDATE_BOOL)&&(int)$profile['profile_usage']===0): ?><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/core-profile-delete" data-confirm="Delete this unused Core Profile?"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="core_profile_id" value="<?php echo almsivi_ui_h($profile['core_profile_id']); ?>"><button class="danger" type="submit">Delete</button></form><?php endif; ?></div>
        </article>
    <?php endforeach; ?>
    </section>
    <?php endif; ?>
</main>
<?php include dirname(__DIR__).'/tmpl/footer.html'; ?>
