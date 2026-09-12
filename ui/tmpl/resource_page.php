<?php

declare(strict_types=1);

use LorkhanServer\Application\ConnectorCatalog;
use LorkhanServer\Infrastructure\ProductRepository;

if (!isset($uiRootDir, $view, $pageTitle, $topNavSection)) throw new RuntimeException('Incomplete management page definition.');
require $uiRootDir . '/ui_bootstrap.php';
$rows = $uiRepository->rows($view);
$policyRows = $view === 'actions' ? $uiRepository->rows('action_policies') : [];
$narrativeRows = $view === 'autonomy' ? $uiRepository->rows('narratives') : [];
$scheduleRows = [];
$backupRows = $view === 'database_manager' ? $uiRepository->rows('backup_health') : [];
$observedNpcs = $view === 'characters' ? $uiRepository->rows('observed_npcs') : [];
$profilePreferenceRows = $view === 'characters' ? $uiRepository->rows('profile_preferences') : [];
$coreProfileRows = in_array($view, ['characters', 'profiles'], true) ? $uiRepository->rows('core_profiles') : [];
$installationRows=$uiRepository->rows('installations');
$installationOptions = [];
foreach ($installationRows as $installation) {
    $id = (string) ($installation['installation_id'] ?? '');
    if ($id !== '') $installationOptions[$id] = (string) ($installation['display_name'] ?? $id);
}
$configurationBackupOptions=[];
foreach($backupRows as$backup){$scope=is_array($backup['scope']??null)?$backup['scope']:[];
    if(($scope['kind']??null)!=='configuration')continue;$id=(string)($backup['backup_id']??'');
    if($id!=='')$configurationBackupOptions[$id]=(string)($installationOptions[$scope['installation_id']??'']??($scope['installation_id']??'Installation')).' - '.(string)($backup['created_at']??$id);}
$profileRowsForOptions=array_merge($uiRepository->rows('profiles'),$uiRepository->rows('player'));
$profileOptions=[];$actionProfileOptions=[];foreach($profileRowsForOptions as$profile){$id=(string)($profile['profile_id']??'');if($id==='')continue;$label=(string)($profile['name']??$id);$profileOptions[$id]=$label;$identity=is_array($profile['actor_identity']??null)?$profile['actor_identity']:[];if(($identity['kind']??'')!=='template')$actionProfileOptions[$id]=$label;}
$playthroughOptions=[];$playthroughOptionsByInstallation=[];foreach($uiRepository->rows('playthroughs')as$playthrough){$id=(string)($playthrough['playthrough_id']??'');if($id==='')continue;
    $plainLabel=(string)($playthrough['playthrough']??$id);$installation=(string)($playthrough['installation_id']??'');$label=$plainLabel;
    if(count($installationOptions)>1)$label=(string)($installationOptions[$installation]??substr($installation,0,8)).' - '.$label;
    $playthroughOptions[$id]=$label;if($installation!=='')$playthroughOptionsByInstallation[$installation][$id]=$plainLabel;}
$sessionOptions=[];foreach($uiRepository->rows('active_sessions')as$session){$id=(string)($session['session_id']??'');if($id!=='')$sessionOptions[$id]=(string)($session['label']??$id);}
$routingViews=['characters','profiles','narrator'];
$llmRoutingRows=in_array($view,$routingViews,true)?$uiRepository->rows('llm'):[];
$ttsRoutingRows=in_array($view,$routingViews,true)?$uiRepository->rows('tts'):[];
$promptRoutingRows=in_array($view,['characters','profiles'],true)?$uiRepository->rows('prompts'):[];
$promptRoutingRows=array_values(array_filter($promptRoutingRows,static fn(array $row):bool=>($row['content']['purpose']??'')!=='narrator_event'));
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
$voiceRoot = (string) ($config['voice_storage_path'] ?? '/var/lib/lorkhanserver/voices');
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
    'npc_biographies' => 'Review and edit the biography that LORKHAN includes in each NPC roleplay profile.',
    'llm' => 'Configure server-side dialogue provider presets. Stored secrets remain redacted.',
    'tts' => 'Configure server-side speech provider presets. Stored secrets remain redacted.',
    'stt' => 'Configure server-side speech-to-text provider presets. Stored secrets remain redacted.',
    'global_settings' => 'Inspect registered installations and configure inherited playback-gated rechat. Timer-driven autonomy remains excluded.',
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
    'provider_usage' => 'Review measured provider attempts, byte counts, failures, and latency. LORKHAN does not fabricate currency costs without recorded token usage and pricing.',
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
            ['context_visibility','Include narrator context in prompts','checkbox','1'],
            ['welcome_events','Welcome narration','checkbox','1'],['random_events','Random narration','checkbox','1'],
            ['quest_events','Quest narration','checkbox','1'],['book_events','Book narration','checkbox','1'],
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
        'route' => 'profile-create', 'legend' => 'Add LORKHAN NPC', 'hidden'=>['management_fields'=>'1'],
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
            ['voice_id', 'Voice sample', 'datalist', '', $voiceOptions, false],
            ['voice_language', 'Voice language', 'text', 'en'],
            ['locked', 'Lock against automatic AI profile generation', 'checkbox', '1', [], false],
            ['favorite', 'Favorite NPC', 'checkbox', '1', [], false],
            ['notes', 'Notes', 'textarea', '', [], false],
        ],
    ]],
    'profiles' => [
        [
            'route'=>'profile-template-create','legend'=>'Create biography template',
            'fields'=>[
                ['installation_id','Installation','select','',$installationOptions],['name','Template name'],
                ['record_id','Match Morrowind record ID','text','',[],false],['content_file','Match content file','text','',[],false],
                ['gender','Match gender','select','',[''=>'Any','Male'=>'Male','Female'=>'Female','Other'=>'Other'],false],
                ['race','Match race','text','',[],false],['biography','Biography seed','textarea','',[],false],
                ['personality','Personality seed','textarea','',[],false],['speech_style','Speech style seed','textarea','',[],false],
                ['occupation','Occupation seed','text','',[],false],['goals','Goals seed','textarea','',[],false],
                ['relationships','Relationships seed','textarea','',[],false],['notes','Template notes','textarea','',[],false],
            ],
        ],
        [
            'route' => 'profile-create', 'legend' => 'Create profile', 'hidden'=>['management_fields'=>'1'],
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
                ['voice_id', 'Voice sample', 'datalist', '', $voiceOptions, false],
                ['voice_language', 'Voice language', 'text', 'en'],
                ['locked', 'Lock against automatic AI profile generation', 'checkbox', '1', [], false],
                ['favorite', 'Favorite NPC', 'checkbox', '1', [], false],
                ['notes', 'Notes', 'textarea', '', [], false],
            ],
        ],
        [
            'route' => 'profile-import', 'legend' => 'Import LORKHAN profile',
            'fields' => [
                ['installation_id', 'Installation', 'select', '', $installationOptions],
                ['profile_json', 'Portable LORKHAN profile JSON', 'jsonfile'],
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
        'fields'=>[['installation_id','Installation','select','',$installationOptions],['provider_json','Portable LORKHAN model-slot JSON','jsonfile']],
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
        'fields'=>[['installation_id','Installation','select','',$installationOptions],['connector_json','Portable LORKHAN connector JSON','jsonfile']],
    ]],
    'global_settings' => [],
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
        'fields'=>[['installation_id','Installation','select','',$installationOptions],['prompt_json','Portable LORKHAN prompt JSON','jsonfile']],
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
            'fields' => [['installation_id', 'Installation', 'select', '', $installationOptions], ['profile_id', 'Profile', 'select', '', $profileOptions], ['playthrough_id', 'Destination playthrough', 'select', '', $playthroughOptions], ['playthrough_json', 'LORKHAN playthrough backup JSON', 'textarea']],
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
function lorkhan_ui_management_form(array $form, string $managementBasePath, string $csrf): void
{
    $formId=(string)($form['id']??$form['route']);
    echo '<form id="management-form-' . lorkhan_ui_h($formId) . '" class="management-form" method="post" action="' . lorkhan_ui_h($managementBasePath . '/forms/' . $form['route']) . '"><fieldset><legend>' . lorkhan_ui_h($form['legend']) . '</legend>';
    foreach ($form['fields'] as $field) {
        [$name, $label] = $field;
        $type = $field[2] ?? 'text';
        $value = $field[3] ?? '';
        $required = $field[5] ?? true;
        $id = 'field-' . $formId . '-' . $name;
        echo '<div class="management-field management-field-' . lorkhan_ui_h($type) . '">';
        if ($type === 'checkbox') {
            $checked=($field[6]??false)===true?' checked':'';
            echo '<label><input name="' . lorkhan_ui_h($name) . '" type="checkbox" value="' . lorkhan_ui_h($value) . '"'.$checked.'> ' . lorkhan_ui_h($label) . '</label></div>';
            continue;
        }
        if($type==='connector-options'){
            $catalog=is_array($field[4]??null)?$field[4]:[];$current=is_array($field[6]??null)?$field[6]:[];$defaults=is_array($field[7]??null)?$field[7]:[];
            $defaultAttribute=$defaults===[]?'':' data-connector-defaults="'.lorkhan_ui_h(json_encode($defaults,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)).'"';
            echo'<div id="'.lorkhan_ui_h($id).'" class="connector-option-editor" data-connector-options data-driver-control="'.lorkhan_ui_h('field-'.$formId.'-driver').'"'.$defaultAttribute.'><p><strong>'.lorkhan_ui_h($label).'</strong></p>';
            foreach($catalog as$driver=>$optionFields){$active=(string)$driver===(string)$value;$fields=is_array($optionFields)?$optionFields:[];
                echo'<div class="connector-option-set" data-connector-driver="'.lorkhan_ui_h($driver).'"'.($active?'':' hidden').'>';
                foreach($fields as$optionField){$optionName=(string)($optionField['name']??'');$optionType=(string)($optionField['type']??'string');if($optionName==='')continue;
                    $optionId=$id.'-'.$driver.'-'.$optionName;$optionValue=$current[$optionName]??'';$disabled=$active?'':' disabled';
                    if($optionType==='boolean')echo'<label><input id="'.lorkhan_ui_h($optionId).'" name="option__'.lorkhan_ui_h($optionName).'" type="checkbox" value="1"'.($optionValue===true?' checked':'').$disabled.'> '.lorkhan_ui_h($optionField['label']??$optionName).'</label>';
                    else{echo'<label for="'.lorkhan_ui_h($optionId).'">'.lorkhan_ui_h($optionField['label']??$optionName).'</label>';
                        if($optionType==='select'){echo'<select id="'.lorkhan_ui_h($optionId).'" name="option__'.lorkhan_ui_h($optionName).'"'.$disabled.'><option value="">Connector default</option>';
                            foreach(($optionField['values']??[])as$choice)echo'<option value="'.lorkhan_ui_h($choice).'"'.((string)$choice===(string)$optionValue?' selected':'').'>'.lorkhan_ui_h(ucwords(str_replace(['_','-'],' ',(string)$choice))).'</option>';echo'</select>';}
                        elseif(in_array($optionType,['number','integer'],true))echo'<input id="'.lorkhan_ui_h($optionId).'" name="option__'.lorkhan_ui_h($optionName).'" type="number" value="'.lorkhan_ui_h($optionValue).'" min="'.lorkhan_ui_h($optionField['minimum']??'').'" max="'.lorkhan_ui_h($optionField['maximum']??'').'" step="'.($optionType==='integer'?'1':'any').'"'.$disabled.'>';
                        else echo'<input id="'.lorkhan_ui_h($optionId).'" name="option__'.lorkhan_ui_h($optionName).'" type="text" value="'.lorkhan_ui_h($optionValue).'" maxlength="512"'.$disabled.'>';
                    }
                }
                echo'</div>';
            }
            $selectedFields=is_array($catalog[(string)$value]??null)?$catalog[(string)$value]:[];
            echo'<p class="management-note" data-connector-options-empty'.($selectedFields===[]?'':' hidden').'>This connector has no additional labelled options.</p></div></div>';continue;
        }
        echo '<label for="' . lorkhan_ui_h($id) . '">' . lorkhan_ui_h($label) . '</label>';
        if ($type === 'textarea') {
            echo '<textarea id="' . lorkhan_ui_h($id) . '" name="' . lorkhan_ui_h($name) . '"' . ($required ? ' required' : '') . '>' . lorkhan_ui_h($value) . '</textarea>';
        } elseif ($type === 'jsonfile') {
            $fileId=$id.'-file';
            echo '<label for="'.lorkhan_ui_h($fileId).'">Choose JSON file</label><input id="'.lorkhan_ui_h($fileId).'" name="'.lorkhan_ui_h($name).'_file" type="file" accept="application/json,.json" data-json-import-target="'.lorkhan_ui_h($id).'">';
            echo '<textarea id="'.lorkhan_ui_h($id).'" name="'.lorkhan_ui_h($name).'" required placeholder="Choose a JSON file or paste its contents here.">'.lorkhan_ui_h($value).'</textarea>';
        } elseif ($type === 'select') {
            echo '<select id="' . lorkhan_ui_h($id) . '" name="' . lorkhan_ui_h($name) . '">';
            foreach (($field[4] ?? []) as $optionValue => $optionLabel) {
                if (is_int($optionValue)) $optionValue = $optionLabel;
                echo '<option value="' . lorkhan_ui_h($optionValue) . '"' . ((string) $optionValue === (string) $value ? ' selected' : '') . '>' . lorkhan_ui_h($optionLabel) . '</option>';
            }
            echo '</select>';
        } elseif ($type === 'datalist') {
            $listId = $id . '-options';
            echo '<input id="' . lorkhan_ui_h($id) . '" name="' . lorkhan_ui_h($name) . '" type="text" list="' . lorkhan_ui_h($listId) . '" value="' . lorkhan_ui_h($value) . '"' . ($required ? ' required' : '') . '>';
            echo '<datalist id="' . lorkhan_ui_h($listId) . '">';
            foreach (($field[4] ?? []) as $optionValue => $optionLabel) {
                if (is_int($optionValue)) $optionValue = $optionLabel;
                echo '<option value="' . lorkhan_ui_h($optionValue) . '">' . lorkhan_ui_h($optionLabel) . '</option>';
            }
            echo '</datalist>';
        } else {
            echo '<input id="' . lorkhan_ui_h($id) . '" name="' . lorkhan_ui_h($name) . '" type="' . lorkhan_ui_h($type) . '" value="' . lorkhan_ui_h($value) . '"' . ($required ? ' required' : '') . '>';
        }
        echo '</div>';
    }
    foreach (($form['hidden'] ?? []) as $name => $value) echo '<input type="hidden" name="' . lorkhan_ui_h($name) . '" value="' . lorkhan_ui_h($value) . '">';
    echo '</fieldset><input type="hidden" name="_csrf" value="' . lorkhan_ui_h($csrf) . '"><button class="btn-base btn-primary" type="submit">' . lorkhan_ui_h($form['legend']) . '</button></form>';
}

/** Echo the current NPC list state so management redirects can restore search, filters, page, and embed mode. */
function lorkhan_ui_hidden_state(array $state): void
{
    foreach ($state as $name => $value) {
        if ((string) $value === '') continue;
        echo '<input type="hidden" name="' . lorkhan_ui_h($name) . '" value="' . lorkhan_ui_h($value) . '">';
    }
}

/** Render the lazily loaded narrative event History tab for one saved NPC profile. */
function lorkhan_ui_npc_history_panel(string $profileId,bool $creating,array $playthroughOptions,string $managementBasePath,string $csrf):void
{
    echo'<section class="npc-editor-panel" id="npc-'.substr(hash('sha256',$profileId),0,12).'-edit-history" role="tabpanel" data-npc-editor-panel="history" hidden>';
    if($creating){
        echo'<div class="npc-editor-placeholder"><h3>History</h3><p>Narrative event history is recorded per playthrough. Save this NPC first, then reopen the editor to read or add events.</p><button type="button" class="btn-base" disabled aria-disabled="true">Save this NPC first</button></div></section>';
        return;
    }
    $key=substr(hash('sha256',$profileId),0,12);
    $id=static fn(string$part):string=>'npc-history-'.$part.'-'.$key;
    echo'<div class="npc-history" data-npc-history-view'
        .' data-history-endpoint="'.lorkhan_ui_h($managementBasePath.'/api/v1/profiles/'.rawurlencode($profileId).'/eventlog').'"'
        .' data-history-csrf="'.lorkhan_ui_h($csrf).'" data-history-limit="100" data-history-max-length="4000" data-history-recipient-cap="12">';
    echo'<p class="npc-history-note">Narrative events recorded for this NPC in one playthrough. Saved edits to the profile itself stay under Profile Versions.</p>';
    if($playthroughOptions===[]){
        echo'<p class="npc-history-empty">Create a playthrough before reading or adding NPC events.</p></div></section>';
        return;
    }
    $only=count($playthroughOptions)===1?(string)array_key_first($playthroughOptions):'';
    echo'<div class="npc-history-controls">';
    echo'<div class="form-item"><label for="'.lorkhan_ui_h($id('playthrough')).'">Playthrough</label><select id="'.lorkhan_ui_h($id('playthrough')).'" data-npc-history-playthrough>';
    if($only==='')echo'<option value="">Choose a playthrough</option>';
    foreach($playthroughOptions as$playthroughId=>$label)echo'<option value="'.lorkhan_ui_h($playthroughId).'"'.((string)$playthroughId===$only?' selected':'').'>'.lorkhan_ui_h($label).'</option>';
    echo'</select></div>';
    echo'<div class="form-item"><label for="'.lorkhan_ui_h($id('type')).'">Event type</label><select id="'.lorkhan_ui_h($id('type')).'" data-npc-history-type><option value="">All event types</option></select></div>';
    echo'<button type="button" class="btn-base" data-npc-history-refresh>Refresh</button></div>';
    echo'<p class="npc-history-status" id="'.lorkhan_ui_h($id('status')).'" role="status" aria-live="polite"></p>';
    echo'<p class="npc-history-error" id="'.lorkhan_ui_h($id('error')).'" role="alert" hidden></p>';
    echo'<div class="npc-history-table-scroll" tabindex="0" role="region" aria-label="Recent events for this NPC (scrollable)" data-npc-history-results><p class="npc-history-empty">Recent events load when this tab is opened.</p></div>';
    echo'<div class="npc-history-inject"><h3>Add an event</h3>';
    echo'<p class="npc-history-note">Saves the typed text as one recorded event. No provider is called and no text is generated.</p>';
    echo'<div class="form-item"><label for="'.lorkhan_ui_h($id('event')).'">Event text</label>';
    echo'<textarea id="'.lorkhan_ui_h($id('event')).'" data-npc-history-event aria-describedby="'.lorkhan_ui_h($id('count')).'"></textarea>';
    echo'<small class="hint" id="'.lorkhan_ui_h($id('count')).'" data-npc-history-count>0 of 4000 characters.</small></div>';
    echo'<div class="form-item"><label for="'.lorkhan_ui_h($id('recipients')).'">Additional known recipients (optional)</label>';
    echo'<select id="'.lorkhan_ui_h($id('recipients')).'" multiple size="4" data-npc-history-recipients aria-describedby="'.lorkhan_ui_h($id('recipients-help')).'"></select>';
    echo'<small class="hint" id="'.lorkhan_ui_h($id('recipients-help')).'">This NPC is always a recipient. Choose up to 11 more known profiles from this installation.</small></div>';
    echo'<button type="button" class="btn-base btn-primary" data-npc-history-submit>Save event</button></div>';
    echo'</div></section>';
}

