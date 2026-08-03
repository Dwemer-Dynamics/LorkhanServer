<?php

declare(strict_types=1);

use ALMSIVIserver\Application\ConnectorCatalog;

if (!isset($uiRootDir, $view, $pageTitle, $topNavSection)) throw new RuntimeException('Incomplete management page definition.');
require $uiRootDir . '/ui_bootstrap.php';
$rows = $uiRepository->rows($view);
$policyRows = $view === 'actions' ? $uiRepository->rows('action_policies') : [];
$narrativeRows = $view === 'autonomy' ? $uiRepository->rows('narratives') : [];
$scheduleRows = $view === 'global_settings' ? $uiRepository->rows('autonomy') : [];
$backupRows = $view === 'database_manager' ? $uiRepository->rows('backup_health') : [];
$observedNpcs = $view === 'characters' ? $uiRepository->rows('observed_npcs') : [];
$profilePreferenceRows = $view === 'characters' ? $uiRepository->rows('profile_preferences') : [];
$installationOptions = [];
foreach ($uiRepository->rows('global_settings') as $installation) {
    $id = (string) ($installation['installation_id'] ?? '');
    if ($id !== '') $installationOptions[$id] = (string) ($installation['display_name'] ?? $id);
}
$configurationBackupOptions=[];
foreach($backupRows as$backup){$scope=is_array($backup['scope']??null)?$backup['scope']:[];
    if(($scope['kind']??null)!=='configuration')continue;$id=(string)($backup['backup_id']??'');
    if($id!=='')$configurationBackupOptions[$id]=(string)($installationOptions[$scope['installation_id']??'']??($scope['installation_id']??'Installation')).' - '.(string)($backup['created_at']??$id);}
$profileOptions=[];foreach(array_merge($uiRepository->rows('profiles'),$uiRepository->rows('player'))as$profile){$id=(string)($profile['profile_id']??'');if($id!=='')$profileOptions[$id]=(string)($profile['name']??$id);}
$playthroughOptions=[];foreach($uiRepository->rows('playthroughs')as$playthrough){$id=(string)($playthrough['playthrough_id']??'');if($id!=='')$playthroughOptions[$id]=(string)($playthrough['playthrough']??$id);}
$sessionOptions=[];foreach($uiRepository->rows('active_sessions')as$session){$id=(string)($session['session_id']??'');if($id!=='')$sessionOptions[$id]=(string)($session['label']??$id);}
$routingViews=['characters','profiles','narrator'];
$llmRoutingRows=in_array($view,$routingViews,true)?$uiRepository->rows('llm'):[];
$ttsRoutingRows=in_array($view,$routingViews,true)?$uiRepository->rows('tts'):[];
$promptRoutingRows=in_array($view,['characters','profiles'],true)?$uiRepository->rows('prompts'):[];
$llmRoutingOptions=[''=>'Use the current session model slot'];
foreach($llmRoutingRows as$row){$id=(string)($row['configuration_id']??'');$content=is_array($row['content']??null)?$row['content']:[];
    if($id!=='')$llmRoutingOptions[$id]=(string)($row['name']??$id).' - '.(string)($content['model']??'default model');}
$ttsRoutingOptions=[''=>'Use the active installation TTS connector'];
foreach($ttsRoutingRows as$row){$id=(string)($row['configuration_id']??'');$content=is_array($row['content']??null)?$row['content']:[];
    if($id!=='')$ttsRoutingOptions[$id]=(string)($row['name']??$id).' - '.(string)($content['driver']??'TTS');}
$promptRoutingOptions=[''=>'Use the first applicable installation prompt'];
foreach($promptRoutingRows as$row){$id=(string)($row['configuration_id']??'');if($id!=='')$promptRoutingOptions[$id]=(string)($row['name']??$id);}
$connectorOptions = [];
$connectorOptionCatalog=[];
$connectorDefaults=[];
if ($view === 'tts' || $view === 'stt') {
    foreach (ConnectorCatalog::all($view . '_provider') as $definition) {
        $connectorOptions[(string) $definition['driver']] = (string) $definition['label'];
        $connectorOptionCatalog[(string)$definition['driver']]=ConnectorCatalog::optionFields($view.'_provider',(string)$definition['driver']);
        $connectorDefaults[(string)$definition['driver']]=ConnectorCatalog::defaults($view.'_provider',(string)$definition['driver']);
    }
}
$voiceOptions = [];
$voiceRoot = (string) ($config['voice_storage_path'] ?? (is_dir('/var/lib/almsiviserver') ? '/var/lib/almsiviserver/voices' : ($applicationRoot . '/storage/voices')));
if (is_dir($voiceRoot)) {
    foreach (glob($voiceRoot . DIRECTORY_SEPARATOR . '*.wav') ?: [] as $voicePath) {
        $voiceName = pathinfo($voicePath, PATHINFO_FILENAME);
        if ($voiceName !== '') $voiceOptions[$voiceName] = $voiceName;
    }
}
foreach($uiRepository->rows('voice_catalog')as$providerVoice){
    $voiceId=(string)($providerVoice['voice_id']??'');if($voiceId==='')continue;
    $display=(string)($providerVoice['display_name']??$voiceId);$connector=(string)($providerVoice['connector_name']??'TTS connector');
    $voiceOptions[$voiceId]=$display.' - '.$connector;
}
natcasesort($voiceOptions);
$defaultConnectorDriver=$view==='tts'?'omnivoice':($view==='stt'?'parakeet':'');
$defaultConnectorValues=$defaultConnectorDriver===''?[]:ConnectorCatalog::defaults($view.'_provider',$defaultConnectorDriver);

$descriptions = [
    'characters' => 'Manage OpenMW NPC identities, roleplay instructions, and per-character voices.',
    'profiles' => 'Manage the versioned roleplay profiles selected in-game for OpenMW actors.',
    'player' => 'Describe the player character so NPC dialogue can recognize their history, personality, goals, and manner of speaking.',
    'narrator' => 'Configure the opt-in narrator persona, inline narration routing, and narrator-specific TTS voice.',
    'npc_biographies' => 'Review and edit the biography that ALMSIVI includes in each NPC roleplay profile.',
    'llm' => 'Configure server-side dialogue provider presets. Stored secrets remain redacted.',
    'tts' => 'Configure server-side speech provider presets. Stored secrets remain redacted.',
    'stt' => 'Configure server-side speech-to-text provider presets. Stored secrets remain redacted.',
    'global_settings' => 'Inspect registered installations and configure bounded rechat, boredom, and greeting schedules for an active playthrough.',
    'worldknowledge' => 'Create and inspect scoped Morrowind world knowledge.',
    'actions' => 'Inspect the negotiated OpenMW action catalog and create action-policy revisions.',
    'prompts' => 'Create and inspect versioned dialogue prompt configurations.',
    'autonomy' => 'Create and manage narrator, diary, and summary records included in scoped roleplay context.',
    'playthroughs' => 'Create and inspect playthrough state bound to a profile.',
    'request_logs' => 'Inspect bounded source-event and request traces.',
    'relationship_logs' => 'Inspect current relationship state and its latest updates.',
    'jobs' => 'Inspect durable worker jobs, retries, and dead-letter state.',
    'response_queue' => 'Inspect the latest dialogue delivery states and distinguish pending, played, failed, interrupted, and expired responses.',
    'oghma_audit' => 'Inspect bounded Oghma Infinium retrieval traces, result counts, and the deterministic retrieval algorithm.',
    'provider_usage' => 'Review measured provider attempts, byte counts, failures, and latency. ALMSIVI does not fabricate currency costs without recorded token usage and pricing.',
    'provider_attempts' => 'Inspect redacted LLM, TTS, and STT provider attempts.',
    'media_cache' => 'Inspect bounded speech-cache metadata without exposing private audio files or storage paths.',
    'backup_health' => 'Inspect backup records and run bounded retention maintenance.',
    'database_manager' => 'Back up and restore installation configuration, inspect applied schema migrations, and run bounded retention maintenance without accepting filesystem paths.',
    'diagnostics' => 'Inspect redacted operational audit records.',
];

