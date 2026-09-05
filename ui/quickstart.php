<?php
declare(strict_types=1);
$pageTitle='Quickstart';$topNavSection='quickstart';
require __DIR__.'/ui_bootstrap.php';
$installations=$uiRepository->rows('installations');
$installationId=(string)($_GET['installation_id']??($installations[0]['installation_id']??''));
$installationIds=array_column($installations,'installation_id');
if(!in_array($installationId,$installationIds,true))$installationId='';
$scoped=static fn(array $rows):array=>array_values(array_filter($rows,static fn(array $row):bool=>($row['installation_id']??'')===$installationId));
$profiles=$scoped($uiRepository->rows('core_profiles'));
$selectedId=(string)($_GET['core_profile_id']??'');$selected=null;
foreach($profiles as$profile){
    if($selectedId!==''&&$profile['core_profile_id']===$selectedId){$selected=$profile;break;}
    if($selectedId===''&&filter_var($profile['default_npc']??false,FILTER_VALIDATE_BOOL))$selected=$profile;
}
if($selected===null&&$selectedId==='')$selected=$profiles[0]??null;
$routing=$selected['content']['routing']??[];
$llms=$scoped($uiRepository->rows('llm'));$tts=$scoped($uiRepository->rows('tts'));$stt=$scoped($uiRepository->rows('stt'));
$active=[];
foreach(['tts_provider'=>$tts,'stt_provider'=>$stt]as$kind=>$rows)foreach($rows as$row)
    if(filter_var($row['active']??false,FILTER_VALIDATE_BOOL))$active[$kind]=$row['configuration_id'];
$ready=$selected!==null&&$llms!==[];
$additionalStylesheets=['quickstart.css?v='.(string)filemtime(__DIR__.'/css/quickstart.css')];
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="container quickstart-page">
    <header class="quickstart-header"><h1>Quickstart</h1><p>Choose your models and speech connectors. Your current save and character profiles are kept.</p></header>
    <?php if(($_GET['status']??'')==='saved'): ?><p role="status" class="quickstart-notice">Connector selections saved.</p><?php endif; ?>
    <section class="quickstart-card"><h2>Profile</h2>
        <form method="get" class="quickstart-grid">
            <label>Installation<select name="installation_id"><?php foreach($installations as$row): ?><option value="<?php echo lorkhan_ui_h($row['installation_id']); ?>"<?php echo $installationId===$row['installation_id']?' selected':''; ?>><?php echo lorkhan_ui_h($row['display_name']??$row['installation_id']); ?></option><?php endforeach; ?></select></label>
            <label>Core Profile<select name="core_profile_id"><option value="">Installation default</option><?php foreach($profiles as$row): ?><option value="<?php echo lorkhan_ui_h($row['core_profile_id']); ?>"<?php echo $selectedId===$row['core_profile_id']?' selected':''; ?>><?php echo lorkhan_ui_h($row['label']??$row['name']); ?></option><?php endforeach; ?></select></label>
            <button type="submit" class="btn-base">Load Profile</button>
        </form>
        <p>Only the selected Core Profile's model and TTS routes change. NPC-specific overrides stay in place.</p>
        <a class="btn-base" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/core_profiles.php">Manage Profiles</a>
    </section>
    <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/quickstart-save" data-confirm="Save these model routes to the selected Core Profile and speech defaults to this installation? Other profile settings and existing NPC overrides stay unchanged.">
        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
        <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
        <input type="hidden" name="core_profile_id" value="<?php echo lorkhan_ui_h($selected['core_profile_id']??''); ?>">
        <input type="hidden" name="base_revision" value="<?php echo (int)($selected['current_revision']??0); ?>">
        <section class="quickstart-card"><h2>LLM Connectors</h2><p>Four model choices for Interact. Standard is the default; your current in-game selection is not reset.</p>
            <div class="quickstart-grid"><?php foreach(['llm_configuration_id'=>'Standard','llm_fast_configuration_id'=>'Fast','llm_powerful_configuration_id'=>'Powerful','llm_experimental_configuration_id'=>'Experimental']as$field=>$label): ?>
                <label><?php echo $label; ?><select name="<?php echo $field; ?>" required><option value="">Choose a model</option><?php foreach($llms as$row): ?><option value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>"<?php echo ($routing[$field]??'')===$row['configuration_id']?' selected':''; ?>><?php echo lorkhan_ui_h($row['name']); ?></option><?php endforeach; ?></select></label>
            <?php endforeach; ?></div>
            <div class="quickstart-links"><a class="btn-base" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/llm_connectors.php">Configure Models</a><a class="btn-base" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/api_keys.php">API Keys</a></div>
        </section>
        <section class="quickstart-card"><h2>Voice &amp; Speech Recognition</h2><div class="quickstart-grid">
            <?php foreach(['tts_provider'=>['Text-to-Speech',$tts],'stt_provider'=>['Speech-to-Text',$stt]]as$kind=>[$label,$rows]): ?>
                <label><?php echo $label; ?><select name="<?php echo $kind; ?>"><option value="">Keep current selection</option><?php foreach($rows as$row): ?><option value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>"<?php echo ($kind==='tts_provider'?($routing['tts_configuration_id']??$active[$kind]??''):($active[$kind]??''))===$row['configuration_id']?' selected':''; ?>><?php echo lorkhan_ui_h($row['name']); ?></option><?php endforeach; ?></select></label>
            <?php endforeach; ?></div><p>TTS also sets the selected Core Profile's voice connector. STT is shared by the installation.</p>
            <div class="quickstart-links"><a class="btn-base" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/tts_connectors.php">Configure TTS</a><a class="btn-base" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/stt_connectors.php">Configure STT</a><a class="btn-base" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/voice_library.php">TTS Studio</a></div>
        </section>
        <section class="quickstart-card"><h2>Memory</h2><p>Configure MiniMe and automatic summaries in Global Settings. Saving Quickstart makes no provider requests and does not regenerate NPCs or memories.</p><a class="btn-base" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/config_hub.php?tab=globals">Global Settings</a></section>
        <?php if(!$ready): ?><p class="quickstart-notice">Create a Core Profile and at least one LLM connector before saving Quickstart.</p><?php endif; ?>
        <div class="quickstart-footer"><button type="submit" class="btn-base btn-primary"<?php echo !$ready?' disabled':''; ?>>Save Quickstart</button><a class="btn-base" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/home.php">Home</a></div>
    </form>
</main>
<?php include __DIR__.'/tmpl/footer.html'; ?>