/** Render bounded rollback and soft-delete controls for one revisioned record. */
function lorkhan_ui_revision_actions(string $resource, string $id, array $revisions, int $currentRevision, string $managementBasePath, string $csrf, ?string $kind = null,bool $allowDelete=true,string $deleteBlockedReason='',array $extraHidden=[]): void
{
    $options=[];foreach($revisions as$revision){$number=(int)($revision['revision']??0);if($number>0&&$number!==$currentRevision)$options[(string)$number]='Revision '.$number.' - '.(string)($revision['reason']??'saved');}
    echo '<div class="revision-actions">';
    if($options!==[])lorkhan_ui_management_form(['route'=>$resource.'-rollback','id'=>$resource.'-rollback-'.$id,'legend'=>'Restore earlier revision',
        'hidden'=>[$resource==='profile'?'profile_id':'configuration_id'=>$id]+($kind===null?[]:['kind'=>$kind])+$extraHidden,
        'fields'=>[['revision','Revision','select','', $options]]],$managementBasePath,$csrf);
    if($allowDelete){echo '<form class="danger-form" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/'.$resource.'-delete').'">';
        echo '<input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="'.($resource==='profile'?'profile_id':'configuration_id').'" value="'.lorkhan_ui_h($id).'">';
        if($kind!==null)echo '<input type="hidden" name="kind" value="'.lorkhan_ui_h($kind).'">';
        lorkhan_ui_hidden_state($extraHidden);
        echo '<button class="btn-base btn-danger" type="submit">Delete '.lorkhan_ui_h($resource).'</button></form>';}
    elseif($deleteBlockedReason!=='')echo'<p class="management-note">'.lorkhan_ui_h($deleteBlockedReason).'</p>';
    echo'</div>';
}

/** Build installation-scoped connector choices for a profile editor without exposing another install's presets. */
function lorkhan_ui_profile_connector_options(array $rows,string $installationId,string $fallback,string $detailField):array
{
    $options=[''=>$fallback];
    foreach($rows as$row){if((string)($row['installation_id']??'')!==$installationId)continue;
        $id=(string)($row['configuration_id']??'');$content=is_array($row['content']??null)?$row['content']:[];
        $detail=$detailField===''?'':trim((string)($content[$detailField]??''));
        if($id!=='')$options[$id]=(string)($row['name']??$id).($detail===''?'':' - '.$detail);}
    return$options;
}

