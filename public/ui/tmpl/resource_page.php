<?php

declare(strict_types=1);

use ALMSIVIserver\Application\ConnectorCatalog;
use ALMSIVIserver\Infrastructure\ProductRepository;

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
            ['llm_randomizer_enabled', 'LLM randomizer', 'checkbox', '1', [], false],
            ['llm_fallback_configuration_id', 'Fallback LLM', 'select', '', $llmRoutingOptions],
            ['oghma_configuration_id', 'Oghma Extractor', 'select', '', $llmRoutingOptions],
            ['llm_fallback_enabled', 'Fallback retry', 'checkbox', '1', [], false],
            ['tts_configuration_id', 'TTS connector', 'select', '', $ttsRoutingOptions],
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
                ['llm_randomizer_enabled', 'LLM randomizer', 'checkbox', '1', [], false],
                ['llm_fallback_configuration_id', 'Fallback LLM', 'select', '', $llmRoutingOptions],
                ['oghma_configuration_id', 'Oghma Extractor', 'select', '', $llmRoutingOptions],
                ['llm_fallback_enabled', 'Fallback retry', 'checkbox', '1', [], false],
                ['tts_configuration_id', 'TTS connector', 'select', '', $ttsRoutingOptions],
                ['voice_id', 'Voice sample', 'datalist', '', $voiceOptions, false],
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
    echo '<form id="management-form-' . almsivi_ui_h($formId) . '" class="management-form" method="post" action="' . almsivi_ui_h($managementBasePath . '/forms/' . $form['route']) . '"><fieldset><legend>' . almsivi_ui_h($form['legend']) . '</legend>';
    foreach ($form['fields'] as $field) {
        [$name, $label] = $field;
        $type = $field[2] ?? 'text';
        $value = $field[3] ?? '';
        $required = $field[5] ?? true;
        $id = 'field-' . $formId . '-' . $name;
        echo '<div class="management-field management-field-' . almsivi_ui_h($type) . '">';
        if ($type === 'checkbox') {
            $checked=($field[6]??false)===true?' checked':'';
            echo '<label><input name="' . almsivi_ui_h($name) . '" type="checkbox" value="' . almsivi_ui_h($value) . '"'.$checked.'> ' . almsivi_ui_h($label) . '</label></div>';
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
            echo'<p class="management-note" data-connector-options-empty'.($selectedFields===[]?'':' hidden').'>This connector has no additional labelled options.</p></div></div>';continue;
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
        echo '</div>';
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

/** Render the searchable NPC-manager controls without exposing profile content in the query string. */
function almsivi_ui_profile_filters(array $rows):void
{
    $installations=[];foreach($rows as$row){$id=(string)($row['installation_id']??'');if($id!=='')$installations[$id]=$id;}
    $selectedInitial=(string)($_GET['initial']??'');
    echo'<form class="profile-management-filters" method="get"><div class="npc-toolbar-tools"><label for="profile-filter-q">Search NPCs<input id="profile-filter-q" type="search" name="q" maxlength="100" value="'.almsivi_ui_h($_GET['q']??'').'" placeholder="Search..."></label>';
    $states=['all'=>'All NPCs','favorites'=>'Favorites','locked'=>'Locked','unlocked'=>'Unlocked','generated'=>'AI generated'];
    echo'<label for="profile-filter-state">Status<select id="profile-filter-state" name="state">';foreach($states as$value=>$label)echo'<option value="'.$value.'"'.(($_GET['state']??'all')===$value?' selected':'').'>'.$label.'</option>';echo'</select></label>';
    if(count($installations)>1){echo'<label for="profile-filter-installation">Installation<select id="profile-filter-installation" name="installation_id"><option value="">All installations</option>';foreach($installations as$id)echo'<option value="'.almsivi_ui_h($id).'"'.(($_GET['installation_id']??'')===$id?' selected':'').'>'.almsivi_ui_h($id).'</option>';echo'</select></label>';}
    if(($_GET['embed']??'')==='1')echo'<input type="hidden" name="embed" value="1">';
    echo'<button class="btn-base btn-primary" type="submit">Filter NPCs</button><a class="btn-base" href="?'.(($_GET['embed']??'')==='1'?'embed=1':'').'">Clear</a></div>';
    echo'<div class="npc-letter-filter" aria-label="Filter NPCs by first letter"><button class="npc-letter-btn'.($selectedInitial===''?' active':'').'" type="submit" name="initial" value="">All</button>';
    foreach(range('A','Z')as$letter)echo'<button class="npc-letter-btn'.($selectedInitial===$letter?' active':'').'" type="submit" name="initial" value="'.$letter.'">'.$letter.'</button>';
    echo'</div></form>';
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
function almsivi_ui_profile_cards(array $rows,array $voiceOptions,array $promptRows,array $llmRows,array $ttsRows,string $managementBasePath,string $csrf,bool $showFilters=true,bool $showSummary=true,bool $compact=false,array $coreProfileRows=[],bool $editorOpen=false):void
{
    if ($rows === []) { echo '<p class="empty-state">No NPC profiles are configured yet.</p>'; return; }
    $allCount=count($rows);if($showFilters)almsivi_ui_profile_filters($rows);$rows=almsivi_ui_filter_profiles($rows);
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
        $portrait=is_array($content['portrait']??null)?$content['portrait']:[];
        $portraitEndpoint=preg_replace('#/manage$#','/ui/core/profile_portrait.php',$managementBasePath)?:'/ALMSIVIserver/ui/core/profile_portrait.php';
        $routing=is_array($content['routing']??null)&&!array_is_list($content['routing'])?$content['routing']:[];
        $promptId=(string)($routing['prompt_configuration_id']??'');$llmId=(string)($routing['llm_configuration_id']??'');
        $fastLlmId=(string)($routing['llm_fast_configuration_id']??'');$powerfulLlmId=(string)($routing['llm_powerful_configuration_id']??'');
        $experimentalLlmId=(string)($routing['llm_experimental_configuration_id']??'');$fallbackLlmId=(string)($routing['llm_fallback_configuration_id']??'');$oghmaLlmId=(string)($routing['oghma_configuration_id']??'');
        $randomizerEnabled=($routing['llm_randomizer_enabled']??false)===true;$fallbackEnabled=($routing['llm_fallback_enabled']??false)===true;
        $ttsId=(string)($routing['tts_configuration_id']??'');
        $promptOptions=almsivi_ui_profile_connector_options($promptRows,$installationId,'Use the first applicable installation prompt','');
        $llmOptions=almsivi_ui_profile_connector_options($llmRows,$installationId,'Use the current session model slot','model');
        $ttsOptions=almsivi_ui_profile_connector_options($ttsRows,$installationId,'Use the active installation TTS connector','driver');
        $coreProfileOptions=[''=>'Use installation default'];
        foreach($coreProfileRows as$coreProfileRow){
            if((string)($coreProfileRow['installation_id']??'')!==$installationId)continue;
            $coreProfileId=(string)($coreProfileRow['core_profile_id']??'');
            if($coreProfileId!=='')$coreProfileOptions[$coreProfileId]=(string)($coreProfileRow['label']??$coreProfileId);
        }
        $coreProfileId=(string)($row['core_profile_id']??'');
        if($coreProfileId!==''&&!isset($coreProfileOptions[$coreProfileId]))$coreProfileOptions[$coreProfileId]=(string)($row['core_profile_label']??'Unavailable Core Profile');
        if($promptId!==''&&!isset($promptOptions[$promptId]))$promptOptions[$promptId]='Unavailable prompt';
        foreach([$llmId,$fastLlmId,$powerfulLlmId,$experimentalLlmId,$fallbackLlmId]as$routeId)if($routeId!==''&&!isset($llmOptions[$routeId]))$llmOptions[$routeId]='Unavailable model slot';
        if($ttsId!==''&&!isset($ttsOptions[$ttsId]))$ttsOptions[$ttsId]='Unavailable TTS connector';
        echo '<article class="profile-card'.($compact?' profile-card-compact':'').'"><header><div><span class="connector-kind">'.($isTemplate?'Biography template':'OpenMW NPC').'</span><h3>' . almsivi_ui_h($row['name'] ?? '') . '</h3></div>';
        echo '<div class="profile-statuses">'.($favorite?'<span class="status-badge connector-active">Favorite</span>':'').($locked?'<span class="status-badge profile-locked">Locked</span>':'').'<span class="status-badge">Revision ' . almsivi_ui_h($row['current_revision'] ?? '') . '</span></div></header>';
        if($portrait!==[])echo'<div class="profile-portrait"><img src="'.almsivi_ui_h($portraitEndpoint.'?profile_id='.rawurlencode($profileId).'&revision='.(int)($row['current_revision']??1)).'" alt="Portrait of '.almsivi_ui_h($row['name']??'NPC').'" width="160" height="160"></div>';
        elseif($compact)echo'<div class="profile-portrait profile-portrait-fallback" aria-hidden="true">'.almsivi_ui_h(mb_strtoupper(mb_substr((string)($row['name']??'N'),0,1))).'</div>';
        if($compact)echo'<dl class="profile-card-glance"><dt>Race</dt><dd>'.almsivi_ui_h(trim((string)($content['gender']??'').' '.(string)($content['race']??''))?:'Unspecified').'</dd><dt>Voice</dt><dd>'.almsivi_ui_h($voice['id']??'Connector default').'</dd><dt>Bindings</dt><dd>'.almsivi_ui_h($row['binding_count']??0).'</dd></dl><details class="profile-routing-summary"><summary>Profile summary</summary>';
        echo '<dl><dt>'.($isTemplate?'Record match':'Record').'</dt><dd><code>' . almsivi_ui_h($identity['record_id'] ?? ($isTemplate?'Any record':'Unbound profile')) . '</code></dd>';
        echo '<dt>Content file</dt><dd>' . almsivi_ui_h($identity['content_file'] ?? '') . '</dd>';
        echo '<dt>Gender / race</dt><dd>' . almsivi_ui_h(trim((string)($content['gender']??'').' '.(string)($content['race']??''))?:'Unspecified') . '</dd>';
        echo '<dt>Dialogue prompt</dt><dd>' . almsivi_ui_h($promptOptions[$promptId]??$promptOptions['']) . '</dd>';
        echo '<dt>Standard LLM</dt><dd>' . almsivi_ui_h($llmOptions[$llmId]??$llmOptions['']) . '</dd>';
        echo '<dt>LLM routing</dt><dd>' . almsivi_ui_h($randomizerEnabled?'Randomized configured slots':'Standard slot') . '</dd>';
        echo '<dt>Fallback LLM</dt><dd>' . almsivi_ui_h($fallbackEnabled?($llmOptions[$fallbackLlmId]??$llmOptions['']):'Disabled') . '</dd>';
        echo '<dt>TTS connector</dt><dd>' . almsivi_ui_h($ttsOptions[$ttsId]??$ttsOptions['']) . '</dd>';
        echo '<dt>Voice</dt><dd>' . almsivi_ui_h($voice['id'] ?? 'Connector default') . '</dd>';
        echo '<dt>Core Profile</dt><dd>' . almsivi_ui_h($coreProfileOptions[$coreProfileId]??$coreProfileOptions['']) . '</dd>';
        echo '<dt>In-game bindings</dt><dd>' . almsivi_ui_h($row['binding_count'] ?? 0) . '</dd></dl>';
        if($compact)echo'</details>';
        echo '<details class="profile-primary-editor"'.($editorOpen?' open':'').'><summary>Edit roleplay and voice</summary>';
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
                ['core_profile_id','Core Profile','select',$coreProfileId,$coreProfileOptions],
                ['prompt_configuration_id','Dialogue prompt','select',$promptId,$promptOptions],
                ['llm_configuration_id','Standard LLM','select',$llmId,$llmOptions],
                ['llm_fast_configuration_id','Fast LLM','select',$fastLlmId,$llmOptions],
                ['llm_powerful_configuration_id','Powerful LLM','select',$powerfulLlmId,$llmOptions],
                ['llm_experimental_configuration_id','Experimental LLM','select',$experimentalLlmId,$llmOptions],
                ['llm_randomizer_enabled','Randomize configured LLM slots','checkbox','1',[],false,$randomizerEnabled],
                ['llm_fallback_configuration_id','Fallback LLM','select',$fallbackLlmId,$llmOptions],
                ['oghma_configuration_id','Oghma Extractor','select',$oghmaLlmId,$llmOptions],
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

/** Render one typed NPC revision form inside the pinned Herika six-tab editor presentation. */
function almsivi_ui_npc_editor_form(array $row,array $voiceOptions,array $promptRows,array $llmRows,array $ttsRows,array $coreProfileRows,string $managementBasePath,string $csrf,bool$creating=false,array$installationOptions=[],array$effectiveSettings=[]):void
{
    $content=is_array($row['content']??null)?$row['content']:[];$identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];
    $profileId=$creating?'create':(string)($row['profile_id']??'');$installationId=$creating?(string)(array_key_first($installationOptions)??''):(string)($row['installation_id']??'');$formId='management-form-profile-'.$profileId;
    $management=is_array($content['management']??null)?$content['management']:[];$routing=is_array($content['routing']??null)?$content['routing']:[];
    $overrides=is_array($content['settings_overrides']??null)?$content['settings_overrides']:[];$voice=$content['voice']??[];
    if(is_string($voice))$voice=['id'=>$voice];if(!is_array($voice))$voice=[];
    $locked=($management['locked']??false)===true;$favorite=($management['favorite']??false)===true;
    $promptOptions=almsivi_ui_profile_connector_options($promptRows,$installationId,'Inherit Core Profile','');
    $llmOptions=almsivi_ui_profile_connector_options($llmRows,$installationId,'Inherit Core Profile','model');
    $ttsOptions=almsivi_ui_profile_connector_options($ttsRows,$installationId,'Inherit Core Profile','driver');
    $coreProfileOptions=[''=>'Use installation default'];foreach($coreProfileRows as$coreProfileRow){
        if((string)($coreProfileRow['installation_id']??'')!==$installationId)continue;$id=(string)($coreProfileRow['core_profile_id']??'');
        if($id!=='')$coreProfileOptions[$id]=(string)($coreProfileRow['label']??$id);
    }
    $coreProfileId=(string)($row['core_profile_id']??'');if($coreProfileId!==''&&!isset($coreProfileOptions[$coreProfileId]))$coreProfileOptions[$coreProfileId]=(string)($row['core_profile_label']??'Unavailable Core Profile');
    $field=function(string$name,string$label,string$type='text',string$value='',array$options=[],string$classes='',string$help='')use($formId,$profileId,$creating):void{
        $id='npc-editor-'.substr(hash('sha256',$profileId.$name),0,14);$class='form-item'.($classes===''?'':' '.$classes);
        $required=$creating&&in_array($name,['installation_id','name'],true)?' required':'';
        $describe=$help===''?'':' aria-describedby="'.almsivi_ui_h($id).'-help"';
        echo'<div class="'.almsivi_ui_h($class).'"><label for="'.almsivi_ui_h($id).'">'.almsivi_ui_h($label).'</label>';
        if($type==='textarea')echo'<textarea id="'.almsivi_ui_h($id).'" name="'.almsivi_ui_h($name).'" form="'.almsivi_ui_h($formId).'"'.$required.$describe.'>'.almsivi_ui_h($value).'</textarea>';
        elseif($type==='select'){echo'<select id="'.almsivi_ui_h($id).'" name="'.almsivi_ui_h($name).'" form="'.almsivi_ui_h($formId).'"'.$required.$describe.'>';foreach($options as$optionValue=>$optionLabel)echo'<option value="'.almsivi_ui_h($optionValue).'"'.((string)$optionValue===$value?' selected':'').'>'.almsivi_ui_h($optionLabel).'</option>';echo'</select>';}
        elseif($type==='datalist'){$listId=$id.'-options';echo'<input id="'.almsivi_ui_h($id).'" name="'.almsivi_ui_h($name).'" form="'.almsivi_ui_h($formId).'" type="text" list="'.almsivi_ui_h($listId).'" value="'.almsivi_ui_h($value).'"'.$describe.'><datalist id="'.almsivi_ui_h($listId).'">';foreach($options as$optionValue=>$optionLabel)echo'<option value="'.almsivi_ui_h(is_int($optionValue)?$optionLabel:$optionValue).'">'.almsivi_ui_h($optionLabel).'</option>';echo'</datalist>';}
        else echo'<input id="'.almsivi_ui_h($id).'" name="'.almsivi_ui_h($name).'" form="'.almsivi_ui_h($formId).'" type="'.almsivi_ui_h($type).'" value="'.almsivi_ui_h($value).'"'.$required.$describe.'>';
        if($help!=='')echo'<small class="hint" id="'.almsivi_ui_h($id).'-help">'.almsivi_ui_h($help).'</small>';
        echo'</div>';
    };
    $checkbox=function(string$name,string$label,bool$checked,string$hint='')use($formId):void{echo'<div class="form-item npc-editor-check"><label><input name="'.almsivi_ui_h($name).'" form="'.almsivi_ui_h($formId).'" type="checkbox" value="1"'.($checked?' checked':'').'> '.almsivi_ui_h($label).'</label>'.($hint===''?'':'<small class="hint">'.almsivi_ui_h($hint).'</small>').'</div>';};
    $inheritBool=function(string$name,string$label,mixed$value)use($field):void{$field($name,$label,'select',$value===true?'1':($value===false?'0':'inherit'),['inherit'=>'Inherit Core Profile','1'=>'Enabled','0'=>'Disabled']);};
    $number=function(string$section,string$name,string$label,int$min,int$max)use($formId,$overrides):void{$id='npc-editor-'.substr(hash('sha256',$formId.$section.$name),0,14);$value=$overrides[$section][$name]??'';echo'<div class="form-item"><label for="'.$id.'">'.almsivi_ui_h($label).'</label><input id="'.$id.'" name="setting_'.almsivi_ui_h($section.'_'.$name).'" form="'.almsivi_ui_h($formId).'" type="number" min="'.$min.'" max="'.$max.'" value="'.almsivi_ui_h($value).'" placeholder="Inherit Core Profile"></div>';};
    $disabled=function(string$label,string$feature,string$value='',string$classes='')use($formId):void{$id='npc-disabled-'.substr(hash('sha256',$formId.$label),0,14);echo'<div class="form-item npc-editor-disabled'.($classes===''?'':' '.almsivi_ui_h($classes)).'"><label for="'.$id.'">'.almsivi_ui_h($label).' '.almsivi_ui_feature_badge($feature,true).'</label><input id="'.$id.'" type="text" value="'.almsivi_ui_h($value).'" disabled aria-disabled="true" title="'.almsivi_ui_h(almsivi_ui_feature($feature)['description']).'"></div>';};
    $disabledOverrideBool=function(string$name,string$label,mixed$value,string$feature)use($formId,$disabled):void{$stored=$value===true?'1':($value===false?'0':'inherit');echo'<input type="hidden" name="'.almsivi_ui_h($name).'" form="'.almsivi_ui_h($formId).'" value="'.$stored.'">';$disabled($label,$feature,$value===true?'On':($value===false?'Off':'Inherit'));};
    $disabledOverrideNumber=function(string$section,string$name,string$label,string$feature)use($formId,$overrides,$disabled):void{$value=$overrides[$section][$name]??'';echo'<input type="hidden" name="setting_'.almsivi_ui_h($section.'_'.$name).'" form="'.almsivi_ui_h($formId).'" value="'.almsivi_ui_h($value).'">';$disabled($label,$feature,$value===''?'Inherit':(string)$value);};
    $routingValue=static fn(array$r,string$key):string=>array_key_exists($key,$r)?((string)$r[$key]===''?'__disabled__':(string)$r[$key]):'';
    $routingOptions=static fn(array$options):array=>[''=>'Inherit Core Profile','__disabled__'=>'Disabled for this NPC']+$options;
    $labelOf=static fn(array$options,string$id):string=>(string)($options[$id]??($id===''?'Inherit Core Profile':'Unavailable'));
    $standard=$routingValue($routing,'llm_configuration_id');$fast=$routingValue($routing,'llm_fast_configuration_id');$power=$routingValue($routing,'llm_powerful_configuration_id');
    $experimental=$routingValue($routing,'llm_experimental_configuration_id');$fallback=$routingValue($routing,'llm_fallback_configuration_id');$oghma=$routingValue($routing,'oghma_configuration_id');
    $generation=$routingValue($routing,'profile_generation_configuration_id');
    $generationOptions=[''=>'Inherit Core Profile','__disabled__'=>'Use server runtime']+$llmOptions;
    if(!isset($generationOptions[$generation]))$generationOptions[$generation]='Unavailable connector';
    $uiRoot=preg_replace('#/manage$#','',$managementBasePath)?:'/ALMSIVIserver';
    echo'<div class="npc-editor-meta"><label for="npc-editor-tags-'.$profileId.'">Tags:</label><input id="npc-editor-tags-'.$profileId.'" name="tags" form="'.almsivi_ui_h($formId).'" value="'.almsivi_ui_h(is_array($content['tags']??null)?implode(', ',array_map('strval',$content['tags'])):(string)($content['tags']??'')).'" placeholder="tags">'.($creating?'':'<a class="btn-base" target="_blank" rel="noopener" href="'.almsivi_ui_h($uiRoot.'/ui/oghma_knowledge.php?installation_id='.rawurlencode($installationId).'&profile_id='.rawurlencode($profileId)).'">Oghma Knowledge</a>').'<label class="npc-editor-favorite" title="Favorite NPC"><input type="checkbox" name="favorite" form="'.almsivi_ui_h($formId).'" value="1"'.($favorite?' checked':'').'><span>'.($favorite?'&#9733;':'&#9734;').'</span></label></div>';
    echo'<div class="npc-profile-llms"><strong>Profile LLMs</strong><span>&#127918; '.almsivi_ui_h($labelOf($llmOptions,$standard)).' | &#127939; '.almsivi_ui_h($labelOf($llmOptions,$fast)).' | &#128170; '.almsivi_ui_h($labelOf($llmOptions,$power)).' | &#129514; '.almsivi_ui_h($labelOf($llmOptions,$experimental)).' | &#128209; '.almsivi_ui_feature_badge('config.profiles.diary-llm',true).' | &#129534; '.almsivi_ui_feature_badge('config.profiles.formatter-llm',true).'</span></div>';
    echo'<div class="npc-editor-tabs" role="tablist" aria-label="NPC editor categories" data-npc-editor-tabs>';
    foreach(['general'=>'&#129517; General','roleplay'=>'&#128214; Roleplay','relationships'=>'&#129309; Relationships','background-life'=>'&#127758; Background Life','info'=>'&#128736;&#65039; Info','actions'=>'&#9889; Actions']as$key=>$label)echo'<button type="button" class="npc-editor-tab'.($key==='general'?' is-active':'').'" role="tab" aria-selected="'.($key==='general'?'true':'false').'" data-npc-editor-tab="'.$key.'">'.$label.'</button>';
    echo'</div><div class="npc-editor-panels">';
    echo'<section class="npc-editor-panel form-grid" role="tabpanel" data-npc-editor-panel="general">';
    if($creating){$field('installation_id','Installation','select',$installationId,$installationOptions, 'span-2');$field('name','NPC Name','text','',[],'span-2');}
    else$disabled('NPC Name','config.npc.identity',(string)($row['name']??''),'span-2');
    $field('core_profile_id','Profile','select',$coreProfileId,$coreProfileOptions);$checkbox('locked','Lock against automatic AI profile generation',$locked,'Prevents automatic AI profile generation from replacing manual edits.');
    $field('gender','Gender','select',(string)($content['gender']??''),[''=>'Unspecified','Male'=>'Male','Female'=>'Female','Other'=>'Other']);$field('race','Race','text',(string)($content['race']??''));
    if($creating){$field('content_file','Base / Content File','text','Morrowind.esm');$field('record_id','Ref ID','text','');$field('refnum','Reference Number','text','');}
    else{$disabled('Base / Content File','config.npc.identity',(string)($identity['content_file']??''));$disabled('Ref ID','config.npc.identity',(string)($identity['record_id']??''));}
    $field('voice_id','Voice sample','datalist',(string)($voice['id']??''),$voiceOptions);$field('voice_language','Voice Language','text',(string)($voice['language']??'en'));
    $disabled('Dynamic Profile','config.profiles.dynamic-profile','Not connected');$disabled('Middle Term Memory','config.profiles.middle-term-memory','Typed memory repositories');$disabled('Individual Memory Bank','config.npc.memory-bank','Typed memory repositories');$disabled('Automatic Diary','config.profiles.auto-diary','Excluded');
    $disabledOverrideBool('setting_behavior_auto_greeting','Auto Greeting',$overrides['behavior']['auto_greeting']??null,'autonomy');$field('prompt_head','Prompt head (advanced system guidance)','textarea',(string)($content['prompt_head']??''),[],'span-2');echo'</section>';
    echo'<section class="npc-editor-panel form-grid" role="tabpanel" data-npc-editor-panel="roleplay" hidden>';
    $field('core','Core identity and boundaries','textarea',(string)($content['core']??''),[],'span-2');$field('biography','Biography','textarea',(string)($content['biography']??''),[],'span-2');$field('appearance','Appearance','textarea',(string)($content['appearance']??''));$field('personality','Personality','textarea',(string)($content['personality']??''));$field('occupation','Occupation','text',(string)($content['occupation']??''));$field('skills','Skills and capabilities','textarea',(string)($content['skills']??''));$field('emote_moods','Allowed moods and emotes','textarea',(string)($content['emote_moods']??''));$field('speech_style','Speech Style','textarea',(string)($content['speech_style']??''));$field('goals','Goals','textarea',(string)($content['goals']??''));echo'</section>';
    echo'<section class="npc-editor-panel form-grid" role="tabpanel" data-npc-editor-panel="relationships" hidden>';
    $field('relationships','Relationships','textarea',(string)($content['relationships']??''),[],'span-2');
    $relationshipRoute=$routingValue($routing,'relationship_configuration_id');$relationshipOptions=$routingOptions($llmOptions);
    if(!isset($relationshipOptions[$relationshipRoute]))$relationshipOptions[$relationshipRoute]='Unavailable connector';
    $field('relationship_configuration_id','Relationship LLM','select',$relationshipRoute,$relationshipOptions,'span-2','Inherit uses the Core Profile connector. Disabled stops automatic updates and manual builds. Chance 0 stops automatic calls; 100 evaluates every eligible played response. Saved relationships still appear in prompts.');
    $number('relationship','update_chance_percent','Relationship Update Chance (0-100)',0,100);
    $relationshipLock=$overrides['relationship']['locked']??null;
    $field('setting_relationship_locked','Relationship Lock','select',$relationshipLock===true?'1':($relationshipLock===false?'0':'inherit'),['inherit'=>'Inherit Core Profile','1'=>'Locked','0'=>'Unlocked'],'','Stop AI relationship updates and manual builds. Separate from the profile lock on the General tab.');
    echo'<div class="npc-editor-placeholder span-2"><h3>Build with AI</h3><p>Analyze recent played conversations for this NPC. Choose a playthrough on Relationship Audit.</p>';
    if($creating)echo'<button type="button" class="btn-base" disabled>Save this NPC first</button>';
    else echo'<a class="btn-base btn-primary" target="_blank" rel="noopener" href="'.almsivi_ui_h($uiRoot.'/ui/relationship_logs.php?installation_id='.rawurlencode($installationId).'&profile_id='.rawurlencode($profileId).'#relationship-builder').'">Build with AI</a>';
    echo'</div></section>';
    echo'<section class="npc-editor-panel" role="tabpanel" data-npc-editor-panel="background-life" hidden><div class="npc-bgl-dashboard"><section><header><div><h3>Current Background Life '.almsivi_ui_feature_badge('roleplay.background-life',true).'</h3><p>'.almsivi_ui_h(almsivi_ui_feature('roleplay.background-life')['description']).'</p></div><button type="button" disabled aria-disabled="true">Excluded</button></header><div class="npc-bgl-summary"><article><span>Status</span><strong>Excluded</strong></article><article><span>Current Location</span><strong>Not tracked</strong></article><article><span>Latest Activity</span><strong>Not generated</strong></article></div></section><section><header><div><h3>Rules</h3><p>Copied controls remain visible but cannot schedule background activity.</p></div></header><div class="npc-bgl-control-grid"><button type="button" disabled>Auto Actions '.almsivi_ui_feature_badge('roleplay.background-life',true).'</button><button type="button" disabled>Send Letters '.almsivi_ui_feature_badge('roleplay.background-life',true).'</button><button type="button" disabled>Hourly Tracking '.almsivi_ui_feature_badge('roleplay.background-life',true).'</button></div></section><section><header><div><h3>Immediate Actions</h3><p>Background requests are intentionally unavailable.</p></div></header><div class="npc-bgl-request-grid"><button type="button" disabled>Trigger Action</button><button type="button" disabled>Send Letter</button><button type="button" disabled>Update Location</button></div></section></div></section>';
    echo'<section class="npc-editor-panel form-grid" role="tabpanel" data-npc-editor-panel="info" hidden>';
    if(!$creating&&$effectiveSettings!==[]){echo'<div class="span-2">';almsivi_ui_effective_settings_summary($effectiveSettings,'Effective NPC settings and sources');echo'</div>';}
    $field('prompt_configuration_id','Dialogue Prompt','select',$routingValue($routing,'prompt_configuration_id'),$routingOptions($promptOptions));$field('llm_configuration_id','Standard LLM','select',$standard,$routingOptions($llmOptions));$field('llm_fast_configuration_id','Fast LLM','select',$fast,$routingOptions($llmOptions));$field('llm_powerful_configuration_id','Powerful LLM','select',$power,$routingOptions($llmOptions));$field('llm_experimental_configuration_id','Experimental LLM','select',$experimental,$routingOptions($llmOptions));$field('llm_fallback_configuration_id','Fallback LLM','select',$fallback,$routingOptions($llmOptions));$field('oghma_configuration_id','Oghma Extractor','select',$oghma,$routingOptions($llmOptions));$field('profile_generation_configuration_id','Profile Generation LLM','select',$generation,$generationOptions,'','Applies to newly queued generation jobs. Queued jobs keep their frozen connector revision; saving never calls a provider.');$field('tts_configuration_id','TTS connector','select',$routingValue($routing,'tts_configuration_id'),$routingOptions($ttsOptions));
    $inheritBool('llm_randomizer_enabled','LLM randomizer',$routing['llm_randomizer_enabled']??null);$inheritBool('llm_fallback_enabled','Fallback retry',$routing['llm_fallback_enabled']??null);$disabled('Diary LLM','config.profiles.diary-llm','Active narrative pipeline');$disabled('Formatter LLM','config.profiles.formatter-llm','Not configured');$field('notes','Notes','textarea',(string)($content['notes']??''),[],'span-2');if(!$creating)$field('change_reason','Change Reason','text','management edit',[],'span-2');echo'</section>';
    echo'<section class="npc-editor-panel form-grid" role="tabpanel" data-npc-editor-panel="actions" hidden>';
    $disabledOverrideBool('setting_behavior_rechat','Rechat',$overrides['behavior']['rechat']??null,'autonomy');$disabledOverrideBool('setting_behavior_boredom','Boredom Events',$overrides['behavior']['boredom']??null,'autonomy');$disabledOverrideBool('setting_behavior_combat_barks','Combat Barks',$overrides['behavior']['combat_barks']??null,'autonomy');$disabledOverrideNumber('behavior','rechat_delay_seconds','Rechat Delay','autonomy');$disabledOverrideNumber('behavior','rechat_max_depth','Rechat Depth','autonomy');$disabledOverrideNumber('behavior','boredom_delay_seconds','Boredom Delay','autonomy');$disabledOverrideNumber('behavior','combat_bark_period_seconds','Combat Bark Period','autonomy');$number('memory','recent_turn_limit','Recent Turns',1,100);$number('memory','knowledge_limit','Knowledge Results',0,20);$inheritBool('setting_oghma_enabled','Oghma Enabled',$overrides['oghma']['enabled']??null);$number('oghma','topic_count','Oghma Topics',1,3);$number('oghma','result_limit','Oghma Results',1,5);$inheritBool('setting_oghma_racial_context_enabled','Oghma Racial Context',$overrides['oghma']['racial_context_enabled']??null);$inheritBool('setting_oghma_location_context_enabled','Oghma Location Context',$overrides['oghma']['location_context_enabled']??null);$inheritBool('setting_oghma_extractor_fallback_enabled','Oghma Extractor Fallback',$overrides['oghma']['extractor_fallback_enabled']??null);$number('oghma','extractor_timeout_ms','Oghma Extractor Timeout',250,3000);$disabledOverrideBool('setting_presentation_show_status_hud','Show Status HUD',$overrides['presentation']['show_status_hud']??null,'presentation.local');$disabledOverrideNumber('presentation','transcript_rows','Transcript Rows','presentation.local');$disabledOverrideNumber('presentation','tts_volume_boost','TTS Volume Boost','presentation.local');$inheritBool('setting_safety_actions_enabled','Negotiated Actions',$overrides['safety']['actions_enabled']??null);$inheritBool('setting_safety_allow_hostile','Hostile Targets',$overrides['safety']['allow_hostile']??null);$inheritBool('setting_safety_allow_creatures','Creature Targets',$overrides['safety']['allow_creatures']??null);echo'<div class="npc-editor-placeholder span-2"><h3>Action Policy</h3><p>NPC overrides inherit Global Settings through the assigned Core Profile. The Action Editor remains the source of negotiated OpenMW capabilities.</p></div></section>';
    echo'</div><form id="'.almsivi_ui_h($formId).'" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/'.($creating?'profile-create':'profile-revise')).'">';
    if(!$creating)echo'<input type="hidden" name="profile_id" value="'.almsivi_ui_h($profileId).'"><input type="hidden" name="base_content_json" value="'.almsivi_ui_h(json_encode($content===[]?(object)[]:$content,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'">';
    echo'<input type="hidden" name="management_fields" value="1"><input type="hidden" name="llm_routing_fields" value="1"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"></form>';
}

/** Render profiles with the same left-list and right-editor hierarchy used by CHIM. */
function almsivi_ui_profiles_page(array $rows,array $forms,array $voiceOptions,array $promptRows,array $llmRows,array $ttsRows,string $description,string $managementBasePath,string $csrf):void
{
    $selectedId=(string)($_GET['selected']??'');$selected=null;
    foreach($rows as$row)if(hash_equals((string)($row['profile_id']??''),$selectedId)){$selected=$row;break;}
    echo'<header class="configuration-page-header"><h1>ALMSIVI Profiles</h1><p>'.almsivi_ui_h($description).'</p></header>';
    echo'<div class="configuration-split-shell"><aside class="configuration-sidebar"><div class="configuration-sidebar-actions">';
    foreach($forms as$index=>$form){echo'<details class="configuration-action-panel"><summary class="btn-base '.($index===0?'btn-success':'btn-primary').'">'.almsivi_ui_h($form['legend']??'Manage').'</summary>';almsivi_ui_management_form($form,$managementBasePath,$csrf);echo'</details>';}
    echo'</div><div class="configuration-record-list">';
    foreach($rows as$row){$id=(string)($row['profile_id']??'');$content=is_array($row['content']??null)?$row['content']:[];$management=is_array($content['management']??null)?$content['management']:[];
        $query=http_build_query(['embed'=>($_GET['embed']??'')==='1'?'1':null,'selected'=>$id]);
        echo'<a class="configuration-record'.($selected!==null&&hash_equals($selectedId,$id)?' active':'').'" href="?'.almsivi_ui_h($query).'"><span><strong>'.almsivi_ui_h($row['name']??'Profile').'</strong><small>'.almsivi_ui_h(($row['binding_count']??0).' NPC binding'.((int)($row['binding_count']??0)===1?'':'s')).'</small></span><span class="status-badge">'.(($management['locked']??false)===true?'Locked':'Rev '.(int)($row['current_revision']??1)).'</span></a>';}
    echo'</div></aside><section class="configuration-detail">';
    if($selected===null)echo'<div class="configuration-empty"><h2>No profile selected</h2><p>Select a profile from the list on the left to view and edit its settings.</p></div>';
    else almsivi_ui_profile_cards([$selected],$voiceOptions,$promptRows,$llmRows,$ttsRows,$managementBasePath,$csrf,false,false);
    echo'</section></div>';
}

/** Render the player editor as the same centered header and two-column settings surface used by CHIM. */
function almsivi_ui_player_page(array $rows,array $forms,string $description,string $managementBasePath,string $csrf):void
{
    echo'<header class="configuration-page-header"><h1>&#128100; Player Management</h1><p>'.almsivi_ui_h($description).'</p></header>';
    echo'<section class="player-settings-shell">';
    if($rows===[]){foreach($forms as$form)almsivi_ui_management_form($form,$managementBasePath,$csrf);}
    else almsivi_ui_player_cards($rows,$managementBasePath,$csrf);
    echo'</section>';
}

/** Render narrator controls in the same centered, two-column settings format as CHIM. */
function almsivi_ui_narrator_page(array $rows,array $forms,array $voiceOptions,array $ttsRows,string $description,string $managementBasePath,string $csrf):void
{
    echo'<header class="configuration-page-header"><h1>&#128483; Narrator Management</h1><p>'.almsivi_ui_h($description).'</p></header>';
    echo'<section class="narrator-settings-shell">';
    if($rows===[]){foreach($forms as$form)almsivi_ui_management_form($form,$managementBasePath,$csrf);}
    else almsivi_ui_narrator_cards($rows,$voiceOptions,$ttsRows,$managementBasePath,$csrf);
    echo'</section>';
}

/** Render ALMSIVI profiles with the card hierarchy and modal editing flow used by CHIM's NPC page. */
function almsivi_ui_chim_profile_cards(array $rows,array $voiceOptions,array $promptRows,array $llmRows,array $ttsRows,array $coreProfileRows,array $effectiveProfileSettings,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo'<p class="npc-empty-state">No NPC profiles match these filters.</p>';return;}
    $portraitEndpoint=preg_replace('#/manage$#','/ui/core/profile_portrait.php',$managementBasePath)?:'/ALMSIVIserver/ui/core/profile_portrait.php';
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
        echo'<article class="npc-card" tabindex="0" role="button" aria-label="Edit '.almsivi_ui_h($name).'" data-npc-modal-target="'.$modalKey.'-edit">';
        echo'<div class="npc-title"><div class="npc-title-left"><span class="npc-name">'.almsivi_ui_h($name).' ('.(int)($row['current_revision']??1).')</span><span class="npc-gender-icon '.$genderClass.'" title="'.almsivi_ui_h($gender?:'Unspecified').'">'.$genderIcon.'</span></div>';
        echo'<div class="npc-title-actions"><span class="npc-tags-label">Tags:</span><span class="npc-tags-top" title="'.almsivi_ui_h($tags?:'none').'">'.almsivi_ui_h($tags?:'none').'</span>';
        echo'<form class="npc-icon-form" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/profile-toggle-favorite').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.almsivi_ui_h($profileId).'"><button class="btn btn-toggle'.($favorite?' active':'').'" type="submit" data-favorite-id="'.almsivi_ui_h($profileId).'" title="Toggle favorite" aria-label="Toggle favorite">'.($favorite?'&#9733;':'&#9734;').'</button></form>';
        echo'<button class="btn btn-toggle" type="button" data-pick-picture-id="'.almsivi_ui_h($profileId).'" data-npc-modal-target="'.$modalKey.'-edit" title="Set picture" aria-label="Set picture">&#128444;&#65039;</button>';
        echo'<form class="npc-icon-form" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/profile-toggle-lock').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.almsivi_ui_h($profileId).'"><button class="btn btn-toggle'.($locked?' active':'').'" type="submit" data-lock-id="'.almsivi_ui_h($profileId).'" title="Toggle lock" aria-label="Toggle lock">'.($locked?'&#128274;':'&#128275;').'</button></form>';
        echo'<button class="btn btn-trash'.($locked?' disabled':'').'" type="button"'.($locked?' disabled title="Locked - cannot delete"':' data-npc-modal-target="'.$modalKey.'-delete" title="Delete"').' aria-label="Delete profile">&#10060;</button></div></div>';
        echo'<div class="npc-divider"></div><div class="npc-row"><div class="npc-fields">';
        echo'<div class="npc-line"><span class="npc-muted">Gender:</span> '.almsivi_ui_h($gender?:'Unspecified').'</div>';
        echo'<div class="npc-line"><span class="npc-muted">Race:</span> '.almsivi_ui_h($content['race']??'Unspecified').'</div>';
        echo'<div class="npc-line"><span class="npc-muted">Voice:</span> '.almsivi_ui_h($voice['id']??'Connector default').'</div>';
        echo'<div class="npc-line"><span class="npc-muted">RefID:</span> '.almsivi_ui_h($recordId).'</div>';
        echo'<div class="npc-line"><span class="npc-muted">Oghma Tags:</span> '.almsivi_ui_h($tags?:'none').'</div>';
        echo'<div class="npc-line"><span class="npc-muted">Profile:</span> '.almsivi_ui_h($coreProfileLabel).'</div></div><div class="npc-right">';
        if($portrait!==[])echo'<img class="npc-race-art" src="'.almsivi_ui_h($portraitEndpoint.'?profile_id='.rawurlencode($profileId).'&revision='.(int)($row['current_revision']??1)).'" alt="Portrait of '.almsivi_ui_h($name).'">';
        else echo'<div class="npc-race-art npc-race-art-placeholder" aria-label="Portrait placeholder"><strong>'.almsivi_ui_h(mb_strtoupper(mb_substr($name,0,1))).'</strong><span>'.almsivi_ui_h($content['race']??'Morrowind NPC').'</span></div>';
        echo'</div></div></article>';

        $exportUrl=$managementBasePath.'/exports/profiles/'.$profileId.'.json';
        $narrativeUrl=preg_replace('#/manage$#','/ui/narrative_manager.php',$managementBasePath)?:'/ALMSIVIserver/ui/narrative_manager.php';
        $biographiesUrl=preg_replace('#/manage$#','/ui/core/npc_biographies.php',$managementBasePath)?:'/ALMSIVIserver/ui/core/npc_biographies.php';
        echo'<div class="npc-modal-overlay" id="'.$modalKey.'-edit" data-npc-modal hidden><section class="npc-modal npc-editor-modal" role="dialog" aria-modal="true" aria-labelledby="'.$modalKey.'-edit-title"><header class="npc-editor-header"><h2 id="'.$modalKey.'-edit-title">Edit NPC</h2><div class="npc-modal-actions">';
        echo'<button type="submit" class="btn-save" form="management-form-profile-'.almsivi_ui_h($profileId).'">Save</button>';
        echo'<a class="btn-cancel" href="'.almsivi_ui_h($exportUrl).'">Export Bio</a>';
        echo'<button type="button" class="btn-cancel" data-npc-modal-target="npc-import-modal">Import Bio</button>';
        echo'<button type="button" class="btn-cancel" disabled title="OpenMW profile reset is not available yet">Reset NPC '.almsivi_ui_feature_badge('config.npc.reset',true).'</button>';
        echo'<a class="btn-cancel" href="'.almsivi_ui_h($narrativeUrl).'">View Diary</a>';
        echo'<button type="button" class="btn-cancel" data-npc-history>View History</button>';
        if(!$locked)echo'<form class="npc-modal-header-form" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/profile-generate').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.almsivi_ui_h($profileId).'"><button type="submit" class="btn-cancel">AI Generate Profile</button></form>';
        echo'<button type="button" class="btn-cancel" data-npc-modal-close>Close</button></div></header><div class="npc-modal-tabs"><button type="button" class="active">&#9997;&#65039; Manual</button><a href="'.almsivi_ui_h($biographiesUrl).'">&#128218; NPC Biographies</a></div><div class="npc-modal-body">';
        almsivi_ui_npc_editor_form($row,$voiceOptions,$promptRows,$llmRows,$ttsRows,$coreProfileRows,$managementBasePath,$csrf,false,[],$effectiveProfileSettings[$profileId]??[]);
        $portraitControl='npc-editor-portrait-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$profileId);echo'<div class="npc-editor-secondary"><a class="btn-base" href="'.almsivi_ui_h($biographiesUrl).'">Open NPC Biographies</a><details><summary>Manage portrait</summary><form class="management-form portrait-form" method="post" enctype="multipart/form-data" action="'.almsivi_ui_h($portraitEndpoint).'"><fieldset><legend>Upload NPC portrait</legend><label for="'.almsivi_ui_h($portraitControl).'">PNG, JPEG, or WebP (5 MiB and 2048×2048 maximum)</label><input id="'.almsivi_ui_h($portraitControl).'" name="portrait" type="file" accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp" required><input type="hidden" name="profile_id" value="'.almsivi_ui_h($profileId).'"><input type="hidden" name="action" value="upload"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"></fieldset><button class="btn-base btn-primary" type="submit">Upload portrait</button></form>';
        if($portrait!==[])echo'<form method="post" action="'.almsivi_ui_h($portraitEndpoint).'"><input type="hidden" name="profile_id" value="'.almsivi_ui_h($profileId).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base btn-danger" type="submit">Delete portrait</button></form>';
        echo'</details>';
        echo'<details><summary>Clone profile</summary>';
        almsivi_ui_management_form(['route'=>'profile-clone','id'=>'npc-profile-clone-'.$profileId,'legend'=>'Create independent profile copy','hidden'=>['profile_id'=>$profileId],'fields'=>[['name','New profile name','text',$name.' Copy']]],$managementBasePath,$csrf);
        echo'<p class="management-note">The copy starts at revision 1 with the same roleplay and connector settings. Actor bindings and the private portrait file stay with the original.</p></details>';
        almsivi_ui_revision_actions('profile',$profileId,is_array($row['revisions']??null)?$row['revisions']:[],(int)($row['current_revision']??1),$managementBasePath,$csrf);
        echo'</div>';
        echo'</div></section></div>';
        echo'<div class="npc-modal-overlay" id="'.$modalKey.'-delete" data-npc-modal hidden><section class="npc-modal npc-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="'.$modalKey.'-delete-title"><header><h2 id="'.$modalKey.'-delete-title">Delete '.almsivi_ui_h($name).'</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body"><p>This permanently removes the current ALMSIVI profile and its OpenMW actor bindings.</p><form method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/profile-delete').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="profile_id" value="'.almsivi_ui_h($profileId).'"><button class="btn-base btn-danger" type="submit">Delete profile</button></form></div></section></div>';
    }
    echo'</div>';
}