$forms = match ($view) {
    'narrator' => $rows === [] ? [[
        'route'=>'narrator-profile-create','legend'=>'Create narrator profile','fields'=>[
            ['installation_id','Installation','select','',$installationOptions],['name','Roleplay name','text','The Narrator'],
            ['enabled','Enable narrator routing','checkbox','1'],
            ['inline_narration_mode','Inline narration mode','select','Disabled',['Disabled'=>'Disabled','Narrator'=>'Narrator voice','NPC'=>'NPC voice','Text Only'=>'Text only']],
            ['biography','Background','textarea','',[],false],['personality','Personality','textarea','',[],false],
            ['speech_style','Speech style','textarea','',[],false],['goals','Goals','textarea','',[],false],
            ['tts_configuration_id','Narrator TTS connector','select','',$ttsRoutingOptions],
            ['voice_id','TTS voice ID (type or choose a stored sample)','datalist','',$voiceOptions,false],
            ['voice_language','Voice language','text','en'],['notes','Additional notes','textarea','',[],false],
        ],
    ]] : [],
    'player' => $rows === [] ? [[
        'route' => 'player-profile-create', 'legend' => 'Create player profile',
        'fields' => [
            ['installation_id', 'Installation', 'select', '', $installationOptions],
            ['name', 'Player character name'],
            ['appearance', 'Appearance', 'textarea', '', [], false],
            ['biography', 'Biography and backstory', 'textarea', '', [], false],
            ['personality', 'Personality', 'textarea', '', [], false],
            ['goals', 'Goals and motivations', 'textarea', '', [], false],
            ['speech_style', 'Speech style', 'textarea', '', [], false],
            ['notes', 'Additional roleplay notes', 'textarea', '', [], false],
        ],
    ]] : [],
    'characters' => [[
        'route' => 'profile-create', 'legend' => 'Add ALMSIVI NPC', 'hidden'=>['management_fields'=>'1','llm_routing_fields'=>'1'],
        'fields' => [
            ['installation_id', 'Installation', 'select', '', $installationOptions],
            ['name', 'NPC name'], ['record_id', 'Morrowind record ID', 'text', '', [], false],
            ['content_file', 'Content file', 'text', 'Morrowind.esm', [], false],
            ['refnum', 'Reference number', 'text', '', [], false],
            ['prompt_head', 'Prompt head (advanced system guidance)', 'textarea', '', [], false],
            ['core', 'Core identity and boundaries', 'textarea', '', [], false],
            ['appearance', 'Appearance', 'textarea', '', [], false],
            ['gender', 'Gender', 'select', '', [''=>'Unspecified','Male'=>'Male','Female'=>'Female','Other'=>'Other'], false],
            ['race', 'Race', 'text', '', [], false],
            ['biography', 'Biography', 'textarea', '', [], false],
            ['personality', 'Personality', 'textarea', '', [], false],
            ['speech_style', 'Speech style', 'textarea', '', [], false],
            ['occupation', 'Occupation', 'text', '', [], false],
            ['skills', 'Skills and capabilities', 'textarea', '', [], false],
            ['goals', 'Goals', 'textarea', '', [], false],
            ['relationships', 'Relationships', 'textarea', '', [], false],
            ['emote_moods', 'Allowed moods and emotes', 'text', '', [], false],
            ['prompt_configuration_id', 'Dialogue prompt', 'select', '', $promptRoutingOptions],
            ['llm_configuration_id', 'Standard LLM', 'select', '', $llmRoutingOptions],
            ['llm_fast_configuration_id', 'Fast LLM', 'select', '', $llmRoutingOptions],
            ['llm_powerful_configuration_id', 'Powerful LLM', 'select', '', $llmRoutingOptions],
            ['llm_experimental_configuration_id', 'Experimental LLM', 'select', '', $llmRoutingOptions],
            ['llm_randomizer_enabled', 'Randomize configured LLM slots', 'checkbox', '1', [], false],
            ['llm_fallback_configuration_id', 'Fallback LLM', 'select', '', $llmRoutingOptions],
            ['llm_fallback_enabled', 'Use fallback when the selected LLM fails', 'checkbox', '1', [], false],
            ['tts_configuration_id', 'TTS connector', 'select', '', $ttsRoutingOptions],
            ['voice_id', 'TTS voice ID (type or choose a stored sample)', 'datalist', '', $voiceOptions, false],
            ['voice_language', 'Voice language', 'text', 'en'],
            ['locked', 'Lock against automatic AI profile generation', 'checkbox', '1', [], false],
            ['favorite', 'Favorite NPC', 'checkbox', '1', [], false],
            ['notes', 'Notes', 'textarea', '', [], false],
        ],
    ]],
    'profiles' => [
        [
            'route' => 'profile-create', 'legend' => 'Create profile', 'hidden'=>['management_fields'=>'1','llm_routing_fields'=>'1'],
            'fields' => [
                ['installation_id', 'Installation', 'select', '', $installationOptions],
                ['name', 'Profile name'], ['record_id', 'Morrowind record ID', 'text', '', [], false],
                ['content_file', 'Content file', 'text', 'Morrowind.esm', [], false],
                ['refnum', 'Reference number', 'text', '', [], false],
                ['prompt_head', 'Prompt head (advanced system guidance)', 'textarea', '', [], false],
                ['core', 'Core identity and boundaries', 'textarea', '', [], false],
                ['appearance', 'Appearance', 'textarea', '', [], false],
                ['gender', 'Gender', 'select', '', [''=>'Unspecified','Male'=>'Male','Female'=>'Female','Other'=>'Other'], false],
                ['race', 'Race', 'text', '', [], false],
                ['biography', 'Biography', 'textarea', '', [], false],
                ['personality', 'Personality', 'textarea', '', [], false],
                ['speech_style', 'Speech style', 'textarea', '', [], false],
                ['occupation', 'Occupation', 'text', '', [], false],
                ['skills', 'Skills and capabilities', 'textarea', '', [], false],
                ['goals', 'Goals', 'textarea', '', [], false],
                ['relationships', 'Relationships', 'textarea', '', [], false],
                ['emote_moods', 'Allowed moods and emotes', 'text', '', [], false],
                ['prompt_configuration_id', 'Dialogue prompt', 'select', '', $promptRoutingOptions],
                ['llm_configuration_id', 'Standard LLM', 'select', '', $llmRoutingOptions],
                ['llm_fast_configuration_id', 'Fast LLM', 'select', '', $llmRoutingOptions],
                ['llm_powerful_configuration_id', 'Powerful LLM', 'select', '', $llmRoutingOptions],
                ['llm_experimental_configuration_id', 'Experimental LLM', 'select', '', $llmRoutingOptions],
                ['llm_randomizer_enabled', 'Randomize configured LLM slots', 'checkbox', '1', [], false],
                ['llm_fallback_configuration_id', 'Fallback LLM', 'select', '', $llmRoutingOptions],
                ['llm_fallback_enabled', 'Use fallback when the selected LLM fails', 'checkbox', '1', [], false],
                ['tts_configuration_id', 'TTS connector', 'select', '', $ttsRoutingOptions],
                ['voice_id', 'TTS voice ID (type or choose a stored sample)', 'datalist', '', $voiceOptions, false],
                ['voice_language', 'Voice language', 'text', 'en'],
                ['locked', 'Lock against automatic AI profile generation', 'checkbox', '1', [], false],
                ['favorite', 'Favorite NPC', 'checkbox', '1', [], false],
                ['notes', 'Notes', 'textarea', '', [], false],
            ],
        ],
        [
            'route' => 'profile-import', 'legend' => 'Import ALMSIVI profile',
            'fields' => [
                ['installation_id', 'Installation', 'select', '', $installationOptions],
                ['profile_json', 'Portable ALMSIVI profile JSON', 'jsonfile'],
            ],
        ],
    ],
    'llm' => [[
        'route' => 'providers', 'legend' => 'Add LLM model slot',
        'fields' => [
            ['installation_id', 'Installation', 'select', '', $installationOptions],
            ['name', 'Slot name'],
            ['driver', 'Runtime', 'select', 'configured', ['configured'=>'Configured live provider','mock'=>'Deterministic test provider']],
            ['model', 'Model', 'text', (string)($config['provider']['model']??'')],
            ['mock_prefix', 'Test response prefix', 'text', '', [], false],
        ],
    ],[
        'route'=>'provider-import','legend'=>'Import LLM model slot',
        'fields'=>[['installation_id','Installation','select','',$installationOptions],['provider_json','Portable ALMSIVI model-slot JSON','jsonfile']],
    ]],
    'tts', 'stt' => [[
        'route' => $view . '-providers', 'legend' => 'Add ' . strtoupper($view) . ' connector',
        'hidden'=>['option_fields_present'=>'1'],
        'fields' => [
            ['installation_id', 'Installation', 'select', '', $installationOptions],
            ['name', 'Preset name'],
            ['driver', 'Connector', 'select', $defaultConnectorDriver, $connectorOptions],
            ['endpoint', 'Endpoint', 'url', (string)($defaultConnectorValues['endpoint']??'')],
            ['model', 'Model or engine', 'text', (string)($defaultConnectorValues['model']??'default')],
            ['voice', 'Default voice (type or choose a stored sample)', 'datalist', (string)($defaultConnectorValues['voice']??''), $voiceOptions],
            ['language', 'Language', 'text', (string)($defaultConnectorValues['language']??'en')],
            ['timeout_ms', 'Timeout in milliseconds', 'number', '30000'],
            ...($view==='tts'?[['fallback_male','Male fallback voice ID','datalist','',$voiceOptions,false],
                ['fallback_female','Female fallback voice ID','datalist','',$voiceOptions,false]]:[]),
            ['connector_options','Provider-specific options','connector-options',$defaultConnectorDriver,$connectorOptionCatalog,false,[],$connectorDefaults],
            ['options_json', 'Advanced connector options (JSON)', 'textarea', '{}'],
        ],
    ],[
        'route'=>'connector-import','legend'=>'Import '.strtoupper($view).' connector','hidden'=>['kind'=>$view.'_provider'],
        'fields'=>[['installation_id','Installation','select','',$installationOptions],['connector_json','Portable ALMSIVI connector JSON','jsonfile']],
    ]],
    'global_settings' => [[
        'route'=>'autonomy','legend'=>'Save rechat, boredom, or greeting schedule','fields'=>[
            ['installation_id','Installation','select','',$installationOptions],['profile_id','Profile','select','',$profileOptions],
            ['playthrough_id','Playthrough','select','',$playthroughOptions],
            ['kind','Behavior','select','rechat',['rechat'=>'Rechat','boredom'=>'Bored event','greeting'=>'Greeting']],
            ['interval_seconds','Interval seconds','number','300'],['cooldown_seconds','Cooldown seconds','number','300'],
            ['current_session_id','Active game session','select','',$sessionOptions,false],
            ['enabled','Enable after active-session confirmation','checkbox','1'],
        ],
    ]],
    'worldknowledge' => [[
        'route' => 'knowledge', 'legend' => 'Add world knowledge',
        'fields' => [['installation_id', 'Installation', 'select', '', $installationOptions], ['profile_id', 'Profile', 'select', '', $profileOptions], ['playthrough_id', 'Playthrough', 'select', '', $playthroughOptions], ['title', 'Title'], ['content', 'Knowledge', 'textarea'], ['provenance', 'Provenance source', 'text', 'management']],
    ]],
    'actions' => [[
        'route' => 'action-policies', 'legend' => 'Create action policy',
        'fields' => [['installation_id', 'Installation', 'select', '', $installationOptions], ['name', 'Policy name'], ['content_json', 'Policy JSON', 'textarea', '{"enabled":true,"max_tier":1,"denied_actions":[]}']],
    ]],
    'prompts' => [[
        'route' => 'prompts', 'legend' => 'Create prompt',
        'fields' => [['installation_id', 'Installation', 'select', '', $installationOptions], ['name', 'Prompt name'], ['content_json', 'Prompt document (JSON)', 'textarea', '{"instruction":"Respond in character using only scoped context."}']],
    ],[
        'route'=>'prompt-import','legend'=>'Import prompt',
        'fields'=>[['installation_id','Installation','select','',$installationOptions],['prompt_json','Portable ALMSIVI prompt JSON','jsonfile']],
    ]],
    'autonomy' => [
        [
            'route' => 'narratives', 'legend' => 'Create narrative',
            'fields' => [['installation_id', 'Installation', 'select', '', $installationOptions], ['profile_id', 'Profile', 'select', '', $profileOptions], ['playthrough_id', 'Playthrough', 'select', '', $playthroughOptions], ['kind', 'Narrative kind', 'select', 'narrator', ['narrator', 'diary', 'summary']], ['title', 'Title'], ['content', 'Narrative', 'textarea'], ['provenance', 'Provenance source', 'text', 'management']],
        ],
    ],
    'playthroughs' => [
        [
            'route' => 'playthroughs', 'legend' => 'Create playthrough',
            'fields' => [['installation_id', 'Installation', 'select', '', $installationOptions], ['profile_id', 'Profile', 'select', '', $profileOptions], ['name', 'Playthrough name'], ['content_json', 'Playthrough JSON', 'textarea', '{}']],
        ],
        [
            'route' => 'playthrough-import', 'legend' => 'Restore playthrough backup',
            'fields' => [['installation_id', 'Installation', 'select', '', $installationOptions], ['profile_id', 'Profile', 'select', '', $profileOptions], ['playthrough_id', 'Destination playthrough', 'select', '', $playthroughOptions], ['playthrough_json', 'ALMSIVI playthrough backup JSON', 'textarea']],
        ],
    ],
    'backup_health' => [[
        'route' => 'retention', 'legend' => 'Run bounded retention',
        'fields' => [['days', 'Retention days', 'number', '30']],
    ]],
    'database_manager' => array_values(array_filter([[
        'route'=>'configuration-backup','legend'=>'Create configuration backup','fields'=>[
            ['installation_id','Installation','select','',$installationOptions],['confirm','Type Backup to confirm','text','']],
    ],$configurationBackupOptions===[]?null:[
        'route'=>'configuration-restore','legend'=>'Restore configuration backup','fields'=>[
            ['installation_id','Destination installation','select','',$installationOptions],['backup_id','Stored backup','select','',$configurationBackupOptions],
            ['confirm','Type Restore to confirm','text','']],
    ],[
        'route'=>'retention','legend'=>'Run bounded retention','fields'=>[['days','Retention days','number','30']],
    ]])),
    default => [],
};