/** Apply bounded CHIM-style NPC search and management filters to loaded profile rows. */
function lorkhan_ui_filter_profiles(array $rows):array
{
    $query=mb_strtolower(mb_substr(trim((string)($_GET['q']??'')),0,100));
    $initial=strtoupper((string)($_GET['initial']??''));if(preg_match('/^[A-Z]$/D',$initial)!==1)$initial='';
    $state=(string)($_GET['state']??'all');if(!in_array($state,['all','favorites','locked','unlocked','generated'],true))$state='all';
    $favoriteOnly=(string)($_GET['fav']??'')==='1';$lockedOnly=(string)($_GET['lock']??'')==='1';
    $coreProfile=(string)($_GET['profile']??'');
    $installation=(string)($_GET['installation_id']??'');
    $filtered=array_values(array_filter($rows,static function(array$row)use($query,$initial,$state,$coreProfile,$installation,$favoriteOnly,$lockedOnly):bool{
        $content=is_array($row['content']??null)?$row['content']:[];$identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];
        $management=is_array($content['management']??null)?$content['management']:[];
        $name=(string)($row['name']??'');
        if($installation!==''&&!hash_equals($installation,(string)($row['installation_id']??'')))return false;
        if($coreProfile!==''&&!hash_equals($coreProfile,(string)($row['core_profile_id']??'')))return false;
        if($initial!==''&&strtoupper(mb_substr($name,0,1))!==$initial)return false;
        if($favoriteOnly&&($management['favorite']??false)!==true)return false;
        if($lockedOnly&&($management['locked']??false)!==true)return false;
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

/** Explain the immediate queue decision without implying that background jobs already finished. */
function lorkhan_ui_relationship_conversion_notice(array $query):?array
{
    $status=(string)($query['status']??'');$count=static fn(string$key):int=>max(0,min(1000,(int)($query[$key]??0)));
    $queued=$count('queued');$skipped=$count('skipped');$details=[];
    foreach(['no_text'=>'no Relationships text','existing'=>'existing records','locked'=>'Relationship Lock on',
        'no_connector'=>'Relationship LLM disabled','no_targets'=>'no known actor match','pending'=>'work already pending']as$key=>$label){
        $value=$count($key);if($value>0)$details[]=$value.' '.$label;}
    $counts=' Queued '.$queued.' NPC'.($queued===1?'':'s').'; skipped '.$skipped;
    if($details!==[])$counts.=' ('.implode(', ',$details).')';$counts.='.';
    return match($status){
        'relationship_conversion_requested'=>['status','Relationship conversion requested.'.$counts.' Reload Workers & Jobs for current status.'],
        'relationship_conversion_no_eligible'=>['alert','No eligible NPC relationship text was queued.'.$counts],
        'relationship_conversion_request_conflict'=>['alert','That request was already used with different settings. Reload the page and try again.'],
        'relationship_conversion_too_large'=>['alert','A Relationships text document or its bounded model input is too large to convert.'],
        'relationship_conversion_too_many_owners'=>['alert','This installation has too many NPC profiles for one conversion request.'],
        'relationship_conversion_too_many_candidates'=>['alert','This installation has too many known actor profiles for one conversion request.'],
        'relationship_conversion_too_many_targets'=>['alert','One NPC profile names more known actors than a single conversion job can safely process.'],
        'relationship_conversion_ambiguous_records'=>['alert','More than one saved relationship record matches a target. Review current records before converting.'],
        default=>null,
    };
}

/** Render the searchable NPC-manager controls without exposing profile content in the query string. */
function lorkhan_ui_profile_filters(array $rows):void
{
    $installations=[];foreach($rows as$row){$id=(string)($row['installation_id']??'');if($id!=='')$installations[$id]=$id;}
    $selectedInitial=(string)($_GET['initial']??'');
    echo'<form class="profile-management-filters" method="get"><div class="npc-toolbar-tools"><label for="profile-filter-q">Search NPCs<input id="profile-filter-q" type="search" name="q" maxlength="100" value="'.lorkhan_ui_h($_GET['q']??'').'" placeholder="Search..."></label>';
    $states=['all'=>'All NPCs','favorites'=>'Favorites','locked'=>'Locked','unlocked'=>'Unlocked','generated'=>'AI generated'];
    echo'<label for="profile-filter-state">Status<select id="profile-filter-state" name="state">';foreach($states as$value=>$label)echo'<option value="'.$value.'"'.(($_GET['state']??'all')===$value?' selected':'').'>'.$label.'</option>';echo'</select></label>';
    if(count($installations)>1){echo'<label for="profile-filter-installation">Installation<select id="profile-filter-installation" name="installation_id"><option value="">All installations</option>';foreach($installations as$id)echo'<option value="'.lorkhan_ui_h($id).'"'.(($_GET['installation_id']??'')===$id?' selected':'').'>'.lorkhan_ui_h($id).'</option>';echo'</select></label>';}
    if(($_GET['embed']??'')==='1')echo'<input type="hidden" name="embed" value="1">';
    echo'<button class="btn-base btn-primary" type="submit">Filter NPCs</button><a class="btn-base" href="?'.(($_GET['embed']??'')==='1'?'embed=1':'').'">Clear</a></div>';
    echo'<div class="npc-letter-filter" aria-label="Filter NPCs by first letter"><button class="npc-letter-btn'.($selectedInitial===''?' active':'').'" type="submit" name="initial" value="">All</button>';
    foreach(range('A','Z')as$letter)echo'<button class="npc-letter-btn'.($selectedInitial===$letter?' active':'').'" type="submit" name="initial" value="'.$letter.'">'.$letter.'</button>';
    echo'</div></form>';
}

/** Render installation-scoped bulk tools with explicit typed confirmations. */
function lorkhan_ui_bulk_profile_tools(array $rows,array $installationOptions,string $managementBasePath,string $csrf):void
{
    if($rows===[]||$installationOptions===[])return;
    $profileOptions=[];foreach($rows as$row){$id=(string)($row['profile_id']??'');if($id==='')continue;
        $label=(string)($row['name']??$id);if(count($installationOptions)>1)$label.=' - '.substr((string)($row['installation_id']??''),0,8);
        $profileOptions[$id]=$label;}
    echo'<div class="bulk-profile-tools">';
    echo'<details><summary>Generate unlocked NPC profiles with AI</summary><p>Queues revision-safe generation for up to 100 unlocked NPC profiles in the selected installation. Locked, player, and narrator profiles are excluded; duplicate current-revision jobs remain idempotent.</p>';
    lorkhan_ui_management_form(['route'=>'profile-bulk-generate','id'=>'profile-bulk-generate','legend'=>'Generate unlocked NPC profiles','fields'=>[
        ['installation_id','Installation','select','',$installationOptions],['confirm','Type Generate to confirm']]],$managementBasePath,$csrf);echo'</details>';
    echo'<details><summary>Unlock all NPC profiles</summary><p>Creates an unlocked revision for every locked NPC profile in the selected installation. Player and narrator profiles are excluded.</p>';
    lorkhan_ui_management_form(['route'=>'profile-bulk-unlock','id'=>'profile-bulk-unlock','legend'=>'Unlock all NPC profiles','fields'=>[
        ['installation_id','Installation','select','',$installationOptions],['confirm','Type Unlock to confirm']]],$managementBasePath,$csrf);echo'</details>';
    if(count($profileOptions)>=2){$ids=array_keys($profileOptions);echo'<details><summary>Mass switch bound NPC profiles</summary><p>Moves all OpenMW actor bindings from one profile to another in the selected installation. Locked source profiles are skipped unless explicitly included.</p>';
        lorkhan_ui_management_form(['route'=>'profile-bulk-switch','id'=>'profile-bulk-switch','legend'=>'Switch bound NPC profiles','fields'=>[
            ['installation_id','Installation','select','',$installationOptions],['source_profile_id','From profile','select',$ids[0],$profileOptions],
            ['target_profile_id','To profile','select',$ids[1],$profileOptions],['include_locked','Include a locked source profile','checkbox','1',[],false],
            ['confirm','Type Switch to confirm']]],$managementBasePath,$csrf);echo'</details>';}
    echo'<details class="bulk-danger"><summary>Delete all unlocked NPC profiles</summary><p>Soft-deletes every unlocked NPC profile in the selected installation and clears its OpenMW actor bindings. Locked, player, and narrator profiles are preserved.</p>';
    lorkhan_ui_management_form(['route'=>'profile-bulk-delete','id'=>'profile-bulk-delete','legend'=>'Delete all unlocked NPC profiles','fields'=>[
        ['installation_id','Installation','select','',$installationOptions],['confirm','Type Delete to confirm']]],$managementBasePath,$csrf);echo'</details></div>';
}

/** Render the installation-scoped auto-lock preference used by manual NPC saves. */
function lorkhan_ui_profile_preferences(array $rows,string $managementBasePath,string $csrf):void
{
    if($rows===[])return;echo'<div class="profile-preference-grid">';foreach($rows as$row){$id=(string)($row['installation_id']??'');if($id==='')continue;
        $enabled=in_array($row['auto_lock_on_edit']??true,[true,1,'1','t','true'],true);echo'<article><h4>'.lorkhan_ui_h($row['display_name']??$id).'</h4>';
        lorkhan_ui_management_form(['route'=>'profile-auto-lock','id'=>'profile-auto-lock-'.$id,'legend'=>'Save auto-lock preference','hidden'=>['installation_id'=>$id],
            'fields'=>[['enabled','Auto-lock NPC profiles after manual edits','checkbox','1',[],false,$enabled]]],$managementBasePath,$csrf);
        echo'<p class="management-note">When enabled, a management edit locks the saved profile against later automatic AI biography generation. Bulk unlock remains available.</p></article>';}
    echo'</div>';
}

/** Render versioned NPC profiles using the same compact editor fields consumed by the prompt and TTS runtime. */
function lorkhan_ui_profile_cards(array $rows,array $voiceOptions,array $promptRows,array $llmRows,array $ttsRows,string $managementBasePath,string $csrf,bool $showFilters=true,bool $showSummary=true,bool $compact=false,array $coreProfileRows=[],bool $editorOpen=false):void
{
    if ($rows === []) { echo '<p class="empty-state">No NPC profiles are configured yet.</p>'; return; }
    $allCount=count($rows);if($showFilters)lorkhan_ui_profile_filters($rows);$rows=lorkhan_ui_filter_profiles($rows);
    if($showSummary)echo'<p class="management-note">Showing '.count($rows).' of '.$allCount.' NPC profiles. Favorites are shown first.</p>';
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
        $isTemplate=($identity['kind']??'actor')==='template';
        $management=is_array($content['management']??null)?$content['management']:[];
        $locked=($management['locked']??false)===true;$favorite=($management['favorite']??false)===true;
        $dynamicProfile=($content['dynamic_profile']??false)===true;
        $dynamicFields=is_array($content['dynamic_profile_fields']??null)?$content['dynamic_profile_fields']:['personality','speech_style','goals'];
        $portrait=is_array($content['portrait']??null)?$content['portrait']:[];
        $portraitEndpoint=preg_replace('#/manage$#','/ui/core/profile_portrait.php',$managementBasePath)?:'/LorkhanServer/ui/core/profile_portrait.php';
        $coreProfileOptions=[''=>'Use installation default'];
        foreach($coreProfileRows as$coreProfileRow){
            if((string)($coreProfileRow['installation_id']??'')!==$installationId)continue;
            $coreProfileId=(string)($coreProfileRow['core_profile_id']??'');
            if($coreProfileId!=='')$coreProfileOptions[$coreProfileId]=(string)($coreProfileRow['label']??$coreProfileId);
        }
        $coreProfileId=(string)($row['core_profile_id']??'');
        if($coreProfileId!==''&&!isset($coreProfileOptions[$coreProfileId]))$coreProfileOptions[$coreProfileId]=(string)($row['core_profile_label']??'Unavailable Core Profile');
        echo '<article class="profile-card'.($compact?' profile-card-compact':'').'"><header><div><span class="connector-kind">'.($isTemplate?'Biography template':'OpenMW NPC').'</span><h3>' . lorkhan_ui_h($row['name'] ?? '') . '</h3></div>';
        echo '<div class="profile-statuses">'.($favorite?'<span class="status-badge connector-active">Favorite</span>':'').($locked?'<span class="status-badge profile-locked">Locked</span>':'').'<span class="status-badge">Revision ' . lorkhan_ui_h($row['current_revision'] ?? '') . '</span></div></header>';
        if($portrait!==[])echo'<div class="profile-portrait"><img src="'.lorkhan_ui_h($portraitEndpoint.'?profile_id='.rawurlencode($profileId).'&revision='.(int)($row['current_revision']??1)).'" alt="Portrait of '.lorkhan_ui_h($row['name']??'NPC').'" width="160" height="160"></div>';
        elseif($compact)echo'<div class="profile-portrait profile-portrait-fallback" aria-hidden="true">'.lorkhan_ui_h(mb_strtoupper(mb_substr((string)($row['name']??'N'),0,1))).'</div>';
        if($compact)echo'<dl class="profile-card-glance"><dt>Race</dt><dd>'.lorkhan_ui_h(trim((string)($content['gender']??'').' '.(string)($content['race']??''))?:'Unspecified').'</dd><dt>Voice</dt><dd>'.lorkhan_ui_h($voice['id']??'Connector default').'</dd><dt>Bindings</dt><dd>'.lorkhan_ui_h($row['binding_count']??0).'</dd></dl><details class="profile-routing-summary"><summary>Profile summary</summary>';
        echo '<dl><dt>'.($isTemplate?'Record match':'Record').'</dt><dd><code>' . lorkhan_ui_h($identity['record_id'] ?? ($isTemplate?'Any record':'Unbound profile')) . '</code></dd>';
        echo '<dt>Content file</dt><dd>' . lorkhan_ui_h($identity['content_file'] ?? '') . '</dd>';
        echo '<dt>Gender / race</dt><dd>' . lorkhan_ui_h(trim((string)($content['gender']??'').' '.(string)($content['race']??''))?:'Unspecified') . '</dd>';
        echo '<dt>Voice</dt><dd>' . lorkhan_ui_h($voice['id'] ?? 'Connector default') . '</dd>';
        echo '<dt>Core Profile</dt><dd>' . lorkhan_ui_h($coreProfileOptions[$coreProfileId]??$coreProfileOptions['']) . '</dd>';
        echo '<dt>In-game bindings</dt><dd>' . lorkhan_ui_h($row['binding_count'] ?? 0) . '</dd></dl>';
        if($compact)echo'</details>';
        echo '<details class="profile-primary-editor"'.($editorOpen?' open':'').'><summary>Edit roleplay and voice</summary>';
        lorkhan_ui_management_form([
            'route'=>'profile-revise','id'=>'profile-' . $profileId,'legend'=>'Save NPC profile revision',
            'hidden'=>['profile_id'=>$profileId,'base_content_json'=>json_encode($content===[]?(object)[]:$content,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'management_fields'=>'1','dynamic_profile_fields_present'=>'1'],
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
                ['core_profile_id','Core Profile','select',$coreProfileId,$coreProfileOptions],
                ['voice_id','TTS voice ID (type or choose a stored sample)','datalist',(string)($voice['id']??''),$voiceOptions,false],
                ['voice_language','Voice language','text',(string)($voice['language']??'en')],
                ['locked','Lock against automatic AI profile generation','checkbox','1',[],false,$locked],
                ['dynamic_profile','Enable Dynamic Profile','checkbox','1',[],false,$dynamicProfile],
                ['dynamic_profile_personality','Evolve personality','checkbox','1',[],false,in_array('personality',$dynamicFields,true)],
                ['dynamic_profile_occupation','Evolve occupation','checkbox','1',[],false,in_array('occupation',$dynamicFields,true)],
                ['dynamic_profile_skills','Evolve skills','checkbox','1',[],false,in_array('skills',$dynamicFields,true)],
                ['dynamic_profile_speech_style','Evolve speech style','checkbox','1',[],false,in_array('speech_style',$dynamicFields,true)],
                ['dynamic_profile_goals','Evolve goals','checkbox','1',[],false,in_array('goals',$dynamicFields,true)],
                ['favorite','Favorite NPC','checkbox','1',[],false,$favorite],
                ['notes','Notes','textarea',(string)($content['notes']??''),[],false],
                ['change_reason','Change reason','text','management edit'],
            ],
        ],$managementBasePath,$csrf);
        echo '</details>';
        $portraitControl='portrait-'.substr(hash('sha256',$profileId),0,12);
        echo'<details><summary>Manage portrait</summary><form class="management-form portrait-form" method="post" enctype="multipart/form-data" action="'.lorkhan_ui_h($portraitEndpoint).'"><fieldset><legend>Upload NPC portrait</legend>';
        echo'<label for="'.lorkhan_ui_h($portraitControl).'">PNG, JPEG, or WebP (5 MiB and 2048×2048 maximum)</label><input id="'.lorkhan_ui_h($portraitControl).'" name="portrait" type="file" accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp" required>';
        echo'<input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'"><input type="hidden" name="action" value="upload"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"></fieldset><button class="btn-base btn-primary" type="submit">Upload portrait</button></form>';
        if($portrait!==[])echo'<form method="post" action="'.lorkhan_ui_h($portraitEndpoint).'"><input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base btn-danger" type="submit">Delete portrait</button></form>';
        echo'</details>';
        echo'<details><summary>Clone profile</summary>';
        lorkhan_ui_management_form(['route'=>'profile-clone','id'=>'profile-clone-'.$profileId,'legend'=>'Create independent profile copy',
            'hidden'=>['profile_id'=>$profileId],'fields'=>[['name','New profile name','text',(string)($row['name']??'').' Copy']]],$managementBasePath,$csrf);
        echo'<p class="management-note">The copy starts at revision 1 with the same roleplay details and voice. Actor bindings and the private portrait file stay with the original.</p></details>';
        echo '<div class="connector-actions"><a class="btn-base" href="'.lorkhan_ui_h($managementBasePath.'/exports/profiles/'.$profileId.'.json').'">Export profile</a>';
        if(!$locked)echo '<form method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/profile-generate').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'"><button class="btn-base btn-primary" type="submit">Generate profile with AI</button></form>';
        echo'</div>';
        echo '<p class="management-note">'.($locked?'This profile is locked. Automatic AI generation cannot replace it; manual edits and relationship updates remain available.':'Generation creates a new revision and preserves the configured voice and custom fields. A later manual edit wins if the job is still running.').'</p>';
        lorkhan_ui_revision_actions('profile',$profileId,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf);
        echo '</article>';
    }
    echo '</div>';
}

/** Render one typed NPC revision form inside the tabbed NPC editor. */
function lorkhan_ui_npc_editor_form(array $row,array $voiceOptions,array $promptRows,array $llmRows,array $ttsRows,array $coreProfileRows,string $managementBasePath,string $csrf,bool$creating=false,array$installationOptions=[],array$effectiveSettings=[],array$playthroughOptions=[],array$listState=[]):void
{
    $content=is_array($row['content']??null)?$row['content']:[];$identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];
    $profileId=$creating?'create':(string)($row['profile_id']??'');$installationId=$creating?(string)(array_key_first($installationOptions)??''):(string)($row['installation_id']??'');$formId='management-form-profile-'.$profileId;
    $management=is_array($content['management']??null)?$content['management']:[];$voice=$content['voice']??[];
    if(is_string($voice))$voice=['id'=>$voice];if(!is_array($voice))$voice=[];
    $locked=($management['locked']??false)===true;$favorite=($management['favorite']??false)===true;
    $dynamicProfile=($content['dynamic_profile']??false)===true;
    $dynamicFields=is_array($content['dynamic_profile_fields']??null)?$content['dynamic_profile_fields']:['personality','speech_style','goals'];
    $coreProfileOptions=[''=>'Use installation default'];foreach($coreProfileRows as$coreProfileRow){
        if((string)($coreProfileRow['installation_id']??'')!==$installationId)continue;$id=(string)($coreProfileRow['core_profile_id']??'');
        if($id!=='')$coreProfileOptions[$id]=(string)($coreProfileRow['label']??$id);
    }
    $coreProfileId=(string)($row['core_profile_id']??'');if($coreProfileId!==''&&!isset($coreProfileOptions[$coreProfileId]))$coreProfileOptions[$coreProfileId]=(string)($row['core_profile_label']??'Unavailable Core Profile');
    $field=function(string$name,string$label,string$type='text',string$value='',array$options=[],string$classes='',string$help='',string$placeholder='')use($formId,$profileId,$creating):void{
        $id='npc-editor-'.substr(hash('sha256',$profileId.$name),0,14);$class='form-item'.($classes===''?'':' '.$classes);
        $required=$creating&&in_array($name,['installation_id','name'],true)?' required':'';
        $describe=$help===''?'':' aria-describedby="'.lorkhan_ui_h($id).'-help"';
        echo'<div class="'.lorkhan_ui_h($class).'"><label for="'.lorkhan_ui_h($id).'">'.lorkhan_ui_h($label).'</label>';
        if($type==='textarea')echo'<textarea id="'.lorkhan_ui_h($id).'" name="'.lorkhan_ui_h($name).'" form="'.lorkhan_ui_h($formId).'"'.$required.$describe.' placeholder="'.lorkhan_ui_h($placeholder).'">'.lorkhan_ui_h($value).'</textarea>';
        elseif($type==='select'){echo'<select id="'.lorkhan_ui_h($id).'" name="'.lorkhan_ui_h($name).'" form="'.lorkhan_ui_h($formId).'"'.$required.$describe.'>';foreach($options as$optionValue=>$optionLabel)echo'<option value="'.lorkhan_ui_h($optionValue).'"'.((string)$optionValue===$value?' selected':'').'>'.lorkhan_ui_h($optionLabel).'</option>';echo'</select>';}
        elseif($type==='datalist'){$listId=$id.'-options';echo'<input id="'.lorkhan_ui_h($id).'" name="'.lorkhan_ui_h($name).'" form="'.lorkhan_ui_h($formId).'" type="text" list="'.lorkhan_ui_h($listId).'" value="'.lorkhan_ui_h($value).'"'.$describe.'><datalist id="'.lorkhan_ui_h($listId).'">';foreach($options as$optionValue=>$optionLabel)echo'<option value="'.lorkhan_ui_h(is_int($optionValue)?$optionLabel:$optionValue).'">'.lorkhan_ui_h($optionLabel).'</option>';echo'</datalist>';}
        else echo'<input id="'.lorkhan_ui_h($id).'" name="'.lorkhan_ui_h($name).'" form="'.lorkhan_ui_h($formId).'" type="'.lorkhan_ui_h($type).'" value="'.lorkhan_ui_h($value).'"'.$required.$describe.' placeholder="'.lorkhan_ui_h($placeholder).'">';
        if($help!=='')echo'<small class="hint" id="'.lorkhan_ui_h($id).'-help">'.lorkhan_ui_h($help).'</small>';
        echo'</div>';
    };
    $checkbox=function(string$name,string$label,bool$checked,string$hint='')use($formId):void{echo'<div class="form-item npc-editor-check"><label><input name="'.lorkhan_ui_h($name).'" form="'.lorkhan_ui_h($formId).'" type="checkbox" value="1"'.($checked?' checked':'').'> '.lorkhan_ui_h($label).'</label>'.($hint===''?'':'<small class="hint">'.lorkhan_ui_h($hint).'</small>').'</div>';};
    $disabled=function(string$label,string$feature,string$value='',string$classes='')use($formId):void{$id='npc-disabled-'.substr(hash('sha256',$formId.$label),0,14);echo'<div class="form-item npc-editor-disabled'.($classes===''?'':' '.lorkhan_ui_h($classes)).'"><label for="'.$id.'">'.lorkhan_ui_h($label).' '.lorkhan_ui_feature_badge($feature,true).'</label><input id="'.$id.'" type="text" value="'.lorkhan_ui_h($value).'" disabled aria-disabled="true" title="'.lorkhan_ui_h(lorkhan_ui_feature($feature)['description']).'"></div>';};
    echo'<div class="npc-editor-meta"><label for="npc-editor-tags-'.$profileId.'">Tags:</label><input id="npc-editor-tags-'.$profileId.'" name="tags" form="'.lorkhan_ui_h($formId).'" value="'.lorkhan_ui_h(is_array($content['tags']??null)?implode(', ',array_map('strval',$content['tags'])):(string)($content['tags']??'')).'" placeholder="tags">'.'<label class="npc-editor-favorite" title="Favorite NPC"><input type="checkbox" name="favorite" form="'.lorkhan_ui_h($formId).'" value="1"'.($favorite?' checked':'').'><span>'.($favorite?'&#9733;':'&#9734;').'</span></label></div>';
    // Publish only connector labels for the selected Core Profile, never provider configuration.
    $llmLabels=[];foreach($llmRows as$llm)if(($llm['installation_id']??'')===$installationId)$llmLabels[(string)$llm['configuration_id']]=(string)$llm['name'];
    $llmSummaries=[];$defaultCoreId='';
    foreach($coreProfileRows as$core){
        if(($core['installation_id']??'')!==$installationId)continue;
        $routing=is_array($core['content']['routing']??null)?$core['content']['routing']:[];$summary=[];
        foreach(['llm_configuration_id'=>['🕹️','Standard'],'llm_fast_configuration_id'=>['🏃','Fast'],
            'llm_powerful_configuration_id'=>['💪','Powerful'],'llm_experimental_configuration_id'=>['🧪','Experimental'],
            'diary_generation_configuration_id'=>['📓','Diary']]as$key=>[$icon,$label]){
            $id=(string)($routing[$key]??'');$summary[]=$icon.' '.($id!==''?($llmLabels[$id]??'Missing connector'):($label==='Diary'?'Disabled':'Inherited'));
        }
        $llmSummaries[(string)$core['core_profile_id']]=implode(' | ',$summary);
        if(filter_var($core['default_npc']??false,FILTER_VALIDATE_BOOL))$defaultCoreId=(string)$core['core_profile_id'];
    }
    $llmSummaries['']=$llmSummaries[$defaultCoreId]??'No default Core Profile';
    echo'<div class="npc-profile-llms" data-profile-llm-summary data-profile-form="'.lorkhan_ui_h($formId).'" data-profile-summaries="'.lorkhan_ui_h(json_encode($llmSummaries,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'"><strong>Profile LLMs</strong><span title="Standard | Fast | Powerful | Experimental | Diary">'.lorkhan_ui_h($llmSummaries[$coreProfileId]??'Missing Core Profile').'</span></div>';
    echo'<div class="npc-editor-tabs" role="tablist" aria-label="NPC editor categories" data-npc-editor-tabs>';
    foreach(['general'=>'&#129517; General','roleplay'=>'&#128214; Roleplay','relationships'=>'&#129309; Relationships','info'=>'&#128736;&#65039; Info','actions'=>'&#9889; Actions','history'=>'&#128220; History']as$key=>$label)echo'<button type="button" class="npc-editor-tab'.($key==='general'?' is-active':'').'" role="tab" aria-selected="'.($key==='general'?'true':'false').'" tabindex="'.($key==='general'?'0':'-1').'" data-npc-editor-tab="'.$key.'">'.$label.'</button>';
    echo'</div><div class="npc-editor-panels">';
    echo'<section class="npc-editor-panel form-grid" role="tabpanel" data-npc-editor-panel="general">';
    if($creating){$field('installation_id','Installation','select',$installationId,$installationOptions, 'span-2');$field('name','NPC Name','text','',[],'span-2');}
    else$disabled('NPC Name','config.npc.identity',(string)($row['name']??''),'span-2');
    $field('core_profile_id','Profile','select',$coreProfileId,$coreProfileOptions);$checkbox('locked','Lock This NPC',$locked,'Prevents automatic AI profile generation from replacing manual edits.');
    $field('gender','Gender','select',(string)($content['gender']??''),[''=>'Unspecified','Male'=>'Male','Female'=>'Female','Other'=>'Other']);$field('race','Race','text',(string)($content['race']??''));
    if($creating){$field('content_file','Base / Content File','text','Morrowind.esm');$field('record_id','Ref ID','text','');$field('refnum','Reference Number','text','');}
    else{$disabled('Base / Content File','config.npc.identity',(string)($identity['content_file']??''));$disabled('Ref ID','config.npc.identity',(string)($identity['record_id']??''));}
    $knowledgeTags=$content['oghma_knowledge_tags']??$content['oghma_tags']??'';
    $field('oghma_knowledge_tags','Oghma Tags','text',is_array($knowledgeTags)?implode(', ',array_map('strval',$knowledgeTags)):(string)$knowledgeTags,[],'','Used by Oghma systems for knowledge lookup restrictions.','Comma-separated knowledge tags');
    $field('voice_id','Voice sample','datalist',(string)($voice['id']??''),$voiceOptions);$field('voice_language','Voice Language','text',(string)($voice['language']??'en'));
    $checkbox('dynamic_profile','♻️Dynamic Profile',$dynamicProfile,'Every 20 minutes, evolve selected fields from witnessed dialogue while this NPC is nearby and unlocked.');
    echo'<input type="hidden" name="dynamic_profile_fields_present" form="'.lorkhan_ui_h($formId).'" value="1"><details class="form-item npc-editor-check npc-dynamic-fields"><summary>Dynamic Profile Fields</summary>';
    foreach(['personality'=>'Personality','occupation'=>'Occupation','skills'=>'Skills','speech_style'=>'Speech Style','goals'=>'Goals']as$key=>$label)echo'<label><input name="dynamic_profile_fields[]" form="'.lorkhan_ui_h($formId).'" type="checkbox" value="'.$key.'"'.(in_array($key,$dynamicFields,true)?' checked':'').'> '.$label.'</label>';
    echo'<small class="hint">Choose at least one field when Dynamic Profile is enabled.</small></details>';
    foreach(['automatic_enabled'=>['📙 Auto Diary','Generate diary entries on the configured timer and sleep events. Requires Diary generation and a Diary LLM in Core Profile.'],
        'automatic_wait_enabled'=>['⏳ Auto Diary Wait','When Auto Diary is enabled, include wait events as well as sleep events.']]as$key=>[$label,$help]){
        $defaults=[];foreach($coreProfileRows as$core){
            $scope=(string)($core['installation_id']??'');$id=(string)$core['core_profile_id'];
            $defaults[$scope][$id]=($core['content']['settings_overrides']['diary'][$key]??false)===true;
            if(filter_var($core['default_npc']??false,FILTER_VALIDATE_BOOL))$defaults[$scope]['']=$defaults[$scope][$id];
        }
        $own=$content['diary'][$key]??null;$value=is_bool($own)?($own?'1':'0'):'inherit';
        $checked=is_bool($own)?$own:($defaults[$installationId][$coreProfileId]??false);
        echo'<div class="form-item npc-editor-check npc-diary-control" data-npc-inherited data-profile-form="'.lorkhan_ui_h($formId).'" data-installation-id="'.lorkhan_ui_h($installationId).'" data-core-defaults="'.lorkhan_ui_h(json_encode($defaults)).'">';
        echo'<label><input type="checkbox" form="'.lorkhan_ui_h($formId).'" data-npc-inherited-toggle'.($checked?' checked':'').'> '.lorkhan_ui_h($label).'</label>';
        echo'<input type="hidden" name="npc_diary_'.$key.'" form="'.lorkhan_ui_h($formId).'" value="'.$value.'">';
        echo'<small class="hint">'.lorkhan_ui_h($help).' <strong data-npc-inherited-source>'.($value==='inherit'?'(Inherited from profile)':'(NPC override)').'</strong></small>';
        echo'<button type="button" class="npc-inherit-button" data-npc-inherited-reset'.($value==='inherit'?' disabled':'').'>Use Core Profile</button></div>';
    }
    $field('prompt_head','Prompt head (advanced system guidance)','textarea',(string)($content['prompt_head']??''),[],'span-2');echo'</section>';
    echo'<section class="npc-editor-panel form-grid" role="tabpanel" data-npc-editor-panel="roleplay" hidden>';
    $field('core','Core','textarea',(string)($content['core']??''),[],'span-2','Core NPC description. 1–2 sentences describing the character.','Unchanging rules, boundaries, and core identity.');
    $field('biography','Backstory','textarea',(string)($content['biography']??''),[],'span-2','Historical facts and background information.','Fixed background, history, and facts.');
    $field('appearance','Appearance','textarea',(string)($content['appearance']??''),[],'span-2','Physical appearance. Keep it limited to character cosmetics, not equipment.','Physical appearance.');
    $field('personality','Personality','textarea',(string)($content['personality']??''),[],'','Traits and quirks that guide tone and behavior.','Personality traits and speaking characteristics.');
    $field('occupation','Occupation','textarea',(string)($content['occupation']??''),[],'','Primary role or job. Include relevant guilds or factions.','Role, job, affiliations.');
    $field('skills','Skills','textarea',(string)($content['skills']??''),[],'','Highlight notable competencies of the NPC.','Strengths, abilities, and specialties.');
    $field('speech_style','Speech Style','textarea',(string)($content['speech_style']??''),[],'','How the NPC speaks their dialogue.','Dialect, cadence, verbal tics.');
    $field('goals','Goals','textarea',(string)($content['goals']??''),[],'','General motivations and goals used during regular dialogue.','Short and long-term objectives.');echo'</section>';
    echo'<section class="npc-editor-panel form-grid" role="tabpanel" data-npc-editor-panel="relationships" hidden>';
    include __DIR__.'/npc_relationships.html.php';
    echo'</section>';
    echo'<section class="npc-editor-panel form-grid" role="tabpanel" data-npc-editor-panel="info" hidden>';
    $field('emote_moods','Emote Moods Override','textarea',(string)($content['emote_moods']??''),[],'span-2','Allowed mood/emote cues. Leave empty to use the inherited defaults.');
    include __DIR__.'/npc_observed_state.php';
    include __DIR__.'/npc_profile_metadata.php';
    include __DIR__.'/npc_setting_overrides.php';
    if(!$creating&&$effectiveSettings!==[]){echo'<div class="span-2">';lorkhan_ui_effective_settings_summary($effectiveSettings,'Effective NPC settings and sources');echo'</div>';}
    $field('notes','Notes','textarea',(string)($content['notes']??''),[],'span-2');if(!$creating)$field('change_reason','Change Reason','text','management edit',[],'span-2');echo'</section>';
    echo'<section class="npc-editor-panel form-grid" role="tabpanel" data-npc-editor-panel="actions" hidden>';
    echo'<p class="npc-editor-action-note">Visit and Teleport require profile-targeted movement support in the OpenMW client. They are not available yet.</p><div class="npc-editor-action-list">';
    foreach(['Visit'=>"Move the player to this NPC’s current position.",'Teleport'=>"Move this NPC to the player’s current position and save their previous location."]as$label=>$description)
        echo'<article class="npc-editor-action-card"><div><h3>'.lorkhan_ui_h($label).'</h3><p>'.lorkhan_ui_h($description).'</p></div><button type="button" class="btn-base" disabled title="Profile-targeted movement is not supported by the current OpenMW client.">'.lorkhan_ui_h($label).'</button></article>';
    echo'</div></section>';
    lorkhan_ui_npc_history_panel($profileId,$creating,is_array($playthroughOptions[$installationId]??null)?$playthroughOptions[$installationId]:[],$managementBasePath,$csrf);
    echo'</div><form id="'.lorkhan_ui_h($formId).'" method="post" data-track-dirty action="'.lorkhan_ui_h($managementBasePath.'/forms/'.($creating?'profile-create':'profile-revise')).'">';
    if(!$creating)echo'<input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'">';
    echo'<input type="hidden" name="management_fields" value="1"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'">';
    lorkhan_ui_hidden_state($listState);echo'</form>';
}

/** Render profiles with the same left-list and right-editor hierarchy used by CHIM. */
function lorkhan_ui_profiles_page(array $rows,array $forms,array $voiceOptions,array $promptRows,array $llmRows,array $ttsRows,string $description,string $managementBasePath,string $csrf):void
{
    $selectedId=(string)($_GET['selected']??'');$selected=null;
    foreach($rows as$row)if(hash_equals((string)($row['profile_id']??''),$selectedId)){$selected=$row;break;}
    echo'<header class="configuration-page-header"><h1>LORKHAN Profiles</h1><p>'.lorkhan_ui_h($description).'</p></header>';
    echo'<div class="configuration-split-shell"><aside class="configuration-sidebar"><div class="configuration-sidebar-actions">';
    foreach($forms as$index=>$form){echo'<details class="configuration-action-panel"><summary class="btn-base '.($index===0?'btn-success':'btn-primary').'">'.lorkhan_ui_h($form['legend']??'Manage').'</summary>';lorkhan_ui_management_form($form,$managementBasePath,$csrf);echo'</details>';}
    echo'</div><div class="configuration-record-list">';
    foreach($rows as$row){$id=(string)($row['profile_id']??'');$content=is_array($row['content']??null)?$row['content']:[];$management=is_array($content['management']??null)?$content['management']:[];
        $query=http_build_query(['embed'=>($_GET['embed']??'')==='1'?'1':null,'selected'=>$id]);
        echo'<a class="configuration-record'.($selected!==null&&hash_equals($selectedId,$id)?' active':'').'" href="?'.lorkhan_ui_h($query).'"><span><strong>'.lorkhan_ui_h($row['name']??'Profile').'</strong><small>'.lorkhan_ui_h(($row['binding_count']??0).' NPC binding'.((int)($row['binding_count']??0)===1?'':'s')).'</small></span><span class="status-badge">'.(($management['locked']??false)===true?'Locked':'Rev '.(int)($row['current_revision']??1)).'</span></a>';}
    echo'</div></aside><section class="configuration-detail">';
    if($selected===null)echo'<div class="configuration-empty"><h2>No profile selected</h2><p>Select a profile from the list on the left to view and edit its settings.</p></div>';
    else lorkhan_ui_profile_cards([$selected],$voiceOptions,$promptRows,$llmRows,$ttsRows,$managementBasePath,$csrf,false,false);
    echo'</section></div>';
}

/** Render the player editor as the same centered header and two-column settings surface used by CHIM. */
function lorkhan_ui_player_page(array $rows,array $forms,string $description,string $managementBasePath,string $csrf):void
{
    echo'<header class="configuration-page-header"><h1>&#128100; Player Management</h1><p>'.lorkhan_ui_h($description).'</p></header>';
    echo'<section class="player-settings-shell">';
    if($rows===[]){foreach($forms as$form)lorkhan_ui_management_form($form,$managementBasePath,$csrf);}
    else lorkhan_ui_player_cards($rows,$managementBasePath,$csrf);
    echo'</section>';
}

/** Render narrator controls in the same centered, two-column settings format as CHIM. */
function lorkhan_ui_narrator_page(array $rows,array $forms,array $voiceOptions,array $ttsRows,string $description,string $managementBasePath,string $csrf):void
{
    echo'<header class="configuration-page-header"><h1>&#128483; Narrator Management</h1><p>'.lorkhan_ui_h($description).'</p></header>';
    echo'<section class="narrator-settings-shell">';
    if($rows===[]){foreach($forms as$form)lorkhan_ui_management_form($form,$managementBasePath,$csrf);}
    else lorkhan_ui_narrator_cards($rows,$voiceOptions,$ttsRows,$managementBasePath,$csrf);
    echo'</section>';
}

/** Render LORKHAN profiles with the card hierarchy and modal editing flow used by CHIM's NPC page. */
function lorkhan_ui_chim_profile_cards(array $rows,array $voiceOptions,array $promptRows,array $llmRows,array $ttsRows,array $coreProfileRows,array $effectiveProfileSettings,string $managementBasePath,string $csrf,array $playthroughOptionsByInstallation=[],array $listState=[]):void
{
    if($rows===[]){echo'<p class="npc-empty-state">No NPC profiles match these filters.</p>';return;}
    $portraitEndpoint=preg_replace('#/manage$#','/ui/core/profile_portrait.php',$managementBasePath)?:'/LorkhanServer/ui/core/profile_portrait.php';
    echo'<div class="npc-grid">';
    foreach($rows as$row){
        $content=is_array($row['content']??null)?$row['content']:[];$identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];
        $management=is_array($content['management']??null)?$content['management']:[];
        $voice=$content['voice']??[];if(is_string($voice))$voice=['id'=>$voice];if(!is_array($voice))$voice=[];
        $portrait=is_array($content['portrait']??null)?$content['portrait']:[];$profileId=(string)($row['profile_id']??'');
        $modalKey='npc-'.substr(hash('sha256',$profileId),0,12);$name=(string)($row['name']??'OpenMW NPC');
        $gender=(string)($content['gender']??'');$genderKey=mb_strtolower($gender);$genderIcon=$genderKey==='female'?'&#9792;':($genderKey==='male'?'&#9794;':'&#9893;');
        $genderClass=$genderKey==='female'?'gender-female':($genderKey==='male'?'gender-male':'gender-nb');
        $tags=$content['tags']??'';if(is_array($tags))$tags=implode(', ',array_map('strval',$tags));$tags=trim((string)$tags);
        $coreProfileLabel=(string)($row['core_profile_label']??'Installation Default');
        $locked=($management['locked']??false)===true;$favorite=($management['favorite']??false)===true;
        $recordId=(string)($identity['record_id']??'Unbound');
        echo'<article class="npc-card" tabindex="0" role="button" aria-label="Edit '.lorkhan_ui_h($name).'" data-npc-modal-target="'.$modalKey.'-edit">';
        echo'<div class="npc-title"><div class="npc-title-left"><span class="npc-name">'.lorkhan_ui_h($name).' ('.(int)($row['current_revision']??1).')</span><span class="npc-gender-icon '.$genderClass.'" title="'.lorkhan_ui_h($gender?:'Unspecified').'">'.$genderIcon.'</span></div>';
        echo'<div class="npc-title-actions"><span class="npc-tags-label">Tags:</span><span class="npc-tags-top" title="'.lorkhan_ui_h($tags?:'none').'">'.lorkhan_ui_h($tags?:'none').'</span>';
        echo'<form class="npc-icon-form" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/profile-toggle-favorite').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'">';lorkhan_ui_hidden_state($listState);echo'<button class="btn btn-toggle'.($favorite?' active':'').'" type="submit" data-favorite-id="'.lorkhan_ui_h($profileId).'" title="Toggle favorite" aria-label="Toggle favorite">'.($favorite?'&#9733;':'&#9734;').'</button></form>';
        echo'<button class="btn btn-toggle" type="button" data-pick-picture-id="'.lorkhan_ui_h($profileId).'" data-npc-modal-target="'.$modalKey.'-edit" title="Set picture" aria-label="Set picture">&#128444;&#65039;</button>';
        echo'<form class="npc-icon-form" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/profile-toggle-lock').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'">';lorkhan_ui_hidden_state($listState);echo'<button class="btn btn-toggle'.($locked?' active':'').'" type="submit" data-lock-id="'.lorkhan_ui_h($profileId).'" title="Toggle lock" aria-label="Toggle lock">'.($locked?'&#128274;':'&#128275;').'</button></form>';
        echo'<button class="btn btn-trash'.($locked?' disabled':'').'" type="button"'.($locked?' disabled title="Locked - cannot delete"':' data-npc-modal-target="'.$modalKey.'-delete" title="Delete"').' aria-label="Delete profile">&#10060;</button></div></div>';
        echo'<div class="npc-divider"></div><div class="npc-row"><div class="npc-fields">';
        echo'<div class="npc-line"><span class="npc-muted">Gender:</span> '.lorkhan_ui_h($gender?:'Unspecified').'</div>';
        echo'<div class="npc-line"><span class="npc-muted">Race:</span> '.lorkhan_ui_h($content['race']??'Unspecified').'</div>';
        echo'<div class="npc-line"><span class="npc-muted">Voice:</span> '.lorkhan_ui_h($voice['id']??'Connector default').'</div>';
        echo'<div class="npc-line"><span class="npc-muted">RefID:</span> '.lorkhan_ui_h($recordId).'</div>';
        echo'<div class="npc-line"><span class="npc-muted">Oghma Tags:</span> '.lorkhan_ui_h($tags?:'none').'</div>';
        echo'<div class="npc-line"><span class="npc-muted">Profile:</span> '.lorkhan_ui_h($coreProfileLabel).'</div></div><div class="npc-right">';
        if($portrait!==[])echo'<img class="npc-race-art" src="'.lorkhan_ui_h($portraitEndpoint.'?profile_id='.rawurlencode($profileId).'&revision='.(int)($row['current_revision']??1)).'" alt="Portrait of '.lorkhan_ui_h($name).'">';
        else echo'<div class="npc-race-art npc-race-art-placeholder" aria-label="Portrait placeholder"><strong>'.lorkhan_ui_h(mb_strtoupper(mb_substr($name,0,1))).'</strong><span>'.lorkhan_ui_h($content['race']??'Morrowind NPC').'</span></div>';
        echo'</div></div></article>';

        $exportUrl=$managementBasePath.'/exports/profiles/'.$profileId.'.json';
        $diaryUrl=(preg_replace('#/manage$#','/ui/diary_book.php',$managementBasePath)?:'/LorkhanServer/ui/diary_book.php').'?'.http_build_query(['installation_id'=>(string)$row['installation_id'],'person'=>$profileId]);
        $biographiesUrl=preg_replace('#/manage$#','/ui/core/npc_biographies.php',$managementBasePath)?:'/LorkhanServer/ui/core/npc_biographies.php';
        echo'<div class="npc-modal-overlay" id="'.$modalKey.'-edit" data-npc-modal hidden><section class="npc-modal npc-editor-modal" role="dialog" aria-modal="true" aria-labelledby="'.$modalKey.'-edit-title"><header class="npc-editor-header"><h2 id="'.$modalKey.'-edit-title">Edit NPC</h2><div class="npc-modal-actions">';
        echo'<button type="submit" class="btn-save" form="management-form-profile-'.lorkhan_ui_h($profileId).'">Save</button>';
        echo'<a class="btn-cancel" href="'.lorkhan_ui_h($exportUrl).'">Export Bio</a>';
        echo'<button type="button" class="btn-cancel" data-npc-import-to="'.lorkhan_ui_h($profileId).'" data-base-revision="'.(int)$row['current_revision'].'" data-import-url="'.lorkhan_ui_h($managementBasePath.'/forms/profile-import-to').'">Import Bio</button>';
        $canReset=in_array($identity['kind']??'',['actor','npc','creature'],true)&&trim((string)($identity['record_id']??''))!==''&&trim((string)($identity['content_file']??''))!=='';
        echo'<form class="npc-modal-header-form" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/profile-reset-biography').'" data-confirm="Reload non-empty biography template fields? Voice, connectors, identity and history stay unchanged. This creates a restorable profile revision."><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'"><input type="hidden" name="base_revision" value="'.(int)$row['current_revision'].'"><input type="hidden" name="confirm_reset" value="1">';lorkhan_ui_hidden_state($listState);echo'<button class="btn-cancel" type="submit"'.(!$canReset?' disabled title="Bind this profile to an NPC before resetting its biography"':' title="Reload non-empty biography template fields"').'>Reset NPC</button></form>';
        echo'<a class="btn-cancel" target="_blank" rel="noopener" href="'.lorkhan_ui_h($diaryUrl).'">View Diary</a>';
        echo'<a class="btn-cancel" href="#'.$modalKey.'-versions-title">Profile Versions</a>';

        if(!$locked){echo'<form class="npc-modal-header-form" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/profile-generate').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'">';lorkhan_ui_hidden_state($listState);echo'<button type="submit" class="btn-cancel">AI Generate Profile</button></form>';}
        echo'<button type="button" class="btn-cancel" data-npc-modal-close>Close</button></div></header><div class="npc-modal-body"><div class="npc-editor-viewport"><div class="npc-editor-content">';
        lorkhan_ui_npc_editor_form($row,$voiceOptions,$promptRows,$llmRows,$ttsRows,$coreProfileRows,$managementBasePath,$csrf,false,[],$effectiveProfileSettings[$profileId]??[],$playthroughOptionsByInstallation,$listState);
        $portraitControl='npc-editor-portrait-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$profileId);echo'<div class="npc-editor-secondary"><a class="btn-base" target="_blank" rel="noopener" href="'.lorkhan_ui_h((preg_replace('#/manage$#','',$managementBasePath)?:'/LorkhanServer').'/ui/oghma_knowledge.php?installation_id='.rawurlencode((string)$row['installation_id']).'&profile_id='.rawurlencode($profileId)).'">Oghma Knowledge</a><a class="btn-base" href="'.lorkhan_ui_h($biographiesUrl).'">Open NPC Biographies</a><details><summary>Manage portrait</summary><form class="management-form portrait-form" method="post" enctype="multipart/form-data" action="'.lorkhan_ui_h($portraitEndpoint).'"><fieldset><legend>Upload NPC portrait</legend><label for="'.lorkhan_ui_h($portraitControl).'">PNG, JPEG, or WebP (5 MiB and 2048×2048 maximum)</label><input id="'.lorkhan_ui_h($portraitControl).'" name="portrait" type="file" accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp" required><input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'"><input type="hidden" name="action" value="upload"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"></fieldset><button class="btn-base btn-primary" type="submit">Upload portrait</button></form>';
        if($portrait!==[])echo'<form method="post" action="'.lorkhan_ui_h($portraitEndpoint).'"><input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base btn-danger" type="submit">Delete portrait</button></form>';
        echo'</details>';
        echo'<details><summary>Clone profile</summary>';
        lorkhan_ui_management_form(['route'=>'profile-clone','id'=>'npc-profile-clone-'.$profileId,'legend'=>'Create independent profile copy','hidden'=>['profile_id'=>$profileId]+$listState,'fields'=>[['name','New profile name','text',$name.' Copy']]],$managementBasePath,$csrf);
        echo'<p class="management-note">The copy starts at revision 1 with the same roleplay and connector settings. Actor bindings and the private portrait file stay with the original.</p></details>';
        echo'<section class="npc-profile-versions" aria-labelledby="'.$modalKey.'-versions-title"><h3 id="'.$modalKey.'-versions-title">Profile Versions</h3><p class="management-note">Saved revisions of this NPC profile. In-game narrative events are on the History tab.</p>';
        lorkhan_ui_revision_actions('profile',$profileId,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf,null,true,'',$listState);
        echo'</section></div>';
        echo'</div></div></div></section></div>';
        echo'<div class="npc-modal-overlay" id="'.$modalKey.'-delete" data-npc-modal hidden><section class="npc-modal npc-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="'.$modalKey.'-delete-title"><header><h2 id="'.$modalKey.'-delete-title">Delete '.lorkhan_ui_h($name).'</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body"><p>This permanently removes the current LORKHAN profile and its OpenMW actor bindings.</p><form method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/profile-delete').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'">';lorkhan_ui_hidden_state($listState);echo'<button class="btn-base btn-danger" type="submit">Delete profile</button></form></div></section></div>';
    }
    echo'</div>';
}