/** Render the CHIM NPC-page composition while retaining ALMSIVI's typed, revisioned operations. */
function almsivi_ui_character_manager(array $rows,array $observedNpcs,array $profilePreferenceRows,array $installationOptions,array $voiceOptions,array $promptRows,array $llmRows,array $ttsRows,array $coreProfileRows,ProductRepository $productRepository,array $forms,string $managementBasePath,string $csrf):void
{
    $filtered=almsivi_ui_filter_profiles($rows);$totalRows=count($filtered);$perPage=12;$totalPages=max(1,(int)ceil($totalRows/$perPage));
    $page=max(1,min($totalPages,(int)($_GET['page']??1)));$pageRows=array_slice($filtered,($page-1)*$perPage,$perPage);
    $effectiveProfileSettings=[];
    foreach($pageRows as$profileRow){
        $profileId=(string)($profileRow['profile_id']??'');$installationId=(string)($profileRow['installation_id']??'');
        if($profileId!==''&&$installationId!=='')$effectiveProfileSettings[$profileId]=$productRepository->effectiveSettingsForProfile($installationId,$profileId);
    }
    $pageWindow=min(10,$totalPages);$pageStart=max(1,min($page-4,$totalPages-$pageWindow+1));$pageEnd=min($totalPages,$pageStart+$pageWindow-1);
    $query=(string)($_GET['q']??'');$profileFilter=(string)($_GET['profile']??'');$state=(string)($_GET['state']??'all');$initial=(string)($_GET['initial']??'');
    $coreProfileOptions=[];foreach($coreProfileRows as$coreProfileRow){$id=(string)($coreProfileRow['core_profile_id']??'');if($id!=='')$coreProfileOptions[$id]=(string)($coreProfileRow['label']??$id);}
    $profileOptions=[];foreach($rows as$row){$id=(string)($row['profile_id']??'');if($id!=='')$profileOptions[$id]=(string)($row['name']??$id);}
    $favoriteOnly=(string)($_GET['fav']??'')==='1';$lockedOnly=(string)($_GET['lock']??'')==='1';
    $hidden=function(array$omit=[])use($query,$profileFilter,$state,$initial,$favoriteOnly,$lockedOnly):void{foreach(['embed'=>($_GET['embed']??'')==='1'?'1':'','q'=>$query,'profile'=>$profileFilter,'state'=>$state==='all'?'':$state,'initial'=>$initial,'fav'=>$favoriteOnly?'1':'','lock'=>$lockedOnly?'1':'']as$name=>$value)if($value!==''&&!in_array($name,$omit,true))echo'<input type="hidden" name="'.almsivi_ui_h($name).'" value="'.almsivi_ui_h($value).'">';};
    echo'<section class="npc-manager-shell"><div class="pagination npc-toolbar"><div class="npc-toolbar-main"><div class="npc-toolbar-actions">';
    foreach([['npc-create-modal','+ Create NPC','',true],['npc-import-modal','&#128229; Import NPC','Import an ALMSIVI profile from JSON',true],['npc-relationships-modal','&#128279; Build Relationships '.almsivi_ui_feature_badge('config.npc.relationship-builder',true),'Bulk relationship text conversion is planned',false],['npc-switch-modal','&#128256; Mass Switch Profile','Switch OpenMW bindings',true],['npc-unlock-modal','&#128275; Unlock All Profiles','Unlock NPC profiles',true],['npc-delete-all-modal','&#10060; Delete All Profiles','Delete all unlocked NPC profiles',true]]as$index=>$button)
        echo'<button type="button" class="npc-toolbar-btn npc-toolbar-btn-uniform '.($index===5?'npc-toolbar-btn-danger':'npc-toolbar-btn-action').'"'.($button[3]?' data-npc-modal-target="'.$button[0].'"':' disabled aria-disabled="true"').' '.($button[2]!==''?' title="'.almsivi_ui_h($button[2]).'"':'').'>'.$button[1].'</button>';
    echo'</div><form class="npc-toolbar-tools" method="get" data-npc-filter-form>';$hidden(['q','profile']);
    echo'<label class="visually-hidden" for="npc_search">Search NPCs</label><input id="npc_search" type="text" name="q" maxlength="100" placeholder="Search..." aria-label="Search NPCs" value="'.almsivi_ui_h($query).'">';
    echo'<label class="visually-hidden" for="npc_profile_filter">Filter by Core Profile</label><select id="npc_profile_filter" name="profile" aria-label="Filter by Core Profile"><option value="">All Profiles</option>';foreach($coreProfileOptions as$id=>$label)echo'<option value="'.almsivi_ui_h($id).'"'.($profileFilter===$id?' selected':'').'>'.almsivi_ui_h($label).'</option>';echo'</select></form></div>';
    echo'<div class="npc-toolbar-subrow"><div class="npc-toolbar-pager">';
    echo'<button type="button" class="npc-letter-btn npc-page-link" data-page="1"'.($page<=1?' disabled aria-disabled="true"':'').'>First</button><button type="button" class="npc-letter-btn npc-page-link" data-page="'.max(1,$page-1).'"'.($page<=1?' disabled aria-disabled="true"':'').'>Prev</button>';
    for($p=$pageStart;$p<=$pageEnd;$p++)echo'<button type="button" class="npc-letter-btn npc-page-link'.($p===$page?' active':'').'" data-page="'.$p.'"'.($p===$page?' disabled aria-current="page"':'').'>'.$p.'</button>';
    echo'<button type="button" class="npc-letter-btn npc-page-link" data-page="'.min($totalPages,$page+1).'"'.($page>=$totalPages?' disabled aria-disabled="true"':'').'>Next</button><button type="button" class="npc-letter-btn npc-page-link" data-page="'.$totalPages.'"'.($page>=$totalPages?' disabled aria-disabled="true"':'').'>Last</button><div class="npc-page-indicator" title="Current page">'.$page.'/'.$totalPages.'</div></div></div>';
    echo'<div class="npc-toolbar-letter-row"><div class="npc-letter-filter" role="group" aria-label="Filter NPCs by first letter">';
    echo'<button class="npc-letter-btn'.($initial===''?' active':'').'" type="button" data-letter="">All</button>';foreach(range('A','Z')as$letter)echo'<button class="npc-letter-btn'.($initial===$letter?' active':'').'" type="button" data-letter="'.$letter.'">'.$letter.'</button>';echo'</div>';
    $preference=$profilePreferenceRows[0]??null;if(is_array($preference)){echo'<form class="npc-auto-lock-profile" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/profile-auto-lock').'" data-auto-lock-form><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><input type="hidden" name="installation_id" value="'.almsivi_ui_h($preference['installation_id']??'').'"><label title="When enabled, saving an NPC profile automatically locks it against automatic replacement."><input type="checkbox" name="enabled" value="1"'.(in_array($preference['auto_lock_on_edit']??true,[true,1,'1','t','true'],true)?' checked':'').'> Auto Lock Profiles on Edit</label></form>';}
    else echo'<label class="npc-auto-lock-profile unavailable" title="Available after the first installation is paired"><input type="checkbox" disabled> Auto Lock Profiles on Edit</label>';
    echo'<div class="npc-toolbar-summary"><div class="npc-filter-dropdown"><button type="button" class="npc-toolbar-btn npc-toolbar-btn-uniform npc-toolbar-btn-action npc-toolbar-filter-btn" data-filter-menu-toggle aria-expanded="false">&#9662; Filters</button><form class="npc-filter-menu" method="get" data-filter-menu hidden>';$hidden(['state','fav','lock']);
    echo'<label><input type="checkbox" name="fav" value="1"'.($favoriteOnly?' checked':'').'> &#11088; Favorites</label>';
    echo'<label><input type="checkbox" disabled aria-disabled="true"> &#9851;&#65039; Dynamic profile '.almsivi_ui_feature_badge('config.profiles.dynamic-profile',true).'</label>';
    echo'<label><input type="checkbox" disabled aria-disabled="true"> &#128195; Middle-term memory '.almsivi_ui_feature_badge('config.profiles.middle-term-memory',true).'</label>';
    echo'<label><input type="checkbox" name="lock" value="1"'.($lockedOnly?' checked':'').'> &#128274; Locked</label>';
    echo'<label><input type="checkbox" disabled aria-disabled="true"> &#128075; Auto Greeting '.almsivi_ui_feature_badge('config.npc.inherited-filter',true).'</label>';
    echo'<label><input type="checkbox" disabled aria-disabled="true"> &#127918; BGL: Auto Actions '.almsivi_ui_feature_badge('roleplay.background-life',true).'</label>';
    echo'<label><input type="checkbox" disabled aria-disabled="true"> &#128205; BGL: GPS track '.almsivi_ui_feature_badge('roleplay.background-life',true).'</label>';
    echo'<label><input type="checkbox" disabled aria-disabled="true"> &#129516; Created NPCs '.almsivi_ui_feature_badge('config.npc.identity',true).'</label>';
    echo'</form></div><div class="npc-total-pill" title="Total NPC profiles"><span class="npc-total-pill-icon">&#128101;</span><strong class="npc-total-pill-value">'.$totalRows.'</strong></div></div></div></div>';
    echo'<aside class="npc-history-pullback"><strong>History Pullback:</strong> ALMSIVI preserves every NPC revision. OpenMW save-time profile pullback is not available yet, so loading an older save does not silently replace server profiles.<br><span>Lock a profile (&#128274;) to protect it from automatic AI generation.</span> Use the revision controls in the edit modal to inspect and restore an earlier version.</aside>';
    echo'<div class="npc-profile-results">';almsivi_ui_chim_profile_cards($pageRows,$voiceOptions,$promptRows,$llmRows,$ttsRows,$coreProfileRows,$effectiveProfileSettings,$managementBasePath,$csrf);echo'</div>';

    $biographiesUrl=preg_replace('#/manage$#','/ui/core/npc_biographies.php',$managementBasePath)?:'/ALMSIVIserver/ui/core/npc_biographies.php';
    echo'<div class="npc-modal-overlay" id="npc-create-modal" data-npc-modal hidden><section class="npc-modal npc-editor-modal" role="dialog" aria-modal="true" aria-labelledby="npc-create-title"><header class="npc-editor-header"><h2 id="npc-create-title">Edit NPC</h2><div class="npc-modal-actions"><button type="submit" class="btn-save" form="management-form-profile-create">Save</button><button type="button" class="btn-cancel" disabled aria-disabled="true">Reset NPC '.almsivi_ui_feature_badge('config.npc.reset',true).'</button><button type="button" class="btn-cancel" disabled aria-disabled="true">View Diary '.almsivi_ui_feature_badge('config.npc.saved-only',true).'</button><button type="button" class="btn-cancel" disabled aria-disabled="true">View History '.almsivi_ui_feature_badge('config.npc.saved-only',true).'</button><button type="button" class="btn-cancel" disabled aria-disabled="true">AI Generate Profile '.almsivi_ui_feature_badge('config.npc.saved-only',true).'</button><button type="button" class="btn-cancel" data-npc-modal-close>Close</button></div></header><div class="npc-modal-tabs"><button type="button" class="active">&#9997;&#65039; Manual</button><a href="'.almsivi_ui_h($biographiesUrl).'">&#128218; NPC Biographies</a></div><div class="npc-modal-body">';
    almsivi_ui_npc_editor_form(['profile_id'=>'create','installation_id'=>(string)(array_key_first($installationOptions)??''),'name'=>'','content'=>[],'actor_identity'=>[]],$voiceOptions,$promptRows,$llmRows,$ttsRows,$coreProfileRows,$managementBasePath,$csrf,true,$installationOptions);
    echo'<details class="npc-observed-picker"><summary>Observed OpenMW NPCs <span class="npc-toolbar-count">'.count($observedNpcs).'</span></summary>';almsivi_ui_observed_npcs($observedNpcs,$managementBasePath,$csrf);echo'</details></div></section></div>';
    echo'<div class="npc-modal-overlay" id="npc-import-modal" data-npc-modal hidden><section class="npc-modal" role="dialog" aria-modal="true" aria-labelledby="npc-import-title"><header><h2 id="npc-import-title">Import NPC</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body">';almsivi_ui_management_form(['route'=>'profile-import','id'=>'npc-profile-import','legend'=>'Import ALMSIVI profile','fields'=>[['installation_id','Installation','select','',$installationOptions],['profile_json','Portable ALMSIVI profile JSON','jsonfile']]],$managementBasePath,$csrf);echo'</div></section></div>';
    $relationshipUrl=preg_replace('#/manage$#','/ui/relationship_logs.php',$managementBasePath)?:'/ALMSIVIserver/ui/relationship_logs.php';
    echo'<div class="npc-modal-overlay" id="npc-relationships-modal" data-npc-modal hidden><section class="npc-modal npc-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="npc-relationships-title"><header><h2 id="npc-relationships-title">&#128279; Build Relationships</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body"><p>Bulk conversion of relationship text is not available yet. Use Build with AI in an NPC editor to analyze its played conversation history.</p><p>Existing ALMSIVI relationship records remain available in the relationship log.</p><a class="btn-base btn-primary" href="'.almsivi_ui_h($relationshipUrl).'" target="_blank" rel="noopener">Open Relationship Logs</a><hr><h3>Generate NPC Profiles</h3><p>Generate AI profile revisions for unlocked NPCs already observed by ALMSIVI.</p>';
    almsivi_ui_management_form(['route'=>'profile-bulk-generate','id'=>'npc-bulk-generate','legend'=>'Generate unlocked NPC profiles','fields'=>[['installation_id','Installation','select','',$installationOptions],['confirm','Type Generate to confirm']]],$managementBasePath,$csrf);
    echo'</div></section></div>';
    echo'<div class="npc-modal-overlay" id="npc-switch-modal" data-npc-modal hidden><section class="npc-modal" role="dialog" aria-modal="true" aria-labelledby="npc-switch-title"><header><h2 id="npc-switch-title">&#128256; Mass Switch Profile</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body">';if(count($profileOptions)>=2){$ids=array_keys($profileOptions);almsivi_ui_management_form(['route'=>'profile-bulk-switch','id'=>'npc-bulk-switch','legend'=>'Switch bound NPC profiles','fields'=>[['installation_id','Installation','select','',$installationOptions],['source_profile_id','From profile','select',$ids[0],$profileOptions],['target_profile_id','To profile','select',$ids[1],$profileOptions],['include_locked','Include a locked source profile','checkbox','1',[],false],['confirm','Type Switch to confirm']]],$managementBasePath,$csrf);}else echo'<p>At least two NPC profiles are required before OpenMW bindings can be switched.</p>';echo'</div></section></div>';
    echo'<div class="npc-modal-overlay" id="npc-unlock-modal" data-npc-modal hidden><section class="npc-modal npc-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="npc-unlock-title"><header><h2 id="npc-unlock-title">&#128275; Unlock All NPC Profiles</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body"><p>Creates an unlocked revision for every locked NPC profile. Player and narrator profiles are excluded.</p>';almsivi_ui_management_form(['route'=>'profile-bulk-unlock','id'=>'npc-bulk-unlock','legend'=>'Unlock all NPC profiles','fields'=>[['installation_id','Installation','select','',$installationOptions],['confirm','Type Unlock to confirm']]],$managementBasePath,$csrf);echo'</div></section></div>';
    echo'<div class="npc-modal-overlay" id="npc-delete-all-modal" data-npc-modal hidden><section class="npc-modal npc-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="npc-delete-all-title"><header><h2 id="npc-delete-all-title">&#10060; Delete All Profiles</h2><button type="button" class="npc-modal-close" data-npc-modal-close aria-label="Close">&times;</button></header><div class="npc-modal-body"><p>Soft-deletes every unlocked NPC profile and clears its OpenMW actor bindings. Locked, player, and narrator profiles are preserved.</p>';almsivi_ui_management_form(['route'=>'profile-bulk-delete','id'=>'npc-bulk-delete','legend'=>'Delete all unlocked NPC profiles','fields'=>[['installation_id','Installation','select','',$installationOptions],['confirm','Type Delete to confirm']]],$managementBasePath,$csrf);echo'</div></section></div>';
    echo'</section>';
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
        $latest=is_array($row['latest_context']??null)?$row['latest_context']:[];
        if($latest!==[]){$playerState=is_array($latest['playerState']??null)?$latest['playerState']:[];
            echo'<section class="player-runtime-context"><header><h4>Latest OpenMW context</h4><span class="status-badge">'.almsivi_ui_h($latest['accepted_at']??'Synced').'</span></header><div class="player-context-grid">';
            foreach(['Level'=>$playerState['level']??'Unknown','Health'=>$playerState['health']??'Unknown','Magicka'=>$playerState['magicka']??'Unknown','Fatigue'=>$playerState['fatigue']??'Unknown']as$label=>$value)
                echo'<article><span>'.almsivi_ui_h($label).'</span><strong>'.almsivi_ui_h(is_array($value)?json_encode($value,JSON_UNESCAPED_SLASHES):$value).'</strong></article>';
            foreach(['Inventory'=>'inventory','Equipment'=>'equipment','Skills'=>'skills','Factions'=>'factions','Journal'=>'journal']as$label=>$key){$section=is_array($latest[$key]??null)?$latest[$key]:[];$items=is_array($section['items']??null)?$section['items']:[];echo'<article><span>'.almsivi_ui_h($label).'</span><strong>'.count($items).'</strong></article>';}
            echo'</div></section>';
        }else echo'<p class="management-note">Live stats, inventory, equipment, skills, factions, and journal will appear after the next accepted in-game turn.</p>';
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
                ['context_visibility','Include narrator context in prompts','checkbox','1',[],false,($content['context_visibility']??true)===true],
                ['welcome_events','Welcome narration','checkbox','1',[],false,($content['welcome_events']??false)===true],
                ['random_events','Random narration','checkbox','1',[],false,($content['random_events']??false)===true],
                ['quest_events','Quest narration','checkbox','1',[],false,($content['quest_events']??false)===true],
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