/** Render one CSRF-protected management form with explicit labels for every control. */
function almsivi_ui_management_form(array $form, string $managementBasePath, string $csrf): void
{
    $formId=(string)($form['id']??$form['route']);
    echo '<form class="management-form" method="post" action="' . almsivi_ui_h($managementBasePath . '/forms/' . $form['route']) . '"><fieldset><legend>' . almsivi_ui_h($form['legend']) . '</legend>';
    foreach ($form['fields'] as $field) {
        [$name, $label] = $field;
        $type = $field[2] ?? 'text';
        $value = $field[3] ?? '';
        $required = $field[5] ?? true;
        $id = 'field-' . $formId . '-' . $name;
        if ($type === 'checkbox') {
            $checked=($field[6]??false)===true?' checked':'';
            echo '<label><input name="' . almsivi_ui_h($name) . '" type="checkbox" value="' . almsivi_ui_h($value) . '"'.$checked.'> ' . almsivi_ui_h($label) . '</label>';
            continue;
        }
        if($type==='connector-options'){
            $catalog=is_array($field[4]??null)?$field[4]:[];$current=is_array($field[6]??null)?$field[6]:[];$defaults=is_array($field[7]??null)?$field[7]:[];
            $defaultAttribute=$defaults===[]?'':' data-connector-defaults="'.almsivi_ui_h(json_encode($defaults,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)).'"';
            echo'<div id="'.almsivi_ui_h($id).'" class="connector-option-editor" data-connector-options data-driver-control="'.almsivi_ui_h('field-'.$formId.'-driver').'"'.$defaultAttribute.'><p><strong>'.almsivi_ui_h($label).'</strong></p>';
            foreach($catalog as$driver=>$optionFields){$active=(string)$driver===(string)$value;$fields=is_array($optionFields)?$optionFields:[];
                echo'<div class="connector-option-set" data-connector-driver="'.almsivi_ui_h($driver).'"'.($active?'':' hidden').'>';
                foreach($fields as$optionField){$optionName=(string)($optionField['name']??'');$optionType=(string)($optionField['type']??'string');if($optionName==='')continue;
                    $optionId=$id.'-'.$driver.'-'.$optionName;$optionValue=$current[$optionName]??'';$disabled=$active?'':' disabled';
                    if($optionType==='boolean')echo'<label><input id="'.almsivi_ui_h($optionId).'" name="option__'.almsivi_ui_h($optionName).'" type="checkbox" value="1"'.($optionValue===true?' checked':'').$disabled.'> '.almsivi_ui_h($optionField['label']??$optionName).'</label>';
                    else{echo'<label for="'.almsivi_ui_h($optionId).'">'.almsivi_ui_h($optionField['label']??$optionName).'</label>';
                        if($optionType==='select'){echo'<select id="'.almsivi_ui_h($optionId).'" name="option__'.almsivi_ui_h($optionName).'"'.$disabled.'><option value="">Connector default</option>';
                            foreach(($optionField['values']??[])as$choice)echo'<option value="'.almsivi_ui_h($choice).'"'.((string)$choice===(string)$optionValue?' selected':'').'>'.almsivi_ui_h(ucwords(str_replace(['_','-'],' ',(string)$choice))).'</option>';echo'</select>';}
                        elseif(in_array($optionType,['number','integer'],true))echo'<input id="'.almsivi_ui_h($optionId).'" name="option__'.almsivi_ui_h($optionName).'" type="number" value="'.almsivi_ui_h($optionValue).'" min="'.almsivi_ui_h($optionField['minimum']??'').'" max="'.almsivi_ui_h($optionField['maximum']??'').'" step="'.($optionType==='integer'?'1':'any').'"'.$disabled.'>';
                        else echo'<input id="'.almsivi_ui_h($optionId).'" name="option__'.almsivi_ui_h($optionName).'" type="text" value="'.almsivi_ui_h($optionValue).'" maxlength="512"'.$disabled.'>';
                    }
                }
                echo'</div>';
            }
            $selectedFields=is_array($catalog[(string)$value]??null)?$catalog[(string)$value]:[];
            echo'<p class="management-note" data-connector-options-empty'.($selectedFields===[]?'':' hidden').'>This connector has no additional labelled options.</p></div>';continue;
        }
        echo '<label for="' . almsivi_ui_h($id) . '">' . almsivi_ui_h($label) . '</label>';
        if ($type === 'textarea') {
            echo '<textarea id="' . almsivi_ui_h($id) . '" name="' . almsivi_ui_h($name) . '"' . ($required ? ' required' : '') . '>' . almsivi_ui_h($value) . '</textarea>';
        } elseif ($type === 'jsonfile') {
            $fileId=$id.'-file';
            echo '<label for="'.almsivi_ui_h($fileId).'">Choose JSON file</label><input id="'.almsivi_ui_h($fileId).'" name="'.almsivi_ui_h($name).'_file" type="file" accept="application/json,.json" data-json-import-target="'.almsivi_ui_h($id).'">';
            echo '<textarea id="'.almsivi_ui_h($id).'" name="'.almsivi_ui_h($name).'" required placeholder="Choose a JSON file or paste its contents here.">'.almsivi_ui_h($value).'</textarea>';
        } elseif ($type === 'select') {
            echo '<select id="' . almsivi_ui_h($id) . '" name="' . almsivi_ui_h($name) . '">';
            foreach (($field[4] ?? []) as $optionValue => $optionLabel) {
                if (is_int($optionValue)) $optionValue = $optionLabel;
                echo '<option value="' . almsivi_ui_h($optionValue) . '"' . ((string) $optionValue === (string) $value ? ' selected' : '') . '>' . almsivi_ui_h($optionLabel) . '</option>';
            }
            echo '</select>';
        } elseif ($type === 'datalist') {
            $listId = $id . '-options';
            echo '<input id="' . almsivi_ui_h($id) . '" name="' . almsivi_ui_h($name) . '" type="text" list="' . almsivi_ui_h($listId) . '" value="' . almsivi_ui_h($value) . '"' . ($required ? ' required' : '') . '>';
            echo '<datalist id="' . almsivi_ui_h($listId) . '">';
            foreach (($field[4] ?? []) as $optionValue => $optionLabel) {
                if (is_int($optionValue)) $optionValue = $optionLabel;
                echo '<option value="' . almsivi_ui_h($optionValue) . '">' . almsivi_ui_h($optionLabel) . '</option>';
            }
            echo '</datalist>';
        } else {
            echo '<input id="' . almsivi_ui_h($id) . '" name="' . almsivi_ui_h($name) . '" type="' . almsivi_ui_h($type) . '" value="' . almsivi_ui_h($value) . '"' . ($required ? ' required' : '') . '>';
        }
    }
    foreach (($form['hidden'] ?? []) as $name => $value) echo '<input type="hidden" name="' . almsivi_ui_h($name) . '" value="' . almsivi_ui_h($value) . '">';
    echo '</fieldset><input type="hidden" name="_csrf" value="' . almsivi_ui_h($csrf) . '"><button class="btn-base btn-primary" type="submit">' . almsivi_ui_h($form['legend']) . '</button></form>';
}

/** Render bounded rollback and soft-delete controls for one revisioned record. */
function almsivi_ui_revision_actions(string $resource, string $id, array $revisions, int $currentRevision, string $managementBasePath, string $csrf, ?string $kind = null,bool $allowDelete=true,string $deleteBlockedReason=''): void
{
    $options=[];foreach($revisions as$revision){$number=(int)($revision['revision']??0);if($number>0&&$number!==$currentRevision)$options[(string)$number]='Revision '.$number.' - '.(string)($revision['reason']??'saved');}
    echo '<div class="revision-actions">';
    if($options!==[])almsivi_ui_management_form(['route'=>$resource.'-rollback','id'=>$resource.'-rollback-'.$id,'legend'=>'Restore earlier revision',
        'hidden'=>[$resource==='profile'?'profile_id':'configuration_id'=>$id]+($kind===null?[]:['kind'=>$kind]),
        'fields'=>[['revision','Revision','select','', $options]]],$managementBasePath,$csrf);
    if($allowDelete){echo '<form class="danger-form" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/'.$resource.'-delete').'">';
        echo '<input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="'.($resource==='profile'?'profile_id':'configuration_id').'" value="'.almsivi_ui_h($id).'">';
        if($kind!==null)echo '<input type="hidden" name="kind" value="'.almsivi_ui_h($kind).'">';
        echo '<button class="btn-base btn-danger" type="submit">Delete '.almsivi_ui_h($resource).'</button></form>';}
    elseif($deleteBlockedReason!=='')echo'<p class="management-note">'.almsivi_ui_h($deleteBlockedReason).'</p>';
    echo'</div>';
}

/** Build installation-scoped connector choices for a profile editor without exposing another install's presets. */
function almsivi_ui_profile_connector_options(array $rows,string $installationId,string $fallback,string $detailField):array
{
    $options=[''=>$fallback];
    foreach($rows as$row){if((string)($row['installation_id']??'')!==$installationId)continue;
        $id=(string)($row['configuration_id']??'');$content=is_array($row['content']??null)?$row['content']:[];
        $detail=$detailField===''?'':trim((string)($content[$detailField]??''));
        if($id!=='')$options[$id]=(string)($row['name']??$id).($detail===''?'':' - '.$detail);}
    return$options;
}

/** Apply bounded CHIM-style NPC search and management filters to loaded profile rows. */
function almsivi_ui_filter_profiles(array $rows):array
{
    $query=mb_strtolower(mb_substr(trim((string)($_GET['q']??'')),0,100));
    $initial=strtoupper((string)($_GET['initial']??''));if(preg_match('/^[A-Z]$/D',$initial)!==1)$initial='';
    $state=(string)($_GET['state']??'all');if(!in_array($state,['all','favorites','locked','unlocked','generated'],true))$state='all';
    $installation=(string)($_GET['installation_id']??'');
    $filtered=array_values(array_filter($rows,static function(array$row)use($query,$initial,$state,$installation):bool{
        $content=is_array($row['content']??null)?$row['content']:[];$identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];
        $management=is_array($content['management']??null)?$content['management']:[];
        $name=(string)($row['name']??'');
        if($installation!==''&&!hash_equals($installation,(string)($row['installation_id']??'')))return false;
        if($initial!==''&&strtoupper(mb_substr($name,0,1))!==$initial)return false;
        if($state==='favorites'&&($management['favorite']??false)!==true)return false;
        if($state==='locked'&&($management['locked']??false)!==true)return false;
        if($state==='unlocked'&&($management['locked']??false)===true)return false;
        if($state==='generated'){$generated=false;foreach(($row['revisions']??[])as$revision)if(str_contains(mb_strtolower((string)($revision['reason']??'')),'ai profile generation')){$generated=true;break;}if(!$generated)return false;}
        if($query==='')return true;
        $haystack=mb_strtolower(implode("\n",[$name,(string)($identity['record_id']??''),(string)($identity['content_file']??''),
            (string)($content['prompt_head']??''),(string)($content['core']??''),(string)($content['appearance']??''),
            (string)($content['biography']??''),(string)($content['personality']??''),(string)($content['speech_style']??''),
            (string)($content['occupation']??''),(string)($content['skills']??''),(string)($content['emote_moods']??''),(string)($content['notes']??''),
            (string)((is_array($content['voice']??null)?$content['voice']['id']??'':$content['voice']??''))]));
        return str_contains($haystack,$query);
    }));
    usort($filtered,static function(array$a,array$b):int{
        $am=is_array(($a['content']??[])['management']??null)?$a['content']['management']:[];
        $bm=is_array(($b['content']??[])['management']??null)?$b['content']['management']:[];
        $favorite=(int)(($bm['favorite']??false)===true)<=>(int)(($am['favorite']??false)===true);
        return$favorite!==0?$favorite:strnatcasecmp((string)($a['name']??''),(string)($b['name']??''));
    });
    return$filtered;
}