/** Render the CHIM NPC-page composition while retaining LORKHAN's typed, revisioned operations. */
function lorkhan_ui_character_manager(array $rows,array $observedNpcs,array $profilePreferenceRows,array $installationOptions,array $playthroughOptions,array $playthroughOptionsByInstallation,array $voiceOptions,array $promptRows,array $llmRows,array $ttsRows,array $coreProfileRows,ProductRepository $productRepository,array $forms,string $managementBasePath,string $csrf):void
{
    $filtered=lorkhan_ui_filter_profiles($rows);$totalRows=count($filtered);$perPage=12;$totalPages=max(1,(int)ceil($totalRows/$perPage));
    $page=max(1,min($totalPages,(int)($_GET['page']??1)));$pageRows=array_slice($filtered,($page-1)*$perPage,$perPage);
    $effectiveProfileSettings=[];
    foreach($pageRows as$profileIndex=>$profileRow){
        $profileId=(string)($profileRow['profile_id']??'');$installationId=(string)($profileRow['installation_id']??'');
        if($profileId!==''&&$installationId!=='')$effectiveProfileSettings[$profileId]=$productRepository->effectiveSettingsForProfile($installationId,$profileId);
        if($profileId!==''&&$installationId!=='')$pageRows[$profileIndex]['observed_state']=$productRepository->npcObservedState($installationId,$profileId);
    }
    $pageWindow=min(10,$totalPages);$pageStart=max(1,min($page-4,$totalPages-$pageWindow+1));$pageEnd=min($totalPages,$pageStart+$pageWindow-1);
    $query=(string)($_GET['q']??'');$profileFilter=(string)($_GET['profile']??'');$state=(string)($_GET['state']??'all');$initial=(string)($_GET['initial']??'');
    $coreProfileOptions=[];foreach($coreProfileRows as$coreProfileRow){$id=(string)($coreProfileRow['core_profile_id']??'');if($id!=='')$coreProfileOptions[$id]=(string)($coreProfileRow['label']??$id);}
    $favoriteOnly=(string)($_GET['fav']??'')==='1';$lockedOnly=(string)($_GET['lock']??'')==='1';
    $installationFilter=(string)($_GET['installation_id']??'');if(!isset($installationOptions[$installationFilter]))$installationFilter='';
    $uiState=['embed'=>($_GET['embed']??'')==='1'?'1':'','q'=>$query,'profile'=>$profileFilter,
        'state'=>$state==='all'?'':$state,'initial'=>$initial,'fav'=>$favoriteOnly?'1':'','lock'=>$lockedOnly?'1':'',
        'page'=>$page>1?(string)$page:'','installation_id'=>$installationFilter];
    $hidden=function(array$omit=[])use($uiState):void{foreach($uiState as$name=>$value)if($value!==''&&!in_array($name,$omit,true))echo'<input type="hidden" name="'.lorkhan_ui_h($name).'" value="'.lorkhan_ui_h($value).'">';};
    $postState=[];foreach($uiState as$name=>$value)if($value!=='')$postState['ui_'.$name]=$value;
    echo'<section class="npc-manager-shell"><div class="pagination npc-toolbar"><div class="npc-toolbar-main"><div class="npc-toolbar-actions">';
    foreach([['npc-create-modal','+ Create NPC','',true],['npc-import-modal','&#128229; Import NPC','Import an LORKHAN profile from JSON',true],['npc-relationships-modal','&#128279; Build Relationships','Convert saved profile Relationships text into relationship records',true],['npc-generate-modal','&#10024; Generate Profiles','Generate unlocked NPC profiles with AI',true],['npc-switch-modal','&#128256; Mass Switch Profile','Switch all NPCs from one Core Profile to another',true],['npc-unlock-modal','&#128275; Unlock All Profiles','Unlock NPC profiles',true],['npc-delete-all-modal','&#10060; Delete All Profiles','Delete all unlocked NPC profiles',true]]as$index=>$button)
        echo'<button type="button" class="npc-toolbar-btn npc-toolbar-btn-uniform '.($index===6?'npc-toolbar-btn-danger':'npc-toolbar-btn-action').'"'.($button[3]?' data-npc-modal-target="'.$button[0].'"':' disabled aria-disabled="true"').($button[2]!==''?' title="'.lorkhan_ui_h($button[2]).'"':'').'>'.$button[1].'</button>';
    echo'</div><form class="npc-toolbar-tools" method="get" data-npc-filter-form>';$hidden(['q','profile']);
    echo'<label class="visually-hidden" for="npc_search">Search NPCs</label><input id="npc_search" type="text" name="q" maxlength="100" placeholder="Search..." aria-label="Search NPCs" value="'.lorkhan_ui_h($query).'">';
    echo'<label class="visually-hidden" for="npc_profile_filter">Filter by Core Profile</label><select id="npc_profile_filter" name="profile" aria-label="Filter by Core Profile"><option value="">All Profiles</option>';foreach($coreProfileOptions as$id=>$label)echo'<option value="'.lorkhan_ui_h($id).'"'.($profileFilter===$id?' selected':'').'>'.lorkhan_ui_h($label).'</option>';echo'</select></form></div>';
    echo'<div class="npc-toolbar-subrow"><div class="npc-toolbar-pager">';
    echo'<button type="button" class="npc-letter-btn npc-page-link" data-page="1"'.($page<=1?' disabled aria-disabled="true"':'').'>First</button><button type="button" class="npc-letter-btn npc-page-link" data-page="'.max(1,$page-1).'"'.($page<=1?' disabled aria-disabled="true"':'').'>Prev</button>';
    for($p=$pageStart;$p<=$pageEnd;$p++)echo'<button type="button" class="npc-letter-btn npc-page-link'.($p===$page?' active':'').'" data-page="'.$p.'"'.($p===$page?' disabled aria-current="page"':'').'>'.$p.'</button>';
    echo'<button type="button" class="npc-letter-btn npc-page-link" data-page="'.min($totalPages,$page+1).'"'.($page>=$totalPages?' disabled aria-disabled="true"':'').'>Next</button><button type="button" class="npc-letter-btn npc-page-link" data-page="'.$totalPages.'"'.($page>=$totalPages?' disabled aria-disabled="true"':'').'>Last</button><div class="npc-page-indicator" title="Current page">'.$page.'/'.$totalPages.'</div></div></div>';
    echo'<div class="npc-toolbar-letter-row"><div class="npc-letter-filter" role="group" aria-label="Filter NPCs by first letter">';
    echo'<button class="npc-letter-btn'.($initial===''?' active':'').'" type="button" data-letter="">All</button>';foreach(range('A','Z')as$letter)echo'<button class="npc-letter-btn'.($initial===$letter?' active':'').'" type="button" data-letter="'.$letter.'">'.$letter.'</button>';echo'</div>';
    $preference=$profilePreferenceRows[0]??null;if(is_array($preference)){echo'<form class="npc-auto-lock-profile" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/profile-auto-lock').'" data-auto-lock-form><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="installation_id" value="'.lorkhan_ui_h($preference['installation_id']??'').'">';lorkhan_ui_hidden_state($postState);echo'<label title="When enabled, saving an NPC profile automatically locks it against automatic replacement."><input type="checkbox" name="enabled" value="1"'.(in_array($preference['auto_lock_on_edit']??true,[true,1,'1','t','true'],true)?' checked':'').'> Auto Lock Profiles on Edit</label></form>';}
    else echo'<label class="npc-auto-lock-profile unavailable" title="Available after the first installation is paired"><input type="checkbox" disabled> Auto Lock Profiles on Edit</label>';
    echo'<div class="npc-toolbar-summary"><div class="npc-filter-dropdown"><button type="button" class="npc-toolbar-btn npc-toolbar-btn-uniform npc-toolbar-btn-action npc-toolbar-filter-btn" data-filter-menu-toggle aria-expanded="false">&#9662; Filters</button><form class="npc-filter-menu" method="get" data-filter-menu hidden>';$hidden(['state','fav','lock']);
    echo'<label><input type="checkbox" name="fav" value="1"'.($favoriteOnly?' checked':'').'> &#11088; Favorites</label>';
    echo'<label><input type="checkbox" name="lock" value="1"'.($lockedOnly?' checked':'').'> &#128274; Locked</label>';
    echo'</form></div><div class="npc-total-pill" title="Total NPC profiles"><span class="npc-total-pill-icon">&#128101;</span><strong class="npc-total-pill-value">'.$totalRows.'</strong></div></div></div></div>';
    echo'<aside class="npc-history-pullback"><strong>History Pullback:</strong> LORKHAN preserves every NPC revision. OpenMW save-time profile pullback is not available yet, so loading an older save does not silently replace server profiles.<br><span>Lock a profile (&#128274;) to protect it from automatic AI generation.</span> Use Profile Versions in the edit modal to inspect and restore an earlier version, and the History tab to read recorded narrative events.</aside>';
    echo'<div class="npc-profile-results">';lorkhan_ui_chim_profile_cards($pageRows,$voiceOptions,$promptRows,$llmRows,$ttsRows,$coreProfileRows,$effectiveProfileSettings,$managementBasePath,$csrf,$playthroughOptionsByInstallation,$postState);echo'</div>';

    $biographiesUrl=preg_replace('#/manage$#','/ui/core/npc_biographies.php',$managementBasePath)?:'/LorkhanServer/ui/core/npc_biographies.php';
    echo'<div class="npc-modal-overlay" id="npc-create-modal" data-npc-modal hidden><section class="npc-modal npc-editor-modal" role="dialog" aria-modal="true" aria-labelledby="npc-create-title"><header class="npc-editor-header"><h2 id="npc-create-title">Edit NPC</h2><div class="npc-modal-actions"><button type="submit" class="btn-save" form="management-form-profile-create">Save</button><button type="button" class="btn-cancel" disabled aria-disabled="true">Reset NPC '.lorkhan_ui_feature_badge('config.npc.saved-only',true).'</button><button type="button" class="btn-cancel" disabled aria-disabled="true">View Diary '.lorkhan_ui_feature_badge('config.npc.saved-only',true).'</button><button type="button" class="btn-cancel" disabled aria-disabled="true">View History '.lorkhan_ui_feature_badge('config.npc.saved-only',true).'</button><button type="button" class="btn-cancel" disabled aria-disabled="true">AI Generate Profile '.lorkhan_ui_feature_badge('config.npc.saved-only',true).'</button><button type="button" class="btn-cancel" data-npc-modal-close>Close</button></div></header><div class="npc-modal-tabs"><button type="button" class="active">&#9997;&#65039; Manual</button><a href="'.lorkhan_ui_h($biographiesUrl).'">&#128218; NPC Biographies</a></div><div class="npc-modal-body">';
    lorkhan_ui_npc_editor_form(['profile_id'=>'create','installation_id'=>(string)(array_key_first($installationOptions)??''),'name'=>'','content'=>[],'actor_identity'=>[]],$voiceOptions,$promptRows,$llmRows,$ttsRows,$coreProfileRows,$managementBasePath,$csrf,true,$installationOptions,[],$playthroughOptionsByInstallation,$postState);
    echo'<details class="npc-observed-picker"><summary>Observed OpenMW NPCs <span class="npc-toolbar-count">'.count($observedNpcs).'</span></summary>';lorkhan_ui_observed_npcs($observedNpcs,$managementBasePath,$csrf);echo'</details></div></section></div>';
    echo'<div class="npc-modal-overlay" id="npc-import-modal" data-npc-modal hidden><section class="npc-modal" role="dialog" aria-modal="true" aria-labelledby="npc-import-title"><header><h2 id="npc-import-title">Import NPC</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body">';lorkhan_ui_management_form(['route'=>'profile-import','id'=>'npc-profile-import','legend'=>'Import LORKHAN profile','hidden'=>$postState,'fields'=>[['installation_id','Installation','select','',$installationOptions],['profile_json','Portable LORKHAN profile JSON','jsonfile']]],$managementBasePath,$csrf);echo'</div></section></div>';
    $relationshipUrl=preg_replace('#/manage$#','/ui/relationship_logs.php',$managementBasePath)?:'/LorkhanServer/ui/relationship_logs.php';
    $jobsUrl=preg_replace('#/manage$#','/ui/jobs.php',$managementBasePath)?:'/LorkhanServer/ui/jobs.php';
    echo'<div class="npc-modal-overlay" id="npc-relationships-modal" data-npc-modal hidden><section class="npc-modal npc-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="npc-relationships-title"><header><h2 id="npc-relationships-title">&#128279; Build Relationships</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body"><p>Convert the Relationships text saved on NPC profiles into typed relationship records for one playthrough.</p><p>This uses each NPC&#39;s saved Relationship LLM and can cost provider tokens. It reads only Relationships text. It never reads Custom Info, and Custom Info on existing records is never changed.</p><p>Only this explicit form queues provider work. Opening this page or saving an NPC profile does not call a provider.</p>';
    if($playthroughOptions!==[])lorkhan_ui_management_form(['route'=>'relationship-text-convert','id'=>'npc-relationship-convert','legend'=>'Build relationships','hidden'=>$postState+['request_id'=>\LorkhanServer\Infrastructure\Uuid::v4()],
        'fields'=>[['playthrough_id','Playthrough','select','',$playthroughOptions],
            ['mode','Conversion mode','select','missing',['missing'=>'Only NPCs without relationship records','rebuild'=>'Rebuild matched relationship scores']],
            ['confirm','Type Build to confirm']]],$managementBasePath,$csrf);
    else echo'<p class="empty-state">Create a playthrough before converting relationship text.</p>';
    echo'<details><summary>How this works</summary><p>The default mode leaves every NPC that already has a relationship record alone. Rebuild mode may update the scores of explicitly matched records; omitted records and Custom Info remain unchanged.</p><p>NPCs whose Relationship LLM is Disabled or whose Relationship Lock is on are skipped. Targets must match OpenMW actors LORKHAN already knows. Unmatched names are skipped instead of invented, and relationships are never inferred between two other characters.</p><p>Each eligible NPC runs as a bounded background job. Reload Workers &amp; Jobs for current status; this page does not poll or refresh automatically.</p></details><p>This differs from Build with AI on Relationship Audit, which analyzes played conversations instead of profile text.</p><div class="connector-actions"><a class="btn-base btn-primary" href="'.lorkhan_ui_h($relationshipUrl).'" target="_blank" rel="noopener">Open Relationship Logs</a><a class="btn-base" href="'.lorkhan_ui_h($jobsUrl).'" target="_blank" rel="noopener">Open Workers &amp; Jobs</a></div>';
    echo'</div></section></div>';
    echo'<div class="npc-modal-overlay" id="npc-generate-modal" data-npc-modal hidden><section class="npc-modal npc-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="npc-generate-title"><header><h2 id="npc-generate-title">&#10024; Generate NPC Profiles</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body"><p>Generate AI profile revisions for unlocked NPCs already observed by LORKHAN. This is separate from relationship conversion and can cost provider tokens.</p>';
    lorkhan_ui_management_form(['route'=>'profile-bulk-generate','id'=>'npc-bulk-generate','legend'=>'Generate unlocked NPC profiles','hidden'=>$postState,'fields'=>[['installation_id','Installation','select','',$installationOptions],['confirm','Type Generate to confirm']]],$managementBasePath,$csrf);
    echo'</div></section></div>';
    echo'<div class="npc-modal-overlay" id="npc-switch-modal" data-npc-modal hidden><section class="npc-modal npc-switch-modal" role="dialog" aria-modal="true" aria-labelledby="npc-switch-title"><h2 id="npc-switch-title">Mass Switch NPC Profiles</h2><p>Move every NPC currently on one profile to another profile in one pass.</p>';
    echo'<form method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/profile-bulk-switch').'" data-npc-core-switch><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'">';lorkhan_ui_hidden_state($postState);
    $switchInstallation=$installationFilter!==''?$installationFilter:(string)(array_key_first($installationOptions)??'');
    if(count($installationOptions)>1){echo'<label>Installation<select name="installation_id">';foreach($installationOptions as$id=>$label)echo'<option value="'.lorkhan_ui_h($id).'"'.($id===$switchInstallation?' selected':'').'>'.lorkhan_ui_h($label).'</option>';echo'</select></label>';}
    else echo'<input type="hidden" name="installation_id" value="'.lorkhan_ui_h($switchInstallation).'">';
    echo'<div class="npc-switch-profiles">';
    foreach(['source_profile_id'=>'From profile','target_profile_id'=>'To profile']as$name=>$label){echo'<label>'.$label.'<select name="'.$name.'">';foreach($coreProfileRows as$core)echo'<option value="'.lorkhan_ui_h($core['core_profile_id']).'" data-installation="'.lorkhan_ui_h($core['installation_id']).'">'.lorkhan_ui_h($core['label']).'</option>';echo'</select></label>';}
    echo'</div><label class="npc-switch-lock"><input type="checkbox" name="include_locked" value="1"> Include locked NPCs</label><label>Type <strong>Switch</strong> to confirm:<input name="confirm" autocomplete="off" required></label><p data-npc-switch-status role="status" aria-live="polite"></p><div class="npc-switch-actions"><button type="button" class="btn-cancel" data-npc-modal-close>Cancel</button><button type="submit" class="btn-base npc-switch-submit" disabled>Switch Profiles</button></div></form></section></div>';
    echo'<div class="npc-modal-overlay" id="npc-unlock-modal" data-npc-modal hidden><section class="npc-modal npc-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="npc-unlock-title"><header><h2 id="npc-unlock-title">&#128275; Unlock All NPC Profiles</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body"><p>Creates an unlocked revision for every locked NPC profile. Player and narrator profiles are excluded.</p>';lorkhan_ui_management_form(['route'=>'profile-bulk-unlock','id'=>'npc-bulk-unlock','legend'=>'Unlock all NPC profiles','hidden'=>$postState,'fields'=>[['installation_id','Installation','select','',$installationOptions],['confirm','Type Unlock to confirm']]],$managementBasePath,$csrf);echo'</div></section></div>';
    echo'<div class="npc-modal-overlay" id="npc-delete-all-modal" data-npc-modal hidden><section class="npc-modal npc-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="npc-delete-all-title"><header><h2 id="npc-delete-all-title">&#10060; Delete All Profiles</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body"><p>Soft-deletes every unlocked NPC profile and clears its OpenMW actor bindings. Locked, player, and narrator profiles are preserved.</p>';lorkhan_ui_management_form(['route'=>'profile-bulk-delete','id'=>'npc-bulk-delete','legend'=>'Delete all unlocked NPC profiles','hidden'=>$postState,'fields'=>[['installation_id','Installation','select','',$installationOptions],['confirm','Type Delete to confirm']]],$managementBasePath,$csrf);echo'</div></section></div>';
    echo'</section>';
}