/** Explain the disabled autonomy surface while directing rechat to inherited profile settings. */
function almsivi_ui_schedule_cards(array $rows,array $sessionOptions,string $managementBasePath,string $csrf):void
{
    echo'<div class="feature-status feature-state-excluded"><h3>Automatic schedules <span class="status-badge">Excluded</span></h3>';
    echo'<p>Automatic greetings, boredom, combat barks, and timer-driven model requests cannot be enabled. Playback-gated rechat is inherited through Global &rarr; Core Profile &rarr; NPC settings and starts only after successful dialogue playback.</p></div>';
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
function almsivi_ui_provider_cards(array $rows, array $runtime, string $managementBasePath, string $csrf,bool $showRuntime=true): void
{
    $runtimeDriver=(string)($runtime['driver']??'mock');$runtimeModel=(string)($runtime['model']??'');
    $endpoint=(string)($runtime['endpoint']??'');
    if($showRuntime){echo '<article class="connector-card active"><header><div><span class="connector-kind">Server runtime</span><h3>Dialogue provider</h3></div><span class="status-badge connector-active">Configured</span></header><dl>';
        echo '<dt>Driver</dt><dd>'.almsivi_ui_h($runtimeDriver).'</dd><dt>Default model</dt><dd>'.almsivi_ui_h($runtimeModel===''?'Not set':$runtimeModel).'</dd>';
        echo '<dt>Endpoint</dt><dd><code>'.almsivi_ui_h($endpoint===''?'Local configuration':$endpoint).'</code></dd><dt>Credential</dt><dd><code>ALMSIVI_LLM_API_KEY</code> via API Keys or environment</dd></dl>';
        echo '<p>Configured slots inherit this vetted endpoint and credential while selecting only their own model. Test slots never call the network.</p><div class="connector-actions"><form class="connector-test" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/provider-runtime-test').'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base" type="submit">Test server runtime</button></form></div></article>';}
    if($rows===[]){if($showRuntime)echo '<p class="empty-state">No in-game model slots are configured yet. The server default remains available.</p>';return;}
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

/** Render LLM, TTS, and STT records with CHIM's shared connector master/detail layout. */
function almsivi_ui_connector_page(string $view,array $rows,array $forms,array $runtime,array $voiceOptions,string $pageTitle,string $description,string $managementBasePath,string $csrf):void
{
    $selectedId=(string)($_GET['selected']??'');$selected=null;
    foreach($rows as$row)if(hash_equals((string)($row['configuration_id']??''),$selectedId)){$selected=$row;break;}
    echo'<header class="configuration-page-header"><h1>'.almsivi_ui_h($pageTitle).'</h1><p>'.almsivi_ui_h($description).'</p></header>';
    echo'<div class="configuration-split-shell"><aside class="configuration-sidebar"><div class="configuration-sidebar-actions">';
    foreach($forms as$index=>$form){echo'<details class="configuration-action-panel"><summary class="btn-base '.($index===0?'btn-success':'btn-primary').'">'.($index===0?'New':'Import').'</summary>';almsivi_ui_management_form($form,$managementBasePath,$csrf);echo'</details>';}
    echo'</div><div class="configuration-record-list">';
    if($view==='llm'){echo'<a class="configuration-record'.($selectedId==='runtime'?' active':'').'" href="?'.almsivi_ui_h(http_build_query(['embed'=>($_GET['embed']??'')==='1'?'1':null,'selected'=>'runtime'])).'"><span><strong>Server runtime</strong><small>'.almsivi_ui_h($runtime['model']??'Default dialogue provider').'</small></span><span class="status-badge connector-active">Live</span></a>';}
    foreach($rows as$row){$id=(string)($row['configuration_id']??'');$content=is_array($row['content']??null)?$row['content']:[];$driver=(string)($content['driver']??strtoupper($view));
        $summary=$view==='llm'?(string)($content['model']??$driver):(string)($content['endpoint']??$driver);
        echo'<a class="configuration-record'.($selected!==null&&hash_equals($selectedId,$id)?' active':'').'" href="?'.almsivi_ui_h(http_build_query(['embed'=>($_GET['embed']??'')==='1'?'1':null,'selected'=>$id])).'"><span><strong>'.almsivi_ui_h($row['name']??strtoupper($view).' connector').'</strong><small>'.almsivi_ui_h($summary).'</small></span><span class="status-badge">'.almsivi_ui_h($driver).'</span></a>';}
    echo'</div></aside><section class="configuration-detail">';
    if($view==='llm'&&$selectedId==='runtime')almsivi_ui_provider_cards([],$runtime,$managementBasePath,$csrf,true);
    elseif($selected!==null&&$view==='llm')almsivi_ui_provider_cards([$selected],$runtime,$managementBasePath,$csrf,false);
    elseif($selected!==null)almsivi_ui_connector_cards([$selected],$voiceOptions,$view,$managementBasePath,$csrf);
    else echo'<div class="configuration-empty"><h2>No connector selected</h2><p>Select a connector from the list on the left to view and edit its settings.</p></div>';
    echo'</section></div>';
}

/** Render one strict revisioned settings form without exposing server endpoints or credentials. */
function almsivi_ui_global_settings_form(?array $row,array $installationOptions,string $managementBasePath,string $csrf):void
{
    $content=is_array($row['content']??null)?$row['content']:[];$behavior=is_array($content['behavior']??null)?$content['behavior']:[];
    $memory=is_array($content['memory']??null)?$content['memory']:[];$narrator=is_array($content['narrator']??null)?$content['narrator']:[];
    $presentation=is_array($content['presentation']??null)?$content['presentation']:[];$safety=is_array($content['safety']??null)?$content['safety']:[];
    $installation=(string)($row['installation_id']??'');$id=(string)($row['configuration_id']??($installation!==''?$installation:'new'));
    almsivi_ui_management_form(['route'=>'global-settings-save','id'=>'global-settings-'.$id,'legend'=>$row===null?'Create installation settings':'Save installation settings revision',
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
            ['tts_volume_boost','ALMSIVI TTS volume boost','number',(string)($presentation['tts_volume_boost']??3)],
            ['actions_enabled','Allow typed AI actions','checkbox','1',[],false,($safety['actions_enabled']??true)===true],
            ['allow_hostile','Allow hostile NPC activation','checkbox','1',[],false,($safety['allow_hostile']??false)===true],
            ['allow_creatures','Allow creature activation','checkbox','1',[],false,($safety['allow_creatures']??false)===true],
            ['change_reason','Change reason','text','management global settings'],
        ]],$managementBasePath,$csrf);
}