/** Render the searchable NPC-manager controls without exposing profile content in the query string. */
function almsivi_ui_profile_filters(array $rows):void
{
    $installations=[];foreach($rows as$row){$id=(string)($row['installation_id']??'');if($id!=='')$installations[$id]=$id;}
    echo'<form class="profile-management-filters" method="get"><label for="profile-filter-q">Search NPCs<input id="profile-filter-q" type="search" name="q" maxlength="100" value="'.almsivi_ui_h($_GET['q']??'').'" placeholder="Name, record, voice, biography"></label>';
    echo'<label for="profile-filter-initial">Starts with<select id="profile-filter-initial" name="initial"><option value="">All letters</option>';foreach(range('A','Z')as$letter)echo'<option value="'.$letter.'"'.(($_GET['initial']??'')===$letter?' selected':'').'>'.$letter.'</option>';echo'</select></label>';
    $states=['all'=>'All NPCs','favorites'=>'Favorites','locked'=>'Locked','unlocked'=>'Unlocked','generated'=>'AI generated'];
    echo'<label for="profile-filter-state">Status<select id="profile-filter-state" name="state">';foreach($states as$value=>$label)echo'<option value="'.$value.'"'.(($_GET['state']??'all')===$value?' selected':'').'>'.$label.'</option>';echo'</select></label>';
    if(count($installations)>1){echo'<label for="profile-filter-installation">Installation<select id="profile-filter-installation" name="installation_id"><option value="">All installations</option>';foreach($installations as$id)echo'<option value="'.almsivi_ui_h($id).'"'.(($_GET['installation_id']??'')===$id?' selected':'').'>'.almsivi_ui_h($id).'</option>';echo'</select></label>';}
    if(($_GET['embed']??'')==='1')echo'<input type="hidden" name="embed" value="1">';
    echo'<button class="btn-base btn-primary" type="submit">Filter NPCs</button><a class="btn-base" href="?'.(($_GET['embed']??'')==='1'?'embed=1':'').'">Clear</a></form>';
}

/** Render installation-scoped bulk tools with explicit typed confirmations. */
function almsivi_ui_bulk_profile_tools(array $rows,array $installationOptions,string $managementBasePath,string $csrf):void
{
    if($rows===[]||$installationOptions===[])return;
    $profileOptions=[];foreach($rows as$row){$id=(string)($row['profile_id']??'');if($id==='')continue;
        $label=(string)($row['name']??$id);if(count($installationOptions)>1)$label.=' - '.substr((string)($row['installation_id']??''),0,8);
        $profileOptions[$id]=$label;}
    echo'<div class="bulk-profile-tools">';
    echo'<details><summary>Generate unlocked NPC profiles with AI</summary><p>Queues revision-safe generation for up to 100 unlocked NPC profiles in the selected installation. Locked, player, and narrator profiles are excluded; duplicate current-revision jobs remain idempotent.</p>';
    almsivi_ui_management_form(['route'=>'profile-bulk-generate','id'=>'profile-bulk-generate','legend'=>'Generate unlocked NPC profiles','fields'=>[
        ['installation_id','Installation','select','',$installationOptions],['confirm','Type Generate to confirm']]],$managementBasePath,$csrf);echo'</details>';
    echo'<details><summary>Unlock all NPC profiles</summary><p>Creates an unlocked revision for every locked NPC profile in the selected installation. Player and narrator profiles are excluded.</p>';
    almsivi_ui_management_form(['route'=>'profile-bulk-unlock','id'=>'profile-bulk-unlock','legend'=>'Unlock all NPC profiles','fields'=>[
        ['installation_id','Installation','select','',$installationOptions],['confirm','Type Unlock to confirm']]],$managementBasePath,$csrf);echo'</details>';
    if(count($profileOptions)>=2){$ids=array_keys($profileOptions);echo'<details><summary>Mass switch bound NPC profiles</summary><p>Moves all OpenMW actor bindings from one profile to another in the selected installation. Locked source profiles are skipped unless explicitly included.</p>';
        almsivi_ui_management_form(['route'=>'profile-bulk-switch','id'=>'profile-bulk-switch','legend'=>'Switch bound NPC profiles','fields'=>[
            ['installation_id','Installation','select','',$installationOptions],['source_profile_id','From profile','select',$ids[0],$profileOptions],
            ['target_profile_id','To profile','select',$ids[1],$profileOptions],['include_locked','Include a locked source profile','checkbox','1',[],false],
            ['confirm','Type Switch to confirm']]],$managementBasePath,$csrf);echo'</details>';}
    echo'<details class="bulk-danger"><summary>Delete all unlocked NPC profiles</summary><p>Soft-deletes every unlocked NPC profile in the selected installation and clears its OpenMW actor bindings. Locked, player, and narrator profiles are preserved.</p>';
    almsivi_ui_management_form(['route'=>'profile-bulk-delete','id'=>'profile-bulk-delete','legend'=>'Delete all unlocked NPC profiles','fields'=>[
        ['installation_id','Installation','select','',$installationOptions],['confirm','Type Delete to confirm']]],$managementBasePath,$csrf);echo'</details></div>';
}

/** Render the installation-scoped auto-lock preference used by manual NPC saves. */
function almsivi_ui_profile_preferences(array $rows,string $managementBasePath,string $csrf):void
{
    if($rows===[])return;echo'<div class="profile-preference-grid">';foreach($rows as$row){$id=(string)($row['installation_id']??'');if($id==='')continue;
        $enabled=in_array($row['auto_lock_on_edit']??true,[true,1,'1','t','true'],true);echo'<article><h4>'.almsivi_ui_h($row['display_name']??$id).'</h4>';
        almsivi_ui_management_form(['route'=>'profile-auto-lock','id'=>'profile-auto-lock-'.$id,'legend'=>'Save auto-lock preference','hidden'=>['installation_id'=>$id],
            'fields'=>[['enabled','Auto-lock NPC profiles after manual edits','checkbox','1',[],false,$enabled]]],$managementBasePath,$csrf);
        echo'<p class="management-note">When enabled, a management edit locks the saved profile against later automatic AI biography generation. Bulk unlock remains available.</p></article>';}
    echo'</div>';
}

/** Render versioned NPC profiles using the same compact editor fields consumed by the prompt and TTS runtime. */
function almsivi_ui_profile_cards(array $rows,array $voiceOptions,array $promptRows,array $llmRows,array $ttsRows,string $managementBasePath,string $csrf):void
{
    if ($rows === []) { echo '<p class="empty-state">No NPC profiles are configured yet.</p>'; return; }
    $allCount=count($rows);almsivi_ui_profile_filters($rows);$rows=almsivi_ui_filter_profiles($rows);
    echo'<p class="management-note">Showing '.count($rows).' of '.$allCount.' NPC profiles. Favorites are shown first.</p>';
    if($rows===[]){echo'<p class="empty-state">No NPC profiles match these filters.</p>';return;}
    echo '<div class="profile-grid">';
    foreach ($rows as $row) {
        $content = is_array($row['content'] ?? null) ? $row['content'] : [];
        $identity = is_array($row['actor_identity'] ?? null) ? $row['actor_identity'] : [];
        $voice = $content['voice'] ?? [];
        if (is_string($voice)) $voice = ['id' => $voice];
        if (!is_array($voice)) $voice = [];
        $profileId = (string) ($row['profile_id'] ?? '');
        $installationId=(string)($row['installation_id']??'');
        $management=is_array($content['management']??null)?$content['management']:[];
        $locked=($management['locked']??false)===true;$favorite=($management['favorite']??false)===true;
        $portrait=is_array($content['portrait']??null)?$content['portrait']:[];
        $portraitEndpoint=preg_replace('#/manage$#','/ui/core/profile_portrait.php',$managementBasePath)?:'/ALMSIVIserver/ui/core/profile_portrait.php';
        $routing=is_array($content['routing']??null)&&!array_is_list($content['routing'])?$content['routing']:[];
        $promptId=(string)($routing['prompt_configuration_id']??'');$llmId=(string)($routing['llm_configuration_id']??'');
        $fastLlmId=(string)($routing['llm_fast_configuration_id']??'');$powerfulLlmId=(string)($routing['llm_powerful_configuration_id']??'');
        $experimentalLlmId=(string)($routing['llm_experimental_configuration_id']??'');$fallbackLlmId=(string)($routing['llm_fallback_configuration_id']??'');
        $randomizerEnabled=($routing['llm_randomizer_enabled']??false)===true;$fallbackEnabled=($routing['llm_fallback_enabled']??false)===true;
        $ttsId=(string)($routing['tts_configuration_id']??'');
        $promptOptions=almsivi_ui_profile_connector_options($promptRows,$installationId,'Use the first applicable installation prompt','');
        $llmOptions=almsivi_ui_profile_connector_options($llmRows,$installationId,'Use the current session model slot','model');
        $ttsOptions=almsivi_ui_profile_connector_options($ttsRows,$installationId,'Use the active installation TTS connector','driver');
        if($promptId!==''&&!isset($promptOptions[$promptId]))$promptOptions[$promptId]='Unavailable prompt';
        foreach([$llmId,$fastLlmId,$powerfulLlmId,$experimentalLlmId,$fallbackLlmId]as$routeId)if($routeId!==''&&!isset($llmOptions[$routeId]))$llmOptions[$routeId]='Unavailable model slot';
        if($ttsId!==''&&!isset($ttsOptions[$ttsId]))$ttsOptions[$ttsId]='Unavailable TTS connector';
        echo '<article class="profile-card"><header><div><span class="connector-kind">OpenMW NPC</span><h3>' . almsivi_ui_h($row['name'] ?? '') . '</h3></div>';
        echo '<div class="profile-statuses">'.($favorite?'<span class="status-badge connector-active">Favorite</span>':'').($locked?'<span class="status-badge profile-locked">Locked</span>':'').'<span class="status-badge">Revision ' . almsivi_ui_h($row['current_revision'] ?? '') . '</span></div></header>';
        if($portrait!==[])echo'<div class="profile-portrait"><img src="'.almsivi_ui_h($portraitEndpoint.'?profile_id='.rawurlencode($profileId).'&revision='.(int)($row['current_revision']??1)).'" alt="Portrait of '.almsivi_ui_h($row['name']??'NPC').'" width="160" height="160"></div>';
        echo '<dl><dt>Record</dt><dd><code>' . almsivi_ui_h($identity['record_id'] ?? 'Unbound template') . '</code></dd>';
        echo '<dt>Content file</dt><dd>' . almsivi_ui_h($identity['content_file'] ?? '') . '</dd>';
        echo '<dt>Gender / race</dt><dd>' . almsivi_ui_h(trim((string)($content['gender']??'').' '.(string)($content['race']??''))?:'Unspecified') . '</dd>';
        echo '<dt>Dialogue prompt</dt><dd>' . almsivi_ui_h($promptOptions[$promptId]??$promptOptions['']) . '</dd>';
        echo '<dt>Standard LLM</dt><dd>' . almsivi_ui_h($llmOptions[$llmId]??$llmOptions['']) . '</dd>';
        echo '<dt>LLM routing</dt><dd>' . almsivi_ui_h($randomizerEnabled?'Randomized configured slots':'Standard slot') . '</dd>';
        echo '<dt>Fallback LLM</dt><dd>' . almsivi_ui_h($fallbackEnabled?($llmOptions[$fallbackLlmId]??$llmOptions['']):'Disabled') . '</dd>';
        echo '<dt>TTS connector</dt><dd>' . almsivi_ui_h($ttsOptions[$ttsId]??$ttsOptions['']) . '</dd>';
        echo '<dt>Voice</dt><dd>' . almsivi_ui_h($voice['id'] ?? 'Connector default') . '</dd>';
        echo '<dt>In-game bindings</dt><dd>' . almsivi_ui_h($row['binding_count'] ?? 0) . '</dd></dl>';
        echo '<details><summary>Edit roleplay and voice</summary>';
        almsivi_ui_management_form([
            'route'=>'profile-revise','id'=>'profile-' . $profileId,'legend'=>'Save NPC profile revision',
            'hidden'=>['profile_id'=>$profileId,'base_content_json'=>json_encode($content===[]?(object)[]:$content,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'management_fields'=>'1','llm_routing_fields'=>'1'],
            'fields'=>[
                ['prompt_head','Prompt head (advanced system guidance)','textarea',(string)($content['prompt_head']??''),[],false],
                ['core','Core identity and boundaries','textarea',(string)($content['core']??''),[],false],
                ['appearance','Appearance','textarea',(string)($content['appearance']??''),[],false],
                ['gender','Gender','select',(string)($content['gender']??''),[''=>'Unspecified','Male'=>'Male','Female'=>'Female','Other'=>'Other'],false],
                ['race','Race','text',(string)($content['race']??''),[],false],
                ['biography','Biography','textarea',(string)($content['biography']??''),[],false],
                ['personality','Personality','textarea',(string)($content['personality']??''),[],false],
                ['speech_style','Speech style','textarea',(string)($content['speech_style']??''),[],false],
                ['occupation','Occupation','text',(string)($content['occupation']??''),[],false],
                ['skills','Skills and capabilities','textarea',(string)($content['skills']??''),[],false],
                ['goals','Goals','textarea',(string)($content['goals']??''),[],false],
                ['relationships','Relationships','textarea',(string)($content['relationships']??''),[],false],
                ['emote_moods','Allowed moods and emotes','text',(string)($content['emote_moods']??''),[],false],
                ['prompt_configuration_id','Dialogue prompt','select',$promptId,$promptOptions],
                ['llm_configuration_id','Standard LLM','select',$llmId,$llmOptions],
                ['llm_fast_configuration_id','Fast LLM','select',$fastLlmId,$llmOptions],
                ['llm_powerful_configuration_id','Powerful LLM','select',$powerfulLlmId,$llmOptions],
                ['llm_experimental_configuration_id','Experimental LLM','select',$experimentalLlmId,$llmOptions],
                ['llm_randomizer_enabled','Randomize configured LLM slots','checkbox','1',[],false,$randomizerEnabled],
                ['llm_fallback_configuration_id','Fallback LLM','select',$fallbackLlmId,$llmOptions],
                ['llm_fallback_enabled','Use fallback when the selected LLM fails','checkbox','1',[],false,$fallbackEnabled],
                ['tts_configuration_id','TTS connector','select',$ttsId,$ttsOptions],
                ['voice_id','TTS voice ID (type or choose a stored sample)','datalist',(string)($voice['id']??''),$voiceOptions,false],
                ['voice_language','Voice language','text',(string)($voice['language']??'en')],
                ['locked','Lock against automatic AI profile generation','checkbox','1',[],false,$locked],
                ['favorite','Favorite NPC','checkbox','1',[],false,$favorite],
                ['notes','Notes','textarea',(string)($content['notes']??''),[],false],
                ['change_reason','Change reason','text','management edit'],
            ],
        ],$managementBasePath,$csrf);
        echo '</details>';
        $portraitControl='portrait-'.substr(hash('sha256',$profileId),0,12);
        echo'<details><summary>Manage portrait</summary><form class="management-form portrait-form" method="post" enctype="multipart/form-data" action="'.almsivi_ui_h($portraitEndpoint).'"><fieldset><legend>Upload NPC portrait</legend>';
        echo'<label for="'.almsivi_ui_h($portraitControl).'">PNG, JPEG, or WebP (5 MiB and 2048×2048 maximum)</label><input id="'.almsivi_ui_h($portraitControl).'" name="portrait" type="file" accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp" required>';
        echo'<input type="hidden" name="profile_id" value="'.almsivi_ui_h($profileId).'"><input type="hidden" name="action" value="upload"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"></fieldset><button class="btn-base btn-primary" type="submit">Upload portrait</button></form>';
        if($portrait!==[])echo'<form method="post" action="'.almsivi_ui_h($portraitEndpoint).'"><input type="hidden" name="profile_id" value="'.almsivi_ui_h($profileId).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base btn-danger" type="submit">Delete portrait</button></form>';
        echo'</details>';
        echo'<details><summary>Clone profile</summary>';
        almsivi_ui_management_form(['route'=>'profile-clone','id'=>'profile-clone-'.$profileId,'legend'=>'Create independent profile copy',
            'hidden'=>['profile_id'=>$profileId],'fields'=>[['name','New profile name','text',(string)($row['name']??'').' Copy']]],$managementBasePath,$csrf);
        echo'<p class="management-note">The copy starts at revision 1 with the same roleplay and connector settings. Actor bindings and the private portrait file stay with the original.</p></details>';
        echo '<div class="connector-actions"><a class="btn-base" href="'.almsivi_ui_h($managementBasePath.'/exports/profiles/'.$profileId.'.json').'">Export profile</a>';
        if(!$locked)echo '<form method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/profile-generate').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.almsivi_ui_h($profileId).'"><button class="btn-base btn-primary" type="submit">Generate profile with AI</button></form>';
        echo'</div>';
        echo '<p class="management-note">'.($locked?'This profile is locked. Automatic AI generation cannot replace it; manual edits and relationship updates remain available.':'Generation creates a new revision and preserves the configured voice and custom fields. A later manual edit wins if the job is still running.').'</p>';
        almsivi_ui_revision_actions('profile',$profileId,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf);
        echo '</article>';
    }
    echo '</div>';
}