/** Render the one player profile editor whose current revision is added to every dialogue prompt. */
function lorkhan_ui_player_cards(array $rows, string $managementBasePath, string $csrf): void
{
    if($rows===[]){echo'<p class="empty-state">No player profile is configured yet. Use the form above to create one.</p>';return;}
    echo'<div class="profile-grid">';
    foreach($rows as$row){
        $content=is_array($row['content']??null)?$row['content']:[];$identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];
        $profileId=(string)($row['profile_id']??'');
        echo'<article class="profile-card"><header><div><span class="connector-kind">OpenMW Player</span><h3>'.lorkhan_ui_h($identity['display_name']??$row['name']??'Player').'</h3></div>';
        echo'<span class="status-badge">Revision '.lorkhan_ui_h($row['current_revision']??'').'</span></header>';
        echo'<p>This profile is server-owned and included automatically when LORKHAN assembles an NPC conversation prompt.</p>';
        $inputCount=(int)($row['input_count']??0);echo'<dl><dt>Recent player inputs available</dt><dd>'.lorkhan_ui_h(min(200,$inputCount)).'</dd></dl>';
        $latest=is_array($row['latest_context']??null)?$row['latest_context']:[];
        if($latest!==[]){$playerState=is_array($latest['playerState']??null)?$latest['playerState']:[];
            echo'<section class="player-runtime-context"><header><h4>Latest OpenMW context</h4><span class="status-badge">'.lorkhan_ui_h($latest['accepted_at']??'Synced').'</span></header><div class="player-context-grid">';
            foreach(['Level'=>$playerState['level']??'Unknown','Health'=>$playerState['health']??'Unknown','Magicka'=>$playerState['magicka']??'Unknown','Fatigue'=>$playerState['fatigue']??'Unknown']as$label=>$value)
                echo'<article><span>'.lorkhan_ui_h($label).'</span><strong>'.lorkhan_ui_h(is_array($value)?json_encode($value,JSON_UNESCAPED_SLASHES):$value).'</strong></article>';
            foreach(['Inventory'=>'inventory','Equipment'=>'equipment','Skills'=>'skills','Factions'=>'factions','Journal'=>'journal']as$label=>$key){$section=is_array($latest[$key]??null)?$latest[$key]:[];$items=is_array($section['items']??null)?$section['items']:[];echo'<article><span>'.lorkhan_ui_h($label).'</span><strong>'.count($items).'</strong></article>';}
            echo'</div></section>';
        }else echo'<p class="management-note">Live stats, inventory, equipment, skills, factions, and journal will appear after the next accepted in-game turn.</p>';
        if($inputCount>0)echo'<form class="connector-test" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/player-speech-style-generate').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.lorkhan_ui_h($profileId).'"><button class="btn-base" type="submit">Generate speech style from recent inputs</button></form>';
        else echo'<p class="management-note">Speech-style generation becomes available after LORKHAN records at least one real player turn.</p>';
        lorkhan_ui_management_form([
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
        lorkhan_ui_revision_actions('profile',$profileId,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf);
        echo'</article>';
    }
    echo'</div>';
}

