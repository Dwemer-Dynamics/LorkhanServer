<?php
declare(strict_types=1);

use LorkhanServer\Application\ActionPolicyValidator;
use LorkhanServer\Application\CancellationToken;
use LorkhanServer\Application\FirstPartyJobHandlerFactory;
use LorkhanServer\Application\DeterministicClock;
use LorkhanServer\Application\MockProvider;
use LorkhanServer\Application\MockSpeechProvider;
use LorkhanServer\Application\MockSpeechToTextProvider;
use LorkhanServer\Application\MorrowindVoiceCatalog;
use LorkhanServer\Application\Provider;
use LorkhanServer\Application\PromptAssembler;
use LorkhanServer\Application\ProductService;
use LorkhanServer\Application\RechatCoordinator;
use LorkhanServer\Application\Worker;
use LorkhanServer\Http\Request;
use LorkhanServer\Http\Router;
use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\ActionCatalogRepository;
use LorkhanServer\Infrastructure\BiographyCatalogImporter;
use LorkhanServer\Infrastructure\DefaultConnectorProvisioner;
use LorkhanServer\Infrastructure\EventLogRepository;
use LorkhanServer\Infrastructure\JobRepository;
use LorkhanServer\Infrastructure\MediaStore;
use LorkhanServer\Infrastructure\MigrationRunner;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\Repository;
use LorkhanServer\Infrastructure\TtsPronunciationRepository;
use LorkhanServer\Protocol\Validator;
use LorkhanServer\Security\PairingToken;
use LorkhanServer\Security\RequestMac;

require dirname(__DIR__) . '/lib/Autoload.php';

$dsn = getenv('LORKHAN_TEST_DSN') ?: '';
if ($dsn === '') { fwrite(STDERR, "LORKHAN_TEST_DSN is required\n"); exit(2); }
$db = Connection::open(['database_dsn' => $dsn, 'database_user' => getenv('LORKHAN_TEST_DB_USER') ?: '',
    'database_password' => getenv('LORKHAN_TEST_DB_PASSWORD') ?: '']);
$repo = new Repository($db, 256, new ActionCatalogRepository($db), new ActionPolicyValidator());
(new MigrationRunner($db, dirname(__DIR__) . '/data/migrations'))->up();
$mediaPath = sys_get_temp_dir() . '/lorkhan-media-' . bin2hex(random_bytes(8));
$mediaStore = new MediaStore($mediaPath, 33_554_432, 67_108_864);
$token = PairingToken::generate();
$tokenHash = PairingToken::hash($token);$macKey=hex2bin($tokenHash);$installationId='00000000-0000-4000-8000-000000000001';
$attempts = new ProviderAttemptRepository($db);
$products = new ProductRepository($db);
$repo->ensureInstallation($installationId,$tokenHash,$macKey);
// Quickstart connector ownership is stable, revision fenced, and participates in an outer routing rollback.
$localSetup=['server_type'=>'lm_studio','scope'=>'conversations','endpoint'=>'http://127.0.0.1:1234/v1/chat/completions','model'=>' fixture ','credential'=>'custom'];
try{
    $products->transaction(function()use($products,$installationId,$localSetup,$db):void{
        $first=$products->saveQuickstartLocalLlm($installationId,$localSetup,0,'2026-09-08T12:00:00Z');
        $id=$first['configuration_id'];
        $db->exec('SAVEPOINT local_setup_down');
        try{$db->exec((string)file_get_contents(dirname(__DIR__).'/data/migrations/096_quickstart_local_llm.down.sql'));throw new RuntimeException('populated Local LLM downgrade accepted');}
        catch(PDOException $error){
            $db->exec('ROLLBACK TO SAVEPOINT local_setup_down');
            if(!str_contains($error->getMessage(),'Cannot remove Local LLM setup'))throw$error;
        }

        if($first['connector']['content']['model']!=='fixture'||$first['connector']['current_revision']!==1)throw new RuntimeException('local setup creation failed');
        $next=$localSetup;unset($next['credential']);$next['server_type']='ollama';$next['scope']='all';$next['disable_streaming']=true;
        $second=$products->saveQuickstartLocalLlm($installationId,$next,1,'2026-09-08T12:01:00Z');
        if($second['configuration_id']!==$id||$second['connector']['current_revision']!==2
            ||$second['connector']['content']['credential']!=='custom'||$second['connector']['content']['options']['stream']!==false
            ||$second['scope']!=='all'||$second['connector']['name']!=='Local LLM - Ollama')throw new RuntimeException('local setup revision failed');
        try{$products->saveQuickstartLocalLlm($installationId,$localSetup,1,'2026-09-08T12:02:00Z');throw new RuntimeException('stale local setup accepted');}
        catch(RuntimeException $error){if($error->getMessage()!=='revision_conflict')throw$error;}
        if($products->quickstartLocalLlmForInstallation($installationId)['connector']['current_revision']!==2)throw new RuntimeException('stale local setup changed state');
        throw new RuntimeException('rollback-local-setup-fixture');
    });
}catch(RuntimeException $error){if($error->getMessage()!=='rollback-local-setup-fixture')throw$error;}
if($products->coreCreationPreset($installationId)!==null)throw new RuntimeException('routing rollback retained creation defaults');
if($products->quickstartLocalLlmForInstallation($installationId)!==null)throw new RuntimeException('outer rollback retained local setup');
// Local setup changes default/Narrator routes as one transaction, preserving unrelated profiles.
try{
    $products->transaction(function()use($products,$installationId,$localSetup):void{
        $now='2026-09-08T12:03:00Z';
        $base=['schema'=>'lorkhan.core-profile.v1','prompt'=>'Preserve this Core prompt.','routing'=>[], 'settings_overrides'=>[]];
        $default=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Default fixture','default_npc'=>true,'content'=>$base],$now);
        $narratorCore=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Narrator fixture','content'=>$base],$now);
        $other=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Unrelated fixture','content'=>$base],$now);
        $products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Narrator','actor_identity'=>['kind'=>'narrator'],'core_profile_id'=>$narratorCore['core_profile_id'],'content'=>[]],$now);
        $plan=$products->quickstartLocalRoutingPlan($installationId);
        if(count($plan['core_profiles'])!==2)throw new RuntimeException('local setup target selection failed');
        $saved=$products->applyQuickstartLocalLlm($installationId,$localSetup,$plan['fingerprint'],$now);
        foreach([$default,$narratorCore] as $target){
            $content=$products->getRevisioned('core_profile',$target['core_profile_id'])['content'];
            foreach(['llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id','llm_experimental_configuration_id'] as $field){
                if(($content['routing'][$field]??null)!==$saved['configuration_id'])throw new RuntimeException('default dialogue route missing');
            }
            if(isset($content['routing']['diary_generation_configuration_id']))throw new RuntimeException('dialogue setup changed background routes');
        }
        if($products->getRevisioned('core_profile',$other['core_profile_id'])['current_revision']!==1)throw new RuntimeException('local setup changed unrelated Core');
        if($products->globalSettingsForInstallation($installationId)!==null||$products->memorySummaryPolicyForInstallation($installationId)!==null)throw new RuntimeException('dialogue setup changed system policies');
        try{$products->applyQuickstartLocalLlm($installationId,$localSetup,$plan['fingerprint'],$now);throw new RuntimeException('stale local routing accepted');}
        catch(RuntimeException $error){if($error->getMessage()!=='revision_conflict')throw$error;}
        if($products->quickstartLocalLlmForInstallation($installationId)['connector']['current_revision']!==1)throw new RuntimeException('stale routing revised connector');
        $all=$localSetup;$all['scope']='all';
        $products->applyQuickstartLocalLlm($installationId,$all,$saved['routing_plan']['fingerprint'],$now);
        $global=$products->globalSettingsForInstallation($installationId)['content'];
        foreach(\LorkhanServer\Application\SettingsCatalog::systemRoutingFields() as $field){
            if($global['system_routing'][$field]!==$saved['configuration_id'])throw new RuntimeException('background system route missing');
        }
        $summary=$products->memorySummaryPolicyForInstallation($installationId)['content'];
        if($summary['enabled']!==false||$summary['provider_configuration_id']!==$saved['configuration_id'])throw new RuntimeException('summary route or enabled state changed incorrectly');
        if($products->coreSettingsSnapshot($installationId)['default']!=\LorkhanServer\Application\CoreProfilePreset::capture([]))
            throw new RuntimeException('unconfigured creation defaults copied the NPC default profile');
        // Setup presets affect every Core, not just the profiles used for default routing.
        $presetPlan=$products->quickstartLocalRoutingPlan($installationId);$beforePreset=[];
        foreach($presetPlan['preset_profiles'] as $target)$beforePreset[$target['core_profile_id']]=$products->getRevisioned('core_profile',$target['core_profile_id']);
        $afterPreset=$products->applyInstallationCorePreset($installationId,'builtin:local_llm',$presetPlan['fingerprint'],$now);
        foreach($beforePreset as $id=>$before){
            $after=$products->getRevisioned('core_profile',$id);
            if((int)$after['current_revision']!==(int)$before['current_revision']+1||$after['content']['settings_overrides']['response']['max_words']!==60
                ||$after['content']['settings_overrides']['memory']['recent_turn_limit']!==20)throw new RuntimeException('Quickstart did not apply the preset to every Core');
            if($after['content']['prompt']!==$before['content']['prompt'])throw new RuntimeException('Quickstart preset replaced Core prompt ownership');
            foreach($before['content']['routing']??[] as $field=>$value)if(str_ends_with($field,'configuration_id')&&($after['content']['routing'][$field]??null)!==$value)throw new RuntimeException('Quickstart preset changed a connector binding');
        }
        $snapshot=$products->coreSettingsSnapshot($installationId);
        if(count($snapshot['items'])!==count($beforePreset)||($snapshot['default']['settings_overrides']['response']['max_words']??null)!==60)
            throw new RuntimeException('Core snapshot missed default or existing profiles');
        // A profile absent from the snapshot receives its saved default settings on Apply.
        unset($snapshot['items'][$other['core_profile_id']]);
        $snapshot['default']['settings_overrides']['response']['max_words']=41;
        $snapshot['default']['settings_overrides']['context']['item_blacklist']=['fixture_item'];
        $afterPreset=$products->applyInstallationCorePreset($installationId,$snapshot,$afterPreset['fingerprint'],$now);
        if($products->getRevisioned('core_profile',$other['core_profile_id'])['content']['settings_overrides']['response']['max_words']!==41)
            throw new RuntimeException('Core snapshot fallback did not apply to an uncaptured profile');
        foreach($snapshot['items'] as $id=>$captured){
            $after=$products->getRevisioned('core_profile',$id);
            if(\LorkhanServer\Application\CoreProfilePreset::capture($after['content'])!==$captured)
                throw new RuntimeException('Captured Core settings did not survive application');
        }
        // A later edit to a non-default Core also invalidates the loaded Setup snapshot.
        $edited=$products->getRevisioned('core_profile',$other['core_profile_id']);
        $products->revise('core_profile',$other['core_profile_id'],$edited['content'],'Concurrent editor',$now,(int)$edited['current_revision']);
        $currentPlan=$products->quickstartLocalRoutingPlan($installationId);
        try{$products->applyInstallationCorePreset($installationId,'builtin:default',$afterPreset['fingerprint'],$now);throw new RuntimeException('stale non-default Core preset accepted');}
        catch(RuntimeException $error){if($error->getMessage()!=='revision_conflict')throw$error;}
        if($products->quickstartLocalRoutingPlan($installationId)!==$currentPlan)throw new RuntimeException('rejected preset changed profiles');
        $minimal=['schema'=>'lorkhan.core-profile.v1','prompt'=>'Owned prompt','routing'=>[],'settings_overrides'=>[]];
        $created=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Future defaults','content'=>$minimal],$now);
        if(($created['content']['settings_overrides']['response']['max_words']??null)!==41
            ||($created['content']['settings_overrides']['context']['item_blacklist']??null)!==['fixture_item'])
            throw new RuntimeException('new Core did not inherit stored creation defaults');
        $explicit=$minimal;$explicit['settings_overrides']=['response'=>['max_words'=>0],'behavior'=>['rechat'=>false],'context'=>['item_blacklist'=>[]]];
        $created=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Explicit choices','content'=>$explicit],$now);
        foreach($explicit['settings_overrides'] as $section=>$fields)foreach($fields as $key=>$value)
            if($created['content']['settings_overrides'][$section][$key]!==$value)throw new RuntimeException('creation defaults replaced an explicit value');
        $preserved=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Imported settings','content'=>$minimal],$now,false);
        if($preserved['content']!=$minimal)throw new RuntimeException('explicit clone/import inherited creation defaults');
        if($products->withCoreCreationDefaults('00000000-0000-4000-8000-000000000002',$minimal)!==$minimal)
            throw new RuntimeException('creation defaults crossed installation scope');
        $defaultCore=$products->defaultCoreProfileForInstallation($installationId);
        $changed=$defaultCore['content'];$changed['settings_overrides']['response']['max_words']=99;
        $products->revise('core_profile',$defaultCore['core_profile_id'],$changed,'Independent NPC edit',$now);
        if($products->coreSettingsSnapshot($installationId)['default']['settings_overrides']['response']['max_words']!==41)
            throw new RuntimeException('NPC default edit changed creation defaults');
        throw new RuntimeException('rollback-local-routing-fixture');
    });
}catch(RuntimeException $error){if($error->getMessage()!=='rollback-local-routing-fixture')throw$error;}
if($products->coreCreationPreset($installationId)!==null)throw new RuntimeException('routing rollback retained creation defaults');
if($products->quickstartLocalLlmForInstallation($installationId)!==null)throw new RuntimeException('routing rollback retained managed connector');
$presetStore=new \LorkhanServer\Infrastructure\ManagementRepository($db);
$presetPayload=\LorkhanServer\Application\CoreProfilePreset::capture(['settings_overrides'=>['response'=>['max_words'=>60]]]);
$presetId=$presetStore->saveCoreProfilePreset($installationId,'Custom companion',$presetPayload);
if($presetStore->coreProfilePreset($installationId,$presetId)!=$presetPayload)throw new RuntimeException('Core preset round-trip failed');
$presetPayload['settings_overrides']['response']['max_words']=80;
$presetStore->saveCoreProfilePreset($installationId,'Custom companion',$presetPayload,$presetId,1);
try{$presetStore->saveCoreProfilePreset($installationId,'Custom companion',$presetPayload,$presetId,1);throw new RuntimeException('stale Core preset overwrite accepted');}
catch(RuntimeException $error){if($error->getMessage()!=='revision_conflict')throw $error;}
try{$presetStore->saveCoreProfilePreset($installationId,'custom COMPANION',$presetPayload);throw new RuntimeException('duplicate Core preset name accepted');}
catch(InvalidArgumentException $error){if($error->getMessage()!=='preset_name_exists')throw $error;}
try{$presetStore->coreProfilePreset('00000000-0000-4000-8000-000000000002',$presetId);throw new RuntimeException('cross-installation Core preset read accepted');}
catch(RuntimeException $error){if($error->getMessage()!=='not_found')throw $error;}
if((int)$presetStore->coreProfilePresets($installationId)[0]['revision']!==2)throw new RuntimeException('Core preset catalogue revision mismatch');
$db->prepare('DELETE FROM lorkhan_internal.core_profile_presets WHERE preset_id=:id')->execute(['id'=>$presetId]);
(new DefaultConnectorProvisioner($db))->provision($installationId);
$morrowindVoices=MorrowindVoiceCatalog::bundled();
$rechatCoordinator = new RechatCoordinator($repo,$products);
$router = new Router($repo, new Validator(), new MockProvider(), $tokenHash, rateLimitRequests: 1000,
    mediaStore: $mediaStore, speechProvider: new MockSpeechProvider(), providerAttempts: $attempts,
    products:$products,promptAssembler:new PromptAssembler(),
    morrowindVoices:$morrowindVoices,rechatCoordinator:$rechatCoordinator);
$base = '/LorkhanServer/api/v1';
$jsonAuth = ['Content-Type' => 'application/json; charset=utf-8'];
$fixture = fn(string $name): array => json_decode(file_get_contents(dirname(__DIR__) . '/protocol/fixtures/v1/valid/' . $name . '.json'), true, 64, JSON_THROW_ON_ERROR)['instance'];
$call = function (Router $target, string $method, string $path, array $headers = [], array $query = [], array|string|null $body = null) use($macKey,$installationId): array {
    $encoded = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : ($body ?? '');
    $request=new Request($method,$path,$headers,$query,$encoded);
    if(!isset($headers['Authorization'])&&$path!=='/LorkhanServer/api/v1/health'){$timestamp=gmdate('Y-m-d\TH:i:s\Z');$nonce=bin2hex(random_bytes(16));$digest=hash('sha256',$encoded);$headers+=['X-LORKHAN-Auth'=>RequestMac::ALGORITHM,'X-LORKHAN-Installation-Id'=>$installationId,'X-LORKHAN-Timestamp'=>$timestamp,'X-LORKHAN-Nonce'=>$nonce,'X-LORKHAN-Content-SHA256'=>$digest,'X-LORKHAN-Signature'=>RequestMac::sign($macKey,$request,$installationId,$timestamp,$nonce,(string)($headers['Content-Type']??''),$digest)];$request=new Request($method,$path,$headers,$query,$encoded);}
    $response = $target->dispatch($request);
    $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
    $capture = getenv('LORKHAN_RESPONSE_CAPTURE') ?: '';
    if ($capture !== '') file_put_contents($capture, $response->body . "\n", FILE_APPEND | LOCK_EX);
    return [$response->status, $decoded];
};
$assert = function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$headers = fn(string $key): array => $jsonAuth + ['Idempotency-Key' => $key];
$newUuid = function (int $n): string { return sprintf('10000000-0000-4000-8000-%012d', $n); };
$pronunciations=new TtsPronunciationRepository($db);
$pronunciationRows=$pronunciations->rows();
$builtinPronunciations=array_values(array_filter($pronunciationRows,static fn(array $row):bool=>in_array($row['is_builtin']??false,[true,1,'1','t'],true)));
$assert(count($builtinPronunciations)>=40&&$pronunciations->apply('The Nerevarine returned to Vvardenfell under the Tribunal.')
    ==='The Nerevareen returned to Vardenfell under the Trybyoonal.','joined Morrowind pronunciations were not seeded or applied');
$assert($pronunciations->apply('Azura called Nerevar.')==='Azura called Nerevar.',
    'Azura or Nerevar should not override the TTS engine pronunciation');
$assert(array_reduce($builtinPronunciations,static fn(bool $clean,array $row):bool=>$clean&&!str_contains((string)($row['spoken_text']??''),'-'),true),
    'built-in pronunciations must not contain pause-inducing hyphens');
$customPronunciation=$pronunciations->saveCustom(null,'TestTerm','Spoken Test','','Dark Elf','sixth-house',true);
$assert($pronunciations->apply('TestTerm')==='TestTerm','scoped pronunciation leaked into the global dictionary');
$assert($pronunciations->apply('TestTerm',['pronunciation_scope'=>['race'=>'Dark Elf','oghma_tags'=>['sixth-house']]])==='Spoken Test',
    'matching race and Oghma scopes did not apply the custom pronunciation');
$pronunciations->setEnabled($customPronunciation,false);
$assert($pronunciations->apply('TestTerm',['pronunciation_scope'=>['race'=>'Dark Elf','oghma_tags'=>['sixth-house']]])==='TestTerm',
    'disabled pronunciation remained active');
$pronunciations->deleteEntry($customPronunciation);
$builtinTestId=(int)$db->query("INSERT INTO core_tts_pronunciation(source_text,spoken_text,npc_names,is_builtin) VALUES ('BuiltinTestTerm','Before','Jiub',true) RETURNING id")->fetchColumn();
$pronunciations->saveBuiltin($builtinTestId,'After',true);
$assert($pronunciations->apply('BuiltinTestTerm',['pronunciation_scope'=>['npc_name'=>'Jiub']])==='After'
    &&$pronunciations->apply('BuiltinTestTerm')==='BuiltinTestTerm','edited built-in pronunciation lost its scope');
$pronunciations->setEnabled($builtinTestId,false);
$assert($pronunciations->apply('BuiltinTestTerm',['pronunciation_scope'=>['npc_name'=>'Jiub']])==='BuiltinTestTerm','disabled edited built-in remained active');
$pronunciations->deleteEntry($builtinTestId);
$assert($db->query('SELECT count(*) FROM core_tts_pronunciation WHERE id='.$builtinTestId)->fetchColumn()===0,'built-in pronunciation was not deleted');
$defaultInstallationId='00000000-0000-4000-8000-000000000099';
$defaultVoicePath=sys_get_temp_dir().'/lorkhan-default-voices-'.bin2hex(random_bytes(8));
mkdir($defaultVoicePath,0700,true);file_put_contents($defaultVoicePath.'/mw_dark_elf_male.wav','test');
$defaultProvisioner=new DefaultConnectorProvisioner($db,$defaultVoicePath);
$defaultRepository=new Repository($db,256,null,null,$defaultProvisioner);
$defaultRepository->ensureInstallation($defaultInstallationId,hash('sha256','lorkhan-default-installation'));
$defaultRows=$db->prepare("SELECT c.name,r.content FROM configuration_sets c JOIN configuration_revisions r "
    ."ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.installation_id=:installation "
    ."AND c.deleted_at IS NULL ORDER BY c.kind,c.name");
$defaultRows->execute(['installation'=>$defaultInstallationId]);$defaultConfigurations=$defaultRows->fetchAll();
$defaultModels=[];$defaultPromptFormat=null;foreach($defaultConfigurations as$configuration){$content=json_decode((string)$configuration['content'],true,16,JSON_THROW_ON_ERROR);
    if($configuration['name']==='Roleplay Dialogue')$defaultPromptFormat=$content['format']??null;
    if(($content['driver']??null)==='configured')$defaultModels[(string)$configuration['name']]=$content['model']??null;}
$assert($defaultModels===[
    'DeepSeek Chat V3.2'=>'deepseek/deepseek-v3.2','GLM 4.7'=>'z-ai/glm-4.7','GLM 5'=>'z-ai/glm-5',
    'Gemini 2.5 Flash Lite'=>'google/gemini-2.5-flash-lite'],
    'new installation did not receive the pinned CHIM LLM connector set: '.json_encode($defaultModels));
$defaultCore=(new ProductRepository($db))->defaultCoreProfileForInstallation($defaultInstallationId);
$defaultRouting=$defaultCore['content']['routing']??[];
$defaultGlobal=(new ProductRepository($db))->globalSettingsForInstallation($defaultInstallationId);
$defaultSystemRouting=$defaultGlobal['content']['system_routing']??[];
$assert(count(array_filter($defaultRouting,static fn(mixed$value,string$key):bool=>str_starts_with($key,'llm_')
    &&str_ends_with($key,'_configuration_id'),ARRAY_FILTER_USE_BOTH))===4
    &&!array_key_exists('llm_fallback_configuration_id',$defaultRouting)
    &&isset($defaultRouting['tts_configuration_id'],$defaultRouting['prompt_configuration_id'])
    &&!isset($defaultRouting['oghma_configuration_id'],$defaultRouting['profile_generation_configuration_id'],$defaultRouting['relationship_configuration_id'])
    &&($defaultSystemRouting['oghma_configuration_id']??null)===($defaultRouting['llm_fast_configuration_id']??null)
    &&($defaultSystemRouting['profile_generation_configuration_id']??null)===($defaultRouting['llm_fast_configuration_id']??null)
    &&($defaultSystemRouting['relationship_configuration_id']??null)===($defaultRouting['llm_fast_configuration_id']??null),
    'new installation routing was not split between Core Profiles and Global Settings');
$defaultPrompt=$db->prepare("SELECT p.prompt_key,p.default_prompt,p.custom_prompt,p.description FROM prompts p WHERE p.installation_id=:installation AND p.prompt_key='roleplay_dialogue'");
$defaultPrompt->execute(['installation'=>$defaultInstallationId]);$defaultPromptRow=$defaultPrompt->fetch();
$assert($defaultPromptRow&&$defaultPromptRow['custom_prompt']===null
    &&$defaultPromptFormat===null
    &&str_contains((string)$defaultPromptRow['default_prompt'],'selected Morrowind actor')
    &&str_contains((string)$defaultPromptRow['description'],'CHIM-style roleplay prompt'),
    'new installation did not receive the editable CHIM-style default roleplay prompt');
$excludedCount=$db->prepare("SELECT count(*) FROM configuration_sets WHERE installation_id=:installation AND kind='stt_provider' AND deleted_at IS NULL");
$excludedCount->execute(['installation'=>$defaultInstallationId]);
$voiceCount=$db->prepare('SELECT count(*) FROM speech_connector_voices v JOIN configuration_sets c ON c.configuration_id=v.configuration_id WHERE c.installation_id=:installation');
$voiceCount->execute(['installation'=>$defaultInstallationId]);
$assert((int)$excludedCount->fetchColumn()===1&&(int)$voiceCount->fetchColumn()===1
    &&$products->connectorForInstallation($defaultInstallationId,'stt_provider')!==null,
    'global STT default or available Morrowind voices were not provisioned');
$beforeRevision=(int)$defaultCore['current_revision'];$beforeConfigurations=count($defaultConfigurations);
$defaultProvisioner->provision($defaultInstallationId);
$defaultRows->execute(['installation'=>$defaultInstallationId]);
$defaultCore=(new ProductRepository($db))->defaultCoreProfileForInstallation($defaultInstallationId);
$assert(count($defaultRows->fetchAll())===$beforeConfigurations&&(int)$defaultCore['current_revision']===$beforeRevision,
    'default connector provisioning was not idempotent');
unlink($defaultVoicePath.'/mw_dark_elf_male.wav');rmdir($defaultVoicePath);
$runWorker = function (array $types, ?Provider $provider = null, ?\LorkhanServer\Application\SpeechToTextProvider $sttProvider=null,
    ?\LorkhanServer\Application\TranslationProvider $translationProvider=null,
    ?\LorkhanServer\Application\SpeechProvider $speechProvider=null) use ($db,$mediaStore): array {
    return (new Worker(new JobRepository($db), FirstPartyJobHandlerFactory::registry($db,$mediaStore,
        provider:$provider,speechProvider:$speechProvider??($provider === null ? null : new MockSpeechProvider()),providerTimeoutMs:1000,
        sttProvider:$sttProvider,translationProvider:$translationProvider),
        'integration-worker',5,10,100,0,10,$types,
        static fn(int $microseconds):mixed=>null))->run();
};
$runTurnWorker = function(Provider $provider,?\LorkhanServer\Application\TranslationProvider $translationProvider=null,
    ?\LorkhanServer\Application\SpeechProvider $speechProvider=null) use($runWorker):array {
    $turnStats=$runWorker(['turn.process'],$provider,null,$translationProvider,$speechProvider);
    $runWorker(['speech.synthesize'],$provider,null,$translationProvider,$speechProvider);
    return $turnStats;
};

[$status, $health] = $call($router, 'GET', $base . '/health');
$assert($status === 200 && $health['schema'] === 'lorkhan.health.v1', 'health failed');
$unsignedResponse=$router->dispatch(new Request('GET',$base.'/events',[],['session_id'=>$newUuid(1),'generation'=>'1'],''));
$assert($unsignedResponse->status===401,'event auth missing');
[$status] = $call($router, 'GET', $base . '/events', [], ['token' => 'hostile']);
$assert($status === 400, 'query credential accepted');
[$status] = $call($router, 'POST', $base . '/sessions', $jsonAuth, [], '{}');
$assert($status === 422, 'empty JSON object not decoded as object');
[$status] = $call($router, 'POST', $base . '/sessions', $jsonAuth, [], '{bad');
$assert($status === 422, 'malformed JSON accepted');

$limited = new Router($repo, new Validator(), new MockProvider(), $tokenHash, rateLimitRequests: 1);
$limitedQuery = ['session_id' => $newUuid(2), 'generation' => '1', 'after' => '0'];
$call($limited, 'GET', $base . '/events', [], $limitedQuery);
[$status, $rateError] = $call($limited, 'GET', $base . '/events', [], $limitedQuery);
$assert($status === 429 && $rateError['retry_after_ms'] === 1000, 'rate retry_after_ms missing');

$session = $fixture('session-init');
$session['runtime']['capabilities'][]='debug.commands.v1';
$session['runtime']['capabilities'][]='speech.browser.v1';
$session['runtime']['capabilities'][]='action.conversation.end';
[$status] = $call($router, 'POST', $base . '/sessions', $jsonAuth, [], $session);
$assert($status === 422, 'missing session idempotency key accepted');
[$status] = $call($router, 'POST', $base . '/sessions', $headers($newUuid(3)), [], $session);
$assert($status === 422, 'incoherent session idempotency key accepted');
[$status, $accepted] = $call($router, 'POST', $base . '/sessions', $headers($session['message_id']), [], $session);
$assert($status === 201 && $accepted['generation'] === 7
    && $accepted['capabilities'] === ['dialogue.text', 'speech.say', 'speech.listen', 'controls.session', 'debug.commands.v1', 'speech.browser.v1', 'action.inspect.report', 'action.ai.follow',
        'action.ai.stop', 'action.conversation.end', 'action.ai.approach', 'action.ai.wait', 'action.ai.travel', 'action.ai.escort', 'action.ai.face', 'action.ai.wander',
        'action.combat.start', 'action.combat.stop', 'action.animation.play', 'action.item.equip', 'action.item.unequip', 'action.item.use',
        'action.inventory.inspect']
    &&$accepted['config_revision']==='global-settings-r1'
    &&($accepted['client_settings']['schema']??null)==='lorkhan.client-settings.v1'
    &&($accepted['client_settings']['behavior']['rechat']??null)===false, 'session create failed');
$sessionId = $accepted['session_id'];
$browserCapableSessions=array_values(array_filter($products->debugCommandSessions(),static fn(array $row):bool=>$row['session_id']===$sessionId));
$assert(count($browserCapableSessions)===1&&$browserCapableSessions[0]['browser_speech_supported']===true,
    'session negotiation stripped browser speech support before management discovery');
$playerProfile=$products->playerProfileForInstallation($installationId);
$assert(($playerProfile['actor_identity']['kind']??null)==='player'&&($playerProfile['revision']??null)===1,
    'session start did not materialize the installation player profile');
[$status, $duplicateSession] = $call($router, 'POST', $base . '/sessions', $headers($session['message_id']), [], $session);
$assert($status === 201 && $duplicateSession == $accepted, 'session duplicate failed');
$conflict = $session; $conflict['profile_id'] = $newUuid(4);
[$status, $body] = $call($router, 'POST', $base . '/sessions', $headers($session['message_id']), [], $conflict);
$assert($status === 409 && $body['code'] === 'duplicate_conflict', 'session duplicate conflict failed');
$staleSession = $session; $staleSession['message_id'] = $newUuid(5); $staleSession['generation'] = 6;
[$status, $body] = $call($router, 'POST', $base . '/sessions', $headers($staleSession['message_id']), [], $staleSession);
$assert($status === 409 && $body['code'] === 'stale_generation', 'non-monotonic generation accepted');

$now=gmdate('Y-m-d\TH:i:s\Z');
$modelSlot=$products->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'In-game mock',
    'content'=>['driver'=>'mock','model'=>'deterministic-mock-v1','mock_prefix'=>'[slot] ']],$now);
$profileModelSlot=$products->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Profile-routed mock',
    'content'=>['driver'=>'mock','model'=>'deterministic-profile-v1','mock_prefix'=>'[profile] ']],$now);
$fastModelSlot=$products->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Profile fast mock',
    'content'=>['driver'=>'mock','model'=>'deterministic-fast-v1','mock_prefix'=>'[fast] ']],$now);
$fallbackModelSlot=$products->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Profile fallback mock',
    'content'=>['driver'=>'mock','model'=>'deterministic-fallback-v1','mock_prefix'=>'[fallback] ']],$now);
$actorCoreProfile=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Integration Actor',
    'default_npc'=>false,'slot'=>null,'content'=>['schema'=>'lorkhan.core-profile.v1','prompt'=>'',
        'routing'=>['llm_configuration_id'=>$profileModelSlot['configuration_id'],
            'llm_fast_configuration_id'=>$modelSlot['configuration_id'],'llm_powerful_configuration_id'=>''],
        'settings_overrides'=>[]]],$now);
$actorProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Fargoth scholar',
    'actor_identity'=>['record_id'=>'fargoth'],'core_profile_id'=>$actorCoreProfile['core_profile_id'],
    'content'=>['persona'=>'A cautious Dwemer scholar.']],$now);
$profileTtsPreset=$products->createRevisioned('tts_provider',['installation_id'=>$installationId,'name'=>'Profile-routed speech',
    'content'=>['driver'=>'pockettts','endpoint'=>'http://127.0.0.1:8086','model'=>'tts-1','voice'=>'default',
        'language'=>'en','timeout_ms'=>30000,'options'=>['fallback_female'=>'fallback_female_voice']]],$now);
$products->replaceConnectorVoiceCatalog($profileTtsPreset['configuration_id'],[[
    'id'=>'mw_wood_elf_male','display'=>'Morrowind Wood Elf Male','language'=>'en','status'=>'runtime_ready','custom'=>true],
    ['id'=>'fargoth','display'=>'Fargoth (Morrowind Wood Elf Male)','language'=>'en','status'=>'runtime_ready','custom'=>true]],$now);
$products->selectConnector($installationId,'tts_provider',$profileTtsPreset['configuration_id'],$now);
$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Bosmer male biography template',
    'actor_identity'=>['kind'=>'template'],'content'=>['race'=>'Wood Elf','gender'=>'Male',
        'biography'=>'A Bosmer raised beneath the great graht-oaks.','personality'=>'Observant and quick-witted.']],$now);
$biographyService=new ProductService($products,new DeterministicClock(new \DateTimeImmutable($now)));
$portableBiographyRow=['content_file'=>'HTTP Portability.esp','record_id'=>'portable_biography_npc','name'=>'Portable Biography NPC',
    'core'=>'A careful guide with strong local boundaries.','biography'=>'Portable biography v1.','appearance'=>'Travel-worn clothes.',
    'personality'=>'Patient and observant.','relationships'=>'{"Player":{"aff":25}}','occupation'=>'Guide',
    'skills'=>'Local geography.','speech_style'=>'Direct and calm.','goals'=>'Help respectful travellers.',
    'oghma_tags'=>'Balmora, common','voice_id'=>'mw_dark_elf_female','gender'=>'Female','race'=>'Dark Elf'];
$portableSaved=$biographyService->importBiographyTemplates($installationId,[$portableBiographyRow]);
$portableTemplateId=$portableSaved[0]['profile_id'];$portableTemplate=$products->getRevisioned('profile',$portableTemplateId);
$portableIdentity=json_decode((string)$portableTemplate['actor_identity'],true,16,JSON_THROW_ON_ERROR);
$assert($portableSaved[0]['created']===true&&(int)$portableTemplate['current_revision']===1
    &&($portableIdentity['kind']??null)==='template'
    &&($portableTemplate['content']['oghma_knowledge_tags']??null)==='Balmora',
    'portable biography import did not create one typed OpenMW template');
$portableContent=$portableTemplate['content'];$portableContent['notes']='Preserve this nonportable field.';
$portableContent['routing']=['llm_configuration_id'=>$profileModelSlot['configuration_id']];
$products->revise('profile',$portableTemplateId,$portableContent,'manual template settings',$now);
$portableBiographyRow['biography']='Portable biography v2.';$portableBiographyRow['voice_id']='';
$portableSaved=$biographyService->importBiographyTemplates($installationId,[$portableBiographyRow]);
$portableTemplate=$products->getRevisioned('profile',$portableTemplateId);
$portableExport=array_values(array_filter($products->customBiographyTemplates($installationId),
    static fn(array$row):bool=>$row['record_id']==='portable_biography_npc'));
$assert($portableSaved[0]['created']===false&&$portableSaved[0]['revision']===3
    &&($portableTemplate['content']['biography']??null)==='Portable biography v2.'
    &&($portableTemplate['content']['notes']??null)==='Preserve this nonportable field.'
    &&($portableTemplate['content']['routing']['llm_configuration_id']??null)===$profileModelSlot['configuration_id']
    &&!isset($portableTemplate['content']['voice'])&&count($portableExport)===1
    &&$portableExport[0]['oghma_tags']==='Balmora',
    'biography re-import did not revise the same template while preserving nonportable settings');
$portableTarget=['kind'=>'npc','record_id'=>'portable_biography_npc','refnum'=>['index'=>99,'content_file'=>0],
    'content_file'=>'HTTP Portability.esp','cell'=>['kind'=>'interior','name'=>'Balmora'],
    'display_name'=>'Portable Biography NPC'];
$portableVoice=$morrowindVoices->resolve($portableTarget,['targetState'=>['identity'=>['race'=>'Dark Elf','gender'=>'Female']]]);
$portableProfileId=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$portableTarget]],$portableVoice,$now);
$portableProfile=$products->getRevisioned('profile',$portableProfileId);
$portableActorIdentity=json_decode((string)$portableProfile['actor_identity'],true,16,JSON_THROW_ON_ERROR);
$assert($portableProfileId!==$portableTemplateId&&($portableProfile['content']['biography']??null)==='Portable biography v2.'
    &&($portableActorIdentity['kind']??null)==='npc',
    'first-seen OpenMW actor did not inherit the exact imported biography template');
// Reset reusable biographies without deleting instantiated NPCs or another installation's templates.
$db->beginTransaction();
$biographyOtherInstallation=$newUuid(998878);$repo->ensureInstallation($biographyOtherInstallation,$tokenHash,$macKey);
$otherBiography=$biographyService->importBiographyTemplates($biographyOtherInstallation,[$portableBiographyRow])[0];
$globalBiography=array_replace($portableBiographyRow,['scope'=>'global','content_file'=>'','name'=>'Global Reset Fixture','record_id'=>'global_reset_fixture','voice_id'=>'global_voice']);
$biographyService->importBiographyTemplates($installationId,[$globalBiography]);
$combinedExport=$products->customBiographyTemplates($installationId);
$globalExport=array_values(array_filter($combinedExport,static fn(array$row):bool=>$row['name']==='Global Reset Fixture'));
$assert(count($globalExport)===1&&$globalExport[0]['scope']==='global'&&$globalExport[0]['voice_id']==='global_voice',
    'full biography export omitted a global custom override or its voice');
$factoryCount=(int)$db->query('SELECT count(*) FROM public.bio_templates')->fetchColumn();
$products->resetBiographyTemplates($installationId,$now);
$assert($products->customBiographyTemplates($installationId)===[]
    &&(int)$db->query('SELECT count(*) FROM public.bio_templates')->fetchColumn()===$factoryCount
    &&$products->getRevisioned('profile',$portableProfileId)['content']===$portableProfile['content']
    &&(int)$db->query("SELECT count(*) FROM profiles WHERE name='Bosmer male biography template' AND deleted_at IS NULL")->fetchColumn()===1
    &&$products->getRevisioned('profile',$otherBiography['profile_id'])['deleted_at']===null,
    'biography reset changed factory data, active NPC content or another installation');
$biographyService->importBiographyTemplates($installationId,$combinedExport);
$assert(count($products->customBiographyTemplates($installationId))===count($combinedExport),
    'complete biography export could not restore both scopes after reset');
$db->rollBack();
$resetCandidate=$portableProfile;$resetCandidate['content']['biography']='Manual biography';
$resetCandidate['content']['voice']=['id'=>'PreservedVoice','language'=>'en'];
$resetCandidate['content']['notes']='Preserved notes';
$resetContent=$products->biographyResetContent($resetCandidate);
$assert($resetContent['biography']==='Portable biography v2.'
    &&$resetContent['voice']===$resetCandidate['content']['voice']&&$resetContent['notes']==='Preserved notes',
    'biography reset must reload only filled template fields and preserve voice and unrelated data');
$products->deleteRevisioned('profile',$portableTemplateId,$now);
$factoryDirectory=sys_get_temp_dir().'/lorkhan-biography-factory-'.bin2hex(random_bytes(4));
mkdir($factoryDirectory,0700,true);$factoryBiographies=$factoryDirectory.'/biographies.json';$factoryManifest=$factoryDirectory.'/manifest.json';
$factoryRow=['npc_name'=>'factory_bosmer','oghma_knowledge_tags'=>'','core'=>'Factory Bosmer keeps a careful watch over Seyda Neen.',
    'npc_static_bio'=>'Factory Bosmer has lived beside the Bitter Coast for many years. He knows the paths around Seyda Neen.',
    'appearance'=>'A slight Wood Elf with weathered travel clothes and an alert posture.','personality'=>'Watchful, patient, and quietly helpful to respectful travelers.',
    'relationships'=>'{}','occupation'=>'A local pathfinder who guides travelers around the Bitter Coast.',
    'skills'=>'* Navigating the Bitter Coast\n* Identifying safe wilderness paths\n* Watching for nearby danger',
    'speechstyle'=>'He speaks in brief, practical observations with a cautious tone.',
    'goals'=>'* Keep travelers safe\n* Protect the paths near Seyda Neen\n* Avoid needless conflict',
    'voiceid'=>null,'gender'=>'male','race'=>'Wood Elf','refid'=>'factory_bosmer'];
file_put_contents($factoryBiographies,json_encode([$factoryRow],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
file_put_contents($factoryManifest,json_encode(['format'=>'lorkhan.morrowind-biography-preflight.v1','selected_count'=>1,
    'completed_count'=>1,'failed_count'=>0,'model'=>'fixture/model','builder_sha256'=>hash('sha256','fixture builder'),
    'official_content_sha256'=>['Morrowind.esm'=>str_repeat('a',64),'Tribunal.esm'=>str_repeat('b',64),'Bloodmoon.esm'=>str_repeat('c',64),'TR_Mainland.esm'=>str_repeat('d',64)],
    'items'=>[['record_id'=>'factory_bosmer','display_name'=>'Factory Bosmer','content_file'=>'TR_Mainland.esm','generation_status'=>'complete']]],
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
(new BiographyCatalogImporter($db))->apply($factoryBiographies,$factoryManifest,'integration-fixture-v1');
$factoryTarget=['kind'=>'npc','record_id'=>'factory_bosmer','refnum'=>['index'=>100,'content_file'=>0],
    'content_file'=>'TR_Mainland.esm','cell'=>['kind'=>'exterior','grid_x'=>-2,'grid_y'=>-9],'display_name'=>'Factory Bosmer'];
$factoryVoice=$morrowindVoices->resolve($factoryTarget,['targetState'=>['identity'=>['race'=>'Wood Elf','gender'=>'Male','is_male'=>true]]]);
$factoryProfileId=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$factoryTarget]],$factoryVoice,$now);
$factoryProfile=$products->getRevisioned('profile',$factoryProfileId);
$assert(($factoryProfile['content']['biography']??null)===$factoryRow['npc_static_bio']
    &&($factoryProfile['content']['speech_style']??null)===$factoryRow['speechstyle'],
    'exact mod-source identity did not seed a typed profile from the active CHIM biography catalog');
unlink($factoryBiographies);unlink($factoryManifest);rmdir($factoryDirectory);
$automaticTarget=['kind'=>'npc','record_id'=>'automatic_bosmer','refnum'=>['index'=>101,'content_file'=>0],
    'content_file'=>'Morrowind.esm','cell'=>['kind'=>'exterior','grid_x'=>-2,'grid_y'=>-9],'display_name'=>'Automatic Bosmer'];
$automaticContext=['targetState'=>['identity'=>['race'=>'Wood Elf','class'=>'Commoner','gender'=>'Male','is_male'=>true],
    'factions'=>[['id'=>'fighters guild','rank'=>1,'reputation'=>4],['id'=>'former guild','rank'=>-1,'reputation'=>0]]]];
$automaticVoice=$morrowindVoices->resolve($automaticTarget,$automaticContext);
$assert(($automaticVoice['id']??null)==='mw_wood_elf_male','Morrowind voice catalog did not resolve Wood Elf male');
$dagothVoice=$morrowindVoices->resolve(['kind'=>'creature','record_id'=>'dagoth_ur_1'],[]);
$assert(($dagothVoice['id']??null)==='dagoth_ur_1'&&($dagothVoice['source']??null)==='actor_catalog',
    'Morrowind voice catalog did not resolve Dagoth Ur from his unique vanilla sample');
$assert(($morrowindVoices->resolve(['kind'=>'actor','record_id'=>'fargoth'],
    ['targetState'=>['identity'=>['race'=>'','gender'=>'']]])['id']??null)==='mw_wood_elf_male',
    'known legacy NPC fallback was hidden by empty profile metadata');
$exactFargothVoice=$products->preferExactProviderActorVoice($installationId,['kind'=>'npc','record_id'=>'fargoth'],
    $morrowindVoices->resolve(['kind'=>'actor','record_id'=>'fargoth'],['targetState'=>['identity'=>['race'=>'','gender'=>'']]]));
$assert(($exactFargothVoice['id']??null)==='fargoth'&&($exactFargothVoice['source']??null)==='actor_provider_catalog'
    &&($exactFargothVoice['race']??null)==='wood elf'&&($exactFargothVoice['gender']??null)==='Male',
    'active provider exact actor voice did not override the race and gender fallback');
$ruleCoreLow=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Rule low priority',
    'content'=>['schema'=>'lorkhan.core-profile.v1','prompt'=>'','routing'=>[],'settings_overrides'=>[]]],$now);
$ruleCoreHigh=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Rule high priority',
    'content'=>['schema'=>'lorkhan.core-profile.v1','prompt'=>'','routing'=>[],'settings_overrides'=>[]]],$now);
$emptyRuleMatch=array_fill_keys(['names','races','classes','genders','factions','content_files'],[]);
$lowRule=$products->saveProfileAssignmentRule(['installation_id'=>$installationId,'description'=>'Automatic Bosmer by name',
    'core_profile_id'=>$ruleCoreLow['core_profile_id'],'priority'=>10,'enabled'=>true,
    'match'=>array_replace($emptyRuleMatch,['names'=>['automatic bosmer']])],$now);
$highRule=$products->saveProfileAssignmentRule(['installation_id'=>$installationId,'description'=>'Exact OpenMW actor data',
    'core_profile_id'=>$ruleCoreHigh['core_profile_id'],'priority'=>20,'enabled'=>true,
    'match'=>array_replace($emptyRuleMatch,['races'=>['wood elf'],'classes'=>['COMMONER'],'genders'=>['male'],
        'factions'=>['Fighters Guild'],'content_files'=>['morrowind.esm']])],$now);
$rulePlan=$products->profileAssignmentRulesPlan($installationId);
$assert(array_column($rulePlan['rules'],'rule_id')===[$highRule['rule_id'],$lowRule['rule_id']]
    &&in_array('fighters guild',array_map('strtolower',$rulePlan['options']['factions']),true),
    'assignment rule plan did not retain priority or observed faction values');
$automaticProfileId=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$automaticTarget,'context'=>$automaticContext]],$automaticVoice,$now);
$automaticProfile=$products->getRevisioned('profile',$automaticProfileId);
$assert(($automaticProfile['content']['voice']['id']??null)==='mw_wood_elf_male'
    &&($automaticProfile['content']['voice']['source']??null)==='morrowind_race_gender_catalog'
    &&($automaticProfile['content']['biography']??null)==='A Bosmer raised beneath the great graht-oaks.'
    &&($automaticProfile['content']['personality']??null)==='Observant and quick-witted.'
    &&str_contains((string)($automaticProfile['content']['oghma_knowledge_tags']??''),'bitter_coast')
    &&($automaticProfile['content']['oghma_locality']['source']??null)==='current_cell_fallback'
    &&($automaticProfile['core_profile_id']??null)===$ruleCoreHigh['core_profile_id'],
    'first-seen NPC profile did not retain its voice, biography template, and deterministic home locality');
foreach(['inworld','cartesia','pockettts','omnivoice','chatterbox','xtts-fastapi','xtts','zonos_gradio',
    'openai','11labs','azure','convai','coqui-ai','deepgram','gcp','kokoro','koboldcpp','melotts','mimic3','piper-tts','stylettsv2','xvasynth']as$voiceDriver){
    $sampleDriver=in_array($voiceDriver,['inworld','cartesia','pockettts','omnivoice','chatterbox','xtts-fastapi','xtts','zonos_gradio'],true);
    $voiceContext=$products->speechContext($installationId,$session['playthrough_id'],$automaticTarget,
        ['configuration_id'=>\LorkhanServer\Infrastructure\Uuid::v4(),'content'=>['driver'=>$voiceDriver,'options'=>['fallback_male'=>'provider_male']]]);
    $assert(($voiceContext['voice']??'')===($sampleDriver?'mw_wood_elf_male':'provider_male'),
        $voiceDriver.' must preserve sample voices only when the adapter can consume them');
}
$globalFallbacks=new \LorkhanServer\Infrastructure\TtsFallbackRepository($db);
$defaultFallbacks=$globalFallbacks->matrix();
$assert(count($defaultFallbacks)===10&&array_sum(array_map('count',$defaultFallbacks))===20
    &&$globalFallbacks->voice('Dunmer','Male')==='mw_dark_elf_male',
    'global fallback table must contain all twenty ordinary Morrowind race/gender samples');
$unprofiledActor=['kind'=>'actor','record_id'=>'fallback_unprofiled','content_file'=>'Morrowind.esm',
    'refnum'=>['index'=>991234,'content_file'=>0],'race'=>'Bosmer','gender'=>'male'];
$assert(in_array('Global fallback: dark_elf male',$products->voiceReferenceIndex()['mw_dark_elf_male']??[],true),
    'global fallback samples must be protected by the voice-library reference guard');
$customFallbacks=$defaultFallbacks;$customFallbacks['wood_elf']['male']='global_bosmer_voice';
$globalFallbacks->save($customFallbacks);
foreach(['inworld','cartesia','pockettts','omnivoice','chatterbox','xtts-fastapi','xtts','zonos_gradio']as$driver){
    $fallbackConnector=['configuration_id'=>\LorkhanServer\Infrastructure\Uuid::v4(),'content'=>['driver'=>$driver,
        'options'=>['race_fallbacks'=>['wood_elf'=>['male'=>'obsolete_connector_voice']],'fallback_male'=>'last_resort']]];
    $fallbackContext=$products->speechContext($installationId,$session['playthrough_id'],$unprofiledActor,$fallbackConnector);
    $assert(($fallbackContext['voice']??'')==='global_bosmer_voice',$driver.' did not use the shared global fallback');
    $explicitContext=$products->speechContext($installationId,$session['playthrough_id'],$automaticTarget,$fallbackConnector);
    $assert(($explicitContext['voice']??'')==='mw_wood_elf_male','global fallback overrode an assigned NPC voice');
}
$customFallbacks['wood_elf']['male']='';$globalFallbacks->save($customFallbacks);
$assert(($products->speechContext($installationId,$session['playthrough_id'],$unprofiledActor,$fallbackConnector)['voice']??'')==='last_resort',
    'a deliberately blank global fallback must skip the race and gender combination');
try{$globalFallbacks->save(['wood_elf'=>['male'=>'invalid_partial']]);$assert(false,'partial global matrix accepted');}
catch(\InvalidArgumentException){$assert($globalFallbacks->matrix()===$customFallbacks,'failed global save partially changed the table');}
$globalFallbacks->save($defaultFallbacks);
$products->saveProfileAssignmentRule(['installation_id'=>$installationId,'rule_id'=>$highRule['rule_id'],
    'description'=>'Exact OpenMW actor data retargeted','core_profile_id'=>$ruleCoreLow['core_profile_id'],'priority'=>20,'enabled'=>true,
    'match'=>array_replace($emptyRuleMatch,['races'=>['wood elf'],'classes'=>['COMMONER'],'genders'=>['male'],
        'factions'=>['Fighters Guild'],'content_files'=>['morrowind.esm']])],$now);
$existingAutomatic=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$automaticTarget,'context'=>$automaticContext]],$automaticVoice,$now);
$assert($existingAutomatic===$automaticProfileId
    &&($products->getRevisioned('profile',$existingAutomatic)['core_profile_id']??null)===$ruleCoreHigh['core_profile_id'],
    'editing a rule reassigned an existing NPC profile');
$tieTarget=$automaticTarget;$tieTarget['record_id']='rule_tie_npc';$tieTarget['refnum']['index']=106;$tieTarget['display_name']='Rule Tie NPC';
$tieCoreOld=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Rule older tie winner',
    'content'=>['schema'=>'lorkhan.core-profile.v1','prompt'=>'','routing'=>[],'settings_overrides'=>[]]],$now);
$tieCoreNew=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Rule newer tie loser',
    'content'=>['schema'=>'lorkhan.core-profile.v1','prompt'=>'','routing'=>[],'settings_overrides'=>[]]],$now);
$tieOld=$products->saveProfileAssignmentRule(['installation_id'=>$installationId,'description'=>'Older tie',
    'core_profile_id'=>$tieCoreOld['core_profile_id'],'priority'=>50,'enabled'=>true,
    'match'=>array_replace($emptyRuleMatch,['names'=>['Rule Tie NPC']])],$now);
$tieNew=$products->saveProfileAssignmentRule(['installation_id'=>$installationId,'description'=>'Newer tie',
    'core_profile_id'=>$tieCoreNew['core_profile_id'],'priority'=>50,'enabled'=>true,
    'match'=>array_replace($emptyRuleMatch,['names'=>['Rule Tie NPC']])],$now);
$tieProfileId=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$tieTarget,'context'=>$automaticContext]],$automaticVoice,$now);
$assert(($products->getRevisioned('profile',$tieProfileId)['core_profile_id']??null)===$tieCoreOld['core_profile_id'],
    'equal-priority assignment rules did not preserve the older rule');
$ruleOnlyCore=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Rule deletion guard',
    'content'=>['schema'=>'lorkhan.core-profile.v1','prompt'=>'','routing'=>[],'settings_overrides'=>[]]],$now);
$ruleOnly=$products->saveProfileAssignmentRule(['installation_id'=>$installationId,'description'=>'Rule-only Core Profile use',
    'core_profile_id'=>$ruleOnlyCore['core_profile_id'],'priority'=>0,'enabled'=>false,
    'match'=>array_replace($emptyRuleMatch,['names'=>['Never Seen NPC']])],$now);
try{$products->deleteRevisioned('core_profile',$ruleOnlyCore['core_profile_id'],$now);throw new RuntimeException('rule-owned Core Profile deleted');}
catch(InvalidArgumentException $error){$assert($error->getMessage()==='core_profile_in_use','rule target deletion guard failed');}
$products->deleteProfileAssignmentRule($installationId,$ruleOnly['rule_id']);
$products->deleteRevisioned('core_profile',$ruleOnlyCore['core_profile_id'],$now);
$secondPlacement=$automaticTarget;$secondPlacement['refnum']['index']=103;
$secondPlacement['cell']=['kind'=>'interior','name'=>'Balmora, Guild of Mages'];
$secondPlacementProfileId=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$secondPlacement]],$automaticVoice,$now);
$secondPlacementProfile=$products->getRevisioned('profile',$secondPlacementProfileId);
$assert($secondPlacementProfileId!==$automaticProfileId
    &&str_contains((string)($secondPlacementProfile['content']['oghma_knowledge_tags']??''),'west_gash'),
    'generic NPC bases at different RefNums did not receive independent regional profiles');
$movedTarget=$automaticTarget;$movedTarget['cell']=['kind'=>'interior','name'=>'Balmora, Guild of Mages'];
$movedProfileId=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$movedTarget]],$automaticVoice,$now);
$movedProfile=$products->getRevisioned('profile',$movedProfileId);
$assert($movedProfileId===$automaticProfileId
    &&str_contains((string)($movedProfile['content']['oghma_knowledge_tags']??''),'bitter_coast')
    &&!str_contains((string)($movedProfile['content']['oghma_knowledge_tags']??''),'west_gash'),
    'walking into another region rewrote an NPC immutable home locality');
$legacyLocalityTarget=$automaticTarget;$legacyLocalityTarget['record_id']='legacy_locality_bosmer';
$legacyLocalityTarget['refnum']['index']=104;$legacyLocalityTarget['cell']=['kind'=>'interior','name'=>'Balmora, Guild of Mages'];
$legacyLocality=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Legacy Locality Bosmer',
    'actor_identity'=>$legacyLocalityTarget,'content'=>['oghma_knowledge_tags'=>'common','management'=>['locked'=>false]],
    'change_reason'=>'automatic Morrowind actor discovery'],$now);
$lockedLocalityTarget=$automaticTarget;$lockedLocalityTarget['record_id']='locked_locality_bosmer';
$lockedLocalityTarget['refnum']['index']=105;$lockedLocalityTarget['cell']=['kind'=>'interior','name'=>'Balmora, Guild of Mages'];
$lockedLocality=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Locked Locality Bosmer',
    'actor_identity'=>$lockedLocalityTarget,'content'=>['oghma_knowledge_tags'=>'custom','management'=>['locked'=>true]],
    'change_reason'=>'automatic Morrowind actor discovery'],$now);
$localityBackfill=$products->backfillMorrowindCatalogLocalities($now);
$legacyLocality=$products->getRevisioned('profile',$legacyLocality['profile_id']);
$lockedLocality=$products->getRevisioned('profile',$lockedLocality['profile_id']);
$assert($localityBackfill['updated']>=1
    &&str_contains((string)($legacyLocality['content']['oghma_knowledge_tags']??''),'west_gash')
    &&!str_contains((string)($legacyLocality['content']['oghma_knowledge_tags']??''),'common')
    &&($lockedLocality['content']['oghma_knowledge_tags']??null)==='custom',
    'locality backfill did not update automatic profiles while preserving locked custom profiles');
$rediscoveredTarget=$automaticTarget;$rediscoveredTarget['record_id']='rediscovered_bosmer';
$rediscoveredTarget['refnum']['index']=102;$rediscoveredTarget['display_name']='Rediscovered Bosmer';
$deletedProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Rediscovered Bosmer',
    'actor_identity'=>$rediscoveredTarget,'content'=>[]],$now);
$products->deleteRevisioned('profile',$deletedProfile['profile_id'],$now);
$rediscoveredProfileId=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$rediscoveredTarget]],$automaticVoice,$now);
$rediscoveredProfile=$products->getRevisioned('profile',$rediscoveredProfileId);
$assert($rediscoveredProfileId!==$deletedProfile['profile_id']&&$rediscoveredProfile['name']==='Rediscovered Bosmer',
    'soft-deleted NPC name prevented automatic rediscovery');
$legacyContent=$automaticProfile['content'];$legacyContent['voice']=['id'=>'automatic_bosmer','language'=>'en'];
$legacyContent['management']['locked']=true;$products->revise('profile',$automaticProfileId,$legacyContent,'legacy automatic voice fixture',$now);
$backfilled=$products->backfillMorrowindCatalogVoices($morrowindVoices,$now);
$automaticProfile=$products->getRevisioned('profile',$automaticProfileId);
$fargothProfile=$products->getRevisioned('profile',$actorProfile['profile_id']);
$assert($backfilled['updated']>=1&&($automaticProfile['content']['voice']['id']??null)==='mw_wood_elf_male'
    &&($automaticProfile['content']['management']['locked']??false)===true,
    'legacy actor-name voice was not repaired without preserving its profile lock');
$assert(($fargothProfile['content']['voice']['id']??null)==='fargoth'
    &&($fargothProfile['content']['voice']['source']??null)==='morrowind_actor_provider_catalog',
    'voice backfill did not upgrade the current Fargoth profile to its exact provider sample');
$actorProfile=$fargothProfile;
$automaticSpeech=$products->speechContext($installationId,$session['playthrough_id'],$automaticTarget,$profileTtsPreset);
$assert($automaticSpeech===['voice'=>'mw_wood_elf_male','language'=>'en'],
    'catalog voice was not selected when the routed connector contained it');
$db->prepare("DELETE FROM installation_provider_selections WHERE installation_id=:installation AND provider_kind='tts_provider'")
    ->execute(['installation'=>$installationId]);
$speechCore=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Integration Speech',
    'default_npc'=>false,'slot'=>null,'content'=>['schema'=>'lorkhan.core-profile.v1','prompt'=>'',
        'routing'=>['tts_configuration_id'=>$profileTtsPreset['configuration_id']],'settings_overrides'=>[]]],$now);
$speechProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Jiub speech route',
    'actor_identity'=>['record_id'=>'jiub'],'core_profile_id'=>$speechCore['core_profile_id'],
    'content'=>['gender'=>'Female','race'=>'Dunmer']],$now);
$narratorProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'The Test Narrator',
    'actor_identity'=>['kind'=>'narrator','record_id'=>'lorkhan:narrator','content_file'=>'LORKHAN'],
    'content'=>['enabled'=>true,'inline_narration_mode'=>'Narrator','speech_style'=>'Measured narration.']],$now);
$controlsQuery=$fixture('controls-query');
$controlsQuery['message_id']=$newUuid(6);$controlsQuery['request_id']=$newUuid(7);
$controlsQuery['session_id']=$sessionId;$controlsQuery['generation']=7;
$directControlSlot=$products->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Controls explicit connector',
    'content'=>['driver'=>'openai-compatible','model'=>'controls-test','endpoint'=>'http://127.0.0.1:1234/v1/chat/completions']],$now);
[$status,$controls]=$call($router,'POST',$base.'/controls/query',$jsonAuth,[],$controlsQuery);
$assert($status===200&&$controls['schema']==='lorkhan.controls.v1'
    &&array_column($controls['model_slots'],'key')===['standard','fast','powerful','experimental']
    &&count($controls['model_slots'])===4
    &&in_array($actorProfile['profile_id'],array_column($controls['profiles'],'profile_id'),true)
    &&!in_array($narratorProfile['profile_id'],array_column($controls['profiles'],'profile_id'),true)
    &&$controls['narrator_profile_id']===$narratorProfile['profile_id']
    &&$controls['selected_model_slot_key']==='standard'&&$controls['selected_profile_id']===null
    &&($controls['effective_settings']['schema']??null)==='lorkhan.effective-settings.v1'
    &&preg_match('/^[0-9a-f]{64}$/D',(string)($controls['effective_settings']['change_token']??''))===1
    &&($controls['effective_settings']['profile_id']??null)===null
    &&isset($controls['effective_settings']['settings']['memory'],$controls['effective_settings']['settings']['narrator'],$controls['effective_settings']['settings']['safety'])
    &&isset($controls['effective_settings']['settings']['behavior'])
    &&$controls['effective_settings']['settings']['presentation']===\LorkhanServer\Application\EffectiveSettingsResolver::defaults()['presentation']
    &&!isset($controls['effective_settings']['settings']['memory']['oghma_knowledge_tags']),
    'in-game controls query did not return safe model/profile choices');
$assert(!str_contains(json_encode($controls,JSON_THROW_ON_ERROR),'127.0.0.1:1234'),
    'unrouted connectors or endpoint secrets reached the semantic model slots');

$selectModel=$fixture('controls-select');
$selectModel['message_id']=$newUuid(8);$selectModel['request_id']=$newUuid(9);
$selectModel['session_id']=$sessionId;$selectModel['generation']=7;$selectModel['created_at']=$now;
$selectModel['target']=$controlsQuery['target'];$selectModel['kind']='model_slot';
$selectModel['selection_id']=null;$selectModel['selection_key']='fast';
[$status,$modelSelected]=$call($router,'POST',$base.'/controls/select',$headers($selectModel['message_id']),[],$selectModel);
$assert($status===200&&$modelSelected['selected_model_slot_key']==='fast'
    &&$products->selectedModelSlot($installationId)==='fast',
    'in-game model slot selection failed');
[$status,$modelReplay]=$call($router,'POST',$base.'/controls/select',$headers($selectModel['message_id']),[],$selectModel);
$assert($status===200&&$modelReplay==$modelSelected,'in-game model slot selection was not idempotent');

$selectProfile=$selectModel;$selectProfile['message_id']=$newUuid(10);$selectProfile['request_id']=$newUuid(11);
$selectProfile['kind']='actor_profile';$selectProfile['selection_id']=$actorProfile['profile_id'];$selectProfile['selection_key']=null;
[$status,$profileSelected]=$call($router,'POST',$base.'/controls/select',$headers($selectProfile['message_id']),[],$selectProfile);
    $assert($status===200&&$profileSelected['selected_profile_id']===$actorProfile['profile_id']
        &&$profileSelected['selected_model_slot_key']==='fast'&&$profileSelected['resolved_model_slot_key']==='fast'
        &&($profileSelected['effective_settings']['profile_id']??null)===$actorProfile['profile_id']
        &&($profileSelected['effective_settings']['change_token']??null)!==($controls['effective_settings']['change_token']??null),
        'in-game actor profile selection failed');
$turnLike=['session_id'=>$sessionId,'generation'=>7,'installation_id'=>$installationId,
    'playthrough_id'=>$session['playthrough_id'],'payload'=>['target'=>$controlsQuery['target']]];
$explicitContext=$products->providerContext($turnLike);
$assert(($explicitContext['configuration_id']??null)===$modelSlot['configuration_id'],
    'Fast model selection did not resolve through the assigned Core Profile');
$products->selectModelSlot(['session_id'=>$sessionId,'generation'=>7,'installation_id'=>$installationId],'powerful',$now);
$missingSlotContext=$products->providerContext($turnLike);$missingSlotControls=$products->sessionControls($session,$controlsQuery['target']);
$assert(($missingSlotContext['configuration_id']??null)===$profileModelSlot['configuration_id']
    &&$missingSlotControls['selected_model_slot_key']==='powerful'&&$missingSlotControls['resolved_model_slot_key']==='standard',
    'an unavailable selected model slot did not fall back to the first configured profile slot: '.json_encode([
        'provider'=>$missingSlotContext['configuration_id']??null,'selected'=>$missingSlotControls['selected_model_slot_key']??null,
        'resolved'=>$missingSlotControls['resolved_model_slot_key']??null]));
$clearModel=$selectModel;$clearModel['message_id']=$newUuid(706);$clearModel['request_id']=$newUuid(707);$clearModel['selection_key']='standard';
[$status]=$call($router,'POST',$base.'/controls/select',$headers($clearModel['message_id']),[],$clearModel);
$profileContext=$products->providerContext($turnLike);
$assert($status===200&&($profileContext['configuration_id']??null)===$profileModelSlot['configuration_id'],
    'assigned Core Profile did not supply its primary LLM model slot');
$coreModelSlot=$products->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Core-profile mock',
    'content'=>['driver'=>'mock','model'=>'deterministic-core-v1','mock_prefix'=>'[core] ']],$now);
$coreProfile=$products->getRevisioned('core_profile',$actorCoreProfile['core_profile_id']);
$coreProfile=$products->revise('core_profile',$coreProfile['core_profile_id'],[
    'schema'=>'lorkhan.core-profile.v1','prompt'=>'CORE PROFILE INSTRUCTION SENTINEL',
    'routing'=>['llm_configuration_id'=>$coreModelSlot['configuration_id']],
    'settings_overrides'=>['behavior'=>['rechat'=>true,'rechat_probability_percent'=>0,'open_rechat'=>false],
        'memory'=>['knowledge_limit'=>0]],
],'integration layered settings',$now);
$inheritedContent=$actorProfile['content'];$inheritedContent['routing']=[];
$actorProfile=$products->revise('profile',$actorProfile['profile_id'],$inheritedContent,'inherit Core Profile routing',$now);
$inheritedContext=$products->providerContext($turnLike);
$effectiveSettings=$products->effectiveSettingsForActor($installationId,$session['playthrough_id'],$controlsQuery['target']);
$effectiveControlsQuery=$controlsQuery;$effectiveControlsQuery['message_id']=$newUuid(712);$effectiveControlsQuery['request_id']=$newUuid(713);
[$effectiveControlsStatus,$effectiveControls]=$call($router,'POST',$base.'/controls/query',$jsonAuth,[],$effectiveControlsQuery);
$assert(($inheritedContext['configuration_id']??null)===$coreModelSlot['configuration_id']
    &&$effectiveSettings['settings']['behavior']['rechat']===true
    &&$effectiveSettings['settings']['memory']['knowledge_limit']===5
    &&($effectiveSettings['sources']['settings.behavior.rechat']??null)==='core_profile'
    &&$effectiveControlsStatus===200
    &&($effectiveControls['effective_settings']['profile_id']??null)===$actorProfile['profile_id']
    &&($effectiveControls['effective_settings']['core_profile_id']??null)===$coreProfile['core_profile_id']
    &&($effectiveControls['effective_settings']['settings']['memory']['knowledge_limit']??null)===5
    &&($effectiveControls['effective_settings']['settings']['behavior']['rechat']??null)===true
    &&($effectiveControls['effective_settings']['settings']['behavior']['rechat_probability_percent']??null)===0
    &&($effectiveControls['effective_settings']['settings']['behavior']['open_rechat']??null)===false
    &&!array_key_exists('settings.memory.knowledge_limit',$effectiveControls['effective_settings']['source_map']??[]),
    'Core Profile response routing and explicit profile settings did not reach runtime resolution');
$coreContent=$coreProfile['content'];$coreContent['settings_overrides']['behavior']=['rechat'=>true];
$coreProfile=$products->revise('core_profile',$coreProfile['core_profile_id'],$coreContent,'restore rechat fixture probability',$now);
$maskedContent=$actorProfile['content'];$maskedContent['routing']=['llm_configuration_id'=>''];
$actorProfile=$products->revise('profile',$actorProfile['profile_id'],$maskedContent,'explicit NPC route disable',$now);
$assert(($products->providerContext($turnLike)['configuration_id']??null)===$coreModelSlot['configuration_id'],
    'legacy NPC routing unexpectedly masked the assigned Core Profile connector');
$randomizedContent=$coreProfile['content'];$randomizedContent['routing']=[
    'llm_configuration_id'=>$profileModelSlot['configuration_id'],
    'llm_fast_configuration_id'=>$fastModelSlot['configuration_id'],
    'llm_randomizer_enabled'=>true,
];
$coreProfile=$products->revise('core_profile',$coreProfile['core_profile_id'],$randomizedContent,'integration LLM routing',$now);
$randomizedSelections=[];
for($i=720;$i<752;$i++){$candidate=$turnLike;$candidate['turn_id']=$newUuid($i);
    $context=$products->providerContext($candidate);$randomizedSelections[$context['configuration_id']??'']=true;}
$assert(isset($randomizedSelections[$profileModelSlot['configuration_id']],$randomizedSelections[$fastModelSlot['configuration_id']])
    &&count($randomizedSelections)===2,'Core Profile LLM randomizer did not use every configured general-purpose slot');
$manualContent=$coreProfile['content'];$manualContent['routing']['llm_randomizer_enabled']=false;
$coreProfile=$products->revise('core_profile',$coreProfile['core_profile_id'],$manualContent,'restore manual LLM slot selection',$now);
$restoreModel=$selectModel;$restoreModel['message_id']=$newUuid(708);$restoreModel['request_id']=$newUuid(709);
[$status]=$call($router,'POST',$base.'/controls/select',$headers($restoreModel['message_id']),[],$restoreModel);
$speechTarget=$controlsQuery['target'];$speechTarget['record_id']='jiub';$speechTarget['display_name']='Jiub';$speechTarget['refnum']['index']=99;
$products->bindActorProfile(['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']],$speechTarget,$speechProfile['profile_id'],$now);
$profileSpeech=$products->connectorForActor($installationId,$session['playthrough_id'],$speechTarget,'tts_provider');
$assert($status===200&&($profileSpeech['configuration_id']??null)===$profileTtsPreset['configuration_id'],
    'assigned Core Profile did not supply its TTS connector');
$profileSpeechContext=$products->speechContext($installationId,$session['playthrough_id'],$speechTarget,$profileSpeech);
$assert($profileSpeechContext===['voice'=>'mw_dark_elf_female'],
    'NPC profile race and gender did not select the global Morrowind fallback before the connector fallback: '.json_encode($profileSpeechContext));
// A diary retains its recorded author even when a live actor is rebound to another profile.
$db->beginTransaction();
try {
    $diaryScope=['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id'],'profile_id'=>$speechProfile['profile_id']];
    $voiceDiary=$products->createNarrative($diaryScope+['kind'=>'diary','title'=>'Author voice fixture',
        'content'=>'The Nerevarine returned to Vvardenfell under the Tribunal.','provenance'=>[]],$now);
    $products->bindActorProfile(['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']],$speechTarget,$actorProfile['profile_id'],$now);
    $diaryPlan=$products->diarySpeechPlan($installationId,$voiceDiary['narrative_id']);
    $assert($diaryPlan['profile_id']===$speechProfile['profile_id']
        &&$diaryPlan['connector']['configuration_id']===$profileTtsPreset['configuration_id']
        &&$diaryPlan['context']['voice']==='mw_dark_elf_female'
        &&$diaryPlan['text']==='The Nerevareen returned to Vardenfell under the Trybyoonal.'
        &&$diaryPlan['context']['pronunciation_scope']['npc_name']==='Jiub speech route',
        'diary author routing, fallback voice or pronunciation used the live binding instead of its recorded author');
    $summaryEntry=$products->createNarrative($diaryScope+['kind'=>'summary','title'=>'Not a diary','content'=>'Summary.','provenance'=>[]],$now);
    foreach([[$newUuid(9900),$voiceDiary['narrative_id']],[$installationId,$summaryEntry['narrative_id']]] as [$scopeId,$entryId]) {
        $rejected=false;try{$products->diarySpeechPlan($scopeId,$entryId);}catch(RuntimeException $e){$rejected=$e->getMessage()==='diary_audio_entry_not_found';}
        $assert($rejected,'diary speech accepted a wrong-scope or non-diary entry');
    }
    $narratorDiaryContent=$narratorProfile['content'];
    $narratorDiaryContent['voice']=['id'=>'NarratorFixture','language'=>'en'];
    $narratorDiaryContent['routing']=['tts_configuration_id'=>$profileTtsPreset['configuration_id']];
    $products->revise('profile',$narratorProfile['profile_id'],$narratorDiaryContent,'diary narrator fixture',$now);
    $narratorDiary=$products->createNarrative(array_replace($diaryScope,['profile_id'=>$narratorProfile['profile_id']])+
        ['kind'=>'diary','title'=>'Narrator diary','content'=>'Narrator entry.','provenance'=>[]],$now);
    $narratorPlan=$products->diarySpeechPlan($installationId,$narratorDiary['narrative_id']);
    $assert($narratorPlan['profile_id']===$narratorProfile['profile_id']&&$narratorPlan['context']['voice']==='NarratorFixture',
        'a Narrator-authored diary did not retain its own explicit voice');
    $db->prepare('UPDATE profiles SET deleted_at=:now WHERE profile_id=:id')->execute(['now'=>$now,'id'=>$speechProfile['profile_id']]);
    $rejected=false;try{$products->diarySpeechPlan($installationId,$voiceDiary['narrative_id']);}catch(RuntimeException $e){$rejected=$e->getMessage()==='diary_audio_entry_not_found';}
    $assert($rejected,'diary speech accepted a deleted author');
    $db->prepare('UPDATE profiles SET deleted_at=NULL WHERE profile_id=:id')->execute(['id'=>$speechProfile['profile_id']]);
    $products->deleteNarrative($voiceDiary['narrative_id'],$now);
    $rejected=false;try{$products->diarySpeechPlan($installationId,$voiceDiary['narrative_id']);}catch(RuntimeException $e){$rejected=$e->getMessage()==='diary_audio_entry_not_found';}
    $assert($rejected,'diary speech accepted a deleted entry');
} finally {$db->rollBack();}
$playerContent=$playerProfile['content'];
$playerContent['routing']['tts_configuration_id']=$profileTtsPreset['configuration_id'];
$playerContent['routing']['player_autochat_configuration_id']=$profileModelSlot['configuration_id'];
$playerContent['voice']=['id'=>'MaleArgonian','language'=>'en-US'];
$products->revise('profile',$playerProfile['profile_id'],$playerContent,'integration player TTS route',$now);
$playerProfile=$products->playerProfileForInstallation($installationId);
$playerSpeech=$products->connectorForActor($installationId,$session['playthrough_id'],$playerProfile['actor_identity'],'tts_provider');
$playerSpeechContext=$products->speechContext($installationId,$session['playthrough_id'],$playerProfile['actor_identity'],$playerSpeech);
$assert(($playerSpeech['configuration_id']??null)===$profileTtsPreset['configuration_id']
    &&$playerSpeechContext===['voice'=>'MaleArgonian','language'=>'en-US'],
    'player profile did not supply its TTS connector and voice: '.json_encode($playerSpeechContext));
$playerContent['player_elevenlabs']=['model_id'=>'eleven_v3','speed'=>1.1];
$products->revise('profile',$playerProfile['profile_id'],$playerContent,'player voice overrides',$now);
$playerOverrideContext=$products->speechContext($installationId,$session['playthrough_id'],$playerProfile['actor_identity'],$playerSpeech);
$assert(($playerOverrideContext['player_elevenlabs']['model_id']??null)==='eleven_v3'
    && ($playerOverrideContext['player_elevenlabs']['speed']??null)===1.1
    && !isset($products->speechContext($installationId,$session['playthrough_id'],$speechTarget,$profileSpeech)['player_elevenlabs']),
    'player overrides must be resolved for the player only');
unset($playerContent['player_elevenlabs']);
$products->revise('profile',$playerProfile['profile_id'],$playerContent,'restore player voice defaults',$now);
$playerAutochat=$fixture('player-autochat');
$playerAutochat['message_id']=$newUuid(714);$playerAutochat['request_id']=$newUuid(715);
$playerAutochat['session_id']=$sessionId;$playerAutochat['generation']=7;$playerAutochat['created_at']=$now;
$playerAutochat['target']=$controlsQuery['target'];
$playerAutochat['intent']='ask whether he has found his ring';
[$status,$rewritten]=$call($router,'POST',$base.'/player-autochat',$headers($playerAutochat['message_id']),[],$playerAutochat);
$assert($status===201&&($rewritten['schema']??null)==='lorkhan.player-autochat.ready.v1'
    &&($rewritten['request_id']??null)===$playerAutochat['request_id']
    &&($rewritten['text']??null)===$playerAutochat['intent'],
    'player Auto Chat did not resolve its dedicated player-profile connector: '.json_encode($rewritten));

$generateProfile=$selectProfile;$generateProfile['message_id']=$newUuid(12);$generateProfile['request_id']=$newUuid(13);
$generateProfile['kind']='profile_generate';
[$status,$generationQueued]=$call($router,'POST',$base.'/controls/select',$headers($generateProfile['message_id']),[],$generateProfile);
$queuedProfileJob=$db->prepare("SELECT state,payload FROM durable_jobs WHERE job_type='profile.generate' AND payload->>'profile_id'=:profile");
$queuedProfileJob->execute(['profile'=>$actorProfile['profile_id']]);$queuedProfileJobRow=$queuedProfileJob->fetch();
$assert($status===200&&$generationQueued['selected_profile_id']===$actorProfile['profile_id']
    &&$queuedProfileJobRow&&$queuedProfileJobRow['state']==='queued',
    'in-game bound profile generation did not queue a durable job');

$unboundGenerate=$generateProfile;$unboundGenerate['message_id']=$newUuid(14);$unboundGenerate['request_id']=$newUuid(15);
$unboundGenerate['target']['record_id']='not_bound';
[$status]=$call($router,'POST',$base.'/controls/select',$headers($unboundGenerate['message_id']),[],$unboundGenerate);
$assert($status===404,'in-game profile generation accepted a profile not bound to the target');

$generateNarrator=$selectProfile;$generateNarrator['message_id']=$newUuid(710);$generateNarrator['request_id']=$newUuid(711);
$generateNarrator['kind']='narrator_profile_generate';$generateNarrator['selection_id']=$narratorProfile['profile_id'];
[$status,$narratorQueued]=$call($router,'POST',$base.'/controls/select',$headers($generateNarrator['message_id']),[],$generateNarrator);
$queuedNarratorJob=$db->prepare("SELECT state,payload FROM durable_jobs WHERE job_type='profile.generate' AND payload->>'profile_id'=:profile");
$queuedNarratorJob->execute(['profile'=>$narratorProfile['profile_id']]);$queuedNarratorJobRow=$queuedNarratorJob->fetch();
$queuedNarratorPayload=$queuedNarratorJobRow?json_decode((string)$queuedNarratorJobRow['payload'],true,32,JSON_THROW_ON_ERROR):[];
$assert($status===200&&$narratorQueued['narrator_profile_id']===$narratorProfile['profile_id']
    &&$queuedNarratorJobRow&&$queuedNarratorJobRow['state']==='queued'&&($queuedNarratorPayload['mode']??null)==='narrator_profile',
    'in-game narrator profile generation did not queue a durable narrator job: '.json_encode([
        'status'=>$status,'body'=>$narratorQueued,'job'=>$queuedNarratorJobRow,'payload'=>$queuedNarratorPayload],JSON_UNESCAPED_SLASHES));
$wrongNarrator=$generateNarrator;$wrongNarrator['message_id']=$newUuid(712);$wrongNarrator['request_id']=$newUuid(713);
$wrongNarrator['selection_id']=$actorProfile['profile_id'];
[$status]=$call($router,'POST',$base.'/controls/select',$headers($wrongNarrator['message_id']),[],$wrongNarrator);
$assert($status===422,'in-game narrator generation accepted a non-narrator profile');

$autoTarget=['kind'=>'npc','record_id'=>'auto_profile_sentinel','refnum'=>['index'=>852,'content_file'=>0],
    'content_file'=>'Morrowind.esm','cell'=>['kind'=>'interior','name'=>'Balmora'],
    'display_name'=>'Auto Profile Sentinel'];
$autoProfileData=$fixture('gamedata-captured-dialogue');
$autoProfileData['installation_id']=$installationId;$autoProfileData['playthrough_id']=$session['playthrough_id'];
$autoProfileData['session_id']=$sessionId;$autoProfileData['generation']=7;$autoProfileData['runtime_generation']=7;
$autoProfileData['request_id']=$newUuid(852);$autoProfileData['type']='actor_profile';
$autoProfileData['payload']=['actor'=>$autoTarget,'race'=>'Wood Elf','class'=>'Commoner','gender'=>'male',
    'level'=>1,'disposition'=>50,'factions'=>['fighters guild']];
[$status,$autoAccepted]=$call($router,'POST',$base.'/gamedata',$headers($autoProfileData['request_id']),[],$autoProfileData);
$autoControls=$controlsQuery;$autoControls['message_id']=$newUuid(853);$autoControls['request_id']=$newUuid(854);
$autoControls['target']=$autoTarget;
[$autoControlsStatus,$autoControlsBody]=$call($router,'POST',$base.'/controls/query',$jsonAuth,[],$autoControls);
$autoProfileId=$autoControlsBody['selected_profile_id']??null;
$autoProfile=is_string($autoProfileId)?$products->getRevisioned('profile',$autoProfileId):null;
$assert($status===202&&($autoAccepted['type']??null)==='actor_profile'&&$autoControlsStatus===200
    &&is_array($autoProfile)&&strtolower((string)($autoProfile['content']['race']??''))==='wood elf'
    &&($autoProfile['content']['gender']??null)==='Male'
    &&($autoProfile['core_profile_id']??null)===$ruleCoreLow['core_profile_id'],
    'auto-activated NPC did not create and bind its server profile: '.json_encode([
        'status'=>$status,'body'=>$autoAccepted,'controls'=>$autoControlsBody,'profile'=>$autoProfile],JSON_UNESCAPED_SLASHES));
[$duplicateAutoStatus]=$call($router,'POST',$base.'/gamedata',$headers($autoProfileData['request_id']),[],$autoProfileData);
$autoProfileCount=$db->prepare("SELECT count(*) FROM profiles WHERE installation_id=:installation AND actor_identity->>'record_id'=:record");
$autoProfileCount->execute(['installation'=>$installationId,'record'=>$autoTarget['record_id']]);
$assert($duplicateAutoStatus===202&&(int)$autoProfileCount->fetchColumn()===1,
    'replayed auto-activation created a duplicate NPC profile');

// Opposing global/Core policies prove the responder owns the single RPG decision, including legacy fallback.
$db->beginTransaction();
try {
    $rpgGlobal=$products->globalSettingsForInstallation($installationId);
    $rpgGlobalContent=$rpgGlobal['content'];
    $rpgGlobalContent['rpg_comments']=['events'=>['levelup'],'chance_percent'=>0];
    $products->revise('global_settings',$rpgGlobal['configuration_id'],$rpgGlobalContent,'RPG global fixture',$now);
    $rpgCore=$products->getRevisioned('core_profile',$autoProfile['core_profile_id']);
    $rpgCoreContent=$rpgCore['content'];$rpgCoreContent['settings_overrides']['rpg_comments']=['events'=>['levelup'],'chance_percent'=>100];
    $products->revise('core_profile',$rpgCore['core_profile_id'],$rpgCoreContent,'RPG Core fixture',$now);
    $rpgData=$autoProfileData;$rpgData['type']='rpg_event';$rpgData['request_id']=$newUuid(68001);
    $rpgData['payload']=$fixture('gamedata-rpg-responder')['payload'];$rpgData['payload']['responder']=$autoTarget;
    [$rpgStatus,$rpgAccepted]=$call($router,'POST',$base.'/gamedata',$headers($rpgData['request_id']),[],$rpgData);
    $assert($rpgStatus===202&&($rpgAccepted['comment_requested']??null)===true,'Core 100 must override global zero for the bound responder');
    $rpgCoreContent['settings_overrides']['rpg_comments']['chance_percent']=0;
    $products->revise('core_profile',$rpgCore['core_profile_id'],$rpgCoreContent,'RPG Core disabled',$now);
    [$rpgReplayStatus,$rpgReplay]=$call($router,'POST',$base.'/gamedata',$headers($rpgData['request_id']),[],$rpgData);
    $assert($rpgReplayStatus===202&&($rpgReplay['comment_requested']??null)===true,'RPG retry must retain its original decision after profile changes');
    $rpgGlobalContent['rpg_comments']['chance_percent']=100;
    $products->revise('global_settings',$rpgGlobal['configuration_id'],$rpgGlobalContent,'RPG global enabled',$now);
    $rpgData['request_id']=$newUuid(68002);
    [$rpgStatus,$rpgAccepted]=$call($router,'POST',$base.'/gamedata',$headers($rpgData['request_id']),[],$rpgData);
    $assert($rpgStatus===202&&($rpgAccepted['comment_requested']??null)===false,'Core zero must override global 100');
    unset($rpgData['payload']['responder']);$rpgData['request_id']=$newUuid(68003);
    [$rpgStatus,$rpgAccepted]=$call($router,'POST',$base.'/gamedata',$headers($rpgData['request_id']),[],$rpgData);
    $assert($rpgStatus===202&&($rpgAccepted['comment_requested']??null)===true,'Legacy player-only RPG observations retain the global policy');
} finally { $db->rollBack(); }

// Bored opportunities use the actual responder and retain the original decision on retry.
$db->beginTransaction();
try {
    $boredGlobal=$products->globalSettingsForInstallation($installationId);
    $boredGlobalContent=$boredGlobal['content'];$boredGlobalContent['bored_event']=['chance_percent'=>0];
    $products->revise('global_settings',$boredGlobal['configuration_id'],$boredGlobalContent,'Bored global fixture',$now);
    $boredCore=$products->getRevisioned('core_profile',$autoProfile['core_profile_id']);
    $boredContent=$boredCore['content'];$boredContent['settings_overrides']['bored_event']=['chance_percent'=>100];
    $products->revise('core_profile',$boredCore['core_profile_id'],$boredContent,'Bored Core fixture',$now);
    $boredData=$autoProfileData;$boredData['type']='bored_event';$boredData['request_id']=$newUuid(68101);
    $boredData['payload']=['responder'=>$autoTarget,'game_time'=>120];
    [$boredStatus,$boredAccepted]=$call($router,'POST',$base.'/gamedata',$headers($boredData['request_id']),[],$boredData);
    $assert($boredStatus===202&&($boredAccepted['comment_requested']??null)===true,'Bored responder Core 100 overrides global zero');
    $boredContent['settings_overrides']['bored_event']['chance_percent']=0;
    $products->revise('core_profile',$boredCore['core_profile_id'],$boredContent,'Bored Core zero',$now);
    [$boredStatus,$boredAccepted]=$call($router,'POST',$base.'/gamedata',$headers($boredData['request_id']),[],$boredData);
    $assert($boredStatus===202&&($boredAccepted['comment_requested']??null)===true,'Bored retry retains original decision');
    $boredData['request_id']=$newUuid(68102);
    [$boredStatus,$boredAccepted]=$call($router,'POST',$base.'/gamedata',$headers($boredData['request_id']),[],$boredData);
    $assert($boredStatus===202&&($boredAccepted['comment_requested']??null)===false,'Bored Core zero suppresses a new opportunity');
} finally { $db->rollBack(); }

// Quest observations remain persisted even when Core commentary is disabled.
$db->beginTransaction();
try {
    $questCore=$products->getRevisioned('core_profile',$autoProfile['core_profile_id']);
    $questContent=$questCore['content'];$questContent['settings_overrides']['quest_comments']=['enabled'=>true,'chance_percent'=>100];
    $products->revise('core_profile',$questCore['core_profile_id'],$questContent,'Quest Core fixture',$now);
    $questData=$autoProfileData;$questData['type']='quest_event';$questData['request_id']=$newUuid(68201);
    $questData['payload']=['responder'=>$autoTarget,'game_time'=>120,'text'=>'Quest mq_test, stage 20: Actual observed objective.'];
    [$questStatus,$questAccepted]=$call($router,'POST',$base.'/gamedata',$headers($questData['request_id']),[],$questData);
    $assert($questStatus===202&&($questAccepted['comment_requested']??null)===true,'Quest enabled Core100 requests a comment');
    $questContent['settings_overrides']['quest_comments']['enabled']=false;
    $products->revise('core_profile',$questCore['core_profile_id'],$questContent,'Quest Core disabled',$now);
    [$questStatus,$questAccepted]=$call($router,'POST',$base.'/gamedata',$headers($questData['request_id']),[],$questData);
    $assert($questStatus===202&&($questAccepted['comment_requested']??null)===true,'Quest retry preserves original decision');
    $questData['request_id']=$newUuid(68202);
    [$questStatus,$questAccepted]=$call($router,'POST',$base.'/gamedata',$headers($questData['request_id']),[],$questData);
    $assert($questStatus===202&&($questAccepted['comment_requested']??null)===false,'Quest disabled Core suppresses new commentary');
    $questLog=$db->prepare('SELECT e.data FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid WHERE m.request_id=:request AND e.type=\'quest\'');
    $questLog->execute(['request'=>$questData['request_id']]);
    $assert($questLog->fetchColumn()===$questData['payload']['text'],'Quest source text remains in eventlog when commentary is disabled');
} finally { $db->rollBack(); }

$backfillTarget=$autoTarget;$backfillTarget['record_id']='profile_backfill_sentinel';
$backfillTarget['display_name']='Profile Backfill Sentinel';$backfillTarget['refnum']['index']=6100;
$backfillProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,
    'name'=>'Profile Backfill Sentinel','actor_identity'=>$backfillTarget,
    'content'=>['management'=>['locked'=>false,'favorite'=>false]]],$now);
$products->bindActorProfile(['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']],
    $backfillTarget,$backfillProfile['profile_id'],$now);
$backfillGlobal=$products->globalSettingsForInstallation($installationId);$backfillSettings=$backfillGlobal['content'];
$backfillSettings['profile_management']['autofill_custom_profiles']=true;
$backfillSettings['profile_management']['autofill_custom_profiles_trigger']=10;
$products->revise('global_settings',$backfillGlobal['configuration_id'],$backfillSettings,'enable profile backfill fixture',$now);
$backfillBeforeHistory=$products->maybeEnqueueAutomaticProfileBackfill($backfillProfile['profile_id'],$session['playthrough_id']);
$assert($backfillBeforeHistory['queued']===false&&$backfillBeforeHistory['reason']==='history_threshold'
    &&$backfillBeforeHistory['observed']===0&&$backfillBeforeHistory['required']===10,
    'automatic profile backfill did not wait for its configured history threshold');
$backfillContent=$backfillProfile['content'];
$backfillContent['settings_overrides']['profile_management']=['autofill_custom_profiles'=>false,'autofill_custom_profiles_trigger'=>100];
$products->revise('profile',$backfillProfile['profile_id'],$backfillContent,'disable actor backfill override',$now);
$backfillDisabled=$products->maybeEnqueueAutomaticProfileBackfill($backfillProfile['profile_id'],$session['playthrough_id']);
$assert($backfillDisabled['reason']==='disabled'&&$backfillDisabled['required']===100,
    'automatic backfill ignored NPC enablement or threshold overrides');
$backfillContent['settings_overrides']['profile_management']['autofill_custom_profiles']=true;
$products->revise('profile',$backfillProfile['profile_id'],$backfillContent,'enable actor threshold override',$now);
$backfillThreshold=$products->maybeEnqueueAutomaticProfileBackfill($backfillProfile['profile_id'],$session['playthrough_id']);
$assert($backfillThreshold['reason']==='history_threshold'&&$backfillThreshold['required']===100,
    'automatic backfill did not use the effective NPC history threshold');
$backfillContent['settings_overrides']['profile_management']['autofill_custom_profiles_trigger']=10;
$products->revise('profile',$backfillProfile['profile_id'],$backfillContent,'use ten actor events',$now);
$backfillData=$autoProfileData;$backfillData['request_id']=$newUuid(6160);$backfillData['payload']['actor']=$backfillTarget;
[$backfillStatus]=$call($router,'POST',$base.'/gamedata',$headers($backfillData['request_id']),[],$backfillData);
$backfillSource=$db->prepare("SELECT event_kind FROM source_events WHERE source_event_id=:source");
$backfillSource->execute(['source'=>$backfillData['request_id']]);
$assert($backfillStatus===202&&$backfillSource->fetchColumn()==='gamedata.actor_profile',
    'automatic profile observation was not persisted as immutable game data');
$insertBackfillTurn=$db->prepare("INSERT INTO turns(turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,"
    ."speaker,target,audience,context,state,accepted_at,completed_at,response_id,response_payload,response_created_at) VALUES "
    ."(:turn,:request,:message,:session,7,'text','en',:input,CAST(:speaker AS jsonb),CAST(:target AS jsonb),'[]'::jsonb,'{}'::jsonb,"
    ."'complete',:now,:now,:response,CAST(:response_payload AS jsonb),:now)");
for($index=0;$index<10;$index++)$insertBackfillTurn->execute(['turn'=>$newUuid(6110+$index*4),
    'request'=>$newUuid(6111+$index*4),'message'=>$newUuid(6112+$index*4),'response'=>$newUuid(6113+$index*4),
    'session'=>$sessionId,'input'=>'Observed exchange '.($index+1),'speaker'=>json_encode($playerProfile['actor_identity'],JSON_THROW_ON_ERROR),
    'target'=>json_encode($backfillTarget,JSON_THROW_ON_ERROR),'response_payload'=>json_encode(['lines'=>[[
        'action'=>'say','speaker_identity'=>$backfillTarget,'text'=>'Observed NPC reply '.($index+1)]]],JSON_THROW_ON_ERROR),
    'now'=>$now]);
$backfillFromTurn=$products->maybeEnqueueAutomaticProfileBackfillForTurn(['installation_id'=>$installationId,
    'playthrough_id'=>$session['playthrough_id'],'payload'=>['target'=>$backfillTarget]]);
$assert($backfillFromTurn['queued']===true&&$backfillFromTurn['observed']===10,
    'completed actor turn did not resolve its bound profile for automatic backfill');
$backfillJob=$db->prepare("SELECT job_id,state,payload FROM durable_jobs WHERE job_type='profile.generate' "
    ."AND payload->>'profile_id'=:profile AND payload->>'mode'='npc_profile_backfill'");
$backfillJob->execute(['profile'=>$backfillProfile['profile_id']]);$backfillJobRow=$backfillJob->fetch();
$backfillPayload=$backfillJobRow?json_decode((string)$backfillJobRow['payload'],true,64,JSON_THROW_ON_ERROR):[];
$assert($backfillJobRow&&$backfillJobRow['state']==='queued'
    &&count($backfillPayload['source_turn_ids']??[])===10&&count($backfillPayload['recent_events']??[])===10,
    'automatic profile backfill did not freeze bounded actor history or persist its source observation: '.json_encode([
        'status'=>$backfillStatus,'job'=>$backfillJobRow,'payload'=>$backfillPayload],JSON_UNESCAPED_SLASHES));
$backfillHandlerPayload=$backfillPayload;unset($backfillHandlerPayload['provider_configuration_id'],$backfillHandlerPayload['provider_revision']);
$backfillHandlerPayload['_job']=['job_id'=>$backfillJobRow['job_id'],'attempt'=>1];
(new \LorkhanServer\Application\ProfileGenerateJobHandler($products,
    new \LorkhanServer\Application\MockProfileGenerationProvider()))->handle($backfillHandlerPayload,'profile-backfill-test',static fn():bool=>true);
$generatedBackfill=$products->getRevisioned('profile',$backfillProfile['profile_id']);
$assert((int)$generatedBackfill['current_revision']===(int)$backfillPayload['base_revision']+1
    &&str_contains((string)($generatedBackfill['content']['notes']??''),'backfill from 10 recent events'),
    'automatic profile backfill worker did not use the frozen actor history');

$evolutionCore=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Evolution discovery defaults',
    'content'=>['schema'=>'lorkhan.core-profile.v1','prompt'=>'','routing'=>[],
        'settings_overrides'=>['profile_evolution'=>['enabled'=>true,'fields'=>['occupation','skills']]]]],$now);
$inheritedNpc=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Evolution inherited NPC',
    'actor_identity'=>['kind'=>'actor','record_id'=>'evolution_inherited'],
    'core_profile_id'=>$evolutionCore['core_profile_id'],'content'=>[]],$now);
$explicitNpc=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Evolution explicit NPC',
    'actor_identity'=>['kind'=>'actor','record_id'=>'evolution_explicit'],
    'core_profile_id'=>$evolutionCore['core_profile_id'],
    'content'=>['dynamic_profile'=>false,'dynamic_profile_fields'=>['goals']]],$now);
$evolutionCoreContent=$evolutionCore['content'];$evolutionCoreContent['settings_overrides']['profile_evolution']['enabled']=false;
$products->revise('core_profile',$evolutionCore['core_profile_id'],$evolutionCoreContent,'change discovery defaults',$now);
$inheritedNpc=$products->getRevisioned('profile',$inheritedNpc['profile_id']);
$assert($inheritedNpc['content']['dynamic_profile']===true
    &&$inheritedNpc['content']['dynamic_profile_fields']===['occupation','skills']
    &&$explicitNpc['content']['dynamic_profile']===false&&$explicitNpc['content']['dynamic_profile_fields']===['goals'],
    'Core Profile discovery defaults did not seed new NPCs or overwrote explicit/existing choices');

$dynamicContent=$generatedBackfill['content'];$dynamicContent['dynamic_profile']=true;
$dynamicContent['dynamic_profile_fields']=['personality','occupation','skills'];$dynamicContent['personality']='Baseline personality to evolve.';
$dynamicContent['occupation']='Baseline occupation.';$dynamicContent['skills']='Baseline skills.';
$dynamicContent['speech_style']='Speech style must remain unchanged.';
$dynamicContent['goals']='Goals must remain unchanged.';
$dynamicContent['settings_overrides']['profile_evolution']['history_limit']=2;
$dynamicProfile=$products->revise('profile',$backfillProfile['profile_id'],$dynamicContent,'enable dynamic profile fixture',$now);
$db->prepare("UPDATE sessions SET created_at=clock_timestamp()-interval '21 minutes' WHERE session_id=:session")
    ->execute(['session'=>$sessionId]);
$evolutionHistoryCore=$products->getRevisioned('core_profile',$evolutionCore['core_profile_id']);
$evolutionHistoryContent=$evolutionHistoryCore['content'];$evolutionHistoryContent['settings_overrides']['profile_evolution']['history_limit']=3;
$products->revise('core_profile',$evolutionCore['core_profile_id'],$evolutionHistoryContent,'bounded evolution history fixture',$now);
$db->prepare('UPDATE profiles SET core_profile_id=:core WHERE profile_id IN (:npc,:narrator)')->execute([
    'core'=>$evolutionCore['core_profile_id'],'npc'=>$dynamicProfile['profile_id'],'narrator'=>$narratorProfile['profile_id']]);
$dynamicQueued=$products->maybeEnqueueDynamicProfileEvolution($dynamicProfile['profile_id'],$session['playthrough_id'],$sessionId);
$dynamicJob=$db->prepare("SELECT job_id,state,payload FROM durable_jobs WHERE job_type='profile.generate' "
    ."AND payload->>'profile_id'=:profile AND payload->>'mode'='profile_evolution'");
$dynamicJob->execute(['profile'=>$dynamicProfile['profile_id']]);$dynamicJobRow=$dynamicJob->fetch();
$dynamicPayload=$dynamicJobRow?json_decode((string)$dynamicJobRow['payload'],true,64,JSON_THROW_ON_ERROR):[];
$assert(($dynamicQueued['queued']??false)===true&&$dynamicJobRow&&$dynamicJobRow['state']==='queued'
    &&($dynamicPayload['dynamic_fields']??null)===['personality','occupation','skills']
    &&count($dynamicPayload['source_turn_ids']??[])===2&&count($dynamicPayload['recent_events']??[])===2,
    'dynamic NPC profile evolution did not freeze its selected fields and NPC-limited witnessed history');
$dynamicHandlerPayload=$dynamicPayload;unset($dynamicHandlerPayload['provider_configuration_id'],$dynamicHandlerPayload['provider_revision']);
$dynamicHandlerPayload['_job']=['job_id'=>$dynamicJobRow['job_id'],'attempt'=>1];
(new \LorkhanServer\Application\ProfileGenerateJobHandler($products,
    new \LorkhanServer\Application\MockProfileGenerationProvider()))->handle($dynamicHandlerPayload,'profile-evolution-test',static fn():bool=>true);
$evolvedProfile=$products->getRevisioned('profile',$dynamicProfile['profile_id']);
$dynamicAgain=$products->maybeEnqueueDynamicProfileEvolution($dynamicProfile['profile_id'],$session['playthrough_id'],$sessionId);
$assert((int)$evolvedProfile['current_revision']===(int)$dynamicPayload['base_revision']+1
    &&($evolvedProfile['content']['personality']??'')!==($dynamicContent['personality']??'')
    &&($evolvedProfile['content']['occupation']??'')!==$dynamicContent['occupation']
    &&($evolvedProfile['content']['skills']??'')!==$dynamicContent['skills']
    &&($evolvedProfile['content']['speech_style']??null)==='Speech style must remain unchanged.'
    &&($evolvedProfile['content']['goals']??null)==='Goals must remain unchanged.'
    &&$dynamicAgain['queued']===false&&$dynamicAgain['reason']==='interval',
    'dynamic NPC evolution changed unselected fields or bypassed its 20-minute fence');

$narratorDynamicContent=$narratorProfile['content'];$narratorDynamicContent['dynamic_profile']=true;
$narratorDynamicContent['dynamic_profile_fields']=['goals'];$narratorDynamicContent['personality']='Narrator personality must remain unchanged.';
$narratorDynamic=$products->revise('profile',$narratorProfile['profile_id'],$narratorDynamicContent,'enable narrator evolution fixture',$now);
$inheritedHistoryCore=$products->getRevisioned('core_profile',$evolutionCore['core_profile_id']);
$inheritedHistoryContent=$inheritedHistoryCore['content'];$inheritedHistoryContent['settings_overrides']['profile_evolution']['history_limit']=0;
$inheritedHistoryContent['settings_overrides']['memory']['recent_turn_limit']=3;
$products->revise('core_profile',$evolutionCore['core_profile_id'],$inheritedHistoryContent,'zero inherits regular history fixture',$now);
$narratorEvolution=$products->maybeEnqueueDynamicProfileEvolution($narratorDynamic['profile_id'],$session['playthrough_id'],$sessionId);
$narratorEvolutionJob=$db->prepare("SELECT job_id,state,payload FROM durable_jobs WHERE job_type='profile.generate' "
    ."AND payload->>'profile_id'=:profile AND payload->>'mode'='narrator_profile_evolution'");
$narratorEvolutionJob->execute(['profile'=>$narratorDynamic['profile_id']]);$narratorEvolutionRow=$narratorEvolutionJob->fetch();
$narratorEvolutionPayload=$narratorEvolutionRow?json_decode((string)$narratorEvolutionRow['payload'],true,64,JSON_THROW_ON_ERROR):[];
$assert(count($narratorEvolutionPayload['source_turn_ids']??[])===3,'Narrator evolution did not inherit regular history for zero');
$assert(($narratorEvolution['queued']??false)===true&&$narratorEvolutionRow
    &&($narratorEvolutionPayload['dynamic_fields']??null)===['goals']
    &&count($narratorEvolutionPayload['recent_events']??[])===3,
    'dynamic narrator evolution did not freeze the shared witnessed history');
$narratorEvolutionHandler=$narratorEvolutionPayload;unset($narratorEvolutionHandler['provider_configuration_id'],$narratorEvolutionHandler['provider_revision']);
$narratorEvolutionHandler['_job']=['job_id'=>$narratorEvolutionRow['job_id'],'attempt'=>1];
(new \LorkhanServer\Application\ProfileGenerateJobHandler($products,
    new \LorkhanServer\Application\MockProfileGenerationProvider()))->handle($narratorEvolutionHandler,'narrator-evolution-test',static fn():bool=>true);
$evolvedNarrator=$products->getRevisioned('profile',$narratorDynamic['profile_id']);
$assert(($evolvedNarrator['content']['personality']??null)==='Narrator personality must remain unchanged.'
    &&($evolvedNarrator['content']['goals']??'')!==($narratorDynamicContent['goals']??''),
    'dynamic narrator evolution changed an unselected field or failed to evolve its selected field');

$creatureTemplate=$products->createRevisioned('profile',['installation_id'=>$installationId,
    'name'=>'Dagoth creature template','actor_identity'=>['kind'=>'template','record_id'=>'dagoth_creature_sentinel',
        'content_file'=>'Morrowind.esm'],'content'=>['biography'=>'Exact creature template biography.',
        'personality'=>'Offended by an Argonian Nerevarine.'],'change_reason'=>'fixture creature template'],gmdate('Y-m-d\TH:i:s\Z'));
$creatureTarget=['kind'=>'creature','record_id'=>'dagoth_creature_sentinel','refnum'=>['index'=>855,'content_file'=>0],
    'content_file'=>'Morrowind.esm','cell'=>['kind'=>'interior','name'=>'Dagoth Ur, Facility Cavern'],
    'display_name'=>'Dagoth Creature Sentinel'];
$creatureProfileData=$autoProfileData;$creatureProfileData['request_id']=$newUuid(855);
$creatureProfileData['payload']=['actor'=>$creatureTarget,'race'=>'Creature','class'=>'','gender'=>'none',
    'level'=>20,'disposition'=>0,'factions'=>[]];
[$creatureStatus]=$call($router,'POST',$base.'/gamedata',$headers($creatureProfileData['request_id']),[],$creatureProfileData);
$creatureControls=$controlsQuery;$creatureControls['message_id']=$newUuid(856);$creatureControls['request_id']=$newUuid(857);
$creatureControls['target']=$creatureTarget;
[$creatureControlsStatus,$creatureControlsBody]=$call($router,'POST',$base.'/controls/query',$jsonAuth,[],$creatureControls);
$creatureProfileId=$creatureControlsBody['selected_profile_id']??null;
$creatureProfile=is_string($creatureProfileId)?$products->getRevisioned('profile',$creatureProfileId):null;
$assert($creatureStatus===202&&$creatureControlsStatus===200&&is_array($creatureProfile)
    &&($creatureProfile['content']['biography']??null)==='Exact creature template biography.'
    &&($creatureProfile['content']['personality']??null)==='Offended by an Argonian Nerevarine.',
    'auto-activated creature did not materialize its exact profile template');

$automaticDiary=$fixture('gamedata-automatic-diary');
$automaticDiary['installation_id']=$installationId;$automaticDiary['playthrough_id']=$session['playthrough_id'];
$automaticDiary['session_id']=$sessionId;$automaticDiary['generation']=7;$automaticDiary['runtime_generation']=7;
$automaticDiary['request_id']=$newUuid(858);$automaticDiary['payload']['actors']=[];
[$automaticDiaryStatus,$automaticDiaryAccepted]=$call($router,'POST',$base.'/gamedata',
    $headers($automaticDiary['request_id']),[],$automaticDiary);
$automaticDiarySource=$db->prepare('SELECT event_kind FROM source_events WHERE source_event_id=:source');
$automaticDiarySource->execute(['source'=>$automaticDiary['request_id']]);
$assert($automaticDiaryStatus===202&&($automaticDiaryAccepted['type']??null)==='automatic_diary'
    &&$automaticDiarySource->fetchColumn()==='gamedata.automatic_diary',
    'typed automatic diary candidate was not accepted and recorded through the HTTP router');

$turnMoodTemplates=\LorkhanServer\Application\PlayerMoodPolicy::defaultTemplates();
$turnMoodTemplates['playful']='({PLAYER_NAME} answers in a {MOOD} voice.)';
$turnPrompt=$products->createRevisioned('prompt',['installation_id'=>$installationId,'name'=>'Turn mood prompt',
    'content'=>['instruction'=>'Stay grounded in Morrowind.','player_mood_prompts'=>$turnMoodTemplates]],$now);
$turnProfileContent=$actorProfile['content'];
$turnProfileContent['prompt_head']='NPC PROMPT HEAD SENTINEL';
$turnProfileContent['core']='NPC CORE IDENTITY SENTINEL';
$turnProfileContent['emote_moods']='NPC EMOTE MOODS SENTINEL';
$actorProfile=$products->revise('profile',$actorProfile['profile_id'],$turnProfileContent,'route integration mood prompt',$now);
$turnCore=$products->getRevisioned('core_profile',$actorCoreProfile['core_profile_id']);
$turnCoreContent=$turnCore['content'];$turnCoreContent['routing']['prompt_configuration_id']=$turnPrompt['configuration_id'];
$coreProfile=$products->revise('core_profile',$turnCore['core_profile_id'],$turnCoreContent,'route integration mood prompt',$now);
$captured=$fixture('gamedata-captured-dialogue');
$captured['installation_id']=$installationId;$captured['playthrough_id']=$session['playthrough_id'];
$captured['session_id']=$sessionId;$captured['generation']=7;$captured['runtime_generation']=7;
$captured['request_id']=$newUuid(850);$captured['payload']['speaker']=$controlsQuery['target'];
$captured['payload']['listener']=$fixture('turn')['payload']['speaker'];$captured['payload']['audience']=[];
$captured['payload']['text']='Ambient captured sentinel.';$captured['payload']['source']='background';
[$status,$capturedAccepted]=$call($router,'POST',$base.'/gamedata',$headers($captured['request_id']),[],$captured);
$assert($status===202&&($capturedAccepted['type']??null)==='captured_dialogue',
    'background vanilla dialogue was not accepted: '.json_encode(['status'=>$status,'body'=>$capturedAccepted]));
$menuCaptured=$captured;$menuCaptured['request_id']=$newUuid(851);$menuCaptured['payload']['source']='menu';
$menuCaptured['payload']['text']='Menu captured sentinel.';
[$status]=$call($router,'POST',$base.'/gamedata',$headers($menuCaptured['request_id']),[],$menuCaptured);
$assert($status===202,'menu vanilla dialogue was not accepted');
$capturedRows=$db->prepare("SELECT e.type,e.data,se.event_kind FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid "
    ."JOIN source_events se ON se.source_event_id=m.source_event_id WHERE m.source_event_id IN (:background,:menu) ORDER BY e.rowid");
$capturedRows->execute(['background'=>$captured['request_id'],'menu'=>$menuCaptured['request_id']]);
$capturedRows=$capturedRows->fetchAll();
$assert(array_column($capturedRows,'type')===['chat_background','chat']
    &&array_unique(array_column($capturedRows,'event_kind'))===['gamedata.captured_dialogue'],
    'captured dialogue did not project to CHIM-compatible event types: '.json_encode($capturedRows));
$turn = $fixture('turn');
$turn['session_id'] = $sessionId;
$turn['payload']['target']=$controlsQuery['target'];
$turn['payload']['input']['text'] = 'Please follow me.';
$turn['payload']['input']['mood']=['kind'=>'playful'];
// Turn-advertised capabilities cannot add a capability that was not negotiated; stored session policy is authoritative.
$turn['runtime']['capabilities'] = ['dialogue.text'];
[$status] = $call($router, 'POST', $base . '/turns', $jsonAuth, [], $turn);
$assert($status === 422, 'missing turn idempotency accepted');
[$status, $turnAccepted] = $call($router, 'POST', $base . '/turns', $headers($turn['message_id']), [], $turn);
$assert($status === 202 && $turnAccepted['event_cursor'] === 1, 'turn acceptance failed');
$snapshotStatement=$db->prepare('SELECT source_manifest FROM turn_provider_snapshots WHERE turn_id=:turn');
$snapshotStatement->execute(['turn'=>$turn['turn_id']]);
$snapshot=json_decode((string)$snapshotStatement->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$moodProjectionStatement=$db->prepare('SELECT e.data,m.payload,se.payload AS source_payload FROM eventlog e '
    .'JOIN eventlog_metadata m ON m.rowid=e.rowid JOIN source_events se ON se.source_event_id=m.source_event_id '
    .'WHERE m.turn_id=:turn AND e.type=\'inputtext\'');
$moodProjectionStatement->execute(['turn'=>$turn['turn_id']]);$moodProjection=$moodProjectionStatement->fetch();
$moodProjectionPayload=$moodProjection?json_decode((string)$moodProjection['payload'],true,64,JSON_THROW_ON_ERROR):[];
$moodSourcePayload=$moodProjection?json_decode((string)$moodProjection['source_payload'],true,64,JSON_THROW_ON_ERROR):[];
$traceStatement=$db->prepare('SELECT core_profile_id,core_profile_revision,effective_settings_sha256,settings_sources FROM prompt_traces WHERE turn_id=:turn');
$traceStatement->execute(['turn'=>$turn['turn_id']]);$layerTrace=$traceStatement->fetch();
$traceSources=$layerTrace?json_decode((string)$layerTrace['settings_sources'],true,64,JSON_THROW_ON_ERROR):[];
$promptSectionStatement=$db->prepare('SELECT section_order,section_key,inclusion_reason,source_refs,source_sha256 FROM prompt_trace_sections WHERE prompt_trace_id=(SELECT prompt_trace_id FROM prompt_traces WHERE turn_id=:turn) ORDER BY section_order');
$promptSectionStatement->execute(['turn'=>$turn['turn_id']]);$promptSections=$promptSectionStatement->fetchAll();
$memoryRetrievalStatement=$db->prepare("SELECT prompt_section,result_ids,reasons FROM retrieval_traces WHERE turn_id=:turn AND domain='memory'");
$memoryRetrievalStatement->execute(['turn'=>$turn['turn_id']]);$memoryRetrieval=$memoryRetrievalStatement->fetch();
$promptMessages=$snapshot['message']['_prompt']['_messages']??[];
$promptHistoryJson=json_encode($promptMessages,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$assert(count($snapshot['message']['_allowed_action_definitions']??[])===17
    &&str_contains((string)($promptMessages[0]['content']??''),'`conversation.end()`')
    &&str_contains((string)($promptMessages[0]['content']??''),
        '`ai.follow(distance: 192)` — Follow: Ask one actor to follow the player at the exact negotiated distance.')
    &&!str_contains((string)($promptMessages[0]['content']??''),'"const":192'),
    'accepted turn did not freeze the server-negotiated catalog contract before prompt assembly');
$assert(is_string($snapshot['message']['_prompt']['_assembled_prompt']??null)
    &&is_array($promptMessages)&&array_is_list($promptMessages)&&count($promptMessages)>=2
    &&($promptMessages[0]['role']??null)==='system'
    &&str_contains((string)($promptMessages[0]['content']??''),'# Roleplay Context')
    &&str_contains((string)($promptMessages[0]['content']??''),'- **Roleplay Instructions:**')
    &&str_contains((string)($promptMessages[0]['content']??''),'## NPC Context')
    &&str_contains((string)($promptMessages[0]['content']??''),'- **General Instructions:**')
    &&($snapshot['trace']['algorithm']??null)==='chim-compact-roleplay-prompt-v3-markdown'
    &&($promptMessages[array_key_last($promptMessages)]['role']??null)==='user'
    &&str_contains((string)($promptMessages[array_key_last($promptMessages)]['content']??''),'Please follow me. (Player answers in a playful voice.)')
    &&str_contains($promptHistoryJson,'[Background dialogue] Fargoth: Ambient captured sentinel.')
    &&str_contains($promptHistoryJson,'Menu captured sentinel.')
    &&($snapshot['trace']['player_mood_cue']??null)==='(Player answers in a playful voice.)'
    &&str_contains((string)($moodProjection['data']??''),'(Player answers in a playful voice.)')
    &&($moodProjectionPayload['input']['resolved_mood_cue']??null)==='(Player answers in a playful voice.)'
    &&!array_key_exists('resolved_mood_cue',$moodSourcePayload['payload']['input']??[])
    &&str_contains($snapshot['message']['_prompt']['_assembled_prompt'],'CORE PROFILE INSTRUCTION SENTINEL')
    &&str_contains($snapshot['message']['_prompt']['_assembled_prompt'],'NPC PROMPT HEAD SENTINEL')
    &&str_contains($snapshot['message']['_prompt']['_assembled_prompt'],'NPC CORE IDENTITY SENTINEL')
    &&str_contains($snapshot['message']['_prompt']['_assembled_prompt'],'NPC EMOTE MOODS SENTINEL')
    &&str_contains($snapshot['message']['_prompt']['_assembled_prompt'],'Dwemer scholar')
    &&($snapshot['message']['_selected_profile_id']??null)===$actorProfile['profile_id']
    &&($layerTrace['core_profile_id']??null)===$coreProfile['core_profile_id']
    &&(int)($layerTrace['core_profile_revision']??0)===(int)$coreProfile['current_revision']
    &&preg_match('/^[0-9a-f]{64}$/D',(string)($layerTrace['effective_settings_sha256']??''))===1
    &&($traceSources['settings.behavior.rechat']??null)==='core_profile'
    &&array_column($promptSections,'section_order')===range(1,11)
    &&array_column($promptSections,'section_key')===['output_contract','npc_context','player_narrator_context',
        'morrowind_context','oghma_context','relationships_factions','memory_context','conversation_context','audience_speaker_rules',
        'negotiated_actions','current_turn']
    &&count(array_filter($promptSections,static fn(array$row):bool=>preg_match('/^[0-9a-f]{64}$/D',(string)$row['source_sha256'])===1))===11
    &&($memoryRetrieval['prompt_section']??null)==='memory_context'
    &&($snapshot['message']['_provider_configuration']['configuration_id']??null)===$fastModelSlot['configuration_id'],
    'accepted turn did not freeze the layered Core Profile prompt, settings trace, and provider input for the worker');
$successfulWorkerStats=$runTurnWorker(new MockProvider());
$successfulJob=$db->query("SELECT state,last_error_code,last_error_detail FROM durable_jobs WHERE job_type='turn.process' ORDER BY created_at DESC LIMIT 1")->fetch();
$assert($successfulWorkerStats === ['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0],
    'successful turn job was not acknowledged: '.json_encode(['stats'=>$successfulWorkerStats,'job'=>$successfulJob]));
[$status, $turnDuplicate] = $call($router, 'POST', $base . '/turns', $headers($turn['message_id']), [], $turn);
$assert($status === 202 && $turnDuplicate == $turnAccepted, 'turn duplicate failed');
[$status] = $call($router, 'GET', $base . '/events', [], [
    'session_id' => $sessionId, 'generation' => '7', 'after' => '0', 'wait_ms' => '15001']);
$assert($status === 422, 'oversized event wait accepted');
[$status, $events] = $call($router, 'GET', $base . '/events', [], [
    'session_id' => $sessionId, 'generation' => '7', 'after' => '0', 'wait_ms' => '15000']);
$assert($status === 200 && array_column($events['events'], 'type') === ['turn.accepted','response.complete','dialogue.complete',
    'action.intent','turn.complete','speech.ready'], 'event order failed: '.json_encode(array_column($events['events'],'type')));
$canonicalStatement=$db->prepare('SELECT response_id,response_payload,runtime_generation FROM turns WHERE turn_id=:turn');
$canonicalStatement->execute(['turn'=>$turn['turn_id']]);$canonicalRow=$canonicalStatement->fetch();
$canonicalResponse=json_decode((string)$canonicalRow['response_payload'],true,64,JSON_THROW_ON_ERROR);
$canonicalLines=$canonicalResponse['lines']??[];
$canonicalDialogueLines=array_values(array_filter($canonicalLines,static fn(array $line):bool=>$line['action']==='say'));
$canonicalActionLines=array_values(array_filter($canonicalLines,static fn(array $line):bool=>$line['action']==='rolecommand'));
$projectedResponseEvents=array_values(array_filter($events['events'],
    static fn(array $event):bool=>in_array($event['type'],['dialogue.complete','action.intent'],true)));
$responseCompleteEvents=array_values(array_filter($events['events'],static fn(array $event):bool=>$event['type']==='response.complete'));
$assert($canonicalResponse['schema']==='lorkhan.response.v1'&&$canonicalResponse['response_id']===$canonicalRow['response_id']
    &&$canonicalResponse['installation_id']===$installationId&&$canonicalResponse['session_id']===$sessionId
    &&$canonicalResponse['turn_id']===$turn['turn_id']&&$canonicalResponse['request_id']===$turn['request_id']
    &&$canonicalResponse['generation']===7&&$canonicalResponse['runtime_generation']===$turn['runtime_generation']
    &&(int)$canonicalRow['runtime_generation']===$turn['runtime_generation']&&$canonicalResponse['ok']===true
    &&array_column($canonicalLines,'action')===['say','rolecommand']
    &&array_column($canonicalLines,'line_index')===[0,1]
    &&count($responseCompleteEvents)===1&&$responseCompleteEvents[0]['message_id']===$canonicalResponse['response_id']
    &&$responseCompleteEvents[0]['payload']===$canonicalResponse
    &&array_column($projectedResponseEvents,'message_id')===array_column($canonicalLines,'line_id'),
    'turn did not persist and project one fully correlated ordered canonical response');
$canonicalUtterance=$db->prepare('SELECT dialogue_message_id,response_line_id,utterance_id,runtime_generation FROM dialogue_utterances WHERE turn_id=:turn');
$canonicalUtterance->execute(['turn'=>$turn['turn_id']]);$canonicalUtteranceRow=$canonicalUtterance->fetch();
$canonicalLogs=$db->prepare("SELECT response_message_id,tag,payload FROM responselog WHERE turn_id=:turn AND tag='response.line' ORDER BY rowid");
$canonicalLogs->execute(['turn'=>$turn['turn_id']]);$canonicalLogRows=$canonicalLogs->fetchAll();
$assert($canonicalUtteranceRow['dialogue_message_id']===$canonicalDialogueLines[0]['line_id']
    &&$canonicalUtteranceRow['response_line_id']===$canonicalDialogueLines[0]['line_id']
    &&$canonicalUtteranceRow['utterance_id']===$canonicalDialogueLines[0]['utterance_id']
    &&(int)$canonicalUtteranceRow['runtime_generation']===$turn['runtime_generation']
    &&array_column($canonicalLogRows,'response_message_id')===array_column($canonicalLines,'line_id')
    &&array_column($canonicalLogRows,'tag')===['response.line','response.line']
    &&array_map(static fn(array $row):string=>json_decode((string)$row['payload'],true,64,JSON_THROW_ON_ERROR)['schema'],
        $canonicalLogRows)===['lorkhan.response.line.v1','lorkhan.response.line.v1'],
    'canonical response lines did not retain line, utterance, generation, and responselog identity');
$pollStarted = microtime(true);
[$status, $emptyPoll] = $call($router, 'GET', $base . '/events', [], [
    'session_id' => $sessionId, 'generation' => '7', 'after' => (string) $events['next_after'], 'wait_ms' => '50']);
$assert($status === 200 && $emptyPoll['events'] === [] && microtime(true) - $pollStarted >= 0.04, 'bounded event long poll returned early');
[$status, $futureCursor] = $call($router, 'GET', $base . '/events', [], [
    'session_id' => $sessionId, 'generation' => '7', 'after' => '999999']);
$assert($status === 409 && $futureCursor['code'] === 'cursor_expired', 'future event cursor was accepted');
foreach ($events['events'] as $event) {
    $assert($event['request_id'] === $turn['request_id'] && $event['turn_id'] === $turn['turn_id'], 'event identity missing');
}
$action = null;
$speech = null;
foreach ($events['events'] as $event) {
    if ($event['type'] === 'action.intent') $action = $event['payload'];
    if ($event['type'] === 'speech.ready') $speech = $event['payload'];
}
$assert(is_array($action), 'stored negotiated capability not used');
$dialogueEvents=array_values(array_filter($events['events'],static fn(array $e):bool=>$e['type']==='dialogue.complete'));$dialogueEvent=$dialogueEvents[0];
$assert(is_array($speech) && $speech['bytes'] === 204 && $speech['codec'] === 'wav'
    && $speech['dialogue_message_id'] === $dialogueEvents[0]['message_id'], 'mock speech descriptor missing');
$delivery=$fixture('dialogue-delivery-result');$delivery['message_id']=$newUuid(23);$delivery['request_id']=$turn['request_id'];
$delivery['dialogue_message_id']=$dialogueEvent['message_id'];$delivery['turn_id']=$turn['turn_id'];$delivery['session_id']=$sessionId;
$delivery['speaker']=$dialogueEvent['payload']['speaker'];$delivery['completed_at']=gmdate('Y-m-d\TH:i:s\Z');
[$status,$deliveryAccepted]=$call($router,'POST',$base.'/dialogue-delivery-results',$headers($delivery['message_id']),[],$delivery);
$assert($status===200&&!$deliveryAccepted['duplicate'],'dialogue delivery result failed');
$memoryJobStatement=$db->prepare("SELECT payload FROM durable_jobs WHERE job_type='memory.derive' AND idempotency_key=:key");
$memoryJobStatement->execute(['key'=>'memory:dialogue:'.$delivery['dialogue_message_id']]);
$memoryJobPayload=json_decode((string)$memoryJobStatement->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$assert(($memoryJobPayload['source_event_id']??null)===$delivery['message_id']
    &&($memoryJobPayload['provenance']['status']??null)==='played'
    &&($memoryJobPayload['tier']??null)==='recent',
    'played dialogue did not queue one delivery-fenced recent-memory derivation');
$memoryWorkerStats=$runWorker(['memory.derive']);
$deliveredMemoryStatement=$db->prepare('SELECT tier,content,source_event_id,current_revision,provenance FROM memory_records WHERE source_event_id=:source');
$deliveredMemoryStatement->execute(['source'=>$delivery['message_id']]);$deliveredMemory=$deliveredMemoryStatement->fetch();
$deliveredMemoryProvenance=$deliveredMemory?json_decode((string)$deliveredMemory['provenance'],true,32,JSON_THROW_ON_ERROR):[];
$assert($memoryWorkerStats['succeeded']===1&&($deliveredMemory['tier']??null)==='recent'
    &&str_contains((string)($deliveredMemory['content']??''),(string)$dialogueEvent['payload']['text'])
    &&($deliveredMemory['source_event_id']??null)===$delivery['message_id']
    &&(int)($deliveredMemory['current_revision']??0)===1
    &&($deliveredMemoryProvenance['status']??null)==='played',
    'delivery-fenced recent-memory worker did not persist the correlated revisioned source');
// Exercise the optional worker with a real played source; roll back only these synthetic fixtures.
$db->beginTransaction();
$powerState=$db->prepare("UPDATE turns SET context=jsonb_set(context,'{targetState}',CAST(:state AS jsonb),true) WHERE turn_id=:turn");
$powerState->execute(['state'=>'{"stats":{"level":20}}','turn'=>$turn['turn_id']]);
$powerProbe=$turn;$powerProbe['payload']['context']['nearbyActors']=[];
$powerProbe['payload']['context']['targetState']['stats']['level']=5;
$observedPower=$products->powerObservationsForTurn($powerProbe);
$assert(count($observedPower)===1&&$observedPower[0]['level']===5,'Current observed target level did not take precedence');
$powerProbe['payload']['context']['rechat']=[];
$observedPower=$products->powerObservationsForTurn($powerProbe);
$assert(count($observedPower)===1&&$observedPower[0]['level']===20,'Rerouted Rechat did not use exact recorded actor level');
$wrongPowerScope=$powerProbe;$wrongPowerScope['playthrough_id']=$newUuid(98001);
$assert($products->powerObservationsForTurn($wrongPowerScope)===[],'Power observations crossed playthrough scope');
$powerProbe['payload']['target']['refnum']['index']+=1000;
$assert($products->powerObservationsForTurn($powerProbe)===[],'Power observations crossed actor instance identity');
$db->rollBack();
$db->beginTransaction();
$relationships=new \LorkhanServer\Infrastructure\RelationshipEvaluationRepository($db);
$assert($relationships->enqueue($delivery['message_id'])===null,'default relationship policy launched work');
$relationshipGlobal=$products->globalSettingsForInstallation($installationId);
$relationshipContent=$relationshipGlobal['content'];
$relationshipContent['relationship']=['enabled'=>true,'update_chance_percent'=>100];
$relationshipContent['system_routing']['relationship_configuration_id']=$profileModelSlot['configuration_id'];
$relationshipGlobal=$products->revise('global_settings',$relationshipGlobal['configuration_id'],$relationshipContent,
    'enable relationship test',$now);
$relationshipOwner=$relationships->policy($installationId,$actorProfile['profile_id']);
$relationshipCore=$products->getRevisioned('core_profile',$relationshipOwner['core_profile_id']);
$relationshipChance=$relationshipCore['content'];$relationshipChance['settings_overrides']['relationship']=['enabled'=>true,'update_chance_percent'=>37];
$products->revise('core_profile',$relationshipCore['core_profile_id'],$relationshipChance,'Core relationship chance fixture',$now);
$assert($relationships->policy($installationId,$actorProfile['profile_id'])['update_chance_percent']===37,'Core relationship chance did not reach queued-job policy');
$relationshipNpc=$products->getRevisioned('profile',$actorProfile['profile_id']);
$relationshipNpcChance=$relationshipNpc['content'];$relationshipNpcChance['settings_overrides']['relationship']=['update_chance_percent'=>0];
$products->revise('profile',$actorProfile['profile_id'],$relationshipNpcChance,'NPC relationship chance fixture',$now);
$assert($relationships->policy($installationId,$actorProfile['profile_id'])['update_chance_percent']===0
    &&$relationships->enqueue($delivery['message_id'])===null,'NPC zero chance still queued a relationship job');
$products->revise('profile',$actorProfile['profile_id'],$relationshipNpc['content'],'Restore NPC relationship inheritance',$now);
$assert($relationships->policy($installationId,$actorProfile['profile_id'])['update_chance_percent']===37,'Removing NPC override failed to inherit Core chance');
$relationshipOff=$relationshipCore['content'];$relationshipOff['settings_overrides']['relationship']['enabled']=false;
$products->revise('core_profile',$relationshipCore['core_profile_id'],$relationshipOff,'Core relationship off fixture',$now);
$assert($relationships->enqueue($delivery['message_id'])===null,'Core relationship off still queued an evaluation');
$products->revise('core_profile',$relationshipCore['core_profile_id'],$relationshipCore['content'],'Restore Core relationship inheritance',$now);
$relationshipJob=$relationships->enqueue($delivery['message_id']);
$assert(is_array($relationshipJob),'eligible played response did not queue relationship evaluation');
$relationshipProvider=new class implements \LorkhanServer\Application\ProfileGenerationProvider {
    public int $calls=0;
    public mixed $during=null;
    public function generate(array $input,\LorkhanServer\Application\CancellationToken $cancellation):array{
        ++$this->calls;$cancellation->throwIfCancellationRequested();
        if(($input['generation_mode']??'')!=='relationship_evaluation'||!isset($input['played_reply'],$input['interlocutor'])
            ||($input['relationship_type']??null)!=='neutral'
            ||!in_array('romantic',$input['available_relationship_types']??[],true))
            throw new RuntimeException('relationship input missing');
        if($this->during!==null)($this->during)();
        return ['disposition_delta'=>4,'affinity_delta'=>2,'relationship_type'=>'romantic',
            'reason'=>'A friendly played exchange.'];
    }
};
$relationshipRegistry=new \LorkhanServer\Application\JobHandlerRegistry([new \LorkhanServer\Application\RelationshipEvaluateJobHandler(
    $relationships,$products,new \LorkhanServer\Infrastructure\ProviderAttemptRepository($db),[],$relationshipProvider)]);
$relationshipWorker=static fn()=> (new \LorkhanServer\Application\Worker(new \LorkhanServer\Infrastructure\JobRepository($db),
    $relationshipRegistry,'relationship-integration',5,1,1,0,10,['relationship.evaluate']))->run();
$db->exec('SAVEPOINT relationship_queued');
$products->revise('provider',$profileModelSlot['configuration_id'],
    array_replace($profileModelSlot['content'],['model'=>'later-model']), 'provider changed after enqueue',$now);
$relationshipStats=$relationshipWorker();
$relationshipReceipt=$db->query('SELECT * FROM relationship_evaluation_results')->fetch();
$relationshipLog=(new \LorkhanServer\Infrastructure\RelationshipLogRepository($db))->page($installationId);
$assert(count($relationshipLog['rows'])===1&&(int)$relationshipLog['rows'][0]['affinity_delta']===2
    &&$relationshipLog['rows'][0]['context']!==''&&$relationshipLog['rows'][0]['type']==='eval_',
    'relationship log did not map committed evaluation and retained source context');
$assert(($relationshipLog['rows'][0]['changes'][0]['type']??null)==='neutral'
    &&(json_decode($relationshipLog['rows'][0]['proposal'],true)['relationship_type']??null)==='romantic'
    &&$relationshipLog['rows'][0]['request']!==''&&str_contains($relationshipLog['rows'][0]['context_note'],'typed'),
    'relationship log confused rejected model type with applied state or lost recorded input');
$assert($relationshipStats['succeeded']===1&&$relationshipProvider->calls===1&&$relationshipReceipt
    &&(int)$relationshipReceipt['disposition_delta']===4&&(int)$relationshipReceipt['affinity_delta']===2,
    'played relationship worker did not persist one bounded result under the global policy');
$assert($products->relationships(['installation_id'=>$installationId,'profile_id'=>$actorProfile['profile_id'],
    'playthrough_id'=>$session['playthrough_id']])[0]['relationship_type']==='neutral',
    'low-affinity romantic proposal changed type or blocked safe score deltas');
$assert($relationships->enqueue($delivery['message_id'])['job_id']===$relationshipJob['job_id']
    &&$relationshipWorker()['claimed']===0,'duplicate delivery reapplied relationship evaluation');
$assert((int)$db->query("SELECT config_revision FROM provider_attempts WHERE operation='evaluate_relationship'")->fetchColumn()===1,
    'queued relationship job did not keep its frozen provider revision');
$db->exec('SAVEPOINT prefill_relationship_audit');
$prefillAuditId='00000000-0000-4000-8000-000000000991';
(new ProviderAttemptRepository($db))->start($prefillAuditId,'llm','mock','evaluate_relationship',1);
$prefillAuditMessages=[['role'=>'system','content'=>'Fixture contract'],['role'=>'user','content'=>'Fixture exchange'],
    ['role'=>'assistant','content'=>'{"disposition_delta":']];
(new ProviderAttemptRepository($db))->recordRelationshipRequest($prefillAuditId,$prefillAuditMessages);
$prefillAuditQuery=$db->prepare('SELECT metadata FROM provider_attempts WHERE provider_attempt_id=:id');
$prefillAuditQuery->execute(['id'=>$prefillAuditId]);
$prefillAuditMetadata=json_decode($prefillAuditQuery->fetchColumn(),true);
$assert($prefillAuditMetadata['relationship_request']['messages']===$prefillAuditMessages,
    'relationship request audit discarded the actual assistant continuation prefix');
$prefillAuditMessages[2]['content']='Unbounded assistant prompt';
try{(new ProviderAttemptRepository($db))->recordRelationshipRequest($prefillAuditId,$prefillAuditMessages);
    throw new RuntimeException('relationship audit accepted an unrelated assistant message');}
catch(InvalidArgumentException $error){$assert($error->getMessage()==='invalid_relationship_log_request','unexpected assistant audit error');}
$db->exec('ROLLBACK TO SAVEPOINT prefill_relationship_audit');
$db->prepare("UPDATE durable_jobs SET state='queued',completed_at=NULL,next_run_at=clock_timestamp() WHERE job_id=:id")
    ->execute(['id'=>$relationshipJob['job_id']]);
$assert($relationshipWorker()['succeeded']===1&&$relationshipProvider->calls===1
    &&(int)$db->query('SELECT count(*) FROM relationship_evaluation_results')->fetchColumn()===1,
    'a retried job reapplied an already committed relationship receipt');
$db->exec('SAVEPOINT relationship_downgrade');
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/data/migrations/064_relationship_evaluation_results.down.sql'));
    throw new RuntimeException('relationship downgrade discarded receipts');}
catch(PDOException $error){$assert(str_contains($error->getMessage(),'Cannot remove relationship evaluation'),
    'unexpected relationship downgrade error');$db->exec('ROLLBACK TO SAVEPOINT relationship_downgrade');}
$db->exec('ROLLBACK TO SAVEPOINT relationship_queued');
$disabledRelationshipContent=$relationshipContent;$disabledRelationshipContent['relationship']['enabled']=false;
$products->revise('global_settings',$relationshipGlobal['configuration_id'],$disabledRelationshipContent,'disable queued relationship',$now);
$assert($relationshipWorker()['succeeded']===1&&$relationshipProvider->calls===1,
    'global relationship disable failed to cancel queued provider work');
$db->exec('ROLLBACK TO SAVEPOINT relationship_queued');
$relationshipProvider->during=static function()use($products,$relationshipGlobal,$disabledRelationshipContent,$now):void{
    $products->revise('global_settings',$relationshipGlobal['configuration_id'],$disabledRelationshipContent,
        'disable during provider call',$now);
};
$assert($relationshipWorker()['succeeded']===1&&$relationshipProvider->calls===2
    &&(int)$db->query('SELECT count(*) FROM relationship_evaluation_results')->fetchColumn()===0,
    'late relationship output survived a Global Settings policy revision');
$cancelledLog=(new \LorkhanServer\Infrastructure\RelationshipLogRepository($db))->page($installationId);
$assert($cancelledLog['rows'][0]['state']==='cancelled'&&$cancelledLog['rows'][0]['changes']===null,
    'cancelled relationship proposal was shown as an applied change');
$db->exec('ROLLBACK TO SAVEPOINT relationship_queued');
$relationshipProvider->during=static function()use($products,$actorProfile,$installationId,$session,$turn,$now):void{
    $record=$products->setRelationship(['installation_id'=>$installationId,'profile_id'=>$actorProfile['profile_id'],
        'playthrough_id'=>$session['playthrough_id'],'actor_identity'=>$turn['payload']['speaker'],
        'disposition'=>50,'affinity'=>30,'source_mode'=>'manual','reason'=>'Manual create during evaluation'],$now);
    $products->deleteRelationship($record['relationship_id'],$now,1);
};
$assert($relationshipWorker()['succeeded']===1&&$relationshipProvider->calls===3
    &&(int)$db->query('SELECT count(*) FROM relationship_evaluation_results')->fetchColumn()===0,
    'manual create/delete did not invalidate absent relationship snapshot');
$db->exec('ROLLBACK TO SAVEPOINT relationship_queued');$relationshipProvider->during=null;
$db->prepare("UPDATE eventlog_metadata SET suppressed_at=clock_timestamp() WHERE projection_key=:key")
    ->execute(['key'=>'turn:'.$turn['turn_id']]);
$assert($relationshipWorker()['succeeded']===1&&$relationshipProvider->calls===3,
    'hidden source was sent to relationship provider');
$db->exec('ROLLBACK TO SAVEPOINT relationship_queued');
$db->prepare("UPDATE sessions SET state='ended',ended_at=clock_timestamp() WHERE session_id=:session")
    ->execute(['session'=>$sessionId]);
$assert($relationshipWorker()['succeeded']===1&&$relationshipProvider->calls===3,
    'ended session reached relationship provider');
$db->rollBack();
// A manual build spans recent history, works offline at chance zero, and applies one atomic score set.
$db->beginTransaction();
$historyGlobal=$products->globalSettingsForInstallation($installationId);$historyContent=$historyGlobal['content'];
$historyContent['relationship']=['enabled'=>true,'update_chance_percent'=>0];
$historyContent['system_routing']['relationship_configuration_id']=$profileModelSlot['configuration_id'];
$products->revise('global_settings',$historyGlobal['configuration_id'],$historyContent,'manual history fixture',$now);
$historyTurn=$turn;$historyTurn['message_id']=$newUuid(5700);$historyTurn['turn_id']=$newUuid(5701);$historyTurn['request_id']=$newUuid(5702);
$historyTurn['payload']['speaker']=$speechTarget;$historyTurn['payload']['input']['text']='Thank you for keeping your promise.';
[$historyStatus]=$call($router,'POST',$base.'/turns',$headers($historyTurn['message_id']),[],$historyTurn);
$assert($historyStatus===202&&$runTurnWorker(new MockProvider())['succeeded']===1,'second historical turn failed');
$historyDialogue=$db->query("SELECT dialogue_message_id,speaker FROM dialogue_utterances WHERE turn_id='{$historyTurn['turn_id']}'")->fetch();
$historyDelivery=$delivery;$historyDelivery['message_id']=$newUuid(5703);$historyDelivery['request_id']=$historyTurn['request_id'];
$historyDelivery['turn_id']=$historyTurn['turn_id'];$historyDelivery['dialogue_message_id']=$historyDialogue['dialogue_message_id'];
$historyDelivery['speaker']=json_decode($historyDialogue['speaker'],true,32,JSON_THROW_ON_ERROR);
// This is a new playback receipt, not the first reply's earlier completion time.
$historyDelivery['completed_at']=gmdate('Y-m-d\TH:i:s\Z');
[$historyStatus,$historyAck]=$call($router,'POST',$base.'/dialogue-delivery-results',$headers($historyDelivery['message_id']),[],$historyDelivery);
$assert($historyStatus===200,'second historical delivery failed: '.json_encode([$historyStatus,$historyAck]));
$db->prepare("UPDATE sessions SET state='ended',ended_at=clock_timestamp() WHERE session_id=:session")->execute(['session'=>$sessionId]);
$builds=new \LorkhanServer\Infrastructure\RelationshipBuildRepository($db);
$buildScope=['installation_id'=>$installationId,'profile_id'=>$actorProfile['profile_id'],'playthrough_id'=>$session['playthrough_id']];
$privateBuildNote='PLAYER-ONLY CUSTOM INFO';
$products->setRelationship($buildScope+['actor_identity'=>$turn['payload']['speaker'],
    'disposition'=>0,'affinity'=>0,'source_mode'=>'manual','custom_info'=>$privateBuildNote],$now);
$omittedIdentity=$speechTarget;$omittedIdentity['refnum']['index']+=321;
$products->setRelationship($buildScope+['actor_identity'=>$omittedIdentity,
    'disposition'=>11,'affinity'=>12,'source_mode'=>'manual','custom_info'=>$privateBuildNote],$now);
$buildDirection='Focus on House hierarchy.';
$db->exec('SAVEPOINT preview_enqueue');
$queuedPreview=$builds->enqueue($buildScope,$newUuid(5790),100,$buildDirection,true);
$previewPayloadQuery=$db->prepare('SELECT payload FROM durable_jobs WHERE job_id=:id');$previewPayloadQuery->execute(['id'=>$queuedPreview['job_id']]);
$previewPayload=json_decode($previewPayloadQuery->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$assert($previewPayload['preview']===true&&$previewPayload['profile_revision']>0,'enqueue lost draft mode or owner revision');
$db->exec('ROLLBACK TO SAVEPOINT preview_enqueue');
$buildRequest=$newUuid(5704);$buildJob=$builds->enqueue($buildScope,$buildRequest,100,$buildDirection);
$assert($builds->enqueue($buildScope,$buildRequest,100,' '.$buildDirection.' ')['job_id']===$buildJob['job_id'],'manual build request was not idempotent');
try{$builds->enqueue($buildScope,$buildRequest,100,'Different direction');throw new RuntimeException('build direction changed on retry');}
catch(InvalidArgumentException $error){$assert($error->getMessage()==='relationship_build_request_conflict','unexpected build direction conflict');}
try{$builds->enqueue($buildScope,$newUuid(5705));throw new RuntimeException('parallel history build accepted');}
catch(InvalidArgumentException $error){$assert($error->getMessage()==='relationship_build_pending','unexpected pending-build error');}
$buildProvider=new class implements \LorkhanServer\Application\ProfileGenerationProvider {
    public int $calls=0;public mixed $during=null;public bool $unknownTarget=false;
    public function generate(array $input,\LorkhanServer\Application\CancellationToken $cancellation):array{
        ++$this->calls;$cancellation->throwIfCancellationRequested();
        if(($input['user_direction']??null)!=='Focus on House hierarchy.')throw new RuntimeException('player direction did not reach relationship model');
        if(count($input['exchanges'])!==2||count($input['interlocutors'])!==2
            ||!in_array('professional',$input['available_relationship_types']??[],true))throw new RuntimeException('history was reduced to one exchange or target');
        if(str_contains(json_encode($input,JSON_THROW_ON_ERROR),'PLAYER-ONLY CUSTOM INFO'))throw new RuntimeException('private relationship text reached AI');
        if($this->during!==null)($this->during)();
        $result=['relationships'=>array_map(static fn(array $target):array=>['target_key'=>$target['target_key'],
            'disposition'=>35,'affinity'=>20,'relationship_type'=>'professional',
            'reason'=>'A pattern of kept promises.'],$input['interlocutors'])];
        if($this->unknownTarget)$result['relationships'][1]['target_key']=str_repeat('f',64);
        return $result;
    }
};
$buildRegistry=new \LorkhanServer\Application\JobHandlerRegistry([new \LorkhanServer\Application\RelationshipBuildJobHandler(
    $builds,$products,new \LorkhanServer\Infrastructure\ProviderAttemptRepository($db),[],$buildProvider)]);
$buildWorker=static fn()=> (new \LorkhanServer\Application\Worker(new \LorkhanServer\Infrastructure\JobRepository($db),
    $buildRegistry,'relationship-build-integration',5,1,1,0,10,['relationship.build']))->run();
// Preview builds retain scores for review without modifying saved relationships.
$db->exec('SAVEPOINT relationship_preview');
$beforePreview=$products->exportScope($buildScope)['relationships'];
$db->prepare("UPDATE durable_jobs SET payload=jsonb_set(payload,'{preview}','true'::jsonb) WHERE job_id=:id")->execute(['id'=>$buildJob['job_id']]);
$assert($builds->enqueue($buildScope,$buildRequest,100,$buildDirection,true)['job_id']===$buildJob['job_id'],'preview retry lost its request');
try{$builds->enqueue($buildScope,$buildRequest,100,$buildDirection);throw new RuntimeException('preview mode changed on retry');}
catch(InvalidArgumentException $error){$assert($error->getMessage()==='relationship_build_request_conflict','unexpected preview mode conflict');}
$assert($builds->previewStatus($buildScope,$buildJob['job_id'])['state']==='queued','preview polling did not report queued');
$assert($buildWorker()['succeeded']===1,'relationship preview worker failed');
$preview=$builds->draft($buildScope,$buildJob['job_id']);
$assert($preview!==null&&count($preview['relationships'])===2&&$preview['profile_revision']>0
    &&$products->exportScope($buildScope)['relationships']===$beforePreview
    &&!str_contains(json_encode($preview),$privateBuildNote),'preview changed saved scores or copied private notes');
$assert($builds->previewStatus($buildScope,$buildJob['job_id'])['state']==='ready','preview polling did not report ready');
// The initial relationship editor is bounded; generated targets must still load their exact saved row.
$db->exec('SAVEPOINT preview_outside_window');
for($reviewIndex=0;$reviewIndex<100;$reviewIndex++){
    $reviewIdentity=$omittedIdentity;$reviewIdentity['refnum']['index']+=10000+$reviewIndex;
    $products->setRelationship($buildScope+['actor_identity'=>$reviewIdentity,'affinity'=>1,'disposition'=>1,'source_mode'=>'manual'], '2035-01-01T00:00:00Z');
}
$reviewUi=new \LorkhanServer\Infrastructure\ManagementUiRepository($db);
$initialReviewRows=$reviewUi->rows('relationships',$installationId,$buildScope);
$expectedReviewIds=array_values(array_column($preview['relationships'],'relationship_id'));
$reviewStatus=$builds->previewStatus($buildScope,$buildJob['job_id']);
$assert(count($initialReviewRows)===100&&array_intersect(array_column($initialReviewRows,'relationship_id'),$expectedReviewIds)===[]
    &&$reviewStatus['state']==='ready'&&array_column($reviewStatus['editor_rows'],'relationship_id')===$expectedReviewIds
    &&$reviewStatus['editor_rows'][0]['custom_info']===$privateBuildNote,'preview failed to load a saved target beyond the editor window');
$assert($reviewUi->rows('relationships',$installationId,array_replace($buildScope,['profile_id'=>$newUuid(5791),'relationship_ids'=>$expectedReviewIds]))===[],
    'targeted relationship load escaped the NPC scope');
$assert(!str_contains(json_encode($builds->draft($buildScope,$buildJob['job_id'])),$privateBuildNote),'editor-only private notes leaked into the proposal receipt');
$db->exec('ROLLBACK TO SAVEPOINT preview_outside_window');

foreach(['profile','source','relationship'] as $staleCase){
    $db->exec('SAVEPOINT preview_stale');
    if($staleCase==='profile'){
        $profileBefore=$products->getRevisioned('profile',$buildScope['profile_id']);
        $products->revise('profile',$buildScope['profile_id'],$profileBefore['content'],'Concurrent editor save',$now);
    }elseif($staleCase==='source')$db->prepare('UPDATE eventlog_metadata SET suppressed_at=clock_timestamp() WHERE projection_key=:key')
        ->execute(['key'=>'dialogue:'.$historyDelivery['dialogue_message_id']]);
    else $db->prepare('UPDATE relationship_records SET affinity=affinity+1 WHERE profile_id=:id')->execute(['id'=>$buildScope['profile_id']]);
    $assert($builds->draft($buildScope,$buildJob['job_id'])===null
        &&$builds->previewStatus($buildScope,$buildJob['job_id'])===['job_id'=>$buildJob['job_id'],'state'=>'stale'],
        'preview exposed a stale '.$staleCase.' result');
    $db->exec('ROLLBACK TO SAVEPOINT preview_stale');
}
// Exercise the same revisioned Save handler used by the NPC header, with one existing and one new target.
$db->exec('SAVEPOINT preview_editor_save');
$previewBatch=['profile_revision'=>$preview['profile_revision'],'playthrough_id'=>$buildScope['playthrough_id'],'updates'=>[],'additions'=>[],'deletes'=>[]];
foreach($preview['relationships'] as $candidate){
    $edit=array_intersect_key($candidate,array_flip(['relationship_id','expected_revision','affinity','disposition','relationship_type','reason']));
    $edit+=['preview_job_id'=>$buildJob['job_id'],'preview_target_key'=>$candidate['target_key']];
    $previewBatch[isset($candidate['relationship_id'])?'updates':'additions'][]=$edit;
}
$previewManager=new \LorkhanServer\Http\ManagementRouter($presetStore,$products,$biographyService);
$previewSave=new ReflectionMethod($previewManager,'reviseNpcProfile');
$previewForm=['profile_id'=>$buildScope['profile_id'],'voice_id'=>'','change_reason'=>'Review generated relationships',
    'base_content_json'=>json_encode($products->getRevisioned('profile',$buildScope['profile_id'])['content']),
    'npc_settings_overrides_json'=>'{"response":{"max_words":23}}','npc_relationship_edits'=>json_encode($previewBatch)];
$forgedBatch=$previewBatch;$forgedBatch['additions'][0]['_preview_identity']=['kind'=>'player'];
try{$previewSave->invoke($previewManager,array_replace($previewForm,['npc_relationship_edits'=>json_encode($forgedBatch)]));throw new RuntimeException('browser injected a preview identity');}
catch(InvalidArgumentException $error){$assert($error->getMessage()==='invalid_relationship_batch','unexpected forged identity error');}
$previewSaved=$previewSave->invoke($previewManager,$previewForm);
$reviewedRelationships=$products->exportScope($buildScope)['relationships'];
$assert(count($previewBatch['updates'])===1&&count($previewBatch['additions'])===1
    &&count(array_filter($reviewedRelationships,static fn(array $row):bool=>$row['relationship_type']==='professional'))===2
    &&count(array_filter($reviewedRelationships,static fn(array $row):bool=>$row['custom_info']===$privateBuildNote))===2
    &&$previewSaved['content']['settings_overrides']['response']['max_words']===23,'review Save did not persist both targets and override while preserving private notes');
$db->exec('ROLLBACK TO SAVEPOINT preview_editor_save');
$previewStatus=$builds->recentJobs($buildScope)[0];
$assert($previewStatus['outcome']==='draft_ready'&&(int)$previewStatus['changed_count']===0&&(int)$previewStatus['draft_count']===2,'preview status claims committed writes');
foreach(['installation_id','profile_id','playthrough_id'] as $previewScopeField)
    $assert($builds->draft(array_replace($buildScope,[$previewScopeField=>$newUuid(5780)]),$buildJob['job_id'])===null,'preview escaped scope');
$db->prepare("UPDATE durable_jobs SET state='queued',completed_at=NULL,next_run_at=clock_timestamp() WHERE job_id=:id")->execute(['id'=>$buildJob['job_id']]);
$assert($buildWorker()['succeeded']===1&&$buildProvider->calls===1&&$builds->draft($buildScope,$buildJob['job_id'])===$preview,'preview reran on retry');
$db->exec('SAVEPOINT preview_downgrade');
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/data/migrations/095_relationship_build_drafts.down.sql'));throw new RuntimeException('draft was discarded on downgrade');}
catch(PDOException $error){$assert(str_contains($error->getMessage(),'Review and remove relationship build drafts'),'unexpected preview downgrade error');$db->exec('ROLLBACK TO SAVEPOINT preview_downgrade');}
$db->exec('ROLLBACK TO SAVEPOINT relationship_preview');$buildProvider->calls=0;
$db->exec('SAVEPOINT history_queued');
$assert($buildWorker()['succeeded']===1&&$buildProvider->calls===1,'offline history build did not run at chance zero');
$buildReceipt=$db->query('SELECT source_count,target_count,changed_count FROM relationship_build_results')->fetch();
$buildLog=(new \LorkhanServer\Infrastructure\RelationshipLogRepository($db))->page($installationId,'analyze_');
$assert(count($buildLog['rows'])===1&&(int)$buildLog['rows'][0]['changed_count']===2
    &&$buildLog['rows'][0]['context']!==''&&!str_contains(json_encode($buildLog),$privateBuildNote),
    'relationship build log lost its receipt/context or exposed private Custom Info');
$assert(count($buildLog['rows'][0]['changes']??[])===2
    &&count(array_filter($buildLog['rows'][0]['changes'],static fn(array $change):bool=>$change['old_type']==='neutral'&&$change['type']==='professional'))===2,
    'relationship build log lost per-target committed type transitions');
$db->exec('SAVEPOINT relationship_log_visibility');
$db->prepare('UPDATE eventlog_metadata SET suppressed_at=clock_timestamp() WHERE projection_key=:key')
    ->execute(['key'=>'dialogue:'.$historyDelivery['dialogue_message_id']]);
$hiddenBuildLog=(new \LorkhanServer\Infrastructure\RelationshipLogRepository($db))->page($installationId,'analyze_');
$assert($hiddenBuildLog['rows'][0]['request']===''&&$hiddenBuildLog['rows'][0]['context']===''&&$hiddenBuildLog['rows'][0]['proposal']==='',
    'frozen relationship request resurrected suppressed source conversation');
$db->exec('ROLLBACK TO SAVEPOINT relationship_log_visibility');
$assert($buildReceipt&&array_map('intval',array_values($buildReceipt))===[2,2,2], 'history build did not atomically update both known targets');
$privateRows=$products->exportScope($buildScope)['relationships'];
$assert(count(array_filter($privateRows,static fn(array $row):bool=>$row['custom_info']===$privateBuildNote))===2,
    'history build changed Custom Info on a selected or omitted relationship');
$assert(count(array_filter($privateRows,static fn(array $row):bool=>$row['relationship_type']==='professional'))===2,
    'history build did not apply an allowlisted relationship type only to returned targets');
$buildStatus=$builds->recentJobs($buildScope);
$assert(count($buildStatus)===1&&$buildStatus[0]['outcome']==='succeeded'&&(int)$buildStatus[0]['changed_count']===2
    &&$builds->recentJobs(array_replace($buildScope,['profile_id'=>$newUuid(5706)]))===[], 'history build status escaped its scope');
$assert($builds->enqueue($buildScope,$buildRequest,100,$buildDirection)['job_id']===$buildJob['job_id']&&$buildWorker()['claimed']===0,
    'completed history request was reapplied');
$db->prepare("UPDATE durable_jobs SET state='queued',completed_at=NULL,next_run_at=clock_timestamp() WHERE job_id=:id")
    ->execute(['id'=>$buildJob['job_id']]);
$assert($buildWorker()['succeeded']===1&&$buildProvider->calls===1,'committed history receipt was reapplied on retry');
$db->exec('SAVEPOINT history_downgrade');
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/data/migrations/065_relationship_build_results.down.sql'));
    throw new RuntimeException('history downgrade discarded receipts');}
catch(PDOException $error){$assert(str_contains($error->getMessage(),'Cannot remove relationship build'),'unexpected history downgrade error');
    $db->exec('ROLLBACK TO SAVEPOINT history_downgrade');}
$db->exec('ROLLBACK TO SAVEPOINT history_queued');
$buildProvider->during=static function()use($db,$historyDelivery):void{
    $db->prepare('UPDATE eventlog_metadata SET suppressed_at=clock_timestamp() WHERE projection_key=:key')
        ->execute(['key'=>'dialogue:'.$historyDelivery['dialogue_message_id']]);
};
$assert($buildWorker()['succeeded']===1&&$buildProvider->calls===2
    &&(int)$db->query('SELECT count(*) FROM relationship_build_results')->fetchColumn()===0,
    'history suppressed during provider I/O still changed relationships');
$assert($builds->recentJobs($buildScope)[0]['outcome']==='stale','cancelled build was reported as an applied success');
$db->exec('ROLLBACK TO SAVEPOINT history_queued');
$buildProvider->during=static function()use($products,$buildScope,$speechTarget,$now):void{
    $created=$products->setRelationship($buildScope+['actor_identity'=>$speechTarget,'disposition'=>90,'affinity'=>80,
        'source_mode'=>'manual','reason'=>'User owns the newer edit'],$now);
    $products->deleteRelationship($created['relationship_id'],$now,(int)$created['revision']);
};
$assert($buildWorker()['succeeded']===1&&$buildProvider->calls===3
    &&(int)$db->query('SELECT count(*) FROM relationship_build_results')->fetchColumn()===0,
    'manual create/delete during history build did not cancel the whole result');
$db->exec('ROLLBACK TO SAVEPOINT history_queued');
$buildProvider->during=static function()use($db,$sessionId):void{
    $db->prepare("UPDATE sessions SET state='active',ended_at=NULL WHERE session_id=:id")->execute(['id'=>$sessionId]);
};
$assert($buildWorker()['succeeded']===1&&$buildProvider->calls===4
    &&(int)$db->query('SELECT count(*) FROM relationship_build_results')->fetchColumn()===0,
    'session lifecycle change during history build did not cancel the result');
$db->exec('ROLLBACK TO SAVEPOINT history_queued');
$buildProvider->during=null;$buildProvider->unknownTarget=true;
$beforeRecords=$products->relationships($buildScope);$buildWorker();
$assert($products->relationships($buildScope)===$beforeRecords
    &&(int)$db->query('SELECT count(*) FROM relationship_build_results')->fetchColumn()===0,
    'an invented target allowed a partial history update');
$db->rollBack();
// Explicit profile-text conversion is OpenMW-native, per-owner, retry-safe, and never exposes Custom Info.
$db->beginTransaction();
$conversionNpcIdentity=['kind'=>'npc','record_id'=>'conversion_friend','display_name'=>'Conversion Friend',
    'content_file'=>'Morrowind.esm','refnum'=>['index'=>61001,'content_file'=>0]];
$conversionOmittedIdentity=['kind'=>'creature','record_id'=>'conversion_omitted','display_name'=>'Conversion Omitted',
    'content_file'=>'Morrowind.esm','refnum'=>['index'=>61002,'content_file'=>0]];
$conversionTarget=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Conversion Friend',
    'actor_identity'=>$conversionNpcIdentity,'content'=>['biography'=>'Known conversion target.']],$now);
$conversionOmitted=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Conversion Omitted',
    'actor_identity'=>$conversionOmittedIdentity,'content'=>['biography'=>'Known omitted target.']],$now);
$conversionPlayer=$products->getRevisioned('profile',(string)$playerProfile['profile_id']);
$conversionText='Conversion Friend is a trusted ally. Conversion Omitted is still distrusted. '
    .(string)$conversionPlayer['name'].' remains a complicated acquaintance. Unknown Conversion Stranger is irrelevant.';
$conversionOwnerIdentity=['kind'=>'npc','record_id'=>'conversion_owner','display_name'=>'Conversion Owner',
    'content_file'=>'Morrowind.esm','refnum'=>['index'=>61000,'content_file'=>0]];
$conversionContent=['relationships'=>$conversionText,
    'routing'=>['relationship_configuration_id'=>$profileModelSlot['configuration_id']],
    'settings_overrides'=>['relationship'=>['update_chance_percent'=>0,'locked'=>false]],
    'management'=>['locked'=>true]];
$conversionOwner=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Conversion Owner',
    'actor_identity'=>$conversionOwnerIdentity,'content'=>$conversionContent],$now);
$otherConversionOwners=$db->prepare('SELECT p.profile_id,r.content FROM profiles p JOIN profile_revisions r '
    .'ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.installation_id=:installation '
    .'AND p.profile_id<>:owner AND p.deleted_at IS NULL');
$otherConversionOwners->execute(['installation'=>$installationId,'owner'=>$conversionOwner['profile_id']]);
foreach($otherConversionOwners->fetchAll()as$otherOwner){$otherContent=json_decode($otherOwner['content'],true,64,JSON_THROW_ON_ERROR);
    if(trim((string)($otherContent['relationships']??''))==='')continue;unset($otherContent['relationships']);
    $products->revise('profile',$otherOwner['profile_id'],$otherContent,'isolate relationship conversion fixture',$now);}
$conversionScope=['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']];
$conversionRecordScope=$conversionScope+['profile_id'=>$conversionOwner['profile_id']];
$conversions=new \LorkhanServer\Infrastructure\RelationshipConversionRepository($db);
$conversionRequest=$newUuid(5800);$conversionSummary=$conversions->enqueue($conversionScope,$conversionRequest,'missing');
$assert($conversionSummary['queued']===1&&$conversionSummary['existing']===0
    &&$conversions->enqueue($conversionScope,$conversionRequest,'missing')==$conversionSummary,
    'missing-mode conversion was not bounded and idempotent: '.json_encode($conversionSummary));
try{$conversions->enqueue($conversionScope,$conversionRequest,'rebuild');throw new RuntimeException('conversion request mode changed');}
catch(InvalidArgumentException $error){$assert($error->getMessage()==='relationship_conversion_request_conflict','unexpected conversion request conflict');}
$conversionProvider=new class implements \LorkhanServer\Application\ProfileGenerationProvider {
    public int $calls=0;public string $phase='all';public mixed $during=null;
    public function generate(array $input,\LorkhanServer\Application\CancellationToken $cancellation):array{
        ++$this->calls;$cancellation->throwIfCancellationRequested();$encoded=json_encode($input,JSON_THROW_ON_ERROR);
        if(($input['generation_mode']??null)!=='relationship_text_conversion'||isset($input['exchanges'])
            ||!str_contains((string)($input['relationship_text']??''),'Unknown Conversion Stranger')
            ||str_contains($encoded,'CONVERSION PRIVATE CUSTOM INFO'))throw new RuntimeException('unsafe conversion model input');
        if(count($input['interlocutors']??[])!==3||!in_array('professional',$input['available_relationship_types']??[],true))
            throw new RuntimeException('conversion did not resolve the exact known actors: '.json_encode($input['interlocutors']??[]));
        if($this->during!==null)($this->during)();$rows=[];
        foreach($input['interlocutors']as$target){
            if($this->phase==='omit'&&($target['identity']['display_name']??'')==='Conversion Omitted')continue;
            $rows[]=['target_key'=>$target['target_key'],'disposition'=>$this->phase==='all'?10:40,
                'affinity'=>$this->phase==='all'?20:50,'relationship_type'=>'professional',
                'reason'=>'Explicit profile text names this actor.'];
        }
        if($this->phase==='invented')$rows[0]['target_key']=str_repeat('f',64);
        return['relationships'=>$rows];
    }
};
$conversionRegistry=new \LorkhanServer\Application\JobHandlerRegistry([new \LorkhanServer\Application\RelationshipConversionJobHandler(
    $conversions,$products,new \LorkhanServer\Infrastructure\ProviderAttemptRepository($db),[],$conversionProvider)]);
$conversionWorker=static fn()=> (new \LorkhanServer\Application\Worker(new \LorkhanServer\Infrastructure\JobRepository($db),
    $conversionRegistry,'relationship-conversion-integration',5,1,1,0,10,['relationship.convert']))->run();
$firstConversionRun=$conversionWorker();
$assert($firstConversionRun['succeeded']===1&&$conversionProvider->calls===1,
    'explicit conversion did not run its durable job: '.json_encode([$firstConversionRun,$conversionProvider->calls,
        $db->query("SELECT last_error_code,last_error_detail FROM durable_jobs WHERE job_type='relationship.convert'")->fetch()]));
$conversionReceipt=$db->query('SELECT source_bytes,target_count,changed_count FROM relationship_conversion_results')->fetch();
$conversionRows=$products->relationships($conversionRecordScope);
$assert($conversionReceipt&&array_map('intval',array_values($conversionReceipt))===[strlen($conversionText),3,3]
    &&count($conversionRows)===3
    &&count(array_filter($conversionRows,static fn(array$row):bool=>$row['source_event_id']===null))===3
    &&count(array_filter($conversionRows,static fn(array$row):bool=>$row['relationship_type']==='professional'))===3,
    'conversion receipt or source ownership was incomplete');
$assert((int)$db->query("SELECT count(*) FROM relationship_audit WHERE reason LIKE 'Relationship text conversion:%'")->fetchColumn()===3,
    'conversion writes were not visibly attributed');
$secondMissing=$conversions->enqueue($conversionScope,$newUuid(5801),'missing');
$assert($secondMissing['queued']===0&&$secondMissing['existing']===1,'missing mode rebuilt an owner with active records');
$privateConversionNote='CONVERSION PRIVATE CUSTOM INFO';
foreach($conversionRows as$row)$products->setRelationship($conversionRecordScope+[
    'relationship_id'=>$row['relationship_id'],'expected_revision'=>(int)$row['revision'],
    'disposition'=>(int)$row['disposition'],'affinity'=>(int)$row['affinity'],'source_mode'=>'manual',
    'custom_info'=>$privateConversionNote,'reason'=>'Attach private conversion note'],$now);
$rebuildRequest=$newUuid(5802);$rebuildSummary=$conversions->enqueue($conversionScope,$rebuildRequest,'rebuild');
$assert($rebuildSummary['queued']===1,'explicit rebuild did not queue the text owner');
$conversionProvider->phase='omit';
$assert($conversionWorker()['succeeded']===1&&$conversionProvider->calls===2,'conversion rebuild did not finish');
$rebuiltRows=$products->relationships($conversionRecordScope);$omittedRows=array_values(array_filter($rebuiltRows,
    static fn(array$row):bool=>($row['actor_identity']['record_id']??'')==='conversion_omitted'));
$selectedRows=array_values(array_filter($rebuiltRows,
    static fn(array$row):bool=>($row['actor_identity']['record_id']??'')!=='conversion_omitted'));
$assert(count($omittedRows)===1&&(int)$omittedRows[0]['disposition']===10&&(int)$omittedRows[0]['affinity']===20
    &&count(array_filter($selectedRows,static fn(array$row):bool=>(int)$row['disposition']===40&&(int)$row['affinity']===50))===2
    &&(int)$db->query("SELECT count(*) FROM relationship_records WHERE profile_id='{$conversionOwner['profile_id']}' "
        ."AND playthrough_id='{$session['playthrough_id']}' AND custom_info='CONVERSION PRIVATE CUSTOM INFO'")->fetchColumn()===3,
    'rebuild changed an omitted row or private Custom Info');
$conversions->enqueue($conversionScope,$newUuid(5803),'rebuild');
$db->exec('SAVEPOINT conversion_queued');
$conversionProvider->during=static function()use($products,$conversionTarget,$now):void{
    $products->revise('profile',$conversionTarget['profile_id'],$conversionTarget['content']+['notes'=>'Changed during provider work'],
        'target changed during conversion',$now);
};
$receiptCount=(int)$db->query('SELECT count(*) FROM relationship_conversion_results')->fetchColumn();
$assert($conversionWorker()['succeeded']===1&&$conversionProvider->calls===3
    &&(int)$db->query('SELECT count(*) FROM relationship_conversion_results')->fetchColumn()===$receiptCount,
    'target profile revision did not cancel late conversion output');
$db->exec('ROLLBACK TO SAVEPOINT conversion_queued');
$conversionProvider->during=static function()use($products,$conversionRecordScope,$now):void{
    $row=array_values(array_filter($products->relationships($conversionRecordScope),
        static fn(array$item):bool=>($item['actor_identity']['record_id']??'')==='conversion_friend'))[0];
    $products->setRelationship($conversionRecordScope+['relationship_id'=>$row['relationship_id'],
        'expected_revision'=>(int)$row['revision'],'disposition'=>77,'affinity'=>66,'source_mode'=>'manual',
        'custom_info'=>'CONVERSION PRIVATE CUSTOM INFO','reason'=>'Manual ownership during conversion'],$now);
};
$manualEditRun=$conversionWorker();$manualEditReceipts=(int)$db->query('SELECT count(*) FROM relationship_conversion_results')->fetchColumn();
$assert($manualEditRun['succeeded']===1&&$conversionProvider->calls===4&&$manualEditReceipts===$receiptCount,
    'manual relationship edit did not cancel late conversion output: '.json_encode([$manualEditRun,$conversionProvider->calls,$manualEditReceipts,$receiptCount]));
$db->exec('ROLLBACK TO SAVEPOINT conversion_queued');
$conversionProvider->during=static function()use($db,$sessionId):void{
    $db->prepare("UPDATE sessions SET state='ended',ended_at=clock_timestamp() WHERE session_id=:id")->execute(['id'=>$sessionId]);
};
$assert($conversionWorker()['succeeded']===1&&$conversionProvider->calls===5
    &&(int)$db->query('SELECT count(*) FROM relationship_conversion_results')->fetchColumn()===$receiptCount,
    'session lifecycle change did not cancel late conversion output');
$db->exec('ROLLBACK TO SAVEPOINT conversion_queued');
$conversionProvider->during=null;$conversionProvider->phase='invented';$beforeConversion=$products->relationships($conversionRecordScope);
$conversionWorker();
$assert($products->relationships($conversionRecordScope)===$beforeConversion
    &&(int)$db->query('SELECT count(*) FROM relationship_conversion_results')->fetchColumn()===$receiptCount,
    'an invented target allowed a partial profile-text conversion');
$db->exec('SAVEPOINT conversion_downgrade');
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/data/migrations/067_relationship_text_conversion.down.sql'));
    throw new RuntimeException('conversion downgrade discarded receipts');}
catch(PDOException $error){$assert(str_contains($error->getMessage(),'Cannot remove relationship conversion'),
    'unexpected conversion downgrade error');$db->exec('ROLLBACK TO SAVEPOINT conversion_downgrade');}
$db->rollBack();
// Exercise prompt privacy against real source projections without altering later turn fixtures.
$db->beginTransaction();
$memoryNow=(new \DateTimeImmutable('now'))->modify('+1 minute')->format('Y-m-d\TH:i:sP');
$memoryService=new ProductService($products,new DeterministicClock(new \DateTimeImmutable($memoryNow)));
$memoryProbe=$turn;$memoryProbe['turn_id']=$newUuid(3900);
$bystander=$turn['payload']['target'];$bystander['refnum']['index']+=100;
$bystanderProbe=$memoryProbe;$bystanderProbe['payload']['target']=$bystander;
$relationshipInput=['installation_id'=>$installationId,'playthrough_id'=>$turn['playthrough_id'],
    'actor_identity'=>$turn['payload']['speaker'],'disposition'=>20,'affinity'=>5,'source_mode'=>'manual'];
$privateNote="PLAYER PRIVATE RELATIONSHIP NOTE\nKeep verbatim <&> 古";
$relationshipDetails=['relation'=>'mentor','note'=>'Shared a drink','best'=>'Saved the traveller','worst'=>'Betrayed a promise'];
$ownedRelationship=$memoryService->setRelationship($relationshipInput+['profile_id'=>$actorProfile['profile_id'],'custom_info'=>$privateNote,'details'=>$relationshipDetails]);
$assert($ownedRelationship['relationship_type']==='neutral','older relationship create did not retain the neutral type default');
$db->exec('SAVEPOINT relationship_edits');
$relationshipEdit=$relationshipInput+['profile_id'=>$actorProfile['profile_id'],'relationship_id'=>$ownedRelationship['relationship_id'],'expected_revision'=>1];
$relationshipEdit['disposition']=31;$relationshipEdit['relationship_type']='trusted_companion';unset($relationshipEdit['actor_identity']);
$editedRelationship=$memoryService->setRelationship($relationshipEdit);
$assert($editedRelationship['revision']===2&&$editedRelationship['disposition']===31
    &&$editedRelationship['relationship_type']==='trusted_companion','relationship edit lost its revision fence or custom type');
$db->exec('SAVEPOINT custom_info_edits');
$privateScope=['installation_id'=>$installationId,'profile_id'=>$actorProfile['profile_id'],'playthrough_id'=>$turn['playthrough_id']];
$assert($products->exportScope($privateScope)['relationships'][0]['custom_info']===$privateNote,'older score-only edit cleared Custom Info');
$derivedEdit=array_replace($relationshipEdit,['expected_revision'=>2,'source_mode'=>'derived','affinity'=>12,'custom_info'=>'AI overwrite']);
$derivedEdit['reason']='A witnessed act increased trust';unset($derivedEdit['relationship_type']);
$derivedSaved=$products->setRelationship($derivedEdit,$memoryNow);
$derivedExport=$products->exportScope($privateScope)['relationships'][0];
$derivedUi=(new \LorkhanServer\Infrastructure\ManagementUiRepository($db))->rows('relationships',$installationId);
$derivedUi=array_values(array_filter($derivedUi,static fn(array$row):bool=>$row['relationship_id']===$ownedRelationship['relationship_id']))[0];
$assert($derivedExport['details']==$relationshipDetails&&$products->relationships($privateScope)[0]['details']==$relationshipDetails,
    'derived score edit lost AI-visible relationship details');
$assert($derivedExport['custom_info']===$privateNote&&$derivedExport['relationship_type']==='trusted_companion'
    &&(int)$derivedUi['strongest_positive_delta']===7,'derived writer replaced player text/type or lost its strongest affinity signal');
$cleared=$memoryService->setRelationship(array_replace($relationshipEdit,['expected_revision'=>$derivedSaved['revision'],'custom_info'=>'']));
$assert($products->exportScope($privateScope)['relationships'][0]['custom_info']===''&&$cleared['custom_info_changed'],
    'explicit manual clear did not persist');
$db->exec('ROLLBACK TO SAVEPOINT custom_info_edits');
foreach([
    [['expected_revision'=>0],'invalid_relationship_revision'],
    [['installation_id'=>$newUuid(3981)],'invalid_relationship_scope'],
    [['source_event_id'=>$newUuid(3982)],'invalid_relationship_source'],
    [['actor_identity'=>['record_id'=>'replacement_actor']],'relationship_identity_immutable'],
] as [$changes,$expectedError]){
    try{$memoryService->setRelationship(array_replace($relationshipEdit,['expected_revision'=>2],$changes));throw new RuntimeException('invalid relationship edit accepted');}
    catch(InvalidArgumentException $error){$assert($error->getMessage()===$expectedError,'wrong relationship validation failure');}
}
foreach(['edit','delete'] as $operation){
    try{if($operation==='edit')$memoryService->setRelationship($relationshipEdit);else$memoryService->deleteRelationship($ownedRelationship['relationship_id'],1);
        throw new RuntimeException('stale relationship edit was accepted');}
    catch(RuntimeException $error){$assert($error->getMessage()==='relationship_revision_conflict','wrong stale relationship failure');}
}
$renamedRelationship=$relationshipInput+['profile_id'=>$actorProfile['profile_id']];
$renamedRelationship['actor_identity']['display_name']='Renamed speaker';
$renamedRelationship['actor_identity']['cell']=['kind'=>'interior','name'=>'A different cell'];
try{$memoryService->setRelationship($renamedRelationship);throw new RuntimeException('renaming a relationship created a duplicate');}
catch(RuntimeException $error){$assert($error->getMessage()==='relationship_already_exists','wrong duplicate relationship failure');}
$renamedRelationship['actor_identity']['refnum']['index']+=200;
$otherRelationship=$memoryService->setRelationship($renamedRelationship);
$assert($otherRelationship['relationship_id']!==$ownedRelationship['relationship_id'],'distinct runtime actors collapsed into one relationship');
$legacyId=$newUuid(3980);
$db->prepare('INSERT INTO relationship_records(relationship_id,installation_id,profile_id,playthrough_id,actor_identity,disposition,affinity,source_mode) '
    .'VALUES(:id,:installation,:profile,:playthrough,CAST(:identity AS jsonb),-10,0,\'manual\')')->execute([
        'id'=>$legacyId,'installation'=>$installationId,'profile'=>$actorProfile['profile_id'],'playthrough'=>$turn['playthrough_id'],
        'identity'=>json_encode(['record_id'=>'legacy_actor','display_name'=>'Legacy actor'],JSON_THROW_ON_ERROR)]);
$legacyEdit=$relationshipEdit;$legacyEdit['relationship_id']=$legacyId;
$assert($memoryService->setRelationship($legacyEdit)['revision']===2,'legacy identity could not be edited by explicit record ID');
$memoryService->deleteRelationship($ownedRelationship['relationship_id'],2);
$relationshipUi=new \LorkhanServer\Infrastructure\ManagementUiRepository($db);
$currentRelationships=$relationshipUi->rows('relationships');
$scopedRelationships=$relationshipUi->rows('relationships',$installationId,['profile_id'=>$actorProfile['profile_id'],'playthrough_id'=>$turn['playthrough_id']]);
$assert(in_array($legacyId,array_column($scopedRelationships,'relationship_id'),true)
    &&count(array_filter($scopedRelationships,static fn(array$row):bool=>$row['profile_id']!==$actorProfile['profile_id']||$row['playthrough_id']!==$turn['playthrough_id']))===0,
    'NPC relationship editor mixed profile or playthrough scope');
$assert(!in_array($ownedRelationship['relationship_id'],array_column($currentRelationships,'relationship_id'),true)
    &&in_array($legacyId,array_column($currentRelationships,'relationship_id'),true)
    &&in_array($otherRelationship['relationship_id'],array_column($currentRelationships,'relationship_id'),true),
    'relationship manager collapsed legacy identities or retained a deleted record');
$historyRows=array_values(array_filter($relationshipUi->rows('relationship_logs'),static fn(array$row):bool=>$row['relationship_id']===$ownedRelationship['relationship_id']));
$assert(!str_contains(json_encode($historyRows,JSON_THROW_ON_ERROR),'PLAYER PRIVATE RELATIONSHIP NOTE'),
    'private relationship text was copied into audit history');
$db->exec('SAVEPOINT relationship_details_downgrade');
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/data/migrations/089_relationship_details.down.sql'));
    throw new RuntimeException('downgrade discarded saved relationship details');}
catch(PDOException $error){$assert(str_contains($error->getMessage(),'Cannot remove saved relationship details'),
    'unexpected relationship details downgrade error');$db->exec('ROLLBACK TO SAVEPOINT relationship_details_downgrade');}
$db->exec('SAVEPOINT custom_info_downgrade');
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/data/migrations/066_relationship_custom_info.down.sql'));
    throw new RuntimeException('downgrade discarded deleted relationship notes');}
catch(PDOException $error){$assert(str_contains($error->getMessage(),'Cannot remove player-authored relationship Custom Info'),
    'unexpected Custom Info downgrade error');$db->exec('ROLLBACK TO SAVEPOINT custom_info_downgrade');}
$assert(count($historyRows)===3&&($historyRows[0]['after_value']['deleted']??false)===true
    &&$historyRows[0]['before_value']['revision']===2&&$historyRows[1]['after_value']['disposition']===31
    &&$historyRows[1]['after_value']['relationship_type']==='trusted_companion',
    'relationship history must retain ordered create, edit and delete audit records');
$clearScope=['installation_id'=>$installationId,'profile_id'=>$actorProfile['profile_id'],'playthrough_id'=>$turn['playthrough_id']];
$db->prepare("INSERT INTO relationship_records(relationship_id,installation_id,profile_id,playthrough_id,actor_identity,disposition,affinity,source_mode)
    SELECT ('aa000000-0000-4000-8000-'||lpad(n::text,12,'0'))::uuid,:installation,:profile,:playthrough,
        jsonb_build_object('record_id','clear_limit_'||n::text),0,0,'manual' FROM generate_series(1,101) n")
    ->execute(['installation'=>$installationId,'profile'=>$actorProfile['profile_id'],'playthrough'=>$turn['playthrough_id']]);
$clearSnapshot=$products->relationshipClearSnapshot($clearScope);
$assert((int)$clearSnapshot['count']>100,'clear snapshot was truncated to the editor page');
$db->prepare('UPDATE relationship_records SET custom_info=:note WHERE relationship_id=:id')->execute(['note'=>'Private note retained on clear','id'=>$legacyId]);
try{$products->clearRelationships($clearScope,$clearSnapshot['token'],$memoryNow);throw new RuntimeException('stale clear accepted');}
catch(RuntimeException$error){$assert($error->getMessage()==='relationship_revision_conflict','wrong stale clear result');}
$freshClear=$products->relationshipClearSnapshot($clearScope);
$assert((int)$freshClear['count']===(int)$clearSnapshot['count'],'stale clear removed some records');
$otherCount=(int)$db->query("SELECT count(*) FROM relationship_records WHERE deleted_at IS NULL AND profile_id<>'".$actorProfile['profile_id']."'")->fetchColumn();
$cleared=$products->clearRelationships($clearScope,$freshClear['token'],$memoryNow);
$assert($cleared===(int)$freshClear['count']&&(int)$products->relationshipClearSnapshot($clearScope)['count']===0
    &&(int)$db->query("SELECT count(*) FROM relationship_records WHERE deleted_at IS NULL AND profile_id<>'".$actorProfile['profile_id']."'")->fetchColumn()===$otherCount,
    'clear all crossed NPC scope or left confirmed relationships active');
$clearNote=$db->prepare('SELECT custom_info FROM relationship_records WHERE relationship_id=:id AND deleted_at IS NOT NULL');$clearNote->execute(['id'=>$legacyId]);
$assert($clearNote->fetchColumn()==='Private note retained on clear','clear all discarded private notes');
$db->exec('ROLLBACK TO SAVEPOINT relationship_edits');
$memoryService->setRelationship($privateScope+['relationship_id'=>$ownedRelationship['relationship_id'],'expected_revision'=>1,
    'disposition'=>20,'affinity'=>5,'relationship_type'=>'trusted_companion','source_mode'=>'manual',
    'reason'=>'Choose a custom relationship type']);
$memoryService->setRelationship($relationshipInput+['profile_id'=>$turn['profile_id']]);
$relationshipSelection=$products->promptContext($memoryProbe,$memoryNow);
$assert(array_column($relationshipSelection['relationship'],'relationship_id')===[$ownedRelationship['relationship_id']]
    &&$products->promptContext($bystanderProbe,$memoryNow)['relationship']===[],
    'relationships must belong to the selected NPC, not the session profile or an unbound same-name actor');
$relationshipProbe=$memoryProbe;$relationshipProbe['_selected_profile_id']=$actorProfile['profile_id'];
$relationshipPrompt=(new PromptAssembler())->assemble($relationshipProbe,$relationshipSelection);
$assert(!str_contains(json_encode([$relationshipSelection,$relationshipPrompt],JSON_THROW_ON_ERROR),'PLAYER PRIVATE RELATIONSHIP NOTE'),
    'Custom Info leaked through prompt selection, text or trace');
$assert(str_contains(json_encode($relationshipPrompt['provider_input'],JSON_THROW_ON_ERROR),'trusted_companion'),
    'saved relationship type was omitted from the bounded prompt');
$privateExport=$memoryService->exportPlaythrough($privateScope);
$restorePlaythrough=$products->createRevisioned('playthrough',['installation_id'=>$installationId,
    'profile_id'=>$actorProfile['profile_id'],'name'=>'Private note restore','content'=>[]],$memoryNow);
$privateExport['scope']['playthrough_id']=$restorePlaythrough['playthrough_id'];
$db->exec('SAVEPOINT custom_info_restore');
$memoryService->restorePlaythrough($privateExport);$memoryService->restorePlaythrough($privateExport);
$restoredPrivate=$products->exportScope($privateExport['scope'])['relationships'];
$assert($restoredPrivate[0]['details']==$relationshipDetails,'explicit restore lost relationship details');
$assert(count($restoredPrivate)===1&&$restoredPrivate[0]['custom_info']===$privateNote,'explicit restore lost or duplicated Custom Info');
$restoredMemoryCount=(int)$db->query("SELECT count(*) FROM memory_records WHERE playthrough_id='{$restorePlaythrough['playthrough_id']}'")->fetchColumn();
$olderPrivateExport=$privateExport;unset($olderPrivateExport['data']['relationships'][0]['custom_info'],
    $olderPrivateExport['data']['relationships'][0]['relationship_type'],$olderPrivateExport['data']['relationships'][0]['details']);
$memoryService->restorePlaythrough($olderPrivateExport);
$assert($products->exportScope($privateExport['scope'])['relationships'][0]['custom_info']===$privateNote
    &&$products->exportScope($privateExport['scope'])['relationships'][0]['relationship_type']==='trusted_companion'
    &&$products->exportScope($privateExport['scope'])['relationships'][0]['details']==$relationshipDetails,
    'older relationship backup cleared newer Custom Info or relationship type');
$duplicateExport=$privateExport;$duplicateExport['data']['relationships'][]=$duplicateExport['data']['relationships'][0];
try{$memoryService->restorePlaythrough($duplicateExport);throw new RuntimeException('duplicate relationship restore was accepted');}
catch(RuntimeException$error){$assert($error->getMessage()==='relationship_restore_conflict','duplicate relationship restore had wrong result');}
$conflictingDetails=$privateExport;$conflictingDetails['data']['relationships'][0]['details']['note']='Conflicting backup note';
try{$memoryService->restorePlaythrough($conflictingDetails);throw new RuntimeException('restore overwrote edited details');}
catch(RuntimeException$error){$assert($error->getMessage()==='relationship_restore_conflict','unexpected relationship detail restore conflict');}
$conflictingExport=$privateExport;$conflictingExport['data']['relationships'][0]['disposition']=-99;
try{$memoryService->restorePlaythrough($conflictingExport);throw new RuntimeException('relationship restore overwrote local state');}
catch(RuntimeException$error){$assert($error->getMessage()==='relationship_restore_conflict','unexpected relationship restore conflict');}
$assert((int)$db->query("SELECT count(*) FROM memory_records WHERE playthrough_id='{$restorePlaythrough['playthrough_id']}'")->fetchColumn()===$restoredMemoryCount
    &&$products->exportScope($privateExport['scope'])['relationships'][0]['disposition']===$restoredPrivate[0]['disposition'],
    'relationship restore conflict committed partial data');
$products->deleteRelationship($restoredPrivate[0]['relationship_id'],$memoryNow,1);
try{$memoryService->restorePlaythrough($privateExport);throw new RuntimeException('relationship restore resurrected a deleted record');}
catch(RuntimeException$error){$assert($error->getMessage()==='relationship_restore_conflict','deleted relationship restore had wrong result');}
$db->exec('ROLLBACK TO SAVEPOINT custom_info_restore');
unset($privateExport['data']['relationships'][0]['custom_info']);
$memoryService->restorePlaythrough($privateExport);
$assert($products->exportScope($privateExport['scope'])['relationships'][0]['custom_info']==='',
    'legacy export without Custom Info did not restore the empty default');
$db->exec('ROLLBACK TO SAVEPOINT custom_info_restore');
$legacyRestore=$privateExport;$legacyRestore['scope']['playthrough_id']=$restorePlaythrough['playthrough_id'];
$legacyRestore['data']=['memories'=>[],'narratives'=>[],'relationships'=>[[
    'relationship_id'=>$newUuid(5810),'actor_identity'=>['record_id'=>'legacy_restore','display_name'=>'Legacy restore'],
    'disposition'=>7,'affinity'=>8,'custom_info'=>'legacy private note',
]]];
$memoryService->restorePlaythrough($legacyRestore);$memoryService->restorePlaythrough($legacyRestore);
$legacyRows=$products->exportScope($legacyRestore['scope'])['relationships'];
$assert(count($legacyRows)===1&&$legacyRows[0]['actor_identity']['record_id']==='legacy_restore'
    &&$legacyRows[0]['custom_info']==='legacy private note'&&$legacyRows[0]['relationship_type']==='neutral',
    'legacy relationship restore was not stable and idempotent');
$db->exec('SAVEPOINT relationship_type_downgrade');
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/data/migrations/068_relationship_types.down.sql'));
    throw new RuntimeException('relationship type downgrade discarded custom types');}
catch(PDOException $error){$assert(str_contains($error->getMessage(),'Cannot remove saved non-neutral relationship types'),
    'unexpected relationship type downgrade error');$db->exec('ROLLBACK TO SAVEPOINT relationship_type_downgrade');}
$db->exec('ROLLBACK TO SAVEPOINT custom_info_restore');
$assert(str_contains(json_encode($relationshipPrompt['provider_input'],JSON_THROW_ON_ERROR),'Saved the traveller'), 'relationship detail missing from AI prompt and trace');
$relationshipSources=array_values(array_filter($relationshipPrompt['trace']['sources'],
    static fn(array$row):bool=>$row['source_kind']==='relationship'));
$assert(array_column($relationshipSources,'source_id')===[$ownedRelationship['relationship_id']],
    'selected NPC relationships failed prompt scope validation or lost their source trace');
$wrongOwnerSelection=$relationshipSelection;
$wrongOwnerSelection['relationship']=$products->relationships($relationshipInput+['profile_id'=>$turn['profile_id']]);
try{(new PromptAssembler())->assemble($relationshipProbe,$wrongOwnerSelection);
    throw new RuntimeException('session-profile relationships passed selected-NPC prompt validation');}
catch(InvalidArgumentException$error){$assert($error->getMessage()==='prompt_source_scope_mismatch',
    'unexpected relationship scope validation error');}
$db->exec('SAVEPOINT relationship_fallback');
$db->prepare('DELETE FROM actor_profile_bindings WHERE installation_id=:installation AND playthrough_id=:playthrough AND profile_id=:profile')
    ->execute(['installation'=>$installationId,'playthrough'=>$turn['playthrough_id'],'profile'=>$actorProfile['profile_id']]);
$fallbackIdentity=$memoryProbe['payload']['target'];unset($fallbackIdentity['refnum']);
$db->prepare('UPDATE profiles SET actor_identity=CAST(:identity AS jsonb) WHERE profile_id=:profile')
    ->execute(['identity'=>json_encode($fallbackIdentity,JSON_THROW_ON_ERROR),'profile'=>$actorProfile['profile_id']]);
$fallbackProbe=$memoryProbe;$fallbackProbe['profile_id']=$actorProfile['profile_id'];
$fallbackMemory=$memoryService->createMemory($relationshipInput+['profile_id'=>$actorProfile['profile_id'],
    'tier'=>'recent','content'=>'EXACT OWNER MEMORY SENTINEL','provenance'=>['source'=>'manual']]);
$mismatchedContext=$products->promptContext($fallbackProbe,$memoryNow);
$assert($mismatchedContext['relationship']===[]
    &&!in_array($fallbackMemory['memory_id'],array_column($mismatchedContext['memory'],'id'),true),
    'an unbound actor with a different RefNum must not inherit the session profile relationships or manual memories');
$fallbackProbe['payload']['target']=$fallbackIdentity;
$fallbackProbe['payload']['target']['display_name']='Renamed NPC';
$fallbackContext=$products->promptContext($fallbackProbe,$memoryNow);
$assert(array_column($fallbackContext['relationship'],'relationship_id')===[$ownedRelationship['relationship_id']]
    &&in_array($fallbackMemory['memory_id'],array_column($fallbackContext['memory'],'id'),true),
    'an exact-identity session profile must retain its own relationships and memories without a binding or matching display name');
$db->exec('ROLLBACK TO SAVEPOINT relationship_fallback');
$visible=$products->promptContext($memoryProbe,$memoryNow)['memory'];
$db->exec('SAVEPOINT narrator_prompt_editor');
$priorPrompt=$products->promptContext($memoryProbe,$memoryNow)['prompt'];
$savedEventPrompt=$products->saveNarratorEventPrompt($installationId,'narrator_welcome_prompt','A scoped welcome for {PLAYER_NAME}.',0);
$narratorEventProbe=$memoryProbe;$narratorEventProbe['payload']['ui_source']='lorkhan_narrator_welcome';
$eventSelection=$products->promptContext($narratorEventProbe,$memoryNow);
$assert(($eventSelection['narrator_event_prompts']['narrator_welcome_prompt']??'')==='A scoped welcome for {PLAYER_NAME}.'
    &&$products->promptContext($memoryProbe,$memoryNow)['prompt']['configuration_id']===$priorPrompt['configuration_id'],
    'narrator event instructions load into scoped selection without replacing the roleplay prompt');
$products->saveNarratorEventPrompt($installationId,'narrator_welcome_prompt','',(int)$savedEventPrompt['current_revision']);
$assert($products->narratorEventPromptTexts($installationId)===[],'clearing narrator event text restores the factory instruction');
$db->exec('ROLLBACK TO SAVEPOINT narrator_prompt_editor');
$hidden=$products->promptContext($bystanderProbe,$memoryNow)['memory'];
$assert(in_array($delivery['message_id'],array_column($visible,'source_event_id'),true)
    &&!in_array($delivery['message_id'],array_column($hidden,'source_event_id'),true),
    'session-scoped played memories leaked to an unwitnessing same-name NPC');
$manualMemory=$memoryService->createMemory(['installation_id'=>$installationId,'profile_id'=>$actorProfile['profile_id'],
    'playthrough_id'=>$turn['playthrough_id'],'tier'=>'recent','content'=>'NPC PRIVATE MEMORY SENTINEL',
    'provenance'=>['source'=>'manual']]);
$sessionMemory=$memoryService->createMemory(['installation_id'=>$installationId,'profile_id'=>$turn['profile_id'],
    'playthrough_id'=>$turn['playthrough_id'],'tier'=>'recent','content'=>'SESSION PRIVATE MEMORY SENTINEL',
    'provenance'=>['source'=>'manual']]);
$sharedSource=$newUuid(3901);$sharedTurn=$newUuid(3902);
$sharedPayload=['speaker'=>$turn['payload']['speaker'],'target'=>$turn['payload']['target'],
    'audience'=>[$bystander],'input'=>['text'=>'SHARED CONVERSATION SENTINEL','mood'=>['kind'=>'playful']],
    'context'=>['world'=>['cell'=>'History limit test cell']]];
$db->prepare("INSERT INTO source_events(source_event_id,installation_id,session_id,generation,event_kind,occurred_at,schema_name,turn_id,payload) "
    . "VALUES(:id,:installation,:session,7,'turn.requested',:now,'lorkhan.turn.v1',:turn,CAST(:payload AS jsonb))")
    ->execute(['id'=>$sharedSource,'installation'=>$installationId,'session'=>$sessionId,'now'=>$memoryNow,
        'turn'=>$sharedTurn,'payload'=>json_encode($sharedPayload,JSON_THROW_ON_ERROR)]);
(new EventLogRepository($db))->projectSource($sharedSource,$installationId,$sessionId,'turn.requested',$memoryNow,
    null,$sharedTurn,null,$sharedPayload,['player_mood_cue'=>'(RANGROO sounds playful.)']);
$sharedProjection=$db->prepare('SELECT e.data,m.payload FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid '
    .'WHERE m.source_event_id=:source AND e.type=\'inputtext\'');
$sharedProjection->execute(['source'=>$sharedSource]);$sharedProjectionRow=$sharedProjection->fetch();
$sharedProjectionPayload=$sharedProjectionRow?json_decode((string)$sharedProjectionRow['payload'],true,64,JSON_THROW_ON_ERROR):[];
$assert($sharedProjectionRow&&str_contains((string)$sharedProjectionRow['data'],'(RANGROO sounds playful.)')
    &&($sharedProjectionPayload['input']['text']??null)==='SHARED CONVERSATION SENTINEL'
    &&($sharedProjectionPayload['input']['resolved_mood_cue']??null)==='(RANGROO sounds playful.)',
    'the accepted player mood cue was not frozen into readable history while preserving authored source text');
$sharedMemory=$memoryService->createMemory(['installation_id'=>$installationId,'profile_id'=>$turn['profile_id'],
    'playthrough_id'=>$turn['playthrough_id'],'tier'=>'recent','content'=>'SHARED MEMORY SENTINEL',
    'source_event_id'=>$sharedSource,'provenance'=>['source'=>'turn.requested']]);
$mixedMemory=$memoryService->createMemory(['installation_id'=>$installationId,'profile_id'=>$turn['profile_id'],
    'playthrough_id'=>$turn['playthrough_id'],'tier'=>'mid','content'=>'MIXED PRIVATE SUMMARY SENTINEL',
    'provenance'=>['source'=>'memory.consolidate','source_event_ids'=>[$sharedSource,$delivery['message_id']]]]);
$visible=$products->promptContext($memoryProbe,$memoryNow);
$hidden=$products->promptContext($bystanderProbe,$memoryNow);
$visibleIds=array_column($visible['memory'],'id');$hiddenIds=array_column($hidden['memory'],'id');
$assert(in_array($manualMemory['memory_id'],$visibleIds,true)
    &&!in_array($sessionMemory['memory_id'],$visibleIds,true)
    &&in_array($mixedMemory['memory_id'],$visibleIds,true)
    &&in_array($sharedMemory['memory_id'],$hiddenIds,true)
    &&!in_array($mixedMemory['memory_id'],$hiddenIds,true)
    &&!in_array($manualMemory['memory_id'],$hiddenIds,true),
    'NPC-profile manual memory or all-source summary eligibility was not enforced');
$semanticPolicyContent=['schema'=>\LorkhanServer\Application\MemoryEmbeddingPolicy::SCHEMA,'enabled'=>true,
    'endpoint'=>'http://127.0.0.1:8085','timeout_ms'=>1500];
$semanticPolicy=$memoryService->createRevisioned('memory_embedding_policy',['installation_id'=>$installationId,
    'name'=>'Semantic memory integration','content'=>$semanticPolicyContent]);
$semanticVector=[1,0,0,0,0,0,0,0];
$db->prepare('INSERT INTO memory_embeddings(memory_id,memory_revision,policy_configuration_id,policy_revision,dimensions,embedding,input_sha256,model,created_at)
    VALUES(:memory,1,:policy,1,8,CAST(:embedding AS jsonb),:sha,:model,:now)')->execute([
        'memory'=>$manualMemory['memory_id'],'policy'=>$semanticPolicy['configuration_id'],
        'embedding'=>json_encode($semanticVector,JSON_THROW_ON_ERROR),'sha'=>hash('sha256',$manualMemory['content']),
        'model'=>\LorkhanServer\Application\MemoryEmbeddingPolicy::MODEL,'now'=>$memoryNow]);
$semanticSignal=['status'=>'succeeded','policy_configuration_id'=>$semanticPolicy['configuration_id'],
    'policy_revision'=>1,'model'=>\LorkhanServer\Application\MemoryEmbeddingPolicy::MODEL,'embedding'=>$semanticVector];
$semanticSelection=$products->promptContext($memoryProbe,$memoryNow,[],$semanticSignal);
$semanticReasons=$semanticSelection['memory_retrieval']['reasons'];
$fallbackSelection=$products->promptContext($memoryProbe,$memoryNow,[],
    array_replace(array_diff_key($semanticSignal,['embedding'=>true]),['status'=>'failed']));
$assert($semanticSelection['memory_retrieval']['algorithm']==='prompt-memory-lexical-0.75+minime-0.25+deterministic-fallback+tier-v1'
    &&($semanticReasons[$manualMemory['memory_id']]['semantic_source']??null)==='minime'
    &&($semanticReasons[$mixedMemory['memory_id']]['semantic_source']??null)==='deterministic-fallback'
    &&($semanticReasons['_semantic']['status']??null)==='succeeded'
    &&$fallbackSelection['memory_retrieval']['algorithm']==='prompt-memory-lexical-0.75+fake-vector-0.25+tier-v1'
    &&($fallbackSelection['memory_retrieval']['reasons']['_semantic']['status']??null)==='failed'
    &&!str_contains(json_encode([$semanticSelection['memory'],$fallbackSelection['memory']],JSON_THROW_ON_ERROR),'_semantic_embedding'),
    'semantic prompt ranking did not blend valid vectors, fall back per memory, or scrub internal projections');
$memoryProbe['_selected_profile_id']=$actorProfile['profile_id'];
$memoryPrompt=(new PromptAssembler())->assemble($memoryProbe,$visible)['provider_input']['_assembled_prompt'];
$assert(str_contains($memoryPrompt,'NPC PRIVATE MEMORY SENTINEL'),
    'selected NPC-profile memory failed prompt scope validation');
$bystanderProbe['payload']['ui_source']='lorkhan_rechat';
$modelProvider=$memoryService->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Model privacy fixture',
    'content'=>['driver'=>'mock','model'=>'privacy-v1']]);
$modelPolicyContent=['schema'=>'lorkhan.memory-policy.v1','enabled'=>true,'provider_configuration_id'=>$modelProvider['configuration_id']];
$modelPolicy=$memoryService->createRevisioned('memory_policy',['installation_id'=>$installationId,'name'=>'Model privacy policy','content'=>$modelPolicyContent]);
$db->prepare('INSERT INTO memory_model_summaries(memory_id,memory_revision,policy_configuration_id,policy_revision,provider_configuration_id,provider_revision,content,input_sha256,created_at)
    VALUES(:memory,1,:policy,1,:provider,1,:content,:sha,:now)')->execute(['memory'=>$mixedMemory['memory_id'],
        'policy'=>$modelPolicy['configuration_id'],'provider'=>$modelProvider['configuration_id'],'content'=>'MODEL MIXED MEMORY SENTINEL',
        'sha'=>hash('sha256',$mixedMemory['content']),'now'=>$memoryNow]);
$modelSelection=$products->promptContext($memoryProbe,$memoryNow);
$modelPrompt=(new PromptAssembler())->assemble($memoryProbe,$modelSelection);
$modelSources=array_column(array_filter($modelPrompt['trace']['sources'],static fn(array $row):bool=>$row['source_kind']==='memory'),null,'source_id');
$assert(str_contains($modelPrompt['provider_input']['_assembled_prompt'],'MODEL MIXED MEMORY SENTINEL')
    &&$modelSources[$mixedMemory['memory_id']]['source_table']==='memory_model_summaries'
    &&!in_array($mixedMemory['memory_id'],array_column($products->promptContext($bystanderProbe,$memoryNow)['memory'],'id'),true),
    'model summary projection lost its original witness privacy or trace provenance');
$modelPolicyContent['enabled']=false;
$memoryService->revise('memory_policy',$modelPolicy['configuration_id'],$modelPolicyContent,'privacy fixture off');
$originalSelection=$products->promptContext($memoryProbe,$memoryNow);
$originalRows=array_column($originalSelection['memory'],null,'id');
$assert($originalRows[$mixedMemory['memory_id']]['content']==='MIXED PRIVATE SUMMARY SENTINEL',
    'disabling model memory did not restore deterministic text');
$modelPolicyContent['enabled']=true;
$memoryService->revise('memory_policy',$modelPolicy['configuration_id'],$modelPolicyContent,'privacy fixture on');
$products->updateMemory($mixedMemory['memory_id'],'MIXED EDITED MEMORY SENTINEL',['edited'],
    \LorkhanServer\Application\DeterministicRetrieval::fakeVector('MIXED EDITED MEMORY SENTINEL'),$memoryNow);
$editedRows=array_column($products->promptContext($memoryProbe,$memoryNow)['memory'],null,'id');
$assert($editedRows[$mixedMemory['memory_id']]['content']==='MIXED EDITED MEMORY SENTINEL'
    &&!isset($editedRows[$mixedMemory['memory_id']]['_model_summary']),'an older model projection hid a manual memory edit');
$rechatHistory=$products->promptContext($bystanderProbe,$memoryNow)['history'];
$rechatHistoryText=json_encode(array_column($rechatHistory,'content'),JSON_THROW_ON_ERROR);
$assert(str_contains($rechatHistoryText,'SHARED CONVERSATION SENTINEL')
    &&!str_contains($rechatHistoryText,'Please follow me.'),
    'rechat history bypassed the original conversation audience');
$limitedCore=$products->getRevisioned('core_profile',$actorCoreProfile['core_profile_id']);
$limitedContent=$limitedCore['content'];$limitedContent['settings_overrides']['memory']['recent_turn_limit']=1;
$products->revise('core_profile',$actorCoreProfile['core_profile_id'],$limitedContent,'test recent-turn limit',$memoryNow);
$limitedHistory=$products->promptContext($memoryProbe,$memoryNow)['history'];
$db->exec('SAVEPOINT profile_event_filter_probe');
$eventCountBefore=(int)$db->query('SELECT count(*) FROM eventlog')->fetchColumn();
$filteredContent=$limitedContent;$filteredContent['settings_overrides']['context']['event_types']=[];
$products->revise('core_profile',$actorCoreProfile['core_profile_id'],$filteredContent,'empty profile history filter',$memoryNow);
$assert($products->promptContext($memoryProbe,$memoryNow)['history']===[], 'Core empty event filter must exclude retrieved history');
$assert((int)$db->query('SELECT count(*) FROM eventlog')->fetchColumn()===$eventCountBefore,'profile context filtering must not delete event history');
$db->exec('ROLLBACK TO SAVEPOINT profile_event_filter_probe');
$assert($products->promptContext($memoryProbe,$memoryNow)['history']===$limitedHistory,'removing Core event filter restores inherited history');
$limitedTurns=array_values(array_unique(array_column(array_column($limitedHistory,'content'),'turn_id')));
$assert($limitedTurns===[$sharedTurn]&&count($limitedHistory)===2,
    'profile recent-turn limit must count one conversation turn with both input and world context');
$db->prepare("UPDATE eventlog_metadata SET suppressed_at=clock_timestamp() WHERE source_event_id=:source AND projection_kind='turn'")
    ->execute(['source'=>$sharedSource]);
$hiddenIds=array_column($products->promptContext($bystanderProbe,$memoryNow)['memory'],'id');
$assert(!in_array($sharedMemory['memory_id'],$hiddenIds,true),
    'suppressed source conversation remained accessible through a derived memory');
// Use a reversible synthetic projection to distinguish canonical Narrator speech from world events and names.
$db->exec('SAVEPOINT narrator_visibility_probe');
$visibilityRow=$db->query("SELECT e.rowid FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid WHERE e.type='chat' AND m.turn_id=".$db->quote($turn['turn_id'])." LIMIT 1")->fetchColumn();
$visibilityNarrator=['kind'=>'narrator','record_id'=>'lorkhan:narrator','content_file'=>'LORKHAN','display_name'=>'Renamed storyteller'];
$db->prepare("UPDATE eventlog SET ts=(extract(epoch FROM clock_timestamp())*1000)::bigint+3600000 WHERE rowid=:id")->execute(['id'=>$visibilityRow]);
$db->prepare("UPDATE eventlog_metadata SET speaker=CAST(:speaker AS jsonb),target=CAST(:target AS jsonb),suppressed_at=NULL WHERE rowid=:id")
    ->execute(['speaker'=>json_encode($visibilityNarrator),'target'=>json_encode($turn['payload']['target']),'id'=>$visibilityRow]);
$visibilityId='event:'.$visibilityRow;
$assert(!in_array($visibilityId,array_column($products->promptContext($memoryProbe,$memoryNow)['history'],'id'),true),
    'default Narrator visibility did not hide canonically identified speech from NPC history');
$narratorVisibilityProfile=$products->getRevisioned('profile',$narratorProfile['profile_id']);
$narratorVisibilityContent=$narratorVisibilityProfile['content'];$narratorVisibilityContent['hide_from_context']=false;
$products->revise('profile',$narratorProfile['profile_id'],$narratorVisibilityContent,'allow Narrator history fixture',$memoryNow);
$assert(in_array($visibilityId,array_column($products->promptContext($memoryProbe,$memoryNow)['history'],'id'),true),
    'explicit false Narrator visibility did not retain eligible speech');
$narratorVisibilityContent['hide_from_context']=true;
$products->revise('profile',$narratorProfile['profile_id'],$narratorVisibilityContent,'hide Narrator history fixture',$memoryNow);
$narratorVisibilityTurn=$memoryProbe;$narratorVisibilityTurn['payload']['target']=$visibilityNarrator;
$assert(in_array($visibilityId,array_column($products->promptContext($narratorVisibilityTurn,$memoryNow)['history'],'id'),true),
    'Narrator visibility hid the Narrator own history');
$db->prepare("UPDATE eventlog SET type='narration' WHERE rowid=:id")->execute(['id'=>$visibilityRow]);
$assert(in_array($visibilityId,array_column($products->promptContext($memoryProbe,$memoryNow)['history'],'id'),true),
    'Narrator speech visibility removed a world narration event');
$db->prepare("UPDATE eventlog SET type='chat' WHERE rowid=:id")->execute(['id'=>$visibilityRow]);
$namedNpc=$turn['payload']['target'];$namedNpc['display_name']='The Narrator';
$db->prepare('UPDATE eventlog_metadata SET speaker=CAST(:speaker AS jsonb) WHERE rowid=:id')->execute(['speaker'=>json_encode($namedNpc),'id'=>$visibilityRow]);
$assert(in_array($visibilityId,array_column($products->promptContext($memoryProbe,$memoryNow)['history'],'id'),true),
    'Narrator visibility must use identity, not an NPC display name');
$db->prepare("UPDATE eventlog SET type='inputtext' WHERE rowid=:id")->execute(['id'=>$visibilityRow]);
$db->prepare('UPDATE eventlog_metadata SET speaker=CAST(:speaker AS jsonb),target=CAST(:target AS jsonb),audience=CAST(:audience AS jsonb) WHERE rowid=:id')
    ->execute(['speaker'=>json_encode($turn['payload']['speaker']),'target'=>json_encode($visibilityNarrator),
        'audience'=>json_encode([$turn['payload']['target']]),'id'=>$visibilityRow]);
foreach([true,false]as$hideNarratorSpeech){
    $narratorVisibilityContent['hide_from_context']=$hideNarratorSpeech;
    $products->revise('profile',$narratorProfile['profile_id'],$narratorVisibilityContent,'Narrator addressed input fixture',$memoryNow);
    $assert(!in_array($visibilityId,array_column($products->promptContext($memoryProbe,$memoryNow)['history'],'id'),true)
        &&in_array($visibilityId,array_column($products->promptContext($narratorVisibilityTurn,$memoryNow)['history'],'id'),true),
        'Narrator-addressed input must stay in Narrator history only, independent of spoken-dialogue visibility');
}
$db->prepare('UPDATE eventlog_metadata SET target=CAST(:target AS jsonb) WHERE rowid=:id')->execute(['target'=>json_encode($namedNpc),'id'=>$visibilityRow]);
$assert(in_array($visibilityId,array_column($products->promptContext($memoryProbe,$memoryNow)['history'],'id'),true),
    'an input addressed to an ordinary NPC named The Narrator was hidden');
$db->exec('ROLLBACK TO SAVEPOINT narrator_visibility_probe');
$narrativeInsert=$db->prepare('INSERT INTO narrative_records(narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,created_at,updated_at) '
    ."VALUES(:id,:installation,:profile,:playthrough,'diary','Recency probe',:content,'{\"source\":\"manual\"}',:now,:now)");
for($i=0;$i<12;$i++)$narrativeInsert->execute(['id'=>$newUuid(3940+$i),'installation'=>$installationId,
    'profile'=>$turn['profile_id'],'playthrough'=>$turn['playthrough_id'],'content'=>'NARRATIVE RECENCY '.$i,
    'now'=>(new \DateTimeImmutable($memoryNow))->modify('+'.$i.' seconds')->format('Y-m-d\TH:i:sP')]);
$narrativeSelection=$products->promptContext($memoryProbe,$memoryNow);
$narrativePrompt=(new PromptAssembler())->assemble($memoryProbe,$narrativeSelection)['provider_input']['_assembled_prompt'];
$assert(array_column($narrativeSelection['narrative'],'narrative_id')===array_map($newUuid,range(3951,3942))
    &&str_contains($narrativePrompt,'NARRATIVE RECENCY 11')&&!str_contains($narrativePrompt,'NARRATIVE RECENCY 0'),
    'prompt narrative budget must select the latest entries before the ten-record cap, not UUID order');
// Narrator diary access must change author eligibility without changing world scope or NPC recall.
$db->exec('SAVEPOINT narrator_diary_access_probe');
foreach([3965=>$actorProfile['profile_id'],3966=>$narratorProfile['profile_id']]as$diaryId=>$authorId)
    $narrativeInsert->execute(['id'=>$newUuid($diaryId),'installation'=>$installationId,'profile'=>$authorId,
        'playthrough'=>$turn['playthrough_id'],'content'=>'NARRATOR DIARY ACCESS '.$diaryId,
        'now'=>(new \DateTimeImmutable($memoryNow))->modify('+60 seconds')->format('Y-m-d\TH:i:sP')]);
$diaryNarratorTurn=$memoryProbe;$diaryNarratorTurn['payload']['target']=$visibilityNarrator;
$diaryNarratorTurn['_selected_profile_id']=$narratorProfile['profile_id'];
$diaryNarratorContent=$products->getRevisioned('profile',$narratorProfile['profile_id'])['content'];
$diaryNarratorContent['diary']['include_in_context']=true;
foreach([false,true]as$onlyOwnDiary){
    $diaryNarratorContent['only_diary_access']=$onlyOwnDiary;
    $products->revise('profile',$narratorProfile['profile_id'],$diaryNarratorContent,'diary access fixture',$memoryNow);
    $diarySelection=$products->promptContext($diaryNarratorTurn,$memoryNow);
    $diaryIds=array_column($diarySelection['narrative'],'narrative_id');
    $assert(in_array($newUuid(3966),$diaryIds,true)&&in_array($newUuid(3965),$diaryIds,true)===!$onlyOwnDiary,
        'Narrator diary author eligibility did not follow only_diary_access');
    $diaryPrompt=(new PromptAssembler())->assemble($diaryNarratorTurn,$diarySelection);
    $assert(str_contains($diaryPrompt['provider_input']['_assembled_prompt'],'NARRATOR DIARY ACCESS 3966'),
        'selected Narrator diary did not reach the assembled prompt');
    foreach(['installation_id','playthrough_id']as$foreignField){
        $foreignDiarySelection=$diarySelection;$foreignDiarySelection['narrative'][0][$foreignField]=$newUuid(3999);
        try{(new PromptAssembler())->assemble($diaryNarratorTurn,$foreignDiarySelection);$assert(false,'Narrator accepted a foreign diary scope');}
        catch(InvalidArgumentException$error){$assert($error->getMessage()==='prompt_source_scope_mismatch','wrong foreign diary rejection');}
    }
}
$db->exec('ROLLBACK TO SAVEPOINT narrator_diary_access_probe');
$latestCore=$products->getRevisioned('core_profile',$actorCoreProfile['core_profile_id']);
$latestContent=$latestCore['content'];$latestContent['settings_overrides']['diary']['latest_entry_in_context']=true;
$latestContent['settings_overrides']['diary']['include_in_context']=false;
$products->revise('core_profile',$actorCoreProfile['core_profile_id'],$latestContent,'latest diary prompt probe',$memoryNow);
foreach([3960,3961]as$diaryId)$narrativeInsert->execute(['id'=>$newUuid($diaryId),'installation'=>$installationId,
    'profile'=>$actorProfile['profile_id'],'playthrough'=>$turn['playthrough_id'],'content'=>'AUTHOR LATEST DIARY '.$diaryId,
    'now'=>(new \DateTimeImmutable($memoryNow))->modify('+'.($diaryId-3960).' seconds')->format('Y-m-d\TH:i:sP')]);
$latestProbe=$memoryProbe;$latestProbe['_selected_profile_id']=$actorProfile['profile_id'];
$latestSelection=$products->promptContext($latestProbe,$memoryNow);
$latestAssembled=(new PromptAssembler())->assemble($latestProbe,$latestSelection);
$latestSources=array_values(array_filter($latestAssembled['trace']['sources'],static fn(array$row):bool=>$row['source_id']===$newUuid(3961)));
$assert(array_column($latestSelection['latest_diary'],'narrative_id')===[$newUuid(3961)]
    &&str_contains($latestAssembled['provider_input']['_assembled_prompt'],'AUTHOR LATEST DIARY 3961')
    &&!str_contains($latestAssembled['provider_input']['_assembled_prompt'],'AUTHOR LATEST DIARY 3960')
    &&count($latestSources)===1&&$latestSources[0]['section_key']==='npc_context'&&$latestSources[0]['included']===true,
    'latest author diary must be selected separately from newer session diaries and attributed to character context');
$assert($products->promptContext($bystanderProbe,$memoryNow)['latest_diary']===[],
    'an unprofiled bystander must not inherit the session author diary');
$db->prepare('UPDATE narrative_records SET deleted_at=clock_timestamp() WHERE narrative_id=:id')->execute(['id'=>$newUuid(3961)]);
$assert(array_column($products->promptContext($latestProbe,$memoryNow)['latest_diary'],'narrative_id')===[$newUuid(3960)],
    'deleted latest diary was selected');
$db->prepare("UPDATE narrative_records SET content='   ' WHERE narrative_id=:id")->execute(['id'=>$newUuid(3960)]);
$assert($products->promptContext($latestProbe,$memoryNow)['latest_diary']===[], 'empty latest diary must not create a character context block');

$db->rollBack();
$eventProjection=$db->prepare('SELECT e.type,e.data,e.utterance_id,e.delivery_state,m.turn_id,m.source_event_id,m.dialogue_message_id '
    .'FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid WHERE m.turn_id=:turn ORDER BY e.rowid');
$eventProjection->execute(['turn'=>$turn['turn_id']]);$eventProjectionRows=$eventProjection->fetchAll();
$assert(array_column($eventProjectionRows,'type')===['inputtext','chat']
    &&str_contains((string)$eventProjectionRows[0]['data'],'Please follow me.')
    &&!str_starts_with((string)$eventProjectionRows[0]['data'],'{')
    &&$eventProjectionRows[1]['utterance_id']===$dialogueEvent['message_id']
    &&$eventProjectionRows[1]['dialogue_message_id']===$dialogueEvent['message_id']
    &&$eventProjectionRows[1]['delivery_state']==='played',
    'CHIM event projection did not preserve readable turn/dialogue rows and exact delivery correlation: '.json_encode($eventProjectionRows));
$immutableSourceCount=$db->prepare("SELECT count(*) FROM source_events WHERE turn_id=:turn AND event_kind IN ('turn.requested','dialogue.delivery')");
$immutableSourceCount->execute(['turn'=>$turn['turn_id']]);
$assert((int)$immutableSourceCount->fetchColumn()===2,'event projection replaced immutable LORKHAN source records');
[$status,$deliveryReplay]=$call($router,'POST',$base.'/dialogue-delivery-results',$headers($delivery['message_id']),[],$delivery);
$assert($status===200&&$deliveryReplay['duplicate'],'dialogue delivery replay failed');
$wrongRequestDelivery=$delivery;$wrongRequestDelivery['message_id']=$newUuid(24);$wrongRequestDelivery['request_id']=$newUuid(25);
[$status]=$call($router,'POST',$base.'/dialogue-delivery-results',$headers($wrongRequestDelivery['message_id']),[],$wrongRequestDelivery);
$assert($status===409,'dialogue request mismatch accepted');
$wrongDelivery=$delivery;$wrongDelivery['speaker']=$turn['payload']['speaker'];
[$status]=$call($router,'POST',$base.'/dialogue-delivery-results',$headers($wrongDelivery['message_id']),[],$wrongDelivery);
$assert($status===409,'dialogue speaker mismatch accepted');
$providerAttemptRows = $db->query("SELECT provider_kind, state FROM provider_attempts WHERE turn_id = " . $db->quote($turn['turn_id'])
    . " ORDER BY provider_kind")->fetchAll();
$assert($providerAttemptRows === [['provider_kind' => 'llm', 'state' => 'succeeded'], ['provider_kind' => 'tts', 'state' => 'succeeded']],
    'provider attempts were not reconciled');
$mediaPath=$base.'/media/'.$speech['media_id'];$timestamp=gmdate('Y-m-d\TH:i:s\Z');$nonce=bin2hex(random_bytes(16));$mediaUnsigned=new Request('GET',$mediaPath,[],[],'');$mediaHeaders=['X-LORKHAN-Auth'=>RequestMac::ALGORITHM,'X-LORKHAN-Installation-Id'=>$installationId,'X-LORKHAN-Timestamp'=>$timestamp,'X-LORKHAN-Nonce'=>$nonce,'X-LORKHAN-Content-SHA256'=>RequestMac::EMPTY_SHA256,'X-LORKHAN-Signature'=>RequestMac::sign($macKey,$mediaUnsigned,$installationId,$timestamp,$nonce,'',RequestMac::EMPTY_SHA256)];
$mediaResponse = $router->dispatch(new Request('GET',$mediaPath,$mediaHeaders));
$assert($mediaResponse->status === 200 && strlen($mediaResponse->body) === 204 && substr($mediaResponse->body, 0, 4) === 'RIFF'
    && hash('sha256', $mediaResponse->body) === $speech['sha256'], 'private media serving failed');
$traversalResponse = $router->dispatch(new Request('GET', $base . '/media/../../etc/passwd', []));
$assert(in_array($traversalResponse->status,[401,404],true),'media path traversal accepted');
$unsignedMedia=$router->dispatch(new Request('GET',$base.'/media/'.$speech['media_id']));
$assert($unsignedMedia->status===401,'unauthenticated media served');

$interrupt = $fixture('interrupt');
$interrupt['session_id'] = $sessionId; $interrupt['request_id'] = $turn['request_id']; $interrupt['turn_id'] = $turn['turn_id'];
[$status, $body] = $call($router, 'POST', $base . '/interruptions', $headers($interrupt['message_id']), [], $interrupt);
$assert($status === 409 && $body['code'] === 'turn_terminal', 'terminal turn cancellation accepted');

$result = $fixture('action-result');
$result['message_id'] = $newUuid(20); $result['request_id'] = $newUuid(22);
$result['turn_id'] = $turn['turn_id']; $result['session_id'] = $sessionId; $result['generation'] = 7;
$result['action_id'] = $action['action_id'];
$result['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
$wrongResult = $result; $wrongResult['turn_id'] = $newUuid(21);
[$status, $body] = $call($router, 'POST', $base . '/action-results', $headers($wrongResult['message_id']), [], $wrongResult);
$assert($status === 409 && $body['code'] === 'action_result_mismatch', 'unbound action result accepted');
[$status, $resultAccepted] = $call($router, 'POST', $base . '/action-results', $headers($result['message_id']), [], $result);
$assert($status === 200 && !($resultAccepted['duplicate'] ?? true), 'action result failed: ' . $status . ' ' . json_encode($resultAccepted));
$actionProjection=$db->prepare("SELECT e.data FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid WHERE m.source_event_id=:source AND e.type='infoaction'");
$actionProjection->execute(['source'=>$result['message_id']]);
$assert(str_contains((string)$actionProjection->fetchColumn(),'succeeded'),'terminal action did not project into CHIM event history');
[$status, $resultDuplicate] = $call($router, 'POST', $base . '/action-results', $headers($result['message_id']), [], $result);
$assert($status === 200 && $resultDuplicate['duplicate'], 'action result duplicate failed');
$resultConflict = $result; $resultConflict['status'] = 'failed';
[$status, $body] = $call($router, 'POST', $base . '/action-results', $headers($resultConflict['message_id']), [], $resultConflict);
$assert($status === 409 && $body['code'] === 'duplicate_conflict', 'second terminal action result accepted');

$tooMany = $turn; $tooMany['message_id'] = $newUuid(30); $tooMany['request_id'] = $newUuid(31); $tooMany['turn_id'] = $newUuid(32);
$tooMany['payload']['recent_action_results'] = array_fill(0, 17, []);
[$status] = $call($router, 'POST', $base . '/turns', $headers($tooMany['message_id']), [], $tooMany);
$assert($status === 422, 'unbounded recent_action_results accepted');
$stale = $turn; $stale['message_id'] = $newUuid(33); $stale['request_id'] = $newUuid(34); $stale['turn_id'] = $newUuid(35); $stale['generation'] = 8;
[$status, $body] = $call($router, 'POST', $base . '/turns', $headers($stale['message_id']), [], $stale);
$assert($status === 409 && $body['code'] === 'stale_generation', 'stale turn generation accepted');

$cancelledTurn = $turn; $cancelledTurn['message_id'] = $newUuid(37); $cancelledTurn['request_id'] = $newUuid(38); $cancelledTurn['turn_id'] = $newUuid(39);
[$status] = $call($router, 'POST', $base . '/turns', $headers($cancelledTurn['message_id']), [], $cancelledTurn);
$cancelRequest = ['schema'=>'lorkhan.interrupt.v1','message_id'=>$newUuid(36),'request_id'=>$cancelledTurn['request_id'],
    'turn_id'=>$cancelledTurn['turn_id'],'session_id'=>$sessionId,'generation'=>7,'created_at'=>gmdate('Y-m-d\\TH:i:s\\Z'),'reason'=>'player'];
[$interruptStatus]=$call($router,'POST',$base.'/interruptions',$headers($cancelRequest['message_id']),[],$cancelRequest);
$assert($status === 202 && $interruptStatus === 202, 'active turn interruption failed');
[$status, $cancelledEvents] = $call($router, 'GET', $base . '/events', [], [
    'session_id' => $sessionId, 'generation' => '7', 'after' => (string) $events['next_after']]);
$assert($status === 200 && array_column($cancelledEvents['events'], 'type') === ['turn.accepted','response.complete','turn.cancelled'], 'interruption was not terminal');
$cancelledProjection=$db->prepare('SELECT response_payload FROM turns WHERE turn_id=:turn');
$cancelledProjection->execute(['turn'=>$cancelledTurn['turn_id']]);
$cancelledResponse=json_decode((string)$cancelledProjection->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$assert($cancelledResponse['schema']==='lorkhan.response.v1'&&$cancelledResponse['ok']===false
    &&$cancelledResponse['lines']===[]&&$cancelledResponse['error']==='interrupted.player',
    'interrupted turn did not persist a canonical terminal response');

$failingProvider = new class implements Provider { public function complete(array $turn, CancellationToken $cancellation): array { throw new RuntimeException('provider secret'); } };
$failingRouter = new Router($repo, new Validator(), $failingProvider, $tokenHash, rateLimitRequests: 1000,
    providerAttempts: $attempts);
$failedTurn = $turn; $failedTurn['message_id'] = $newUuid(40); $failedTurn['request_id'] = $newUuid(41); $failedTurn['turn_id'] = $newUuid(42);
$failedTurn['payload']['recent_action_results'] = [];
[$status] = $call($failingRouter, 'POST', $base . '/turns', $headers($failedTurn['message_id']), [], $failedTurn);
$failedWorkerStats=$runTurnWorker($failingProvider);
$assert($failedWorkerStats === ['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0], 'terminal provider failure left a retryable job');
$assert($status === 202, 'provider failure lost durable acceptance');
[$status, $failedEvents] = $call($router, 'GET', $base . '/events', [], [
    'session_id' => $sessionId, 'generation' => '7', 'after' => (string) $cancelledEvents['next_after']]);
$assert($status === 200 && array_column($failedEvents['events'], 'type') === ['turn.accepted','response.complete','turn.failed'], 'provider failure not terminally persisted');
$failedAttempt = $db->query("SELECT state, error_code, error_detail FROM provider_attempts WHERE turn_id = " . $db->quote($failedTurn['turn_id']))->fetch();
$assert($failedAttempt === ['state' => 'failed', 'error_code' => 'provider_unavailable', 'error_detail' => null],
    'provider failure attempt was not redacted/reconciled');
$failedProjection=$db->prepare('SELECT response_payload FROM turns WHERE turn_id=:turn');
$failedProjection->execute(['turn'=>$failedTurn['turn_id']]);
$failedResponse=json_decode((string)$failedProjection->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$assert($failedResponse['schema']==='lorkhan.response.v1'&&$failedResponse['ok']===false
    &&$failedResponse['lines']===[]&&$failedResponse['error']==='provider_unavailable',
    'provider failure did not persist a canonical terminal response');

// A profile fallback is frozen with the accepted turn and is attempted once after the default provider fails.
$fallbackTarget=$controlsQuery['target'];$fallbackTarget['record_id']='fallback_actor';$fallbackTarget['display_name']='Fallback Actor';
$fallbackTarget['refnum']['index']=733;
$fallbackCore=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Fallback Core',
    'default_npc'=>false,'slot'=>null,'content'=>['schema'=>'lorkhan.core-profile.v1','prompt'=>'','routing'=>[
        'llm_configuration_id'=>'','llm_fallback_configuration_id'=>$fallbackModelSlot['configuration_id'],
        'llm_fallback_enabled'=>true],'settings_overrides'=>[]]],$now);
$fallbackProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Fallback-only actor',
    'actor_identity'=>['record_id'=>'fallback_actor'],'core_profile_id'=>$fallbackCore['core_profile_id'],'content'=>[]],$now);
$products->bindActorProfile(['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']],
    $fallbackTarget,$fallbackProfile['profile_id'],$now);
$products->selectModelSlot(['session_id'=>$sessionId,'generation'=>7,'installation_id'=>$installationId],'standard',$now);
$fallbackRouter=new Router($repo,new Validator(),$failingProvider,$tokenHash,rateLimitRequests:1000,
    providerAttempts:$attempts,products:$products,promptAssembler:new PromptAssembler());
$fallbackTurn=$turn;$fallbackTurn['message_id']=$newUuid(740);$fallbackTurn['request_id']=$newUuid(741);
$fallbackTurn['turn_id']=$newUuid(742);$fallbackTurn['payload']['target']=$fallbackTarget;
$fallbackTurn['payload']['input']['text']='[fallback] Continue after the primary provider fails.';
[$status]=$call($fallbackRouter,'POST',$base.'/turns',$headers($fallbackTurn['message_id']),[],$fallbackTurn);
$fallbackSnapshotStatement=$db->prepare('SELECT source_manifest FROM turn_provider_snapshots WHERE turn_id=:turn');
$fallbackSnapshotStatement->execute(['turn'=>$fallbackTurn['turn_id']]);
$fallbackSnapshot=json_decode((string)$fallbackSnapshotStatement->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$assert($status===202&&!isset($fallbackSnapshot['message']['_provider_configuration'])
    &&($fallbackSnapshot['message']['_fallback_provider_configuration']['configuration_id']??null)===$fallbackModelSlot['configuration_id'],
    'accepted turn did not freeze the Core Profile fallback connector');
$assert(($fallbackSnapshot['message']['_fallback_provider_configuration']['name']??null)==='Profile fallback mock',
    'accepted fallback snapshot lost its historical connector label');
$fallbackWorkerStats=$runTurnWorker($failingProvider);
$assert($fallbackWorkerStats===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0],
    'explicit Core Profile fallback did not complete the durable turn');
[$status,$fallbackEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$failedEvents['next_after']]);
$fallbackTypes=array_column($fallbackEvents['events'],'type');
$assert($status===200&&in_array('dialogue.complete',$fallbackTypes,true)&&in_array('turn.complete',$fallbackTypes,true)
    &&!in_array('turn.failed',$fallbackTypes,true),'fallback turn did not complete normally');
$fallbackAttempts=$db->query("SELECT state,metadata->>'fallback' AS fallback FROM provider_attempts WHERE provider_kind='llm' AND turn_id="
    .$db->quote($fallbackTurn['turn_id'])." ORDER BY started_at,provider_attempt_id")->fetchAll();
$assert($fallbackAttempts===[['state'=>'failed','fallback'=>'false'],['state'=>'succeeded','fallback'=>'true']],
    'profile fallback attempts were not recorded as one primary failure and one fallback success');
$recordedLabel=$db->query("SELECT metadata->>'configuration_name' FROM provider_attempts WHERE state='succeeded' AND turn_id=".$db->quote($fallbackTurn['turn_id']))->fetchColumn();
$assert($recordedLabel==='Profile fallback mock','successful fallback attempt lost its frozen connector label');
$products->selectModelSlot(['session_id'=>$sessionId,'generation'=>7,'installation_id'=>$installationId],'fast',$now);

// Exercise the lower group bounds through the same durable provider/TTS pipeline.
// Keep every offline group speaker on the same route-free Core Profile so the injected mock TTS remains deterministic.
$groupAfter=(int)$fallbackEvents['next_after'];
foreach([2,3] as $groupCount){$bounded=$turn;$bounded['message_id']=$newUuid(50+$groupCount*3);$bounded['request_id']=$newUuid(51+$groupCount*3);
    $bounded['turn_id']=$newUuid(52+$groupCount*3);$bounded['payload']['input']['text']='[group] Bounded report.';$bounded['payload']['audience']=[];
    for($i=1;$i<$groupCount;++$i){$actor=$bounded['payload']['target'];$actor['record_id']='bounded_'.$groupCount.'_'.$i;
        $actor['display_name']='Bounded Actor '.$groupCount.'-'.$i;$actor['refnum']['index']=150+$groupCount*10+$i;
        $products->bindActorProfile(['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']],
            $actor,$actorProfile['profile_id'],$now);
        $bounded['payload']['audience'][]=$actor;}
    [$status]=$call($router,'POST',$base.'/turns',$headers($bounded['message_id']),[],$bounded);$assert($status===202,'bounded group acceptance failed');
    $boundedStats=$runTurnWorker(new MockProvider());$assert($boundedStats===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0],'bounded group worker failed');
    [$status,$boundedEvents]=$call($router,'GET',$base.'/events',[],[
        'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$groupAfter]);
    $boundedDialogues=array_values(array_filter($boundedEvents['events'],static fn(array $e):bool=>$e['type']==='dialogue.complete'));
    $boundedSpeech=array_values(array_filter($boundedEvents['events'],static fn(array $e):bool=>$e['type']==='speech.ready'));
    $assert($status===200&&count($boundedDialogues)===$groupCount&&count($boundedSpeech)===$groupCount,
        '2-3 utterance group coverage failed: expected='.$groupCount.' dialogues='.count($boundedDialogues).' speech='.count($boundedSpeech));
    foreach($boundedEvents['events'] as $event)$assert($event['request_id']===$bounded['request_id'],'bounded group event correlation failed');
    $boundedMapping=$db->prepare('SELECT count(*) FROM media_objects m JOIN dialogue_utterances u ON u.dialogue_message_id=m.dialogue_message_id WHERE u.turn_id=:turn');
    $boundedMapping->execute(['turn'=>$bounded['turn_id']]);$assert((int)$boundedMapping->fetchColumn()===$groupCount,'bounded group media mapping failed');
    $groupAfter=(int)$boundedEvents['next_after'];}

// A four-speaker group turn must produce independent dialogue, media, receipt, and expiry state
// while every event and receipt retains the single originating turn request correlation.
$groupTurn=$turn;$groupTurn['message_id']=$newUuid(60);$groupTurn['request_id']=$newUuid(61);$groupTurn['turn_id']=$newUuid(62);
$groupTurn['payload']['input']['text']='[group] Report in.';
$groupTurn['payload']['audience']=[];
for($i=0;$i<3;++$i){$actor=$groupTurn['payload']['target'];$actor['record_id']='group_actor_'.($i+1);
    $actor['display_name']='Group Actor '.($i+1);$actor['refnum']['index']=200+$i;
    $products->bindActorProfile(['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']],
        $actor,$actorProfile['profile_id'],$now);
    $groupTurn['payload']['audience'][]=$actor;}
[$status,$groupAccepted]=$call($router,'POST',$base.'/turns',$headers($groupTurn['message_id']),[],$groupTurn);
$assert($status===202,'group turn acceptance failed');
$groupWorker=$runTurnWorker(new MockProvider());
$assert($groupWorker===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0],'group turn worker failed');
[$status,$groupEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$groupAfter]);
$groupTypes=array_column($groupEvents['events'],'type');
$assert($status===200&&$groupTypes===['turn.accepted','response.complete','dialogue.complete','dialogue.complete',
    'dialogue.complete','dialogue.complete','turn.complete','speech.ready','speech.ready','speech.ready','speech.ready'],'four-utterance group event order failed');
$groupDialogues=array_values(array_filter($groupEvents['events'],static fn(array $e):bool=>$e['type']==='dialogue.complete'));
$groupSpeech=array_values(array_filter($groupEvents['events'],static fn(array $e):bool=>$e['type']==='speech.ready'));
$assert(count($groupDialogues)===4&&count($groupSpeech)===4&&count(array_unique(array_column(array_column($groupSpeech,'payload'),'media_id')))===4,
    'group turn did not produce one distinct speech object per utterance');
$assert(array_column(array_column($groupSpeech,'payload'),'dialogue_message_id')===array_column($groupDialogues,'message_id'),
    'delayed group speech did not preserve dialogue order');
foreach($groupEvents['events'] as $event)$assert($event['request_id']===$groupTurn['request_id'],'group event lost originating request correlation');
$mapping=$db->prepare('SELECT count(*) AS media_count,count(DISTINCT m.dialogue_message_id) AS dialogue_count FROM media_objects m '
    .'JOIN dialogue_utterances u ON u.dialogue_message_id=m.dialogue_message_id WHERE u.turn_id=:turn');
$mapping->execute(['turn'=>$groupTurn['turn_id']]);$mapped=$mapping->fetch();
$assert((int)$mapped['media_count']===4&&(int)$mapped['dialogue_count']===4,'media was not mapped one-to-one to group utterances');
for($i=0;$i<3;++$i){$dialogue=$groupDialogues[$i];$receipt=$fixture('dialogue-delivery-result');$receipt['message_id']=$newUuid(70+$i);
    $receipt['request_id']=$groupTurn['request_id'];$receipt['dialogue_message_id']=$dialogue['message_id'];$receipt['turn_id']=$groupTurn['turn_id'];
    $receipt['session_id']=$sessionId;$receipt['speaker']=$dialogue['payload']['speaker'];$receipt['completed_at']=gmdate('Y-m-d\TH:i:s\Z');
    [$status,$receiptAccepted]=$call($router,'POST',$base.'/dialogue-delivery-results',$headers($receipt['message_id']),[],$receipt);
    $assert($status===200&&!$receiptAccepted['duplicate'],'independent group dialogue receipt failed');}
$sharedReceipts=$db->prepare('SELECT count(*) FROM dialogue_delivery_results WHERE turn_id=:turn AND request_id=:request');
$sharedReceipts->execute(['turn'=>$groupTurn['turn_id'],'request'=>$groupTurn['request_id']]);
$assert((int)$sharedReceipts->fetchColumn()===3,'group receipts did not share originating request independently');
$expiringDialogue=$groupDialogues[3]['message_id'];
$db->prepare("UPDATE dialogue_utterances SET delivery_deadline_at=clock_timestamp()-interval '1 second' WHERE dialogue_message_id=:id")
    ->execute(['id'=>$expiringDialogue]);
$db->prepare("UPDATE durable_jobs SET next_run_at=clock_timestamp()-interval '1 second' WHERE job_type='dialogue.expire' AND idempotency_key=:key")
    ->execute(['key'=>'dialogue:'.$expiringDialogue]);
$expiryStats=$runWorker(['dialogue.expire']);
$expiryState=$db->query('SELECT delivery_state FROM dialogue_utterances WHERE dialogue_message_id='.$db->quote($expiringDialogue))->fetchColumn();
$assert($expiryStats===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0]&&$expiryState==='expired','dialogue expiry job failed');

// Exercise the second negotiated action through the actual provider, event, and terminal-result path.
$inspectTurn=$turn;$inspectTurn['message_id']=$newUuid(80);$inspectTurn['request_id']=$newUuid(81);$inspectTurn['turn_id']=$newUuid(82);
$inspectTurn['payload']['input']['text']='Inspect this target.';$inspectTurn['payload']['recent_action_results']=[];
[$status]=$call($router,'POST',$base.'/turns',$headers($inspectTurn['message_id']),[],$inspectTurn);
$assert($status===202,'inspect turn acceptance failed');$runTurnWorker(new MockProvider());
[$status,$inspectEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$groupEvents['next_after']]);
$inspectIntents=array_values(array_filter($inspectEvents['events'],static fn(array $e):bool=>$e['type']==='action.intent'));
$assert($status===200&&count($inspectIntents)===1&&$inspectIntents[0]['payload']['name']==='inspect.report','inspect.report was not emitted E2E');
$inspectResult=$fixture('action-result');$inspectResult['message_id']=$newUuid(83);$inspectResult['request_id']=$newUuid(84);
$inspectResult['turn_id']=$inspectTurn['turn_id'];$inspectResult['session_id']=$sessionId;$inspectResult['generation']=7;
$inspectResult['action_id']=$inspectIntents[0]['payload']['action_id'];$inspectResult['completed_at']=gmdate('Y-m-d\TH:i:s\Z');
[$status,$inspectResultAccepted]=$call($router,'POST',$base.'/action-results',$headers($inspectResult['message_id']),[],$inspectResult);
$assert($status===200&&!$inspectResultAccepted['duplicate'],'inspect.report terminal result failed');

// Exercise bounded movement and a tier-2 action proposal through catalog-backed validation.
$wanderTurn=$turn;$wanderTurn['message_id']=$newUuid(180);$wanderTurn['request_id']=$newUuid(181);$wanderTurn['turn_id']=$newUuid(182);
$wanderTurn['payload']['input']['text']='Wander around for a while.';$wanderTurn['payload']['recent_action_results']=[];
[$status]=$call($router,'POST',$base.'/turns',$headers($wanderTurn['message_id']),[],$wanderTurn);
$assert($status===202,'wander turn acceptance failed');$runTurnWorker(new MockProvider());
[$status,$wanderEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$inspectEvents['next_after']]);
$wanderIntents=array_values(array_filter($wanderEvents['events'],static fn(array $e):bool=>$e['type']==='action.intent'));
$assert($status===200&&count($wanderIntents)===1&&$wanderIntents[0]['payload']['name']==='ai.wander'
    &&$wanderIntents[0]['payload']['parameters']===['distance'=>512,'duration_seconds'=>3600],'ai.wander was not emitted E2E');

$combatTurn=$turn;$combatTurn['message_id']=$newUuid(183);$combatTurn['request_id']=$newUuid(184);$combatTurn['turn_id']=$newUuid(185);
$combatTurn['payload']['input']['text']='Attack that target.';$combatTurn['payload']['recent_action_results']=[];
[$status]=$call($router,'POST',$base.'/turns',$headers($combatTurn['message_id']),[],$combatTurn);
$assert($status===202,'combat turn acceptance failed');$runTurnWorker(new MockProvider());
[$status,$combatEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$wanderEvents['next_after']]);
$combatIntents=array_values(array_filter($combatEvents['events'],static fn(array $e):bool=>$e['type']==='action.intent'));
$assert($status===200&&count($combatIntents)===1&&$combatIntents[0]['payload']['name']==='combat.start'
    &&$combatIntents[0]['payload']['tier']===2,'combat.start tier-2 proposal was not emitted E2E');

// Authenticated binary STT is durable and returns its transcript through the session event stream.
$sttAudio=(new MockSpeechProvider())->synthesize('pre-turn stt',new \LorkhanServer\Application\NeverCancelledToken())['bytes'];
$sttMessage=$newUuid(90);$sttRequest=$newUuid(91);$sttTurn=$newUuid(92);$sttCreated=gmdate('Y-m-d\TH:i:s\Z');
$sttHeaders=['Content-Type'=>'application/octet-stream','Idempotency-Key'=>$sttMessage,
    'X-LORKHAN-Schema'=>'lorkhan.stt.request.v1','X-LORKHAN-Message-Id'=>$sttMessage,'X-LORKHAN-Request-Id'=>$sttRequest,
    'X-LORKHAN-Turn-Id'=>$sttTurn,'X-LORKHAN-Session-Id'=>$sessionId,'X-LORKHAN-Generation'=>'7','X-LORKHAN-Created-At'=>$sttCreated,
    'X-LORKHAN-Codec'=>'wav','X-LORKHAN-Language'=>'en-US','X-LORKHAN-Audio-Bytes'=>(string)strlen($sttAudio),
    'X-LORKHAN-Sha256'=>hash('sha256',$sttAudio)];
[$status,$sttAccepted]=$call($router,'POST',$base.'/stt',$sttHeaders,[],$sttAudio);
$assert($status===202&&!$sttAccepted['duplicate'],'typed STT route did not durably accept audio');
$assert(in_array('stt.process',FirstPartyJobHandlerFactory::jobTypes(),true),'STT worker was not registered');
$sttWorker=$runWorker(['stt.process'],null,new MockSpeechToTextProvider());
$assert($sttWorker===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0],'STT worker did not complete transcription');
[$status,$sttEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$combatEvents['next_after']]);
$assert($status===200&&array_column($sttEvents['events'],'type')===['stt.transcript']
    &&($sttEvents['events'][0]['payload']['text']??'')!=='','STT transcript event was not emitted');

$autonomyExcluded=false;
try{(new ProductService($products,new DeterministicClock()))->scheduleAutonomy([
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],
    'playthrough_id'=>$session['playthrough_id'],'kind'=>'rechat','enabled'=>true,'interval_seconds'=>30,
    'cooldown_seconds'=>30,'current_session_id'=>$sessionId,'confirmed_at'=>gmdate('Y-m-d\TH:i:s\Z')]);}
catch(InvalidArgumentException $error){$autonomyExcluded=$error->getMessage()==='feature_excluded';}
$assert($autonomyExcluded,'excluded autonomy scheduling remained reachable');
[$status,$autonomyEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$sttEvents['next_after']]);
$assert($status===200&&$autonomyEvents['autonomy']===[],'excluded autonomy directive was delivered');

// Player menu actions bypass the provider but retain the same authenticated turn and action policy boundary.
$menuTurn=$turn;$menuTurn['message_id']=$newUuid(280);$menuTurn['request_id']=$newUuid(281);$menuTurn['turn_id']=$newUuid(282);
$menuTurn['payload']['input']['text']='Wait here';$menuTurn['payload']['recent_action_results']=[];
$menuTurn['payload']['action_request']=['name'=>'ai.wait','tier'=>1,'parameters'=>['duration_seconds'=>3600]];
[$status,$menuAccepted]=$call($router,'POST',$base.'/turns',$headers($menuTurn['message_id']),[],$menuTurn);
$assert($status===202,'typed player action turn acceptance failed: '.$status.' '.json_encode($menuAccepted));
[$status,$menuEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$autonomyEvents['next_after']]);
$menuTypes=array_column($menuEvents['events'],'type');
$menuIntents=array_values(array_filter($menuEvents['events'],static fn(array $e):bool=>$e['type']==='action.intent'));
$menuJobs=$db->prepare("SELECT count(*) FROM durable_jobs WHERE job_type='turn.process' AND payload->>'turn_id'=:turn");
$menuJobs->execute(['turn'=>$menuTurn['turn_id']]);
$assert($status===200&&$menuTypes===['turn.accepted','response.complete','action.intent','turn.complete']
    &&count($menuIntents)===1&&$menuIntents[0]['payload']['name']==='ai.wait'
    &&$menuIntents[0]['payload']['parameters']===['duration_seconds'=>3600]
    &&$menuAccepted['event_cursor']===$menuEvents['next_after']&&(int)$menuJobs->fetchColumn()===0,
    'typed player action did not follow the direct policy-validated path');

// A second combat target must be a different actor present in the bounded nearby snapshot.
$secondaryTarget=$menuTurn['payload']['target'];$secondaryTarget['record_id']='mudcrab';
$secondaryTarget['display_name']='Mudcrab';$secondaryTarget['kind']='creature';$secondaryTarget['refnum']['index']=113;
$invalidTargetTurn=$turn;$invalidTargetTurn['message_id']=$newUuid(283);$invalidTargetTurn['request_id']=$newUuid(284);
$invalidTargetTurn['turn_id']=$newUuid(285);$invalidTargetTurn['payload']['input']['text']='Attack the selected target';
$invalidTargetTurn['payload']['action_request']=['name'=>'combat.start','tier'=>2,'parameters'=>[],'target'=>$secondaryTarget];
[$status,$invalidTargetError]=$call($router,'POST',$base.'/turns',$headers($invalidTargetTurn['message_id']),[],$invalidTargetTurn);
$assert($status===409&&$invalidTargetError['code']==='action_target_invalid',
    'secondary action target outside nearby context was accepted');

$targetedTurn=$invalidTargetTurn;$targetedTurn['message_id']=$newUuid(286);$targetedTurn['request_id']=$newUuid(287);
$targetedTurn['turn_id']=$newUuid(288);$targetedTurn['payload']['context']['nearbyActors']=[
    'items'=>[$secondaryTarget],'total'=>1,'truncated'=>false];
[$status,$targetedAccepted]=$call($router,'POST',$base.'/turns',$headers($targetedTurn['message_id']),[],$targetedTurn);
$assert($status===202,'secondary-target combat action was rejected');
[$status,$targetedEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$menuEvents['next_after']]);
$targetedIntents=array_values(array_filter($targetedEvents['events'],static fn(array $e):bool=>$e['type']==='action.intent'));
$assert($status===200&&array_column($targetedEvents['events'],'type')===['turn.accepted','response.complete','action.intent','turn.complete']
    &&count($targetedIntents)===1&&$targetedIntents[0]['payload']['actor']==$targetedTurn['payload']['target']
    &&$targetedIntents[0]['payload']['target']==$secondaryTarget
    &&$targetedAccepted['event_cursor']===$targetedEvents['next_after'],
    'secondary-target combat action did not preserve actor and target identities');

// Aimed movement coordinates follow the direct action path and reject fields outside the catalog schema.
$destination=['destination_x'=>1024.5,'destination_y'=>-256,'destination_z'=>32,
    'destination_cell'=>'exterior:0:0'];
$travelTurn=$turn;$travelTurn['message_id']=$newUuid(289);$travelTurn['request_id']=$newUuid(290);$travelTurn['turn_id']=$newUuid(291);
$travelTurn['payload']['input']['text']='Go to selected destination';$travelTurn['payload']['recent_action_results']=[];
$travelTurn['payload']['action_request']=['name'=>'ai.travel','tier'=>1,'parameters'=>$destination];
[$status,$travelAccepted]=$call($router,'POST',$base.'/turns',$headers($travelTurn['message_id']),[],$travelTurn);
$assert($status===202,'same-cell travel action was rejected');
[$status,$travelEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$targetedEvents['next_after']]);
$travelIntents=array_values(array_filter($travelEvents['events'],static fn(array $e):bool=>$e['type']==='action.intent'));
$assert($status===200&&array_column($travelEvents['events'],'type')===['turn.accepted','response.complete','action.intent','turn.complete']
    &&count($travelIntents)===1&&$travelIntents[0]['payload']['name']==='ai.travel'
    &&$travelIntents[0]['payload']['parameters']===$destination
    &&$travelAccepted['event_cursor']===$travelEvents['next_after'],'travel destination was not preserved end to end');

$escortTurn=$turn;$escortTurn['message_id']=$newUuid(292);$escortTurn['request_id']=$newUuid(293);$escortTurn['turn_id']=$newUuid(294);
$escortTurn['payload']['input']['text']='Escort me to selected destination';$escortTurn['payload']['recent_action_results']=[];
$escortTurn['payload']['action_request']=['name'=>'ai.escort','tier'=>1,'parameters'=>$destination];
[$status,$escortAccepted]=$call($router,'POST',$base.'/turns',$headers($escortTurn['message_id']),[],$escortTurn);
$assert($status===202,'same-cell escort action was rejected');
[$status,$escortEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$travelEvents['next_after']]);
$escortIntents=array_values(array_filter($escortEvents['events'],static fn(array $e):bool=>$e['type']==='action.intent'));
$assert($status===200&&count($escortIntents)===1&&$escortIntents[0]['payload']['name']==='ai.escort'
    &&$escortIntents[0]['payload']['target']==$escortTurn['payload']['speaker']
    &&$escortAccepted['event_cursor']===$escortEvents['next_after'],'escort action did not retain the player target: '.json_encode([
        'status'=>$status,'intent_count'=>count($escortIntents),'intent'=>$escortIntents[0]['payload']??null,
        'speaker'=>$escortTurn['payload']['speaker'],'accepted_cursor'=>$escortAccepted['event_cursor']??null,
        'next_after'=>$escortEvents['next_after']??null]));

$faceTurn=$turn;$faceTurn['message_id']=$newUuid(298);$faceTurn['request_id']=$newUuid(299);$faceTurn['turn_id']=$newUuid(300);
$faceTurn['payload']['input']['text']='Face me';$faceTurn['payload']['recent_action_results']=[];
$faceTurn['payload']['action_request']=['name'=>'ai.face','tier'=>1,'parameters'=>[]];
[$status,$faceAccepted]=$call($router,'POST',$base.'/turns',$headers($faceTurn['message_id']),[],$faceTurn);
$assert($status===202,'face-player action was rejected');
[$status,$faceEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$escortEvents['next_after']]);
$faceIntents=array_values(array_filter($faceEvents['events'],static fn(array $e):bool=>$e['type']==='action.intent'));
$assert($status===200&&count($faceIntents)===1&&$faceIntents[0]['payload']['name']==='ai.face'
    &&$faceIntents[0]['payload']['target']==$faceTurn['payload']['speaker']
    &&$faceAccepted['event_cursor']===$faceEvents['next_after'],'face-player action did not retain the player target');

$faceTargetTurn=$faceTurn;$faceTargetTurn['message_id']=$newUuid(301);$faceTargetTurn['request_id']=$newUuid(302);
$faceTargetTurn['turn_id']=$newUuid(303);$faceTargetTurn['payload']['input']['text']='Face selected target';
$faceTargetTurn['payload']['context']['nearbyActors']=['items'=>[$secondaryTarget],'total'=>1,'truncated'=>false];
$faceTargetTurn['payload']['action_request']['target']=$secondaryTarget;
[$status,$faceTargetAccepted]=$call($router,'POST',$base.'/turns',$headers($faceTargetTurn['message_id']),[],$faceTargetTurn);
$assert($status===202,'secondary-target face action was rejected');
[$status,$faceTargetEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$faceEvents['next_after']]);
$faceTargetIntents=array_values(array_filter($faceTargetEvents['events'],static fn(array $e):bool=>$e['type']==='action.intent'));
$assert($status===200&&count($faceTargetIntents)===1&&$faceTargetIntents[0]['payload']['target']==$secondaryTarget
    &&$faceTargetAccepted['event_cursor']===$faceTargetEvents['next_after'],'secondary face target was not preserved');

$invalidTravel=$turn;$invalidTravel['message_id']=$newUuid(295);$invalidTravel['request_id']=$newUuid(296);$invalidTravel['turn_id']=$newUuid(297);
$invalidTravel['payload']['input']['text']='Unsafe travel';$invalidTravel['payload']['action_request']=[
    'name'=>'ai.travel','tier'=>1,'parameters'=>$destination+['teleport'=>true]];
[$status,$invalidTravelError]=$call($router,'POST',$base.'/turns',$headers($invalidTravel['message_id']),[],$invalidTravel);
$assert($status===409&&$invalidTravelError['code']==='action_parameters_invalid',
    'unknown travel coordinate field passed catalog validation: '.$status.' '.json_encode($invalidTravelError));

// Rechat is a playback-gated continuation of a player-started chain, not timer-driven autonomy.
$rechatChainId=$newUuid(838);
$rechatTurn=$turn;
$rechatTurn['message_id']=$newUuid(821);$rechatTurn['request_id']=$newUuid(822);$rechatTurn['turn_id']=$newUuid(823);
$rechatTurn['payload']['ui_source']='lorkhan_rechat';
$rechatTurn['payload']['input']['text']='Please follow me again.';
$rechatTurn['payload']['recent_action_results']=[];
$rechatTurn['payload']['audience']=[$dialogueEvent['payload']['speaker'],$secondaryTarget];
$rechatTurn['payload']['context']['dialogueMode']='Close';
$rechatTurn['payload']['context']['rechat']=['speaker'=>$dialogueEvent['payload']['speaker'],
    'listener_hint'=>$turn['payload']['speaker'],'rechat_target_hint'=>$secondaryTarget,
    'origin_line'=>$turn['payload']['input']['text'],'rechat_depth'=>1,'chain_id'=>$rechatChainId,
    'origin_turn_id'=>$turn['turn_id']];
$speakerIdentity=$dialogueEvent['payload']['speaker'];
$thirdTarget=$secondaryTarget;$thirdTarget['record_id']='vivec_guard';$thirdTarget['display_name']='Vivec Guard';
$thirdTarget['kind']='npc';$thirdTarget['refnum']['index']=114;
$products->bindActorProfile(['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']],
    $secondaryTarget,$actorProfile['profile_id'],$now);
$products->bindActorProfile(['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']],
    $thirdTarget,$actorProfile['profile_id'],$now);
$participantRow=static fn(array $identity,string $state):array=>['identity'=>$identity,'state'=>$state];
$resolveRechatError=static function(array $message)use($rechatCoordinator):string{
    try{$rechatCoordinator->resolve($message);return '';}
    catch(DomainException $error){return $error->getMessage();}
};

$eligibleProbe=$rechatTurn;
$eligibleProbe['payload']['audience']=[$speakerIdentity,$secondaryTarget,$thirdTarget];
$eligibleProbe['payload']['context']['rechat']['participant_states']=[
    ['state'=>'active','identity'=>$speakerIdentity],
    $participantRow($secondaryTarget,'busy'),
    $participantRow($thirdTarget,'active'),
];
$eligibleResolved=$rechatCoordinator->resolve($eligibleProbe);
$db->beginTransaction();
$modeOwner=$products->effectiveSettingsForActor($installationId,$session['playthrough_id'],$speakerIdentity)['core_profile'];
$modeContent=$modeOwner['content'];$modeContent['settings_overrides']['behavior']['rechat_mode']='group';
$products->revise('core_profile',$modeOwner['core_profile_id'],$modeContent,'Core mode fixture',$now);
$modeProbe=$rechatCoordinator->resolve($eligibleProbe);
$assert($modeProbe['payload']['context']['rechat']['configured_mode']==='group'
    &&$modeProbe['payload']['context']['rechat']['mode']==='group','Rechat coordinator ignored initiating Core mode');
$modeContent['settings_overrides']['behavior']['open_rechat']=false;
$modeContent['settings_overrides']['behavior']['rechat_strict_targeting']=true;
$products->revise('core_profile',$modeOwner['core_profile_id'],$modeContent,'Core Rechat participation fixture',$now);
$tightProbe=$eligibleProbe;$tightProbe['payload']['context']['rechat']['listener_hint']=$thirdTarget;
$tightResolved=$rechatCoordinator->resolve($tightProbe);
$assert($tightResolved['payload']['context']['rechat']['mode']==='tight'
    &&$tightResolved['payload']['target']===$thirdTarget
    &&$tightResolved['payload']['context']['rechat']['strict_targeting']===true,
    'Core Open Rechat or strict responder setting did not reach chain selection');
$db->rollBack();
$assert($eligibleResolved['payload']['target']===$thirdTarget
    &&$eligibleResolved['payload']['audience']===[$speakerIdentity,$secondaryTarget,$thirdTarget]
    &&count($eligibleResolved['payload']['context']['rechat']['participant_states'])===3,
    'fresh rechat eligibility did not skip a busy candidate or accept key-order-independent rows');

$sleepingProbe=$eligibleProbe;
$sleepingProbe['payload']['audience']=[$speakerIdentity,$secondaryTarget];
$sleepingProbe['payload']['context']['rechat']['participant_states']=[
    $participantRow($speakerIdentity,'active'),$participantRow($secondaryTarget,'sleeping')];
$sleepingResolved=$rechatCoordinator->resolve($sleepingProbe);
$assert($sleepingResolved['payload']['target']===$secondaryTarget,
    'directly addressed sleeping rechat target was rejected');
$sleepingBystander=$eligibleProbe;
$sleepingBystander['payload']['context']['rechat']['rechat_target_hint']=$thirdTarget;
$sleepingBystander['payload']['context']['rechat']['participant_states']=[
    $participantRow($speakerIdentity,'active'),$participantRow($secondaryTarget,'sleeping'),
    $participantRow($thirdTarget,'active')];
$assert($rechatCoordinator->resolve($sleepingBystander)['payload']['target']===$thirdTarget,
    'sleeping bystander was not excluded from rechat selection');

foreach(['busy','unconscious','inactive'] as $blockedState){
    $blockedSpeaker=$sleepingProbe;
    $blockedSpeaker['payload']['context']['rechat']['participant_states'][0]['state']=$blockedState;
    $assert($resolveRechatError($blockedSpeaker)==='rechat_no_responder',
        'blocked previous speaker state was accepted: '.$blockedState);
}
$missingSpeaker=$sleepingProbe;
$missingSpeaker['payload']['context']['rechat']['participant_states']=[$participantRow($secondaryTarget,'active')];
$assert($resolveRechatError($missingSpeaker)==='invalid_rechat_context',
    'participant snapshot without the previous speaker was accepted');
$duplicateSnapshot=$sleepingProbe;
$duplicateSnapshot['payload']['context']['rechat']['participant_states'][]=$participantRow($secondaryTarget,'active');
$assert($resolveRechatError($duplicateSnapshot)==='invalid_rechat_context',
    'duplicate participant state was accepted');
$foreignSnapshot=$sleepingProbe;$foreignSnapshot['payload']['context']['rechat']['participant_states'][]=
    $participantRow($thirdTarget,'active');
$assert($resolveRechatError($foreignSnapshot)==='invalid_rechat_context',
    'participant state outside the submitted rechat identities was accepted');
$playerSnapshot=$sleepingProbe;$playerSnapshot['payload']['context']['rechat']['participant_states'][]=
    $participantRow($turn['payload']['speaker'],'active');
$assert($resolveRechatError($playerSnapshot)==='invalid_rechat_context',
    'non-actor participant state was accepted');
$legacyResolved=$rechatCoordinator->resolve($rechatTurn);
$assert(!array_key_exists('participant_states',$legacyResolved['payload']['context']['rechat']),
    'legacy rechat unexpectedly required or synthesized participant state');

$disabledTarget=$thirdTarget;$disabledTarget['record_id']='disabled_rechat_actor';
$disabledTarget['display_name']='Disabled Rechat Actor';$disabledTarget['refnum']['index']=115;
$disabledCore=$products->createRevisioned('core_profile',['installation_id'=>$installationId,
    'name'=>'Disabled rechat Core Profile','default_npc'=>false,'slot'=>null,
    'content'=>['schema'=>'lorkhan.core-profile.v1','prompt'=>'','routing'=>[],
        'settings_overrides'=>['behavior'=>['rechat'=>false]]]],$now);
$disabledProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,
    'name'=>'Disabled rechat actor','actor_identity'=>['record_id'=>'disabled_rechat_actor'],
    'core_profile_id'=>$disabledCore['core_profile_id'],'content'=>[]],$now);
$products->bindActorProfile(['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']],
    $disabledTarget,$disabledProfile['profile_id'],$now);
$disabledProbe=$rechatTurn;$disabledProbe['payload']['audience']=[$speakerIdentity,$disabledTarget];
$disabledProbe['payload']['context']['rechat']['rechat_target_hint']=$disabledTarget;
$disabledProbe['payload']['context']['rechat']['participant_states']=[
    $participantRow($speakerIdentity,'active'),$participantRow($disabledTarget,'active')];
$assert($resolveRechatError($disabledProbe)==='rechat_no_responder',
    'selected responder with effective rechat disabled was accepted');

$rechatTurn['payload']['context']['rechat']['participant_states']=[
    $participantRow($speakerIdentity,'active'),$participantRow($secondaryTarget,'active')];
$disabledActionProbe=$rechatTurn;
$disabledActionProbe['payload']['context']['rechat']['allow_actions']=true;
$disabledActionProbe=$rechatCoordinator->resolve($disabledActionProbe);
$assert(($disabledActionProbe['payload']['context']['rechat']['allow_actions']??null)===false,
    'Rechat actions did not retain the disabled default or reject the client-supplied policy flag');
$rechatGlobal=$products->globalSettingsForInstallation($installationId);
$rechatGlobalContent=$rechatGlobal['content'];
$rechatGlobalContent['client']['behavior']['rechat_allow_actions']=true;
$products->revise('global_settings',$rechatGlobal['configuration_id'],$rechatGlobalContent,'enable Rechat actions',$now);
[$status,$rechatAccepted]=$call($router,'POST',$base.'/turns',$headers($rechatTurn['message_id']),[],$rechatTurn);
$assert($status===202,'first typed rechat continuation was rejected: '.$status.' '.json_encode($rechatAccepted));
$rechatPrompt=$db->prepare('SELECT source_manifest FROM turn_provider_snapshots WHERE turn_id=:turn');
$rechatPrompt->execute(['turn'=>$rechatTurn['turn_id']]);
$rechatManifest=json_decode((string)$rechatPrompt->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$rechatWorker=$runTurnWorker(new MockProvider());
$rechatState=$db->prepare('SELECT state,configured_mode,mode,current_depth,max_depth,round_budget,origin_turn_id,latest_turn_id FROM rechat_chains WHERE chain_id=:chain');
$rechatState->execute(['chain'=>$rechatChainId]);$firstRechatState=$rechatState->fetch();
$rechatActions=$db->prepare("SELECT count(*) FROM response_events WHERE turn_id=:turn AND event_type='action.intent'");
$rechatActions->execute(['turn'=>$rechatTurn['turn_id']]);
$rechatActionCount=(int)$rechatActions->fetchColumn();
$assembledRechatPrompt=(string)($rechatManifest['message']['_prompt']['_assembled_prompt']??'');
$rechatMessages=$rechatManifest['message']['_prompt']['_messages']??[];
$rechatDefinitions=$rechatManifest['message']['_allowed_action_definitions']??[];
    $assert($rechatWorker===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0]
    &&is_array($rechatMessages)&&array_is_list($rechatMessages)&&count($rechatMessages)===2
    &&($rechatMessages[0]['role']??null)==='system'
    &&($rechatMessages[array_key_last($rechatMessages)]['role']??null)==='user'
    &&str_contains((string)($rechatMessages[array_key_last($rechatMessages)]['content']??''),'Dialogue turn for Mudcrab.')
    &&str_contains((string)($rechatMessages[array_key_last($rechatMessages)]['content']??''),'Close mode audience:')
    &&str_contains((string)($rechatMessages[array_key_last($rechatMessages)]['content']??''),$secondaryTarget['display_name'])
    &&str_contains((string)($rechatMessages[array_key_last($rechatMessages)]['content']??''),$speakerIdentity['display_name'])
    &&!str_contains($assembledRechatPrompt,'Please follow me.')
    &&!str_contains($assembledRechatPrompt,'"type":"turn.requested"')
    &&!str_contains($assembledRechatPrompt,'[fallback] Continue after the primary provider fails.')
    &&$firstRechatState&&$firstRechatState['state']==='awaiting_playback'
    &&$firstRechatState['configured_mode']==='random'&&$firstRechatState['mode']==='conversational'
    &&(int)$firstRechatState['current_depth']===1&&(int)$firstRechatState['max_depth']===2
    &&(int)$firstRechatState['round_budget']===2
    &&$firstRechatState['origin_turn_id']===$turn['turn_id']&&$firstRechatState['latest_turn_id']===$rechatTurn['turn_id']
    &&is_array($rechatDefinitions)&&count($rechatDefinitions)>0
    &&$rechatActionCount===1,
    'enabled Rechat did not preserve CHIM history, chain state, or policy-checked action generation: '.json_encode([
        'worker'=>$rechatWorker,'message_count'=>is_array($rechatMessages)?count($rechatMessages):null,
        'state'=>$firstRechatState,'action_count'=>$rechatActionCount,'definition_count'=>count($rechatDefinitions)]));

$finalRechat=$rechatTurn;
$finalRechat['message_id']=$newUuid(824);$finalRechat['request_id']=$newUuid(825);$finalRechat['turn_id']=$newUuid(826);
$finalRechat['payload']['input']['text']='Continue the conversation.';
$finalRechat['payload']['context']['rechat']['rechat_depth']=2;
$finalRechat['payload']['context']['rechat']['speaker']=$secondaryTarget;
$finalRechat['payload']['context']['rechat']['listener_hint']=$turn['payload']['speaker'];
[$status,$finalRechatAccepted]=$call($router,'POST',$base.'/turns',$headers($finalRechat['message_id']),[],$finalRechat);
$finalRechatWorker=$status===202?$runTurnWorker(new MockProvider()):[];
$rechatState->execute(['chain'=>$rechatChainId]);$closedRechatState=$rechatState->fetch();
$rechatSources=$db->prepare("SELECT count(*) FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid WHERE m.session_id=:session AND e.type='rechat' AND m.turn_id IN (:first,:second)");
$rechatSources->execute(['session'=>$sessionId,'first'=>$rechatTurn['turn_id'],'second'=>$finalRechat['turn_id']]);
$assert($status===202&&$finalRechatWorker===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0]
    &&$closedRechatState&&$closedRechatState['state']==='closed'&&(int)$closedRechatState['current_depth']===2
    &&$closedRechatState['origin_turn_id']===$turn['turn_id']
    &&$closedRechatState['latest_turn_id']===$finalRechat['turn_id']&&(int)$rechatSources->fetchColumn()===2,
    'rechat chain did not close deterministically at its configured maximum depth');

$cooldownRechat=$rechatTurn;
$cooldownRechat['message_id']=$newUuid(827);$cooldownRechat['request_id']=$newUuid(828);$cooldownRechat['turn_id']=$newUuid(829);
$cooldownRechat['payload']['context']['rechat']['chain_id']=$newUuid(839);
$assert($resolveRechatError($cooldownRechat)==='',
    'normal chain exhaustion incorrectly applied End Conversation cooldown');
$endTurn=$turn;
foreach(['message_id','request_id','turn_id']as$key)$endTurn[$key]=\LorkhanServer\Infrastructure\Uuid::v4();
$endTurn['payload']['input']['text']='End the conversation.';
$endTurn['payload']['ui_source']='lorkhan_actions';
$endTurn['payload']['action_request']=['name'=>'conversation.end','tier'=>1,'parameters'=>[]];
$endTurn['payload']['recent_action_results']=[];
[$status,$endAccepted]=$call($router,'POST',$base.'/turns',$headers($endTurn['message_id']),[],$endTurn);
$assert($status===202,'End Conversation action was not accepted: '.json_encode($endAccepted));
$endQuery=$db->prepare("SELECT action_id FROM action_intents WHERE turn_id=:turn AND action_name='conversation.end'");
$endQuery->execute(['turn'=>$endTurn['turn_id']]);$endActionId=$endQuery->fetchColumn();
$assert(is_string($endActionId),'End Conversation did not emit an action intent');
$endResult=$fixture('action-result');$endResult['message_id']=\LorkhanServer\Infrastructure\Uuid::v4();
$endResult['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();$endResult['action_id']=$endActionId;
$endResult['session_id']=$sessionId;$endResult['generation']=7;$endResult['turn_id']=$endTurn['turn_id'];
$endResult['status']='succeeded';$endResult['reason_code']='conversation_ended';$endResult['observed']=[];
$endResult['completed_at']=gmdate('Y-m-d\TH:i:s\Z');
[$status,$endReceipt]=$call($router,'POST',$base.'/action-results',$headers($endResult['message_id']),[],$endResult);
$assert($status===200&&!($endReceipt['duplicate']??true),'End Conversation result failed: '.json_encode($endReceipt));
$blockedTurn=$turn;foreach(['message_id','request_id','turn_id']as$key)$blockedTurn[$key]=\LorkhanServer\Infrastructure\Uuid::v4();
[$status,$blockedEnd]=$call($router,'POST',$base.'/turns',$headers($blockedTurn['message_id']),[],$blockedTurn);
$assert($status===409&&($blockedEnd['code']??null)==='conversation_cooldown','Ended NPC accepted direct dialogue during cooldown');
// Keep subsequent isolated tests independent of this deliberately completed conversation.
$db->prepare("UPDATE action_results SET completed_at=clock_timestamp()-interval '301 seconds' WHERE action_id=:id")
    ->execute(['id'=>$endActionId]);
$db->beginTransaction();
try{
    $db->prepare("UPDATE action_intents SET action_name='conversation.end',actor=CAST(:actor AS jsonb) WHERE action_id=:id")
        ->execute(['actor'=>json_encode($speakerIdentity,JSON_THROW_ON_ERROR),'id'=>$result['action_id']]);
    $db->prepare("UPDATE action_results SET status='succeeded',completed_at=clock_timestamp() WHERE action_id=:id")
        ->execute(['id'=>$result['action_id']]);
    $assert($repo->conversationCooldownActive($installationId,$session['playthrough_id'],$speakerIdentity,60),
        'successful End Conversation receipt did not start actor cooldown');
    $movedActor=$speakerIdentity;$movedActor['display_name']='Changed display name';$movedActor['cell']=['kind'=>'interior','name'=>'Balmora'];
    $assert($repo->conversationCooldownActive($installationId,$session['playthrough_id'],$movedActor,60),
        'actor movement or display name bypassed conversation cooldown');
    $assert(!$repo->conversationCooldownActive($installationId,$session['playthrough_id'],$thirdTarget,60)
        &&!$repo->conversationCooldownActive($installationId,$session['playthrough_id'],$speakerIdentity,0),
        'conversation cooldown affected an unrelated NPC or ignored zero seconds');
    $db->prepare('UPDATE sessions SET generation=generation+1 WHERE session_id=:id')->execute(['id'=>$sessionId]);
    $assert(!$repo->conversationCooldownActive($installationId,$session['playthrough_id'],$speakerIdentity,60),
        'conversation cooldown survived its session generation fence');
    $db->prepare('UPDATE sessions SET generation=generation-1 WHERE session_id=:id')->execute(['id'=>$sessionId]);
    $db->prepare("UPDATE action_results SET status='failed' WHERE action_id=:id")->execute(['id'=>$result['action_id']]);
    $assert(!$repo->conversationCooldownActive($installationId,$session['playthrough_id'],$speakerIdentity,60),
        'failed End Conversation action started cooldown');
}finally{$db->rollBack();}

foreach([
    ['topic'=>'sixth_house','aliases'=>'House Dagoth','content'=>'The Sixth House is the hidden House Dagoth.'],
    ['topic'=>'vivec','aliases'=>'Warrior-Poet','content'=>'Vivec is one of the living gods of the Tribunal.'],
]as$oghmaRow)$products->createKnowledge([
    'installation_id'=>$installationId,'profile_id'=>null,'playthrough_id'=>null,'title'=>$oghmaRow['topic'],
    'content'=>$oghmaRow['content'],'provenance'=>['source'=>'authored-test'],'topic'=>$oghmaRow['topic'],
    'aliases'=>$oghmaRow['aliases'],'topic_desc_basic'=>$oghmaRow['content'],'knowledge_class'=>'',
    'knowledge_class_basic'=>'','tags'=>'','category'=>'lore',
],preg_split('/[^a-z0-9]+/',strtolower($oghmaRow['topic']))?:[],$now);
$oghmaGlobal=$products->globalSettingsForInstallation($installationId);$oghmaGlobalContent=$oghmaGlobal['content'];
$oghmaGlobalContent['oghma']=array_replace($oghmaGlobalContent['oghma'],['enabled'=>true,'knowledge_tags'=>'',
    'racial_context_enabled'=>false,'location_context_enabled'=>false,'topic_count'=>2,
    'extractor_fallback_enabled'=>true,'extractor_enabled'=>true]);
$products->revise('global_settings',$oghmaGlobal['configuration_id'],$oghmaGlobalContent,'enable Oghma integration',$now);
$oghmaTurn=$turn;$oghmaTurn['message_id']=$newUuid(840);$oghmaTurn['request_id']=$newUuid(841);$oghmaTurn['turn_id']=$newUuid(842);
$oghmaTurn['payload']['input']['text']='Tell me about House Dagoth and Vivec.';
[$status,$oghmaAccepted]=$call($router,'POST',$base.'/turns',$headers($oghmaTurn['message_id']),[],$oghmaTurn);
$oghmaSnapshotStatement=$db->prepare('SELECT source_manifest FROM turn_provider_snapshots WHERE turn_id=:turn');
$oghmaSnapshotStatement->execute(['turn'=>$oghmaTurn['turn_id']]);
$oghmaSnapshot=json_decode((string)$oghmaSnapshotStatement->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$oghmaTraceStatement=$db->prepare("SELECT result_ids,algorithm,reasons FROM retrieval_traces WHERE turn_id=:turn AND domain='knowledge'");
$oghmaTraceStatement->execute(['turn'=>$oghmaTurn['turn_id']]);$oghmaTrace=$oghmaTraceStatement->fetch();
$oghmaReasons=$oghmaTrace?json_decode((string)$oghmaTrace['reasons'],true,64,JSON_THROW_ON_ERROR):[];
$preWorkerAttempts=$db->prepare('SELECT count(*) FROM provider_attempts WHERE turn_id=:turn');
$preWorkerAttempts->execute(['turn'=>$oghmaTurn['turn_id']]);$preWorkerAttemptCount=(int)$preWorkerAttempts->fetchColumn();
$oghmaPrompt=(string)($oghmaSnapshot['message']['_prompt']['_assembled_prompt']??'');
$assert($status===202&&$oghmaAccepted['turn_id']===$oghmaTurn['turn_id']&&$preWorkerAttemptCount===0
    &&($oghmaTrace['algorithm']??null)==='oghma-parity-v1'
    &&($oghmaReasons['_context']['extractor_status']??null)==='grounded'
    &&($oghmaReasons['_context']['extracted_topics']??[])===['sixth_house','vivec']
    &&str_contains($oghmaPrompt,'The Sixth House is the hidden House Dagoth.')
    &&str_contains($oghmaPrompt,'Vivec is one of the living gods of the Tribunal.'),
    'grounded Oghma turn did not avoid connector extraction and inject exact ordered catalog articles: '.json_encode([
        'status'=>$status,'attempts'=>$preWorkerAttemptCount,'trace'=>$oghmaTrace,'reasons'=>$oghmaReasons],JSON_UNESCAPED_SLASHES));
$oghmaWorker=$runTurnWorker(new MockProvider());
$auditRows=(new \LorkhanServer\Infrastructure\ManagementUiRepository($db))->oghmaAudit(['search'=>'Tell me about House Dagoth and Vivec.','matched'=>'matched']);
$auditRow=current(array_filter($auditRows['rows'],static fn(array $row):bool=>$row['turn_id']===$oghmaTurn['turn_id']));
$assert(is_array($auditRow) && is_array($auditRow['result_ids']) && count($auditRow['result_ids'])===2
    && $auditRow['input_kind']===$oghmaTurn['payload']['input']['kind']
    && $auditRow['input_text']===$oghmaTurn['payload']['input']['text'], 'Oghma audit reader lost native selected IDs or recorded input metadata: '.json_encode($auditRow));
$assert($oghmaWorker===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0],
    'grounded Oghma turn did not complete through the normal response pipeline');

$db->beginTransaction();
try{
    $dynamic=$products->dynamicOghma();
    $dynamicRule=['id_quest'=>'parity_dynamic_quest','stage'=>10,'topic'=>'vivec','topic_desc'=>'Vivec acknowledges the completed parity quest.',
        'knowledge_class'=>'','topic_desc_basic'=>'clearall','knowledge_class_basic'=>'','tags'=>'','category'=>''];
    $dynamic->save($installationId,[$dynamicRule,array_replace($dynamicRule,['stage'=>20,'topic_desc'=>'clearall','topic_desc_basic'=>'Vivec has a new public account of the quest.','knowledge_class_basic'=>'common','tags'=>'clearall']),
        array_replace($dynamicRule,['stage'=>30,'topic'=>'parity_new_lore','topic_desc'=>'New lore appeared after the final quest stage.','topic_desc_basic'=>''])]);
    $dynamicTurn=$oghmaTurn;$dynamicTurn['message_id']=$newUuid(996001);$dynamicTurn['request_id']=$newUuid(996002);$dynamicTurn['turn_id']=$newUuid(996003);
    $dynamicTurn['payload']['input']['text']='Tell me about Vivec.';
    $dynamicTurn['payload']['context']['journal']=['items'=>[['quest_id'=>'PARITY_DYNAMIC_QUEST','id'=>'31540100981975929470','stage'=>10,'text'=>'The quest reached stage ten.']],'truncated'=>false];
    $legacyJournalTurn=$dynamicTurn;unset($legacyJournalTurn['payload']['context']['journal']['items'][0]['stage']);
    $assert($dynamic->plan($legacyJournalTurn)===[],'journal entry identifiers must never be interpreted as quest stages');
    $beforeDynamicSources=(int)$db->query('SELECT count(*) FROM source_events')->fetchColumn();
    $preview=$dynamic->plan($dynamicTurn);
    $assert(count($preview)===1&&$preview[0]['document']['topic_desc_basic']===''&&$preview[0]['document']['category']==='lore'
        &&(int)$db->query('SELECT count(*) FROM source_events')->fetchColumn()===$beforeDynamicSources
        &&(int)$db->query('SELECT count(*) FROM oghma_dynamic_applications')->fetchColumn()===0,'Dynamic Oghma preview wrote state or lost blank/clearall semantics');
    [$dynamicStatus]=$call($router,'POST',$base.'/turns',$headers($dynamicTurn['message_id']),[],$dynamicTurn);
    $oghmaSnapshotStatement->execute(['turn'=>$dynamicTurn['turn_id']]);$dynamicSnapshot=json_decode((string)$oghmaSnapshotStatement->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
    $dynamicPrompt=(string)($dynamicSnapshot['message']['_prompt']['_assembled_prompt']??'');
    $dynamicDocument=$products->knowledge($preview[0]['document']['id']);
    $assert($dynamicStatus===202&&str_contains($dynamicPrompt,$dynamicRule['topic_desc'])
        &&$dynamicDocument['content']===$dynamicRule['topic_desc']&&$dynamicDocument['topic_desc_basic']===''
        &&$dynamicDocument['provenance']['source_event_id']===$dynamicTurn['message_id'], 'first Dynamic Oghma response did not use and persist the exact preview');
    $applicationSource=$db->query('SELECT a.source_event_id FROM oghma_dynamic_applications a JOIN source_events e ON e.source_event_id=a.source_event_id')->fetchColumn();
    $assert($applicationSource===$dynamicTurn['message_id'],'Dynamic Oghma did not retain its accepted immutable source');
    $db->prepare('UPDATE knowledge_documents SET content=:content,content_sha256=:sha WHERE document_id=:id')->execute(['content'=>'A later manual story edit.','sha'=>hash('sha256','A later manual story edit.'),'id'=>$dynamicDocument['id']]);
    [$repeatStatus]=$call($router,'POST',$base.'/turns',$headers($dynamicTurn['message_id']),[],$dynamicTurn);
    $dynamicTurn['message_id']=$newUuid(996004);$dynamicTurn['request_id']=$newUuid(996005);$dynamicTurn['turn_id']=$newUuid(996006);
    [$snapshotRepeatStatus]=$call($router,'POST',$base.'/turns',$headers($dynamicTurn['message_id']),[],$dynamicTurn);
    $assert($repeatStatus===202&&$snapshotRepeatStatus===202&&count($dynamic->plan($dynamicTurn))===0
        &&$products->knowledge($dynamicDocument['id'])['content']==='A later manual story edit.'
        &&(int)$db->query('SELECT count(*) FROM oghma_dynamic_applications')->fetchColumn()===1,'duplicate turns or repeated journal snapshots reapplied an old stage');
    $dynamicGameData=['schema'=>'lorkhan.gamedata.v1','installation_id'=>$installationId,'playthrough_id'=>$dynamicTurn['playthrough_id'],
        'session_id'=>$sessionId,'request_id'=>$newUuid(996007),'generation'=>7,'runtime_generation'=>7,'observed_at'=>$now,'game'=>'tes3','type'=>'journal',
        'payload'=>['entries'=>[['journal_id'=>'parity_dynamic_quest','stage'=>20,'status'=>'active','title'=>'Parity quest','text'=>'The next stage was reached.']]]];
    [$gameDataStatus,$gameDataResult]=$call($router,'POST',$base.'/gamedata',$headers($dynamicGameData['request_id']),[],$dynamicGameData);
    $storyQuery=$db->prepare("SELECT content,topic_desc_basic,tags FROM knowledge_documents WHERE installation_id=:installation AND playthrough_id=:playthrough AND topic='vivec' AND deleted_at IS NULL");
    $storyQuery->execute(['installation'=>$installationId,'playthrough'=>$dynamicTurn['playthrough_id']]);$story=$storyQuery->fetch();
    $assert($gameDataStatus===202&&$story['content']===''&&$story['topic_desc_basic']==='Vivec has a new public account of the quest.'&&$story['tags']==='', 'standalone journal observation did not clear advanced content and update basic knowledge: '.json_encode(['status'=>$gameDataStatus,'result'=>$gameDataResult,'story'=>$story]));
    $dynamicTurn['message_id']=$newUuid(996008);$dynamicTurn['request_id']=$newUuid(996009);$dynamicTurn['turn_id']=$newUuid(996010);
    $dynamicTurn['payload']['input']['text']='Tell me about parity new lore.';$dynamicTurn['payload']['context']['journal']['items'][0]['stage']=30;
    [$newTopicStatus]=$call($router,'POST',$base.'/turns',$headers($dynamicTurn['message_id']),[],$dynamicTurn);
    $oghmaSnapshotStatement->execute(['turn'=>$dynamicTurn['turn_id']]);$newTopicSnapshot=json_decode((string)$oghmaSnapshotStatement->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
    $assert($newTopicStatus===202&&str_contains((string)($newTopicSnapshot['message']['_prompt']['_assembled_prompt']??''),'New lore appeared after the final quest stage.'),'a new Dynamic Oghma topic was missing from its first response');
    $otherStory=$products->createRevisioned('playthrough',['installation_id'=>$installationId,'profile_id'=>$turn['profile_id'],'name'=>'Dynamic isolation','content'=>[]],$now);
    $storyReader=$products->oghmaKnowledgeForProfile($installationId,$dynamicTurn['profile_id'],['playthrough_id'=>$dynamicTurn['playthrough_id'],'search'=>'parity_new_lore']);
    $otherReader=$products->oghmaKnowledgeForProfile($installationId,$dynamicTurn['profile_id'],['playthrough_id'=>$otherStory['playthrough_id'],'search'=>'parity_new_lore']);
    $assert($storyReader['total']===1&&$otherReader['total']===0&&$storyReader['playthrough']['playthrough_id']===$dynamicTurn['playthrough_id'],'NPC Oghma reader lost the selected story knowledge or leaked it into another playthrough');
    $otherKnowledge=$products->knowledgeCandidates(['installation_id'=>$installationId,'profile_id'=>$turn['profile_id'],'playthrough_id'=>$otherStory['playthrough_id']]);
    $assert(!in_array('parity_new_lore',array_column($otherKnowledge,'topic'),true)
        &&count(array_filter($otherKnowledge,static fn(array $row):bool=>$row['topic']==='vivec'&&$row['content']==='Vivec is one of the living gods of the Tribunal.'))===1,'Dynamic Oghma leaked one playthrough into another');
}finally{$db->rollBack();}

$historySourceId=$newUuid(843);$historyRequestId=$newUuid(844);$historyTurnId=$newUuid(845);
$historyPayload=['speaker'=>$turn['payload']['speaker'],'target'=>$turn['payload']['target'],
    'input'=>['text'=>'[oghma: Vivec]'],'context'=>$turn['payload']['context']];
$historySource=$db->prepare('INSERT INTO source_events(source_event_id,installation_id,session_id,generation,event_kind,occurred_at,schema_name,request_id,turn_id,payload) VALUES(:source,:installation,:session,7,\'turn.requested\',:now,\'lorkhan.turn.v1\',:request,:turn,CAST(:payload AS jsonb))');
$historySource->execute(['source'=>$historySourceId,'installation'=>$installationId,'session'=>$sessionId,'now'=>$now,
    'request'=>$historyRequestId,'turn'=>$historyTurnId,'payload'=>json_encode($historyPayload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);
(new EventLogRepository($db))->projectSource($historySourceId,$installationId,$sessionId,'turn.requested',$now,
    $historyRequestId,$historyTurnId,null,$historyPayload);
$fallbackOghmaTurn=$turn;$fallbackOghmaTurn['message_id']=$newUuid(846);$fallbackOghmaTurn['request_id']=$newUuid(847);
$fallbackOghmaTurn['turn_id']=$newUuid(848);$fallbackOghmaTurn['payload']['input']['text']='Tell me about an unknown forgotten island.';
[$status]=$call($router,'POST',$base.'/turns',$headers($fallbackOghmaTurn['message_id']),[],$fallbackOghmaTurn);
$fallbackAttempt=$db->prepare("SELECT state,operation FROM provider_attempts WHERE turn_id=:turn ORDER BY started_at");
$fallbackAttempt->execute(['turn'=>$fallbackOghmaTurn['turn_id']]);$fallbackAttemptRows=$fallbackAttempt->fetchAll();
$fallbackTraceStatement=$db->prepare("SELECT algorithm,reasons FROM retrieval_traces WHERE turn_id=:turn AND domain='knowledge'");
$fallbackTraceStatement->execute(['turn'=>$fallbackOghmaTurn['turn_id']]);$fallbackOghmaTrace=$fallbackTraceStatement->fetch();
$fallbackOghmaReasons=$fallbackOghmaTrace?json_decode((string)$fallbackOghmaTrace['reasons'],true,64,JSON_THROW_ON_ERROR):[];
$assert($status===202&&$fallbackAttemptRows===[['state'=>'succeeded','operation'=>'extract_oghma_topics']]
    &&($fallbackOghmaTrace['algorithm']??null)==='oghma-parity-v1'
    &&($fallbackOghmaReasons['_context']['extractor_status']??null)==='fallback_succeeded'
    &&($fallbackOghmaReasons['_context']['suggested_topics']??[])===['Vivec']
    &&($fallbackOghmaReasons['_context']['extracted_topics']??[])===['vivec'],
    'explicit unresolved Oghma request did not make exactly one catalog-constrained connector fallback: '.json_encode([
        'status'=>$status,'attempts'=>$fallbackAttemptRows,'trace'=>$fallbackOghmaTrace,'reasons'=>$fallbackOghmaReasons],JSON_UNESCAPED_SLASHES));
$fallbackOghmaWorker=$runTurnWorker(new MockProvider());
$assert($fallbackOghmaWorker===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0],
    'fallback-grounded Oghma turn did not complete through the normal response pipeline');

// Freeze NPC output translation at acceptance and keep stored history, subtitle, and TTS text independent.
$translationEnabled=array_replace(\LorkhanServer\Application\TranslationPolicy::defaults(),[
    'provider'=>'deepl','translate_text'=>true,'translate_audio'=>true,'source_language'=>'EN','target_language'=>'DE']);
$translationSaved=$biographyService->createRevisioned('translation_policy',['installation_id'=>$installationId,
    'name'=>'NPC Output Translation','content'=>$translationEnabled]);
$translationTurn=$turn;$translationTurn['message_id']=$newUuid(860);$translationTurn['request_id']=$newUuid(861);
$translationTurn['turn_id']=$newUuid(862);$translationTurn['payload']['input']['text']='[oghma: Vivec] Translate this reply.';
[$status]=$call($router,'POST',$base.'/turns',$headers($translationTurn['message_id']),[],$translationTurn);
$translationSnapshotStatement=$db->prepare('SELECT source_manifest FROM turn_provider_snapshots WHERE turn_id=:turn');
$translationSnapshotStatement->execute(['turn'=>$translationTurn['turn_id']]);
$translationSnapshot=json_decode((string)$translationSnapshotStatement->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$biographyService->revise('translation_policy',$translationSaved['configuration_id'],
    \LorkhanServer\Application\TranslationPolicy::defaults(),'disable after accepted translation turn');
$translationProvider=new class implements \LorkhanServer\Application\TranslationProvider {
    public int$calls=0;
    public function translate(array$texts,string$sourceLanguage,string$targetLanguage,\LorkhanServer\Application\CancellationToken$token):array{
        ++$this->calls;$token->throwIfCancellationRequested();
        if($sourceLanguage!=='EN'||$targetLanguage!=='DE')throw new RuntimeException('translation policy not frozen');
        return array_map(static fn(string$text):string=>'DE: '.$text,$texts);
    }
};
$translationStats=$runWorker(['turn.process'],new MockProvider(),null,$translationProvider);
$translationResponseStatement=$db->prepare('SELECT response_payload FROM turns WHERE turn_id=:turn');
$translationResponseStatement->execute(['turn'=>$translationTurn['turn_id']]);
$translationResponse=json_decode((string)$translationResponseStatement->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$translationLine=$translationResponse['lines'][0];
$translationDialogueStatement=$db->prepare('SELECT text,dialogue_message_id FROM dialogue_utterances WHERE turn_id=:turn');
$translationDialogueStatement->execute(['turn'=>$translationTurn['turn_id']]);$translationDialogue=$translationDialogueStatement->fetch();
$translationEventStatement=$db->prepare("SELECT payload FROM response_events WHERE turn_id=:turn AND event_type='dialogue.complete'");
$translationEventStatement->execute(['turn'=>$translationTurn['turn_id']]);
$translationEvent=json_decode((string)$translationEventStatement->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
$translationJobStatement=$db->prepare("SELECT payload FROM durable_jobs WHERE job_type='speech.synthesize' AND idempotency_key=:key");
$translationJobStatement->execute(['key'=>'speech:'.$translationDialogue['dialogue_message_id']]);
$translationJobPayload=json_decode((string)$translationJobStatement->fetchColumn(),true,16,JSON_THROW_ON_ERROR);
$translationAttemptStatement=$db->prepare("SELECT state,provider_name,operation,metadata FROM provider_attempts WHERE turn_id=:turn AND provider_kind='translation'");
$translationAttemptStatement->execute(['turn'=>$translationTurn['turn_id']]);$translationAttempt=$translationAttemptStatement->fetch();
$translationAttemptMetadata=$translationAttempt?json_decode((string)$translationAttempt['metadata'],true,16,JSON_THROW_ON_ERROR):[];
$expectedOriginal='[fast] '.(string)$turn['payload']['target']['display_name'].' heard: [oghma: Vivec] Translate this reply.';
$expectedTranslation='DE: '.$expectedOriginal;
$assert($status===202&&$translationStats===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0]
    &&$translationProvider->calls===1
    &&($translationSnapshot['message']['_translation_policy']['revision']??null)===1
    &&$translationLine['text']===$expectedOriginal&&$translationLine['subtitle']===$expectedTranslation
    &&$translationLine['tts_text']===$expectedTranslation&&$translationDialogue['text']===$expectedOriginal
    &&($translationEvent['text']??null)===$expectedTranslation&&($translationJobPayload['tts_text']??null)===$expectedTranslation
    &&($translationAttempt['state']??null)==='succeeded'&&($translationAttempt['provider_name']??null)==='deepl'
    &&($translationAttempt['operation']??null)==='translate_dialogue'
    &&($translationAttemptMetadata['configuration_revision']??null)===1,
    'accepted translation policy did not freeze or separate history, subtitle, TTS, and audit state: '.json_encode([
        'status'=>$status,'stats'=>$translationStats,'calls'=>$translationProvider->calls,
        'snapshot'=>$translationSnapshot['message']['_translation_policy']??null,'line'=>$translationLine,
        'dialogue'=>$translationDialogue,'event'=>$translationEvent,'job'=>$translationJobPayload,
        'attempt'=>$translationAttempt,'attempt_metadata'=>$translationAttemptMetadata,'expected'=>$expectedOriginal],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

$capturedSpeech=new class implements \LorkhanServer\Application\SpeechProvider {
    public array$inputs=[];
    public function synthesize(string$text,\LorkhanServer\Application\CancellationToken$cancellation,array$context=[]):array{
        $this->inputs[]=$text;return(new MockSpeechProvider())->synthesize($text,$cancellation,$context);
    }
};
$speechRegistry=new \LorkhanServer\Application\JobHandlerRegistry([new \LorkhanServer\Application\SpeechSynthesizeJobHandler(
    new Repository($db),$capturedSpeech,$mediaStore,$attempts,null)]);
$translationSpeechStats=(new Worker(new JobRepository($db),$speechRegistry,'translation-speech-worker',5,1,1,0,10,
    ['speech.synthesize'],static fn(int$microseconds):mixed=>null))->run();
$translationTtsAttempt=$db->prepare("SELECT input_bytes FROM provider_attempts WHERE turn_id=:turn AND provider_kind='tts'");
$translationTtsAttempt->execute(['turn'=>$translationTurn['turn_id']]);
$assert($translationSpeechStats===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0]
    &&$capturedSpeech->inputs===[$expectedTranslation]
    &&(int)$translationTtsAttempt->fetchColumn()===strlen($expectedTranslation),
    'speech worker did not synthesize the TTS text frozen in the durable job payload');

// A DeepL outage is audited but falls back to the original valid dialogue without failing the turn.
$translationCurrent=$products->translationPolicyForInstallation($installationId);
$biographyService->revise('translation_policy',$translationCurrent['configuration_id'],$translationEnabled,'enable failed translation probe');
$translationFailureTurn=$turn;$translationFailureTurn['message_id']=$newUuid(863);$translationFailureTurn['request_id']=$newUuid(864);
$translationFailureTurn['turn_id']=$newUuid(865);$translationFailureTurn['payload']['input']['text']='[oghma: Vivec] Keep the original reply.';
[$status]=$call($router,'POST',$base.'/turns',$headers($translationFailureTurn['message_id']),[],$translationFailureTurn);
$translationCurrent=$products->translationPolicyForInstallation($installationId);
$biographyService->revise('translation_policy',$translationCurrent['configuration_id'],
    \LorkhanServer\Application\TranslationPolicy::defaults(),'disable after failed translation acceptance');
$unavailableTranslation=new class implements \LorkhanServer\Application\TranslationProvider {
    public function translate(array$texts,string$sourceLanguage,string$targetLanguage,\LorkhanServer\Application\CancellationToken$token):array{
        throw new RuntimeException('sensitive upstream detail');
    }
};
$translationFailureStats=$runWorker(['turn.process'],new MockProvider(),null,$unavailableTranslation);
$translationResponseStatement->execute(['turn'=>$translationFailureTurn['turn_id']]);
$translationFailureResponse=json_decode((string)$translationResponseStatement->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$translationFailureAttempt=$db->prepare("SELECT state,error_code,error_detail FROM provider_attempts WHERE turn_id=:turn AND provider_kind='translation'");
$translationFailureAttempt->execute(['turn'=>$translationFailureTurn['turn_id']]);
$translationFailureOriginal='[fast] '.(string)$turn['payload']['target']['display_name'].' heard: [oghma: Vivec] Keep the original reply.';
$assert($status===202&&$translationFailureStats===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0]
    &&$translationFailureResponse['ok']===true
    &&$translationFailureResponse['lines'][0]['text']===$translationFailureOriginal
    &&$translationFailureResponse['lines'][0]['subtitle']===$translationFailureOriginal
    &&$translationFailureResponse['lines'][0]['tts_text']===$translationFailureOriginal
    &&$translationFailureAttempt->fetch()===['state'=>'failed','error_code'=>'provider_unavailable','error_detail'=>null],
    'translation failure did not preserve original dialogue with a redacted failed provider attempt');

// A result continuation replaces the client's generic text with the configured server-owned prompt.
$configuredFollowupPrompt='Configured result prompt sentinel: react to the observed outcome only.';
$db->prepare('UPDATE action_intents SET followup_enabled=true,followup_prompt=:prompt WHERE action_id=:action')
    ->execute(['prompt'=>$configuredFollowupPrompt,'action'=>$action['action_id']]);
$db->prepare("UPDATE action_delivery SET continuation_state='eligible',terminal_at=COALESCE(terminal_at,clock_timestamp()) WHERE action_id=:action")
    ->execute(['action'=>$action['action_id']]);
$followupTurn=$turn;$followupTurn['message_id']=$newUuid(866);$followupTurn['request_id']=$newUuid(867);
$followupTurn['turn_id']=$newUuid(868);$followupTurn['payload']['input']['text']='Client generic follow-up text.';
$followupTurn['payload']['ui_source']='lorkhan_action_followup';
$followupTurn['payload']['recent_action_results']=[[
    'action_id'=>$result['action_id'],'status'=>$result['status'],'reason_code'=>$result['reason_code'],
    'observed'=>$result['observed'],'completed_at'=>$result['completed_at'],
]];
[$status]=$call($router,'POST',$base.'/turns',$headers($followupTurn['message_id']),[],$followupTurn);
$followupSnapshot=$db->prepare('SELECT source_manifest FROM turn_provider_snapshots WHERE turn_id=:turn');
$followupSnapshot->execute(['turn'=>$followupTurn['turn_id']]);
$followupManifest=json_decode((string)$followupSnapshot->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$assert($status===202
    &&($followupManifest['message']['payload']['input']['text']??null)===$configuredFollowupPrompt
    &&str_contains((string)($followupManifest['message']['_prompt']['_assembled_prompt']??''),$configuredFollowupPrompt),
    'configured action follow-up prompt did not replace the client placeholder in the frozen model input');

$deleteKey = $newUuid(50);
[$status] = $call($router, 'DELETE', $base . '/sessions/' . $sessionId, []);
$assert($status === 422, 'session delete without identity accepted');
[$status, $ended] = $call($router, 'DELETE', $base . '/sessions/' . $sessionId,
    ['Idempotency-Key'=>$deleteKey]);
$assert($status === 200 && $ended['ended'] && $ended['request_id'] === $deleteKey, 'session delete failed');
[$status, $endedAgain] = $call($router, 'DELETE', $base . '/sessions/' . $sessionId,
    ['Idempotency-Key'=>$deleteKey]);
$assert($status === 200 && $endedAgain == $ended, 'session delete replay incoherent');
[$status, $resultAfterEnd] = $call($router, 'POST', $base . '/action-results', $headers($result['message_id']), [], $result);
$assert($status === 200 && $resultAfterEnd['duplicate'], 'terminal result replay expired with session');
[$status, $body] = $call($router, 'GET', $base . '/media/' . $speech['media_id'], []);
$assert($status === 404 && $body['code'] === 'media_unavailable', 'ended-session media remained available');
$settingsDocument=['schema'=>'lorkhan.client-settings.v1','behavior'=>[
    'auto_greeting'=>true,'rechat'=>true,'rechat_delay_seconds'=>60,'rechat_max_depth'=>8,
    'rechat_probability_percent'=>50,'rechat_mode'=>'random','rechat_strict_targeting'=>false,
    'open_rechat'=>true,'rechat_allow_actions'=>false,'end_conversation_cooldown_seconds'=>60,
    'boredom'=>true,'boredom_delay_seconds'=>240,'combat_barks'=>true,'combat_bark_period_seconds'=>30],
    'memory'=>['recent_turn_limit'=>24,'knowledge_limit'=>6],
    'narrator'=>['enabled'=>true,'name'=>'The Temple Chronicler','context_visibility'=>true,'inline_mode'=>'Narrator',
        'welcome_events'=>true,'welcome_cooldown_minutes'=>10,'random_events'=>false,'random_chance_percent'=>15,
        'random_cooldown_rounds'=>2,'bored_events'=>false,'bored_chance_percent'=>25,'quest_events'=>true,
        'quest_chance_percent'=>10,'quest_cooldown_minutes'=>3,'book_events'=>true],
    'presentation'=>['show_status_hud'=>true,'transcript_rows'=>10,'tts_volume_boost'=>4],
    'safety'=>['actions_enabled'=>true,'allow_hostile'=>false,'allow_creatures'=>true]];
$settingsGlobal=\LorkhanServer\Application\SettingsCatalog::globalDefaults();
$settingsGlobal['client']=$settingsDocument;
$settingsGlobal['prompt']=['prompt_head'=>'Installation prompt sentinel.','emote_moods'=>'thoughtful, calm'];
$existingGlobal=$products->globalSettingsForInstallation($installationId);
$configuredGlobal=$products->revise('global_settings',$existingGlobal['configuration_id'],$settingsGlobal,
    'integration session settings',$now);
$configuredRevision='global-settings-r'.$configuredGlobal['current_revision'];
$globalPromptContext=$products->promptContext($turn,gmdate(DATE_ATOM));
$assert(($globalPromptContext['effective_settings']['prompt']['prompt_head']??null)===$settingsGlobal['prompt']['prompt_head']
    &&($globalPromptContext['effective_settings']['prompt']['emote_moods']??null)===$settingsGlobal['prompt']['emote_moods'],
    'global prompt defaults were not included in the frozen turn selection');
$configuredSession=$session;$configuredSession['message_id']=$newUuid(304);$configuredSession['generation']=8;
$db->prepare('UPDATE profiles SET deleted_at=clock_timestamp() WHERE profile_id=:profile')
    ->execute(['profile'=>$configuredSession['profile_id']]);
[$status,$configuredAccepted]=$call($router,'POST',$base.'/sessions',$headers($configuredSession['message_id']),[],$configuredSession);
$restoredSessionProfile=$db->prepare('SELECT deleted_at IS NULL FROM profiles WHERE profile_id=:profile');
$restoredSessionProfile->execute(['profile'=>$configuredSession['profile_id']]);
$assert($status===201&&$configuredAccepted['config_revision']===$configuredRevision
    &&$configuredAccepted['client_settings']==$settingsDocument&&filter_var($restoredSessionProfile->fetchColumn(),FILTER_VALIDATE_BOOL),
    'revisioned installation settings were not returned by the next OpenMW session handshake');
$configuredDeleteKey=$newUuid(305);
[$status,$configuredEnded]=$call($router,'DELETE',$base.'/sessions/'.$configuredAccepted['session_id'],['Idempotency-Key'=>$configuredDeleteKey]);
$assert($status===200&&$configuredEnded['ended']===true,'configured integration session did not end cleanly');
// Persist the pre-event-tuning shapes to exercise the same upgrade path as existing installations.
$legacyClientSettings=$settingsDocument;
foreach(['welcome_cooldown_minutes','random_chance_percent','random_cooldown_rounds','bored_events',
    'bored_chance_percent','quest_chance_percent','quest_cooldown_minutes']as$field)unset($legacyClientSettings['narrator'][$field]);
$legacyGlobalSettings=$settingsGlobal;$legacyGlobalSettings['client']=$legacyClientSettings;
foreach([$legacyClientSettings,$legacyGlobalSettings]as$index=>$legacySettings){
    $legacyRevision=$products->revise('global_settings',$existingGlobal['configuration_id'],$legacySettings,
        'integration legacy session settings',$now);
    $legacySession=$session;$legacySession['message_id']=$newUuid(306+$index*2);$legacySession['generation']=9+$index;
    [$status,$legacyAccepted]=$call($router,'POST',$base.'/sessions',$headers($legacySession['message_id']),[],$legacySession);
    $unchangedSettings=$products->globalSettingsForInstallation($installationId);
    $assert($status===201&&$legacyAccepted['client_settings']==$settingsDocument
        &&$legacyAccepted['config_revision']==='global-settings-r'.$legacyRevision['current_revision']
        &&$unchangedSettings['content']==$legacySettings,
        'legacy session settings must gain missing defaults without changing saved values or revisions');
    [$replayStatus,$legacyReplay]=$call($router,'POST',$base.'/sessions',$headers($legacySession['message_id']),[],$legacySession);
    $assert($replayStatus===201&&$legacyReplay==$legacyAccepted,'normalized legacy session replay changed');
    [$status,$legacyEnded]=$call($router,'DELETE',$base.'/sessions/'.$legacyAccepted['session_id'],
        ['Idempotency-Key'=>$newUuid(307+$index*2)]);
    $assert($status===200&&$legacyEnded['ended']===true,'legacy integration session did not end cleanly');
}
// Presentation-log maintenance must never erase immutable history or a response still in flight.
$maintenance = new \LorkhanServer\Infrastructure\ManagementRepository($db);
$queueHistory=static fn(): array => $db->query('SELECT (SELECT count(*) FROM response_events) AS events,
    (SELECT count(*) FROM lorkhan_internal.responselog) AS typed_queue,(SELECT count(*) FROM source_events) AS sources')->fetch();
$queueBefore=$queueHistory();
$queueEntry=$db->query("SELECT r.rowid,m.installation_id FROM public.responselog r
    JOIN lorkhan_internal.responselog_metadata m ON m.rowid=r.rowid JOIN sessions s ON s.session_id=m.session_id
    WHERE r.sent=1 AND s.state IN ('ended','replaced') ORDER BY r.rowid LIMIT 1")->fetch();
$assert(is_array($queueEntry),'response queue maintenance needs an ended-session fixture');
$db->prepare('UPDATE public.responselog SET sent=0 WHERE rowid=:rowid')->execute(['rowid'=>$queueEntry['rowid']]);
try { $maintenance->removeResponseQueueEntry($queueEntry['installation_id'],(int)$queueEntry['rowid']); $assert(false,'unsent response log entry was removed'); }
catch (RuntimeException $error) { $assert($error->getMessage()==='response_queue_entry_unavailable_or_pending','unexpected queue-removal error'); }
$db->prepare('UPDATE public.responselog SET sent=1 WHERE rowid=:rowid')->execute(['rowid'=>$queueEntry['rowid']]);
try { $maintenance->removeResponseQueueEntry(\LorkhanServer\Infrastructure\Uuid::v4(),(int)$queueEntry['rowid']); $assert(false,'queue removal crossed installations'); }
catch (RuntimeException $error) { $assert($error->getMessage()==='response_queue_entry_unavailable_or_pending','unexpected queue scope error'); }
$assert($maintenance->removeResponseQueueEntry($queueEntry['installation_id'],(int)$queueEntry['rowid'])===1
    && $queueHistory()===$queueBefore,'queue removal erased typed delivery or source history');
$removedQueue=$db->prepare('SELECT (SELECT count(*) FROM public.responselog WHERE rowid=:rowid)+(SELECT count(*) FROM lorkhan_internal.responselog_metadata WHERE rowid=:metadata)');
$removedQueue->execute(['rowid'=>$queueEntry['rowid'],'metadata'=>$queueEntry['rowid']]);
$assert((int)$removedQueue->fetchColumn()===0,'queue removal left an orphaned projection');
$responseScope = $db->query("SELECT s.installation_id,s.playthrough_id,t.turn_id FROM public.log l
    JOIN lorkhan_internal.log_metadata m ON m.rowid=l.rowid JOIN turns t ON t.turn_id=m.turn_id
    JOIN sessions s ON s.session_id=t.session_id WHERE t.state='complete' ORDER BY l.rowid DESC LIMIT 1")->fetch();
$assert(is_array($responseScope), 'response maintenance needs a populated completed-turn fixture');
$db->prepare("UPDATE turns SET state='processing' WHERE turn_id=:turn")->execute(['turn'=>$responseScope['turn_id']]);
$historyCounts = static fn(): array => $db->query('SELECT (SELECT count(*) FROM source_events) AS sources,
    (SELECT count(*) FROM dialogue_utterances) AS utterances,(SELECT count(*) FROM public.audit_request) AS audit')->fetch();
$relLogAttempts=new \LorkhanServer\Infrastructure\ProviderAttemptRepository($db);
foreach([[9801,'relationship.evaluate','succeeded','evaluate_relationship','2 weeks',$responseScope['installation_id']],
    [9802,'relationship.evaluate','succeeded','evaluate_relationship','0 seconds',$responseScope['installation_id']],
    [9803,'relationship.evaluate','queued','evaluate_relationship','2 weeks',$responseScope['installation_id']],
    [9804,'profile.generate','succeeded','generate_profile','2 weeks',$responseScope['installation_id']],
    [9805,'relationship.build','succeeded','build_relationships','2 weeks',$newUuid(9806)]] as [$num,$jobType,$jobState,$operation,$age,$installation]) {
    $jobId=$newUuid($num);$attemptId=$newUuid($num+10);
    $db->prepare('INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,state) VALUES(:id,:type,1,:key,CAST(:payload AS jsonb),:state)')
        ->execute(['id'=>$jobId,'type'=>$jobType,'key'=>'relationship-log-'.$num,'payload'=>json_encode(['installation_id'=>$installation]),'state'=>$jobState]);
    $relLogAttempts->start($attemptId,'llm','mock',$operation,1,jobId:$jobId);
    $relLogAttempts->finish($attemptId,'failed',errorCode:'fixture');
    $db->prepare('UPDATE provider_attempts SET started_at=clock_timestamp()-CAST(:age AS interval) WHERE provider_attempt_id=:id')->execute(['age'=>$age,'id'=>$attemptId]);
}
$assert($maintenance->clearRequestLog($responseScope['installation_id'],'1 week')===1,
    'relationship log age clear crossed operation, installation, recent or unfinished-job boundaries');
$assert($maintenance->clearRequestLog($responseScope['installation_id'],'all')===1
    &&$maintenance->clearRequestLog($responseScope['installation_id'],'all')===0,
    'relationship log clear did not preserve pending work or was not idempotent');
$db->prepare("UPDATE durable_jobs SET state='dead' WHERE job_id=:id")->execute(['id'=>$newUuid(9803)]);
$beforeHistory = $historyCounts();
$beforeProviderCount=(int)$db->query('SELECT count(*) FROM provider_attempts')->fetchColumn();
$requestCleared=$maintenance->clearRequestLog($responseScope['installation_id']);
$assert($requestCleared>0 && (int)$db->query('SELECT count(*) FROM provider_attempts')->fetchColumn()===$beforeProviderCount
    && $historyCounts()===$beforeHistory,'request log clear erased accounting/history or did not hide completed entries');
$hiddenInvalid=$db->prepare("SELECT count(*) FROM lorkhan_internal.request_log_hidden h
    JOIN provider_attempts a USING(provider_attempt_id) LEFT JOIN turns t ON t.turn_id=a.turn_id
    LEFT JOIN sessions s ON s.session_id=t.session_id LEFT JOIN durable_jobs j ON j.job_id=a.job_id
    WHERE a.provider_kind<>'llm' OR a.state='started' OR t.state IN ('accepted','processing')
        OR j.state IN ('queued','leased') OR COALESCE(s.installation_id::text,j.payload->>'installation_id','')<>:installation");
$hiddenInvalid->execute(['installation'=>$responseScope['installation_id']]);
$assert((int)$hiddenInvalid->fetchColumn()===0,'request log clear crossed installation or hid pending/non-LLM work');
$assert($maintenance->clearRequestLog($responseScope['installation_id'])===0,'request log clear was not idempotent');
$outsideCount = $db->prepare('SELECT count(*) FROM public.log l JOIN lorkhan_internal.log_metadata m ON m.rowid=l.rowid
    JOIN turns t ON t.turn_id=m.turn_id JOIN sessions s ON s.session_id=t.session_id
    WHERE s.installation_id<>:installation OR s.playthrough_id<>:playthrough');
$scopeParams = ['installation'=>$responseScope['installation_id'], 'playthrough'=>$responseScope['playthrough_id']];
$outsideCount->execute($scopeParams); $outsideBefore = (int)$outsideCount->fetchColumn();
$cleared = $maintenance->clearRoleplayLog($scopeParams['installation'], $scopeParams['playthrough'], 'responses');
$assert($cleared>0 && $historyCounts()===$beforeHistory, 'clean response log erased immutable history or did not clear completed responses');
$outsideCount->execute($scopeParams);
$assert((int)$outsideCount->fetchColumn()===$outsideBefore, 'clean response log crossed its playthrough scope');
$activeLog = $db->prepare('SELECT count(*) FROM public.log l JOIN lorkhan_internal.log_metadata m ON m.rowid=l.rowid WHERE m.turn_id=:turn');
$activeLog->execute(['turn'=>$responseScope['turn_id']]);
$assert((int)$activeLog->fetchColumn()===1, 'clean response log removed an active response');
$assert($maintenance->clearRoleplayLog($scopeParams['installation'], $scopeParams['playthrough'], 'responses')===0, 'repeated clean response log was not idempotent');
// Bulk summary actions remain playthrough-scoped, idempotent and asynchronous.
$bulkModel=$memoryService->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Bulk summary mock',
    'content'=>['driver'=>'mock','model'=>'bulk-summary-v1']]);
$bulkPolicyContent=['schema'=>'lorkhan.memory-policy.v1','enabled'=>true,'provider_configuration_id'=>$bulkModel['configuration_id']];
$bulkPolicy=$products->memorySummaryPolicyForInstallation($installationId);
if ($bulkPolicy===null) $memoryService->createRevisioned('memory_policy',['installation_id'=>$installationId,'name'=>'Bulk summary policy','content'=>$bulkPolicyContent]);
else $memoryService->revise('memory_policy',$bulkPolicy['configuration_id'],$bulkPolicyContent,'bulk summary fixture');
$syncMemory=$memoryService->createMemory(['installation_id'=>$installationId,'profile_id'=>$turn['profile_id'],
    'playthrough_id'=>$turn['playthrough_id'],'tier'=>'mid','content'=>'Bulk summary input sentinel.',
    'provenance'=>['source'=>'memory.consolidate','provider'=>'first-party','model'=>'deterministic-extractive-v1']]);
$db->prepare('UPDATE memory_records SET derivation_key=:key WHERE memory_id=:memory')
    ->execute(['key'=>'bulk-summary-fixture','memory'=>$syncMemory['memory_id']]);
$syncResult=$maintenance->syncMemorySummaries($installationId,$turn['playthrough_id']);
$assert($syncResult['queued']>=1 && $syncResult['has_more']===false,'bulk summary did not queue eligible memory');
$syncJobs=$db->prepare("SELECT count(*) FROM durable_jobs WHERE job_type='memory.summarize' AND payload->>'memory_id'=:memory");
$syncJobs->execute(['memory'=>$syncMemory['memory_id']]);
$assert((int)$syncJobs->fetchColumn()===1 && $maintenance->syncMemorySummaries($installationId,$turn['playthrough_id'])['queued']===0,
    'repeated bulk summary duplicated paid work');
$outsideMemories=$db->prepare('SELECT count(*) FROM memory_records WHERE deleted_at IS NULL AND (installation_id<>:installation OR playthrough_id<>:playthrough)');
$memoryScope=['installation'=>$installationId,'playthrough'=>$turn['playthrough_id']];
$outsideMemories->execute($memoryScope);$outsideMemoryCount=(int)$outsideMemories->fetchColumn();
$memoryHistoryBefore=$historyCounts();
$recentCount=(int)$db->query("SELECT count(*) FROM memory_records WHERE tier='recent' AND deleted_at IS NULL")->fetchColumn();
$assert($maintenance->clearRoleplayLog($installationId,$turn['playthrough_id'],'memories')>=1,'bulk memory delete did not clear its scope');
$outsideMemories->execute($memoryScope);
$assert((int)$outsideMemories->fetchColumn()===$outsideMemoryCount && $historyCounts()===$memoryHistoryBefore
    &&(int)$db->query("SELECT count(*) FROM memory_records WHERE tier='recent' AND deleted_at IS NULL")->fetchColumn()===$recentCount,
    'bulk memory delete changed another playthrough or immutable history');
$syncDeleted=$db->prepare('SELECT content,deleted_at IS NOT NULL AS deleted FROM memory_records WHERE memory_id=:memory');
$syncDeleted->execute(['memory'=>$syncMemory['memory_id']]);$deletedMemory=$syncDeleted->fetch();
$assert($deletedMemory['content']==='Bulk summary input sentinel.' && filter_var($deletedMemory['deleted'],FILTER_VALIDATE_BOOL)
    &&$maintenance->clearRoleplayLog($installationId,$turn['playthrough_id'],'memories')===0,'bulk memory delete lost originals or was not idempotent');
// Diary date filtering follows recorded OpenMW context and survives ordinary text edits.
require dirname(__DIR__).'/ui/tmpl/roleplay_reader.php';
$db->beginTransaction();
$calendarTurn=$db->prepare('SELECT s.profile_id,s.installation_id,s.playthrough_id FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE t.turn_id=:turn');
$calendarTurn->execute(['turn'=>$responseScope['turn_id']]); $calendarScope=$calendarTurn->fetch();
$calendar=['year'=>427,'month'=>7,'day'=>16,'hour'=>9.5];
$db->prepare("UPDATE turns SET context=jsonb_set(context,'{world}',COALESCE(context->'world','{}'::jsonb)||jsonb_build_object('calendar',CAST(:calendar AS jsonb))) WHERE turn_id=:turn")
    ->execute(['calendar'=>json_encode($calendar),'turn'=>$responseScope['turn_id']]);
$calendarDiary=$products->createNarrative($calendarScope+['kind'=>'diary','title'=>'Calendar fixture','content'=>'A recorded morning.',
    'provenance'=>['source_turn_ids'=>[$responseScope['turn_id']]]],gmdate(DATE_ATOM));
// Home reads scoped whole chat events and real diaries, not sentence delivery rows or summaries.
$db->exec("UPDATE sessions SET state='ended'");
$db->prepare("UPDATE sessions SET state='active' WHERE session_id=(SELECT session_id FROM turns WHERE turn_id=:turn)")
    ->execute(['turn'=>$responseScope['turn_id']]);
$products->createNarrative($calendarScope+['kind'=>'summary','title'=>'Not a diary','content'=>'Summary sentinel.',
    'provenance'=>[]],gmdate(DATE_ATOM,time()+10));
$homeEvent=$db->prepare("INSERT INTO public.eventlog(type,data,localts,gamets) VALUES ('chat',:text,:time,0) RETURNING rowid");
$homeMetadata=$db->prepare("INSERT INTO lorkhan_internal.eventlog_metadata(rowid,installation_id,playthrough_id,turn_id,projection_kind,projection_key,payload,suppressed_at) "
    . "VALUES (:rowid,:installation,:playthrough,:turn,'home_fixture',:key,CAST(:payload AS jsonb),:suppressed)");
for($i=0;$i<7;$i++){
    $homeEvent->execute(['text'=>$i===6?'Hidden: hiddenword':'Speakername: Moonstone moonstone the and (contextword).','time'=>time()+100+$i]);
    $homeMetadata->execute(['rowid'=>$homeEvent->fetchColumn(),'installation'=>$calendarScope['installation_id'],
        'playthrough'=>$calendarScope['playthrough_id'],'turn'=>$responseScope['turn_id'],'key'=>'home-'.$i,
        'payload'=>json_encode(['calendar'=>$calendar]),'suppressed'=>$i===6?gmdate(DATE_ATOM):null]);
}
$homeDashboard=(new \LorkhanServer\Infrastructure\ManagementUiRepository($db))->dashboard();
$homeWords=array_column($homeDashboard['words'],null,'text');
$assert(count($homeDashboard['dialogue'])===5 && !in_array('Hidden: hiddenword',array_column($homeDashboard['dialogue'],'text'),true)
    &&\LorkhanServer\Application\MorrowindCalendar::parse($homeDashboard['dialogue'][0]['calendar_data'])['label']==='16 Last Seed, 3E 427 · 09:30',
    'Home dialogue lost its five whole-event limit, suppression or recorded calendar');
$assert($homeDashboard['latest_diary']['narrative_id']===$calendarDiary['narrative_id']
    &&($homeWords['moonstone']['count']??0)===12 && !isset($homeWords['speakername'],$homeWords['contextword'],$homeWords['hiddenword'],$homeWords['the']),
    'Home selected a non-diary or counted speaker/context/stop/suppressed words');
$db->prepare("UPDATE lorkhan_internal.eventlog_metadata SET playthrough_id=NULL WHERE projection_kind='home_fixture'")->execute();
$otherScopeDashboard=(new \LorkhanServer\Infrastructure\ManagementUiRepository($db))->dashboard();
$assert(!in_array('moonstone',array_column($otherScopeDashboard['words'],'text'),true), 'Home vocabulary crossed playthrough scope');
$assert($homeDashboard['statistics']['counts']['Total Events']-$otherScopeDashboard['statistics']['counts']['Total Events']===6
    &&$homeDashboard['statistics']['counts']['Diary Entries']>=1
    &&!isset($homeDashboard['statistics']['counts']['Queued Jobs']), 'Home statistics counted hidden/other-playthrough events or generic jobs');
foreach([['llm','succeeded'],['llm','failed'],['tts','succeeded']] as $index=>[$kind,$state]) {
    $id=$newUuid(998800+$index);
    $attempts->start($id,$kind,'home-fixture','preview',1,turnId:$responseScope['turn_id']);
    $attempts->finish($id,$state);
}
$homeAfterAttempts=(new \LorkhanServer\Infrastructure\ManagementUiRepository($db))->dashboard();
foreach(['24h','72h','1w','lifetime'] as $period) {
    $assert($homeAfterAttempts['statistics']['llm'][$period]['total']-$homeDashboard['statistics']['llm'][$period]['total']===2
        &&$homeAfterAttempts['statistics']['llm'][$period]['success']-$homeDashboard['statistics']['llm'][$period]['success']===1,
        'Home LLM period totals included TTS or lost failed attempts');
}
$savedQuery=$_GET;
$_GET=['installation_id'=>$calendarScope['installation_id'],'playthrough_id'=>$calendarScope['playthrough_id'],
    'calendar'=>'tamrielic','game_date'=>'0427-08-16'];
$calendarState=lorkhan_roleplay_reader_state($db,[$calendarScope['installation_id']=>'Test'],'diaries');
$calendarRows=array_column($calendarState['rows'],null,'narrative_id');
$datedEvents=(new EventLogRepository($db))->page($calendarScope+['limit'=>500]);
$assert(in_array('16 Last Seed, 3E 427 · 09:30',array_column($datedEvents['data'],'game_time'),true),
    'Events did not share the recorded calendar date presentation');
$assert(isset($calendarRows[$calendarDiary['narrative_id']]) && ($calendarState['calendar']['0427-08-16']??0)>0
    &&$calendarRows[$calendarDiary['narrative_id']]['game_date_label']==='16 Last Seed, 3E 427 · 09:30',
    'diary calendar did not resolve its scoped source turn and date filter');
$editedCalendar=$products->updateNarrative($calendarDiary['narrative_id'],['kind'=>'diary','title'=>'Edited date fixture','content'=>'Still the same morning.',
    'provenance'=>['source'=>'management edit']],gmdate(DATE_ATOM));
$assert($editedCalendar['provenance']['source_turn_ids']===[$responseScope['turn_id']], 'diary edit erased source-turn provenance');
$_GET['game_date']='0427-08-17';
$otherDate=lorkhan_roleplay_reader_state($db,[$calendarScope['installation_id']=>'Test'],'diaries');
$assert(!in_array($calendarDiary['narrative_id'],array_column($otherDate['rows'],'narrative_id'),true), 'Tamrielic date filter returned another day');
unset($_GET['game_date']);
$unselectedDiary=lorkhan_roleplay_reader_state($db,[$calendarScope['installation_id']=>'Test'],'diaries');
$assert($unselectedDiary['rows']===[] && $unselectedDiary['total']===0
    && $unselectedDiary['calendar']===$calendarState['calendar'] && $unselectedDiary['people']===$calendarState['people'],
    'unselected diary calendar listed entries or lost calendar/author navigation');
$_GET['person']=$calendarScope['profile_id'];
$authorDiary=lorkhan_roleplay_reader_state($db,[$calendarScope['installation_id']=>'Test'],'diaries');
$assert(in_array($calendarDiary['narrative_id'],array_column($authorDiary['rows'],'narrative_id'),true), 'author diary selection required a date');
unset($_GET['person']);
$_GET['q']='Edited date fixture';
$searchedDiary=lorkhan_roleplay_reader_state($db,[$calendarScope['installation_id']=>'Test'],'diaries');
$assert(in_array($calendarDiary['narrative_id'],array_column($searchedDiary['rows'],'narrative_id'),true), 'explicit diary search required a date');
$_GET=$savedQuery;
$db->rollBack();
if (is_dir($mediaPath)) {
    foreach (glob($mediaPath . '/*') ?: [] as $file) unlink($file);
    rmdir($mediaPath);
}

// Candidate browsing projects only supported context fields and remains installation scoped.
$db->beginTransaction();
$filterContext=['world'=>['cell'=>'Browse Cell','region'=>'Browse Region'],
    'playerState'=>['inventory'=>['items'=>[['display_name'=>'Browse Robe']]],'spells'=>['Browse Spell']],
    'targetState'=>['equipment'=>[['record_id'=>'browse_ring']],'activeEffects'=>['items'=>[['name'=>'Browse Effect']]]],
    'nearbyObjects'=>['items'=>[['kind'=>'items','display_name'=>'Browse Robe'],['kind'=>'doors','display_name'=>'Not An Item']]],
    'nearbyActors'=>[['equipment'=>['items'=>[['display_name'=>'Browse Sword']]]]], 'private_prompt'=>'Never expose this'];
$db->prepare('UPDATE turns SET context=CAST(:context AS jsonb) WHERE turn_id=:turn')->execute(['context'=>json_encode($filterContext),'turn'=>$responseScope['turn_id']]);
$filterInstallation=$responseScope['installation_id'];
$locations=$products->contextFilterCandidates($filterInstallation,'locations')['items'];
$items=array_column($products->contextFilterCandidates($filterInstallation,'items')['items'],null,'value');
$magic=array_column($products->contextFilterCandidates($filterInstallation,'magic')['items'],'value');
$assert(in_array('Browse Cell',array_column($locations,'value'),true)&&in_array('Browse Region',array_column($locations,'value'),true),'recorded location candidates missing');
$assert(isset($items['Browse Robe'],$items['browse_ring'],$items['Browse Sword'])&&$items['Browse Robe']['count']===2&&!isset($items['Not An Item']),'item candidate projection or counts incorrect');
$assert(in_array('Browse Spell',$magic,true)&&in_array('Browse Effect',$magic,true),'raw and wrapped magic candidates missing');
$otherInstallation=$newUuid(998877);$repo->ensureInstallation($otherInstallation,$tokenHash,$macKey);
$assert($products->contextFilterCandidates($otherInstallation,'items')['items']===[],'context candidates crossed installations');
$db->rollBack();

// Bulk profile writes preserve unrelated content/metadata and never cross installation boundaries.
$db->beginTransaction();
$copyContent=['schema'=>'lorkhan.core-profile.v1','prompt'=>'Preserve these instructions','routing'=>['llm_randomizer_enabled'=>true],
    'settings_overrides'=>['response'=>['max_words'=>21],'memory'=>['recent_turn_limit'=>17],'behavior'=>['rechat_allow_actions'=>true]]];
$copySource=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Copy source','content'=>$copyContent],gmdate(DATE_ATOM));
$copyTarget=$products->createRevisioned('core_profile',['installation_id'=>$installationId,'name'=>'Copy target','content'=>$copyContent],gmdate(DATE_ATOM));
$copyOtherInstallation=$newUuid(998878);$repo->ensureInstallation($copyOtherInstallation,$tokenHash,$macKey);
$copyOther=$products->createRevisioned('core_profile',['installation_id'=>$copyOtherInstallation,'name'=>'Other installation','content'=>$copyContent],gmdate(DATE_ATOM));
$copyRequest=['core_profile_id'=>$copySource['core_profile_id'],'revision'=>1,'setting'=>'response.max_words','value'=>60,'confirm'=>'Copy to all'];
$copyResult=$products->copyCoreProfileSetting($copyRequest);
$expectedCopy=$copySource['content'];$expectedCopy['settings_overrides']['response']['max_words']=60;
foreach([$copySource,$copyTarget]as$original){$updated=$products->getRevisioned('core_profile',$original['core_profile_id']);
    $assert($updated['content']===$expectedCopy&&$updated['label']===$original['label']&&$updated['slot']===$original['slot']
        &&$updated['default_npc']===$original['default_npc']&&(int)$updated['current_revision']===2,'bulk copy changed unrelated content or metadata');}
$assert($products->getRevisioned('core_profile',$copyOther['core_profile_id'])['content']===$copyOther['content'],'bulk copy crossed installations');
try{$products->copyCoreProfileSetting($copyRequest);$assert(false,'stale bulk copy accepted');}catch(RuntimeException $error){$assert($error->getMessage()==='revision_conflict','wrong stale-copy error');}
$copyRequest['revision']=$copyResult['revision'];$repeatCopy=$products->copyCoreProfileSetting($copyRequest);
$assert($repeatCopy['profiles_updated']===0&&$repeatCopy['revision']===$copyResult['revision'],'unchanged bulk copy created revisions');
$copyRequest['setting']='behavior.rechat_allow_actions';$copyRequest['value']=false;
$boolCopy=$products->copyCoreProfileSetting($copyRequest);
$assert($products->getRevisioned('core_profile',$copyTarget['core_profile_id'])['content']['settings_overrides']['behavior']['rechat_allow_actions']===false,'false checkbox value was lost');
$copyRequest['revision']=$boolCopy['revision'];$copyRequest['setting']='diary.prompt';$copyRequest['value']='Only write witnessed events.';
$products->copyCoreProfileSetting($copyRequest);
$assert($products->getRevisioned('core_profile',$copyTarget['core_profile_id'])['content']['settings_overrides']['diary']['prompt']==='Only write witnessed events.','text setting copy failed');
$db->rollBack();

// NPC Info observations are exact-actor and installation-scoped, with a narrow public projection.
$db->beginTransaction();
try {
    $observedTurn=$db->query("SELECT t.turn_id,t.target,s.installation_id FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE t.target->>'kind'='npc' AND t.target->>'content_file' IS NOT NULL ORDER BY t.accepted_at LIMIT 1")->fetch();
    $assert(is_array($observedTurn),'NPC observation fixture needs an accepted NPC turn');
    $identity=json_decode($observedTurn['target'],true,32,JSON_THROW_ON_ERROR);
    $observedProfile=$products->createRevisioned('profile',['installation_id'=>$observedTurn['installation_id'],'name'=>'Info observation regression',
        'actor_identity'=>$identity,'content'=>[]],$now);
    $context=['inventory'=>['items'=>[['record_id'=>'PRIVATE_PLAYER_ITEMS']]],'targetState'=>[
        'stats'=>['level'=>5,'magicka'=>['current'=>0,'base'=>20]],
        'skills'=>['sneak'=>['base'=>0,'modified'=>0,'private'=>'PRIVATE_SKILL']],
        'equipment'=>[['slot'=>'carried_right','record_id'=>'dagger','display_name'=>'Dagger <safe>','private'=>'PRIVATE_ITEM']],
        'spells'=>[['id'=>'fire_bite','name'=>'Fire <safe>']], 'private'=>'PRIVATE_STATE']];
    $db->prepare("UPDATE turns SET context=:context,accepted_at='2099-01-01T00:00:00Z' WHERE turn_id=:id")
        ->execute(['context'=>json_encode($context),'id'=>$observedTurn['turn_id']]);
    $observed=$products->npcObservedState($observedTurn['installation_id'],$observedProfile['profile_id']);
    $assert($observed['state']['stats']['magicka']['current']===0&&$observed['state']['skills']['sneak']['modified']===0
        &&$observed['state']['equipment'][0]['display_name']==='Dagger <safe>'&&!str_contains(json_encode($observed),'PRIVATE_')
        &&!array_key_exists('inventory',$observed['state']),'NPC observation leaked private/player data or lost zero values');
    $context['targetState']['inventory']=['items'=>[
        ['record_id'=>'z_item','display_name'=>'Z item','count'=>2,'private'=>'PRIVATE_INVENTORY'],
        ['record_id'=>'a_item','display_name'=>'A <safe> item','count'=>0]],'total'=>49,'truncated'=>true];
    $db->prepare('UPDATE turns SET context=:context WHERE turn_id=:id')
        ->execute(['context'=>json_encode($context),'id'=>$observedTurn['turn_id']]);
    $inventoryObservation=$products->npcObservedState($observedTurn['installation_id'],$observedProfile['profile_id']);
    $assert($inventoryObservation['state']['inventory'][0]['display_name']==='A <safe> item'
        &&$inventoryObservation['state']['inventory'][0]['count']===0
        &&$inventoryObservation['state']['inventory_observation']===['total'=>49,'truncated'=>true]
        &&!str_contains(json_encode($inventoryObservation),'PRIVATE_'),'NPC inventory sorting, bounds or safe projection failed');
    $otherIdentity=$identity;$otherIdentity['refnum']=['index'=>987654321,'content_file'=>0];
    $otherReference=$products->createRevisioned('profile',['installation_id'=>$observedTurn['installation_id'],'name'=>'Other NPC reference regression',
        'actor_identity'=>$otherIdentity,'content'=>[]],$now);
    $assert($products->npcObservedState($observedTurn['installation_id'],$otherReference['profile_id'])===[],
        'NPC observation crossed an exact reference boundary');
    $assert($products->npcObservedState('99999999-0000-4000-8000-000000000001',$observedProfile['profile_id'])===[],
        'NPC observation crossed installation ownership');
} finally { $db->rollBack(); }

$db->beginTransaction();
try {
    $quickstartInstallation=\LorkhanServer\Infrastructure\Uuid::v4();
    $db->prepare('INSERT INTO installations(installation_id,token_fingerprint) VALUES (:id,:fingerprint)')
        ->execute(['id'=>$quickstartInstallation,'fingerprint'=>hash('sha256',$quickstartInstallation)]);
    foreach (['tts_provider'=>'omnivoice','stt_provider'=>'parakeet'] as $kind=>$driver) {
        $connectorId=$products->ensureQuickstartSpeechConnector($quickstartInstallation,$kind,$driver,$now);
        $connector=$products->getRevisioned($kind,$connectorId);
        $custom=$connector['content'];$custom['endpoint']='http://127.0.0.1:12345/custom';
        $products->revise($kind,$connectorId,$custom,'keep custom settings on Quickstart reuse',$now);
        $again=$products->ensureQuickstartSpeechConnector($quickstartInstallation,$kind,$driver,$now);
        $reused=$products->getRevisioned($kind,$again);
        $assert($again===$connectorId&&$reused['content']===$custom&&(int)$reused['current_revision']===2,
            'Quickstart service choice duplicated a connector or overwrote its saved settings');
    }
} finally { $db->rollBack(); }

// Loaded-save capture uses committed old state while session replacement holds its installation fence.
$dragonTurn=$db->query('SELECT t.turn_id,s.installation_id,s.profile_id,s.playthrough_id FROM turns t JOIN sessions s ON s.session_id=t.session_id ORDER BY t.accepted_at DESC LIMIT 1')->fetch();
$dragonMessage=json_decode((string)file_get_contents(dirname(__DIR__).'/protocol/fixtures/v1/valid/session-loaded-save.json'),true,64,JSON_THROW_ON_ERROR)['instance'];
foreach(['installation_id','profile_id','playthrough_id'] as $key)$dragonMessage[$key]=$dragonTurn[$key];
$dragonMessage['message_id']=\LorkhanServer\Infrastructure\Uuid::v4();
$dragonGeneration=$db->prepare('SELECT max(generation) FROM sessions WHERE installation_id=:id');
$dragonGeneration->execute(['id'=>$dragonMessage['installation_id']]);
$dragonMessage['generation']=(int)$dragonGeneration->fetchColumn()+1;
$dragonCurrent=\LorkhanServer\Infrastructure\Uuid::v4();
$repo->createSession($dragonMessage,$dragonCurrent,$tokenHash);
$db->prepare("UPDATE turns SET context=jsonb_set(context,'{world}',CAST(:world AS jsonb)),accepted_at=clock_timestamp() WHERE turn_id=:id")
    ->execute(['world'=>json_encode(['calendar'=>['year'=>427,'month'=>7,'day'=>19,'hour'=>9.5]]),'id'=>$dragonTurn['turn_id']]);
$dragonRoot=sys_get_temp_dir().'/lorkhan-dragon-'.bin2hex(random_bytes(8));
$dragonConfig=['database_dsn'=>$dsn,'database_user'=>getenv('LORKHAN_TEST_DB_USER')?:'',
    'database_password'=>getenv('LORKHAN_TEST_DB_PASSWORD')?:'','backup_storage_path'=>$dragonRoot];
$dragonCapture=new \LorkhanServer\Infrastructure\DragonBreakSnapshot($dragonConfig);
$dragonCount=static fn():int=>(int)$db->query("SELECT count(*) FROM backup_records WHERE jsonb_exists(scope,'dragon_break')")->fetchColumn();
$dragonBefore=$dragonCount();
try {
    $under=$dragonMessage;$under['loaded_save']['day']=17;$dragonCapture->capture($under);
    $assert($dragonCount()===$dragonBefore,'subthreshold loaded save captured a snapshot');
    $called=false;
    try {$repo->createSession($dragonMessage,\LorkhanServer\Infrastructure\Uuid::v4(),$tokenHash,null,function()use(&$called):void{$called=true;});}
    catch(RuntimeException $error){$assert($error->getMessage()==='stale_generation','unexpected stale loaded-save failure');}
    $assert(!$called,'stale loaded save reached snapshot callback');
    ++$dragonMessage['generation'];$dragonMessage['message_id']=\LorkhanServer\Infrastructure\Uuid::v4();
    $repo->createSession($dragonMessage,\LorkhanServer\Infrastructure\Uuid::v4(),$tokenHash,null,
        function()use($db,$dragonCurrent,$dragonCapture,$dragonMessage,$assert):void{
            $state=$db->prepare('SELECT state FROM sessions WHERE session_id=:id');$state->execute(['id'=>$dragonCurrent]);
            $assert($state->fetchColumn()==='active','old session replaced before snapshot callback');
            $dragonCapture->capture($dragonMessage);
        });
    $assert($dragonCount()===$dragonBefore+1,'three-day rollback did not capture a real database snapshot');
    $dragonCapture->capture($dragonMessage);
    $assert($dragonCount()===$dragonBefore+1,'repeated loaded-save snapshot was not deduplicated');
    $dragonRecord=$db->query("SELECT backup_id,scope FROM backup_records WHERE jsonb_exists(scope,'dragon_break') ORDER BY created_at DESC LIMIT 1")->fetch();
    $dragonScope=json_decode($dragonRecord['scope'],true,64,JSON_THROW_ON_ERROR);
    $dragonPath=(new \LorkhanServer\Infrastructure\DatabaseSqlBackup($dragonConfig))->path($dragonRecord['backup_id']);
    $assert(is_file($dragonPath.'.dump')&&filesize($dragonPath)>0
        &&str_starts_with($dragonScope['snapshot']['name'],'Dragon Break ('),'automatic snapshot archive or stored presentation missing');
    $dragonSql=(string)file_get_contents($dragonPath);
    $assert(preg_match('/COPY lorkhan_internal\.sessions \(([^)]+)\) FROM stdin;\n(.*?)\n\\\\\./s',$dragonSql,$sessionCopy)===1,
        'snapshot lacks the native session COPY data');
    $sessionColumns=explode(', ',$sessionCopy[1]);$capturedOldState=null;
    foreach(explode("\n",$sessionCopy[2]) as $line){
        $values=explode("\t",$line);
        if(($values[array_search('session_id',$sessionColumns,true)]??null)===$dragonCurrent)
            $capturedOldState=$values[array_search('state',$sessionColumns,true)]??null;
    }
    $assert($capturedOldState==='active','archive captured the old session after replacement');
    $busyConnection=Connection::open($dragonConfig,false);
    $busyConnection->query('SELECT pg_advisory_lock(7514,113)');
    try {
        ++$dragonMessage['generation'];$dragonMessage['message_id']=\LorkhanServer\Infrastructure\Uuid::v4();
        $dragonMessage['loaded_save']['day']=12;
        $continued=$repo->createSession($dragonMessage,\LorkhanServer\Infrastructure\Uuid::v4(),$tokenHash,null,
            fn()=>$dragonCapture->capture($dragonMessage));
        $assert($continued['generation']===$dragonMessage['generation']&&$dragonCount()===$dragonBefore+1,
            'busy snapshot maintenance blocked normal session replacement');
        $failure=$db->prepare("SELECT detail->>'reason' FROM operational_audit WHERE action='dragon_break_failed' AND scope->>'request_id'=:id");
        $failure->execute(['id'=>$dragonMessage['message_id']]);
        $assert($failure->fetchColumn()==='maintenance_busy','snapshot failure was not audited');
    } finally {$busyConnection->query('SELECT pg_advisory_unlock(7514,113)');}
} finally {
    foreach(glob($dragonRoot.'/sql/*')?:[] as $file)unlink($file);
    if(is_dir($dragonRoot.'/sql'))rmdir($dragonRoot.'/sql');
    if(is_dir($dragonRoot))rmdir($dragonRoot);
}
$db->beginTransaction();
try {
    $player2Routing=new \LorkhanServer\Infrastructure\Player2RoutingRepository($db);
    $normalRouting=$products->effectiveSettingsForProfile($installationId,null)['routing'];
    $sameLabel=$products->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Player2 Local','content'=>['driver'=>'mock','model'=>'not-player2']],$now);
    $enabled=$player2Routing->save($installationId,true,0,$now);
    $assert($enabled['enabled']&&$enabled['revision']===1,'Player2 enable did not create a revision');
    $assert($enabled['configuration_id']!==$sameLabel['configuration_id'],'Player2 adopted an unrelated connector label');
    try{$products->deleteRevisioned('provider',$enabled['configuration_id'],$now);$assert(false,'active Player2 connector deleted');}
    catch(InvalidArgumentException $error){$assert($error->getMessage()==='provider_in_use','unexpected Player2 delete error');}
    try{$products->revise('provider',$enabled['configuration_id'],['driver'=>'mock','model'=>'repurposed'],'test',$now);$assert(false,'active Player2 connector repurposed');}
    catch(InvalidArgumentException $error){$assert($error->getMessage()==='provider_in_use','unexpected Player2 revise error');}
    $forced=$products->effectiveSettingsForProfile($installationId,null)['routing'];
    foreach(\LorkhanServer\Application\SettingsCatalog::routingTypes()as$field=>$type){
        if($type==='uuid_or_empty'&&!in_array($field,['tts_configuration_id','prompt_configuration_id'],true))
            $assert($forced[$field]===$enabled['configuration_id'],'Player2 missed route '.$field);
        else $assert(($forced[$field]??null)===($normalRouting[$field]??null),'Player2 changed non-LLM routing');
    }
    $assert($player2Routing->save($installationId,true,1,$now)===$enabled,'Player2 repeated enable was not idempotent');
    $assert($player2Routing->forcedConnector('99999999-0000-4000-8000-000000000001')===null,'Player2 crossed installations');
    try{$player2Routing->save($installationId,false,0,$now);$assert(false,'stale Player2 save accepted');}
    catch(RuntimeException $error){$assert($error->getMessage()==='revision_conflict','unexpected Player2 conflict');}
    $disabled=$player2Routing->save($installationId,false,1,$now);
    $assert($disabled['revision']===2&&!$disabled['enabled']&&$products->effectiveSettingsForProfile($installationId,null)['routing']===$normalRouting,
        'Disabling Player2 did not restore original routing');
    $assert($player2Routing->save($installationId,true,2,$now)['configuration_id']===$enabled['configuration_id'],'Player2 did not reuse owned connector');
    $scopeQuery=$db->prepare('SELECT profile_id,playthrough_id FROM playthroughs WHERE installation_id=:installation LIMIT 1');
    $scopeQuery->execute(['installation'=>$installationId]);$memoryScope=$scopeQuery->fetch();
    $memoryFixture=$memoryService->createMemory(['installation_id'=>$installationId]+$memoryScope+['tier'=>'mid','content'=>'Player2 frozen summary fixture.',
        'provenance'=>['source'=>'memory.consolidate','provider'=>'first-party','model'=>'deterministic-extractive-v1']]);
    $memoryId=$memoryFixture['memory_id'];
    $db->prepare("UPDATE memory_records SET derivation_key='player2-summary-fixture',expires_at=NULL WHERE memory_id=:id")->execute(['id'=>$memoryId]);
    $summaryPolicy=$products->memorySummaryPolicyForInstallation($installationId);
    $ordinarySummary=$products->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Ordinary summary regression',
        'content'=>['driver'=>'mock','model'=>'ordinary-summary']],$now);
    $summaryContent=['schema'=>'lorkhan.memory-policy.v1','enabled'=>true,'provider_configuration_id'=>$ordinarySummary['configuration_id']];
    if($summaryPolicy===null)$products->createRevisioned('memory_policy',['installation_id'=>$installationId,'name'=>'Player2 regression policy','content'=>$summaryContent],$now);
    else $products->revise('memory_policy',$summaryPolicy['configuration_id'],$summaryContent,'Player2 regression',$now);
    $memoryRouting=new \LorkhanServer\Infrastructure\MemorySummaryRepository($db);
    $memoryJob=$memoryRouting->enqueue($installationId,$memoryId);$assert($memoryJob!==null,'Player2 memory job not queued');
    $jobQuery=$db->prepare('SELECT payload FROM durable_jobs WHERE job_id=:id');$jobQuery->execute(['id'=>$memoryJob['job_id']]);
    $memoryPayload=json_decode($jobQuery->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $assert($memoryPayload['provider_configuration_id']===$enabled['configuration_id']&&$memoryPayload['player2_policy_revision']===3,
        'Memory job missed frozen Player2 policy');
    $lease=\LorkhanServer\Infrastructure\Uuid::v4();
    $db->prepare("UPDATE durable_jobs SET state='leased',attempt_count=1,lease_owner='player2-regression',lease_token=:lease,
        leased_at=clock_timestamp(),heartbeat_at=clock_timestamp(),lease_expires_at=clock_timestamp()+interval '1 minute' WHERE job_id=:id")
        ->execute(['lease'=>$lease,'id'=>$memoryJob['job_id']]);
    $memoryPayload['_job']=['job_id'=>$memoryJob['job_id'],'lease_token'=>$lease,'attempt'=>1];
    $player2Routing->save($installationId,false,3,$now);
    $assert($memoryRouting->input($memoryPayload)!==null,'Switching off Player2 invalidated an accepted memory job');
    $relationshipRouting=new \LorkhanServer\Infrastructure\RelationshipEvaluationRepository($db);
    $frozenRelationship=$relationshipRouting->policy($installationId,$memoryScope['profile_id'],3);
    $assert($frozenRelationship['provider_configuration_id']===$enabled['configuration_id']&&$frozenRelationship['player2_policy_revision']===3,
        'Relationship policy did not preserve the accepted Player2 revision');
    $badPayload=$memoryPayload;$badPayload['player2_policy_revision']=999;
    $assert($memoryRouting->input($badPayload)===null,'Unrecorded Player2 revision accepted');
} finally {$db->rollBack();}
$db->beginTransaction();
try {
    $browserSession=$db->query("SELECT session_id FROM sessions WHERE state='active' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    $assert(is_string($browserSession),'Browser speech needs a session fixture');
    $db->prepare("UPDATE sessions SET capabilities=array_remove(array_append(capabilities,'debug.commands.v1'),'speech.browser.v1') WHERE session_id=:id")
        ->execute(['id'=>$browserSession]);
    $speechParameters=['text'=>'Where is Caius? *curious* / ordinary text','language'=>'en-US'];
    try{$products->queueDebugCommand($browserSession,'player.dialogue.submit',$speechParameters);$assert(false,'old client accepted browser speech');}
    catch(InvalidArgumentException $error){$assert($error->getMessage()==='browser_speech_unsupported','unexpected browser speech capability error');}
    foreach([['text'=>' '],['text'=>str_repeat('x',2049)],['text'=>"line\nbreak"],['language'=>'en-'],['script'=>'tgm']]as$invalid){
        try{$products->queueDebugCommand($browserSession,'player.dialogue.submit',array_replace($speechParameters,$invalid));$assert(false,'invalid browser speech accepted');}
        catch(InvalidArgumentException $error){$assert($error->getMessage()==='invalid_debug_parameters','unexpected browser speech validation error');}
    }
    $db->prepare("UPDATE sessions SET capabilities=array_append(capabilities,'speech.browser.v1') WHERE session_id=:id")->execute(['id'=>$browserSession]);
    $speechId=\LorkhanServer\Infrastructure\Uuid::v4();
    $speechCommand=$products->queueDebugCommand($browserSession,'player.dialogue.submit',$speechParameters,$speechId);
    $assert($speechCommand['state']==='queued'&&$speechCommand['parameters']===$speechParameters,'Browser speech changed text or claimed game completion');
    $assert($products->queueDebugCommand($browserSession,'player.dialogue.submit',$speechParameters,$speechId)===$speechCommand,
        'Browser retry queued duplicate speech');
    try{$products->queueDebugCommand($browserSession,'player.dialogue.submit',array_replace($speechParameters,['text'=>'Changed']),$speechId);$assert(false,'browser request ID reused for changed text');}
    catch(InvalidArgumentException $error){$assert($error->getMessage()==='browser_speech_request_conflict','unexpected browser retry error');}
} finally {$db->rollBack();}

// Execute factory reset only on this disposable fixture, after the other runtime assertions.
$factoryDirectory=getenv('LORKHAN_TEST_FACTORY_DIR')?:'';
if($factoryDirectory!==''){
    $factoryRoot=sys_get_temp_dir().'/lorkhan-factory-worker-'.bin2hex(random_bytes(8));
    $factoryConfig=['database_dsn'=>$dsn,'database_user'=>getenv('LORKHAN_TEST_DB_USER')?:'',
        'database_password'=>getenv('LORKHAN_TEST_DB_PASSWORD')?:'','backup_storage_path'=>$factoryRoot,
        'factory_storage_path'=>$factoryDirectory,'voice_storage_path'=>$factoryRoot.'/voices'];
    $manifestPath=$factoryDirectory.'/factory.json';$manifestText=(string)file_get_contents($manifestPath);
    $factoryManifest=json_decode($manifestText,true,16,JSON_THROW_ON_ERROR);
    $factoryFingerprint=hash('sha256',$factoryManifest['migration_fingerprint']."\0".$factoryManifest['catalog_fingerprint']);
    // Match deployment ownership without granting the worker superuser or extension ownership.
    $db->exec(<<<'SQL'
CREATE ROLE factory_runtime LOGIN;
GRANT USAGE,CREATE ON SCHEMA public,lorkhan_internal TO factory_runtime;
DO $ownership$ DECLARE r record; BEGIN
 EXECUTE format('ALTER DATABASE %I OWNER TO factory_runtime',current_database());
 FOR r IN SELECT n.nspname,c.relname,c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
 WHERE n.nspname IN ('public','lorkhan_internal') AND c.relkind IN ('r','p','v','m','S')
 AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid='pg_class'::regclass AND d.objid=c.oid AND d.deptype='e') ORDER BY c.relkind='S'
 LOOP EXECUTE format('ALTER %s %I.%I OWNER TO factory_runtime',CASE r.relkind WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' WHEN 'S' THEN 'SEQUENCE' ELSE 'TABLE' END,r.nspname,r.relname); END LOOP;
 FOR r IN SELECT p.oid::regprocedure AS name FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
 WHERE n.nspname IN ('public','lorkhan_internal') AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid='pg_proc'::regclass AND d.objid=p.oid AND d.deptype='e')
 LOOP EXECUTE format('ALTER FUNCTION %s OWNER TO factory_runtime',r.name); END LOOP;
END $ownership$;
SQL);
    $factoryConfig['database_user']='factory_runtime';$factoryConfig['database_password']='';
    $db->query('SELECT pg_advisory_unlock_all()');
    $db=\LorkhanServer\Infrastructure\Connection::open($factoryConfig,false);
    $db->exec('SET search_path TO lorkhan_internal,public');
    $assert(!$db->query("SELECT rolsuper FROM pg_roles WHERE rolname=current_user")->fetchColumn(),'factory fixture must not be superuser');
    $factoryJobs=new JobRepository($db);
    $factoryHandler=new class(new \LorkhanServer\Application\DatabaseFactoryResetJobHandler($db,$factoryConfig)) implements \LorkhanServer\Application\JobHandler {
        public array $errors=[];
        public function __construct(private readonly \LorkhanServer\Application\JobHandler $inner){}
        public function supports(string $type,int $version):bool{return $this->inner->supports($type,$version);}
        public function handle(array $payload,string $key,callable $heartbeat):void{
            try{$this->inner->handle($payload,$key,$heartbeat);}catch(\Throwable $error){$this->errors[]=$error->getMessage();throw $error;}
        }
    };
    $factoryRegistry=new \LorkhanServer\Application\JobHandlerRegistry([$factoryHandler]);
    $factoryWorker=new Worker($factoryJobs,$factoryRegistry,'factory-fixture',30,1,1,1,60,['database.factory_reset']);
    $db->exec("CREATE TABLE public.factory_removed(value text); INSERT INTO public.factory_removed VALUES('old-user-data')");
    $eventCount=(int)$db->query('SELECT count(*) FROM source_events')->fetchColumn();
    $assert($eventCount>0,'factory test needs existing history');
    $controlDigest=static function()use($db):string{
        return (string)$db->query("SELECT md5(COALESCE((SELECT jsonb_agg(to_jsonb(t) ORDER BY installation_id)::text FROM installations t),'')||COALESCE((SELECT jsonb_agg(to_jsonb(t) ORDER BY pairing_token_id)::text FROM pairing_tokens t),'')||COALESCE((SELECT jsonb_agg(to_jsonb(t) ORDER BY session_hash)::text FROM browser_sessions t),''))")->fetchColumn();
    };
    $originalControl=$controlDigest();
    try{
        $badJob=\LorkhanServer\Infrastructure\Uuid::v4();$badRollback=\LorkhanServer\Infrastructure\Uuid::v4();
        $factoryJobs->enqueue($badJob,'database.factory_reset',1,$badJob,['fingerprint'=>str_repeat('0',64),'rollback_id'=>$badRollback],1);
        $badStats=$factoryWorker->run();
        $assert($badStats['dead']===1&&$badStats['retried']===0,'stale factory request retried or ran');
        $assert(!is_file((new \LorkhanServer\Infrastructure\DatabaseSqlBackup($factoryConfig))->path($badRollback)),'stale reset created a backup');
        $badManifest=$factoryManifest;$badManifest['seed_sha256']=str_repeat('0',64);
        file_put_contents($manifestPath,json_encode($badManifest,JSON_THROW_ON_ERROR));
        $failedJob=\LorkhanServer\Infrastructure\Uuid::v4();$failedRollback=\LorkhanServer\Infrastructure\Uuid::v4();
        $factoryJobs->enqueue($failedJob,'database.factory_reset',1,$failedJob,['fingerprint'=>$factoryFingerprint,'rollback_id'=>$failedRollback],1);
        $failedStats=$factoryWorker->run();
        $assert($failedStats['dead']===1&&$failedStats['retried']===0,'mismatched factory state was committed or retried');
        $assert((int)$db->query('SELECT count(*) FROM source_events')->fetchColumn()===$eventCount
            &&$db->query('SELECT value FROM public.factory_removed')->fetchColumn()==='old-user-data'&&$controlDigest()===$originalControl,'failed reset lost data or authentication');
        $assert($factoryHandler->errors===["factory_source_changed","factory_state_mismatch"],"factory failure did not reach expected validation stage: ".json_encode($factoryHandler->errors));
        file_put_contents($manifestPath,$manifestText);
        $factoryJob=\LorkhanServer\Infrastructure\Uuid::v4();$rollback=\LorkhanServer\Infrastructure\Uuid::v4();
        $factoryJobs->enqueue($factoryJob,'database.factory_reset',1,$factoryJob,['fingerprint'=>$factoryFingerprint,'rollback_id'=>$rollback],1);
        $factoryStats=$factoryWorker->run();
        $assert($factoryStats['succeeded']===1&&$factoryStats['dead']===0,'factory worker did not succeed');
        $assert((int)$db->query('SELECT count(*) FROM source_events')->fetchColumn()===0
            &&$db->query("SELECT to_regclass('public.factory_removed')")->fetchColumn()===null,'factory reset retained old history or relations');
        $assert($controlDigest()===$originalControl,'factory reset changed login or pairing identities');
        $assert((int)$db->query('SELECT count(*) FROM configuration_sets')->fetchColumn()>0,'factory reset did not provision defaults');
        $backup=(new ProductRepository($db))->configurationBackupRecord($rollback);
        $backupPath=(new \LorkhanServer\Infrastructure\DatabaseSqlBackup($factoryConfig))->path($rollback);
        $assert(($backup['scope']['factory_reset']??false)===true&&$backup['scope']['rollback_for']===$factoryJob
            &&hash_equals($backup['content_sha256'],hash_file('sha256',$backupPath)),'factory rollback backup missing or changed');
        $restoreId=(new \LorkhanServer\Infrastructure\ManagementRepository($db))->queueDatabaseRestore($rollback);
        $restoreRegistry=new \LorkhanServer\Application\JobHandlerRegistry([new \LorkhanServer\Application\DatabaseRestoreJobHandler($db,$factoryConfig)]);
        $restoreStats=(new Worker($factoryJobs,$restoreRegistry,'factory-rollback-fixture',30,1,1,1,60,['database.restore']))->run();
        $assert($restoreStats['succeeded']===1&&$restoreStats['dead']===0,'factory rollback archive did not restore');
        $assert((int)$db->query('SELECT count(*) FROM source_events')->fetchColumn()===$eventCount
            &&$db->query('SELECT value FROM public.factory_removed')->fetchColumn()==='old-user-data','factory rollback lost original history or table');
        $assert($controlDigest()===$originalControl,'factory rollback changed authentication identities');
        $db->exec('DROP TABLE public.factory_removed');
    }finally{
        file_put_contents($manifestPath,$manifestText);
        foreach(glob($factoryRoot.'/sql/*')?:[] as $file)unlink($file);
        if(is_dir($factoryRoot.'/sql'))rmdir($factoryRoot.'/sql');
        if(is_dir($factoryRoot))rmdir($factoryRoot);
    }
}

fwrite(STDOUT, "integration vertical slice passed\n");