/** Render the one player profile editor whose current revision is added to every dialogue prompt. */
function almsivi_ui_player_cards(array $rows, string $managementBasePath, string $csrf): void
{
    if($rows===[]){echo'<p class="empty-state">No player profile is configured yet. Use the form above to create one.</p>';return;}
    echo'<div class="profile-grid">';
    foreach($rows as$row){
        $content=is_array($row['content']??null)?$row['content']:[];$identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];
        $profileId=(string)($row['profile_id']??'');
        echo'<article class="profile-card"><header><div><span class="connector-kind">OpenMW Player</span><h3>'.almsivi_ui_h($identity['display_name']??$row['name']??'Player').'</h3></div>';
        echo'<span class="status-badge">Revision '.almsivi_ui_h($row['current_revision']??'').'</span></header>';
        echo'<p>This profile is server-owned and included automatically when ALMSIVI assembles an NPC conversation prompt.</p>';
        $inputCount=(int)($row['input_count']??0);echo'<dl><dt>Recent player inputs available</dt><dd>'.almsivi_ui_h(min(200,$inputCount)).'</dd></dl>';
        if($inputCount>0)echo'<form class="connector-test" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/player-speech-style-generate').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.almsivi_ui_h($profileId).'"><button class="btn-base" type="submit">Generate speech style from recent inputs</button></form>';
        else echo'<p class="management-note">Speech-style generation becomes available after ALMSIVI records at least one real player turn.</p>';
        almsivi_ui_management_form([
            'route'=>'player-profile-revise','id'=>'player-'.$profileId,'legend'=>'Save player profile revision',
            'hidden'=>['profile_id'=>$profileId,'base_content_json'=>json_encode($content===[]?(object)[]:$content,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)],
            'fields'=>[
                ['appearance','Appearance','textarea',(string)($content['appearance']??''),[],false],
                ['biography','Biography and backstory','textarea',(string)($content['biography']??''),[],false],
                ['personality','Personality','textarea',(string)($content['personality']??''),[],false],
                ['goals','Goals and motivations','textarea',(string)($content['goals']??''),[],false],
                ['speech_style','Speech style','textarea',(string)($content['speech_style']??''),[],false],
                ['notes','Additional roleplay notes','textarea',(string)($content['notes']??''),[],false],
                ['change_reason','Change reason','text','management edit'],
            ],
        ],$managementBasePath,$csrf);
        almsivi_ui_revision_actions('profile',$profileId,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf);
        echo'</article>';
    }
    echo'</div>';
}

/** Render the installation narrator as a focused opt-in profile and playback-routing editor. */
function almsivi_ui_narrator_cards(array $rows,array $voiceOptions,array $ttsRows,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo'<p class="empty-state">No narrator profile is configured. Narrator routing remains disabled.</p>';return;}
    echo'<div class="profile-grid">';foreach($rows as$row){$content=is_array($row['content']??null)?$row['content']:[];
          $identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];$voice=$content['voice']??[];
          if(is_string($voice))$voice=['id'=>$voice];if(!is_array($voice))$voice=[];$id=(string)($row['profile_id']??'');
          $enabled=($content['enabled']??false)===true;$mode=(string)($content['inline_narration_mode']??'Disabled');
          $routing=is_array($content['routing']??null)&&!array_is_list($content['routing'])?$content['routing']:[];
          $ttsId=(string)($routing['tts_configuration_id']??'');
          $ttsOptions=almsivi_ui_profile_connector_options($ttsRows,(string)($row['installation_id']??''),'Use the active installation TTS connector','driver');
          if($ttsId!==''&&!isset($ttsOptions[$ttsId]))$ttsOptions[$ttsId]='Unavailable TTS connector';
        echo'<article class="profile-card"><header><div><span class="connector-kind">Player-local narrator</span><h3>'.almsivi_ui_h($identity['display_name']??$row['name']??'The Narrator').'</h3></div>';
        echo'<span class="status-badge'.($enabled?' connector-active':'').'">'.($enabled?'Enabled':'Disabled').'</span></header>';
          echo'<dl><dt>Inline routing</dt><dd>'.almsivi_ui_h($mode).'</dd><dt>TTS connector</dt><dd>'.almsivi_ui_h($ttsOptions[$ttsId]??$ttsOptions['']).'</dd><dt>Voice</dt><dd>'.almsivi_ui_h($voice['id']??'Connector default').'</dd><dt>Revision</dt><dd>'.almsivi_ui_h($row['current_revision']??'').'</dd></dl>';
        echo'<p>Leading <code>*narration*</code> can be separated from NPC speech. Narrator audio plays through the player-local OpenMW voice lane and keeps normal delivery tracking.</p>';
        almsivi_ui_management_form(['route'=>'narrator-profile-revise','id'=>'narrator-'.$id,'legend'=>'Save narration settings',
            'hidden'=>['profile_id'=>$id,'base_content_json'=>json_encode($content===[]?(object)[]:$content,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)],
            'fields'=>[['enabled','Enable narrator routing','checkbox','1',[],false,$enabled],
                ['inline_narration_mode','Inline narration mode','select',$mode,['Disabled'=>'Disabled','Narrator'=>'Narrator voice','NPC'=>'NPC voice','Text Only'=>'Text only']],
                ['biography','Background','textarea',(string)($content['biography']??''),[],false],
                ['personality','Personality','textarea',(string)($content['personality']??''),[],false],
                  ['speech_style','Speech style','textarea',(string)($content['speech_style']??''),[],false],
                  ['goals','Goals','textarea',(string)($content['goals']??''),[],false],
                  ['tts_configuration_id','Narrator TTS connector','select',$ttsId,$ttsOptions],
                  ['voice_id','TTS voice ID (type or choose a stored sample)','datalist',(string)($voice['id']??''),$voiceOptions,false],
                ['voice_language','Voice language','text',(string)($voice['language']??'en')],
                  ['notes','Additional notes','textarea',(string)($content['notes']??''),[],false],
                ['change_reason','Change reason','text','narration settings edit']]],$managementBasePath,$csrf);
        $locked=(is_array($content['management']??null)&&($content['management']['locked']??false)===true);
        echo'<div class="connector-actions">';if(!$locked)echo'<form method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/narrator-profile-generate').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.almsivi_ui_h($id).'"><button class="btn-base btn-primary" type="submit">Generate narrator profile with AI</button></form>';echo'</div>';
        echo'<p class="management-note">'.($locked?'This narrator profile is locked, so automatic AI generation is disabled.':'Generation creates a new revision, preserves narrator enablement and voice routing, and cannot overwrite a later manual edit.').'</p>';
        almsivi_ui_revision_actions('profile',$id,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf);
        echo'</article>';}
    echo'</div>';
}