/** Render revisioned settings plus separately confirmed runtime schedules in the CHIM hierarchy. */
function almsivi_ui_global_settings_page(array $rows,array $installationRows,array $installationOptions,array $scheduleRows,array $forms,array $sessionOptions,string $managementBasePath,string $csrf):void
{
    echo'<div class="global-settings-shell"><header class="global-settings-title"><h1>Global Settings</h1><div class="global-settings-actions">';
    foreach($forms as$form){echo'<details class="configuration-action-panel"><summary class="btn-base btn-success">New Schedule</summary>';almsivi_ui_management_form($form,$managementBasePath,$csrf);echo'</details>';}
    echo'</div></header><nav class="global-settings-tabs" aria-label="Global setting groups"><span class="active">&#9881; Effective Settings</span><span>&#128172; Conversation Timing</span><span>&#127760; Installations</span></nav>';
    echo'<section class="global-settings-panel"><h2>Server-owned effective settings</h2><p>These revisioned values sync at session start. Local OpenMW safety settings can further restrict actions, hostile actors, and creatures; the server cannot loosen them.</p>';
    $configured=[];foreach($rows as$row){$configured[(string)($row['installation_id']??'')]=true;echo'<details class="global-settings-document" open><summary>'.almsivi_ui_h($row['display_name']??'Installation').' <span class="status-badge">Revision '.almsivi_ui_h($row['current_revision']??1).'</span></summary>';almsivi_ui_global_settings_form($row,$installationOptions,$managementBasePath,$csrf);echo'</details>';}
    foreach($installationOptions as$id=>$label)if(!isset($configured[$id])){echo'<details class="global-settings-document" open><summary>Create settings for '.almsivi_ui_h($label).'</summary>';almsivi_ui_global_settings_form(['installation_id'=>$id],$installationOptions,$managementBasePath,$csrf);echo'</details>';}
    if($installationOptions===[])echo'<p class="empty-state">No OpenMW installation has paired with ALMSIVIserver yet.</p>';echo'</section>';
    echo'<section class="global-settings-panel"><h2>Conversation Continuation</h2><p>Rechat is playback-gated profile behavior, not an idle schedule.</p>';
    almsivi_ui_schedule_cards($scheduleRows,$sessionOptions,$managementBasePath,$csrf);echo'</section>';
    echo'<section class="global-settings-panel"><h2>Registered Installations</h2>';almsivi_ui_table($installationRows);echo'</section></div>';
}