/** Render the installation narrator as a focused opt-in profile and playback-routing editor. */
function lorkhan_ui_narrator_cards(array $rows,array $voiceOptions,array $ttsRows,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo'<p class="empty-state">No narrator profile is configured. Narrator routing remains disabled.</p>';return;}
    echo'<div class="profile-grid">';foreach($rows as$row){$content=is_array($row['content']??null)?$row['content']:[];
          $identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];$voice=$content['voice']??[];
          if(is_string($voice))$voice=['id'=>$voice];if(!is_array($voice))$voice=[];$id=(string)($row['profile_id']??'');
          $enabled=($content['enabled']??false)===true;$mode=(string)($content['inline_narration_mode']??'Disabled');
          $routing=is_array($content['routing']??null)&&!array_is_list($content['routing'])?$content['routing']:[];
          $ttsId=(string)($routing['tts_configuration_id']??'');
          $ttsOptions=lorkhan_ui_profile_connector_options($ttsRows,(string)($row['installation_id']??''),'Use the active installation TTS connector','driver');
          if($ttsId!==''&&!isset($ttsOptions[$ttsId]))$ttsOptions[$ttsId]='Unavailable TTS connector';
        echo'<article class="profile-card"><header><div><span class="connector-kind">Player-local narrator</span><h3>'.lorkhan_ui_h($identity['display_name']??$row['name']??'The Narrator').'</h3></div>';
        echo'<span class="status-badge'.($enabled?' connector-active':'').'">'.($enabled?'Enabled':'Disabled').'</span></header>';
          echo'<dl><dt>Inline routing</dt><dd>'.lorkhan_ui_h($mode).'</dd><dt>TTS connector</dt><dd>'.lorkhan_ui_h($ttsOptions[$ttsId]??$ttsOptions['']).'</dd><dt>Voice</dt><dd>'.lorkhan_ui_h($voice['id']??'Connector default').'</dd><dt>Revision</dt><dd>'.lorkhan_ui_h($row['current_revision']??'').'</dd></dl>';
        echo'<p>Leading <code>*narration*</code> can be separated from NPC speech. Narrator audio plays through the player-local OpenMW voice lane and keeps normal delivery tracking.</p>';
        lorkhan_ui_management_form(['route'=>'narrator-profile-revise','id'=>'narrator-'.$id,'legend'=>'Save narration settings',
            'hidden'=>['profile_id'=>$id,'base_content_json'=>json_encode($content===[]?(object)[]:$content,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)],
            'fields'=>[['enabled','Enable narrator routing','checkbox','1',[],false,$enabled],
                ['inline_narration_mode','Inline narration mode','select',$mode,['Disabled'=>'Disabled','Narrator'=>'Narrator voice','NPC'=>'NPC voice','Text Only'=>'Text only']],
                ['context_visibility','Include narrator context in prompts','checkbox','1',[],false,($content['context_visibility']??true)===true],
                ['welcome_events','Welcome narration','checkbox','1',[],false,($content['welcome_events']??false)===true],
                ['welcome_cooldown_minutes','Welcome cooldown (minutes)','number',(string)($content['welcome_cooldown_minutes']??10)],
                ['random_events','Random narration','checkbox','1',[],false,($content['random_events']??false)===true],
                ['random_chance_percent','Random narration chance (%)','number',(string)($content['random_chance_percent']??15)],
                ['random_cooldown_rounds','Random narration cooldown (rounds)','number',(string)($content['random_cooldown_rounds']??2)],
                ['bored_events','Narrator bored events','checkbox','1',[],false,($content['bored_events']??false)===true],
                ['bored_chance_percent','Narrator bored event chance (%)','number',(string)($content['bored_chance_percent']??25)],
                ['quest_events','Quest narration','checkbox','1',[],false,($content['quest_events']??false)===true],
                ['quest_chance_percent','Quest comment chance (%)','number',(string)($content['quest_chance_percent']??10)],
                ['quest_cooldown_minutes','Quest comment cooldown (minutes)','number',(string)($content['quest_cooldown_minutes']??3)],
                ['book_events','Book narration','checkbox','1',[],false,($content['book_events']??false)===true],
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
        echo'<div class="connector-actions">';if(!$locked)echo'<form method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/narrator-profile-generate').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.lorkhan_ui_h($id).'"><button class="btn-base btn-primary" type="submit">Generate narrator profile with AI</button></form>';echo'</div>';
        echo'<p class="management-note">'.($locked?'This narrator profile is locked, so automatic AI generation is disabled.':'Generation creates a new revision, preserves narrator enablement and voice routing, and cannot overwrite a later manual edit.').'</p>';
        lorkhan_ui_revision_actions('profile',$id,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf);
        echo'</article>';}
    echo'</div>';
}

/** Render a focused biography editor while preserving every other field in the profile revision. */
function lorkhan_ui_biography_cards(array $rows,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo'<p class="empty-state">No NPC profiles are configured yet.</p>';return;}
    echo'<div class="profile-grid">';foreach($rows as$row){$content=is_array($row['content']??null)?$row['content']:[];$identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];$id=(string)($row['profile_id']??'');
        echo'<article class="profile-card"><header><div><span class="connector-kind">NPC Biography</span><h3>'.lorkhan_ui_h($row['name']??'').'</h3></div><span class="status-badge">Revision '.lorkhan_ui_h($row['current_revision']??'').'</span></header>';
        echo'<dl><dt>Record</dt><dd><code>'.lorkhan_ui_h($identity['record_id']??'Unbound template').'</code></dd></dl>';
        lorkhan_ui_management_form(['route'=>'profile-biography-revise','id'=>'biography-'.$id,'legend'=>'Save biography revision',
            'hidden'=>['profile_id'=>$id,'base_content_json'=>json_encode($content===[]?(object)[]:$content,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)],
            'fields'=>[['biography','Biography','textarea',(string)($content['biography']??''),[],false],['change_reason','Change reason','text','biography edit']]],$managementBasePath,$csrf);
        echo'</article>';}
      echo'</div>';
  }

/** Summarize the active automatic-dialogue scheduler configured through Global Settings. */
function lorkhan_ui_schedule_cards(array $rows,array $sessionOptions,string $managementBasePath,string $csrf):void
{
    echo'<div class="feature-status feature-state-active"><h3>Automatic dialogue <span class="status-badge">Active</span></h3>';
    echo'<p>Automatic greetings, boredom remarks, and combat barks use the game-owned idle scheduler. Configure their switches and bounded cooldowns in Global Settings.</p></div>';
}