/** Render a focused biography editor while preserving every other field in the profile revision. */
function almsivi_ui_biography_cards(array $rows,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo'<p class="empty-state">No NPC profiles are configured yet.</p>';return;}
    echo'<div class="profile-grid">';foreach($rows as$row){$content=is_array($row['content']??null)?$row['content']:[];$identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];$id=(string)($row['profile_id']??'');
        echo'<article class="profile-card"><header><div><span class="connector-kind">NPC Biography</span><h3>'.almsivi_ui_h($row['name']??'').'</h3></div><span class="status-badge">Revision '.almsivi_ui_h($row['current_revision']??'').'</span></header>';
        echo'<dl><dt>Record</dt><dd><code>'.almsivi_ui_h($identity['record_id']??'Unbound template').'</code></dd></dl>';
        almsivi_ui_management_form(['route'=>'profile-biography-revise','id'=>'biography-'.$id,'legend'=>'Save biography revision',
            'hidden'=>['profile_id'=>$id,'base_content_json'=>json_encode($content===[]?(object)[]:$content,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)],
            'fields'=>[['biography','Biography','textarea',(string)($content['biography']??''),[],false],['change_reason','Change reason','text','biography edit']]],$managementBasePath,$csrf);
        echo'</article>';}
      echo'</div>';
  }

/** Render runtime-backed rechat, boredom, and greeting schedules with explicit active-session gating. */
function almsivi_ui_schedule_cards(array $rows,array $sessionOptions,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo'<p class="empty-state">No server behavior schedules are configured. Client-side defaults remain in effect.</p>';return;}
    echo'<div class="profile-grid">';foreach($rows as$row){$kind=(string)($row['kind']??'rechat');$enabled=filter_var($row['enabled']??false,FILTER_VALIDATE_BOOL);
        $sessionId=(string)($row['current_session_id']??'');$options=[''=>'Select an active game session']+$sessionOptions;
        if($sessionId!==''&&!isset($options[$sessionId]))$options[$sessionId]='Session is no longer active';
        echo'<article class="profile-card"><header><div><span class="connector-kind">Bounded behavior</span><h3>'.almsivi_ui_h(ucwords(str_replace('_',' ',$kind))).'</h3></div><span class="status-badge'.($enabled?' connector-active':'').'">'.($enabled?'Enabled':'Disabled').'</span></header><dl><dt>Interval</dt><dd>'.almsivi_ui_h($row['interval_seconds']??0).' seconds</dd><dt>Cooldown</dt><dd>'.almsivi_ui_h($row['cooldown_seconds']??0).' seconds</dd><dt>Last triggered</dt><dd>'.almsivi_ui_h($row['last_triggered_at']??'Never').'</dd></dl><details><summary>Edit schedule</summary>';
        almsivi_ui_management_form(['route'=>'autonomy','id'=>'schedule-'.($row['schedule_id']??$kind),'legend'=>'Save behavior schedule',
            'hidden'=>['installation_id'=>$row['installation_id']??'','profile_id'=>$row['profile_id']??'','playthrough_id'=>$row['playthrough_id']??'','kind'=>$kind],
            'fields'=>[['interval_seconds','Interval seconds','number',(string)($row['interval_seconds']??300)],['cooldown_seconds','Cooldown seconds','number',(string)($row['cooldown_seconds']??300)],['current_session_id','Active game session','select',$sessionId,$options,false],['enabled','Enable after active-session confirmation','checkbox','1',[],false,$enabled]]],$managementBasePath,$csrf);
        echo'</details></article>';}
    echo'</div>';
}

/** Render speech presets as CHIM-style connector cards with an explicit active selection. */
function almsivi_ui_connector_cards(array $rows, array $voiceOptions, string $view, string $managementBasePath, string $csrf): void
{
    if ($rows === []) {
        echo '<p class="empty-state">No ' . almsivi_ui_h(strtoupper($view)) . ' connectors are configured yet.</p>';
        return;
    }
    echo '<div class="connector-grid">';
    foreach ($rows as $row) {
        $content = is_array($row['content'] ?? null) ? $row['content'] : [];
        $definition = ConnectorCatalog::definition($view . '_provider', (string) ($content['driver'] ?? ''));
        $active = filter_var($row['active'] ?? false, FILTER_VALIDATE_BOOL);
        $profileUsage=(int)($row['profile_usage']??0);$inUse=$active||$profileUsage>0;
        echo '<article class="connector-card' . ($active ? ' active' : '') . '">';
        echo '<header><div><span class="connector-kind">' . almsivi_ui_h($view) . '</span><h3>' . almsivi_ui_h($row['name'] ?? '') . '</h3></div>';
        echo $active ? '<span class="status-badge connector-active">Active</span>' : '<span class="status-badge">Available</span>';
        echo '</header><dl>';
        echo '<dt>Connector</dt><dd>' . almsivi_ui_h($definition['label']) . '</dd>';
        echo '<dt>Endpoint</dt><dd><code>' . almsivi_ui_h($content['endpoint'] ?? '') . '</code></dd>';
        echo '<dt>Model</dt><dd>' . almsivi_ui_h($content['model'] ?? '') . '</dd>';
        if ($view === 'tts') {$fallbackOptions=is_array($content['options']??null)?$content['options']:[];
            echo '<dt>Voice</dt><dd>' . almsivi_ui_h($content['voice'] ?? '') . '</dd>';
            echo '<dt>Male / female fallback</dt><dd>'.almsivi_ui_h((string)($fallbackOptions['fallback_male']??'Connector default').' / '.(string)($fallbackOptions['fallback_female']??'Connector default')).'</dd>';}
        echo '<dt>Credentials</dt><dd><code>' . almsivi_ui_h($definition['credential_environment']) . '</code></dd>';
        if($view==='tts')echo'<dt>Assigned profiles</dt><dd>'.almsivi_ui_h($profileUsage).'</dd>';
        echo '<dt>Revision</dt><dd>' . almsivi_ui_h($row['current_revision'] ?? '') . '</dd></dl>';
        echo '<div class="connector-actions">';
        if (!$active) {
            echo '<form class="connector-activate" method="post" action="' . almsivi_ui_h($managementBasePath . '/forms/connector-selection') . '">';
            foreach (['_csrf'=>$csrf,'installation_id'=>$row['installation_id'] ?? '',
                'configuration_id'=>$row['configuration_id'] ?? '','kind'=>$view . '_provider'] as $name=>$value) {
                echo '<input type="hidden" name="' . almsivi_ui_h($name) . '" value="' . almsivi_ui_h($value) . '">';
            }
            echo '<button class="btn-base btn-primary" type="submit">Use this connector</button></form>';
        }
        echo '<form class="connector-test" method="post" action="' . almsivi_ui_h($managementBasePath . '/forms/connector-test') . '">';
        foreach (['_csrf'=>$csrf,'installation_id'=>$row['installation_id'] ?? '',
            'configuration_id'=>$row['configuration_id'] ?? '','kind'=>$view . '_provider'] as $name=>$value) {
            echo '<input type="hidden" name="' . almsivi_ui_h($name) . '" value="' . almsivi_ui_h($value) . '">';
        }
        echo '<button class="btn-base" type="submit">Test connector</button></form>';
        echo'<a class="btn-base" href="'.almsivi_ui_h($managementBasePath.'/exports/connectors/'.($row['configuration_id']??'').'.json').'">Export connector</a></div>';
        echo'<details><summary>Clone connector</summary>';
        almsivi_ui_management_form(['route'=>'connector-clone','id'=>'connector-clone-'.($row['configuration_id']??''),'legend'=>'Clone connector',
            'hidden'=>['configuration_id'=>$row['configuration_id']??'','kind'=>$view.'_provider'],
            'fields'=>[['name','New preset name','text',(string)($row['name']??'').' copy']]],$managementBasePath,$csrf);
        echo'</details>';
        echo '<details><summary>Edit connector</summary>';
        $options = [];
        foreach (ConnectorCatalog::all($view . '_provider') as $item) $options[(string)$item['driver']] = (string)$item['label'];
        $connectorFields=[
                ['driver','Connector','select',(string)($content['driver']??''),$options],
                ['endpoint','Endpoint','url',(string)($content['endpoint']??'')],
                ['model','Model or engine','text',(string)($content['model']??''),[],false],
                ['voice','Default voice (type or choose a stored sample)','datalist',(string)($content['voice']??''),$voiceOptions,false],
                ['language','Language','text',(string)($content['language']??'en')],
                ['timeout_ms','Timeout in milliseconds','number',(string)($content['timeout_ms']??30000)],
            ];
        if($view==='tts'){$connectorOptions=is_array($content['options']??null)?$content['options']:[];
            $connectorFields[]=['fallback_male','Male fallback voice ID','datalist',(string)($connectorOptions['fallback_male']??''),$voiceOptions,false];
            $connectorFields[]=['fallback_female','Female fallback voice ID','datalist',(string)($connectorOptions['fallback_female']??''),$voiceOptions,false];}
        $connectorOptions=is_array($content['options']??null)?$content['options']:[];$optionCatalog=[];
        foreach(ConnectorCatalog::all($view.'_provider')as$connectorDefinition){$catalogDriver=(string)$connectorDefinition['driver'];$optionCatalog[$catalogDriver]=ConnectorCatalog::optionFields($view.'_provider',$catalogDriver);}
        $connectorFields[]=['connector_options','Provider-specific options','connector-options',(string)($content['driver']??''),$optionCatalog,false,$connectorOptions];
        $connectorFields[]=['options_json','Advanced connector options (JSON)','textarea',json_encode($content['options']??(object)[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)];
        $connectorFields[]=['change_reason','Change reason','text','management edit'];
        almsivi_ui_management_form([
            'route'=>'connector-revise','id'=>'connector-' . ($row['configuration_id'] ?? ''),'legend'=>'Save connector revision',
            'hidden'=>['configuration_id'=>$row['configuration_id'] ?? '','kind'=>$view . '_provider','option_fields_present'=>'1'],
            'fields'=>$connectorFields,
        ],$managementBasePath,$csrf);
        echo '</details>';
        almsivi_ui_revision_actions('connector',(string)($row['configuration_id']??''),is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf,$view.'_provider',!$inUse,
            $active?'This active connector cannot be deleted. Select another connector first.':($profileUsage>0?'This connector cannot be deleted while assigned to a profile.':''));
        echo '</article>';
    }
    echo '</div>';
}

/** Render dialogue model slots without exposing the global endpoint credential or accepting secrets. */
function almsivi_ui_provider_cards(array $rows, array $runtime, string $managementBasePath, string $csrf): void
{
    $runtimeDriver=(string)($runtime['driver']??'mock');$runtimeModel=(string)($runtime['model']??'');
    $endpoint=(string)($runtime['endpoint']??'');
    echo '<article class="connector-card active"><header><div><span class="connector-kind">Server runtime</span><h3>Dialogue provider</h3></div><span class="status-badge connector-active">Configured</span></header><dl>';
    echo '<dt>Driver</dt><dd>'.almsivi_ui_h($runtimeDriver).'</dd><dt>Default model</dt><dd>'.almsivi_ui_h($runtimeModel===''?'Not set':$runtimeModel).'</dd>';
    echo '<dt>Endpoint</dt><dd><code>'.almsivi_ui_h($endpoint===''?'Local configuration':$endpoint).'</code></dd><dt>Credential</dt><dd><code>ALMSIVI_LLM_API_KEY</code> via API Keys or environment</dd></dl>';
    echo '<p>Configured slots inherit this vetted endpoint and credential while selecting only their own model. Test slots never call the network.</p><div class="connector-actions"><form class="connector-test" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/provider-runtime-test').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base" type="submit">Test server runtime</button></form></div></article>';
    if($rows===[]){echo '<p class="empty-state">No in-game model slots are configured yet. The server default remains available.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){$content=is_array($row['content']??null)?$row['content']:[];$id=(string)($row['configuration_id']??'');$driver=(string)($content['driver']??'configured');
        $activeSessionUsage=(int)($row['active_session_usage']??0);$profileUsage=(int)($row['profile_usage']??0);$inUse=$activeSessionUsage>0||$profileUsage>0;
        echo '<article class="connector-card"><header><div><span class="connector-kind">LLM model slot</span><h3>'.almsivi_ui_h($row['name']??'').'</h3></div><span class="status-badge">Revision '.almsivi_ui_h($row['current_revision']??'').'</span></header><dl>';
        echo '<dt>Runtime</dt><dd>'.almsivi_ui_h($driver==='mock'?'Deterministic test provider':'Configured live provider').'</dd><dt>Model</dt><dd>'.almsivi_ui_h($content['model']??'').'</dd>';
        if($driver==='mock')echo '<dt>Test prefix</dt><dd>'.almsivi_ui_h($content['mock_prefix']??'').'</dd>';
        echo '<dt>Active sessions</dt><dd>'.almsivi_ui_h($activeSessionUsage).'</dd><dt>Assigned profiles</dt><dd>'.almsivi_ui_h($profileUsage).'</dd>';
        echo '</dl><div class="connector-actions"><form class="connector-test" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/provider-test').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="installation_id" value="'.almsivi_ui_h($row['installation_id']??'').'"><input type="hidden" name="configuration_id" value="'.almsivi_ui_h($id).'"><button class="btn-base" type="submit">Test model slot</button></form><a class="btn-base" href="'.almsivi_ui_h($managementBasePath.'/exports/providers/'.$id.'.json').'">Export model slot</a><form method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/provider-clone').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="configuration_id" value="'.almsivi_ui_h($id).'"><label for="provider-clone-name-'.almsivi_ui_h($id).'">Clone name</label><input id="provider-clone-name-'.almsivi_ui_h($id).'" name="name" value="'.almsivi_ui_h(($row['name']??'Model slot').' copy').'" required><button class="btn-base" type="submit">Clone</button></form></div><details><summary>Edit model slot</summary>';
        almsivi_ui_management_form(['route'=>'provider-revise','id'=>'provider-'.$id,'legend'=>'Save model-slot revision','hidden'=>['configuration_id'=>$id],
            'fields'=>[['driver','Runtime','select',$driver,['configured'=>'Configured live provider','mock'=>'Deterministic test provider']],['model','Model','text',(string)($content['model']??'')],['mock_prefix','Test response prefix','text',(string)($content['mock_prefix']??''),[],false],['change_reason','Change reason','text','management edit']]],$managementBasePath,$csrf);
        echo '</details>';almsivi_ui_revision_actions('provider',$id,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf,'provider',!$inUse,
            $activeSessionUsage>0?'This model slot cannot be deleted while selected by an active session.':($profileUsage>0?'This model slot cannot be deleted while assigned to a profile.':''));echo '</article>';
    }echo '</div>';
}

