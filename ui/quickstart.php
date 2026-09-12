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
$player2=$productRepository->player2Routing()->state($installationId);
$llms=$scoped($uiRepository->rows('llm'));$tts=$scoped($uiRepository->rows('tts'));$stt=$scoped($uiRepository->rows('stt'));
// Reuse the read-only global route inventory; this does not run connector tests.
$generalConnectors=$installationId===''?[]:($productRepository->globalConnectorTestPlan($installationId)['groups'][0]['slots']??[]);
$generalConnectors=array_values(array_filter($generalConnectors,static fn(array $slot):bool=>$slot['configuration_id']!==null));
$active=[];
foreach(['tts_provider'=>$tts,'stt_provider'=>$stt]as$kind=>$rows)foreach($rows as$row)
    if(filter_var($row['active']??false,FILTER_VALIDATE_BOOL))$active[$kind]=$row['configuration_id'];
$ready=$selected!==null&&$llms!==[];
$player=$installationId===''?null:$productRepository->playerProfileForInstallation($installationId);
$localState=$installationId===''?null:$productRepository->quickstartLocalLlmForInstallation($installationId);
$localContent=$localState['connector']['content']??[];
$localPlan=$installationId===''?null:$productRepository->quickstartLocalRoutingPlan($installationId);
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
        <input type="hidden" name="player2_revision" value="<?= $player2['revision'] ?>">
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
        <?php include __DIR__.'/tmpl/quickstart_setup.php'; ?>
        <section class="qs-section" id="qs_minime_section" data-minime-endpoint="<?= lorkhan_ui_h($managementBasePath) ?>/api/v1/quickstart-minime" data-minime-installation="<?= lorkhan_ui_h($installationId) ?>" data-minime-csrf="<?= lorkhan_ui_h($csrf) ?>">
            <h2 class="qs-section-title">MiniMe Service</h2>
            <div class="form-group qs-field"><small class="form-text">Checks if MiniMe is reachable at the saved endpoint or the local default. No game text is sent.</small>
                <div id="qs_minime_probe_status" class="qs-status" role="status" aria-live="polite">Checking MiniMe service...</div>
            </div>
        </section>
        <?php foreach(['tts_provider'=>['TTS Service',$tts,'tts_connectors.php'],'stt_provider'=>['STT Service',$stt,'stt_connectors.php']]as$kind=>[$label,$rows,$editor]): ?>
        <section class="qs-section qs-service-card"><h2 class="qs-section-title"><?php echo $label; ?></h2><div class="form-group qs-service-group<?php echo $kind==='stt_provider'?' qs-service-group-stt':''; ?>">
            <?php
            $recommendedDrivers=$kind==='tts_provider'?['omnivoice','pockettts','chatterbox']:['parakeet','deepgram'];
            $otherGroup=$kind==='tts_provider'?'Other TTS Services':'Other STT Services';
            $serviceGroups=['Recommended'=>[],$otherGroup=>[],'Saved connectors'=>[]];$used=[];
            $selectedSpeech=$kind==='tts_provider'?($routing['tts_configuration_id']??$active[$kind]??''):($active[$kind]??'');
            foreach(\LorkhanServer\Application\ConnectorCatalog::QUICKSTART_SPEECH_DRIVERS[$kind] as $driver=>$driverLabel){
                $matching=array_values(array_filter($rows,static fn(array $row):bool=>($row['content']['driver']??'')===$driver));
                $chosen=$matching[0]??null;
                foreach($matching as$row)if($row['configuration_id']===($active[$kind]??''))$chosen=$row;
                foreach($matching as$row)if($row['configuration_id']===$selectedSpeech)$chosen=$row;
                $definition=\LorkhanServer\Application\ConnectorCatalog::definition($kind,$driver);
                $chosen??=['configuration_id'=>'service:'.$driver,'content'=>['driver'=>$driver,'credential'=>$definition['credential_environment']?:'none']];
                $used[]=$chosen['configuration_id'];$chosen['name']=$driverLabel;
                $serviceGroups[in_array($driver,$recommendedDrivers,true)?'Recommended':$otherGroup][]=$chosen;
            }
            foreach($rows as$row)if(!in_array($row['configuration_id'],$used,true))$serviceGroups['Saved connectors'][]=$row;
            ?>
            <label for="qs-<?php echo $kind; ?>"><?php echo $label; ?></label><select class="form-control" id="qs-<?php echo $kind; ?>" name="<?php echo $kind; ?>"><option value="">Keep current selection</option>
                <?php foreach($serviceGroups as$groupLabel=>$groupRows): if($groupRows===[])continue; ?><optgroup label="<?php echo lorkhan_ui_h($groupLabel); ?>">
                <?php foreach($groupRows as$row): ?><option value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>" data-driver="<?php echo lorkhan_ui_h($row['content']['driver']??''); ?>" data-credential="<?php echo lorkhan_ui_h($row['content']['credential']??'LORKHAN_TTS_DEEPGRAM_API_KEY'); ?>"<?php echo ($kind==='tts_provider'?($routing['tts_configuration_id']??$active[$kind]??''):($active[$kind]??''))===$row['configuration_id']?' selected':''; ?>><?php echo lorkhan_ui_h($row['name'].($groupLabel==='Recommended'?' (Recommended)':'')); ?></option><?php endforeach; ?>
                </optgroup><?php endforeach; ?>
            </select>
            <?php if($kind==='stt_provider'): ?><div data-deepgram-key hidden>
                <?php lorkhan_quickstart_key('deepgram','Deepgram','https://console.deepgram.com/',$keyStatuses['LORKHAN_TTS_DEEPGRAM_API_KEY']??[],$keyStoreReady); ?>
                <p class="form-text" data-key-badge-warning hidden>This connector uses a different API badge. Change that key or badge in STT Connectors.</p>
            </div><?php endif; ?>
            <small class="form-text">Choose a service. Saving reuses its saved connector or creates one with default settings. This does not install or start a service, test connectivity, or replace API keys. For provider settings and endpoint editing, use <a href="<?php echo lorkhan_ui_h($webRoot.'/ui/core/'.$editor); ?>"><?php echo $kind==='tts_provider'?'TTS Connectors':'STT Connectors'; ?></a>.</small>
        </div></section><?php endforeach; ?>
        <section class="qs-section"><h2 class="qs-section-title">Player2 Connector</h2>
            <div class="form-group qs-field"><div class="qs-toggle-block"><div class="qs-toggle-header">
                <label class="qs-toggle-title" for="qs_player2_force_all_llm">Use Player 2 for LLMs</label><div class="qs-toggle-control">
                    <input class="form-check-input qs-switch-input" type="checkbox" id="qs_player2_force_all_llm" name="player2_force_all_llm" value="1"<?= $player2['enabled']?' checked':'' ?>>
                    <label class="form-check-label qs-switch-label" for="qs_player2_force_all_llm"><span class="qs-switch-track"></span><span class="qs-switch-copy" data-off="Off" data-on="On"></span></label>
                </div></div></div><small class="form-text">Route all LLM calls through your local Player2 connector. Model choice stays in the Player2 app.</small></div>
        </section>
        <section class="qs-section"><h2 class="qs-section-title">LLM Connectors Note</h2><p class="form-text" data-normal-llm-note>Four hot-swappable models for Interact. Standard is the default; saving does not reset your current in-game slot.</p>
            <p class="form-text" data-local-llm-note hidden>Local LLM profile selected. The recap below reflects the Local LLM Setup fields in the Setup section and is applied on Save and Continue.</p>
            <p class="form-text" data-player2-llm-note hidden>Player2 mode is active. Standard, Fast, Powerful, and Experimental all use the local Player2 connector.</p>
            <div class="qs-connector-grid" data-player2-recap hidden><?php foreach(['🕹️ Standard','🏃 Fast','💪 Powerful','🧪 Experimental']as$index=>$label): ?><div class="qs-connector-card"><div class="qs-connector-label"><b><?= $label ?></b></div><div class="qs-model">Player2 Local</div><small class="qs-connector-detail"><?= $index===0?'Uses the model selected in the Player2 app':'Same local Player2 connector as Standard' ?></small></div><?php endforeach; ?></div>
            <?php $modelSlots=['llm_configuration_id'=>['Standard','🕹️'],'llm_fast_configuration_id'=>['Fast','🏃'],'llm_powerful_configuration_id'=>['Powerful','💪'],'llm_experimental_configuration_id'=>['Experimental','🧪']]; ?>
            <div class="qs-connector-grid" data-normal-recap><?php foreach($modelSlots as$field=>[$label,$icon]):
                $modelLabel='No model selected';foreach($llms as$row)if(($routing[$field]??'')===$row['configuration_id'])$modelLabel=$row['name'].(!empty($row['content']['model'])?' ('.$row['content']['model'].')':''); ?>
            <div class="qs-connector-card"><div class="qs-connector-label"><span aria-hidden="true"><?= $icon ?></span> <b><?= $label ?></b></div>
                <div class="qs-model" data-model-recap="<?= $field ?>"><?= lorkhan_ui_h($modelLabel) ?></div>
            </div><?php endforeach; ?></div>
            <div class="qs-connector-grid" data-local-recap hidden><?php foreach(['🕹️ Standard','🏃 Fast','💪 Powerful','🧪 Experimental'] as $label): ?><div class="qs-connector-card"><div class="qs-connector-label"><b><?= $label ?></b></div><div class="qs-model" data-local-model></div><small class="qs-connector-detail" data-local-endpoint></small></div><?php endforeach; ?></div>
            <div class="qs-general-connector-wrap">
                <div class="qs-general-connector-title" data-general-connector-title>Other Connectors Used:</div>
                <div data-general-connector-saved>
                    <?php if($generalConnectors===[]): ?><div class="qs-general-connector-empty">No additional general-settings connectors are configured.</div>
                    <?php else: ?><ul class="qs-general-connector-list"><?php foreach($generalConnectors as $connector): ?><li><span class="qs-general-connector-name"><?= lorkhan_ui_h($connector['label']) ?>:</span> <?= lorkhan_ui_h($connector['connector_label']) ?></li><?php endforeach; ?></ul><?php endif; ?>
                </div>
                <div class="qs-general-connector-empty" data-general-connector-override hidden></div>
            </div>
            <p class="form-text">These are your saved connectors. <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/llm_connectors.php">Configure Models</a> · <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/api_keys.php">API Keys</a></p>
            <details class="qs-model-selection" data-model-editor><summary>Change saved model selections</summary><div class="qs-connector-grid">
                <?php foreach($modelSlots as$field=>[$label,$icon]): ?><div><label for="qs-<?= $field ?>"><?= $label ?></label>
                    <select name="<?= $field ?>" id="qs-<?= $field ?>" class="form-control" required data-model-select><option value="">Choose a model</option><?php foreach($llms as$row): ?><option value="<?= lorkhan_ui_h($row['configuration_id']) ?>" data-model="<?= lorkhan_ui_h($row['content']['model']??'') ?>"<?= ($routing[$field]??'')===$row['configuration_id']?' selected':'' ?>><?= lorkhan_ui_h($row['name']) ?></option><?php endforeach; ?></select>
                </div><?php endforeach; ?>
            </div></details>
        </section>
        <p class="form-text">MiniMe and automatic summary settings are in <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/config_hub.php?tab=globals">Global Settings</a>. The MiniMe check only tests reachability; it does not enable summaries or generate embeddings.</p>
        <?php if(!$ready): ?><p class="quickstart-notice" data-default-required>Create a Core Profile and at least one LLM connector before saving Quickstart, or choose Local LLM to create its connector.</p><?php endif; ?>
        <div class="qs-actions"><span data-dirty-indicator hidden>Unsaved changes</span><span role="alert" data-quickstart-error></span><button type="submit" class="btn-primary qs-save-btn" aria-describedby="qs-profile-scope"<?php echo !$ready?' disabled':''; ?>>Save and Continue</button></div>
    </form>
    <details class="qs-section qs-profile-scope"><summary id="qs-profile-scope">Profile selection: <?php echo lorkhan_ui_h($selected['label']??$selected['name']??'Not configured'); ?></summary>
        <form method="get" class="quickstart-grid">
            <label>Installation<select name="installation_id"><?php foreach($installations as$row): ?><option value="<?php echo lorkhan_ui_h($row['installation_id']); ?>"<?php echo $installationId===$row['installation_id']?' selected':''; ?>><?php echo lorkhan_ui_h($row['display_name']??$row['installation_id']); ?></option><?php endforeach; ?></select></label>
            <label>Core Profile<select name="core_profile_id"><option value="">Installation default</option><?php foreach($profiles as$row): ?><option value="<?php echo lorkhan_ui_h($row['core_profile_id']); ?>"<?php echo $selectedId===$row['core_profile_id']?' selected':''; ?>><?php echo lorkhan_ui_h($row['label']??$row['name']); ?></option><?php endforeach; ?></select></label>
            <button type="submit" class="btn-base">Load Profile</button>
        </form>
        <p>Setup presets apply to all Core Profiles in this installation. Connector selections below apply to this profile; Local LLM also updates default NPC and Narrator routes. NPC-specific overrides stay in place.</p>
        <a class="btn-base" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/core_profiles.php">Manage Profiles</a>
    </details>
</div></main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo (string)filemtime(__DIR__.'/js/resource-page.js'); ?>"></script>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/quickstart.js?v=<?php echo (string)filemtime(__DIR__.'/js/quickstart.js'); ?>"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