/** Render speech presets as CHIM-style connector cards with an explicit active selection. */
function lorkhan_ui_connector_cards(array $rows, array $voiceOptions, string $view, string $managementBasePath, string $csrf): void
{
    if ($rows === []) {
        echo '<p class="empty-state">No ' . lorkhan_ui_h(strtoupper($view)) . ' connectors are configured yet.</p>';
        return;
    }
    echo '<div class="connector-grid">';
    foreach ($rows as $row) {
        $content = is_array($row['content'] ?? null) ? $row['content'] : [];
        $definition = ConnectorCatalog::definition($view . '_provider', (string) ($content['driver'] ?? ''));
        $active = filter_var($row['active'] ?? false, FILTER_VALIDATE_BOOL);
        $profileUsage=(int)($row['profile_usage']??0);$inUse=$active||$profileUsage>0;
        echo '<article class="connector-card' . ($active ? ' active' : '') . '">';
        echo '<header><div><span class="connector-kind">' . lorkhan_ui_h($view) . '</span><h3>' . lorkhan_ui_h($row['name'] ?? '') . '</h3></div>';
        echo $active ? '<span class="status-badge connector-active">Active</span>' : '<span class="status-badge">Available</span>';
        echo '</header><dl>';
        echo '<dt>Connector</dt><dd>' . lorkhan_ui_h($definition['label']) . '</dd>';
        echo '<dt>Endpoint</dt><dd><code>' . lorkhan_ui_h($content['endpoint'] ?? '') . '</code></dd>';
        echo '<dt>Model</dt><dd>' . lorkhan_ui_h($content['model'] ?? '') . '</dd>';
        if ($view === 'tts') {$fallbackOptions=is_array($content['options']??null)?$content['options']:[];
            echo '<dt>Voice</dt><dd>' . lorkhan_ui_h($content['voice'] ?? '') . '</dd>';
            echo '<dt>Male / female fallback</dt><dd>'.lorkhan_ui_h((string)($fallbackOptions['fallback_male']??'Connector default').' / '.(string)($fallbackOptions['fallback_female']??'Connector default')).'</dd>';}
        echo '<dt>Credentials</dt><dd><code>' . lorkhan_ui_h($definition['credential_environment']) . '</code></dd>';
        if($view==='tts')echo'<dt>Assigned profiles</dt><dd>'.lorkhan_ui_h($profileUsage).'</dd>';
        echo '<dt>Revision</dt><dd>' . lorkhan_ui_h($row['current_revision'] ?? '') . '</dd></dl>';
        echo '<div class="connector-actions">';
        if (!$active) {
            echo '<form class="connector-activate" method="post" action="' . lorkhan_ui_h($managementBasePath . '/forms/connector-selection') . '">';
            foreach (['_csrf'=>$csrf,'installation_id'=>$row['installation_id'] ?? '',
                'configuration_id'=>$row['configuration_id'] ?? '','kind'=>$view . '_provider'] as $name=>$value) {
                echo '<input type="hidden" name="' . lorkhan_ui_h($name) . '" value="' . lorkhan_ui_h($value) . '">';
            }
            echo '<button class="btn-base btn-primary" type="submit">Use this connector</button></form>';
        }
        echo '<form class="connector-test" method="post" action="' . lorkhan_ui_h($managementBasePath . '/forms/connector-test') . '">';
        foreach (['_csrf'=>$csrf,'installation_id'=>$row['installation_id'] ?? '',
            'configuration_id'=>$row['configuration_id'] ?? '','kind'=>$view . '_provider'] as $name=>$value) {
            echo '<input type="hidden" name="' . lorkhan_ui_h($name) . '" value="' . lorkhan_ui_h($value) . '">';
        }
        echo '<button class="btn-base" type="submit">Test connector</button></form>';
        echo'<a class="btn-base" href="'.lorkhan_ui_h($managementBasePath.'/exports/connectors/'.($row['configuration_id']??'').'.json').'">Export connector</a></div>';
        echo'<details><summary>Clone connector</summary>';
        lorkhan_ui_management_form(['route'=>'connector-clone','id'=>'connector-clone-'.($row['configuration_id']??''),'legend'=>'Clone connector',
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
        lorkhan_ui_management_form([
            'route'=>'connector-revise','id'=>'connector-' . ($row['configuration_id'] ?? ''),'legend'=>'Save connector revision',
            'hidden'=>['configuration_id'=>$row['configuration_id'] ?? '','kind'=>$view . '_provider','option_fields_present'=>'1'],
            'fields'=>$connectorFields,
        ],$managementBasePath,$csrf);
        echo '</details>';
        lorkhan_ui_revision_actions('connector',(string)($row['configuration_id']??''),is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf,$view.'_provider',!$inUse,
            $active?'This active connector cannot be deleted. Select another connector first.':($profileUsage>0?'This connector cannot be deleted while assigned to a profile.':''));
        echo '</article>';
    }
    echo '</div>';
}

/** Render dialogue model slots without exposing the global endpoint credential or accepting secrets. */
function lorkhan_ui_provider_cards(array $rows, array $runtime, string $managementBasePath, string $csrf,bool $showRuntime=true): void
{
    $runtimeDriver=(string)($runtime['driver']??'mock');$runtimeModel=(string)($runtime['model']??'');
    $endpoint=(string)($runtime['endpoint']??'');
    if($showRuntime){echo '<article class="connector-card active"><header><div><span class="connector-kind">Server runtime</span><h3>Dialogue provider</h3></div><span class="status-badge connector-active">Configured</span></header><dl>';
        echo '<dt>Driver</dt><dd>'.lorkhan_ui_h($runtimeDriver).'</dd><dt>Default model</dt><dd>'.lorkhan_ui_h($runtimeModel===''?'Not set':$runtimeModel).'</dd>';
        echo '<dt>Endpoint</dt><dd><code>'.lorkhan_ui_h($endpoint===''?'Local configuration':$endpoint).'</code></dd><dt>Credential</dt><dd><code>LORKHAN_LLM_API_KEY</code> via API Keys or environment</dd></dl>';
        echo '<p>Configured slots inherit this vetted endpoint and credential while selecting only their own model. Test slots never call the network.</p><div class="connector-actions"><form class="connector-test" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/provider-runtime-test').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base" type="submit">Test server runtime</button></form></div></article>';}
    if($rows===[]){if($showRuntime)echo '<p class="empty-state">No in-game model slots are configured yet. The server default remains available.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){$content=is_array($row['content']??null)?$row['content']:[];$id=(string)($row['configuration_id']??'');$driver=(string)($content['driver']??'configured');
        $activeSessionUsage=(int)($row['active_session_usage']??0);$profileUsage=(int)($row['profile_usage']??0);$inUse=$activeSessionUsage>0||$profileUsage>0;
        echo '<article class="connector-card"><header><div><span class="connector-kind">LLM model slot</span><h3>'.lorkhan_ui_h($row['name']??'').'</h3></div><span class="status-badge">Revision '.lorkhan_ui_h($row['current_revision']??'').'</span></header><dl>';
        echo '<dt>Runtime</dt><dd>'.lorkhan_ui_h($driver==='mock'?'Deterministic test provider':'Configured live provider').'</dd><dt>Model</dt><dd>'.lorkhan_ui_h($content['model']??'').'</dd>';
        if($driver==='mock')echo '<dt>Test prefix</dt><dd>'.lorkhan_ui_h($content['mock_prefix']??'').'</dd>';
        echo '<dt>Active sessions</dt><dd>'.lorkhan_ui_h($activeSessionUsage).'</dd><dt>Assigned profiles</dt><dd>'.lorkhan_ui_h($profileUsage).'</dd>';
        echo '</dl><div class="connector-actions"><form class="connector-test" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/provider-test').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="installation_id" value="'.lorkhan_ui_h($row['installation_id']??'').'"><input type="hidden" name="configuration_id" value="'.lorkhan_ui_h($id).'"><button class="btn-base" type="submit">Test model slot</button></form><a class="btn-base" href="'.lorkhan_ui_h($managementBasePath.'/exports/providers/'.$id.'.json').'">Export model slot</a><form method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/provider-clone').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="configuration_id" value="'.lorkhan_ui_h($id).'"><label for="provider-clone-name-'.lorkhan_ui_h($id).'">Clone name</label><input id="provider-clone-name-'.lorkhan_ui_h($id).'" name="name" value="'.lorkhan_ui_h(($row['name']??'Model slot').' copy').'" required><button class="btn-base" type="submit">Clone</button></form></div><details><summary>Edit model slot</summary>';
        lorkhan_ui_management_form(['route'=>'provider-revise','id'=>'provider-'.$id,'legend'=>'Save model-slot revision','hidden'=>['configuration_id'=>$id],
            'fields'=>[['driver','Runtime','select',$driver,['configured'=>'Configured live provider','mock'=>'Deterministic test provider']],['model','Model','text',(string)($content['model']??'')],['mock_prefix','Test response prefix','text',(string)($content['mock_prefix']??''),[],false],['change_reason','Change reason','text','management edit']]],$managementBasePath,$csrf);
        echo '</details>';lorkhan_ui_revision_actions('provider',$id,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf,'provider',!$inUse,
            $activeSessionUsage>0?'This model slot cannot be deleted while selected by an active session.':($profileUsage>0?'This model slot cannot be deleted while assigned to a profile.':''));echo '</article>';
    }echo '</div>';
}

/** Render LLM, TTS, and STT records with CHIM's shared connector master/detail layout. */
function lorkhan_ui_connector_page(string $view,array $rows,array $forms,array $runtime,array $voiceOptions,string $pageTitle,string $description,string $managementBasePath,string $csrf):void
{
    $selectedId=(string)($_GET['selected']??'');$selected=null;
    foreach($rows as$row)if(hash_equals((string)($row['configuration_id']??''),$selectedId)){$selected=$row;break;}
    echo'<header class="configuration-page-header"><h1>'.lorkhan_ui_h($pageTitle).'</h1><p>'.lorkhan_ui_h($description).'</p></header>';
    echo'<div class="configuration-split-shell"><aside class="configuration-sidebar"><div class="configuration-sidebar-actions">';
    foreach($forms as$index=>$form){echo'<details class="configuration-action-panel"><summary class="btn-base '.($index===0?'btn-success':'btn-primary').'">'.($index===0?'New':'Import').'</summary>';lorkhan_ui_management_form($form,$managementBasePath,$csrf);echo'</details>';}
    echo'</div><div class="configuration-record-list">';
    if($view==='llm'){echo'<a class="configuration-record'.($selectedId==='runtime'?' active':'').'" href="?'.lorkhan_ui_h(http_build_query(['embed'=>($_GET['embed']??'')==='1'?'1':null,'selected'=>'runtime'])).'"><span><strong>Server runtime</strong><small>'.lorkhan_ui_h($runtime['model']??'Default dialogue provider').'</small></span><span class="status-badge connector-active">Live</span></a>';}
    foreach($rows as$row){$id=(string)($row['configuration_id']??'');$content=is_array($row['content']??null)?$row['content']:[];$driver=(string)($content['driver']??strtoupper($view));
        $summary=$view==='llm'?(string)($content['model']??$driver):(string)($content['endpoint']??$driver);
        echo'<a class="configuration-record'.($selected!==null&&hash_equals($selectedId,$id)?' active':'').'" href="?'.lorkhan_ui_h(http_build_query(['embed'=>($_GET['embed']??'')==='1'?'1':null,'selected'=>$id])).'"><span><strong>'.lorkhan_ui_h($row['name']??strtoupper($view).' connector').'</strong><small>'.lorkhan_ui_h($summary).'</small></span><span class="status-badge">'.lorkhan_ui_h($driver).'</span></a>';}
    echo'</div></aside><section class="configuration-detail">';
    if($view==='llm'&&$selectedId==='runtime')lorkhan_ui_provider_cards([],$runtime,$managementBasePath,$csrf,true);
    elseif($selected!==null&&$view==='llm')lorkhan_ui_provider_cards([$selected],$runtime,$managementBasePath,$csrf,false);
    elseif($selected!==null)lorkhan_ui_connector_cards([$selected],$voiceOptions,$view,$managementBasePath,$csrf);
    else echo'<div class="configuration-empty"><h2>No connector selected</h2><p>Select a connector from the list on the left to view and edit its settings.</p></div>';
    echo'</section></div>';
}

/** Render one strict revisioned settings form without exposing server endpoints or credentials. */
function lorkhan_ui_global_settings_form(?array $row,array $installationOptions,string $managementBasePath,string $csrf):void
{
    $content=is_array($row['content']??null)?$row['content']:[];$behavior=is_array($content['behavior']??null)?$content['behavior']:[];
    $memory=is_array($content['memory']??null)?$content['memory']:[];$narrator=is_array($content['narrator']??null)?$content['narrator']:[];
    $presentation=is_array($content['presentation']??null)?$content['presentation']:[];$safety=is_array($content['safety']??null)?$content['safety']:[];
    $installation=(string)($row['installation_id']??'');$id=(string)($row['configuration_id']??($installation!==''?$installation:'new'));
    lorkhan_ui_management_form(['route'=>'global-settings-save','id'=>'global-settings-'.$id,'legend'=>$row===null?'Create installation settings':'Save installation settings revision',
        'fields'=>[
            ['installation_id','Installation','select',$installation,$installationOptions],
            ['auto_greeting','Automatic greeting','checkbox','1',[],false,($behavior['auto_greeting']??false)===true],
            ['rechat','Rechat','checkbox','1',[],false,($behavior['rechat']??false)===true],
            ['rechat_delay_seconds','Rechat delay (seconds)','number',(string)($behavior['rechat_delay_seconds']??45)],
            ['rechat_max_depth','Maximum rechat replies','number',(string)($behavior['rechat_max_depth']??10)],
            ['boredom','Bored events','checkbox','1',[],false,($behavior['boredom']??false)===true],
            ['boredom_delay_seconds','Boredom delay (seconds)','number',(string)($behavior['boredom_delay_seconds']??180)],
            ['combat_barks','Combat barks','checkbox','1',[],false,($behavior['combat_barks']??false)===true],
            ['combat_bark_period_seconds','Combat bark period (seconds)','number',(string)($behavior['combat_bark_period_seconds']??20)],
            ['recent_turn_limit','Recent turn context limit','number',(string)($memory['recent_turn_limit']??20)],
            ['knowledge_limit','Oghma result limit','number',(string)($memory['knowledge_limit']??5)],
            ['oghma_knowledge_tags','Oghma knowledge tags','text',(string)($memory['oghma_knowledge_tags']??'')],
            ['narrator_enabled','Enable narrator','checkbox','1',[],false,($narrator['enabled']??false)===true],
            ['narrator_name','Narrator roleplay name','text',(string)($narrator['name']??'The Narrator')],
            ['narrator_context_visibility','Include narrator context','checkbox','1',[],false,($narrator['context_visibility']??true)===true],
            ['narrator_inline_mode','Inline narration mode','select',(string)($narrator['inline_mode']??'Disabled'),['Disabled'=>'Disabled','Narrator'=>'Narrator voice','NPC'=>'NPC voice','Text Only'=>'Text only']],
            ['narrator_welcome_events','Welcome narration','checkbox','1',[],false,($narrator['welcome_events']??false)===true],
            ['narrator_random_events','Random narration','checkbox','1',[],false,($narrator['random_events']??false)===true],
            ['narrator_quest_events','Quest narration','checkbox','1',[],false,($narrator['quest_events']??false)===true],
            ['narrator_book_events','Book narration','checkbox','1',[],false,($narrator['book_events']??false)===true],
            ['show_status_hud','Show status HUD','checkbox','1',[],false,($presentation['show_status_hud']??true)===true],
            ['transcript_rows','Transcript rows','number',(string)($presentation['transcript_rows']??8)],
            ['tts_volume_boost','LORKHAN TTS volume boost','number',(string)($presentation['tts_volume_boost']??3)],
            ['actions_enabled','Allow typed AI actions','checkbox','1',[],false,($safety['actions_enabled']??true)===true],
            ['allow_hostile','Allow hostile NPC activation','checkbox','1',[],false,($safety['allow_hostile']??false)===true],
            ['allow_creatures','Allow creature activation','checkbox','1',[],false,($safety['allow_creatures']??false)===true],
            ['change_reason','Change reason','text','management global settings'],
        ]],$managementBasePath,$csrf);
}

/** Render revisioned settings plus separately confirmed runtime schedules in the CHIM hierarchy. */
function lorkhan_ui_global_settings_page(array $rows,array $installationRows,array $installationOptions,array $scheduleRows,array $forms,array $sessionOptions,string $managementBasePath,string $csrf):void
{
    echo'<div class="global-settings-shell"><header class="global-settings-title"><h1>Global Settings</h1><div class="global-settings-actions">';
    foreach($forms as$form){echo'<details class="configuration-action-panel"><summary class="btn-base btn-success">New Schedule</summary>';lorkhan_ui_management_form($form,$managementBasePath,$csrf);echo'</details>';}
    echo'</div></header><nav class="global-settings-tabs" aria-label="Global setting groups"><span class="active">&#9881; Effective Settings</span><span>&#128172; Conversation Timing</span><span>&#127760; Installations</span></nav>';
    echo'<section class="global-settings-panel"><h2>Server-owned effective settings</h2><p>These revisioned values sync at session start. Local OpenMW safety settings can further restrict actions, hostile actors, and creatures; the server cannot loosen them.</p>';
    $configured=[];foreach($rows as$row){$configured[(string)($row['installation_id']??'')]=true;echo'<details class="global-settings-document" open><summary>'.lorkhan_ui_h($row['display_name']??'Installation').' <span class="status-badge">Revision '.lorkhan_ui_h($row['current_revision']??1).'</span></summary>';lorkhan_ui_global_settings_form($row,$installationOptions,$managementBasePath,$csrf);echo'</details>';}
    foreach($installationOptions as$id=>$label)if(!isset($configured[$id])){echo'<details class="global-settings-document" open><summary>Create settings for '.lorkhan_ui_h($label).'</summary>';lorkhan_ui_global_settings_form(['installation_id'=>$id],$installationOptions,$managementBasePath,$csrf);echo'</details>';}
    if($installationOptions===[])echo'<p class="empty-state">No OpenMW installation has paired with LorkhanServer yet.</p>';echo'</section>';
    echo'<section class="global-settings-panel"><h2>Conversation Continuation</h2><p>Rechat is playback-gated profile behavior, not an idle schedule.</p>';
    lorkhan_ui_schedule_cards($scheduleRows,$sessionOptions,$managementBasePath,$csrf);echo'</section>';
    echo'<section class="global-settings-panel"><h2>Registered Installations</h2>';lorkhan_ui_table($installationRows);echo'</section></div>';
}

/** Render versioned prompts with CHIM's guidance, import/create tools, and searchable records. */
function lorkhan_ui_prompts_page(array $rows,array $forms,string $description,string $managementBasePath,string $csrf):void
{
    $query=mb_strtolower(mb_substr(trim((string)($_GET['q']??'')),0,100));
    $filtered=$query===''?$rows:array_values(array_filter($rows,static function(array$row)use($query):bool{$content=is_array($row['content']??null)?$row['content']:[];return str_contains(mb_strtolower((string)($row['name']??'').' '.json_encode($content)), $query);}));
    echo'<header class="configuration-page-header"><h1>Prompts Manager</h1><p>'.lorkhan_ui_h($description).'</p></header>';
    echo'<section class="prompts-guidance"><p><strong>Note:</strong> Prompt changes affect every conversation assigned to that configuration. Revisions and rollback remain available.</p><p><strong>LORKHAN prompt:</strong> A versioned, installation-scoped instruction document. Profile routing determines which prompt is used.</p></section>';
    echo'<section class="widget widget-wide prompts-builtin"><div class="widget-header"><h3>Built-in Default</h3><span class="status-badge connector-active">Always available</span></div><div class="widget-content"><article class="connector-card"><header><div><span class="connector-kind">Read-only fallback</span><h3>LORKHAN Core Conversation</h3></div></header><p>Used only when the active character has no explicit prompt and no applicable saved installation prompt exists.</p><pre class="configuration-preview">'.lorkhan_ui_h(json_encode(['instruction'=>'Respond in character using only scoped context.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'</pre></article></div></section>';
    echo'<section class="prompts-tools">';foreach($forms as$index=>$form){echo'<details class="configuration-action-panel"><summary class="btn-base '.($index===0?'btn-success':'btn-primary').'">'.($index===0?'Create Prompt':'Import Prompt').'</summary><p>'.($index===0?'Create a new versioned dialogue prompt.':'Import a portable LORKHAN prompt JSON document.').'</p>';lorkhan_ui_management_form($form,$managementBasePath,$csrf);echo'</details>';};echo'</section>';
    echo'<section class="prompts-search"><h2>Search Prompts</h2><form method="get"><label for="prompt-search">Filter by prompt name or document text</label><div><input id="prompt-search" type="search" name="q" value="'.lorkhan_ui_h($_GET['q']??'').'" placeholder="Search prompts...">'.((($_GET['embed']??'')==='1')?'<input type="hidden" name="embed" value="1">':'').'<button class="btn-base" type="submit">Search</button></div></form></section>';
    echo'<section class="widget widget-wide prompts-records"><div class="widget-header"><h3>Prompt Records</h3><span class="status-badge">'.count($filtered).' shown</span></div><div class="widget-content">';lorkhan_ui_configuration_cards($filtered,'prompt',$managementBasePath,$csrf);echo'</div></section>';
}

/** Render immutable OpenMW actions and reducible policies in CHIM's Action Editor format. */
function lorkhan_ui_actions_page(array $rows,array $policyRows,array $installationOptions,array $profileOptions,string $description,string $managementBasePath,string $csrf):void
{
    $enabledRows=array_values(array_filter($rows,static fn(array$row):bool=>in_array($row['enabled']??false,[true,1,'1','t','true'],true)));
    $query=mb_strtolower(mb_substr(trim((string)($_GET['q']??'')),0,100));
    $filtered=$query===''?$rows:array_values(array_filter($rows,static fn(array$row):bool=>str_contains(mb_strtolower(implode(' ',[(string)($row['action_name']??''),(string)($row['client_capability']??''),(string)($row['description']??'')])), $query)));
    echo'<header class="configuration-page-header"><h1>Action Editor</h1><p>'.lorkhan_ui_h($description).'</p></header>';
    echo'<div class="action-overview-grid"><section><h2>Action Summary</h2><div class="action-stat-grid"><article><span>Total Actions</span><strong>'.count($rows).'</strong></article><article><span>Enabled</span><strong class="success">'.count($enabledRows).'</strong></article><article><span>Policies</span><strong>'.count($policyRows).'</strong></article></div></section><section><h2>How It Works</h2><p>LORKHAN exposes only the immutable action catalog negotiated with OpenMW. Policies can reduce allowed actions and maximum tier; they cannot add new capabilities or bypass confirmation.</p></section></div>';
    echo'<form class="action-filter-bar" method="get"><label for="action-filter-q">Search actions</label><input id="action-filter-q" type="search" name="q" value="'.lorkhan_ui_h($_GET['q']??'').'" placeholder="Search actions..."><span>'.count($filtered).' of '.count($rows).' shown</span>'.((($_GET['embed']??'')==='1')?'<input type="hidden" name="embed" value="1">':'').'<button class="btn-base" type="submit">Search</button><a class="btn-base" href="?'.(($_GET['embed']??'')==='1'?'embed=1':'').'">Reset Filters</a></form>';
    echo'<section class="widget widget-wide action-catalog-table"><div class="widget-content">';lorkhan_ui_table($filtered);echo'</div></section>';
    echo'<details class="management-create-panel"'.($policyRows===[]?' open':'').'><summary>Create action policy with controls</summary>';lorkhan_ui_action_policy_form(null,$rows,$installationOptions,$profileOptions,$managementBasePath,$csrf);echo'</details>';
    echo'<section class="widget widget-wide"><div class="widget-header"><h3>Action Policies</h3></div><div class="widget-content">';lorkhan_ui_action_policy_cards($policyRows,$rows,$installationOptions,$profileOptions,$managementBasePath,$csrf);echo'</div></section>';
}

/** Render JSON-backed prompt and action-policy revisions with bounded edit, rollback, and delete controls. */
function lorkhan_ui_configuration_cards(array $rows,string $kind,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo '<p class="empty-state">No saved '.lorkhan_ui_h(str_replace('_',' ',$kind)).' records yet.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){$id=(string)($row['configuration_id']??'');$content=is_array($row['content']??null)?$row['content']:[];
        echo '<article class="connector-card"><header><div><span class="connector-kind">'.lorkhan_ui_h(str_replace('_',' ',$kind)).'</span><h3>'.lorkhan_ui_h($row['name']??'').'</h3></div><span class="status-badge">Revision '.lorkhan_ui_h($row['current_revision']??'').'</span></header>';
        if($kind==='prompt')echo'<dl><dt>Assigned profiles</dt><dd>'.lorkhan_ui_h($row['profile_usage']??0).'</dd></dl>';
        echo '<pre class="configuration-preview">'.lorkhan_ui_h(json_encode($content,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'</pre>';
        if($kind==='prompt')echo '<div class="connector-actions"><a class="btn-base" href="'.lorkhan_ui_h($managementBasePath.'/exports/prompts/'.$id.'.json').'">Export prompt</a><form method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/prompt-clone').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="configuration_id" value="'.lorkhan_ui_h($id).'"><label for="prompt-clone-name-'.lorkhan_ui_h($id).'">Clone name</label><input id="prompt-clone-name-'.lorkhan_ui_h($id).'" name="name" value="'.lorkhan_ui_h(($row['name']??'Prompt').' copy').'" required><button class="btn-base" type="submit">Clone</button></form></div>';
        echo '<details><summary>Edit revision</summary>';
        lorkhan_ui_management_form(['route'=>'configuration-revise','id'=>'configuration-'.$id,'legend'=>'Save revision','hidden'=>['configuration_id'=>$id,'kind'=>$kind],
            'fields'=>[['content_json','Configuration document (JSON)','textarea',json_encode($content===[]?(object)[]:$content,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)],['change_reason','Change reason','text','management edit']]],$managementBasePath,$csrf);
        echo '</details>';$inUse=$kind==='prompt'&&(int)($row['profile_usage']??0)>0;lorkhan_ui_revision_actions('configuration',$id,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf,$kind,!$inUse,$inUse?'This prompt is assigned to a profile and cannot be deleted.':'');echo '</article>';
    }echo '</div>';
}

/** Render a labelled policy editor over immutable server-owned action definitions. */
function lorkhan_ui_action_policy_form(?array $row,array $actions,array $installationOptions,array $profileOptions,string $managementBasePath,string $csrf):void
{
    $create=$row===null;$content=$create?[]:(is_array($row['content']??null)?$row['content']:[]);$token=$create?'create':(string)($row['configuration_id']??'policy');
    $allow=is_array($content['allowed_actions']??null)&&array_is_list($content['allowed_actions'])?$content['allowed_actions']:null;
    $deny=is_array($content['denied_actions']??null)&&array_is_list($content['denied_actions'])?$content['denied_actions']:[];
    // Resolve legacy lists and partial action maps with the same deny-first semantics as runtime.
    foreach(($content['actions']??[])as$name=>$allowed){if($allowed){$allow??=[];$allow[]=$name;}else$deny[]=$name;}
    echo '<form class="management-form action-policy-form" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/'.($create?'action-policy-controls-create':'action-policy-controls-revise')).'"><fieldset><legend>'.($create?'Create action policy with controls':'Edit action permissions').'</legend>';
    if($create){echo '<label for="action-policy-installation-'.$token.'">Installation</label><select id="action-policy-installation-'.$token.'" name="installation_id" required>';foreach($installationOptions as$id=>$label)echo '<option value="'.lorkhan_ui_h($id).'">'.lorkhan_ui_h($label).'</option>';echo '</select><label for="action-policy-profile-'.$token.'">Profile scope</label><select id="action-policy-profile-'.$token.'" name="profile_id"><option value="">Installation-wide</option>';foreach($profileOptions as$id=>$label)echo '<option value="'.lorkhan_ui_h($id).'">'.lorkhan_ui_h($label).'</option>';echo '</select><small>Profile policies take precedence over installation-wide policies.</small><label for="action-policy-name-'.$token.'">Policy name</label><input id="action-policy-name-'.$token.'" name="name" required>';}
    else echo '<input type="hidden" name="configuration_id" value="'.lorkhan_ui_h($row['configuration_id']??'').'">';
    echo '<label class="action-policy-master" for="action-policy-enabled-'.$token.'"><input id="action-policy-enabled-'.$token.'" type="checkbox" name="enabled" value="1"'.(($content['enabled']??true)?' checked':'').'> Enable actions for this policy</label>';
    echo '<label for="action-policy-tier-'.$token.'">Maximum action tier</label><select id="action-policy-tier-'.$token.'" name="max_tier">';$maxTier=(int)($content['max_tier']??3);foreach([0=>'Tier 0 - inspect only',1=>'Tier 1 - movement and social',2=>'Tier 2 - confirmed inventory or combat',3=>'Tier 3 - reserved high risk']as$value=>$label)echo '<option value="'.$value.'"'.($maxTier===$value?' selected':'').'>'.lorkhan_ui_h($label).'</option>';echo '</select>';
    $catalog=[];
    foreach($actions as$action){$enabledValue=$action['enabled']??false;if(!in_array($enabledValue,[true,1,'1','t','true'],true))continue;$name=(string)($action['action_name']??'');if($name==='')continue;
        $checked=($allow===null||in_array($name,$allow,true))&&!in_array($name,$deny,true);
        $catalog[]=['name'=>$name,'checked'=>$checked,'tier'=>(string)($action['tier']??''),'description'=>(string)($action['description']??'')];}
    $selectedCount=count(array_filter($catalog,static fn(array$entry):bool=>$entry['checked']));
    echo '<fieldset class="action-policy-selection" data-action-policy-controls><legend>Allowed actions</legend><div class="action-policy-bulk" data-action-bulk data-action-bulk-class="btn-base"><output class="action-policy-count" data-action-selected-count>'.$selectedCount.' of '.count($catalog).' selected</output></div><div class="action-policy-grid">';
    foreach($catalog as$entry){$id='action-policy-'.$token.'-'.substr(hash('sha256',$entry['name']),0,12);
        echo '<label class="action-policy-toggle" for="'.lorkhan_ui_h($id).'"><input id="'.lorkhan_ui_h($id).'" type="checkbox" name="allowed_actions[]" value="'.lorkhan_ui_h($entry['name']).'"'.($entry['checked']?' checked':'').'><span><strong>'.lorkhan_ui_h($entry['name']).'</strong><small>Tier '.lorkhan_ui_h($entry['tier']).' · '.lorkhan_ui_h($entry['description']).'</small></span></label>';}
    echo '</div></fieldset>';
    if(!$create)echo '<label for="action-policy-reason-'.$token.'">Change reason</label><input id="action-policy-reason-'.$token.'" name="change_reason" value="management action editor" required>';
    echo '<input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base btn-primary" type="submit">'.($create?'Create policy':'Save action permissions').'</button></fieldset></form>';
}

/** Render action-policy revisions with labelled controls plus an advanced JSON editor. */
function lorkhan_ui_action_policy_cards(array $rows,array $actions,array $installationOptions,array $profileOptions,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo '<p class="empty-state">No action policies are configured. The immutable server catalog and negotiated client capabilities remain the safety boundary.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){$id=(string)($row['configuration_id']??'');$content=is_array($row['content']??null)?$row['content']:[];
        echo '<article class="connector-card"><header><div><span class="connector-kind">Action policy</span><h3>'.lorkhan_ui_h($row['name']??'').'</h3></div><span class="status-badge">Revision '.lorkhan_ui_h($row['current_revision']??'').'</span></header><dl><dt>Scope</dt><dd>'.lorkhan_ui_h($row['profile_name']??'Installation-wide').'</dd><dt>Enabled</dt><dd>'.(($content['enabled']??true)?'Yes':'No').'</dd><dt>Maximum tier</dt><dd>'.lorkhan_ui_h($content['max_tier']??3).'</dd></dl><details open><summary>Action permissions</summary>';
        lorkhan_ui_action_policy_form($row,$actions,$installationOptions,$profileOptions,$managementBasePath,$csrf);echo '</details><details><summary>Advanced policy JSON</summary>';
        lorkhan_ui_management_form(['route'=>'configuration-revise','id'=>'action-policy-json-'.$id,'legend'=>'Save advanced policy revision','hidden'=>['configuration_id'=>$id,'kind'=>'action_policy'],
            'fields'=>[['content_json','Policy document (JSON)','textarea',json_encode($content===[]?(object)[]:$content,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)],['change_reason','Change reason','text','advanced action policy edit']]],$managementBasePath,$csrf);
        echo '</details>';lorkhan_ui_revision_actions('configuration',$id,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf,'action_policy');echo '</article>';}
    echo '</div>';
}

/** Render scoped Oghma documents with an explicit, CSRF-protected soft-delete action. */
function lorkhan_ui_knowledge_cards(array $rows,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo '<p class="empty-state">No Oghma Infinium documents have been added yet.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){echo '<article class="connector-card"><header><div><span class="connector-kind">World knowledge</span><h3>'.lorkhan_ui_h($row['title']??'').'</h3></div></header>';
        echo '<p>'.nl2br(lorkhan_ui_h($row['content']??'')).'</p><dl><dt>Profile scope</dt><dd><code>'.lorkhan_ui_h($row['profile_id']??'All profiles').'</code></dd><dt>Playthrough scope</dt><dd><code>'.lorkhan_ui_h($row['playthrough_id']??'All playthroughs').'</code></dd><dt>Created</dt><dd>'.lorkhan_ui_h($row['created_at']??'').'</dd></dl>';
        echo '<form class="danger-form" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/knowledge-delete').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="document_id" value="'.lorkhan_ui_h($row['document_id']??'').'"><button class="btn-base btn-danger" type="submit">Delete document</button></form></article>';}
    echo '</div>';
}

/** Render narrator, diary, and summary records that feed scoped prompt assembly. */
function lorkhan_ui_narrative_cards(array $rows,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo '<p class="empty-state">No narrator or diary records have been added yet.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){$provenance=is_array($row['provenance']??null)?$row['provenance']:[];$id=(string)($row['narrative_id']??'');
        echo '<article class="connector-card"><header><div><span class="connector-kind">'.lorkhan_ui_h($row['kind']??'narrative').'</span><h3>'.lorkhan_ui_h($row['title']??'').'</h3></div></header><p>'.nl2br(lorkhan_ui_h($row['content']??'')).'</p>';
        echo'<details><summary>Edit narrative</summary>';lorkhan_ui_management_form(['route'=>'narrative-revise','id'=>'narrative-'.$id,'legend'=>'Save narrative changes','hidden'=>['narrative_id'=>$id],
            'fields'=>[['kind','Narrative kind','select',(string)($row['kind']??'narrator'),['narrator','diary','summary']],
                ['title','Title','text',(string)($row['title']??'')],['content','Narrative','textarea',(string)($row['content']??'')],
                ['provenance','Provenance source','text',(string)($provenance['source']??'management')]]],$managementBasePath,$csrf);echo'</details>';
        echo '<form class="danger-form" method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/narrative-delete').'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><input type="hidden" name="narrative_id" value="'.lorkhan_ui_h($row['narrative_id']??'').'"><button class="btn-base btn-danger" type="submit">Delete narrative</button></form></article>';}
    echo '</div>';
}