/** Render JSON-backed prompt and action-policy revisions with bounded edit, rollback, and delete controls. */
function almsivi_ui_configuration_cards(array $rows,string $kind,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo '<p class="empty-state">No saved '.almsivi_ui_h(str_replace('_',' ',$kind)).' records yet.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){$id=(string)($row['configuration_id']??'');$content=is_array($row['content']??null)?$row['content']:[];
        echo '<article class="connector-card"><header><div><span class="connector-kind">'.almsivi_ui_h(str_replace('_',' ',$kind)).'</span><h3>'.almsivi_ui_h($row['name']??'').'</h3></div><span class="status-badge">Revision '.almsivi_ui_h($row['current_revision']??'').'</span></header>';
        if($kind==='prompt')echo'<dl><dt>Assigned profiles</dt><dd>'.almsivi_ui_h($row['profile_usage']??0).'</dd></dl>';
        echo '<pre class="configuration-preview">'.almsivi_ui_h(json_encode($content,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'</pre>';
        if($kind==='prompt')echo '<div class="connector-actions"><a class="btn-base" href="'.almsivi_ui_h($managementBasePath.'/exports/prompts/'.$id.'.json').'">Export prompt</a><form method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/prompt-clone').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="configuration_id" value="'.almsivi_ui_h($id).'"><label for="prompt-clone-name-'.almsivi_ui_h($id).'">Clone name</label><input id="prompt-clone-name-'.almsivi_ui_h($id).'" name="name" value="'.almsivi_ui_h(($row['name']??'Prompt').' copy').'" required><button class="btn-base" type="submit">Clone</button></form></div>';
        echo '<details><summary>Edit revision</summary>';
        almsivi_ui_management_form(['route'=>'configuration-revise','id'=>'configuration-'.$id,'legend'=>'Save revision','hidden'=>['configuration_id'=>$id,'kind'=>$kind],
            'fields'=>[['content_json','Configuration document (JSON)','textarea',json_encode($content===[]?(object)[]:$content,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)],['change_reason','Change reason','text','management edit']]],$managementBasePath,$csrf);
        echo '</details>';$inUse=$kind==='prompt'&&(int)($row['profile_usage']??0)>0;almsivi_ui_revision_actions('configuration',$id,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf,$kind,!$inUse,$inUse?'This prompt is assigned to a profile and cannot be deleted.':'');echo '</article>';
    }echo '</div>';
}

/** Render a labelled policy editor over immutable server-owned action definitions. */
function almsivi_ui_action_policy_form(?array $row,array $actions,array $installationOptions,string $managementBasePath,string $csrf):void
{
    $create=$row===null;$content=$create?[]:(is_array($row['content']??null)?$row['content']:[]);$token=$create?'create':(string)($row['configuration_id']??'policy');
    $explicit=is_array($content['actions']??null)&&!array_is_list($content['actions'])?$content['actions']:null;
    $allow=is_array($content['allowed_actions']??null)&&array_is_list($content['allowed_actions'])?$content['allowed_actions']:null;
    $deny=is_array($content['denied_actions']??null)&&array_is_list($content['denied_actions'])?$content['denied_actions']:[];
    echo '<form class="management-form action-policy-form" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/'.($create?'action-policy-controls-create':'action-policy-controls-revise')).'"><fieldset><legend>'.($create?'Create action policy with controls':'Edit action permissions').'</legend>';
    if($create){echo '<label for="action-policy-installation-'.$token.'">Installation</label><select id="action-policy-installation-'.$token.'" name="installation_id" required>';foreach($installationOptions as$id=>$label)echo '<option value="'.almsivi_ui_h($id).'">'.almsivi_ui_h($label).'</option>';echo '</select><label for="action-policy-name-'.$token.'">Policy name</label><input id="action-policy-name-'.$token.'" name="name" required>';}
    else echo '<input type="hidden" name="configuration_id" value="'.almsivi_ui_h($row['configuration_id']??'').'">';
    echo '<label class="action-policy-master" for="action-policy-enabled-'.$token.'"><input id="action-policy-enabled-'.$token.'" type="checkbox" name="enabled" value="1"'.(($content['enabled']??true)?' checked':'').'> Enable actions for this policy</label>';
    echo '<label for="action-policy-tier-'.$token.'">Maximum action tier</label><select id="action-policy-tier-'.$token.'" name="max_tier">';$maxTier=(int)($content['max_tier']??3);foreach([0=>'Tier 0 - inspect only',1=>'Tier 1 - movement and social',2=>'Tier 2 - confirmed inventory or combat',3=>'Tier 3 - reserved high risk']as$value=>$label)echo '<option value="'.$value.'"'.($maxTier===$value?' selected':'').'>'.almsivi_ui_h($label).'</option>';echo '</select>';
    echo '<fieldset class="action-policy-grid"><legend>Allowed actions</legend>';
    foreach($actions as$action){$enabledValue=$action['enabled']??false;if(!in_array($enabledValue,[true,1,'1','t','true'],true))continue;$name=(string)($action['action_name']??'');if($name==='')continue;
        $checked=$explicit!==null?(($explicit[$name]??true)===true):($allow===null?!in_array($name,$deny,true):in_array($name,$allow,true)&&!in_array($name,$deny,true));$id='action-policy-'.$token.'-'.substr(hash('sha256',$name),0,12);
        echo '<label class="action-policy-toggle" for="'.almsivi_ui_h($id).'"><input id="'.almsivi_ui_h($id).'" type="checkbox" name="allowed_actions[]" value="'.almsivi_ui_h($name).'"'.($checked?' checked':'').'><span><strong>'.almsivi_ui_h($name).'</strong><small>Tier '.almsivi_ui_h($action['tier']??'').' · '.almsivi_ui_h($action['description']??'').'</small></span></label>';}
    echo '</fieldset>';
    if(!$create)echo '<label for="action-policy-reason-'.$token.'">Change reason</label><input id="action-policy-reason-'.$token.'" name="change_reason" value="management action editor" required>';
    echo '<input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base btn-primary" type="submit">'.($create?'Create policy':'Save action permissions').'</button></fieldset></form>';
}

/** Render action-policy revisions with labelled controls plus an advanced JSON editor. */
function almsivi_ui_action_policy_cards(array $rows,array $actions,array $installationOptions,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo '<p class="empty-state">No action policies are configured. The immutable server catalog and negotiated client capabilities remain the safety boundary.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){$id=(string)($row['configuration_id']??'');$content=is_array($row['content']??null)?$row['content']:[];
        echo '<article class="connector-card"><header><div><span class="connector-kind">Action policy</span><h3>'.almsivi_ui_h($row['name']??'').'</h3></div><span class="status-badge">Revision '.almsivi_ui_h($row['current_revision']??'').'</span></header><dl><dt>Enabled</dt><dd>'.(($content['enabled']??true)?'Yes':'No').'</dd><dt>Maximum tier</dt><dd>'.almsivi_ui_h($content['max_tier']??3).'</dd></dl><details open><summary>Action permissions</summary>';
        almsivi_ui_action_policy_form($row,$actions,$installationOptions,$managementBasePath,$csrf);echo '</details><details><summary>Advanced policy JSON</summary>';
        almsivi_ui_management_form(['route'=>'configuration-revise','id'=>'action-policy-json-'.$id,'legend'=>'Save advanced policy revision','hidden'=>['configuration_id'=>$id,'kind'=>'action_policy'],
            'fields'=>[['content_json','Policy document (JSON)','textarea',json_encode($content===[]?(object)[]:$content,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)],['change_reason','Change reason','text','advanced action policy edit']]],$managementBasePath,$csrf);
        echo '</details>';almsivi_ui_revision_actions('configuration',$id,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf,'action_policy');echo '</article>';}
    echo '</div>';
}

/** Render scoped Oghma documents with an explicit, CSRF-protected soft-delete action. */
function almsivi_ui_knowledge_cards(array $rows,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo '<p class="empty-state">No Oghma Infinium documents have been added yet.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){echo '<article class="connector-card"><header><div><span class="connector-kind">World knowledge</span><h3>'.almsivi_ui_h($row['title']??'').'</h3></div></header>';
        echo '<p>'.nl2br(almsivi_ui_h($row['content']??'')).'</p><dl><dt>Profile scope</dt><dd><code>'.almsivi_ui_h($row['profile_id']??'All profiles').'</code></dd><dt>Playthrough scope</dt><dd><code>'.almsivi_ui_h($row['playthrough_id']??'All playthroughs').'</code></dd><dt>Created</dt><dd>'.almsivi_ui_h($row['created_at']??'').'</dd></dl>';
        echo '<form class="danger-form" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/knowledge-delete').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="document_id" value="'.almsivi_ui_h($row['document_id']??'').'"><button class="btn-base btn-danger" type="submit">Delete document</button></form></article>';}
    echo '</div>';
}