/** Render versioned prompts with CHIM's guidance, import/create tools, and searchable records. */
function almsivi_ui_prompts_page(array $rows,array $forms,string $description,string $managementBasePath,string $csrf):void
{
    $query=mb_strtolower(mb_substr(trim((string)($_GET['q']??'')),0,100));
    $filtered=$query===''?$rows:array_values(array_filter($rows,static function(array$row)use($query):bool{$content=is_array($row['content']??null)?$row['content']:[];return str_contains(mb_strtolower((string)($row['name']??'').' '.json_encode($content)), $query);}));
    echo'<header class="configuration-page-header"><h1>Prompts Manager</h1><p>'.almsivi_ui_h($description).'</p></header>';
    echo'<section class="prompts-guidance"><p><strong>Note:</strong> Prompt changes affect every conversation assigned to that configuration. Revisions and rollback remain available.</p><p><strong>ALMSIVI prompt:</strong> A versioned, installation-scoped instruction document. Profile routing determines which prompt is used.</p></section>';
    echo'<section class="widget widget-wide prompts-builtin"><div class="widget-header"><h3>Built-in Default</h3><span class="status-badge connector-active">Always available</span></div><div class="widget-content"><article class="connector-card"><header><div><span class="connector-kind">Read-only fallback</span><h3>ALMSIVI Core Conversation</h3></div></header><p>Used only when the active character has no explicit prompt and no applicable saved installation prompt exists.</p><pre class="configuration-preview">'.almsivi_ui_h(json_encode(['instruction'=>'Respond in character using only scoped context.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'</pre></article></div></section>';
    echo'<section class="prompts-tools">';foreach($forms as$index=>$form){echo'<details class="configuration-action-panel"><summary class="btn-base '.($index===0?'btn-success':'btn-primary').'">'.($index===0?'Create Prompt':'Import Prompt').'</summary><p>'.($index===0?'Create a new versioned dialogue prompt.':'Import a portable ALMSIVI prompt JSON document.').'</p>';almsivi_ui_management_form($form,$managementBasePath,$csrf);echo'</details>';};echo'</section>';
    echo'<section class="prompts-search"><h2>Search Prompts</h2><form method="get"><label for="prompt-search">Filter by prompt name or document text</label><div><input id="prompt-search" type="search" name="q" value="'.almsivi_ui_h($_GET['q']??'').'" placeholder="Search prompts...">'.((($_GET['embed']??'')==='1')?'<input type="hidden" name="embed" value="1">':'').'<button class="btn-base" type="submit">Search</button></div></form></section>';
    echo'<section class="widget widget-wide prompts-records"><div class="widget-header"><h3>Prompt Records</h3><span class="status-badge">'.count($filtered).' shown</span></div><div class="widget-content">';almsivi_ui_configuration_cards($filtered,'prompt',$managementBasePath,$csrf);echo'</div></section>';
}

/** Render immutable OpenMW actions and reducible policies in CHIM's Action Editor format. */
function almsivi_ui_actions_page(array $rows,array $policyRows,array $installationOptions,array $profileOptions,string $description,string $managementBasePath,string $csrf):void
{
    $enabledRows=array_values(array_filter($rows,static fn(array$row):bool=>in_array($row['enabled']??false,[true,1,'1','t','true'],true)));
    $query=mb_strtolower(mb_substr(trim((string)($_GET['q']??'')),0,100));
    $filtered=$query===''?$rows:array_values(array_filter($rows,static fn(array$row):bool=>str_contains(mb_strtolower(implode(' ',[(string)($row['action_name']??''),(string)($row['client_capability']??''),(string)($row['description']??'')])), $query)));
    echo'<header class="configuration-page-header"><h1>Action Editor</h1><p>'.almsivi_ui_h($description).'</p></header>';
    echo'<div class="action-overview-grid"><section><h2>Action Summary</h2><div class="action-stat-grid"><article><span>Total Actions</span><strong>'.count($rows).'</strong></article><article><span>Enabled</span><strong class="success">'.count($enabledRows).'</strong></article><article><span>Policies</span><strong>'.count($policyRows).'</strong></article></div></section><section><h2>How It Works</h2><p>ALMSIVI exposes only the immutable action catalog negotiated with OpenMW. Policies can reduce allowed actions and maximum tier; they cannot add new capabilities or bypass confirmation.</p></section></div>';
    echo'<form class="action-filter-bar" method="get"><label for="action-filter-q">Search actions</label><input id="action-filter-q" type="search" name="q" value="'.almsivi_ui_h($_GET['q']??'').'" placeholder="Search actions..."><span>'.count($filtered).' of '.count($rows).' shown</span>'.((($_GET['embed']??'')==='1')?'<input type="hidden" name="embed" value="1">':'').'<button class="btn-base" type="submit">Search</button><a class="btn-base" href="?'.(($_GET['embed']??'')==='1'?'embed=1':'').'">Reset Filters</a></form>';
    echo'<section class="widget widget-wide action-catalog-table"><div class="widget-content">';almsivi_ui_table($filtered);echo'</div></section>';
    echo'<details class="management-create-panel"'.($policyRows===[]?' open':'').'><summary>Create action policy with controls</summary>';almsivi_ui_action_policy_form(null,$rows,$installationOptions,$profileOptions,$managementBasePath,$csrf);echo'</details>';
    echo'<section class="widget widget-wide"><div class="widget-header"><h3>Action Policies</h3></div><div class="widget-content">';almsivi_ui_action_policy_cards($policyRows,$rows,$installationOptions,$profileOptions,$managementBasePath,$csrf);echo'</div></section>';
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
function almsivi_ui_action_policy_form(?array $row,array $actions,array $installationOptions,array $profileOptions,string $managementBasePath,string $csrf):void
{
    $create=$row===null;$content=$create?[]:(is_array($row['content']??null)?$row['content']:[]);$token=$create?'create':(string)($row['configuration_id']??'policy');
    $explicit=is_array($content['actions']??null)&&!array_is_list($content['actions'])?$content['actions']:null;
    $allow=is_array($content['allowed_actions']??null)&&array_is_list($content['allowed_actions'])?$content['allowed_actions']:null;
    $deny=is_array($content['denied_actions']??null)&&array_is_list($content['denied_actions'])?$content['denied_actions']:[];
    echo '<form class="management-form action-policy-form" method="post" action="'.almsivi_ui_h($managementBasePath.'/forms/'.($create?'action-policy-controls-create':'action-policy-controls-revise')).'"><fieldset><legend>'.($create?'Create action policy with controls':'Edit action permissions').'</legend>';
    if($create){echo '<label for="action-policy-installation-'.$token.'">Installation</label><select id="action-policy-installation-'.$token.'" name="installation_id" required>';foreach($installationOptions as$id=>$label)echo '<option value="'.almsivi_ui_h($id).'">'.almsivi_ui_h($label).'</option>';echo '</select><label for="action-policy-profile-'.$token.'">Profile scope</label><select id="action-policy-profile-'.$token.'" name="profile_id"><option value="">Installation-wide</option>';foreach($profileOptions as$id=>$label)echo '<option value="'.almsivi_ui_h($id).'">'.almsivi_ui_h($label).'</option>';echo '</select><small>Profile policies take precedence over installation-wide policies.</small><label for="action-policy-name-'.$token.'">Policy name</label><input id="action-policy-name-'.$token.'" name="name" required>';}
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
function almsivi_ui_action_policy_cards(array $rows,array $actions,array $installationOptions,array $profileOptions,string $managementBasePath,string $csrf):void
{
    if($rows===[]){echo '<p class="empty-state">No action policies are configured. The immutable server catalog and negotiated client capabilities remain the safety boundary.</p>';return;}
    echo '<div class="connector-grid">';foreach($rows as$row){$id=(string)($row['configuration_id']??'');$content=is_array($row['content']??null)?$row['content']:[];
        echo '<article class="connector-card"><header><div><span class="connector-kind">Action policy</span><h3>'.almsivi_ui_h($row['name']??'').'</h3></div><span class="status-badge">Revision '.almsivi_ui_h($row['current_revision']??'').'</span></header><dl><dt>Scope</dt><dd>'.almsivi_ui_h($row['profile_name']??'Installation-wide').'</dd><dt>Enabled</dt><dd>'.(($content['enabled']??true)?'Yes':'No').'</dd><dt>Maximum tier</dt><dd>'.almsivi_ui_h($content['max_tier']??3).'</dd></dl><details open><summary>Action permissions</summary>';
        almsivi_ui_action_policy_form($row,$actions,$installationOptions,$profileOptions,$managementBasePath,$csrf);echo '</details><details><summary>Advanced policy JSON</summary>';
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

$BODY_CLASS='configuration-resource view-'.preg_replace('/[^a-z0-9_-]+/','-',strtolower($view)).($embedded?' embedded-page':'');
$additionalStylesheets=['almsivi-pages.css?v='.(string)filemtime(dirname(__DIR__).'/css/almsivi-pages.css')];
if($view==='characters')$additionalStylesheets[]='herika-npcs.css?v='.(string)filemtime(dirname(__DIR__).'/css/herika-npcs.css');
$includeManagementStyles=false;
include $uiRootDir . '/tmpl/head.html';
if (!$embedded) include $uiRootDir . '/tmpl/navbar.php';
?>
<main class="management-page">
    <?php if (isset($_GET['status']) && $_GET['status'] === 'saved'): ?><p class="page-status" role="status"><?php echo $view === 'characters' ? 'NPC profile change saved.' : 'Changes saved.'; ?></p><?php endif; ?>
    <?php if (isset($_GET['status']) && $_GET['status'] === 'tested'): ?><p class="page-status" role="status">Connector test passed<?php echo isset($_GET['detail']) ? ': ' . almsivi_ui_h($_GET['detail']) : '.'; ?></p><?php endif; ?>
    <?php if (isset($_GET['error'])): ?><p class="page-error" role="alert"><?php echo almsivi_ui_h($_GET['error']); ?></p><?php endif; ?>
    <?php if ($view === 'characters'): ?>
    <?php almsivi_ui_character_manager($rows,$observedNpcs,$profilePreferenceRows,$installationOptions,$voiceOptions,$promptRoutingRows,$llmRoutingRows,$ttsRoutingRows,$coreProfileRows,$productRepository,$forms,$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'profiles'): ?>
    <?php almsivi_ui_profiles_page($rows,$forms,$voiceOptions,$promptRoutingRows,$llmRoutingRows,$ttsRoutingRows,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'player'): ?>
    <?php almsivi_ui_player_page($rows,$forms,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'narrator'): ?>
    <?php almsivi_ui_narrator_page($rows,$forms,$voiceOptions,$ttsRoutingRows,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php elseif (in_array($view, ['llm','tts','stt'], true)): ?>
    <?php almsivi_ui_connector_page($view,$rows,$forms,is_array($config['provider']??null)?$config['provider']:[],$voiceOptions,$pageTitle,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'global_settings'): ?>
    <?php almsivi_ui_global_settings_page($rows,$installationRows,$installationOptions,$scheduleRows,$forms,$sessionOptions,$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'prompts'): ?>
    <?php almsivi_ui_prompts_page($rows,$forms,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php elseif ($view === 'actions'): ?>
    <?php almsivi_ui_actions_page($rows,$policyRows,$installationOptions,$actionProfileOptions,$descriptions[$view],$managementBasePath,$csrf); ?>
    <?php else: ?>
    <header class="configuration-page-header"><h1><?php echo almsivi_ui_h($pageTitle); ?></h1><p><?php echo almsivi_ui_h($descriptions[$view] ?? 'ALMSIVIserver management page.'); ?></p></header>
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
    <section class="widget widget-wide"><div class="widget-header"><h3>Conversation Continuation</h3></div><div class="widget-content"><?php almsivi_ui_schedule_cards($scheduleRows,$sessionOptions,$managementBasePath,$csrf); ?></div></section>
    <?php endif; ?>
    <?php if ($view === 'database_manager'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Installation Configuration Backups</h3></div><div class="widget-content"><p>Includes profiles, prompts, model slots, TTS/STT presets, action policies, connector selections, and profile safety preferences. API keys, portrait files, voice files, memories, relationships, narratives, and runtime database records are excluded.</p><?php almsivi_ui_configuration_backup_cards($backupRows,$installationOptions,$managementBasePath); ?></div></section>
    <?php endif; ?>
    <?php if ($view === 'actions'): ?>
    <details class="management-create-panel"<?php echo $policyRows === [] ? ' open' : ''; ?>><summary>Create action policy with controls</summary><?php almsivi_ui_action_policy_form(null,$rows,$installationOptions,$actionProfileOptions,$managementBasePath,$csrf); ?></details>
    <?php endif; ?>
    <?php foreach ($forms as $form): ?>
        <details class="management-create-panel"<?php echo $rows === [] ? ' open' : ''; ?>>
            <summary><?php echo almsivi_ui_h($form['legend']); ?></summary>
            <?php almsivi_ui_management_form($form, $managementBasePath, $csrf); ?>
        </details>
    <?php endforeach; ?>
    <?php if ($view === 'actions'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Action Policies</h3></div><div class="widget-content"><p>Policies can only reduce the immutable server catalog and the capabilities negotiated with OpenMW. Profile-specific policies take precedence over installation policies; equal scopes use policy name order.</p><?php almsivi_ui_action_policy_cards($policyRows,$rows,$installationOptions,$actionProfileOptions,$managementBasePath,$csrf); ?></div></section>
    <?php endif; ?>
    <?php if ($view === 'autonomy'): ?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Narrator and Diary Records</h3></div><div class="widget-content"><?php almsivi_ui_narrative_cards($narrativeRows,$managementBasePath,$csrf); ?></div></section>
    <?php endif; ?>
    <?php endif; ?>
</main>
<script src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo almsivi_ui_h($uiAssetVersion); ?>" defer></script>
<?php include $uiRootDir . '/tmpl/footer.html'; ?>