/** Render playthrough records with an authenticated backup download for their scoped roleplay state. */
function lorkhan_ui_playthrough_cards(array $rows,string $managementBasePath):void
{
    if($rows===[]){echo'<p class="empty-state">No playthroughs are configured yet.</p>';return;}
    echo'<div class="connector-grid">';foreach($rows as$row){
        echo'<article class="connector-card"><header><div><span class="connector-kind">OpenMW playthrough</span><h3>'.lorkhan_ui_h($row['playthrough']??'').'</h3></div><span class="status-badge">Revision '.lorkhan_ui_h($row['current_revision']??'').'</span></header><dl>';
        echo'<dt>Profile</dt><dd>'.lorkhan_ui_h($row['profile']??'').'</dd><dt>Fingerprint</dt><dd><code>'.lorkhan_ui_h($row['content_fingerprint']??'').'</code></dd><dt>Created</dt><dd>'.lorkhan_ui_h($row['created_at']??'').'</dd><dt>Last session</dt><dd>'.lorkhan_ui_h($row['last_session_at']??'Never').'</dd></dl>';
        echo'<div class="widget-stats">';foreach(['Sessions'=>'sessions','Turns'=>'turns','Responses'=>'responses','Memories'=>'memories','Relationships'=>'relationships','Narratives'=>'narratives','Knowledge'=>'knowledge_records']as$label=>$key)
            echo'<div class="stat-card"><span class="stat-value">'.lorkhan_ui_h($row[$key]??0).'</span><span class="stat-label">'.lorkhan_ui_h($label).'</span></div>';echo'</div>';
        echo'<div class="connector-actions"><a class="btn-base btn-primary" href="'.lorkhan_ui_h($managementBasePath.'/exports/playthroughs/'.($row['playthrough_id']??'').'.json').'">Export backup</a></div></article>';
    }echo'</div>';
}

/** Render stored backup metadata without exposing server paths or configuration contents. */
function lorkhan_ui_configuration_backup_cards(array $rows,array $installationOptions,string $managementBasePath):void
{
    $rows=array_values(array_filter($rows,static fn(array$row):bool=>is_array($row['scope']??null)&&($row['scope']['kind']??null)==='configuration'));
    if($rows===[]){echo'<p class="empty-state">No installation configuration backups are available yet.</p>';return;}
    echo'<div class="connector-grid">';foreach($rows as$row){$scope=$row['scope'];$id=(string)($row['backup_id']??'');
        echo'<article class="connector-card"><header><div><span class="connector-kind">Installation configuration</span><h3>'.lorkhan_ui_h($installationOptions[$scope['installation_id']??'']??($scope['installation_id']??'Unknown installation')).'</h3></div><span class="status-badge">'.lorkhan_ui_h($row['state']??'created').'</span></header><dl>';
        echo'<dt>Created</dt><dd>'.lorkhan_ui_h($row['created_at']??'').'</dd><dt>Bytes</dt><dd>'.lorkhan_ui_h($row['byte_count']??'').'</dd><dt>Restored</dt><dd>'.lorkhan_ui_h($row['restored_at']??'Never').'</dd></dl>';
        echo'<div class="connector-actions"><a class="btn-base btn-primary" href="'.lorkhan_ui_h($managementBasePath.'/exports/backups/'.$id.'.json').'">Download backup</a></div></article>';}
    echo'</div>';
}

/** Render NPC identities observed in real OpenMW turns and create correctly bound profile templates. */
function lorkhan_ui_observed_npcs(array $rows,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo '<p class="empty-state">No NPCs have been observed in an accepted OpenMW turn yet.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){$refnum=is_array($row['refnum']??null)?$row['refnum']:[];$profile=(string)($row['profile_id']??'');
        echo '<article class="connector-card"><header><div><span class="connector-kind">Observed in OpenMW</span><h3>'.lorkhan_ui_h($row['display_name']??$row['record_id']??'NPC').'</h3></div>'.($profile!==''?'<span class="status-badge connector-active">Profile exists</span>':'<span class="status-badge">New</span>').'</header><dl><dt>Record</dt><dd><code>'.lorkhan_ui_h($row['record_id']??'').'</code></dd><dt>Content file</dt><dd>'.lorkhan_ui_h($row['content_file']??'').'</dd><dt>Last seen</dt><dd>'.lorkhan_ui_h($row['last_seen_at']??'').'</dd></dl>';
        if($profile===''){echo '<form method="post" action="'.lorkhan_ui_h($managementBasePath.'/forms/profile-create').'">';foreach(['_csrf'=>$csrf,'installation_id'=>$row['installation_id']??'','name'=>$row['display_name']??$row['record_id']??'NPC','record_id'=>$row['record_id']??'','content_file'=>$row['content_file']??'','refnum_index'=>$refnum['index']??'','refnum_content_file'=>$refnum['content_file']??'']as$name=>$value)echo '<input type="hidden" name="'.lorkhan_ui_h($name).'" value="'.lorkhan_ui_h($value).'">';echo '<button class="btn-base btn-primary" type="submit">Create editable profile</button></form>';}
        echo '</article>';}
    echo '</div>';
}

$BODY_CLASS='configuration-resource view-'.preg_replace('/[^a-z0-9_-]+/','-',strtolower($view)).($embedded?' embedded-page':'');
$additionalStylesheets=['lorkhan-pages.css?v='.(string)filemtime(dirname(__DIR__).'/css/lorkhan-pages.css')];
if($view==='characters')$additionalStylesheets[]='herika-npcs.css?v='.(string)filemtime(dirname(__DIR__).'/css/herika-npcs.css');
$includeManagementStyles=false;
include $uiRootDir . '/tmpl/head.html';
if (!$embedded) include $uiRootDir . '/tmpl/navbar.php';
$relationshipConversionNotice=$view==='characters'?lorkhan_ui_relationship_conversion_notice($_GET):null;
?>
<main class="management-page">
    <?php if ($relationshipConversionNotice !== null): ?><p class="<?php echo $relationshipConversionNotice[0] === 'status' ? 'page-status' : 'page-error'; ?>" role="<?php echo $relationshipConversionNotice[0]; ?>"><?php echo lorkhan_ui_h($relationshipConversionNotice[1]); ?></p><?php endif; ?>
    <?php if (isset($_GET['status']) && $_GET['status'] === 'saved'): ?><p class="page-status" role="status"><?php echo $view === 'characters' ? 'NPC profile change saved.' : 'Changes saved.'; ?></p><?php endif; ?>
    <?php if (isset($_GET['status']) && $_GET['status'] === 'tested'): ?><p class="page-status" role="status">Connector test passed<?php echo isset($_GET['detail']) ? ': ' . lorkhan_ui_h($_GET['detail']) : '.'; ?></p><?php endif; ?>
    <?php if (isset($_GET['error'])): ?><p class="page-error" role="alert"><?php echo lorkhan_ui_h($_GET['error']); ?></p><?php endif; ?>
    <?php if ($view === 'characters'): ?>
    <?php if (($_GET['status']??'')==='profiles-switched'): ?><p class="page-status" role="status">Switched <?php echo max(0,(int)($_GET['updated']??0)); ?> NPCs; skipped <?php echo max(0,(int)($_GET['skipped']??0)); ?> locked NPCs.</p><?php endif; ?>
    <?php lorkhan_ui_character_manager($rows,$observedNpcs,$profilePreferenceRows,$installationOptions,$playthroughOptions,$playthroughOptionsByInstallation,$voiceOptions,$promptRoutingRows,$llmRoutingRows,$ttsRoutingRows,$coreProfileRows,$productRepository,$forms,$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'profiles'): ?>
    <?php lorkhan_ui_profiles_page($rows,$forms,$voiceOptions,$promptRoutingRows,$llmRoutingRows,$ttsRoutingRows,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'player'): ?>
    <?php lorkhan_ui_player_page($rows,$forms,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'narrator'): ?>
    <?php lorkhan_ui_narrator_page($rows,$forms,$voiceOptions,$ttsRoutingRows,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php elseif (in_array($view, ['llm','tts','stt'], true)): ?>
    <?php lorkhan_ui_connector_page($view,$rows,$forms,is_array($config['provider']??null)?$config['provider']:[],$voiceOptions,$pageTitle,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'global_settings'): ?>
    <?php lorkhan_ui_global_settings_page($rows,$installationRows,$installationOptions,$scheduleRows,$forms,$sessionOptions,$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'prompts'): ?>
    <?php lorkhan_ui_prompts_page($rows,$forms,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'actions'): ?>
    <?php lorkhan_ui_actions_page($rows,$policyRows,$installationOptions,$actionProfileOptions,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php else: ?>
    <header class="configuration-page-header"><h1><?php echo lorkhan_ui_h($pageTitle); ?></h1><p><?php echo lorkhan_ui_h($descriptions[$view] ?? 'LorkhanServer management page.'); ?></p></header>
    <?php if ($view === 'worldknowledge'): ?>
    <section class="knowledge-search-logic"><h2>&#128269; Article Search Logic</h2><div class="knowledge-steps">
        <article><span>1</span><div><h3>Scoped Retrieval</h3><p>Only knowledge for the current installation, profile, and playthrough can be selected.</p></div></article>
        <article><span>2</span><div><h3>Keyword Matching</h3><p>Dialogue terms are matched against bounded Morrowind world-knowledge records.</p></div></article>
        <article><span>3</span><div><h3>Deterministic Ranking</h3><p>Stable scoring keeps the most relevant records predictable and auditable.</p></div></article>
        <article><span>4</span><div><h3>Safe Fallback</h3><p>If nothing relevant is found, no unscoped knowledge is injected into the prompt.</p></div></article>
    </div></section>
    <?php endif; ?>
    <section class="widget widget-wide">
        <div class="widget-header"><h3>Current Records</h3></div>
        <div class="widget-content"><?php
            if ($view === 'tts' || $view === 'stt') lorkhan_ui_connector_cards($rows, $voiceOptions, $view, $managementBasePath, $csrf);
            elseif ($view === 'llm') lorkhan_ui_provider_cards($rows, is_array($config['provider']??null)?$config['provider']:[], $managementBasePath, $csrf);
            elseif ($view === 'prompts') lorkhan_ui_configuration_cards($rows, 'prompt', $managementBasePath, $csrf);
            elseif ($view === 'worldknowledge') lorkhan_ui_knowledge_cards($rows, $managementBasePath, $csrf);
            elseif ($view === 'characters' || $view === 'profiles') lorkhan_ui_profile_cards($rows,$voiceOptions,$promptRoutingRows,$llmRoutingRows,$ttsRoutingRows,$managementBasePath,$csrf);
            elseif ($view === 'player') lorkhan_ui_player_cards($rows, $managementBasePath, $csrf);
            elseif ($view === 'narrator') lorkhan_ui_narrator_cards($rows,$voiceOptions,$ttsRoutingRows,$managementBasePath,$csrf);
            elseif ($view === 'npc_biographies') lorkhan_ui_biography_cards($rows, $managementBasePath, $csrf);
            elseif ($view === 'playthroughs') lorkhan_ui_playthrough_cards($rows, $managementBasePath);
            else lorkhan_ui_table($rows);
        ?></div>
    </section>
    <?php if ($view === 'global_settings'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Conversation Continuation</h3></div><div class="widget-content"><?php lorkhan_ui_schedule_cards($scheduleRows,$sessionOptions,$managementBasePath,$csrf); ?></div></section>
    <?php endif; ?>
    <?php if ($view === 'database_manager'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Installation Configuration Backups</h3></div><div class="widget-content"><p>Includes profiles, prompts, model slots, TTS/STT presets, action policies, connector selections, and profile safety preferences. API keys, portrait files, voice files, memories, relationships, narratives, and runtime database records are excluded.</p><?php lorkhan_ui_configuration_backup_cards($backupRows,$installationOptions,$managementBasePath); ?></div></section>
    <?php endif; ?>
    <?php if ($view === 'actions'): ?>
    <details class="management-create-panel"<?php echo $policyRows === [] ? ' open' : ''; ?>><summary>Create action policy with controls</summary><?php lorkhan_ui_action_policy_form(null,$rows,$installationOptions,$actionProfileOptions,$managementBasePath,$csrf); ?></details>
    <?php endif; ?>
    <?php foreach ($forms as $form): ?>
        <details class="management-create-panel"<?php echo $rows === [] ? ' open' : ''; ?>>
            <summary><?php echo lorkhan_ui_h($form['legend']); ?></summary>
            <?php lorkhan_ui_management_form($form, $managementBasePath, $csrf); ?>
        </details>
    <?php endforeach; ?>
    <?php if ($view === 'actions'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Action Policies</h3></div><div class="widget-content"><p>Policies can only reduce the immutable server catalog and the capabilities negotiated with OpenMW. Profile-specific policies take precedence over installation policies; equal scopes use policy name order.</p><?php lorkhan_ui_action_policy_cards($policyRows,$rows,$installationOptions,$actionProfileOptions,$managementBasePath,$csrf); ?></div></section>
    <?php endif; ?>
    <?php if ($view === 'autonomy'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Narrator and Diary Records</h3></div><div class="widget-content"><?php lorkhan_ui_narrative_cards($narrativeRows,$managementBasePath,$csrf); ?></div></section>
    <?php endif; ?>
    <?php endif; ?>
</main>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo lorkhan_ui_h($uiAssetVersion); ?>" defer></script>
<?php if ($view === 'actions'): ?><script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/action-editor.js?v=<?php echo lorkhan_ui_h((string) filemtime(dirname(__DIR__) . '/js/action-editor.js')); ?>" defer></script><?php endif; ?>
<?php if ($view === 'characters'): ?><script type="module" src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/profile-json-editor.js?v=<?= filemtime(dirname(__DIR__).'/js/profile-json-editor.js') ?>"></script><?php endif; ?>
<?php if ($view === 'characters'): ?><script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/npc-setting-overrides.js?v=<?php echo filemtime(dirname(__DIR__).'/js/npc-setting-overrides.js'); ?>" defer></script><?php endif; ?>
<?php if ($view === 'characters'): ?><script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/npc-biography-import.js?v=<?= filemtime(dirname(__DIR__).'/js/npc-biography-import.js') ?>" defer></script><?php endif; ?>
<?php include $uiRootDir . '/tmpl/footer.html'; ?>