/** Render narrator, diary, and summary records that feed scoped prompt assembly. */
function almsivi_ui_narrative_cards(array $rows,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo '<p class="empty-state">No narrator or diary records have been added yet.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){$provenance=is_array($row['provenance']??null)?$row['provenance']:[];$id=(string)($row['narrative_id']??'');
        echo '<article class="connector-card"><header><div><span class="connector-kind">'.almsivi_ui_h($row['kind']??'narrative').'</span><h3>'.almsivi_ui_h($row['title']??'').'</h3></div></header><p>'.nl2br(almsivi_ui_h($row['content']??'')).'</p>';
        echo'<details><summary>Edit narrative</summary>';almsivi_ui_management_form(['route'=>'narrative-revise','id'=>'narrative-'.$id,'legend'=>'Save narrative changes','hidden'=>['narrative_id'=>$id],
            'fields'=>[['kind','Narrative kind','select',(string)($row['kind']??'narrator'),['narrator','diary','summary']],
                ['title','Title','text',(string)($row['title']??'')],['content','Narrative','textarea',(string)($row['content']??'')],
                ['provenance','Provenance source','text',(string)($provenance['source']??'management')]]],$managementBasePath,$csrf);echo'</details>';
        echo '<form class="danger-form" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/narrative-delete').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="narrative_id" value="'.almsivi_ui_h($row['narrative_id']??'').'"><button class="btn-base btn-danger" type="submit">Delete narrative</button></form></article>';}
    echo '</div>';
}

/** Render playthrough records with an authenticated backup download for their scoped roleplay state. */
function almsivi_ui_playthrough_cards(array $rows,string $managementBasePath):void
{
    if($rows===[]){echo'<p class="empty-state">No playthroughs are configured yet.</p>';return;}
    echo'<div class="connector-grid">';foreach($rows as$row){
        echo'<article class="connector-card"><header><div><span class="connector-kind">OpenMW playthrough</span><h3>'.almsivi_ui_h($row['playthrough']??'').'</h3></div><span class="status-badge">Revision '.almsivi_ui_h($row['current_revision']??'').'</span></header><dl>';
        echo'<dt>Profile</dt><dd>'.almsivi_ui_h($row['profile']??'').'</dd><dt>Fingerprint</dt><dd><code>'.almsivi_ui_h($row['content_fingerprint']??'').'</code></dd><dt>Created</dt><dd>'.almsivi_ui_h($row['created_at']??'').'</dd><dt>Last session</dt><dd>'.almsivi_ui_h($row['last_session_at']??'Never').'</dd></dl>';
        echo'<div class="widget-stats">';foreach(['Sessions'=>'sessions','Turns'=>'turns','Responses'=>'responses','Memories'=>'memories','Relationships'=>'relationships','Narratives'=>'narratives','Knowledge'=>'knowledge_records']as$label=>$key)
            echo'<div class="stat-card"><span class="stat-value">'.almsivi_ui_h($row[$key]??0).'</span><span class="stat-label">'.almsivi_ui_h($label).'</span></div>';echo'</div>';
        echo'<div class="connector-actions"><a class="btn-base btn-primary" href="'.almsivi_ui_h($managementBasePath.'/exports/playthroughs/'.($row['playthrough_id']??'').'.json').'">Export backup</a></div></article>';
    }echo'</div>';
}

/** Render stored backup metadata without exposing server paths or configuration contents. */
function almsivi_ui_configuration_backup_cards(array $rows,array $installationOptions,string $managementBasePath):void
{
    $rows=array_values(array_filter($rows,static fn(array$row):bool=>is_array($row['scope']??null)&&($row['scope']['kind']??null)==='configuration'));
    if($rows===[]){echo'<p class="empty-state">No installation configuration backups are available yet.</p>';return;}
    echo'<div class="connector-grid">';foreach($rows as$row){$scope=$row['scope'];$id=(string)($row['backup_id']??'');
        echo'<article class="connector-card"><header><div><span class="connector-kind">Installation configuration</span><h3>'.almsivi_ui_h($installationOptions[$scope['installation_id']??'']??($scope['installation_id']??'Unknown installation')).'</h3></div><span class="status-badge">'.almsivi_ui_h($row['state']??'created').'</span></header><dl>';
        echo'<dt>Created</dt><dd>'.almsivi_ui_h($row['created_at']??'').'</dd><dt>Bytes</dt><dd>'.almsivi_ui_h($row['byte_count']??'').'</dd><dt>Restored</dt><dd>'.almsivi_ui_h($row['restored_at']??'Never').'</dd></dl>';
        echo'<div class="connector-actions"><a class="btn-base btn-primary" href="'.almsivi_ui_h($managementBasePath.'/exports/backups/'.$id.'.json').'">Download backup</a></div></article>';}
    echo'</div>';
}

/** Render NPC identities observed in real OpenMW turns and create correctly bound profile templates. */
function almsivi_ui_observed_npcs(array $rows,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo '<p class="empty-state">No NPCs have been observed in an accepted OpenMW turn yet.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){$refnum=is_array($row['refnum']??null)?$row['refnum']:[];$profile=(string)($row['profile_id']??'');
        echo '<article class="connector-card"><header><div><span class="connector-kind">Observed in OpenMW</span><h3>'.almsivi_ui_h($row['display_name']??$row['record_id']??'NPC').'</h3></div>'.($profile!==''?'<span class="status-badge connector-active">Profile exists</span>':'<span class="status-badge">New</span>').'</header><dl><dt>Record</dt><dd><code>'.almsivi_ui_h($row['record_id']??'').'</code></dd><dt>Content file</dt><dd>'.almsivi_ui_h($row['content_file']??'').'</dd><dt>Last seen</dt><dd>'.almsivi_ui_h($row['last_seen_at']??'').'</dd></dl>';
        if($profile===''){echo '<form method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/profile-create').'">';foreach(['_csrf'=>$csrf,'installation_id'=>$row['installation_id']??'','name'=>$row['display_name']??$row['record_id']??'NPC','record_id'=>$row['record_id']??'','content_file'=>$row['content_file']??'','refnum_index'=>$refnum['index']??'','refnum_content_file'=>$refnum['content_file']??'']as$name=>$value)echo '<input type="hidden" name="'.almsivi_ui_h($name).'" value="'.almsivi_ui_h($value).'">';echo '<button class="btn-base btn-primary" type="submit">Create editable profile</button></form>';}
        echo '</article>';}
    echo '</div>';
}

include $uiRootDir . '/tmpl/head.html';
if (!$embedded) include $uiRootDir . '/tmpl/navbar.php';
?>
<main class="management-page">
    <h1><?php echo almsivi_ui_h($pageTitle); ?></h1>
    <p><?php echo almsivi_ui_h($descriptions[$view] ?? 'ALMSIVIserver management page.'); ?></p>
    <?php if (isset($_GET['status']) && $_GET['status'] === 'saved'): ?><p class="page-status" role="status">Changes saved.</p><?php endif; ?>
    <?php if (isset($_GET['status']) && $_GET['status'] === 'tested'): ?><p class="page-status" role="status">Connector test passed<?php echo isset($_GET['detail']) ? ': ' . almsivi_ui_h($_GET['detail']) : '.'; ?></p><?php endif; ?>
    <?php if (isset($_GET['error'])): ?><p class="page-error" role="alert"><?php echo almsivi_ui_h($_GET['error']); ?></p><?php endif; ?>
    <?php if ($view === 'characters'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Observed OpenMW NPCs</h3></div><div class="widget-content"><?php almsivi_ui_observed_npcs($observedNpcs,$managementBasePath,$csrf); ?></div></section>
    <section class="widget widget-wide"><div class="widget-header"><h3>NPC Profile Safety &amp; Bulk Tools</h3></div><div class="widget-content"><?php almsivi_ui_profile_preferences($profilePreferenceRows,$managementBasePath,$csrf); almsivi_ui_bulk_profile_tools($rows,$installationOptions,$managementBasePath,$csrf); ?></div></section>
    <?php endif; ?>
    <section class="widget widget-wide">
        <div class="widget-header"><h3>Current Records</h3></div>
        <div class="widget-content"><?php
            if ($view === 'tts' || $view === 'stt') almsivi_ui_connector_cards($rows, $voiceOptions, $view, $managementBasePath, $csrf);
            elseif ($view === 'llm') almsivi_ui_provider_cards($rows, is_array($config['provider']??null)?$config['provider']:[], $managementBasePath, $csrf);
            elseif ($view === 'prompts') almsivi_ui_configuration_cards($rows, 'prompt', $managementBasePath, $csrf);
            elseif ($view === 'worldknowledge') almsivi_ui_knowledge_cards($rows, $managementBasePath, $csrf);
            elseif ($view === 'characters' || $view === 'profiles') almsivi_ui_profile_cards($rows,$voiceOptions,$promptRoutingRows,$llmRoutingRows,$ttsRoutingRows,$managementBasePath,$csrf);
            elseif ($view === 'player') almsivi_ui_player_cards($rows, $managementBasePath, $csrf);
            elseif ($view === 'narrator') almsivi_ui_narrator_cards($rows,$voiceOptions,$ttsRoutingRows,$managementBasePath,$csrf);
            elseif ($view === 'npc_biographies') almsivi_ui_biography_cards($rows, $managementBasePath, $csrf);
            elseif ($view === 'playthroughs') almsivi_ui_playthrough_cards($rows, $managementBasePath);
            else almsivi_ui_table($rows);
        ?></div>
    </section>
    <?php if ($view === 'global_settings'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Rechat, Boredom &amp; Greetings</h3></div><div class="widget-content"><?php almsivi_ui_schedule_cards($scheduleRows,$sessionOptions,$managementBasePath,$csrf); ?></div></section>
    <?php endif; ?>
    <?php if ($view === 'database_manager'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Installation Configuration Backups</h3></div><div class="widget-content"><p>Includes profiles, prompts, model slots, TTS/STT presets, action policies, connector selections, and profile safety preferences. API keys, portrait files, voice files, memories, relationships, narratives, and runtime database records are excluded.</p><?php almsivi_ui_configuration_backup_cards($backupRows,$installationOptions,$managementBasePath); ?></div></section>
    <?php endif; ?>
    <?php if ($view === 'actions'): ?>
    <details class="management-create-panel"<?php echo $policyRows === [] ? ' open' : ''; ?>><summary>Create action policy with controls</summary><?php almsivi_ui_action_policy_form(null,$rows,$installationOptions,$managementBasePath,$csrf); ?></details>
    <?php endif; ?>
    <?php foreach ($forms as $form): ?>
        <details class="management-create-panel"<?php echo $rows === [] ? ' open' : ''; ?>>
            <summary><?php echo almsivi_ui_h($form['legend']); ?></summary>
            <?php almsivi_ui_management_form($form, $managementBasePath, $csrf); ?>
        </details>
    <?php endforeach; ?>
    <?php if ($view === 'actions'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Action Policies</h3></div><div class="widget-content"><p>Policies can only reduce the immutable server catalog and the capabilities negotiated with OpenMW. Profile-specific policies take precedence over installation policies; equal scopes use policy name order.</p><?php almsivi_ui_action_policy_cards($policyRows,$rows,$installationOptions,$managementBasePath,$csrf); ?></div></section>
    <?php endif; ?>
    <?php if ($view === 'autonomy'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Narrator and Diary Records</h3></div><div class="widget-content"><?php almsivi_ui_narrative_cards($narrativeRows,$managementBasePath,$csrf); ?></div></section>
    <?php endif; ?>
</main>
<script>
document.querySelectorAll('[data-json-import-target]').forEach(function (picker) {
    picker.addEventListener('change', function () {
        var file = picker.files && picker.files[0];
        var target = document.getElementById(picker.getAttribute('data-json-import-target'));
        if (!file || !target) return;
        if (file.size > 1048576) { picker.value = ''; window.alert('JSON imports are limited to 1 MiB.'); return; }
        var reader = new FileReader();
        reader.onload = function () { target.value = typeof reader.result === 'string' ? reader.result : ''; };
        reader.onerror = function () { picker.value = ''; window.alert('The JSON file could not be read.'); };
        reader.readAsText(file, 'UTF-8');
    });
});
</script>
<?php include $uiRootDir . '/tmpl/footer.html'; ?>
