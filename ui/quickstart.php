<?php
declare(strict_types=1);
$pageTitle='Quickstart';$topNavSection='quickstart';
$BODY_CLASS='quickstart-shell';
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
$player=$installationId===''?null:$productRepository->playerProfileForInstallation($installationId);
$keyStatuses=[];$keyStoreReady=true;
try{foreach((new \LorkhanServer\Application\CredentialStore((string)$config['credential_storage_path']))->statuses()as$status)$keyStatuses[$status['variable']]=$status;}
catch(Throwable){$keyStoreReady=false;}
/** Share the two Quickstart key controls without exposing stored credential values. */
function lorkhan_quickstart_key(string $provider,string $label,string $url,array $status,bool $available):void
{
    $configured=($status['configured']??false)===true;$environment=($status['source']??'')==='environment';
    $locked=!$available||$environment;$id='qs-'.$provider.'-key';
    echo '<div class="qs-key-field" data-quick-key="'.$provider.'"><label for="'.$id.'">'.$label.' API Key</label>'
        .'<div class="qs-key-input"><input class="form-control" id="'.$id.'" type="password" autocomplete="new-password" maxlength="8192" data-key-input data-locked="'.($locked?'1':'0').'" aria-describedby="'.$id.'-help '.$id.'-status" placeholder="'.($configured?'Configured - leave blank to keep':'Paste API key').'"'.($locked?' disabled':'').'>'
        .'<button class="btn-primary" type="button" data-key-unhide aria-controls="'.$id.'"'.($locked?' disabled':'').'>Unhide</button></div>'
        .'<p class="form-text" id="'.$id.'-help">Saved when you leave the field. Leave blank to keep the existing key. <a href="'.lorkhan_ui_h($url).'" target="_blank" rel="noopener noreferrer">Create key</a></p>'
        .'<div class="qs-key-status" id="'.$id.'-status" role="status" aria-live="polite" data-key-status>'.(!$available?'Key store unavailable.':($environment?'Configured by the server environment. Change it outside Quickstart.':($configured?'Configured.':'Not configured.'))).'</div></div>';
}
$additionalStylesheets=['main.css','quickstart.css?v='.(string)filemtime(__DIR__.'/css/quickstart.css')];
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="quickstart-page"><div class="qs-shell">
    <section class="qs-section qs-header-card"><h1 class="qs-title">Quickstart Menu</h1></section>
    <?php if(($_GET['status']??'')==='saved'): ?><p role="status" class="quickstart-notice">Quickstart settings saved.</p><?php endif; ?>

    <form class="confwizard" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/quickstart-save" data-track-dirty data-key-endpoint="<?php echo lorkhan_ui_h($managementBasePath); ?>/api/v1/quickstart-key">
        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
        <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
        <input type="hidden" name="core_profile_id" value="<?php echo lorkhan_ui_h($selected['core_profile_id']??''); ?>">
        <input type="hidden" name="base_revision" value="<?php echo (int)($selected['current_revision']??0); ?>">
        <section class="qs-section"><h2 class="qs-section-title">Player</h2>
            <div class="form-group qs-field">
            <?php if($player!==null): ?><input type="hidden" name="player_revision" value="<?php echo (int)$player['revision']; ?>">
            <label for="qs-player-name">Player Name</label><input id="qs-player-name" class="form-control" name="player_name" type="text" required maxlength="256" value="<?php echo lorkhan_ui_h($player['name']); ?>" aria-describedby="qs-player-help">
            <small class="form-text" id="qs-player-help">Your player persona's name. Saving does not rename the character in the game or rewrite recorded dialogue. Manage other player settings in <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/player_management.php?installation_id=<?php echo lorkhan_ui_h($installationId); ?>">Player Management</a>.</small>
            <?php else: ?><p class="form-text">No player profile is configured. Connect OpenMW or create the player profile in <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/player_management.php?installation_id=<?php echo lorkhan_ui_h($installationId); ?>">Player Management</a>.</p><?php endif; ?>
            </div>
        </section>
        <section class="qs-section"><h2 class="qs-section-title">OpenRouter</h2>
            <?php lorkhan_quickstart_key('openrouter','OpenRouter','https://openrouter.ai/keys',$keyStatuses['LORKHAN_LLM_API_KEY']??[],$keyStoreReady); ?>
            <p class="form-text">Updates the server-wide OpenRouter API badge. Direct connectors using a different API badge keep their own key selection.</p>
        </section>
        <?php foreach(['tts_provider'=>['TTS Service',$tts,'tts_connectors.php'],'stt_provider'=>['STT Service',$stt,'stt_connectors.php']]as$kind=>[$label,$rows,$editor]): ?>
        <section class="qs-section qs-service-card"><h2 class="qs-section-title"><?php echo $label; ?></h2><div class="qs-service-group<?php echo $kind==='stt_provider'?' qs-service-group-stt':''; ?>">
            <label for="qs-<?php echo $kind; ?>"><?php echo $label; ?></label><select class="form-control" id="qs-<?php echo $kind; ?>" name="<?php echo $kind; ?>"><option value="">Keep current selection</option><?php foreach($rows as$row): ?><option value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>" data-driver="<?php echo lorkhan_ui_h($row['content']['driver']??''); ?>" data-credential="<?php echo lorkhan_ui_h($row['content']['credential']??'LORKHAN_TTS_DEEPGRAM_API_KEY'); ?>"<?php echo ($kind==='tts_provider'?($routing['tts_configuration_id']??$active[$kind]??''):($active[$kind]??''))===$row['configuration_id']?' selected':''; ?>><?php echo lorkhan_ui_h($row['name']); ?></option><?php endforeach; ?></select>
            <?php if($kind==='stt_provider'): ?><div data-deepgram-key hidden>
                <?php lorkhan_quickstart_key('deepgram','Deepgram','https://console.deepgram.com/',$keyStatuses['LORKHAN_TTS_DEEPGRAM_API_KEY']??[],$keyStoreReady); ?>
                <p class="form-text" data-key-badge-warning hidden>This connector uses a different API badge. Change that key or badge in STT Connectors.</p>
            </div><?php endif; ?>
            <p class="form-text"><?php echo $kind==='tts_provider'?"Select a saved voice connector for the Core Profile and installation default.":"Select a saved speech-recognition connector for the installation."; ?> For provider settings and endpoint editing, use <a href="<?php echo lorkhan_ui_h($webRoot.'/ui/core/'.$editor); ?>"><?php echo $kind==='tts_provider'?'TTS Connectors':'STT Connectors'; ?></a>.</p>
        </div></section><?php endforeach; ?>
        <section class="qs-section"><h2 class="qs-section-title">LLM Connectors Note</h2><p class="form-text">Four hot-swappable models for Interact. Standard is the default; saving does not reset your current in-game slot.</p>
            <div class="qs-connector-grid"><?php foreach(['llm_configuration_id'=>['Standard','🕹️'],'llm_fast_configuration_id'=>['Fast','🏃'],'llm_powerful_configuration_id'=>['Powerful','💪'],'llm_experimental_configuration_id'=>['Experimental','🧪']]as$field=>[$label,$icon]): $model=''; foreach($llms as$row)if(($routing[$field]??'')===$row['configuration_id'])$model=(string)($row['content']['model']??''); ?>
            <div class="qs-connector-card"><label for="qs-<?php echo $field; ?>"><span aria-hidden="true"><?php echo $icon; ?></span> <strong><?php echo $label; ?></strong></label>
                <select name="<?php echo $field; ?>" id="qs-<?php echo $field; ?>" class="form-control" required data-model-select><option value="">Choose a model</option><?php foreach($llms as$row): ?><option value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>" data-model="<?php echo lorkhan_ui_h($row['content']['model']??''); ?>"<?php echo ($routing[$field]??'')===$row['configuration_id']?' selected':''; ?>><?php echo lorkhan_ui_h($row['name']); ?></option><?php endforeach; ?></select>
                <div class="qs-model" data-model-recap><?php echo lorkhan_ui_h($model); ?></div>
            </div><?php endforeach; ?></div>
            <p class="form-text">These are your saved connectors. <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/llm_connectors.php">Configure Models</a> · <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/api_keys.php">API Keys</a></p>
        </section>
        <p class="form-text">MiniMe and automatic summary settings are in <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/config_hub.php?tab=globals">Global Settings</a>. Quickstart makes no provider requests.</p>
        <?php if(!$ready): ?><p class="quickstart-notice">Create a Core Profile and at least one LLM connector before saving Quickstart.</p><?php endif; ?>
        <div class="qs-actions"><span data-dirty-indicator hidden>Unsaved changes</span><span role="alert" data-quickstart-error></span><button type="submit" class="btn-primary qs-save-btn" aria-describedby="qs-profile-scope"<?php echo !$ready?' disabled':''; ?>>Save and Continue</button></div>
    </form>
    <details class="qs-section qs-profile-scope"><summary id="qs-profile-scope">Profile selection: <?php echo lorkhan_ui_h($selected['label']??$selected['name']??'Not configured'); ?></summary>
        <form method="get" class="quickstart-grid">
            <label>Installation<select name="installation_id"><?php foreach($installations as$row): ?><option value="<?php echo lorkhan_ui_h($row['installation_id']); ?>"<?php echo $installationId===$row['installation_id']?' selected':''; ?>><?php echo lorkhan_ui_h($row['display_name']??$row['installation_id']); ?></option><?php endforeach; ?></select></label>
            <label>Core Profile<select name="core_profile_id"><option value="">Installation default</option><?php foreach($profiles as$row): ?><option value="<?php echo lorkhan_ui_h($row['core_profile_id']); ?>"<?php echo $selectedId===$row['core_profile_id']?' selected':''; ?>><?php echo lorkhan_ui_h($row['label']??$row['name']); ?></option><?php endforeach; ?></select></label>
            <button type="submit" class="btn-base">Load Profile</button>
        </form>
        <p>Only the selected Core Profile's model and TTS routes change. NPC-specific overrides stay in place.</p>
        <a class="btn-base" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/core_profiles.php">Manage Profiles</a>
    </details>
</div></main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo (string)filemtime(__DIR__.'/js/resource-page.js'); ?>"></script>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/quickstart.js?v=<?php echo (string)filemtime(__DIR__.'/js/quickstart.js'); ?>"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
