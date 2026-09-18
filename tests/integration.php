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
use LorkhanServer\Infrastructure\Uuid;
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
// Preserve legacy-installation coverage; explicit character adoption is tested separately below.
unset($session['character_id'],$session['character_binding']);
$session['runtime']['capabilities'][]='debug.commands.v1';
$session['runtime']['capabilities'][]='debug.npc_manager.v1';
$session['runtime']['capabilities'][]='speech.browser.v1';
$session['runtime']['capabilities'][]='action.conversation.end';
[$status] = $call($router, 'POST', $base . '/sessions', $jsonAuth, [], $session);
$assert($status === 422, 'missing session idempotency key accepted');
[$status] = $call($router, 'POST', $base . '/sessions', $headers($newUuid(3)), [], $session);
$assert($status === 422, 'incoherent session idempotency key accepted');
[$status, $accepted] = $call($router, 'POST', $base . '/sessions', $headers($session['message_id']), [], $session);
$assert($status === 201 && $accepted['generation'] === 7
    && $accepted['capabilities'] === ['dialogue.text', 'speech.say', 'speech.listen', 'controls.session', 'debug.commands.v1', 'debug.npc_manager.v1', 'speech.browser.v1', 'action.inspect.report', 'action.ai.follow',
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
// Observed names update only the current player and do not churn profile revisions.
$db->beginTransaction();
try{
    $observed=['kind'=>'player','display_name'=>'RANGROO','race'=>'argonian','class'=>'crusader'];
    $products->observePlayerName($installationId,$sessionId,7,$observed,gmdate('c'));
    $named=$products->playerProfileForInstallation($installationId);
    $assert($named['name']==='RANGROO'&&$named['actor_identity']['display_name']==='RANGROO'
        &&$named['content']===$playerProfile['content'],'game player name was not saved without changing content');
    $products->observePlayerName($installationId,$sessionId,7,$observed,gmdate('c'));
    $products->observePlayerName($installationId,$sessionId,6,['kind'=>'player','display_name'=>'Wrong session'],gmdate('c'));
    $products->observePlayerName($installationId,$sessionId,7,['kind'=>'npc','display_name'=>'Caius'],gmdate('c'));
    $products->observePlayerName($installationId,$sessionId,7,['kind'=>'player','display_name'=>' '],gmdate('c'));
    $unchanged=$products->playerProfileForInstallation($installationId);
    $assert($unchanged['name']==='RANGROO'&&$unchanged['revision']===$named['revision'],'invalid or duplicate name observation changed player');
}finally{$db->rollBack();}
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
$automaticContext=['targetState'=>['recordProvenance'=>['state'=>'complete','record_id'=>$automaticTarget['record_id'],
    'files'=>['Morrowind.esm'],'winning_file'=>'Morrowind.esm'],
    'identity'=>['race'=>'Wood Elf','class'=>'Commoner','gender'=>'Male','is_male'=>true],
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
$assert(($products->getRevisioned('profile',$tieProfileId)['core_profile_id']??null)===$tieCoreNew['core_profile_id'],
    'equal-priority assignment rules did not apply the newer rule like Herika');
// Advanced rules use PostgreSQL regex and apply all matching actions before first profile creation.
$advancedTarget=$tieTarget;$advancedTarget['record_id']='advanced_rule_actor';$advancedTarget['display_name']='Rule Advanced NPC';$advancedTarget['refnum']['index']+=100;
$advancedInput=['installation_id'=>$installationId,'description'=>'Advanced low','core_profile_id'=>$tieCoreOld['core_profile_id'],'priority'=>60,'enabled'=>true,'match'=>$emptyRuleMatch,
    'advanced'=>['regex'=>['names'=>'^Rule Advanced','record_ids'=>'^advanced_'], 'action'=>['npc_static_bio'=>'Rule biography','personality'=>'Low priority','settings_overrides'=>['prompt'=>['prompt_head'=>'Rule prompt']]]]];
$advancedLow=$products->saveProfileAssignmentRule($advancedInput,$now);
$advancedInput['description']='Advanced high';$advancedInput['priority']=70;$advancedInput['core_profile_id']=$tieCoreNew['core_profile_id'];$advancedInput['advanced']['action']=['personality'=>'High priority'];
$advancedHigh=$products->saveProfileAssignmentRule($advancedInput,$now);
$actionOnlyInput=$advancedInput;$actionOnlyInput['description']='Action only';$actionOnlyInput['priority']=80;$actionOnlyInput['core_profile_id']='';$actionOnlyInput['advanced']['action']=['appearance'=>'Rule appearance','metadata'=>['MAX_WORDS_LIMIT'=>'173','RECHAT_P'=>'0','RECHAT_ALLOW_ACTIONS'=>'false','DIARY_PROMPT'=>'Rule diary instructions','DIARY_COOLDOWN'=>'240','CONTEXT_HISTORY_DIARY'=>'35']];
$actionOnly=$products->saveProfileAssignmentRule($actionOnlyInput,$now);
$listedActionOnly=array_values(array_filter($products->profileAssignmentRulesPlan($installationId)['rules'],static fn(array $row):bool=>$row['rule_id']===$actionOnly['rule_id']))[0];
$assert($listedActionOnly['core_profile_id']==='','action-only rule missing from editor list');
$db->beginTransaction();$db->exec('SAVEPOINT action_rule_down');
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/data/migrations/104_action_only_profile_rules.down.sql'));$assert(false,'action-only rule downgrade discarded state');}
catch(PDOException $error){$db->exec('ROLLBACK TO SAVEPOINT action_rule_down');$assert(str_contains($error->getMessage(),'Assign or remove action-only'),'wrong action-only downgrade guard');}
$db->rollBack();

$advancedTurn=['session_id'=>$sessionId,'generation'=>7,'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],'payload'=>['target'=>$advancedTarget,'context'=>$automaticContext]];
$advancedProfileId=$products->ensureMorrowindActorProfile($advancedTurn,$automaticVoice,$now);
$advancedProfile=$products->getRevisioned('profile',$advancedProfileId);
$advancedEffective=$products->effectiveSettingsForProfile($installationId,$advancedProfileId);
$assert($advancedEffective['settings']['response']['max_words']===173&&$advancedEffective['settings']['behavior']['rechat_probability_percent']===0&&$advancedEffective['settings']['behavior']['rechat_allow_actions']===false,'rule metadata did not reach effective NPC settings');
$assert($advancedProfile['core_profile_id']===$tieCoreNew['core_profile_id']&&$advancedProfile['content']['appearance']==='Rule appearance'&&$advancedProfile['content']['personality']==='High priority'&&$advancedProfile['content']['biography']==='Rule biography'&&$advancedProfile['content']['settings_overrides']['prompt']['prompt_head']==='Rule prompt','advanced regex/actions did not reach the created NPC');
$advancedDiary=$products->effectiveSettingsForProfile($installationId,$advancedProfileId)['settings']['diary'];
$assert($advancedProfile['content']['diary']['prompt']==='Rule diary instructions'
    &&$advancedDiary['automatic_interval_seconds']===240&&$advancedDiary['context_turn_limit']===35,
    'profile rule diary fields did not persist into the NPC editor and effective settings');
$db->beginTransaction();
$automaticTaskGlobal=$products->globalSettingsForInstallation($installationId);$automaticTaskContent=$automaticTaskGlobal['content']??\LorkhanServer\Application\SettingsCatalog::globalDefaults();$automaticTaskContent['task_availability']['profile_generation']=false;
if($automaticTaskGlobal)$products->revise('global_settings',$automaticTaskGlobal['configuration_id'],$automaticTaskContent,'test',$now);
else $products->createRevisioned('global_settings',['installation_id'=>$installationId,'name'=>'Task availability','content'=>$automaticTaskContent],$now);
$assert($products->maybeEnqueueAutomaticProfileBackfill($advancedProfileId,$session['playthrough_id'])['reason']==='profile_tasks_disabled','disabled automatic backfill was not skipped');
$assert($products->maybeEnqueueDynamicProfileEvolution($advancedProfileId,$session['playthrough_id'],$sessionId)['reason']==='profile_tasks_disabled','disabled profile evolution was not skipped');
$db->rollBack();
$advancedManual=$advancedProfile['content'];$advancedManual['personality']='Manual edit';$products->revise('profile',$advancedProfileId,$advancedManual,'test',$now);
$products->ensureMorrowindActorProfile($advancedTurn,$automaticVoice,$now);
$assert($products->getRevisioned('profile',$advancedProfileId)['content']['personality']==='Manual edit','rules overwrote an existing NPC');
$advancedInput['advanced']['regex']['names']='[';
try{$products->saveProfileAssignmentRule($advancedInput,$now);$assert(false,'invalid PostgreSQL regex saved');}catch(InvalidArgumentException $error){$assert($error->getMessage()==='invalid_or_expensive_rule_regex','invalid regex was not rejected explicitly');}
$assert((int)$db->query('SELECT 1')->fetchColumn()===1,'regex validation poisoned the database transaction');
$defaultActionTarget=$advancedTarget;$defaultActionTarget['record_id']='default_action_actor';$defaultActionTarget['display_name']='Action only NPC';$defaultActionTarget['refnum']['index']+=1;
$actionOnlyInput['rule_id']=$actionOnly['rule_id'];$actionOnlyInput['advanced']['regex']=['names'=>'^Action only NPC$'];$products->saveProfileAssignmentRule($actionOnlyInput,$now);
$defaultActionTurn=$advancedTurn;$defaultActionTurn['payload']['target']=$defaultActionTarget;$defaultActionTurn['payload']['context']=[];
$defaultActionId=$products->ensureMorrowindActorProfile($defaultActionTurn,$automaticVoice,$now);
$defaultActionProfile=$products->getRevisioned('profile',$defaultActionId);
$assert($defaultActionProfile['core_profile_id']===$products->defaultCoreProfileForInstallation($installationId,$now,true)['core_profile_id']&&$defaultActionProfile['content']['appearance']==='Rule appearance','action-only rule did not preserve the default Core Profile');
$products->deleteProfileAssignmentRule($installationId,$actionOnly['rule_id']);
$products->deleteProfileAssignmentRule($installationId,$advancedLow['rule_id']);$products->deleteProfileAssignmentRule($installationId,$advancedHigh['rule_id']);

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
$movedTarget=$automaticTarget;$movedTarget['cell']=['kind'=>'interior','name'=>'Balmora, Guild of Mages'];$movedTarget['refnum']['content_file']=7;
$movedProfileId=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$movedTarget]],$automaticVoice,$now);
$movedProfile=$products->getRevisioned('profile',$movedProfileId);
$assert($movedProfileId===$automaticProfileId
    &&str_contains((string)($movedProfile['content']['oghma_knowledge_tags']??''),'bitter_coast')
    &&!str_contains((string)($movedProfile['content']['oghma_knowledge_tags']??''),'west_gash'),
    'walking into another region rewrote an NPC immutable home locality');
$db->exec('SAVEPOINT reference_group_parity');
$referenceGroup=['group_key'=>'test-ref-alias','name'=>'Test alternate reference','enabled'=>true,
    'canonical_ref'=>\LorkhanServer\Domain\ProfileId::reference($automaticTarget),
    'aliases'=>[\LorkhanServer\Domain\ProfileId::reference($secondPlacement)]];
$products->saveReferenceGroup($installationId,$referenceGroup);
$groupedPlacementId=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$secondPlacement]],$automaticVoice,$now);
$assert($groupedPlacementId===$automaticProfileId,'explicit alternate reference did not share canonical profile');
try{$products->saveReferenceGroup($installationId,array_replace($referenceGroup,['group_key'=>'test-ref-overlap']));throw new RuntimeException('overlapping reference group accepted');}
catch(InvalidArgumentException $error){$assert($error->getMessage()==='reference_already_in_group','reference group overlap not rejected');}
$products->saveReferenceGroup($installationId,array_replace($referenceGroup,['enabled'=>false]));
$independentPlacementId=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$secondPlacement]],$automaticVoice,$now);
$assert($independentPlacementId===$secondPlacementProfileId,'disabled group did not restore independent profile');
$products->deleteReferenceGroup($installationId,'test-ref-alias');
$assert(!in_array('test-ref-alias',array_column($products->referenceGroups($installationId),'group_key'),true),'reference group delete failed');
$db->exec('ROLLBACK TO SAVEPOINT reference_group_parity');
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
$assert($rediscoveredProfileId===$deletedProfile['profile_id']&&$rediscoveredProfile['name']==='Rediscovered Bosmer',
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
$assert($automaticSpeech===['voice'=>'mw_wood_elf_male'],
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
// Core quick slots use the existing typed Interact editor, independently of LLM mode selection.
$slotOwnsTransaction=!$db->inTransaction();if($slotOwnsTransaction)$db->beginTransaction();
$db->exec('SAVEPOINT core_slot_menu_probe');
try{
    $db->prepare('UPDATE core_profiles SET slot=NULL WHERE installation_id=:installation')->execute(['installation'=>$installationId]);
    $db->prepare('UPDATE core_profiles SET slot=1 WHERE core_profile_id=:id')->execute(['id'=>$actorCoreProfile['core_profile_id']]);
    $db->prepare('UPDATE core_profiles SET slot=2 WHERE core_profile_id=:id')->execute(['id'=>$speechCore['core_profile_id']]);
    $query=$controlsQuery;$query['message_id']=\LorkhanServer\Infrastructure\Uuid::v4();$query['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();$query['include_settings_editor']=true;
    [$status,$menu]=$call($router,'POST',$base.'/controls/query',$jsonAuth,[],$query);
    $npcFields=array_column($menu['settings_editor']['sections'],null,'scope')['npc']['fields'];
    $slotField=array_column($npcFields,null,'key')['management.core_profile_slot'];
    $assert($status===200&&$slotField['value']==='1'&&array_column($slotField['choices'],'value')===['1','2'],'Core slot menu did not reflect configured slots and current assignment');
    $beforeOther=$products->getRevisioned('profile',$speechProfile['profile_id']);
    $beforeNpc=$products->getRevisioned('profile',$actorProfile['profile_id']);
    $beforeDefaults=$db->query('SELECT core_profile_id FROM core_profiles WHERE default_npc=true ORDER BY core_profile_id')->fetchAll(\PDO::FETCH_COLUMN);
    $pick=$selectModel;$pick['message_id']=\LorkhanServer\Infrastructure\Uuid::v4();$pick['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();
    $pick['kind']='setting';$pick['selection_id']=null;$pick['selection_key']=null;
    $pick['setting']=['scope'=>'npc','key'=>'management.core_profile_slot','value'=>'2','change_token'=>$menu['settings_editor']['change_token']];
    [$status,$selected]=$call($router,'POST',$base.'/controls/select',$headers($pick['message_id']),[],$pick);
    $assert($status===200&&$selected['effective_settings']['core_profile_id']===$speechCore['core_profile_id']&&$selected['selected_model_slot_key']==='fast','Core slot selection did not change only the targeted Core assignment');
    $afterNpc=$products->getRevisioned('profile',$actorProfile['profile_id']);
    $assert($afterNpc['content']===$beforeNpc['content']&&$afterNpc['current_revision']===$beforeNpc['current_revision'],'Core assignment rewrote NPC personality or lock state');
    $assert($products->getRevisioned('profile',$speechProfile['profile_id'])===$beforeOther&&$db->query('SELECT core_profile_id FROM core_profiles WHERE default_npc=true ORDER BY core_profile_id')->fetchAll(\PDO::FETCH_COLUMN)===$beforeDefaults,'Core slot selection changed another NPC or automatic defaults');
    [$status,$replay]=$call($router,'POST',$base.'/controls/select',$headers($pick['message_id']),[],$pick);
    $assert($status===200&&$replay==$selected,'Core slot selection was not idempotent');
    $pick['message_id']=\LorkhanServer\Infrastructure\Uuid::v4();$pick['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();$pick['setting']['value']='1';
    [$status]=$call($router,'POST',$base.'/controls/select',$headers($pick['message_id']),[],$pick);
    $assert($status===409,'stale Core slot menu changed an assignment');
    $pick['message_id']=\LorkhanServer\Infrastructure\Uuid::v4();$pick['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();$pick['setting']['change_token']=$selected['settings_editor']['change_token'];$pick['setting']['value']='4';
    [$status]=$call($router,'POST',$base.'/controls/select',$headers($pick['message_id']),[],$pick);
    $assert($status===422,'an unconfigured Core slot was accepted');
    $db->prepare('UPDATE core_profiles SET slot=3 WHERE core_profile_id=:id')->execute(['id'=>$speechCore['core_profile_id']]);
    $pick['message_id']=\LorkhanServer\Infrastructure\Uuid::v4();$pick['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();$pick['setting']['value']='1';
    [$status]=$call($router,'POST',$base.'/controls/select',$headers($pick['message_id']),[],$pick);
    $assert($status===409,'web slot reassignment did not invalidate the game menu');
}finally{$db->exec('ROLLBACK TO SAVEPOINT core_slot_menu_probe');if($slotOwnsTransaction)$db->rollBack();}

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
    &&!$queuedProfileJobRow,
    'disabled dynamic profile incorrectly queued full regeneration');

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
    &&!$queuedNarratorJobRow,
    'disabled narrator dynamic profile incorrectly queued full regeneration: '.json_encode([
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
    'level'=>1,'disposition'=>50,'factions'=>['fighters guild'],
    'record_provenance'=>['state'=>'complete','record_id'=>$autoTarget['record_id'],'files'=>['Morrowind.esm'],'winning_file'=>'Morrowind.esm']];
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

// Independent inventory admission is typed and idempotent, without creating model work.
$inventoryIngress=$autoProfileData;$inventoryIngress['type']='inventory';
$inventoryIngress['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();
$inventoryIngress['payload']=['owner'=>$autoTarget,'items'=>[['record_id'=>'common_robe','name'=>'Common Robe','count'=>1,'value'=>2,'equipped'=>true]]];
$turnsBeforeInventory=(int)$db->query('SELECT count(*) FROM turns')->fetchColumn();
[$inventoryStatus,$inventoryReceipt]=$call($router,'POST',$base.'/gamedata',$headers($inventoryIngress['request_id']),[],$inventoryIngress);
[$inventoryReplayStatus,$inventoryReplay]=$call($router,'POST',$base.'/gamedata',$headers($inventoryIngress['request_id']),[],$inventoryIngress);
$assert($inventoryStatus===202&&$inventoryReceipt['type']==='inventory'&&$inventoryReplayStatus===202&&$inventoryReplay==$inventoryReceipt
    &&(int)$db->query('SELECT count(*) FROM turns')->fetchColumn()===$turnsBeforeInventory,'typed inventory ingress/replay failed or created a dialogue turn');
$badInventoryIngress=$inventoryIngress;$badInventoryIngress['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();
$badInventoryIngress['payload']['items'][0]['condition']=1.5;
[$badInventoryStatus]=$call($router,'POST',$base.'/gamedata',$headers($badInventoryIngress['request_id']),[],$badInventoryIngress);
$assert($badInventoryStatus===422,'inventory ingress accepted an invalid normalized condition');

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
$unassignedProfileTasks=$backfillSettings;$unassignedProfileTasks['system_routing']['profile_generation_configuration_id']='';
$products->revise('global_settings',$backfillGlobal['configuration_id'],$unassignedProfileTasks,'unassigned profile tasks fixture',$now);
$unassignedBackfill=$products->maybeEnqueueAutomaticProfileBackfill($backfillProfile['profile_id'],$session['playthrough_id']);
$assert($unassignedBackfill['queued']===false&&$unassignedBackfill['reason']==='profile_generation_connector_unavailable','unassigned automatic backfill did not skip');
$products->revise('global_settings',$backfillGlobal['configuration_id'],$backfillSettings,'restore profile task route',$now);
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
$backfillData['payload']['record_provenance']['record_id']=$backfillTarget['record_id'];
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
$backfillHandlerPayload=$backfillPayload;
$backfillHandlerPayload['recent_events'][0]['scene_event']='A bell rings.';
unset($backfillHandlerPayload['recent_events'][0]['player_input']);
$leaseProfileFixture=$db->prepare("UPDATE durable_jobs SET state='leased',attempt_count=1,lease_owner='profile-fixture',
    lease_token=:token,leased_at=clock_timestamp(),lease_expires_at=clock_timestamp()+interval '5 minutes',heartbeat_at=clock_timestamp() WHERE job_id=:job");
$leaseProfileFixture->execute(['job'=>$backfillJobRow['job_id'],'token'=>\LorkhanServer\Infrastructure\Uuid::v4()]);
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
$dynamicContent['dynamic_profile_fields']=['goals'];$dynamicContent['personality']='Baseline personality to evolve.';
$dynamicContent['occupation']='Baseline occupation.';$dynamicContent['skills']='Baseline skills.';
$dynamicContent['speech_style']='Speech style must remain unchanged.';
$dynamicContent['goals']='Goals must remain unchanged.';
$dynamicContent['settings_overrides']['profile_evolution']['history_limit']=2;
$dynamicProfile=$products->revise('profile',$backfillProfile['profile_id'],$dynamicContent,'enable dynamic profile fixture',$now);
$db->prepare("UPDATE sessions SET created_at=clock_timestamp()-interval '21 minutes' WHERE session_id=:session")
    ->execute(['session'=>$sessionId]);
$evolutionHistoryCore=$products->getRevisioned('core_profile',$evolutionCore['core_profile_id']);
$evolutionHistoryContent=$evolutionHistoryCore['content'];$evolutionHistoryContent['settings_overrides']['profile_evolution']['history_limit']=3;
$evolutionHistoryContent['settings_overrides']['profile_evolution']['fields']=['personality','occupation','skills'];
$products->revise('core_profile',$evolutionCore['core_profile_id'],$evolutionHistoryContent,'bounded evolution history fixture',$now);
$db->prepare('UPDATE profiles SET core_profile_id=:core WHERE profile_id IN (:npc,:narrator)')->execute([
    'core'=>$evolutionCore['core_profile_id'],'npc'=>$dynamicProfile['profile_id'],'narrator'=>$narratorProfile['profile_id']]);
$products->revise('global_settings',$backfillGlobal['configuration_id'],$unassignedProfileTasks,'unassigned evolution route fixture',$now);
$db->prepare('INSERT INTO lorkhan_internal.profile_evolution_clocks(installation_id,playthrough_id,epoch,game_minute,started_minute)
    VALUES(:installation,:playthrough,:epoch,10000,10000) ON CONFLICT(installation_id,playthrough_id) DO UPDATE SET game_minute=10000,started_minute=10000')
    ->execute(['installation'=>$session['installation_id'],'playthrough'=>$session['playthrough_id'],'epoch'=>\LorkhanServer\Infrastructure\Uuid::v4()]);
$unassignedEvolution=$products->maybeEnqueueDynamicProfileEvolution($dynamicProfile['profile_id'],$session['playthrough_id'],$sessionId,true);
$assert($unassignedEvolution['queued']===false&&$unassignedEvolution['reason']==='profile_generation_connector_unavailable','unassigned evolution did not skip');
$products->revise('global_settings',$backfillGlobal['configuration_id'],$backfillSettings,'restore evolution route',$now);
$dynamicQueued=$products->maybeEnqueueDynamicProfileEvolution($dynamicProfile['profile_id'],$session['playthrough_id'],$sessionId,true);
$dynamicJob=$db->prepare("SELECT job_id,state,payload FROM durable_jobs WHERE job_type='profile.generate' "
    ."AND payload->>'profile_id'=:profile AND payload->>'mode'='profile_evolution'");
$dynamicJob->execute(['profile'=>$dynamicProfile['profile_id']]);$dynamicJobRow=$dynamicJob->fetch();
$dynamicPayload=$dynamicJobRow?json_decode((string)$dynamicJobRow['payload'],true,64,JSON_THROW_ON_ERROR):[];
$assert(($dynamicQueued['queued']??false)===true&&$dynamicJobRow&&$dynamicJobRow['state']==='queued'
    &&($dynamicPayload['dynamic_fields']??null)===['personality','occupation','skills']
    &&count($dynamicPayload['source_turn_ids']??[])===2&&count($dynamicPayload['recent_events']??[])===2,
    'dynamic NPC profile evolution did not freeze its selected fields and NPC-limited witnessed history');
// Exercise the UI's full 400-turn evolution range and byte-truncated provenance together.
$scheduleOwns=!$db->inTransaction();if($scheduleOwns)$db->beginTransaction();$db->exec('SAVEPOINT evolution_schedule_probe');
try{
    $scheduler=new \LorkhanServer\Infrastructure\ProfileEvolutionScheduler($db);
    $scheduleIdentity=['kind'=>'actor','record_id'=>'evolution_inherited'];
    $schedulePolicy=['interval_days'=>1,'min_events'=>2,'cooldown_minutes'=>5];
    $probe=fn()=>$scheduler->prepare($installationId,$inheritedNpc['profile_id'],$session['playthrough_id'],$scheduleIdentity,$schedulePolicy);
    $assert(!$probe()['due'],'fresh game clock bypassed elapsed-day gate');
    $db->prepare('UPDATE lorkhan_internal.profile_evolution_clocks SET game_minute=started_minute+1440 WHERE installation_id=:installation AND playthrough_id=:playthrough')
        ->execute(['installation'=>$installationId,'playthrough'=>$session['playthrough_id']]);
    foreach(['spoken','pending','combatbark'] as $state){
        $q=$db->prepare("INSERT INTO eventlog(type,data,localts,gamets,utterance_id,delivery_state) VALUES(:type,'evolution fixture',0,0,:utterance,:state) RETURNING rowid");
        $q->execute(['type'=>$state==='combatbark'?'combatbark':'chat','utterance'=>\LorkhanServer\Infrastructure\Uuid::v4(),'state'=>$state==='combatbark'?'spoken':$state]);$rowid=$q->fetchColumn();
        $db->prepare("INSERT INTO eventlog_metadata(rowid,installation_id,playthrough_id,projection_kind,projection_key,speaker)
            VALUES(:row,:installation,:playthrough,'evolution_probe',:key,CAST(:identity AS jsonb))")
            ->execute(['row'=>$rowid,'installation'=>$installationId,'playthrough'=>$session['playthrough_id'],'key'=>'evolution:'.$rowid,'identity'=>json_encode($scheduleIdentity)]);
        if($state==='pending')$pendingEvolutionRow=$rowid;
    }
    $progress=$probe();$assert(!$progress['due']&&$progress['observed']===1,'pending speech or combat bark counted as delivered evolution event');
    $db->prepare("UPDATE eventlog SET delivery_state='spoken' WHERE rowid=:row")->execute(['row'=>$pendingEvolutionRow]);
    $progress=$probe();$assert($progress['due']&&$progress['observed']===2,'late delivery acknowledgement was skipped');
    $assert($probe()['observed']===2,'repeated scheduler scan counted events twice');
    $scheduler->attempted($inheritedNpc['profile_id'],$session['playthrough_id']);
    $assert(!$probe()['due'],'real-time attempt cooldown was bypassed');
    $manualSchedule=$scheduler->prepare($installationId,$inheritedNpc['profile_id'],$session['playthrough_id'],$scheduleIdentity,$schedulePolicy,true);
    $assert($manualSchedule['due'],'manual evolution failed to bypass schedule');
    $scheduler->attempted($inheritedNpc['profile_id'],$session['playthrough_id'],$manualSchedule);
    $assert(!$probe()['due']&&$probe()['manual'],'manual failure lost intent or bypassed cooldown');
    $newManual=$scheduler->prepare($installationId,$inheritedNpc['profile_id'],$session['playthrough_id'],$scheduleIdentity,$schedulePolicy,true);
    $scheduler->complete(['profile_id'=>$inheritedNpc['profile_id'],'playthrough_id'=>$session['playthrough_id'],'evolution_schedule'=>$manualSchedule]);
    $assert($probe()['manual_request_id']===$newManual['manual_request_id']&&$probe()['manual'],'old success cleared newer manual request');
    for($manualAttempt=0;$manualAttempt<3;$manualAttempt++){
        $scheduler->attempted($inheritedNpc['profile_id'],$session['playthrough_id'],$newManual);
        $db->prepare("UPDATE lorkhan_internal.profile_evolution_progress SET attempted_at=clock_timestamp()-interval '2 days' WHERE profile_id=:profile")->execute(['profile'=>$inheritedNpc['profile_id']]);
    }
    $assert(!$probe()['manual'],'manual request exceeded three attempts');
    $scheduler->complete(['profile_id'=>$inheritedNpc['profile_id'],'playthrough_id'=>$session['playthrough_id'],'evolution_schedule'=>$progress]);
    $assert($probe()['observed']===0,'successful evolution failed to consume frozen events');
    $oldPayload=['profile_id'=>$inheritedNpc['profile_id'],'playthrough_id'=>$session['playthrough_id'],'evolution_schedule'=>$progress];
    $oldCalendar=['year'=>1,'month'=>0,'day'=>7,'hour'=>0];
    $scheduler->observe($installationId,$sessionId,'gamedata.context',['context'=>['world'=>['calendar'=>$oldCalendar]]]);
    $assert($scheduler->active($oldPayload),'delayed older calendar observation rewound the timeline');
    $scheduler->observe($installationId,$sessionId,'session.init',['loaded_save'=>$oldCalendar]);
    $assert(!$scheduler->active($oldPayload),'older-save epoch accepted stale queued profile work');
    $reset=$probe();$assert(!$reset['due']&&$reset['observed']===0,'older-save epoch reused prior timeline progress');
}finally{$db->exec('ROLLBACK TO SAVEPOINT evolution_schedule_probe');if($scheduleOwns)$db->rollBack();}
$historyOwns=!$db->inTransaction();if($historyOwns)$db->beginTransaction();$db->exec('SAVEPOINT profile_history_limits_probe');
try{
    for($index=0;$index<400;$index++)$insertBackfillTurn->execute([
        'turn'=>\LorkhanServer\Infrastructure\Uuid::v4(),'request'=>\LorkhanServer\Infrastructure\Uuid::v4(),
        'message'=>\LorkhanServer\Infrastructure\Uuid::v4(),'response'=>\LorkhanServer\Infrastructure\Uuid::v4(),
        'session'=>$sessionId,'input'=>'Hello','speaker'=>json_encode($playerProfile['actor_identity']),
        'target'=>json_encode($backfillTarget),'response_payload'=>json_encode(['lines'=>[['action'=>'say','speaker_identity'=>$backfillTarget,'text'=>'Hello']]]),'now'=>$now]);
    foreach(['npc','narrator','truncated']as$case){
        if($case==='truncated')$db->prepare("UPDATE turns SET input_text=repeat('history / ',800) WHERE session_id=:session AND target @> CAST(:target AS jsonb)")->execute(['session'=>$sessionId,'target'=>json_encode($backfillTarget)]);
        $largeContent=$dynamicContent;$largeContent['settings_overrides']['profile_evolution']['history_limit']=400;
        $largeIdentity=$case==='narrator'?['kind'=>'narrator','record_id'=>'lorkhan:narrator','content_file'=>'LORKHAN']:$backfillTarget;
        $largeProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'History limit '.$case,
            'actor_identity'=>$largeIdentity,'core_profile_id'=>$evolutionCore['core_profile_id'],'content'=>$largeContent],$now);
        $queued=$products->maybeEnqueueDynamicProfileEvolution($largeProfile['profile_id'],$session['playthrough_id'],$sessionId,true);
        $assert(($queued['queued']??false)===true,'history range fixture did not queue');
        $q=$db->prepare('SELECT payload FROM durable_jobs WHERE job_id=:id');$q->execute(['id'=>$queued['job_id']]);$largePayload=json_decode($q->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
        $events=$largePayload['recent_events'];$sources=$largePayload['source_turn_ids'];
        $assert($sources===array_column($events,'turn_id')&&strlen(json_encode($events,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))<=65536,'truncated history retained discarded source IDs or exceeded the worker byte cap');
        $assert($case==='truncated'?(count($events)>0&&count($events)<400):count($events)===400,'configured 400-turn limit was not honored within its byte budget');
        $largePayload['_job']=['job_id'=>$queued['job_id'],'attempt'=>1];
        $leaseProfileFixture->execute(['job'=>$queued['job_id'],'token'=>\LorkhanServer\Infrastructure\Uuid::v4()]);
        $handler=new \LorkhanServer\Application\ProfileGenerateJobHandler($products,new \LorkhanServer\Application\MockProfileGenerationProvider());
        if($case!=='truncated'){
            $tooLarge=$largePayload;$id=\LorkhanServer\Infrastructure\Uuid::v4();$tooLarge['source_turn_ids'][]=$id;$tooLarge['recent_events'][]=['turn_id'=>$id,'player_input'=>'Hello','npc_responses'=>['Hello']];
            try{$handler->handle($tooLarge,'oversized-history',static fn():bool=>true);$assert(false,'401 evolution turns were accepted');}
            catch(\InvalidArgumentException $error){$assert($error->getMessage()==='invalid_profile_backfill_context','unexpected evolution limit error');}
        }
        $handler->handle($largePayload,'history-limit-probe',static fn():bool=>true);
        $assert($products->getRevisioned('profile',$largeProfile['profile_id'])['current_revision']===$largePayload['base_revision']+1,'valid large or truncated history failed mock evolution');
    }
}finally{$db->exec('ROLLBACK TO SAVEPOINT profile_history_limits_probe');if($historyOwns)$db->rollBack();}

// Routine metadata revisions must not discard an update or be overwritten by it.
$baselineProbe=$products->getRevisioned('profile',$dynamicProfile['profile_id']);
$metadataContent=$baselineProbe['content'];$metadataContent['management']['runtime_probe']='preserve me';
$products->revise('profile',$dynamicProfile['profile_id'],$metadataContent,'metadata conflict regression',$now);
$assert($products->evolutionBaselineMatches($dynamicPayload),'unrelated metadata created an evolution conflict');
$changedBaseline=$dynamicPayload;$changedBaseline['evolution_baseline']['values']['personality']='concurrent human edit';
$assert(!$products->evolutionBaselineMatches($changedBaseline),'selected field conflict was ignored');
$changedBaseline=$dynamicPayload;$changedBaseline['evolution_baseline']['policy']['min_events']=9999;
$assert(!$products->evolutionBaselineMatches($changedBaseline),'schedule policy conflict was ignored');
$dynamicHandlerPayload=$dynamicPayload;
$leaseProfileFixture->execute(['job'=>$dynamicJobRow['job_id'],'token'=>\LorkhanServer\Infrastructure\Uuid::v4()]);
$dynamicHandlerPayload['_job']=['job_id'=>$dynamicJobRow['job_id'],'attempt'=>1];
(new \LorkhanServer\Application\ProfileGenerateJobHandler($products,
    new \LorkhanServer\Application\MockProfileGenerationProvider()))->handle($dynamicHandlerPayload,'profile-evolution-test',static fn():bool=>true);
$evolvedProfile=$products->getRevisioned('profile',$dynamicProfile['profile_id']);
$dynamicAgain=$products->maybeEnqueueDynamicProfileEvolution($dynamicProfile['profile_id'],$session['playthrough_id'],$sessionId);
$assert((int)$evolvedProfile['current_revision']===(int)$dynamicPayload['base_revision']+2
    &&$evolvedProfile['content']['management']['runtime_probe']==='preserve me'
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
$narratorEvolution=$products->maybeEnqueueDynamicProfileEvolution($narratorDynamic['profile_id'],$session['playthrough_id'],$sessionId,true);
$narratorEvolutionJob=$db->prepare("SELECT job_id,state,payload FROM durable_jobs WHERE job_type='profile.generate' "
    ."AND payload->>'profile_id'=:profile AND payload->>'mode'='narrator_profile_evolution'");
$narratorEvolutionJob->execute(['profile'=>$narratorDynamic['profile_id']]);$narratorEvolutionRow=$narratorEvolutionJob->fetch();
$narratorEvolutionPayload=$narratorEvolutionRow?json_decode((string)$narratorEvolutionRow['payload'],true,64,JSON_THROW_ON_ERROR):[];
$assert(count($narratorEvolutionPayload['source_turn_ids']??[])===3,'Narrator evolution did not inherit regular history for zero');
$assert(($narratorEvolution['queued']??false)===true&&$narratorEvolutionRow
    &&($narratorEvolutionPayload['dynamic_fields']??null)===['goals']
    &&count($narratorEvolutionPayload['recent_events']??[])===3,
    'dynamic narrator evolution did not freeze the shared witnessed history');
$narratorEvolutionHandler=$narratorEvolutionPayload;
$narratorEvolutionHandler['_job']=['job_id'=>$narratorEvolutionRow['job_id'],'attempt'=>1];
$leaseProfileFixture->execute(['job'=>$narratorEvolutionRow['job_id'],'token'=>\LorkhanServer\Infrastructure\Uuid::v4()]);
(new \LorkhanServer\Application\ProfileGenerateJobHandler($products,
    new \LorkhanServer\Application\MockProfileGenerationProvider()))->handle($narratorEvolutionHandler,'narrator-evolution-test',static fn():bool=>true);
$evolvedNarrator=$products->getRevisioned('profile',$narratorDynamic['profile_id']);
$assert(($evolvedNarrator['content']['personality']??null)==='Narrator personality must remain unchanged.'
    &&($evolvedNarrator['content']['goals']??'')!==($narratorDynamicContent['goals']??''),
    'dynamic narrator evolution changed an unselected field or failed to evolve its selected field');

// Scene classification is queued after a completed player turn and never changes NPC content.
$sceneRepo=new \LorkhanServer\Infrastructure\SceneClassificationRepository($db);
$sceneProvider=$products->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Scene fixture connector','content'=>['driver'=>'mock','model'=>'scene-test']],$now);
$sceneSettings=$backfillSettings;$sceneSettings['system_routing']['scene_classifier_configuration_id']=$sceneProvider['configuration_id'];
$products->revise('global_settings',$backfillGlobal['configuration_id'],$sceneSettings,'scene route fixture',$now);
$sceneTurn=$fixture('turn');$sceneTurn['installation_id']=$installationId;$sceneTurn['playthrough_id']=$session['playthrough_id'];
$sceneTurn['session_id']=$sessionId;$sceneTurn['turn_id']=$newUuid(6146);$sceneTurn['payload']['target']=$backfillTarget;
$sceneTurn['payload']['ui_source']='lorkhan_text';$sceneTurn['_selected_profile_id']=$backfillProfile['profile_id'];
$sceneQueued=$products->maybeEnqueueSceneClassification($sceneTurn);
$assert(($sceneQueued['queued']??false)===true,'scene classification did not queue after completed dialogue');
$assert($products->maybeEnqueueSceneClassification($sceneTurn)['job_id']===$sceneQueued['job_id'],'scene classification retry duplicated work');
$sceneVoice=$sceneTurn;$sceneVoice['payload']['input']['kind']='stt';
foreach(['lorkhan_voice','lorkhan_open_mic','lorkhan_browser_speech']as$sceneSource){$sceneVoice['payload']['ui_source']=$sceneSource;$assert($products->maybeEnqueueSceneClassification($sceneVoice)['job_id']===$sceneQueued['job_id'],'spoken dialogue source was not eligible: '.$sceneSource);}
$sceneInput=$sceneRepo->input($installationId,$backfillProfile['profile_id'],$sceneQueued['job_id']);
$assert(count($sceneInput['dialogue'])===10&&$sceneInput['dialogue'][0]['text']==='Observed exchange 6','scene history is not the last ten chronological lines');
$sceneJobs=new JobRepository($db);$sceneClaim=$sceneJobs->claim('scene-fixture',1,60,['scene.classify'])[0];
$sceneCalls=0;$sceneMock=new class($sceneCalls) implements \LorkhanServer\Application\ProfileGenerationProvider {
    public function __construct(public int &$calls){}
    public function generate(array $profile,CancellationToken $cancellation):array{$cancellation->throwIfCancellationRequested();$this->calls++;if(($profile['generation_mode']??'')!=='scene_classification')throw new RuntimeException('wrong_scene_mode');return ['genre'=>'romance'];}
};
$sceneHandler=new \LorkhanServer\Application\SceneClassifyJobHandler($sceneRepo,$products,new ProviderAttemptRepository($db),[],$sceneMock);
$scenePayload=$sceneClaim['payload']+['_job'=>['job_id'=>$sceneClaim['job_id'],'attempt'=>$sceneClaim['attempt_count'],'lease_token'=>$sceneClaim['lease_token']]];
$sceneOff=$sceneSettings;$sceneOff['task_availability']['scene_classifier']=false;
$products->revise('global_settings',$backfillGlobal['configuration_id'],$sceneOff,'disable scene fixture',$now);
try{$sceneHandler->handle($scenePayload,'scene-test',static fn()=>true);$assert(false,'disabled classifier executed');}catch(RuntimeException $e){$assert($e->getMessage()==='scene_classifier_unavailable'&&$sceneCalls===0,'disabled classifier reached provider');}
$products->revise('global_settings',$backfillGlobal['configuration_id'],$sceneSettings,'restore scene fixture',$now);
$sceneHandler->handle($scenePayload,'scene-test',static fn()=>true);
$assert($sceneRepo->context($installationId,$session['playthrough_id'],$backfillProfile['profile_id'])===null,'uncommitted scene result leaked');
$sceneJobs->succeed($sceneClaim['job_id'],$sceneClaim['lease_token']);
$sceneContext=$sceneRepo->context($installationId,$session['playthrough_id'],$backfillProfile['profile_id']);
$assert($sceneContext['genre']==='romance'&&$sceneContext['status']==='intimate'&&$sceneContext['note']!==''&&$sceneCalls===1,'scene result or timed note missing');
$assert($sceneRepo->context($installationId,\LorkhanServer\Infrastructure\Uuid::v4(),$backfillProfile['profile_id'])===null,'scene crossed playthrough');
try{$sceneRepo->save($sceneClaim['job_id'],1,$sceneClaim['lease_token'],'horror');$assert(false,'expired scene lease wrote');}catch(RuntimeException $e){$assert($e->getMessage()==='lease_lost','wrong scene lease rejection');}
$db->prepare("UPDATE scene_classifications SET classified_at=clock_timestamp()-interval '61 seconds' WHERE job_id=:job")->execute(['job'=>$sceneClaim['job_id']]);
$assert($sceneRepo->context($installationId,$session['playthrough_id'],$backfillProfile['profile_id'])['note']==='','scene note failed to expire');
$sceneAutomatic=$sceneTurn;$sceneAutomatic['payload']['ui_source']='lorkhan_rechat';
$assert($products->maybeEnqueueSceneClassification($sceneAutomatic)['reason']==='ineligible','Rechat incorrectly queued scene classification');
$sceneFallback=$sceneSettings;$sceneFallback['system_routing']['scene_classifier_configuration_id']='';$sceneFallback['system_routing']['background_memory_configuration_id']=$sceneProvider['configuration_id'];
$products->revise('global_settings',$backfillGlobal['configuration_id'],$sceneFallback,'scene fallback fixture',$now);
$assert($sceneRepo->route($installationId)['configuration_id']===$sceneProvider['configuration_id'],'scene background fallback missing');
$sceneFallback['task_availability']['background_memory']=false;
$products->revise('global_settings',$backfillGlobal['configuration_id'],$sceneFallback,'disable scene fallback fixture',$now);
$assert($sceneRepo->route($installationId)===null,'scene used disabled background fallback');
$namedScene=$products->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Scene Classifier (Gemma 3N E4B)','content'=>['driver'=>'mock','model'=>'named-scene']],$now);
$assert($sceneRepo->route($installationId)['configuration_id']===$namedScene['configuration_id'],'known classifier label fallback missing');
$sceneFallback['task_availability']['scene_classifier']=false;
$products->revise('global_settings',$backfillGlobal['configuration_id'],$sceneFallback,'disable named classifier',$now);
$assert($sceneRepo->route($installationId)===null,'known label bypassed classifier availability');
$products->deleteRevisioned('provider',$namedScene['configuration_id'],$now);
$products->revise('global_settings',$backfillGlobal['configuration_id'],$backfillSettings,'restore pre-scene settings',$now);

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
$spell=$captured;$spell['type']='spell_cast';$spell['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();
$spell['payload']=['caster'=>$captured['payload']['listener'],'spell_id'=>'spell_capture_sentinel','spell_name'=>'Spell Capture Sentinel',
    'game_time'=>12345.25,'target'=>$captured['payload']['speaker']];
$spellTurnCount=(int)$db->query('SELECT count(*) FROM turns')->fetchColumn();
[$status]=$call($router,'POST',$base.'/gamedata',$headers($spell['request_id']),[],$spell);
$assert($status===202&&(int)$db->query('SELECT count(*) FROM turns')->fetchColumn()===$spellTurnCount,'spell capture created a model turn or was rejected');
$spellNpc=$spell;$spellNpc['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();$spellNpc['payload']['caster']=$spell['payload']['target'];unset($spellNpc['payload']['target']);
[$status]=$call($router,'POST',$base.'/gamedata',$headers($spellNpc['request_id']),[],$spellNpc);
$assert($status===202,'NPC self spell capture rejected');
$spellWitness=$spell;$spellWitness['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();unset($spellWitness['payload']['target']);
$spellWitness['payload']['spell_name']='Witnessed Self Cast Sentinel';$spellWitness['payload']['audience']=[$spell['payload']['target']];
[$status]=$call($router,'POST',$base.'/gamedata',$headers($spellWitness['request_id']),[],$spellWitness);
$assert($status===202,'witnessed player self spell capture rejected');
$spellCandidates=array_column($products->contextFilterCandidates($installationId,'magic')['items'],'value');
$assert(in_array('Spell Capture Sentinel',$spellCandidates,true)&&in_array('spell_capture_sentinel',$spellCandidates,true)
    &&in_array('Witnessed Self Cast Sentinel',$spellCandidates,true),'magic chooser missed names/IDs available only in captured casts');


$spellProjection=$db->prepare("SELECT e.type,e.data,m.source_event_id,se.payload FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid JOIN source_events se ON se.source_event_id=m.source_event_id WHERE m.source_event_id IN (:player,:npc) ORDER BY e.type");
$spellProjection->execute(['player'=>$spell['request_id'],'npc'=>$spellNpc['request_id']]);$spellRows=$spellProjection->fetchAll();
$assert(array_column($spellRows,'type')===['npcspellcast','spellcast']
    &&str_contains($spellRows[1]['data'],' casts Spell Capture Sentinel toward ')
    &&!str_contains($spellRows[0]['data'],' toward ')
    &&json_decode($spellRows[1]['payload'],true,64,JSON_THROW_ON_ERROR)['payload']==$spell['payload'],
    'spell projection lost immutable input or claimed a hit/unknown target');
foreach([['game_time'=>-1],['game_time'=>INF],['spell_name'=>''],['spell_id'=>str_repeat('x',257)],['caster'=>array_replace($spell['payload']['caster'],['kind'=>'narrator'])],['target'=>null],['success'=>false],['audience'=>array_fill(0,13,$spell['payload']['caster'])],['audience'=>array_fill(0,2,$spell['payload']['caster'])]]as$invalid){
    $invalidSpell=$spell;$invalidSpell['payload']=array_replace($spell['payload'],$invalid);
    try{(new Validator())->validate($invalidSpell,'lorkhan.gamedata.v1');$assert(false,'malformed spell telemetry accepted');}
    catch(\LorkhanServer\Protocol\ValidationException){}
}
$pickup=$captured;$pickup['type']='item_pickup';$pickup['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();
$pickup['payload']=['player'=>$spell['payload']['caster'],'item_record_id'=>'valuable_pickup_sentinel','item_name'=>'Valuable Pickup Sentinel',
    'count'=>2,'unit_value'=>250,'game_time'=>12346.5,'source_kind'=>'container','source'=>['record_id'=>'chest_ref','display_name'=>'Wooden Chest'],
    'audience'=>[$spell['payload']['target']]];
$pickupTurns=(int)$db->query('SELECT count(*) FROM turns')->fetchColumn();
[$status]=$call($router,'POST',$base.'/gamedata',$headers($pickup['request_id']),[],$pickup);$assert($status===202,'container pickup rejected');
$lowPickup=$pickup;$lowPickup['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();$lowPickup['payload']['item_record_id']='low_pickup_sentinel';
$lowPickup['payload']['item_name']='Low Value Pickup Sentinel';$lowPickup['payload']['count']=1;$lowPickup['payload']['unit_value']=499;
$lowPickup['payload']['source_kind']='world';unset($lowPickup['payload']['source']);
[$status]=$call($router,'POST',$base.'/gamedata',$headers($lowPickup['request_id']),[],$lowPickup);$assert($status===202,'below-threshold pickup source rejected');
$sourceOnlyPickup=$pickup;$sourceOnlyPickup['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();$sourceOnlyPickup['payload']['item_name']='Unwitnessed Source Pickup Sentinel';
$sourceOnlyPickup['payload']['source_kind']='actor';$sourceOnlyPickup['payload']['audience']=[];
$sourceOnlyPickup['payload']['source']=['record_id'=>$spell['payload']['target']['record_id'],'display_name'=>$spell['payload']['target']['display_name']];
[$status]=$call($router,'POST',$base.'/gamedata',$headers($sourceOnlyPickup['request_id']),[],$sourceOnlyPickup);
$assert($status===202&&(int)$db->query('SELECT count(*) FROM turns')->fetchColumn()===$pickupTurns,'pickup source created a model turn');
$pickupCandidates=array_column($products->contextFilterCandidates($installationId,'items')['items'],'value');
$assert(in_array('Valuable Pickup Sentinel',$pickupCandidates,true)&&in_array('valuable_pickup_sentinel',$pickupCandidates,true),
    'item chooser missed a captured pickup absent from inventory turns');

$pickupProjection=$db->prepare("SELECT e.type,e.data,m.target,se.payload FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid JOIN source_events se ON se.source_event_id=m.source_event_id WHERE m.source_event_id=:id");
$pickupProjection->execute(['id'=>$pickup['request_id']]);$pickupRow=$pickupProjection->fetch();
$assert($pickupRow['type']==='itemfound'&&str_contains($pickupRow['data'],' picks up 2 Valuable Pickup Sentinel from Wooden Chest')
    &&json_decode($pickupRow['target'],true)===[]&&json_decode($pickupRow['payload'],true)['payload']==$pickup['payload'],'pickup projection lost source or promoted source description to target');
foreach([['count'=>0],['count'=>2147483648],['unit_value'=>-1],['unit_value'=>0.5],['game_time'=>INF],['item_name'=>''],['source_kind'=>'barter'],['calendar'=>null],['calendar'=>['year'=>427,'month'=>1,'day'=>30,'hour'=>12]],['source'=>['record_id'=>'x']],['audience'=>array_fill(0,2,$spell['payload']['target'])]]as$invalid){
    $badPickup=$pickup;$badPickup['payload']=array_replace($pickup['payload'],$invalid);
    try{(new Validator())->validate($badPickup,'lorkhan.gamedata.v1');$assert(false,'malformed pickup telemetry accepted');}catch(\LorkhanServer\Protocol\ValidationException){}
}
$turn = $fixture('turn');
$turn['session_id'] = $sessionId;
$resurrected=$spell;$resurrected['type']='actor_resurrected';$resurrected['request_id']=Uuid::v4();
$resurrected['payload']=['actor'=>$controlsQuery['target'],'audience'=>[$controlsQuery['target']],'game_time'=>1];
$resurrectionText=$resurrected['payload']['actor']['display_name'].' was resurrected.';
[$resurrectedStatus,$resurrectedBody]=$call($router,'POST',$base.'/gamedata',$headers($resurrected['request_id']),[],$resurrected);
$assert($resurrectedStatus===202,'resurrection telemetry rejected: '.json_encode($resurrectedBody));
$resurrectionProjection=$db->prepare("SELECT e.type,e.data FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid WHERE m.source_event_id=:source");
$resurrectionProjection->execute(['source'=>$resurrected['request_id']]);$resurrectionRow=$resurrectionProjection->fetch();
$assert($resurrectionRow&&$resurrectionRow['type']==='info'&&$resurrectionRow['data']===$resurrectionText,
    'resurrection did not project witnessed background info');
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
$assert(str_contains($promptHistoryJson,'Spell Capture Sentinel'),'captured spell did not reach the scoped NPC prompt');
$assert(str_contains($promptHistoryJson,$resurrectionText),'resurrection did not reach formatted NPC prompt');
$assert(str_contains($promptHistoryJson,'Valuable Pickup Sentinel')&&!str_contains($promptHistoryJson,'Low Value Pickup Sentinel')
    &&!str_contains($promptHistoryJson,'Unwitnessed Source Pickup Sentinel'),'pickup total-value threshold or witness scoping failed');

$assert(str_contains($promptHistoryJson,'Witnessed Self Cast Sentinel'),'witnessed player selfcast did not reach the NPC prompt');
$jobContextProbe=$turn;unset($jobContextProbe['generation']);
$assert(str_contains(json_encode($products->promptContext($jobContextProbe,$now)['history'],JSON_THROW_ON_ERROR),'Valuable Pickup Sentinel'),
    'job prompt scope without explicit generation did not use its recorded session generation');

$unrelatedSpellProbe=$turn;$unrelatedSpellProbe['payload']['target']['refnum']['index']+=100000;
$assert(!str_contains(json_encode($products->promptContext($unrelatedSpellProbe,$now)['history'],JSON_THROW_ON_ERROR),'Witnessed Self Cast Sentinel'),
    'selfcast leaked into an unrelated NPC prompt');

// Save rollback uses captured calendar globals, never the independent DaysPassed game_time clock.
$db->beginTransaction();
try{
    $dated=[];
    foreach(['spell'=>$spell,'pickup'=>$pickup,'resurrection'=>$resurrected]as$family=>$template){
        foreach(['past'=>11.5,'cutoff'=>12.0,'future'=>12.5,'undated'=>null]as$when=>$hour){
            $observation=$template;$observation['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();
            $observation['payload']['game_time']=1; // Deliberately contradicts calendar ordering.
            if($family==='resurrection')$observation['payload']['actor']['display_name']='Timeline '.$family.' '.$when;
            else{$nameField=$family==='spell'?'spell_name':'item_name';$observation['payload'][$nameField]='Timeline '.$family.' '.$when;}
            if($hour!==null)$observation['payload']['calendar']=['year'=>427,'month'=>7,'day'=>15,'hour'=>$hour];
            (new Validator())->validate($observation,'lorkhan.gamedata.v1');$repo->acceptGameData($observation);
            $dated[$family][$when]=$observation['request_id'];
        }
    }
    $pickupMemories=[];$pickupWriter=new \LorkhanServer\Infrastructure\FirstPartyJobRepository($db);
    foreach(['past','future']as$when){$memoryId=\LorkhanServer\Infrastructure\Uuid::v4();$pickupMemories[$when]=$memoryId;
        $pickupWriter->upsertMemory($memoryId,['installation_id'=>$installationId,'playthrough_id'=>$turn['playthrough_id'],
            'profile_id'=>$actorProfile['profile_id'],'tier'=>'recent','content'=>'Pickup memory '.$when,
            'source_event_id'=>$dated['pickup'][$when],'provenance'=>['source'=>'pickup-regression']],$now);}
    $load=$session;$load['message_id']=\LorkhanServer\Infrastructure\Uuid::v4();$load['generation']=8;
    $load['loaded_save']=['year'=>427,'month'=>7,'day'=>15,'hour'=>12.0];$loadSession=\LorkhanServer\Infrastructure\Uuid::v4();
    $repo->createSession($load,$loadSession,$tokenHash);
    $loadProbe=$turn;$loadProbe['session_id']=$loadSession;$loadProbe['generation']=8;
    $loadedHistory=json_encode($products->promptContext($loadProbe,$now)['history'],JSON_THROW_ON_ERROR);
    foreach($dated as$family=>$observations){
        foreach($observations as$when=>$source){
            $q=$db->prepare('SELECT EXISTS(SELECT 1 FROM timeline_invalidated_sources WHERE source_event_id=:source)');$q->execute(['source'=>$source]);
            $assert($q->fetchColumn()===($when!=='past'),'calendar rollback did not classify '.$family.' '.$when);
        }
        $assert(str_contains($loadedHistory,'Timeline '.$family.' past')&&!str_contains($loadedHistory,'Timeline '.$family.' future')
            &&!str_contains($loadedHistory,'Timeline '.$family.' undated'),'loaded history leaked abandoned '.$family.' observations');
    }
    foreach($pickupMemories as$when=>$memoryId){$q=$db->prepare('SELECT deleted_at IS NOT NULL FROM memory_records WHERE memory_id=:id');$q->execute(['id'=>$memoryId]);
        $assert($q->fetchColumn()===($when==='future'),'save rollback did not retire only future derived pickup memory');}
    $loadCandidates=array_column($products->contextFilterCandidates($installationId,'items')['items'],'value');
    $assert(in_array('Timeline pickup past',$loadCandidates,true)&&!in_array('Timeline pickup future',$loadCandidates,true),'chooser leaked future pickup after load');
    $q=$db->prepare('UPDATE eventlog_metadata SET suppressed_at=NULL,suppression_reason=NULL WHERE source_event_id=:source');$q->execute(['source'=>$dated['pickup']['future']]);
    $q=$db->prepare('SELECT suppressed_at IS NOT NULL FROM eventlog_metadata WHERE source_event_id=:source');$q->execute(['source'=>$dated['pickup']['future']]);
    $assert($q->fetchColumn()===true,'late projection revived an invalidated pickup');
    $unknownLoad=$load;$unknownLoad['message_id']=\LorkhanServer\Infrastructure\Uuid::v4();$unknownLoad['generation']=9;$unknownLoad['loaded_save']=null;
    $unknownSession=\LorkhanServer\Infrastructure\Uuid::v4();$repo->createSession($unknownLoad,$unknownSession,$tokenHash);
    $q=$db->prepare('SELECT EXISTS(SELECT 1 FROM timeline_invalidated_sources WHERE source_event_id=:source)');$q->execute(['source'=>$dated['pickup']['past']]);
    $assert($q->fetchColumn()===true,'unanchored load retained an older pickup as known-past');
}finally{$db->rollBack();}

$db->beginTransaction();
try{
    $spellCore=$products->getRevisioned('core_profile',$actorCoreProfile['core_profile_id']);$spellContent=$spellCore['content'];
    // More cheap pickups than the candidate cap must not evict eligible conversation before filtering.
    $db->exec('SAVEPOINT cheap_pickup_candidate_probe');
    $cheapRows=$db->prepare("WITH added AS (INSERT INTO eventlog(type,data,sess,gamets,localts,ts,people,location)
        SELECT e.type,e.data,e.sess,e.gamets,e.localts,(extract(epoch FROM clock_timestamp())*1000)::bigint+n,e.people,e.location
        FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid CROSS JOIN generate_series(1,501) n
        WHERE m.source_event_id=:source RETURNING rowid)
        INSERT INTO eventlog_metadata SELECT (jsonb_populate_record(NULL::eventlog_metadata,to_jsonb(m)||jsonb_build_object('rowid',added.rowid,'projection_key','cheap-fixture:'||added.rowid::text))).*
        FROM added CROSS JOIN eventlog_metadata m WHERE m.source_event_id=:source");
    $cheapRows->execute(['source'=>$lowPickup['request_id']]);
    $cheapHistory=json_encode($products->promptContext($turn,$now)['history'],JSON_THROW_ON_ERROR);
    $assert(str_contains($cheapHistory,'Ambient captured sentinel.')&&str_contains($cheapHistory,'Valuable Pickup Sentinel'),
        'below-threshold pickups evicted eligible conversation before candidate limit');
    $cheapFilter=$spellContent;$cheapFilter['settings_overrides']['context']['item_pickup_min_value']=0;
    $cheapFilter['settings_overrides']['context']['item_blacklist']=['LOW_PICKUP_SENTINEL'];
    $products->revise('core_profile',$actorCoreProfile['core_profile_id'],$cheapFilter,'blacklisted pickup candidate regression',$now);
    $cheapHistory=json_encode($products->promptContext($turn,$now)['history'],JSON_THROW_ON_ERROR);
    $assert(str_contains($cheapHistory,'Ambient captured sentinel.')&&str_contains($cheapHistory,'Valuable Pickup Sentinel'),
        'blacklisted pickups evicted eligible conversation before candidate limit');
    $db->exec('ROLLBACK TO SAVEPOINT cheap_pickup_candidate_probe');
    foreach([['detect_magic_events'=>false],['event_types'=>['chat']],['magic_effects_blacklist'=>['SPELL_CAPTURE_SENTINEL']],['magic_effects_blacklist'=>['spell capture sentinel']]]as$filter){
        $changed=$spellContent;$changed['settings_overrides']['context']=array_replace($changed['settings_overrides']['context']??[],$filter);
        $products->revise('core_profile',$actorCoreProfile['core_profile_id'],$changed,'spell context filter regression',$now);
        $history=json_encode($products->promptContext($turn,$now)['history'],JSON_THROW_ON_ERROR);
        $assert(!str_contains($history,'Spell Capture Sentinel'),'spell event bypassed disabled action category or magic blacklist');
    }
    foreach([['item_pickup_min_value'=>501],['event_types'=>['chat']],['item_blacklist'=>['VALUABLE_PICKUP_SENTINEL']],['item_blacklist'=>['valuable pickup sentinel']]]as$filter){
        $changed=$spellContent;$changed['settings_overrides']['context']=array_replace($changed['settings_overrides']['context']??[],$filter);
        $products->revise('core_profile',$actorCoreProfile['core_profile_id'],$changed,'pickup context filter regression',$now);
        $assert(!str_contains(json_encode($products->promptContext($turn,$now)['history'],JSON_THROW_ON_ERROR),'Valuable Pickup Sentinel'),'pickup bypassed profile threshold/category/item blacklist');
    }
    $changed=$spellContent;$changed['settings_overrides']['context']['item_pickup_min_value']=0;
    $products->revise('core_profile',$actorCoreProfile['core_profile_id'],$changed,'zero pickup threshold regression',$now);
    $assert(str_contains(json_encode($products->promptContext($turn,$now)['history'],JSON_THROW_ON_ERROR),'Low Value Pickup Sentinel'),'lower profile threshold could not recover retained pickup');
    $pickupProjection->execute(['id'=>$lowPickup['request_id']]);$assert($pickupProjection->fetch()!==false,'below-threshold immutable pickup was removed');
    $spellProjection->execute(['player'=>$spell['request_id'],'npc'=>$spellNpc['request_id']]);
    $assert(count($spellProjection->fetchAll())===2,'prompt blacklist deleted captured spell sources');
}finally{$db->rollBack();}

$assert(count($snapshot['message']['_allowed_action_definitions']??[])===17
    &&str_contains((string)($promptMessages[0]['content']??''),'`conversation.end()`')
    &&str_contains((string)($promptMessages[0]['content']??''),
        '`ai.follow(distance: 192)` — Follow: Ask one actor to follow the player at the exact negotiated distance.')
    &&!str_contains((string)($promptMessages[0]['content']??''),'"const":192'),
    'accepted turn did not freeze the server-negotiated catalog contract before prompt assembly');
// Direct Narrator input must not advertise actions that require a physical NPC executor.
$narratorOwnsTransaction=!$db->inTransaction();if($narratorOwnsTransaction)$db->beginTransaction();
$db->exec('SAVEPOINT narrator_action_scope_probe');
try{
    $narratorTurn=$turn;foreach(['message_id','request_id','turn_id']as$key)$narratorTurn[$key]=\LorkhanServer\Infrastructure\Uuid::v4();
    $narratorTurn['payload']['target']=array_replace($turn['payload']['target'],['kind'=>'narrator','record_id'=>'lorkhan:narrator','content_file'=>'LORKHAN','display_name'=>'The Narrator']);
    $narratorTurn['payload']['input']['text']='Describe this place.';
    [$status,$narratorAdmission]=$call($router,'POST',$base.'/turns',$headers($narratorTurn['message_id']),[],$narratorTurn);
    $assert($status===202,'direct Narrator input failed admission: '.json_encode($narratorAdmission));
    $q=$db->prepare('SELECT source_manifest FROM turn_provider_snapshots WHERE turn_id=:turn');$q->execute(['turn'=>$narratorTurn['turn_id']]);
    $manifest=json_decode($q->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
    $assert(($manifest['message']['_allowed_action_definitions']??null)===[],'direct Narrator received NPC-only action definitions');
}finally{$db->exec('ROLLBACK TO SAVEPOINT narrator_action_scope_probe');if($narratorOwnsTransaction)$db->rollBack();}

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
$gameDisposition=new \LorkhanServer\Infrastructure\GameDispositionRepository($db);
$dispositionObservation=['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id'],
    'session_id'=>$turn['session_id'],'generation'=>$session['generation'],'request_id'=>$delivery['message_id'],
    'observed_at'=>$now,'payload'=>['actor'=>$turn['payload']['target'],'player'=>$turn['payload']['speaker'],
        'base_disposition'=>45,'disposition'=>50,'dialogue_open'=>false]];
$gameDisposition->observe($dispositionObservation);
$db->prepare("UPDATE sessions SET capabilities=array_append(capabilities,'relationship.disposition') WHERE session_id=:id")
    ->execute(['id'=>$turn['session_id']]);
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
    &&(int)$relationshipReceipt['disposition_delta']===0&&(int)$relationshipReceipt['affinity_delta']===2,
    'played relationship worker did not persist one bounded result under the global policy');
$adjustment=$db->query('SELECT * FROM disposition_adjustments')->fetch();
$assert($adjustment&&(int)$adjustment['delta']===3&&$adjustment['status']==='pending',
    'AI disposition proposal was not clamped, durable and pending game confirmation');
$assert($gameDisposition->snapshot($dispositionObservation,$turn['payload']['target'],$turn['payload']['speaker'])['disposition']===50,
    'AI proposal changed game mirror before confirmation');
$db->exec('SAVEPOINT disposition_confirm');
$confirmed=$dispositionObservation;$confirmed['payload']+=['adjustment_id'=>$adjustment['adjustment_id'],'status'=>'applied'];
$confirmed['payload']['base_disposition']=63;$confirmed['payload']['disposition']=68;
$gameDisposition->observe($confirmed);
$assert($gameDisposition->snapshot($confirmed,$turn['payload']['target'],$turn['payload']['speaker'])['disposition']===68,
    'game readback failed to override stale server score after vanilla change');
$confirmed['payload']['disposition']=71;$gameDisposition->observe($confirmed);
$assert($gameDisposition->snapshot($confirmed,$turn['payload']['target'],$turn['payload']['speaker'])['disposition']===68,
    'duplicate disposition confirmation was applied twice');
$olderDisposition=$dispositionObservation;$olderDisposition['observed_at']='2000-01-01T00:00:00Z';
$olderDisposition['payload']['disposition']=1;$gameDisposition->observe($olderDisposition);
$assert($gameDisposition->snapshot($confirmed,$turn['payload']['target'],$turn['payload']['speaker'])['disposition']===68,
    'out-of-order snapshot rolled back a newer game reading');
$wrongDispositionActor=$turn['payload']['target'];$wrongDispositionActor['refnum']['index']+=1;
$assert($gameDisposition->snapshot($confirmed,$wrongDispositionActor,$turn['payload']['speaker'])===null,
    'game disposition crossed exact actor identity');
$db->exec('ROLLBACK TO SAVEPOINT disposition_confirm');
$assert($products->relationships(['installation_id'=>$installationId,'profile_id'=>$actorProfile['profile_id'],
    'playthrough_id'=>$session['playthrough_id']])[0]['relationship_type']==='neutral',
    'low-affinity romantic proposal changed type or blocked safe score deltas');
$assert($relationships->enqueue($delivery['message_id'])['job_id']===$relationshipJob['job_id']
    &&$relationshipWorker()['claimed']===0,'duplicate delivery reapplied relationship evaluation');
$assert((int)$db->query("SELECT config_revision FROM provider_attempts WHERE operation='evaluate_relationship'")->fetchColumn()===1,
    'queued relationship job did not keep its frozen provider revision');
$db->exec('DELETE FROM game_dispositions');
// Exercise actual automatic provenance and loaded-save restoration without changing the surrounding worker fixtures.
$db->exec('SAVEPOINT relationship_timeline');
$db->prepare("UPDATE profiles SET actor_identity=jsonb_set(actor_identity,'{kind}','\"npc\"'::jsonb) WHERE profile_id=:id")
    ->execute(['id'=>$actorProfile['profile_id']]);
$relationOwner=$products->getRevisioned('profile',$actorProfile['profile_id']);
$relationOwnerContent=$relationOwner['content'];$relationOwnerContent['management']['locked']=false;
$products->revise('profile',$actorProfile['profile_id'],$relationOwnerContent,'unlocked relationship timeline fixture',$now);
$relationshipTimeline=new \LorkhanServer\Infrastructure\LoadedSaveTimeline($db);
$relationScope=['installation'=>$installationId,'playthrough'=>$session['playthrough_id']];
$relationId=$relationshipReceipt['relationship_id'];
$relationRead=$db->prepare('SELECT * FROM relationship_records WHERE relationship_id=:id');
$relationRead->execute(['id'=>$relationId]);$relationBefore=$relationRead->fetch();
$relationHistory=$db->prepare('SELECT provenance FROM relationship_revisions WHERE relationship_id=:id AND revision=:revision');
$relationHistory->execute(['id'=>$relationId,'revision'=>$relationBefore['revision']]);
$relationOrigin=json_decode($relationHistory->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
$assert($relationOrigin['kind']==='automatic_relationship'&&$relationOrigin['job_id']===$relationshipJob['job_id']
    &&$relationOrigin['source_turn_ids']===[$turn['turn_id']]&&$relationOrigin['base_revision']===0,
    'automatic relationship did not retain its actual leased job and source ancestry');
$db->exec('SAVEPOINT relationship_expired_provenance');
try{
    $products->setRelationship(['installation_id'=>$installationId,'profile_id'=>$actorProfile['profile_id'],
        'playthrough_id'=>$session['playthrough_id'],'relationship_id'=>$relationId,'expected_revision'=>(int)$relationBefore['revision'],
        'source_event_id'=>$delivery['message_id'],'disposition'=>60,'affinity'=>60,'source_mode'=>'derived',
        '_relationship_job'=>['job_id'=>$relationshipJob['job_id'],'attempt'=>1,'lease_token'=>\LorkhanServer\Infrastructure\Uuid::v4()]],$now);
    $assert(false,'expired relationship job manufactured automatic ancestry');
}catch(RuntimeException $error){$assert($error->getMessage()==='relationship_provenance_lease_lost','wrong automatic provenance lease failure');}
$db->exec('ROLLBACK TO SAVEPOINT relationship_expired_provenance');
$relationLoadQuery=$db->prepare("SELECT e.source_event_id FROM source_events e JOIN sessions s ON s.session_id=e.session_id
    WHERE e.installation_id=:installation AND s.playthrough_id=:playthrough AND e.event_kind='session.init' LIMIT 1");
$relationLoadQuery->execute($relationScope);
$relationLoad=['message_id'=>$relationLoadQuery->fetchColumn(),'installation_id'=>$installationId,
    'playthrough_id'=>$session['playthrough_id'],'loaded_save'=>['year'=>427,'month'=>7,'day'=>15,'hour'=>12]];
$relationDate=$db->prepare("UPDATE turns SET context=jsonb_set(context,'{world}',COALESCE(context->'world','{}'::jsonb)
    ||jsonb_build_object('calendar',CAST(:calendar AS jsonb))) WHERE turn_id=:id");
$relationDate->execute(['id'=>$turn['turn_id'],'calendar'=>'{"year":427,"month":7,"day":16,"hour":12}']);
$db->exec('SAVEPOINT relationship_timeline_seed');
$retentionGlobal=$products->globalSettingsForInstallation($installationId);
$retentionContent=$retentionGlobal['content'];
$retentionContent['relationship']['never_clear_relationship_data']=true;
$products->revise('global_settings',$retentionGlobal['configuration_id'],$retentionContent,'relationship retention fixture',$now);
$assert($relationshipTimeline->invalidate($relationLoad)['relationships']===0,'never-clear setting rolled back relationship data');
$relationRead->execute(['id'=>$relationId]);
$assert($relationRead->fetch()['deleted_at']===null,'never-clear setting retired automatic relationship');
$products->deleteRelationship($relationId,$now,(int)$relationBefore['revision']);
$relationRead->execute(['id'=>$relationId]);
$assert($relationRead->fetch()['deleted_at']!==null,'never-clear setting blocked explicit manual deletion');
$db->exec('ROLLBACK TO SAVEPOINT relationship_timeline_seed');
$relationCounts=$relationshipTimeline->invalidate($relationLoad);
$relationRead->execute(['id'=>$relationId]);$relationRemoved=$relationRead->fetch();
$assert($relationCounts['relationships']===1&&$relationRemoved['deleted_at']!==null
    &&(int)$relationRemoved['revision']===(int)$relationBefore['revision']+1,
    'loaded save retained an automatic-only relationship or decreased its revision: '.json_encode([$relationCounts,$relationRemoved['revision'],$relationBefore['revision'],$relationRemoved['deleted_at'],$products->getRevisioned('profile',$actorProfile['profile_id'])['actor_identity']]));
$assert($relationshipTimeline->invalidate($relationLoad)['relationships']===0,'repeated load reapplied relationship retirement');
$db->exec('ROLLBACK TO SAVEPOINT relationship_timeline_seed');
// Only player-owned worst memories fade, measured in game days from the text change.
$db->exec('SAVEPOINT relationship_worst_lifespan');
$db->prepare("UPDATE profiles SET actor_identity=jsonb_set(actor_identity,'{kind}','\"player\"'::jsonb) WHERE profile_id=:id")
    ->execute(['id'=>$actorProfile['profile_id']]);
$db->prepare("UPDATE relationship_records SET actor_identity=CAST(:identity AS jsonb) WHERE relationship_id=:id")
    ->execute(['id'=>$relationId,'identity'=>json_encode($turn['payload']['target'])]);
$relationRead->execute(['id'=>$relationId]);$worstBase=$relationRead->fetch();
$worstInput=['installation_id'=>$installationId,'profile_id'=>$actorProfile['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'relationship_id'=>$relationId,'expected_revision'=>(int)$worstBase['revision'],'disposition'=>21,'affinity'=>19,
    'details'=>['worst'=>'An insult','best'=>'A gift'],'source_mode'=>'manual'];
$worstSaved=$products->setRelationship($worstInput,$now);
$worstScope=array_intersect_key($worstInput,array_flip(['installation_id','profile_id','playthrough_id']));
$worstRead=static function()use($products,$worstScope,$relationId):array{
    foreach($products->relationships($worstScope)as$row)if($row['relationship_id']===$relationId)return$row;
    throw new RuntimeException('worst memory fixture missing');
};
$assert($worstRead()['details']['worst']==='An insult','fresh player worst memory expired');
$relationDate->execute(['id'=>$turn['turn_id'],'calendar'=>'{"year":427,"month":7,"day":22,"hour":12}']);
$assert($worstRead()['details']['worst']==='An insult','player worst memory expired before seven game days');
$relationDate->execute(['id'=>$turn['turn_id'],'calendar'=>'{"year":427,"month":7,"day":23,"hour":12}']);
$assert($worstRead()['details']['worst']===''&&$worstRead()['details']['best']==='A gift','worst lifespan did not expire only worst text');
$worstInput['expected_revision']=$worstSaved['revision'];$worstInput['affinity']=20;
$products->setRelationship($worstInput,$now);
$assert($worstRead()['details']['worst']==='','unrelated relationship update renewed worst memory age');
$retentionContent=$retentionGlobal['content'];$retentionContent['relationship']['worst_memory_lifespan_days']=0;
$products->revise('global_settings',$retentionGlobal['configuration_id'],$retentionContent,'worst memory never forget fixture',$now);
$assert($worstRead()['details']['worst']==='An insult','zero lifespan did not retain worst memory');
$retentionContent['relationship']['worst_memory_lifespan_days']=7;
$products->revise('global_settings',$retentionGlobal['configuration_id'],$retentionContent,'worst memory lifespan fixture',$now);
$relationDate->execute(['id'=>$turn['turn_id'],'calendar'=>'{"year":427,"month":7,"day":15,"hour":12}']);
$assert($worstRead()['details']['worst']==='An insult','loading an earlier game date expired a future worst memory');
$relationDate->execute(['id'=>$turn['turn_id'],'calendar'=>'{"year":427,"month":7,"day":23,"hour":12}']);
$db->prepare("UPDATE profiles SET actor_identity=jsonb_set(actor_identity,'{kind}','\"npc\"'::jsonb) WHERE profile_id=:id")
    ->execute(['id'=>$actorProfile['profile_id']]);
$assert($worstRead()['details']['worst']==='An insult','NPC to NPC worst memory expired');
$db->exec('ROLLBACK TO SAVEPOINT relationship_worst_lifespan');
$relationManual=$products->setRelationship(['installation_id'=>$installationId,'profile_id'=>$actorProfile['profile_id'],
    'playthrough_id'=>$session['playthrough_id'],'relationship_id'=>$relationId,'expected_revision'=>(int)$relationBefore['revision'],
    'disposition'=>21,'affinity'=>19,'custom_info'=>'Keep my manual canon','details'=>['note'=>'Manual relationship'],
    'source_mode'=>'manual'],$now);
$db->exec('SAVEPOINT relationship_manual_boundary');
$assert($relationshipTimeline->invalidate($relationLoad)['relationships']===0,'loaded save replaced a manual relationship edit');
$relationRead->execute(['id'=>$relationId]);$relationManualState=$relationRead->fetch();
$assert($relationManualState['custom_info']==='Keep my manual canon'&&(int)$relationManualState['disposition']===21,
    'manual relationship canon was lost');
$db->exec('ROLLBACK TO SAVEPOINT relationship_manual_boundary');
// A historical automatic chain crosses two game dates but must stop at the manual baseline.
$relationEarlierTurn=\LorkhanServer\Infrastructure\Uuid::v4();
$relationClone=$db->prepare("INSERT INTO turns(turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,
    speaker,target,audience,context,state,accepted_at)
    SELECT :id,:request,:message,session_id,generation,input_kind,input_language,input_text,speaker,target,audience,context,'complete',accepted_at
    FROM turns WHERE turn_id=:source");
$relationClone->execute(['id'=>$relationEarlierTurn,'request'=>\LorkhanServer\Infrastructure\Uuid::v4(),
    'message'=>\LorkhanServer\Infrastructure\Uuid::v4(),'source'=>$turn['turn_id']]);
$relationDate->execute(['id'=>$relationEarlierTurn,'calendar'=>'{"year":427,"month":7,"day":13,"hour":12}']);
$relationRevision=$relationManual['revision'];
foreach([[$relationEarlierTurn,31],[$turn['turn_id'],41]]as[$sourceTurn,$score]){
    $next=$products->setRelationship(['installation_id'=>$installationId,'profile_id'=>$actorProfile['profile_id'],
        'playthrough_id'=>$session['playthrough_id'],'relationship_id'=>$relationId,'expected_revision'=>$relationRevision,
        'disposition'=>$score,'affinity'=>$score,'source_mode'=>'derived'],$now);
    $fixtureProvenance=$relationOrigin;$fixtureProvenance['base_revision']=$relationRevision;$fixtureProvenance['source_turn_ids']=[$sourceTurn];
    $db->prepare('UPDATE relationship_revisions SET provenance=CAST(:provenance AS jsonb) WHERE relationship_id=:id AND revision=:revision')
        ->execute(['id'=>$relationId,'revision'=>$next['revision'],'provenance'=>json_encode($fixtureProvenance)]);
    $relationRevision=$next['revision'];
}
$db->exec('SAVEPOINT relationship_chain');
foreach(['creature','player','narrator','other_playthrough','unknown']as$relationBoundary){
    $db->exec('SAVEPOINT relationship_boundary');
    if(in_array($relationBoundary,['creature','player','narrator'],true)){
        $db->prepare("UPDATE profiles SET actor_identity=jsonb_set(actor_identity,'{kind}',CAST(:kind AS jsonb)) WHERE profile_id=:id")
            ->execute(['id'=>$actorProfile['profile_id'],'kind'=>json_encode($relationBoundary)]);
    }else{
        $boundaryProvenance=$fixtureProvenance;
        if($relationBoundary==='unknown')$boundaryProvenance=[];
        else $boundaryProvenance['playthrough_id']=\LorkhanServer\Infrastructure\Uuid::v4();
        $db->prepare('UPDATE relationship_revisions SET provenance=CAST(:provenance AS jsonb) WHERE relationship_id=:id AND revision=:revision')
            ->execute(['id'=>$relationId,'revision'=>$relationRevision,'provenance'=>json_encode((object)$boundaryProvenance)]);
    }
    $assert($relationshipTimeline->invalidate($relationLoad)['relationships']===($relationBoundary==='creature'?1:0),
        'relationship restoration crossed its '.$relationBoundary.' boundary');
    $db->exec('ROLLBACK TO SAVEPOINT relationship_boundary');
}
$assert($relationshipTimeline->invalidate($relationLoad)['relationships']===1,'automatic relationship chain was not restored');
$relationRead->execute(['id'=>$relationId]);$restoredRelation=$relationRead->fetch();
$assert((int)$restoredRelation['disposition']===31&&(int)$restoredRelation['revision']===$relationRevision+1
    &&$restoredRelation['custom_info']==='Keep my manual canon','relationship baseline, custom information or monotonic revision failed');
$relationProjection=$db->prepare("SELECT npc.extended_data->'relationships' FROM npc_metadata metadata
    JOIN public.core_npc_master npc ON npc.id=metadata.npc_id WHERE metadata.source_profile_id=:profile");
$relationProjection->execute(['profile'=>$actorProfile['profile_id']]);
$relationProjected=json_decode($relationProjection->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
$assert((int)($relationProjected[$turn['payload']['speaker']['record_id']]['disposition']??-999)===31,
    'restored relationship did not reach the public NPC projection');
$assert($relationshipTimeline->invalidate($relationLoad)['relationships']===0,'identical load appended another relationship revision');
$relationEarlierLoad=$relationLoad;$relationEarlierLoad['loaded_save']['day']=12;
$assert($relationshipTimeline->invalidate($relationEarlierLoad)['relationships']===1,'earlier load did not follow restored relationship ancestry');
$relationRead->execute(['id'=>$relationId]);$restoredRelation=$relationRead->fetch();
$assert((int)$restoredRelation['disposition']===21&&(int)$restoredRelation['revision']===$relationRevision+2
    &&$restoredRelation['custom_info']==='Keep my manual canon','earlier load did not stop at manual baseline');
$db->exec('ROLLBACK TO SAVEPOINT relationship_chain');
$relationLocked=$products->getRevisioned('profile',$actorProfile['profile_id']);
$relationLockedContent=$relationLocked['content'];$relationLockedContent['relationship']['locked']=true;
$products->revise('profile',$actorProfile['profile_id'],$relationLockedContent,'relationship lock fixture',$now);
$assert($relationshipTimeline->invalidate($relationLoad)['relationships']===0,'relationship lock did not prevent loaded-save restoration');
$db->exec('ROLLBACK TO SAVEPOINT relationship_chain');
$products->deleteRelationship($relationId,$now,$relationRevision);
$assert($relationshipTimeline->invalidate($relationLoad)['relationships']===0,'loaded save resurrected a manual relationship deletion');
$db->exec('ROLLBACK TO SAVEPOINT relationship_timeline');
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
    &&count(array_filter($selectedRows,static fn(array$row):bool=>(int)$row['disposition']===(($row['actor_identity']['kind']??'')==='player'?0:40)&&(int)$row['affinity']===50))===2
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
$db->exec('SAVEPOINT narrator_portability_probe');
$portableNarrator=new ReflectionMethod($previewManager,'portableSpecialProfileSettings');
$importNarrator=new ReflectionMethod($previewManager,'importSpecialProfileSettings');
$narratorPortable=$portableNarrator->invoke($previewManager,['dynamic_profile'=>true,'dynamic_profile_fields'=>['goals']],'narrator');
$assert($narratorPortable['dynamic_profile']===true&&$narratorPortable['dynamic_profile_fields']===['goals'],'Narration export omitted dynamic profile selection');
$narratorDocument=['schema'=>'lorkhan.narrator-profile-settings.v2','exported_at'=>$now,'settings'=>['dynamic_profile'=>true,'dynamic_profile_fields'=>['goals']]];
$importNarrator->invoke($previewManager,['preset_json'=>json_encode($narratorDocument)],['installation_id'=>$installationId],'narrator');
$storedNarrator=$products->narratorProfileForInstallation($installationId);
$assert($storedNarrator['content']['dynamic_profile']===true&&$storedNarrator['content']['dynamic_profile_fields']===['goals'],'Narration import lost dynamic settings');
$narratorDocument['settings']=['goals'=>'Older preset does not change evolution selection'];
$importNarrator->invoke($previewManager,['preset_json'=>json_encode($narratorDocument)],['installation_id'=>$installationId],'narrator');
$assert($products->narratorProfileForInstallation($installationId)['content']['dynamic_profile_fields']===['goals'],'partial Narration import reset omitted selection');
foreach([['dynamic_profile'=>'true'],['dynamic_profile_fields'=>['voice']],['dynamic_profile_fields'=>['goals','goals']],['dynamic_profile'=>true,'dynamic_profile_fields'=>[]]]as$badSettings){
    $narratorDocument['settings']=$badSettings;
    try{$importNarrator->invoke($previewManager,['preset_json'=>json_encode($narratorDocument)],['installation_id'=>$installationId],'narrator');$assert(false,'invalid Narration evolution preset accepted');}
    catch(InvalidArgumentException){$assert(true,'invalid Narration evolution preset rejected');}
}
$beforePortableName=$products->getRevisioned('profile',$narratorProfile['profile_id']);
$narratorDocument['settings']=['roleplay_name'=>'Portable Storyteller','diary_enabled'=>true,'auto_diary_enabled'=>false];
$importNarrator->invoke($previewManager,['preset_json'=>json_encode($narratorDocument)],['installation_id'=>$installationId],'narrator');
$afterPortableName=$products->getRevisioned('profile',$narratorProfile['profile_id']);
$assert($afterPortableName['name']==='Portable Storyteller'&&$afterPortableName['content']['diary']['enabled']===true
    &&$afterPortableName['content']['diary']['automatic_enabled']===false,'Narration import lost display name or diary controls');
foreach(['beforePortableName','afterPortableName']as$record)if(is_string(${$record}['actor_identity']))${$record}['actor_identity']=json_decode(${$record}['actor_identity'],true,32,JSON_THROW_ON_ERROR);
$assert($afterPortableName['profile_id']===$beforePortableName['profile_id']&&$afterPortableName['core_profile_id']===$beforePortableName['core_profile_id']
    &&array_diff_key($afterPortableName['actor_identity'],['display_name'=>true])===array_diff_key($beforePortableName['actor_identity'],['display_name'=>true]),
    'Narration display name import changed internal identity or Core assignment');
$portableNamed=$portableNarrator->invoke($previewManager,$afterPortableName['content'],'narrator',$afterPortableName['name']);
$assert($portableNamed['roleplay_name']==='Portable Storyteller'&&$portableNamed['diary_enabled']===true&&$portableNamed['auto_diary_enabled']===false,'Narration re-export lost portable name or diary state');
$narratorDocument['settings']=['dynamic_profile'=>false];
$narratorDocument['prompts']=['narrator_welcome_prompt'=>'Welcome custom narration.'];
$importNarrator->invoke($previewManager,['preset_json'=>json_encode($narratorDocument)],['installation_id'=>$installationId],'narrator');
$assert($products->narratorEventPromptTexts($installationId)['narrator_welcome_prompt']==='Welcome custom narration.','Narration prompt import did not persist');
$exportNarrator=new ReflectionMethod($previewManager,'exportSpecialProfileSettings');
$exportedNarrator=json_decode($exportNarrator->invoke($previewManager,$narratorProfile['profile_id'],'narrator')->body,true,32,JSON_THROW_ON_ERROR);
$assert($exportedNarrator['prompts']['narrator_welcome_prompt']==='Welcome custom narration.','Narration export omitted custom prompt');
$narratorDocument['prompts']=['narrator_welcome_prompt'=>''];
$importNarrator->invoke($previewManager,['preset_json'=>json_encode($narratorDocument)],['installation_id'=>$installationId],'narrator');
$assert(!isset($products->narratorEventPromptTexts($installationId)['narrator_welcome_prompt']),'empty Narration import did not restore default prompt');
$beforeNarratorGeneration=$products->getRevisioned('profile',$narratorProfile['profile_id']);
$narratorGenerationJob=$products->enqueueNarratorProfileGeneration($narratorProfile['profile_id']);
$narratorGenerationQuery=$db->prepare('SELECT payload FROM durable_jobs WHERE job_id=:job');
$narratorGenerationQuery->execute(['job'=>$narratorGenerationJob['job_id']]);
$narratorGenerationPayload=json_decode($narratorGenerationQuery->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
$narratorGenerationPayload['_job']=['job_id'=>$narratorGenerationJob['job_id'],'attempt'=>1];
$leaseProfileFixture->execute(['job'=>$narratorGenerationJob['job_id'],'token'=>\LorkhanServer\Infrastructure\Uuid::v4()]);
$narratorGenerator=new \LorkhanServer\Application\ProfileGenerateJobHandler($products,new \LorkhanServer\Application\MockProfileGenerationProvider());
$narratorGenerator->handle($narratorGenerationPayload,'narrator-composition-probe',static fn():bool=>true);
$generatedNarrator=$products->getRevisioned('profile',$narratorProfile['profile_id']);
$assert($generatedNarrator['current_revision']===$beforeNarratorGeneration['current_revision']+1
    &&$generatedNarrator['content']['biography']!==($beforeNarratorGeneration['content']['biography']??''),
    'queued Narrator generation did not apply a persona revision');
foreach(['enabled','voice','routing','diary','dynamic_profile','dynamic_profile_fields','inline_narration_mode','only_diary_access','prompt_head']as$field)
    $assert(($generatedNarrator['content'][$field]??null)===($beforeNarratorGeneration['content'][$field]??null),
        'Narrator generation changed unrelated setting '.$field);
$assert($generatedNarrator['actor_identity']===$beforeNarratorGeneration['actor_identity']
    &&$generatedNarrator['core_profile_id']===$beforeNarratorGeneration['core_profile_id'],
    'Narrator generation changed identity or Core assignment');
$narratorGenerator->handle($narratorGenerationPayload,'narrator-composition-probe',static fn():bool=>true);
$assert($products->getRevisioned('profile',$narratorProfile['profile_id'])['current_revision']===$generatedNarrator['current_revision'],
    'replayed stale Narrator generation created another revision');
$db->exec('ROLLBACK TO SAVEPOINT narrator_portability_probe');
$db->exec('SAVEPOINT digest_witness_probe');
$db->prepare('UPDATE profiles SET actor_identity=CAST(:identity AS jsonb) WHERE profile_id=:profile')->execute(['identity'=>json_encode($memoryProbe['payload']['target'],JSON_THROW_ON_ERROR),'profile'=>$actorProfile['profile_id']]);
$db->prepare("UPDATE memory_records SET derivation_key='digest-witness-fixture',provenance=provenance||'{\"provider\":\"first-party\",\"model\":\"deterministic-extractive-v1\"}'::jsonb WHERE memory_id=:id")
    ->execute(['id'=>$mixedMemory['memory_id']]);
$digestBystander=$memoryService->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Digest bystander',
    'actor_identity'=>$bystanderProbe['payload']['target'],'content'=>['biography'=>'Unwitnessing NPC.']]);
$digestSeen=$products->memoryDigestCandidates($installationId,$turn['playthrough_id'],$actorProfile['profile_id'],$memoryNow);
$digestUnseen=$products->memoryDigestCandidates($installationId,$turn['playthrough_id'],$digestBystander['profile_id'],$memoryNow);
$assert(in_array($mixedMemory['memory_id'],array_column($digestSeen,'id'),true)
    &&!in_array($mixedMemory['memory_id'],array_column($digestUnseen,'id'),true),'digest candidate selection must reuse all-source witness filtering');
$digestConnector=$memoryService->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Digest fixture mock','content'=>['driver'=>'mock','model'=>'digest-fixture']]);
$digestGlobal=$products->globalSettingsForInstallation($installationId);$digestGlobalContent=$digestGlobal['content'];
$digestGlobalContent['system_routing']['background_memory_configuration_id']=$digestConnector['configuration_id'];
$digestGlobalContent['task_availability']['background_memory']=true;
$memoryService->revise('global_settings',$digestGlobal['configuration_id'],$digestGlobalContent,'digest fixture route');
for($digestIndex=1;$digestIndex<=4;$digestIndex++){
    $digestMemory=$memoryService->createMemory(['installation_id'=>$installationId,'profile_id'=>$turn['profile_id'],'playthrough_id'=>$turn['playthrough_id'],
        'tier'=>'mid','content'=>'First digest scene '.$digestIndex,'provenance'=>['source'=>'memory.consolidate','provider'=>'first-party','model'=>'deterministic-extractive-v1','source_event_ids'=>[$sharedSource,$delivery['message_id']]]]);
    $db->prepare('UPDATE memory_records SET derivation_key=:key WHERE memory_id=:id')->execute(['key'=>'digest-fixture-'.$digestIndex,'id'=>$digestMemory['memory_id']]);
}
$digests=new \LorkhanServer\Infrastructure\NpcMemoryDigestRepository($db);$digestJobs=new JobRepository($db);
(new \LorkhanServer\Infrastructure\FirstPartyJobRepository($db))->enqueueMemoryConsolidation($mixedMemory['memory_id'],$mixedMemory);
$digestScanRegistry=new \LorkhanServer\Application\JobHandlerRegistry([new \LorkhanServer\Application\MemoryDigestScanJobHandler($digests)]);
$digestScanStats=(new Worker($digestJobs,$digestScanRegistry,'digest-scan-fixture',30,1,1,0,60,['memory.digest.scan']))->run();
$assert($digestScanStats['succeeded']===1,'scene consolidation did not schedule a digest eligibility scan');
$digestQueued=$digests->enqueue($installationId,$turn['playthrough_id'],$actorProfile['profile_id']);
$assert($digestQueued!==null&&$digests->enqueue($installationId,$turn['playthrough_id'],$actorProfile['profile_id'])['job_id']===$digestQueued['job_id'],'digest queue must deduplicate pending NPC batches');
$digestRegistry=new \LorkhanServer\Application\JobHandlerRegistry([new \LorkhanServer\Application\MemoryDigestJobHandler($digests,$products,new ProviderAttemptRepository($db))]);
$digestStats=(new Worker($digestJobs,$digestRegistry,'digest-fixture',30,1,1,0,60,['memory.digest']))->run();
$firstDigest=$digests->latest($installationId,$turn['playthrough_id'],$actorProfile['profile_id']);
$assert($digestStats['succeeded']===1&&$firstDigest['revision']===1&&str_contains($firstDigest['content'],'First digest scene'),'first digest worker did not save its witnessed source batch');
$assert($digests->enqueue($installationId,$turn['playthrough_id'],$actorProfile['profile_id'])===null,'digest worker requeued without new scenes');
for($digestIndex=5;$digestIndex<=14;$digestIndex++){
    $digestMemory=$memoryService->createMemory(['installation_id'=>$installationId,'profile_id'=>$turn['profile_id'],'playthrough_id'=>$turn['playthrough_id'],
        'tier'=>'mid','content'=>'Second digest scene '.$digestIndex,'provenance'=>['source'=>'memory.consolidate','provider'=>'first-party','model'=>'deterministic-extractive-v1','source_event_ids'=>[$sharedSource,$delivery['message_id']]]]);
    $db->prepare("UPDATE memory_records SET derivation_key=:key,occurred_at=CAST(:now AS timestamptz)+interval '1 minute' WHERE memory_id=:id")->execute(['key'=>'digest-fixture-'.$digestIndex,'id'=>$digestMemory['memory_id'],'now'=>$memoryNow]);
}
$secondDigestJob=$digests->enqueue($installationId,$turn['playthrough_id'],$actorProfile['profile_id']);
$secondDigestStats=(new Worker($digestJobs,$digestRegistry,'digest-fixture-next',30,1,1,0,60,['memory.digest']))->run();
$secondDigest=$digests->latest($installationId,$turn['playthrough_id'],$actorProfile['profile_id']);
$assert($secondDigestStats['succeeded']===1&&$secondDigest['revision']===2&&str_contains($secondDigest['content'],'First digest scene')&&str_contains($secondDigest['content'],'Second digest scene'),'second digest lost previous canon or newer scenes');
$digestSelection=$products->promptContext($memoryProbe,$memoryNow);
$digestPromptTurn=$memoryProbe;$digestPromptTurn['_selected_profile_id']=$actorProfile['profile_id'];
$digestPrompt=(new PromptAssembler())->assemble($digestPromptTurn,$digestSelection);
$digestTraceRows=array_values(array_filter($digestPrompt['trace']['sources'],static fn(array $row):bool=>$row['source_kind']==='memory_digest'));
$assert(str_contains($digestPrompt['provider_input']['_assembled_prompt'],'First digest scene')&&count($digestTraceRows)===1
    &&$digestTraceRows[0]['source_table']==='npc_memory_digests'&&$digestTraceRows[0]['included'],'saved NPC digest did not reach its distinct traced prompt fragment');
$digestDisabled=$digestSelection;$digestDisabled['effective_settings']['settings']['memory']['mid_term_enabled']=false;
$assert(!str_contains((new PromptAssembler())->assemble($digestPromptTurn,$digestDisabled)['provider_input']['_assembled_prompt'],$secondDigest['content']),
    'disabled Middle Term Memory still injected its saved digest');
$db->exec('SAVEPOINT digest_edit_probe');
$digestScope=[$installationId,$turn['playthrough_id'],$actorProfile['profile_id']];
$editor=$digests->editor(...$digestScope);
$digests->edit(...[...$digestScope,$editor['revision'],'Manually corrected canon']);
$assert($digests->editor(...$digestScope)['content']==='Manually corrected canon','digest manual edit was not saved');
try{$digests->edit(...[...$digestScope,$editor['revision'],'Stale draft']);$assert(false,'stale digest draft overwrote newer canon');}
catch(RuntimeException $error){$assert($error->getMessage()==='revision_conflict','unexpected digest conflict error');}
$digests->edit(...[...$digestScope,$editor['revision']+1,'']);
$assert($digests->editor(...$digestScope)['content']===$firstDigest['content'],'clearing edited latest digest failed to reveal previous logical entry');
$digests->edit(...[...$digestScope,$editor['revision']+2,'']);
$assert($digests->latest(...$digestScope)===null,'clearing last digest did not leave an empty editor');
$digests->edit(...[...$digestScope,$editor['revision']+3,'Seeded canon']);
$assert($digests->editor(...$digestScope)['content']==='Seeded canon','manual initial canon was not saved');
$digestBeforeProfile=$products->getRevisioned('profile',$actorProfile['profile_id']);
$digestForm=['profile_id'=>$actorProfile['profile_id'],'base_content_json'=>json_encode($digestBeforeProfile['content']),
    'change_reason'=>'memory editor test','voice_id'=>'','notes'=>'Save All marker','npc_memory_edits'=>[$turn['playthrough_id']=>['revision'=>(string)($editor['revision']+4),'content'=>'Save All canon']]];
$previewSave->invoke($previewManager,$digestForm);
$assert($products->getRevisioned('profile',$actorProfile['profile_id'])['content']['notes']==='Save All marker'
    &&$digests->editor(...$digestScope)['content']==='Save All canon','NPC Save All did not save both profile and digest');
$digestSavedProfile=$products->getRevisioned('profile',$actorProfile['profile_id']);
try{$previewSave->invoke($previewManager,array_replace($digestForm,['notes'=>'Stale profile marker']));$assert(false,'NPC Save All accepted stale digest');}
catch(RuntimeException $error){$assert($error->getMessage()==='revision_conflict','unexpected Save All conflict');}
$assert($products->getRevisioned('profile',$actorProfile['profile_id'])['current_revision']===$digestSavedProfile['current_revision'],'stale memory draft partially saved the NPC profile');
$db->exec('ROLLBACK TO SAVEPOINT digest_edit_probe');

// Loaded-save timelines retire future context without losing recoverable raw records.
$db->exec('SAVEPOINT loaded_timeline_probe');
try {
    $scope=['installation'=>$installationId,'playthrough'=>$turn['playthrough_id']];
    $cloneTurn=\LorkhanServer\Infrastructure\Uuid::v4();$cloneSource=\LorkhanServer\Infrastructure\Uuid::v4();$cloneRequest=\LorkhanServer\Infrastructure\Uuid::v4();
    $clone=$db->prepare("INSERT INTO turns(turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,speaker,target,audience,context,state,accepted_at) SELECT :id,:request,:source,t.session_id,t.generation,t.input_kind,t.input_language,t.input_text,t.speaker,t.target,t.audience,t.context,'complete',t.accepted_at FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE s.installation_id=:installation AND s.playthrough_id=:playthrough LIMIT 1");
    $clone->execute($scope+['id'=>$cloneTurn,'request'=>$cloneRequest,'source'=>$cloneSource]);
    $clone=$db->prepare("INSERT INTO source_events(source_event_id,installation_id,session_id,generation,event_kind,occurred_at,schema_name,turn_id,payload) SELECT :source,s.installation_id,t.session_id,t.generation,'turn.requested',clock_timestamp(),'lorkhan.turn.request.v1',t.turn_id,'{}'::jsonb FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE t.turn_id=:turn");$clone->execute(['source'=>$cloneSource,'turn'=>$cloneTurn]);
    $rows=$db->prepare("SELECT t.turn_id,t.message_id FROM turns t JOIN sessions s ON s.session_id=t.session_id JOIN source_events e ON e.source_event_id=t.message_id WHERE s.installation_id=:installation AND s.playthrough_id=:playthrough ORDER BY t.turn_id LIMIT 2");
    $rows->execute($scope);$timelineTurns=$rows->fetchAll();$assert(count($timelineTurns)===2,'timeline fixture needs two turns');
    foreach($timelineTurns as$index=>$row){$date=['year'=>427,'month'=>7,'day'=>$index===0?16:13,'hour'=>12];
        $q=$db->prepare("UPDATE turns SET context=jsonb_set(context,'{world}',COALESCE(context->'world','{}'::jsonb)||jsonb_build_object('calendar',CAST(:date AS jsonb))) WHERE turn_id=:id");$q->execute(['date'=>json_encode($date),'id'=>$row['turn_id']]);}
    $q=$db->prepare("SELECT e.source_event_id FROM source_events e JOIN sessions s ON s.session_id=e.session_id WHERE e.installation_id=:installation AND s.playthrough_id=:playthrough AND e.event_kind='session.init' LIMIT 1");$q->execute($scope);$loadId=$q->fetchColumn();
    $load=['message_id'=>$loadId,'installation_id'=>$installationId,'playthrough_id'=>$turn['playthrough_id'],'loaded_save'=>['year'=>427,'month'=>7,'day'=>15,'hour'=>12]];
    $writer=new \LorkhanServer\Infrastructure\FirstPartyJobRepository($db);$ids=[];
    foreach($timelineTurns as$row){$id=\LorkhanServer\Infrastructure\Uuid::v4();$ids[]=$id;$writer->upsertMemory($id,['installation_id'=>$installationId,'playthrough_id'=>$turn['playthrough_id'],'profile_id'=>$actorProfile['profile_id'],'tier'=>'recent','content'=>'Timeline memory','source_event_id'=>$row['message_id'],'provenance'=>['source'=>'timeline-test']],gmdate('c'));}
    $diaryId=\LorkhanServer\Infrastructure\Uuid::v4();$manualId=\LorkhanServer\Infrastructure\Uuid::v4();
    foreach([$diaryId,$manualId]as$id)$writer->upsertNarrative($id,['installation_id'=>$installationId,'playthrough_id'=>$turn['playthrough_id'],'profile_id'=>$actorProfile['profile_id'],'kind'=>'diary','title'=>'Timeline diary','content'=>'Test entry','provenance'=>$id===$diaryId?['source_turn_ids'=>[$timelineTurns[0]['turn_id']]]:['source'=>'manual']],gmdate('c'));
    // Real leased jobs record ancestry: a past-source descendant still inherits its future-source base.
    $baseline=$products->getRevisioned('profile',$actorProfile['profile_id']);
    $baselineContent=$baseline['content'];$baselineContent['management']['locked']=false;$baselineContent['personality']='SAFE PROFILE BASELINE';
    $products->revise('profile',$actorProfile['profile_id'],$baselineContent,'manual baseline',gmdate('c'));
    $profileBase=$products->getRevisioned('profile',$actorProfile['profile_id']);
    $generatedJob=$db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,state,attempt_count,
        lease_owner,lease_token,leased_at,lease_expires_at,heartbeat_at) VALUES(:id,'profile.generate',1,:key,CAST(:payload AS jsonb),'leased',1,
        'timeline-test',:token,clock_timestamp(),clock_timestamp()+interval '5 minutes',clock_timestamp())");
    foreach([1,0,1]as$generationIndex=>$sourceIndex){
        $current=$products->getRevisioned('profile',$actorProfile['profile_id']);$jobId=\LorkhanServer\Infrastructure\Uuid::v4();
        $sources=[$timelineTurns[$sourceIndex]['turn_id']];
        $jobPayload=['profile_id'=>$actorProfile['profile_id'],'base_revision'=>$current['current_revision'],
            'mode'=>'profile_evolution','playthrough_id'=>$turn['playthrough_id'],'source_turn_ids'=>$sources];
        $generatedJob->execute(['id'=>$jobId,'key'=>$jobId,'token'=>\LorkhanServer\Infrastructure\Uuid::v4(),'payload'=>json_encode($jobPayload)]);
        $generatedContent=$current['content'];$generatedContent['personality']='GENERATED PROFILE '.$generationIndex;
        $generatedContent['relationships']='GENERATED RELATIONSHIPS '.$generationIndex;
        $assert($products->reviseGeneratedProfileIfCurrent($actorProfile['profile_id'],$current['current_revision'],$generatedContent,
            'reason is not provenance',gmdate('c'),$sources,$jobId,1),'leased automatic profile revision was not published');
    }
    $generatedHead=$products->getRevisioned('profile',$actorProfile['profile_id']);
    $timeline=new \LorkhanServer\Infrastructure\LoadedSaveTimeline($db);
    $db->exec('SAVEPOINT profile_relationship_retention');
    $retentionGlobal=$products->globalSettingsForInstallation($installationId);
    $retentionContent=$retentionGlobal['content'];$retentionContent['relationship']['never_clear_relationship_data']=true;
    $products->revise('global_settings',$retentionGlobal['configuration_id'],$retentionContent,'profile relationship retention fixture',$now);
    $timeline->invalidate($load);
    $retainedProfile=$products->getRevisioned('profile',$actorProfile['profile_id']);
    $assert($retainedProfile['content']['relationships']==='GENERATED RELATIONSHIPS 2'
        &&$retainedProfile['content']['personality']==='GENERATED PROFILE 0','relationship retention prevented unrelated profile rollback or lost relationships');
    $db->exec('ROLLBACK TO SAVEPOINT profile_relationship_retention');
    foreach(['manual','locked','other_playthrough','unknown','player','narrator','creature']as$boundary){
        $db->exec('SAVEPOINT profile_boundary_probe');
        if($boundary==='manual')$products->revise('profile',$actorProfile['profile_id'],$generatedHead['content'],'manual edit',gmdate('c'));
        elseif($boundary==='locked'){
            $q=$db->prepare("UPDATE profile_revisions SET content=jsonb_set(content,'{management,locked}','true') WHERE profile_id=:profile AND revision=:revision");
            $q->execute(['profile'=>$actorProfile['profile_id'],'revision'=>$generatedHead['current_revision']]);
        }elseif(in_array($boundary,['player','narrator','creature'],true)){
            $q=$db->prepare("UPDATE profiles SET actor_identity=jsonb_set(actor_identity,'{kind}',CAST(:kind AS jsonb)) WHERE profile_id=:profile");
            $q->execute(['profile'=>$actorProfile['profile_id'],'kind'=>json_encode($boundary)]);
        }else{
            $q=$db->prepare("UPDATE profile_revisions SET provenance=CAST(:provenance AS jsonb) WHERE profile_id=:profile AND revision=:revision");
            $q->execute(['profile'=>$actorProfile['profile_id'],'revision'=>$generatedHead['current_revision'],
                'provenance'=>$boundary==='unknown'?'{}':json_encode(['kind'=>'automatic_profile','playthrough_id'=>\LorkhanServer\Infrastructure\Uuid::v4()])]);
        }
        $protected=$products->getRevisioned('profile',$actorProfile['profile_id']);$timeline->invalidate($load);
        $afterBoundary=$products->getRevisioned('profile',$actorProfile['profile_id']);
        $assert($boundary==='creature'?$afterBoundary['content']['personality']==='GENERATED PROFILE 0':
            $afterBoundary['current_revision']===$protected['current_revision'],'profile rollback boundary failed: '.$boundary);
        $db->exec('ROLLBACK TO SAVEPOINT profile_boundary_probe');
    }
    $before=(int)$db->query('SELECT count(*) FROM source_events')->fetchColumn();
    $timeline=new \LorkhanServer\Infrastructure\LoadedSaveTimeline($db);$counts=$timeline->invalidate($load);
    $restored=$products->getRevisioned('profile',$actorProfile['profile_id']);
    $assert($counts['profiles']===1&&$restored['current_revision']===$generatedHead['current_revision']+1
        &&$restored['content']['personality']==='GENERATED PROFILE 0','rollback did not remove inherited future profile content monotonically');
    $projection=$db->prepare('SELECT n.personality FROM public.core_npc_master n JOIN npc_metadata m ON m.npc_id=n.id WHERE m.source_profile_id=:profile');
    $projection->execute(['profile'=>$actorProfile['profile_id']]);
    $assert($projection->fetchColumn()===$restored['content']['personality'],'restored profile public projection is stale');
    $q=$db->prepare('SELECT deleted_at IS NOT NULL FROM memory_records WHERE memory_id=:id');$q->execute(['id'=>$ids[0]]);$assert($q->fetchColumn()===true,'future memory remains active');$q->execute(['id'=>$ids[1]]);$assert($q->fetchColumn()===false,'past memory was retired');
    $q=$db->prepare('SELECT deleted_at IS NOT NULL FROM narrative_records WHERE narrative_id=:id');$q->execute(['id'=>$diaryId]);$assert($q->fetchColumn()===true,'future diary remains active');$q->execute(['id'=>$manualId]);$assert($q->fetchColumn()===false,'undated manual diary was retired');
    $assert((int)$db->query('SELECT count(*) FROM source_events')->fetchColumn()===$before,'immutable sources changed');
    $assert(array_sum($timeline->invalidate($load))===0,'timeline invalidation is not idempotent');
    $db->exec('SAVEPOINT profile_earlier_load_probe');
    $earlier=$load;$earlier['loaded_save']['day']=12;$timeline->invalidate($earlier);
    $earlierProfile=$products->getRevisioned('profile',$actorProfile['profile_id']);
    $assert($earlierProfile['current_revision']===$restored['current_revision']+1
        &&$earlierProfile['content']===$profileBase['content'],'earlier load failed to traverse restored ancestry');
    $db->exec('ROLLBACK TO SAVEPOINT profile_earlier_load_probe');
    $bookRow=$db->query("INSERT INTO public.books(title,content,localts,gamets) VALUES('Retired book','Reconstructible book text',0,0) RETURNING rowid")->fetchColumn();
    $bookMeta=$db->prepare('INSERT INTO book_metadata(rowid,installation_id,playthrough_id,record_id,source_turn_id) VALUES(:row,:installation,:playthrough,:record,:turn)');
    $bookMeta->execute($scope+['row'=>$bookRow,'record'=>'timeline_late_book','turn'=>$timelineTurns[0]['turn_id']]);
    $assert((int)$db->query('SELECT count(*) FROM public.books WHERE rowid='.(int)$bookRow)->fetchColumn()===0,'late book projection reappeared');
    $assert(!$writer->narrativeSourcesActive([$timelineTurns[0]['turn_id']])&&$writer->narrativeSourcesActive([$timelineTurns[1]['turn_id']]),'queued diary source eligibility ignores rollback');
    $savedProfile=$products->getRevisioned('profile',$actorProfile['profile_id']);
    $assert(!$products->reviseGeneratedProfileIfCurrent($actorProfile['profile_id'],$savedProfile['current_revision'],$savedProfile['content'],'Retired generation',gmdate('c'),[$timelineTurns[0]['turn_id']]),'late profile generation ignored rollback');

    $lateMemory=\LorkhanServer\Infrastructure\Uuid::v4();
    $writer->upsertMemory($lateMemory,['installation_id'=>$installationId,'playthrough_id'=>$turn['playthrough_id'],'profile_id'=>$actorProfile['profile_id'],'tier'=>'recent','content'=>'Late timeline memory','source_event_id'=>$timelineTurns[0]['message_id'],'provenance'=>['source'=>'timeline-test']],gmdate('c'));
    $q=$db->prepare('SELECT deleted_at IS NOT NULL FROM memory_records WHERE memory_id=:id');$q->execute(['id'=>$lateMemory]);$assert($q->fetchColumn()===true,'late new memory publication bypassed timeline fence');
    $lateDiary=\LorkhanServer\Infrastructure\Uuid::v4();$writer->upsertNarrative($lateDiary,['installation_id'=>$installationId,'playthrough_id'=>$turn['playthrough_id'],'profile_id'=>$actorProfile['profile_id'],'kind'=>'diary','title'=>'Late diary','content'=>'Late entry','provenance'=>['source_turn_ids'=>[$timelineTurns[0]['turn_id']]]],gmdate('c'));
    $q=$db->prepare('SELECT deleted_at IS NOT NULL FROM narrative_records WHERE narrative_id=:id');$q->execute(['id'=>$lateDiary]);$assert($q->fetchColumn()===true,'late new diary publication bypassed timeline fence');


    $speechRow=$db->query("INSERT INTO public.speech(speaker,speech,localts,gamets) VALUES('Retired speaker','Late speech',0,0) RETURNING rowid")->fetchColumn();
    $speechMeta=$db->prepare('INSERT INTO speech_metadata(rowid,installation_id,playthrough_id,turn_id) VALUES(:row,:installation,:playthrough,:turn)');
    $speechMeta->execute($scope+['row'=>$speechRow,'turn'=>$timelineTurns[0]['turn_id']]);
    $assert((int)$db->query('SELECT count(*) FROM public.speech WHERE rowid='.(int)$speechRow)->fetchColumn()===0,'late speech projection reappeared');
    $db->exec('SAVEPOINT fractional_timeline_probe');
    $load['loaded_save']['hour']=12+30/3600;
    foreach([20=>false,40=>true]as$second=>$expected){
        $q=$db->prepare("UPDATE turns SET context=jsonb_set(context,'{world,calendar}',CAST(:calendar AS jsonb)) WHERE turn_id=:id");
        $q->execute(['calendar'=>json_encode(['year'=>427,'month'=>7,'day'=>15,'hour'=>12+$second/3600]),'id'=>$timelineTurns[1]['turn_id']]);
        $timeline->invalidate($load);
        $q=$db->prepare('SELECT EXISTS(SELECT 1 FROM timeline_invalidated_turns WHERE turn_id=:id)');$q->execute(['id'=>$timelineTurns[1]['turn_id']]);
        $assert($q->fetchColumn()===$expected,'fractional GameHour cutoff lost second precision');
    }
    $db->exec('ROLLBACK TO SAVEPOINT fractional_timeline_probe');
    // The source snapshots remain available only as audit evidence.
}finally{$db->exec('ROLLBACK TO SAVEPOINT loaded_timeline_probe');}

$db->exec('SAVEPOINT digest_pagination_probe');
$db->prepare("INSERT INTO memory_records(memory_id,installation_id,profile_id,playthrough_id,tier,content,lexical_terms,fake_vector,provenance,occurred_at,derivation_key)
    SELECT md5('digest-page-'||n)::uuid,m.installation_id,m.profile_id,m.playthrough_id,'mid','Unwitnessed recent scene',ARRAY[]::text[],'[]'::jsonb,m.provenance,m.occurred_at+interval '1 day','digest-page-'||n FROM memory_records m CROSS JOIN generate_series(1,501) n WHERE m.memory_id=:id")->execute(['id'=>$mixedMemory['memory_id']]);
for($digestIndex=1;$digestIndex<=5;$digestIndex++){
    $bystanderScene=$memoryService->createMemory(['installation_id'=>$installationId,'profile_id'=>$turn['profile_id'],'playthrough_id'=>$turn['playthrough_id'],
        'tier'=>'mid','content'=>'Witnessed older scene '.$digestIndex,'provenance'=>['source'=>'memory.consolidate','provider'=>'first-party','model'=>'deterministic-extractive-v1','source_event_ids'=>[$sharedSource]]]);
    $db->prepare('UPDATE memory_records SET derivation_key=:key WHERE memory_id=:id')->execute(['key'=>'bystander-page-'.$digestIndex,'id'=>$bystanderScene['memory_id']]);
}
$assert(count($products->memoryDigestCandidates($installationId,$turn['playthrough_id'],$digestBystander['profile_id'],$memoryNow))===5,'unrelated newer NPC scenes starved the witnessed digest batch');
$db->exec('ROLLBACK TO SAVEPOINT digest_pagination_probe');
$db->prepare("UPDATE eventlog_metadata SET suppressed_at=clock_timestamp() WHERE source_event_id=:source AND projection_kind='turn'")->execute(['source'=>$sharedSource]);
$assert(!in_array($mixedMemory['memory_id'],array_column($products->memoryDigestCandidates($installationId,$turn['playthrough_id'],$actorProfile['profile_id'],$memoryNow),'id'),true),
    'digest generation cannot resurrect suppressed source history');
$assert($digests->latest($installationId,$turn['playthrough_id'],$actorProfile['profile_id'])===null,'hidden source history leaked through the cumulative digest chain');
$assert($products->memoryDigestCandidates($installationId,\LorkhanServer\Infrastructure\Uuid::v4(),$actorProfile['profile_id'],$memoryNow)===[],'digest generation crossed playthrough scope');
$db->exec('ROLLBACK TO SAVEPOINT digest_witness_probe');
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
$db->exec('SAVEPOINT custom_event_filter_probe');
$customEvent=$db->prepare("SELECT rowid FROM eventlog_metadata WHERE source_event_id=:source AND projection_kind='turn'");
$customEvent->execute(['source'=>$sharedSource]);$customEventRow=$customEvent->fetchColumn();
$db->prepare("UPDATE eventlog SET type='custom_parity_event',data='CUSTOM EVENT PARITY SENTINEL' WHERE rowid=:rowid")->execute(['rowid'=>$customEventRow]);
$filteredContent=$limitedContent;unset($filteredContent['settings_overrides']['context']['event_types']);
$filteredContent['settings_overrides']['context']['event_types_excluded']=[];
$products->revise('core_profile',$actorCoreProfile['core_profile_id'],$filteredContent,'empty exclusions include custom events',$memoryNow);
$assert(in_array('event:'.$customEventRow,array_column($products->promptContext($memoryProbe,$memoryNow)['history'],'id'),true),
    'empty exclusion filter did not include a witnessed custom event');
$assert(str_contains((new PromptAssembler())->assemble($memoryProbe,$products->promptContext($memoryProbe,$memoryNow))['provider_input']['_assembled_prompt'],
    'CUSTOM EVENT PARITY SENTINEL'),'custom event text was lost before prompt assembly');
$filteredContent['settings_overrides']['context']['event_types_excluded']=['custom_parity_event'];
$products->revise('core_profile',$actorCoreProfile['core_profile_id'],$filteredContent,'exclude custom event',$memoryNow);
$assert(!in_array('event:'.$customEventRow,array_column($products->promptContext($memoryProbe,$memoryNow)['history'],'id'),true),
    'checked custom event was not excluded from history');
$assert((int)$db->query('SELECT count(*) FROM eventlog')->fetchColumn()===$eventCountBefore,'custom exclusion deleted event history');
$db->exec('ROLLBACK TO SAVEPOINT custom_event_filter_probe');
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
// Transfers are proposals against frozen native observations, never authority to invent or reselect inventory.
$transferRouter=new Router($repo,new Validator(),new MockProvider(),$tokenHash,rateLimitRequests:1000,providerAttempts:$attempts);
$db->beginTransaction();
try{
    $transferNames=['item.give','item.take','item.pickup','gold.give','gold.take'];
    $db->prepare("UPDATE sessions SET enabled_actions=enabled_actions||ARRAY['item.give','item.take','item.pickup','gold.give','gold.take'],
        capabilities=capabilities||ARRAY['action.item.give','action.item.take','action.item.pickup','action.gold.give','action.gold.take','action.confirmation']
        WHERE session_id=:session")->execute(['session'=>$sessionId]);
    $transferRecipient=$turn['payload']['target'];$transferRecipient['record_id']='transfer_recipient';
    $transferRecipient['display_name']='Transfer Recipient';$transferRecipient['refnum']['index']=99123;
    $transferRows=[
        ['item_id'=>'@0x1001','record_id'=>'iron_dagger','name'=>'Iron Dagger','count'=>3,'location'=>'actor_inventory','owner'=>$turn['payload']['target']],
        ['item_id'=>'@0x1002','record_id'=>'common_shirt_01','name'=>'Common Shirt','count'=>4,'location'=>'player_inventory','owner'=>$turn['payload']['speaker']],
        ['item_id'=>'@0x1003','record_id'=>'ingred_bread_01','name'=>'Bread','count'=>2,'location'=>'ground','owner'=>null],
        ['item_id'=>'@0x1004','record_id'=>'gold_001','name'=>'Gold','count'=>50,'location'=>'actor_inventory','owner'=>$turn['payload']['target']],
        ['item_id'=>'@0x1005','record_id'=>'gold_001','name'=>'Gold','count'=>30,'location'=>'player_inventory','owner'=>$turn['payload']['speaker']],
    ];
    $transferTurn=static function()use($turn,$transferRows,$transferRecipient):array{
        $request=$turn;foreach(['message_id','request_id','turn_id']as$field)$request[$field]=Uuid::v4();
        $request['payload']['input']['text']='Propose the observed transfer.';$request['payload']['recent_action_results']=[];
        $request['payload']['context']['action_items']=$transferRows;
        $request['payload']['context']['nearbyActors']=['items'=>[$transferRecipient]];
        unset($request['payload']['action_request']);return$request;
    };
    $transferActionRows=$db->prepare('SELECT action_name,parameters,confirmation_required,actor,target FROM action_intents WHERE turn_id=:turn');
    $transferProviderFor=static fn(array$proposal)=>new class($proposal) implements Provider {
        public int $calls=0;
        public function __construct(private array $proposal){}
        public function complete(array $turn,CancellationToken $cancellation):array{
            $cancellation->throwIfCancellationRequested();++$this->calls;
            return ['utterances'=>[['speaker'=>$turn['payload']['target'],
                'addressee'=>$turn['payload']['speaker'],'text'=>'The transfer awaits your confirmation.']],'action'=>$this->proposal];
        }
    };
    $transferCases=[['item.give',['item_id'=>'@0x1001','count'=>3]],['item.take',['item_id'=>'@0x1002','count'=>4]],
        ['item.pickup',['item_id'=>'@0x1003']],['gold.give',['amount'=>50]],['gold.take',['amount'=>30]]];
    foreach(['direct','provider']as$transferPath)foreach($transferCases as[$transferName,$transferParameters]){
        $request=$transferTurn();
        if($transferPath==='direct')$request['payload']['action_request']=['name'=>$transferName,'tier'=>2,'parameters'=>$transferParameters];
        [$transferStatus,$transferBody]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        $assert($transferStatus===202,'transfer '.$transferPath.' ingress failed: '.json_encode([$transferName,$transferStatus,$transferBody]));
        if($transferPath==='provider'){
            $proposal=['name'=>$transferName,'tier'=>2,'parameters'=>$transferParameters,
                'actor'=>$request['payload']['target'],'target'=>$request['payload']['speaker']];
            $transferProvider=$transferProviderFor($proposal);
            $transferStats=$runTurnWorker($transferProvider);
            $assert($transferStats['succeeded']>=1,'transfer provider worker failed: '.$transferName);
        }
        $transferActionRows->execute(['turn'=>$request['turn_id']]);$transferIntents=$transferActionRows->fetchAll();
        $storedParameters=isset($transferIntents[0])?json_decode($transferIntents[0]['parameters'],true):[];
        $expectedParameters=$transferParameters;ksort($storedParameters);ksort($expectedParameters);
        $transferState=$db->prepare('SELECT state,response_payload FROM turns WHERE turn_id=:id');$transferState->execute(['id'=>$request['turn_id']]);
        $assert(count($transferIntents)===1&&$transferIntents[0]['action_name']===$transferName
            &&$storedParameters===$expectedParameters&&$transferIntents[0]['confirmation_required']===true,
            'transfer intent changed exact parameters or omitted forced confirmation: '.json_encode([$transferName,$transferPath,$transferIntents,$transferStats??null,$transferState->fetch(),$transferProvider->calls??null]));
        $frozen=$db->prepare('SELECT context FROM turns WHERE turn_id=:turn');$frozen->execute(['turn'=>$request['turn_id']]);
        $assert(json_decode($frozen->fetchColumn(),true)['action_items']==$transferRows,'transfer mutated accepted item observations');
    }
    foreach(['unknown_id','over_count','wrong_owner','missing_confirmation','missing_action_capability','wrong_target','gold_overdraft','fake_gold','unavailable_recipient']as$transferFailure){
        $db->exec('SAVEPOINT invalid_transfer');$request=$transferTurn();
        $request['payload']['action_request']=['name'=>'item.give','tier'=>2,'parameters'=>['item_id'=>'@0x1001','count'=>1]];
        if($transferFailure==='unknown_id')$request['payload']['action_request']['parameters']['item_id']='@0xdead';
        elseif($transferFailure==='over_count')$request['payload']['action_request']['parameters']['count']=4;
        elseif($transferFailure==='wrong_owner')$request['payload']['context']['action_items'][0]['owner']=$request['payload']['speaker'];
        elseif($transferFailure==='missing_confirmation'||$transferFailure==='missing_action_capability'){
            $db->prepare('UPDATE sessions SET capabilities=array_remove(capabilities,:capability) WHERE session_id=:session')
                ->execute(['session'=>$sessionId,'capability'=>$transferFailure==='missing_confirmation'?'action.confirmation':'action.item.give']);
        }elseif($transferFailure==='wrong_target'){
            $request['payload']['action_request']['target']=$transferRecipient;$request['payload']['action_request']['target']['refnum']['index']++;
        }elseif($transferFailure==='unavailable_recipient'){
            $request['payload']['action_request']['target']=$transferRecipient;$request['payload']['context']['nearbyActors']['items'][0]['available']=false;
        }else{
            $request['payload']['action_request']=['name'=>'gold.give','tier'=>2,'parameters'=>['amount'=>$transferFailure==='gold_overdraft'?51:1]];
            if($transferFailure==='fake_gold')$request['payload']['context']['action_items'][3]['record_id']='fake_gold';
        }
        $beforeTransfers=(int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn();
        [$transferStatus]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        $assert($transferStatus>=400&&(int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn()===$beforeTransfers,
            'invalid transfer emitted an intent: '.$transferFailure);
        $stored=$db->prepare('SELECT count(*) FROM turns WHERE turn_id=:id');$stored->execute(['id'=>$request['turn_id']]);
        $assert((int)$stored->fetchColumn()===0,'invalid direct transfer partially persisted a turn: '.$transferFailure);
        $db->exec('ROLLBACK TO SAVEPOINT invalid_transfer');
    }
    foreach(['direct','provider']as$transferPath){
        $request=$transferTurn();$proposal=['name'=>'item.give','tier'=>2,'parameters'=>['item_id'=>'@0x1001','count'=>1],
            'actor'=>$request['payload']['target'],'target'=>$transferRecipient];
        if($transferPath==='direct')$request['payload']['action_request']=array_diff_key($proposal,['actor'=>true]);
        [$transferStatus]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        $assert($transferStatus===202,'observed NPC recipient was rejected on '.$transferPath);
        if($transferPath==='provider')$runTurnWorker($transferProviderFor($proposal));
        $transferActionRows->execute(['turn'=>$request['turn_id']]);$intent=$transferActionRows->fetch();
        $assert($intent&&json_decode($intent['target'],true)==$transferRecipient&&$intent['confirmation_required']===true,
            'observed NPC transfer recipient was replaced or lost approval on '.$transferPath);
    }
    foreach(['unknown_id','wrong_actor','wrong_target','over_count','unavailable_recipient','missing_confirmation']as$transferFailure){
        $db->exec('SAVEPOINT invalid_provider_transfer');$request=$transferTurn();
        if($transferFailure==='unavailable_recipient')$request['payload']['context']['nearbyActors']['items'][0]['available']=false;
        if($transferFailure==='missing_confirmation')$db->prepare("UPDATE sessions SET capabilities=array_remove(capabilities,'action.confirmation') WHERE session_id=:session")
            ->execute(['session'=>$sessionId]);
        [$transferStatus]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        $assert($transferStatus===202,'invalid provider proposal fixture failed acceptance');
        $proposal=['name'=>'item.give','tier'=>2,'parameters'=>['item_id'=>'@0x1001','count'=>1],
            'actor'=>$request['payload']['target'],'target'=>$request['payload']['speaker']];
        if($transferFailure==='unknown_id')$proposal['parameters']['item_id']='@0xdead';
        elseif($transferFailure==='over_count')$proposal['parameters']['count']=4;
        elseif($transferFailure==='wrong_actor')$proposal['actor']=$transferRecipient;
        elseif($transferFailure==='wrong_target'){$proposal['target']=$transferRecipient;$proposal['target']['refnum']['index']++;}
        elseif($transferFailure==='unavailable_recipient')$proposal['target']=$transferRecipient;
        $invalidProvider=$transferProviderFor($proposal);$beforeTransfers=(int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn();
        $runTurnWorker($invalidProvider);
        $assert($invalidProvider->calls>0&&(int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn()===$beforeTransfers,
            'invalid provider transfer emitted intent or bypassed the provider fixture: '.$transferFailure);
        $db->exec('ROLLBACK TO SAVEPOINT invalid_provider_transfer');
    }
    // Rechat changes the interlocutor, not ownership of the separately observed player inventory.
    $request=$transferTurn();$request['payload']['speaker']=$transferRecipient;
    $request['payload']['context']['player']=$turn['payload']['speaker'];
    $request['payload']['action_request']=['name'=>'gold.take','tier'=>2,'parameters'=>['amount'=>30]];
    [$transferStatus]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
    $transferActionRows->execute(['turn'=>$request['turn_id']]);$rechatTransfer=$transferActionRows->fetch();
    $assert($transferStatus===202&&$rechatTransfer&&json_decode($rechatTransfer['target'],true)==$turn['payload']['speaker']
        &&$rechatTransfer['confirmation_required']===true,'NPC interlocutor changed the observed player-gold owner or recipient');

    // A caller-modified worker message must not replace the accepted turn's item authority.
    $request=$transferTurn();[$transferStatus]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
    $assert($transferStatus===202,'immutable transfer fixture was not accepted');
    $request['payload']['context']['action_items'][0]['item_id']='@0xdead';
    $beforeTransfers=(int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn();
    try{$repo->completeTurn($request,['utterances'=>[],'action'=>['name'=>'item.give','tier'=>2,
        'actor'=>$request['payload']['target'],'target'=>$request['payload']['speaker'],'parameters'=>['item_id'=>'@0xdead','count'=>1]]]);
        $assert(false,'caller-substituted transfer context gained authority');
    }catch(DomainException $error){$assert($error->getMessage()==='action_parameters_invalid','unexpected stale transfer authority failure: '.$error->getMessage());}
    $assert((int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn()===$beforeTransfers,'stale provider snapshot emitted a transfer');
}finally{$db->rollBack();}

// Explicit Narrator actions need both policies and an observed physical executor's own state.
$db->beginTransaction();
try{
    $db->prepare("UPDATE sessions SET enabled_actions=array_append(enabled_actions,'service.barter'),capabilities=array_append(capabilities,'action.service.barter') WHERE session_id=:session")->execute(['session'=>$sessionId]);
    $physicalIdentity=$turn['payload']['target'];
    $physicalProfile=$products->effectiveSettingsForActor($installationId,$turn['playthrough_id'],$physicalIdentity)['npc_profile'];
    $assert(isset($physicalProfile['profile_id']),'Narrator policy fixture has no physical NPC profile');
    foreach(['allowed','npc_denied','narrator_denied','forged_executor','borrowed_state']as$narratorCase){
        $db->exec('SAVEPOINT narrator_authority');$request=$transferTurn();
        $request['payload']['execution_mode']='narrator';$request['payload']['ui_source']='lorkhan_text';
        $request['payload']['target']=array_replace($physicalIdentity,['kind'=>'narrator','record_id'=>'lorkhan:narrator','content_file'=>'LORKHAN','display_name'=>'The Narrator']);
        $request['payload']['context']['nearbyActors']=['items'=>[$physicalIdentity]];
        $request['payload']['context']['actorActionStates']=[['actor'=>$physicalIdentity,'services_known'=>true,'services'=>['barter']]];
        $request['payload']['context']['targetState']=['services_known'=>true,'services'=>['barter']];
        if($narratorCase==='borrowed_state')$request['payload']['context']['actorActionStates']=[];
        if(in_array($narratorCase,['npc_denied','narrator_denied'],true)){
            $policyProfile=$narratorCase==='npc_denied'?$physicalProfile['profile_id']:$narratorProfile['profile_id'];
            $products->createRevisioned('action_policy',['installation_id'=>$installationId,'profile_id'=>$policyProfile,
                'name'=>'Narrator intersection fixture','content'=>['enabled'=>true,'denied_actions'=>['service.barter']]],$now);
        }
        [$narratorStatus,$narratorBody]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        $assert($narratorStatus===202,'Narrator action fixture rejected: '.json_encode($narratorBody));
        $executor=$narratorCase==='forged_executor'?$transferRecipient:$physicalIdentity;
        $narratorProvider=$transferProviderFor(['name'=>'service.barter','tier'=>1,'parameters'=>[],
            'actor'=>$executor,'target'=>$request['payload']['speaker']]);
        $runTurnWorker($narratorProvider);$assert($narratorProvider->calls>0,'Narrator mock provider bypassed');
        $transferActionRows->execute(['turn'=>$request['turn_id']]);$narratorIntent=$transferActionRows->fetch();
        $assert(($narratorCase==='allowed')===($narratorIntent!==false),'Narrator crossed actor/policy boundary: '.$narratorCase);
        if($narratorIntent)$assert(json_decode($narratorIntent['actor'],true)==$physicalIdentity,'Narrator became physical executor');
        $db->exec('ROLLBACK TO SAVEPOINT narrator_authority');
    }
    $request=$transferTurn();$request['payload']['execution_mode']='narrator';$request['payload']['ui_source']='lorkhan_text';
    $request['payload']['target']=array_replace($physicalIdentity,['kind'=>'narrator','record_id'=>'lorkhan:narrator','content_file'=>'LORKHAN','display_name'=>'The Narrator']);
    $request['payload']['context']['nearbyActors']=['items'=>[]];$request['payload']['context']['actorActionStates']=[];
    [$narratorStatus]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
    $assert($narratorStatus===202,'frozen Narrator fixture rejected');
    $request['payload']['context']['nearbyActors']=['items'=>[$physicalIdentity]];
    $request['payload']['context']['actorActionStates']=[['actor'=>$physicalIdentity,'services_known'=>true,'services'=>['barter']]];
    try{$repo->completeTurn($request,['utterances'=>[],'action'=>['name'=>'service.barter','tier'=>1,'parameters'=>[],
        'actor'=>$physicalIdentity,'target'=>$request['payload']['speaker']]]);$assert(false,'caller invented Narrator executor');}
    catch(DomainException $error){$assert($error->getMessage()==='provider_action_not_allowed','unexpected frozen Narrator error: '.$error->getMessage());}
}finally{$db->rollBack();}

// Casting uses a known spell and an exact observed recipient, with mandatory approval.
$db->beginTransaction();
try{
    $db->prepare("UPDATE sessions SET enabled_actions=array_append(enabled_actions,'spell.cast'),capabilities=capabilities||ARRAY['action.spell.cast','action.confirmation'] WHERE session_id=:session")->execute(['session'=>$sessionId]);
    foreach(['direct','provider']as$spellPath)foreach(['self','player','nearby']as$spellRecipient){
        $request=$transferTurn();$request['payload']['context']['targetState']['spells_known']=true;
        $request['payload']['context']['targetState']['spells']=[['spell_id'=>'fire bite','name'=>'Fire Bite']];
        $spellTarget=match($spellRecipient){'self'=>$request['payload']['target'],'nearby'=>$transferRecipient,default=>$request['payload']['speaker']};
        $spellProposal=['name'=>'spell.cast','tier'=>2,'parameters'=>['spell_id'=>'fire bite'],'actor'=>$request['payload']['target'],'target'=>$spellTarget];
        if($spellPath==='direct')$request['payload']['action_request']=array_diff_key($spellProposal,['actor'=>true]);
        [$spellStatus,$spellBody]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        $assert($spellStatus===202,'known spell rejected: '.json_encode([$spellPath,$spellRecipient,$spellBody]));
        if($spellPath==='provider'){$spellProvider=$transferProviderFor($spellProposal);$runTurnWorker($spellProvider);$assert($spellProvider->calls>0,'spell provider bypassed');}
        $transferActionRows->execute(['turn'=>$request['turn_id']]);$spellIntent=$transferActionRows->fetch();
        $assert($spellIntent&&$spellIntent['action_name']==='spell.cast'&&$spellIntent['confirmation_required']===true
            &&json_decode($spellIntent['parameters'],true)===['spell_id'=>'fire bite']&&json_decode($spellIntent['target'],true)==$spellTarget,
            'spell lost exact recipient, known ID or forced approval: '.$spellPath.' '.$spellRecipient);
    }
    foreach(['direct','provider']as$spellPath)foreach(['unknown_id','unknown_state','unobserved_target','dead_target','missing_confirmation']as$spellFailure){
        $db->exec('SAVEPOINT invalid_spell');$request=$transferTurn();
        $request['payload']['context']['targetState']['spells_known']=$spellFailure!=='unknown_state';
        $request['payload']['context']['targetState']['spells']=[['spell_id'=>'fire bite','name'=>'Fire Bite']];
        $spellProposal=['name'=>'spell.cast','tier'=>2,'parameters'=>['spell_id'=>$spellFailure==='unknown_id'?'invented spell':'fire bite'],
            'actor'=>$request['payload']['target'],'target'=>$request['payload']['speaker']];
        if(in_array($spellFailure,['unobserved_target','dead_target'],true)){
            $spellProposal['target']=$transferRecipient;
            if($spellFailure==='dead_target')$request['payload']['context']['nearbyActors']['items'][0]['dead']=true;
            else $spellProposal['target']['refnum']['index']++;
        }
        if($spellFailure==='missing_confirmation')$db->prepare("UPDATE sessions SET capabilities=array_remove(capabilities,'action.confirmation') WHERE session_id=:session")->execute(['session'=>$sessionId]);
        if($spellPath==='direct')$request['payload']['action_request']=array_diff_key($spellProposal,['actor'=>true]);
        $beforeSpells=(int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn();
        [$spellStatus]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        if($spellPath==='provider'){
            $assert($spellStatus===202,'spell provider fixture rejected');$spellProvider=$transferProviderFor($spellProposal);
            $runTurnWorker($spellProvider);$assert($spellProvider->calls>0,'invalid spell provider bypassed');
        }else $assert($spellStatus>=400,'invalid direct spell accepted: '.$spellFailure);
        $assert((int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn()===$beforeSpells,'invalid spell emitted intent: '.$spellPath.' '.$spellFailure);
        $db->exec('ROLLBACK TO SAVEPOINT invalid_spell');
    }
    $request=$transferTurn();$request['payload']['context']['targetState']['spells_known']=true;
    $request['payload']['context']['targetState']['spells']=[['spell_id'=>'fire bite','name'=>'Fire Bite']];
    [$spellStatus]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
    $assert($spellStatus===202,'frozen known spell fixture rejected');
    $request['payload']['context']['targetState']['spells']=[['spell_id'=>'invented spell','name'=>'Invented']];
    try{$repo->completeTurn($request,['utterances'=>[],'action'=>['name'=>'spell.cast','tier'=>2,'parameters'=>['spell_id'=>'invented spell'],
        'actor'=>$request['payload']['target'],'target'=>$request['payload']['speaker']]]);$assert(false,'caller substituted known spells');}
    catch(DomainException $error){$assert($error->getMessage()==='action_parameters_invalid','unexpected frozen spell error: '.$error->getMessage());}
}finally{$db->rollBack();}

// The effective bark period is a shared server floor, independent of local enable/interval controls.
$db->beginTransaction();
try{
    $bark=$transferTurn();$bark['payload']['ui_source']='lorkhan_auto_combat_bark';
    $settings=$products->effectiveSettingsForActor($installationId,$bark['playthrough_id'],$bark['payload']['target']);
    $barkProfile=$settings['npc_profile'];$barkContent=$barkProfile['content'];
    $barkContent['settings_overrides']['behavior']['combat_bark_period_seconds']=600;
    $products->revise('profile',$barkProfile['profile_id'],$barkContent,'combat cooldown fixture',$now);
    [$status,$body]=$call($transferRouter,'POST',$base.'/turns',$headers($bark['message_id']),[],$bark);
    $assert($status===202,'first automatic combat bark rejected: '.json_encode($body));
    [$duplicate]=$call($transferRouter,'POST',$base.'/turns',$headers($bark['message_id']),[],$bark);
    $assert($duplicate===202,'idempotent combat bark retry incorrectly hit cooldown');
    $other=$transferTurn();$other['payload']['ui_source']='lorkhan_auto_combat_bark';$other['payload']['target']=$transferRecipient;
    [$status,$body]=$call($transferRouter,'POST',$base.'/turns',$headers($other['message_id']),[],$other);
    $assert($status>=400&&($body['code']??null)==='conversation_cooldown','different NPC bypassed global bark cooldown: '.json_encode($body));
    $db->prepare("UPDATE source_events SET received_at=clock_timestamp()-interval '30 seconds' WHERE source_event_id=:turn")->execute(['turn'=>$bark['message_id']]);
    $repeat=$transferTurn();$repeat['payload']['ui_source']='lorkhan_auto_combat_bark';
    [$status]=$call($transferRouter,'POST',$base.'/turns',$headers($repeat['message_id']),[],$repeat);
    $assert($status>=400,'local 30-second scheduler bypassed effective 600-second NPC floor');
    $manual=$transferTurn();$manual['payload']['ui_source']='lorkhan_text';
    [$status]=$call($transferRouter,'POST',$base.'/turns',$headers($manual['message_id']),[],$manual);
    $assert($status===202,'bark cooldown suppressed explicit player conversation');
    $db->prepare("UPDATE source_events SET received_at=clock_timestamp()-interval '601 seconds' WHERE source_event_id=:turn")->execute(['turn'=>$bark['message_id']]);
    $expired=$transferTurn();$expired['payload']['ui_source']='lorkhan_auto_combat_bark';
    [$status]=$call($transferRouter,'POST',$base.'/turns',$headers($expired['message_id']),[],$expired);
    $assert($status===202,'expired effective combat bark cooldown did not reopen');
}finally{$db->rollBack();}

// Inject Event logs context without inference; Inject & Chat responds without treating it as speech.
$db->beginTransaction();
try{
    foreach(['injection_log','injection_chat']as$mode){
        $request=$transferTurn();$request['payload']['execution_mode']=$mode;$request['payload']['ui_source']='lorkhan_text';
        $request['payload']['input']=['kind'=>'text','language'=>'en','text'=>'A brass bell rings in the distance.'];
        $injectionRecipient=$request['payload']['target'];
        if($mode==='injection_log'){
            $request['payload']['audience']=[$injectionRecipient];
            $request['payload']['target']=array_replace($injectionRecipient,['kind'=>'narrator','record_id'=>'lorkhan:narrator','content_file'=>'LORKHAN','display_name'=>'The Narrator']);
        }
        $beforeJobs=(int)$db->query('SELECT count(*) FROM durable_jobs')->fetchColumn();
        [$status,$body]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        $assert($status===202,'injection rejected: '.json_encode($body));
        $projection=$db->prepare('SELECT e.type,e.data,m.payload FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid WHERE m.projection_key=:key');
        $projection->execute(['key'=>'turn:'.$request['turn_id']]);$row=$projection->fetch();
        $assert($row&&$row['type']==='narration'&&$row['data']===$request['payload']['input']['text']
            &&json_decode($row['payload'],true)['source']==='player_injection','injection was projected as player speech');
        $assert(!in_array($request['payload']['input']['text'],$products->recentPlayerInputs($installationId,200),true),'scene injection leaked into player speech history');
        if($mode==='injection_log'){
            $assert((int)$db->query('SELECT count(*) FROM durable_jobs')->fetchColumn()===$beforeJobs,'log-only injection queued work');
            $state=$db->prepare('SELECT state FROM turns WHERE turn_id=:turn');$state->execute(['turn'=>$request['turn_id']]);
            $assert($state->fetchColumn()==='complete','log-only injection remained in flight');
            [$again]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
            $projection->execute(['key'=>'turn:'.$request['turn_id']]);
            $assert($again===202&&count($projection->fetchAll())===1,'injection retry duplicated event');
            $next=$transferTurn();$next['payload']['target']=$injectionRecipient;
            $next['payload']['input']['text']='What did you notice?';
            $selection=$products->promptContext($next,gmdate('Y-m-d\TH:i:s\Z'));
            $next['_selected_profile_id']=$selection['selected_profile_id'];
            $contextPrompt=(new PromptAssembler())->assemble($next,$selection)['provider_input']['_assembled_prompt'];
            $assert(str_contains($contextPrompt,'[Narration] A brass bell rings in the distance.'),
                'Narrator-target injection was invisible to its observed witness in the next normal prompt');
        }else{
            $frozen=$repo->turnMessage($request['turn_id']);
            $assert($frozen['_allowed_action_definitions']===[],'injection chat exposed actions');
            try{$repo->completeTurn($request,['utterances'=>[],'action'=>['name'=>'ai.follow','tier'=>1,'parameters'=>[],
                'actor'=>$request['payload']['target'],'target'=>$request['payload']['speaker']]]);$assert(false,'injected provider action accepted');}
            catch(DomainException $error){$assert($error->getMessage()==='provider_action_not_allowed','injection action failed at wrong boundary');}
            $stats=$runTurnWorker(new MockProvider());$assert($stats['succeeded']>0,'injection chat failed to generate response');
            $history=(new ReflectionMethod($products,'profileBackfillHistory'))->invoke($products,$installationId,$request['playthrough_id'],$request['payload']['target'],100);
            $injectedHistory=array_values(array_filter($history['recent_events'],fn($e)=>$e['turn_id']===$request['turn_id']));
            $assert(count($injectedHistory)===1&&isset($injectedHistory[0]['scene_event'])&&!isset($injectedHistory[0]['player_input']),
                'profile history mislabeled injected context as player speech');
        }
    }
}finally{$db->rollBack();}

// World mutations never inherit ordinary NPC authority or caller-replaced mode/record observations.
$db->beginTransaction();
try{
    foreach(\LorkhanServer\Application\AdvancedActionPolicy::NAMES as$name)$db->prepare('UPDATE sessions SET enabled_actions=array_append(enabled_actions,:name),capabilities=array_append(capabilities,:cap) WHERE session_id=:session')
        ->execute(['name'=>$name,'cap'=>'action.'.$name,'session'=>$sessionId]);
    $db->prepare("UPDATE sessions SET capabilities=array_append(capabilities,'action.confirmation') WHERE session_id=:session")->execute(['session'=>$sessionId]);
    $advancedRequest=static function()use($transferTurn):array{
        $request=$transferTurn();$request['payload']['execution_mode']='cheat';$request['payload']['ui_source']='lorkhan_text';
        $request['payload']['context']['player']=$request['payload']['speaker'];
        $request['payload']['context']['advanced_actions']=['items'=>[['record_id'=>'robe','name'=>'Robe']],
            'actors'=>[['record_id'=>'rat','name'=>'Rat','kind'=>'creature']], 'destinations'=>[['destination_id'=>'Balmora','name'=>'Balmora']]];
        return $request;
    };
    $advancedParams=['item.create'=>['record_id'=>'robe','count'=>1],'gold.create'=>['amount'=>10],
        'actor.spawn'=>['record_id'=>'rat','count'=>1],'player.teleport'=>['destination_id'=>'Balmora']];
    foreach(['cheat','narrator']as$mode)foreach(\LorkhanServer\Application\AdvancedActionPolicy::NAMES as$name){
        $request=$advancedRequest();$physical=$request['payload']['target'];
        $request['payload']['execution_mode']=$mode;
        if($mode==='narrator'){
            $request['payload']['target']=array_replace($physical,['kind'=>'narrator','record_id'=>'lorkhan:narrator','content_file'=>'LORKHAN','display_name'=>'The Narrator']);
            $request['payload']['context']['nearbyActors']['items'][]=$physical;
        }
        $target=in_array($name,['actor.teleport_to_player','actor.resurrect','actor.kill'],true)?$physical:$request['payload']['speaker'];
        [$status,$body]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        $assert($status===202,'world turn rejected '.$name.': '.json_encode($body));
        $definitions=$repo->allowedPromptActions($sessionId,$request['generation'],$request['payload']);
        $assert(in_array($name,array_column($definitions,'name'),true),'world action not exposed '.$mode.' '.$name);
        if($mode==='narrator')$assert(isset($repo->narratorExecutors($sessionId,$request['generation'],$request['payload'])['player']),'Narrator missing world executor');
        $provider=$transferProviderFor(['name'=>$name,'tier'=>2,'parameters'=>$advancedParams[$name]??[],
            'actor'=>$request['payload']['speaker'],'target'=>$target]);
        $runTurnWorker($provider);$assert($provider->calls>0,'world provider bypassed');
        $transferActionRows->execute(['turn'=>$request['turn_id']]);$intent=$transferActionRows->fetch();
        $assert($intent&&$intent['action_name']===$name&&$intent['confirmation_required']===true
            &&json_decode($intent['actor'],true)==$request['payload']['speaker'],'world intent lost authority or approval '.$mode.' '.$name);
        $beforeReplay=(int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn();
        [$replayStatus]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        $assert($replayStatus===202&&(int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn()===$beforeReplay,
            'world request retry duplicated a mutation '.$name);
    }
    foreach(['standard','openmic','npc_executor','record','count','confirmation','forged_target']as$failure){
        $db->exec('SAVEPOINT invalid_world');$request=$advancedRequest();
        if($failure==='standard')$request['payload']['execution_mode']='standard';
        if($failure==='openmic')$request['payload']['ui_source']='lorkhan_open_mic';
        if($failure==='confirmation')$db->prepare("UPDATE sessions SET capabilities=array_remove(capabilities,'action.confirmation') WHERE session_id=:session")->execute(['session'=>$sessionId]);
        [$status]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        $assert($status===202,'negative world turn rejected at unrelated boundary');
        $proposal=['name'=>'item.create','tier'=>2,'parameters'=>['record_id'=>$failure==='record'?'invented':'robe','count'=>$failure==='count'?101:1],
            'actor'=>$failure==='npc_executor'?$request['payload']['target']:$request['payload']['speaker'],'target'=>$request['payload']['speaker']];
        if($failure==='forged_target')$proposal['target']['refnum']['index']++;
        $before=(int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn();
        $provider=$transferProviderFor($proposal);$runTurnWorker($provider);
        $assert((int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn()===$before,'invalid world mutation emitted: '.$failure);
        $db->exec('ROLLBACK TO SAVEPOINT invalid_world');
    }
    $request=$advancedRequest();$request['payload']['execution_mode']='standard';
    [$status]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
    $assert($status===202,'frozen world mode fixture rejected');$request['payload']['execution_mode']='cheat';
    try{$repo->completeTurn($request,['utterances'=>[],'action'=>['name'=>'gold.create','tier'=>2,'parameters'=>['amount'=>5],
        'actor'=>$request['payload']['speaker'],'target'=>$request['payload']['speaker']]]);$assert(false,'caller replaced accepted world mode');}
    catch(DomainException $error){$assert($error->getMessage()==='provider_action_not_allowed','unexpected world mode boundary: '.$error->getMessage());}
    $request=$advancedRequest();
    [$status]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
    $assert($status===202,'frozen record fixture rejected');
    $request['payload']['context']['advanced_actions']['items']=[['record_id'=>'invented','name'=>'Invented']];
    try{$repo->completeTurn($request,['utterances'=>[],'action'=>['name'=>'item.create','tier'=>2,'parameters'=>['record_id'=>'invented','count'=>1],
        'actor'=>$request['payload']['speaker'],'target'=>$request['payload']['speaker']]]);$assert(false,'caller replaced accepted native record candidates');}
    catch(DomainException $error){$assert($error->getMessage()==='action_parameters_invalid','unexpected world candidate boundary: '.$error->getMessage());}
    $request=$advancedRequest();$request['payload']['target']=array_replace($request['payload']['target'],
        ['kind'=>'narrator','record_id'=>'lorkhan:narrator','content_file'=>'LORKHAN','display_name'=>'The Narrator']);
    $request['payload']['context']['nearbyActors']['items']=[];
    [$status,$body]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
    $assert($status===202,'Cheat without selected NPC rejected: '.json_encode($body));
    $available=$repo->allowedPromptActions($sessionId,$request['generation'],$request['payload']);
    $assert(in_array('gold.create',array_column($available,'name'),true)
        &&!in_array('ai.follow',array_column($available,'name'),true),'bodiless Cheat lost world action or gained physical NPC actions');
    $provider=$transferProviderFor(['name'=>'gold.create','tier'=>2,'parameters'=>['amount'=>5],
        'actor'=>$request['payload']['speaker'],'target'=>$request['payload']['speaker']]);
    $runTurnWorker($provider);$transferActionRows->execute(['turn'=>$request['turn_id']]);
    $assert($transferActionRows->fetch()!==false,'Cheat required a selected NPC for player-only mutation');
}finally{$db->rollBack();}

// Offered service menus share the frozen vendor/player authority for direct and generated actions.
$db->beginTransaction();
try{
    $serviceNames=\LorkhanServer\Application\ServiceActionPolicy::NAMES;
    foreach($serviceNames as$serviceName)$db->prepare('UPDATE sessions SET enabled_actions=array_append(enabled_actions,:name),capabilities=array_append(capabilities,:cap) WHERE session_id=:session')
        ->execute(['name'=>$serviceName,'cap'=>'action.'.$serviceName,'session'=>$sessionId]);
    foreach(['direct','provider']as$servicePath)foreach($serviceNames as$serviceName){
        $request=$transferTurn();$request['payload']['context']['targetState']['services_known']=true;
        $request['payload']['context']['targetState']['services']=[substr($serviceName,8)];
        if($servicePath==='direct')$request['payload']['action_request']=['name'=>$serviceName,'tier'=>1,'parameters'=>[]];
        [$serviceStatus,$serviceBody]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        $assert($serviceStatus===202,'offered service rejected: '.json_encode([$serviceName,$servicePath,$serviceBody]));
        if($servicePath==='provider'){
            $serviceProvider=$transferProviderFor(['name'=>$serviceName,'tier'=>1,'parameters'=>[],
                'actor'=>$request['payload']['target'],'target'=>$request['payload']['speaker']]);
            $runTurnWorker($serviceProvider);$assert($serviceProvider->calls>0,'service mock provider was bypassed');
        }
        $transferActionRows->execute(['turn'=>$request['turn_id']]);$serviceIntent=$transferActionRows->fetch();
        $assert($serviceIntent&&$serviceIntent['action_name']===$serviceName&&json_decode($serviceIntent['parameters'],true)===[]
            &&json_decode($serviceIntent['actor'],true)==$request['payload']['target']
            &&json_decode($serviceIntent['target'],true)==$request['payload']['speaker'],'offered service lost vendor/player identity or empty parameters: '.$serviceName.' '.$servicePath);
    }
    foreach(['direct','provider']as$servicePath)foreach(['not_offered','unknown','wrong_actor','wrong_player','missing_capability']as$serviceFailure){
        $db->exec('SAVEPOINT invalid_service');$request=$transferTurn();
        $request['payload']['context']['targetState']['services_known']=$serviceFailure!=='unknown';
        $request['payload']['context']['targetState']['services']=$serviceFailure==='not_offered'?['repair']:['barter'];
        $serviceProposal=['name'=>'service.barter','tier'=>1,'parameters'=>[],
            'actor'=>$request['payload']['target'],'target'=>$request['payload']['speaker']];
        if($serviceFailure==='wrong_actor')$serviceProposal['actor']=$transferRecipient;
        if($serviceFailure==='wrong_player')$serviceProposal['target']=$transferRecipient;
        if($serviceFailure==='missing_capability')$db->prepare("UPDATE sessions SET capabilities=array_remove(capabilities,'action.service.barter') WHERE session_id=:session")->execute(['session'=>$sessionId]);
        // Direct requests derive the actor from the actual observed vendor; only providers supply it.
        if($servicePath==='direct'&&$serviceFailure==='wrong_actor'){$db->exec('ROLLBACK TO SAVEPOINT invalid_service');continue;}
        if($servicePath==='direct')$request['payload']['action_request']=array_diff_key($serviceProposal,['actor'=>true]);
        $beforeServices=(int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn();
        [$serviceStatus,$serviceBody]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
        if($servicePath==='provider'){
            $assert($serviceStatus===202,'service rejection fixture was not accepted');
            $serviceProvider=$transferProviderFor($serviceProposal);$runTurnWorker($serviceProvider);
            $assert($serviceProvider->calls>0,'invalid service bypassed mock provider');
        }else $assert($serviceStatus>=400,'invalid direct service accepted: '.$serviceFailure);
        $assert((int)$db->query('SELECT count(*) FROM action_intents')->fetchColumn()===$beforeServices,'invalid service emitted action: '.$serviceFailure.' '.$servicePath);
        $db->exec('ROLLBACK TO SAVEPOINT invalid_service');
    }
    $request=$transferTurn();$request['payload']['speaker']=$transferRecipient;
    $request['payload']['context']['player']=$turn['payload']['speaker'];
    $request['payload']['context']['targetState']['services_known']=true;$request['payload']['context']['targetState']['services']=['barter'];
    $request['payload']['action_request']=['name'=>'service.barter','tier'=>1,'parameters'=>[]];
    [$serviceStatus]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
    $transferActionRows->execute(['turn'=>$request['turn_id']]);$serviceIntent=$transferActionRows->fetch();
    $assert($serviceStatus===202&&$serviceIntent&&json_decode($serviceIntent['target'],true)==$turn['payload']['speaker'],
        'NPC interlocutor replaced the observed service player');
    $request=$transferTurn();$request['payload']['context']['targetState']['services_known']=true;
    $request['payload']['context']['targetState']['services']=[];
    [$serviceStatus]=$call($transferRouter,'POST',$base.'/turns',$headers($request['message_id']),[],$request);
    $assert($serviceStatus===202,'frozen service context fixture rejected');
    $request['payload']['context']['targetState']['services']=['barter'];
    try{$repo->completeTurn($request,['utterances'=>[],'action'=>['name'=>'service.barter','tier'=>1,'parameters'=>[],
        'actor'=>$request['payload']['target'],'target'=>$request['payload']['speaker']]]);$assert(false,'caller invented an unobserved service');}
    catch(DomainException $error){$assert($error->getMessage()==='provider_action_not_allowed','unexpected frozen service error: '.$error->getMessage());}
}finally{$db->rollBack();}

// Director planning persists only leased, scoped instructions; children consume them once in order.
$db->beginTransaction();
try{
    $director=new \LorkhanServer\Infrastructure\DirectorPlanningRepository($db);
    $directorGlobal=$products->globalSettingsForInstallation($installationId);
    $directorContent=$directorGlobal['content']??\LorkhanServer\Application\SettingsCatalog::globalDefaults();
    $directorContent['system_routing']['director_configuration_id']=$modelSlot['configuration_id'];
    $directorContent['task_availability']['director']=true;
    if($directorGlobal)$products->revise('global_settings',$directorGlobal['configuration_id'],$directorContent,'Director test',gmdate('Y-m-d\TH:i:s\Z'));
    else $products->createRevisioned('global_settings',['installation_id'=>$installationId,'name'=>'Director test','content'=>$directorContent],gmdate('Y-m-d\TH:i:s\Z'));
    $directorRequest=$transferTurn();$directorRequest['payload']['execution_mode']='director';
    $directorRequest['payload']['input']['text']='Arrange a uniquely authored Balmora conversation.';
    $directorRequest['payload']['ui_source']='lorkhan_text';
    $directorRequest['payload']['context']['nearbyActors']=['items'=>[$turn['payload']['target'],$transferRecipient]];
    [$directorStatus,$directorAccepted]=$call($transferRouter,'POST',$base.'/turns',$headers($directorRequest['message_id']),[],$directorRequest);
    $assert($directorStatus===202,'Director route rejected: '.json_encode($directorAccepted));
    $directorPlan=$db->prepare('SELECT plan_id FROM director_plans WHERE origin_turn_id=:turn');
    $directorPlan->execute(['turn'=>$directorRequest['turn_id']]);$directorId=$directorPlan->fetchColumn();
    $assert(is_string($directorId),'Director origin did not queue its dedicated plan');
    $directorInput=$director->input($directorId);
    $assert(isset($directorInput['actors']['nearby:1'],$directorInput['actors']['nearby:2'],$directorInput['actors']['player']), 'Director lost frozen actor selectors');
    $directorLease=Uuid::v4();
    $leaseDirector=$db->prepare("UPDATE durable_jobs SET state='leased',attempt_count=1,lease_token=:lease,lease_owner='director-integration',leased_at=clock_timestamp(),heartbeat_at=clock_timestamp(),lease_expires_at=clock_timestamp()+interval '60 seconds' WHERE job_id=:job");
    $leaseDirector->execute(['lease'=>$directorLease,'job'=>$directorId]);
    $directorOutput=['instructions'=>[
        ['actor_id'=>'nearby:1','recipient_id'=>'nearby:2','instruction'=>'Balmora has a long history.','scene_note'=>'A discussion about Balmora.',
            'action'=>['name'=>'ai.wait','parameters'=>['duration_seconds'=>3600]]],
        ['actor_id'=>'nearby:2','recipient_id'=>'player','instruction'=>'What do you think, traveller?','scene_note'=>''],
        ['actor_id'=>'nearby:1','recipient_id'=>'nearby:2','instruction'=>'This must not play after the player is addressed.','scene_note'=>'']]];
    try{$director->deliver($directorId,1,Uuid::v4(),$directorOutput);$assert(false,'stale Director lease delivered');}
    catch(RuntimeException $error){$assert($error->getMessage()==='director_cancelled','wrong Director lease error');}
    $director->deliver($directorId,1,$directorLease,$directorOutput);
    $director->deliver($directorId,1,$directorLease,$directorOutput);
    $directorEvents=$db->prepare('SELECT event_type,count(*) AS total FROM response_events WHERE turn_id=:turn GROUP BY event_type');
    $directorEvents->execute(['turn'=>$directorRequest['turn_id']]);$directorEventCounts=$directorEvents->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert((int)($directorEventCounts['director.instructions']??0)===1&&(int)($directorEventCounts['turn.complete']??0)===1
        &&!isset($directorEventCounts['response.complete'],$directorEventCounts['speech.ready']),'Director redelivery duplicated events or fabricated dialogue');
    $directorRows=$db->prepare('SELECT instruction_id FROM director_instructions WHERE plan_id=:plan ORDER BY ordinal');
    $directorRows->execute(['plan'=>$directorId]);$directorInstructions=$directorRows->fetchAll(PDO::FETCH_COLUMN);
    $assert(count($directorInstructions)===2&&count($director->sceneNotes($sessionId,$directorRequest['generation']))===1,'Director instructions or temporary notes missing');
    $directorChild=$transferTurn();$directorChild['payload']['execution_mode']='standard';
    $directorChild['payload']['director_instruction_id']=$directorInstructions[1];$directorChild['payload']['target']=$transferRecipient;
    try{$director->prepareChild($directorChild);$assert(false,'Director allowed out-of-order child');}
    catch(RuntimeException $error){$assert($error->getMessage()==='director_previous_child_pending','wrong Director sequence error');}
    $directorChild['payload']['director_instruction_id']=$directorInstructions[0];$directorChild['payload']['target']=$turn['payload']['target'];
    $directorChild['payload']['speaker']=$transferRecipient;
    $directorChild['payload']['input']['text']='Client-substituted planner instructions';
    [$childStatus,$childAccepted]=$call($transferRouter,'POST',$base.'/turns',$headers($directorChild['message_id']),[],$directorChild);
    $assert($childStatus===202,'Director child rejected: '.json_encode($childAccepted));
    $childText=$db->prepare('SELECT input_text FROM turns WHERE turn_id=:turn');$childText->execute(['turn'=>$directorChild['turn_id']]);
    $assert($childText->fetchColumn()==='Balmora has a long history.','Director trusted substituted child instruction text');
    $duplicateChild=$directorChild;$duplicateChild['turn_id']=Uuid::v4();
    try{$director->prepareChild($duplicateChild);$assert(false,'Director instruction reused by another turn');}
    catch(RuntimeException $error){$assert($error->getMessage()==='director_instruction_unavailable','wrong Director single-consumption error');}
    $authored=$director->prepareChild($directorChild)['authored_response'];
    $assert($authored['utterances'][0]['text']==='Balmora has a long history.'&&$authored['utterances'][0]['addressee']==$transferRecipient,'Director lost its trusted authored response');
    (new ReflectionMethod($repo,'validateProviderResult'))->invoke($repo,$authored,$repo->session($sessionId,$directorChild['generation']),$repo->turnMessage($directorChild['turn_id']));
    $db->exec('SAVEPOINT director_policy_revocation');
    try{
        $directorActorProfile=$products->effectiveSettingsForActor($installationId,$session['playthrough_id'],$directorChild['payload']['target'])['npc_profile']['profile_id']??null;
        $assert(is_string($directorActorProfile),'Director actor policy fixture has no bound profile');
        $products->createRevisioned('action_policy',['installation_id'=>$installationId,'profile_id'=>$directorActorProfile,
            'name'=>'Director policy revocation fixture','content'=>['enabled'=>true,'denied_actions'=>['ai.wait']]],$now);
        try{(new ReflectionMethod($repo,'validateProviderResult'))->invoke($repo,$authored,$repo->session($sessionId,$directorChild['generation']),$repo->turnMessage($directorChild['turn_id']));$assert(false,'Director executed an NPC action revoked after scene planning');}
        catch(DomainException $error){$assert($error->getMessage()==='action_disabled','Director fresh actor policy returned unexpected error');}
    }finally{$db->exec('ROLLBACK TO SAVEPOINT director_policy_revocation');}
    $directorNoModel=new class implements Provider {
        public int $calls=0;
        public function complete(array $message,\LorkhanServer\Application\CancellationToken $token):array {++$this->calls;throw new RuntimeException('Director child must not call a dialogue model');}
    };
    $runTurnWorker($directorNoModel);
    $assert($directorNoModel->calls===0,'Director child asked another model to rewrite authored speech');
    $authoredText=$db->prepare('SELECT text FROM dialogue_utterances WHERE turn_id=:turn ORDER BY utterance_index');
    $authoredText->execute(['turn'=>$directorChild['turn_id']]);
    $directorFailure=$db->prepare("SELECT payload FROM response_events WHERE turn_id=:turn AND event_type='turn.failed'");$directorFailure->execute(['turn'=>$directorChild['turn_id']]);
    $assert($authoredText->fetchColumn()==='Balmora has a long history.','Director child did not publish its exact authored line: '.(string)$directorFailure->fetchColumn());
    $authoredAction=$db->prepare("SELECT payload FROM response_events WHERE turn_id=:turn AND event_type='action.intent'");
    $authoredAction->execute(['turn'=>$directorChild['turn_id']]);$authoredActionPayload=json_decode((string)$authoredAction->fetchColumn(),true);
    $assert(($authoredActionPayload['name']??null)==='ai.wait'&&($authoredActionPayload['parameters']??null)===['duration_seconds'=>3600]
        &&\LorkhanServer\Application\TransferActionPolicy::sameIdentity($authoredActionPayload['target']??null,$transferRecipient),'Director action lost its parameters or spoken recipient target');
    $falseInput=$db->prepare("SELECT count(*) FROM eventlog e JOIN eventlog_metadata m USING(rowid) WHERE m.turn_id=:turn AND e.type='inputtext'");
    $falseInput->execute(['turn'=>$directorChild['turn_id']]);$assert((int)$falseInput->fetchColumn()===0,'Director authored words were projected as listener input');
    foreach(['profileBackfillHistory','narratorEvolutionHistory'] as $historyMethod){
        $historyArgs=[$installationId,$session['playthrough_id']];if($historyMethod==='profileBackfillHistory')$historyArgs[]=$directorChild['payload']['target'];$historyArgs[]=400;
        $history=(new ReflectionMethod($products,$historyMethod))->invokeArgs($products,$historyArgs);
        $directorHistory=array_values(array_filter($history['recent_events'],static fn(array $event):bool=>$event['turn_id']===$directorChild['turn_id']));
        $assert(count($directorHistory)===1&&!isset($directorHistory[0]['player_input'])&&$directorHistory[0]['scene_event']===''
            &&str_contains(implode(' ',$directorHistory[0]['npc_responses']),'Balmora has a long history.'),'Director history invented player speech or removed genuine NPC dialogue');
    }
    $assert(!in_array($directorRequest['payload']['input']['text'],$products->recentPlayerInputs($installationId,200),true),'Director off-stage direction contaminated player speech-style samples');
    $nextChild=$transferTurn();$nextChild['payload']['execution_mode']='standard';
    $nextChild['payload']['director_instruction_id']=$directorInstructions[1];$nextChild['payload']['target']=$transferRecipient;
    $assert($director->prepareChild($nextChild)['instruction']==='What do you think, traveller?','Director second child remained blocked after terminal first child');
    $wrongChild=$nextChild;$wrongChild['payload']['target']=$turn['payload']['target'];
    try{$director->prepareChild($wrongChild);$assert(false,'Director substituted actor accepted');}
    catch(RuntimeException $error){$assert($error->getMessage()==='director_actor_mismatch','wrong Director actor error');}
    $director->cancel($sessionId,$directorRequest['generation']);
    $assert($director->sceneNotes($sessionId,$directorRequest['generation'])===[],'cancelled Director notes remained visible');
    try{$director->prepareChild($nextChild);$assert(false,'cancelled Director child accepted');}
    catch(RuntimeException $error){$assert($error->getMessage()==='director_instruction_unavailable','wrong cancelled Director child error');}
}finally{$db->rollBack();}

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
$eligibleProbe['payload']['context']['targetState']=['inventory'=>['items'=>[['record_id'=>'iron_dagger','count'=>1]],'total'=>1,'truncated'=>false],
    'identity'=>['race'=>'dark elf'],'stats'=>['level'=>12]];
$eligibleResolved=$rechatCoordinator->resolve($eligibleProbe);
$assert(!isset($eligibleResolved['payload']['context']['targetState']),
    'Rechat assigned the original target inventory, race or stats to a different responder');
$sameActorProbe=$eligibleProbe;$sameActorProbe['payload']['target']=$thirdTarget;
$sameActorResolved=$rechatCoordinator->resolve($sameActorProbe);
$assert($sameActorResolved['payload']['target']===$thirdTarget
    &&$sameActorResolved['payload']['context']['targetState']===$sameActorProbe['payload']['context']['targetState'],
    'Rechat discarded fresh context for the same exact responder');
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
$settingsHandshakeExpected=$settingsDocument;$settingsHandshakeExpected['behavior']['ai_enabled']=true;
$assert($status===201&&$configuredAccepted['config_revision']===$configuredRevision
    &&$configuredAccepted['client_settings']==$settingsHandshakeExpected&&filter_var($restoredSessionProfile->fetchColumn(),FILTER_VALIDATE_BOOL),
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
    $assert($status===201&&$legacyAccepted['client_settings']==$settingsHandshakeExpected
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
    $observedTurn=$db->query("SELECT t.turn_id,t.target,s.installation_id,s.session_id,s.playthrough_id,s.generation FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE t.target->>'kind'='npc' AND t.target->>'content_file' IS NOT NULL ORDER BY t.accepted_at LIMIT 1")->fetch();
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
    // Standalone inventory updates feed this same exact-NPC view and only fill missing turn context.
    $db->prepare("UPDATE sessions SET state='replaced' WHERE installation_id=:installation AND state='active'")
        ->execute(['installation'=>$observedTurn['installation_id']]);
    $db->prepare("UPDATE sessions SET state='active',ended_at=NULL WHERE session_id=:session")
        ->execute(['session'=>$observedTurn['session_id']]);
    $inventoryScope=array_intersect_key($observedTurn,array_flip(['installation_id','session_id','playthrough_id','generation']));
    $inventoryScope['generation']=(int)$inventoryScope['generation'];
    $inventoryMessage=$inventoryScope+['schema'=>'lorkhan.gamedata.v1','game'=>'tes3','runtime_generation'=>1,
        'request_id'=>\LorkhanServer\Infrastructure\Uuid::v4(),'observed_at'=>'2099-01-01T00:00:01Z','type'=>'inventory',
        'payload'=>['owner'=>$identity,'items'=>[['record_id'=>'inventory_live','name'=>'Inventory <safe>','count'=>2,
            'value'=>10,'equipped'=>false]]]];
    $otherStack=$inventoryMessage['payload']['items'][0];$otherStack['record_id']='INVENTORY_LIVE';
    $otherStack['count']=3;$otherStack['equipped']=true;$otherStack['condition']=0.5;
    $inventoryMessage['payload']['items'][]=$otherStack;
    $repo->acceptGameData($inventoryMessage);
    $db->prepare("UPDATE source_events SET received_at='2099-01-01T00:00:02Z' WHERE source_event_id=:id")
        ->execute(['id'=>$inventoryMessage['request_id']]);
    $liveInventory=$products->npcObservedState($observedTurn['installation_id'],$observedProfile['profile_id']);
    $assert($liveInventory['state']['inventory']=== [['record_id'=>'inventory_live','display_name'=>'Inventory <safe>','count'=>5]]
        &&$liveInventory['state']['inventory_observation']===['total'=>1,'truncated'=>false]
        &&$liveInventory['state']['stats']===$inventoryObservation['state']['stats']
        &&$liveInventory['inventory_source_event_id']===$inventoryMessage['request_id'],
        'standalone inventory did not replace only older inventory with safe item names and receipt provenance');
    $inventoryTurn=$inventoryScope+['payload'=>['target'=>$identity,'context'=>['targetState'=>['stats'=>['level'=>5]]]]];
    $enriched=$products->enrichTurnInventory($inventoryTurn);
    $assert($enriched['payload']['context']['targetState']['inventory']['items'][0]['record_id']==='inventory_live'
        &&$enriched['_inventory_observation']['source_event_id']===$inventoryMessage['request_id'],
        'missing inventory did not receive exact-session fallback');
    $caseTurn=$inventoryTurn;$caseTurn['payload']['target']['kind']='actor';
    foreach(['record_id','content_file']as$field)$caseTurn['payload']['target'][$field]=strtoupper($identity[$field]);
    $assert($products->enrichTurnInventory($caseTurn)['payload']['context']['targetState']['inventory']===$enriched['payload']['context']['targetState']['inventory'],
        'case-only identity spelling or the legacy actor alias hid exact-NPC inventory');
    foreach([[],['items'=>[]],['items'=>[['record_id'=>'current_inventory']]]]as$currentInventory){
        $currentTurn=$inventoryTurn;$currentTurn['payload']['context']['targetState']['inventory']=$currentInventory;
        $assert($products->enrichTurnInventory($currentTurn)===$currentTurn,'current-turn inventory, including explicit empty, lost precedence');
    }
    foreach(['session_id','playthrough_id','installation_id','generation']as$scopeField){
        $wrongScope=$inventoryTurn;$wrongScope[$scopeField]=$scopeField==='generation'?$inventoryScope['generation']+1:\LorkhanServer\Infrastructure\Uuid::v4();
        $assert($products->enrichTurnInventory($wrongScope)===$wrongScope,'inventory fallback crossed '.$scopeField);
    }
    foreach([$otherIdentity,array_replace($identity,['kind'=>'player'])]as$wrongOwner){
        $wrongTurn=$inventoryTurn;$wrongTurn['payload']['target']=$wrongOwner;
        $assert($products->enrichTurnInventory($wrongTurn)===$wrongTurn,'inventory fallback crossed exact owner identity');
    }
    $db->prepare('INSERT INTO timeline_invalidated_sources(source_event_id,loaded_save_id,cutoff_minute) VALUES(:source,:load,0)')
        ->execute(['source'=>$inventoryMessage['request_id'],'load'=>$inventoryMessage['request_id']]);
    $assert($products->enrichTurnInventory($inventoryTurn)===$inventoryTurn,'invalidated inventory source returned to active context');
    $db->prepare('DELETE FROM timeline_invalidated_sources WHERE source_event_id=:source')->execute(['source'=>$inventoryMessage['request_id']]);
    $db->prepare("UPDATE sessions SET state='replaced' WHERE session_id=:session")->execute(['session'=>$observedTurn['session_id']]);
    $assert($products->enrichTurnInventory($inventoryTurn)===$inventoryTurn,'inventory from a replaced save/session remained available');
    $db->prepare("UPDATE sessions SET state='active' WHERE session_id=:session")->execute(['session'=>$observedTurn['session_id']]);
    $emptyInventory=$inventoryMessage;$emptyInventory['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();$emptyInventory['payload']['items']=[];
    $repo->acceptGameData($emptyInventory);
    $db->prepare("UPDATE source_events SET received_at='2099-01-01T00:00:03Z' WHERE source_event_id=:id")
        ->execute(['id'=>$emptyInventory['request_id']]);
    $emptyObserved=$products->npcObservedState($observedTurn['installation_id'],$observedProfile['profile_id']);
    $assert($emptyObserved['state']['inventory']===[]&&$emptyObserved['state']['inventory_observation']===['total'=>0,'truncated'=>false]
        &&$products->enrichTurnInventory($inventoryTurn)['payload']['context']['targetState']['inventory']['items']===[],
        'new observed empty inventory resurrected older items');
    $onlyInventory=$inventoryMessage;$onlyInventory['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();$onlyInventory['payload']['owner']=$otherIdentity;
    $onlyInventory['payload']['items']=[];
    for($index=0;$index<512;$index++)$onlyInventory['payload']['items'][]=array_replace($inventoryMessage['payload']['items'][0],['record_id'=>'bounded_item_'.$index]);
    $repo->acceptGameData($onlyInventory);
    $onlyObserved=$products->npcObservedState($observedTurn['installation_id'],$otherReference['profile_id']);
    $assert(count($onlyObserved['state']['inventory'])===128&&$onlyObserved['state']['inventory_observation']===['total'=>512,'truncated'=>true]
        &&!isset($onlyObserved['state']['stats']),'inventory-only NPC Info was missing, unbounded, or claimed a complete capped snapshot');
    $sessionProfile=$db->query("SELECT profile_id FROM sessions WHERE session_id='{$observedTurn['session_id']}'")->fetchColumn();
    $nextPlaythrough=$products->createRevisioned('playthrough',['installation_id'=>$observedTurn['installation_id'],
        'profile_id'=>$sessionProfile,'name'=>'Inventory new playthrough','content'=>[]],$now);
    $nextSession=\LorkhanServer\Infrastructure\Uuid::v4();
    $nextGeneration=(int)$db->query("SELECT max(generation)+1 FROM sessions WHERE installation_id='{$observedTurn['installation_id']}'")->fetchColumn();
    $db->prepare("UPDATE sessions SET state='replaced' WHERE session_id=:session")->execute(['session'=>$observedTurn['session_id']]);
    $db->prepare("INSERT INTO sessions SELECT (jsonb_populate_record(NULL::sessions,to_jsonb(s)||jsonb_build_object('session_id',CAST(:next AS text),'generation',CAST(:generation AS bigint),'playthrough_id',CAST(:playthrough AS text),'state','active'))).* FROM sessions s WHERE session_id=:previous")
        ->execute(['next'=>$nextSession,'generation'=>$nextGeneration,'playthrough'=>$nextPlaythrough['playthrough_id'],'previous'=>$observedTurn['session_id']]);
    $nextInventory=$inventoryMessage;$nextInventory['request_id']=\LorkhanServer\Infrastructure\Uuid::v4();
    $nextInventory['session_id']=$nextSession;$nextInventory['generation']=$nextGeneration;$nextInventory['playthrough_id']=$nextPlaythrough['playthrough_id'];
    $repo->acceptGameData($nextInventory);
    $nextObserved=$products->npcObservedState($observedTurn['installation_id'],$observedProfile['profile_id']);
    $assert($nextObserved['playthrough_name']==='Inventory new playthrough'&&!isset($nextObserved['state']['stats'])
        &&$nextObserved['inventory_source_event_id']===$nextInventory['request_id']
        &&$nextObserved['observed_at']===$nextObserved['inventory_observed_at'],
        'fresh inventory was combined with another playthrough stats or mislabelled observation time');

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
// File retention previews are explicit, bounded and independent of live gameplay records.
$retentionRoot=sys_get_temp_dir().'/lorkhan-retention-'.bin2hex(random_bytes(8));
$retentionConfig=['backup_storage_path'=>$retentionRoot];$retentionFiles=[];
$db->beginTransaction();
try{
    $retentionIds=[];$retentionInsert=$db->prepare("INSERT INTO backup_records(backup_id,format_version,content_sha256,byte_count,scope,state,created_at) VALUES(:id,1,:hash,4,CAST(:scope AS jsonb),'created','2000-01-01T00:00:00Z')");
    foreach(['eligible','default','active','pending']as$kind){
        $id=Uuid::v4();$retentionIds[$kind]=$id;
        $retentionInsert->execute(['id'=>$id,'hash'=>hash('sha256','test'),'scope'=>json_encode(['kind'=>'database_sql','snapshot'=>['name'=>$kind]])]);
        $path=(new \LorkhanServer\Infrastructure\DatabaseSqlBackup($retentionConfig))->path($id);file_put_contents($path,'test');$retentionFiles[$kind]=$path;
    }
    $db->prepare('UPDATE lorkhan_internal.database_snapshot_source SET backup_id=:id WHERE singleton')->execute(['id'=>$retentionIds['active']]);
    $db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload) VALUES(:id,'database.restore',1,:key,CAST(:payload AS jsonb))")
        ->execute(['id'=>Uuid::v4(),'key'=>'retention-'.Uuid::v4(),'payload'=>json_encode(['backup_id'=>$retentionIds['pending']])]);
    $retention=new \LorkhanServer\Infrastructure\BackupFileRetention($db);$plan=$retention->preview(3650,gmdate('Y-m-d\TH:i:s\Z'));
    $assert(array_column($plan['files'],'backup_id')===[$retentionIds['eligible']],'retention preview included protected archives');
    $db->prepare('UPDATE backup_records SET byte_count=5 WHERE backup_id=:id')->execute(['id'=>$retentionIds['eligible']]);
    try{$retention->confirm(3650,$plan['cutoff'],$plan['token'],$retentionConfig);$assert(false,'changed retention preview accepted');}
    catch(RuntimeException $error){$assert($error->getMessage()==='retention_preview_changed','wrong retention stale-preview error');}
    $assert(is_file($retentionFiles['eligible']),'stale preview deleted a file');
    $plan=$retention->preview(3650,$plan['cutoff']);$beforeGameplay=(int)$db->query('SELECT count(*) FROM source_events')->fetchColumn();
    $deleted=$retention->confirm(3650,$plan['cutoff'],$plan['token'],$retentionConfig);
    $assert($deleted['deleted']===[$retentionIds['eligible']]&&!is_file($retentionFiles['eligible']),'confirmed retention did not delete exact preview');
    $assert((int)$db->query('SELECT count(*) FROM source_events')->fetchColumn()===$beforeGameplay,'retention deleted gameplay data');
    foreach(['default','active','pending']as$kind)$assert(is_file($retentionFiles[$kind]),'retention deleted a protected file');
}finally{
    $db->rollBack();foreach($retentionFiles as$file)if(is_file($file))unlink($file);
    if(is_dir($retentionRoot.'/sql'))rmdir($retentionRoot.'/sql');if(is_dir($retentionRoot))rmdir($retentionRoot);
}

$dragonRoot=sys_get_temp_dir().'/lorkhan-dragon-'.bin2hex(random_bytes(8));
$dragonConfig=['database_dsn'=>$dsn,'database_user'=>getenv('LORKHAN_TEST_DB_USER')?:'',
    'database_password'=>getenv('LORKHAN_TEST_DB_PASSWORD')?:'','backup_storage_path'=>$dragonRoot];
$dragonCapture=new \LorkhanServer\Infrastructure\DragonBreakSnapshot($dragonConfig);
$dragonCount=static fn():int=>(int)$db->query("SELECT count(*) FROM playthrough_saves WHERE kind='dragon_break'")->fetchColumn();
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
    $dragonRecord=$db->query("SELECT save_id,name,document FROM playthrough_saves WHERE kind='dragon_break' ORDER BY created_at DESC LIMIT 1")->fetch();
    $document=json_decode($dragonRecord['document'],true,64,JSON_THROW_ON_ERROR);
    $assert($document['format']==='lorkhan.playthrough-save' && str_starts_with($dragonRecord['name'],'Dragon Break ('),'gameplay save missing');
    $capturedOldState=null;
    foreach($document['tables']['lorkhan_internal.sessions'] as $savedSession) {
        if ($savedSession['session_id']===$dragonCurrent) $capturedOldState=$savedSession['state'];
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
    $managerSession=$db->query("SELECT * FROM sessions WHERE session_id=".$db->quote($browserSession))->fetch();
    $managerIdentity=json_decode($db->query("SELECT target FROM turns WHERE target->>'kind'='npc' AND target->>'content_file' IS NOT NULL ORDER BY accepted_at LIMIT 1")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $managerProfile=$products->createRevisioned('profile',['installation_id'=>$managerSession['installation_id'],'name'=>'NPC manager regression','actor_identity'=>$managerIdentity,'content'=>[]],$now);
    $managerId=$managerProfile['profile_id'];
    $assert($products->npcManagerStatus($managerId)['reason_code']==='npc_manager_profile_not_bound','NPC manager guessed an unbound profile target');
    $products->bindActorProfile($managerSession,$managerIdentity,$managerId,$now);
    $db->prepare("UPDATE sessions SET capabilities=array_remove(capabilities,'debug.npc_manager.v1') WHERE session_id=:id")->execute(['id'=>$browserSession]);
    try{$products->queueNpcManagerCommand($managerId,'visit');$assert(false,'old client accepted NPC manager');}
    catch(InvalidArgumentException $error){$assert($error->getMessage()==='npc_manager_unsupported','unexpected NPC manager capability error');}
    $db->prepare("UPDATE sessions SET capabilities=array_append(capabilities,'debug.npc_manager.v1') WHERE session_id=:id")->execute(['id'=>$browserSession]);
    $assert($products->npcManagerStatus($managerId)['observed']===null,'NPC manager invented saved Return state');
    $products->setNpcRecordId($managerId,$managerSession['installation_id'],'edited_profile_record');
    $products->setNpcName($managerId,$managerSession['installation_id'],'Edited profile label');
    $assert($products->npcManagerStatus($managerId)['actor']===$managerIdentity,'NPC manager used editable profile metadata instead of the bound actor');

    foreach(['status','visit','teleport','return']as$operation){
        $managerCommand=$products->queueNpcManagerCommand($managerId,$operation);
        $assert($managerCommand['state']==='queued'&&$managerCommand['name']==='npc.'.$operation&&$managerCommand['parameters']===['actor'=>$managerIdentity],
            'NPC manager changed exact actor or claimed queued success');
        if($operation!=='status'){
            try{$products->queueNpcManagerCommand($managerId,'visit');$assert(false,'NPC manager queued concurrent actor movements');}
            catch(InvalidArgumentException $error){$assert($error->getMessage()==='npc_manager_command_pending','unexpected duplicate actor command error');}
        }
        if($operation!=='return')$db->prepare("UPDATE debug_commands SET state='expired',completed_at=clock_timestamp(),reason_code='fixture_expired' WHERE command_id=:id")
            ->execute(['id'=>$managerCommand['command_id']]);
    }
    try{$products->queueDebugCommand($browserSession,'npc.visit',['actor'=>$managerIdentity,'target'=>'selected']);$assert(false,'NPC manager accepted target override');}
    catch(InvalidArgumentException $error){$assert($error->getMessage()==='invalid_debug_parameters','unexpected NPC manager actor validation error');}
    $db->prepare("UPDATE debug_commands SET state='expired',completed_at=clock_timestamp(),reason_code='fixture_expired' WHERE session_id=:session AND state IN ('queued','delivered') AND command_id<>:command")
        ->execute(['session'=>$browserSession,'command'=>$managerCommand['command_id']]);
    $claimed=$repo->claimDebugCommand(['session_id'=>$browserSession,'generation'=>(int)$managerSession['generation']]);
    $assert($claimed['command_id']===$managerCommand['command_id'],'NPC manager command did not reach existing typed queue');
    $managerReceipt=['session_id'=>$browserSession,'generation'=>(int)$managerSession['generation'],'command_id'=>$managerCommand['command_id'],
        'message_id'=>\LorkhanServer\Infrastructure\Uuid::v4(),'status'=>'succeeded','reason_code'=>'completed','completed_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        'observed'=>['actor_available'=>true,'return_available'=>false,'record_id'=>$managerIdentity['record_id'],'cell'=>'Balmora']];
    $repo->completeDebugCommand($managerReceipt);
    $managerStatus=$products->npcManagerStatus($managerId);
    $assert($managerStatus['observed']==$managerReceipt['observed']&&$managerStatus['items'][0]['state']==='succeeded',
        'NPC manager lost terminal save-backed Return receipt');
    $db->prepare("UPDATE sessions SET state='replaced' WHERE session_id=:session")->execute(['session'=>$browserSession]);
    $assert($products->npcManagerStatus($managerId)['reason_code']==='npc_manager_no_active_session','NPC manager reused a replaced session');
    $db->prepare("UPDATE sessions SET state='active' WHERE session_id=:session")->execute(['session'=>$browserSession]);
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


// NPC evolution reports are separate, frozen read-only results, including for locked NPCs.
$reportInstallation=\LorkhanServer\Infrastructure\Uuid::v4();
$db->prepare('INSERT INTO installations(installation_id,token_fingerprint) VALUES(:id,:token)')->execute(['id'=>$reportInstallation,'token'=>hash('sha256',$reportInstallation)]);
$reportProducts=new \LorkhanServer\Infrastructure\ProductRepository($db);$reportNow=gmdate('c');
$reportNpc=$reportProducts->createRevisioned('profile',['installation_id'=>$reportInstallation,'name'=>'Report NPC','actor_identity'=>['kind'=>'actor','record_id'=>'report_npc'],'content'=>['biography'=>'Born in Balmora','personality'=>'Reserved','management'=>['locked'=>true]]],$reportNow);
$reportNpcId=$reportNpc['profile_id'];$reports=new \LorkhanServer\Infrastructure\NpcEvolutionReportRepository($db);
try{$reports->enqueue($reportInstallation,$reportNpcId,\LorkhanServer\Infrastructure\Uuid::v4());$assert(false,'disabled report connector queued');}catch(InvalidArgumentException $e){$assert($e->getMessage()==='report_connector_disabled','wrong report disabled error');}
$reportProvider=$reportProducts->createRevisioned('provider',['installation_id'=>$reportInstallation,'name'=>'Mock report connector','content'=>['driver'=>'mock','model'=>'report-test']],$reportNow);
$reportProducts->createRevisioned('memory_policy',['installation_id'=>$reportInstallation,'name'=>'Report summary policy','content'=>['schema'=>'lorkhan.memory-policy.v1','enabled'=>false,'provider_configuration_id'=>$reportProvider['configuration_id']]],$reportNow);
$backgroundProvider=$reportProducts->createRevisioned('provider',['installation_id'=>$reportInstallation,'name'=>'Background report connector','content'=>['driver'=>'mock','model'=>'background-report-test']],$reportNow);
$reportGlobals=\LorkhanServer\Application\SettingsCatalog::globalDefaults();$reportGlobals['system_routing']['background_memory_configuration_id']=$backgroundProvider['configuration_id'];
$reportGlobal=$reportProducts->createRevisioned('global_settings',['installation_id'=>$reportInstallation,'name'=>'Global Settings','content'=>$reportGlobals],$reportNow);
try{$reportProducts->deleteRevisioned('provider',$backgroundProvider['configuration_id'],$reportNow);$assert(false,'background connector deleted while selected');}catch(InvalidArgumentException $e){$assert($e->getMessage()==='provider_in_use','wrong selected background connector error');}

$content=$reportNpc['content'];$content['biography']='Same personality, later biography';$reportProducts->revise('profile',$reportNpcId,$content,'test',$reportNow);
$content['personality']='Outgoing';$reportProducts->revise('profile',$reportNpcId,$content,'test',$reportNow);
$requestId=\LorkhanServer\Infrastructure\Uuid::v4();$reportQueued=$reports->enqueue($reportInstallation,$reportNpcId,$requestId);
$assert($reports->enqueue($reportInstallation,$reportNpcId,$requestId)['job_id']===$reportQueued['job_id'],'report retry duplicated work');
$reportInput=$reports->input($reportInstallation,$reportNpcId,$reportQueued['job_id']);
$assert(count($reportInput['history'])===2&&$reportInput['history'][0]['revision']===1&&$reportInput['history'][1]['revision']===3,'report history order/dedup');
$content['personality']='Changed while report queued';$reportProducts->revise('profile',$reportNpcId,$content,'test',$reportNow);
$assert($reports->input($reportInstallation,$reportNpcId,$reportQueued['job_id'])===$reportInput,'report input changed after enqueue');
$reportJobs=new \LorkhanServer\Infrastructure\JobRepository($db);$claimed=$reportJobs->claim('report-test',1,60,['profile.report'])[0];
$assert($claimed['job_id']===$reportQueued['job_id']&&$claimed['max_attempts']===1,'report claim/attempt bounds');
$assert($claimed['payload']['provider_configuration_id']===$backgroundProvider['configuration_id'],'report used Summaries instead of Background Tasks');
$reportHandler=new \LorkhanServer\Application\NpcEvolutionReportJobHandler($reports,$reportProducts,new \LorkhanServer\Infrastructure\ProviderAttemptRepository($db));
// Availability switches retain selected routes and stop both enqueue and already queued provider work.
$taskNpc=$reportProducts->createRevisioned('profile',['installation_id'=>$reportInstallation,'name'=>'Task availability NPC','actor_identity'=>['kind'=>'actor'],'content'=>[]],$reportNow);
try{$reportProducts->enqueueProfileGeneration($taskNpc['profile_id']);$assert(false,'unassigned profile tasks queued');}catch(InvalidArgumentException $e){$assert($e->getMessage()==='profile_generation_connector_unavailable','wrong missing profile connector error');}
$taskHandler=new \LorkhanServer\Application\ProfileGenerateJobHandler($reportProducts,null);
try{$taskHandler->handle(['profile_id'=>$taskNpc['profile_id'],'base_revision'=>1,'_job'=>['job_id'=>\LorkhanServer\Infrastructure\Uuid::v4(),'attempt'=>1]],'test',static fn()=>true);$assert(false,'legacy profile job used runtime provider');}catch(RuntimeException $e){$assert($e->getMessage()==='profile_generation_connector_unavailable','wrong missing queued profile connector error');}
$reportGlobals['system_routing']['profile_generation_configuration_id']=$reportProvider['configuration_id'];
$reportProducts->revise('global_settings',$reportGlobal['configuration_id'],$reportGlobals,'test',$reportNow);
$taskJob=$reportProducts->enqueueProfileGeneration($taskNpc['profile_id']);
$taskOff=$reportGlobals;$taskOff['task_availability']=['background_memory'=>false,'profile_generation'=>false];
$reportProducts->revise('global_settings',$reportGlobal['configuration_id'],$taskOff,'test',$reportNow);
$assert($reportProducts->globalSettingsForInstallation($reportInstallation)['content']['system_routing']['background_memory_configuration_id']===$backgroundProvider['configuration_id'],'availability switch cleared connector');
try{$reports->enqueue($reportInstallation,$reportNpcId,\LorkhanServer\Infrastructure\Uuid::v4());$assert(false,'disabled background tasks queued report');}catch(InvalidArgumentException $e){$assert($e->getMessage()==='report_connector_disabled','wrong report availability error');}
try{$reportHandler->handle($claimed['payload']+['_job'=>['job_id'=>$claimed['job_id'],'attempt'=>$claimed['attempt_count'],'lease_token'=>$claimed['lease_token']]],$claimed['idempotency_key'],static fn()=>true);$assert(false,'disabled report job executed');}catch(RuntimeException $e){$assert($e->getMessage()==='report_connector_disabled','wrong queued report availability error');}
try{$reportProducts->enqueueProfileGeneration($taskNpc['profile_id']);$assert(false,'disabled profile tasks queued');}catch(InvalidArgumentException $e){$assert($e->getMessage()==='profile_tasks_disabled','wrong profile availability error');}
$taskHandler=new \LorkhanServer\Application\ProfileGenerateJobHandler($reportProducts,null);
try{$taskHandler->handle(['profile_id'=>$taskNpc['profile_id'],'base_revision'=>1,'_job'=>['job_id'=>$taskJob['job_id'],'attempt'=>1]],'test',static fn()=>true);$assert(false,'disabled profile job executed');}catch(RuntimeException $e){$assert($e->getMessage()==='profile_tasks_disabled','wrong queued profile availability error');}
$taskPlan=$reportProducts->globalConnectorTestPlan($reportInstallation);
foreach($taskPlan['groups'][0]['slots']as$taskSlot)if(in_array($taskSlot['field'],['background_memory_configuration_id','profile_generation_configuration_id'],true))$assert($taskSlot['status']==='skipped'&&$taskSlot['message']==='Task disabled in saved settings','disabled task connector test was scheduled');
$reportProducts->revise('global_settings',$reportGlobal['configuration_id'],$reportGlobals,'test',$reportNow);

// A queued report still protects its connector after the global selector is cleared.
$reportDisabled=$reportGlobals;$reportDisabled['system_routing']['background_memory_configuration_id']='';
$reportProducts->revise('global_settings',$reportGlobal['configuration_id'],$reportDisabled,'test',$reportNow);
try{$reportProducts->deleteRevisioned('provider',$backgroundProvider['configuration_id'],$reportNow);$assert(false,'queued report connector deleted');}catch(InvalidArgumentException $e){$assert($e->getMessage()==='provider_in_use','wrong pending report connector error');}
$reportProducts->revise('global_settings',$reportGlobal['configuration_id'],$reportGlobals,'test',$reportNow);
$reportHandler->handle($claimed['payload']+['_job'=>['job_id'=>$claimed['job_id'],'attempt'=>$claimed['attempt_count'],'lease_token'=>$claimed['lease_token']]],$claimed['idempotency_key'],static fn()=>true);
$assert($reports->status($reportInstallation,$reportNpcId,$claimed['job_id'])['report']===null,'uncommitted report exposed');
$reportJobs->succeed($claimed['job_id'],$claimed['lease_token']);$reportStatus=$reports->status($reportInstallation,$reportNpcId,$claimed['job_id']);
$assert($reportStatus['state']==='succeeded'&&str_contains($reportStatus['report'],'2 distinct personality snapshots')&&$reportStatus['base_revision']===3,'report result not persisted');
$assert($reportProducts->getRevisioned('profile',$reportNpcId)['current_revision']===4,'report changed NPC profile');
try{$reports->save($claimed['job_id'],1,$claimed['lease_token'],'late result');$assert(false,'expired report lease wrote');}catch(RuntimeException $e){$assert($e->getMessage()==='lease_lost','wrong report fence error');}
try{$reports->status('00000000-0000-4000-8000-000000000001',$reportNpcId,$claimed['job_id']);$assert(false,'cross-installation report exposed');}catch(RuntimeException){$assert(true,'report scope rejection');}

// Master AI Off retires output, not the session, STT or passive game observations.
$aiQuery=$db->prepare("SELECT * FROM sessions WHERE installation_id=:installation AND state='active' ORDER BY created_at DESC LIMIT 1");
$aiQuery->execute(['installation'=>$installationId]);$aiSession=$aiQuery->fetch();$assert((bool)$aiSession,'AI fixture needs active session');
$aiTurn=$fixture('turn');foreach(['installation_id','profile_id','playthrough_id','content_fingerprint','session_id']as$key)$aiTurn[$key]=$aiSession[$key];
$aiTurn['generation']=(int)$aiSession['generation'];foreach(['message_id','request_id','turn_id']as$key)$aiTurn[$key]=Uuid::v4();
$repo->acceptTurn($aiTurn);
$aiDialogue=Uuid::v4();
$db->prepare("INSERT INTO dialogue_utterances(dialogue_message_id,response_line_id,utterance_id,session_id,turn_id,request_id,generation,utterance_index,utterance_count,speaker,addressee,audience,text,emitted_at,delivery_deadline_at) VALUES(:id,:id,:id,:session,:turn,:request,:generation,1,1,CAST(:speaker AS jsonb),CAST(:addressee AS jsonb),'[]','Cancelled fixture speech',clock_timestamp(),clock_timestamp()+interval '5 minutes')")
 ->execute(['id'=>$aiDialogue,'session'=>$aiTurn['session_id'],'turn'=>$aiTurn['turn_id'],'request'=>$aiTurn['request_id'],'generation'=>$aiTurn['generation'],'speaker'=>json_encode($aiTurn['payload']['target']),'addressee'=>json_encode($aiTurn['payload']['speaker'])]);
$aiStt=['message_id'=>Uuid::v4(),'request_id'=>Uuid::v4(),'turn_id'=>Uuid::v4(),'session_id'=>$aiTurn['session_id'],'generation'=>$aiTurn['generation'],'codec'=>'wav','language'=>'en-US','audio_bytes'=>64,'sha256'=>str_repeat('a',64),'created_at'=>gmdate('Y-m-d\TH:i:s\Z')];
$repo->acceptStt($aiStt,Uuid::v4(),str_repeat('b',64));
$aiGlobal=$products->globalSettingsForInstallation($installationId);$aiOff=$aiGlobal['content'];$aiOff['client']['behavior']['ai_enabled']=false;
$products->revise('global_settings',$aiGlobal['configuration_id'],$aiOff,'master off regression',gmdate('Y-m-d\TH:i:s\Z'));
$assert($repo->isDialogueCancellationRequested($aiDialogue),'AI off left speech usable');
$aiSpeechJob=Uuid::v4();$aiLease=Uuid::v4();
$db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,state,lease_owner,lease_token,leased_at,lease_expires_at,heartbeat_at,attempt_count) VALUES(:id,'speech.synthesize',1,:key,'{}','leased','master-test',:token,clock_timestamp(),clock_timestamp()+interval '5 minutes',clock_timestamp(),1)")
 ->execute(['id'=>$aiSpeechJob,'key'=>'master-speech:'.$aiDialogue,'token'=>$aiLease]);
$aiFence=['job_id'=>$aiSpeechJob,'lease_token'=>$aiLease,'attempt'=>1];
$assert($repo->claimDialogueForSpeech($aiDialogue,$aiFence)===null,'cancelled speech was claimed');
$assert($repo->completeDialogueSpeech(['dialogue_message_id'=>$aiDialogue],[],$aiFence)===[],'late speech published after AI off');

$aiQuery=$db->prepare('SELECT state FROM turns WHERE turn_id=:turn');$aiQuery->execute(['turn'=>$aiTurn['turn_id']]);$assert($aiQuery->fetchColumn()==='cancelled','AI off left accepted turn');
$aiQuery=$db->prepare('SELECT state,generation FROM sessions WHERE session_id=:session');$aiQuery->execute(['session'=>$aiTurn['session_id']]);$aiStill=$aiQuery->fetch();
$assert($aiStill['state']==='active'&&(int)$aiStill['generation']===$aiTurn['generation'],'AI off invalidated session');
$aiQuery=$db->prepare('SELECT state FROM stt_requests WHERE message_id=:id');$aiQuery->execute(['id'=>$aiStt['message_id']]);$assert($aiQuery->fetchColumn()==='accepted','AI off cancelled STT');
foreach(['message_id','request_id','turn_id']as$key)$aiStt[$key]=Uuid::v4();$repo->acceptStt($aiStt,Uuid::v4(),str_repeat('c',64));
$aiObservation=$resurrected;$aiObservation['request_id']=Uuid::v4();
foreach(['installation_id','playthrough_id','session_id','generation']as$key)$aiObservation[$key]=$aiTurn[$key];
$repo->acceptGameData($aiObservation);
$aiQuery=$db->prepare('SELECT 1 FROM source_events WHERE source_event_id=:id');$aiQuery->execute(['id'=>$aiObservation['request_id']]);$assert((bool)$aiQuery->fetchColumn(),'AI off blocked passive observation');
foreach(['message_id','request_id','turn_id']as$key)$aiTurn[$key]=Uuid::v4();
try{$repo->acceptTurn($aiTurn);$assert(false,'AI off accepted turn');}catch(DomainException$error){$assert($error->getMessage()==='ai_disabled','wrong disabled reason');}
$products->revise('global_settings',$aiGlobal['configuration_id'],$aiGlobal['content'],'restore master',gmdate('Y-m-d\TH:i:s\Z'));
$assert($repo->isDialogueCancellationRequested($aiDialogue),'AI on revived cancelled speech');

// Character identity never silently adopts a legacy world or switches unscoped mutable profiles.
$characterSession=$session;$characterSession['installation_id']=Uuid::v4();$characterSession['profile_id']=Uuid::v4();
$characterSession['playthrough_id']=Uuid::v4();$characterSession['message_id']=Uuid::v4();$characterSession['generation']=1;
$characterSession['character_id']=Uuid::v4();$characterSession['character_binding']='new';unset($characterSession['loaded_save']);
$characterSessionId=Uuid::v4();$repo->createSession($characterSession,$characterSessionId,$tokenHash);
$characterState=$products->characterPlaythroughState($characterSession['installation_id']);
$assert(count($characterState['bindings'])===1&&$characterState['current']['character_id']===$characterSession['character_id']
    &&$characterState['switch_available']===true,'new empty installation did not persist stable character identity');
$characterTurn=$turn;foreach(['installation_id','profile_id','playthrough_id','generation'] as $key)$characterTurn[$key]=$characterSession[$key];
$characterTurn['session_id']=$characterSessionId;foreach(['message_id','request_id','turn_id'] as $key)$characterTurn[$key]=Uuid::v4();
$repo->acceptTurn($characterTurn);
$characterReload=$characterSession;$characterReload['message_id']=Uuid::v4();$characterReload['generation']=2;
$characterReloadId=Uuid::v4();$repo->createSession($characterReload,$characterReloadId,$tokenHash);
$assert(count($products->characterPlaythroughState($characterSession['installation_id'])['bindings'])===1,'same-character load duplicated its binding');
$oldCharacterTurn=$db->prepare('SELECT state FROM turns WHERE turn_id=:turn');$oldCharacterTurn->execute(['turn'=>$characterTurn['turn_id']]);
$assert($oldCharacterTurn->fetchColumn()==='cancelled','same-character session replacement did not cancel outgoing work');
$guardCounts=fn()=>array_map('intval',$db->query('SELECT (SELECT count(*) FROM installations) AS installations,(SELECT count(*) FROM profiles) AS profiles,
    (SELECT count(*) FROM playthroughs) AS playthroughs,(SELECT count(*) FROM sessions) AS sessions,(SELECT count(*) FROM source_events) AS sources,
    (SELECT count(*) FROM character_playthrough_bindings) AS bindings')->fetch());
foreach(['legacy','character_conflict','fresh_unknown_existing'] as $bindingCase){
    $rejected=$characterReload;$rejected['message_id']=Uuid::v4();$rejected['generation']=3;$expected='character_binding_conflict';
    if($bindingCase==='legacy'){unset($rejected['character_id'],$rejected['character_binding']);$expected='character_binding_required';}
    if($bindingCase==='character_conflict')$rejected['character_id']=Uuid::v4();
    if($bindingCase==='fresh_unknown_existing'){$rejected['installation_id']=Uuid::v4();$rejected['character_id']=Uuid::v4();$rejected['profile_id']=Uuid::v4();$rejected['playthrough_id']=Uuid::v4();$rejected['character_binding']='existing';}
    $before=$guardCounts();$snapshotCalled=false;
    try{$repo->createSession($rejected,Uuid::v4(),$tokenHash,null,function()use(&$snapshotCalled):void{$snapshotCalled=true;});$assert(false,'unsafe character admission accepted');}
    catch(DomainException $error){$assert($error->getMessage()===$expected,'unexpected character admission error: '.$error->getMessage());}
    $assert($guardCounts()===$before&&!$snapshotCalled&&$repo->session($characterReloadId,2)['state']==='active','rejected admission created data, captured backup or retired the outgoing session');
}
// The saved owner hint may refer to the previous character; new worlds get a fresh blank owner.
$worldNpcTarget=['kind'=>'npc','record_id'=>'parity_world_npc','refnum'=>['index'=>4242,'content_file'=>0],
    'content_file'=>'Morrowind.esm','cell'=>['kind'=>'interior','name'=>'Balmora'],'display_name'=>'World NPC'];
$db->exec("INSERT INTO public.bio_templates_custom(npc_name,personality) VALUES('World NPC','Shared authored biography')");
$worldNpcTurn=$characterReload;$worldNpcTurn['session_id']=$characterReloadId;$worldNpcTurn['payload']=['target'=>$worldNpcTarget];
$firstWorldNpc=$products->ensureMorrowindActorProfile($worldNpcTurn,null,$now);
$firstPersona=$products->getRevisioned('profile',$firstWorldNpc)['content'];$firstPersona['personality']='First world remembers kindness';
$products->revise('profile',$firstWorldNpc,$firstPersona,'first world persona',$now);
$secondCharacter=$characterReload;$secondCharacter['message_id']=Uuid::v4();$secondCharacter['generation']=3;
$secondCharacter['playthrough_id']=Uuid::v4();$secondCharacter['character_id']=Uuid::v4();
$secondSessionId=Uuid::v4();$secondAccepted=$repo->createSession($secondCharacter,$secondSessionId,$tokenHash);
$assert($secondAccepted['profile_id']!==$characterSession['profile_id'],'new character reused another world profile');
$ownedProfiles=$db->prepare('SELECT profile_id,playthrough_id FROM profiles WHERE installation_id=:installation ORDER BY created_at,profile_id');
$ownedProfiles->execute(['installation'=>$characterSession['installation_id']]);$ownedRows=$ownedProfiles->fetchAll();
$assert(count($ownedRows)===3&&count(array_unique(array_column($ownedRows,'playthrough_id')))===2,'new characters did not own distinct mutable profiles');
$secondNpcTurn=$secondCharacter;$secondNpcTurn['session_id']=$secondSessionId;$secondNpcTurn['profile_id']=$secondAccepted['profile_id'];$secondNpcTurn['payload']=['target'=>$worldNpcTarget];
$secondWorldNpc=$products->ensureMorrowindActorProfile($secondNpcTurn,null,$now);
$secondPersona=$products->getRevisioned('profile',$secondWorldNpc)['content'];
$assert($firstWorldNpc!==$secondWorldNpc&&($secondPersona['personality']??'')!=='First world remembers kindness','automatic discovery reused foreign-world NPC persona');
$secondPersona['personality']='Second world remembers rivalry';$products->revise('profile',$secondWorldNpc,$secondPersona,'second world persona',$now);
$assert($db->query("SELECT personality FROM public.bio_templates_custom WHERE npc_name='World NPC'")->fetchColumn()==='Shared authored biography','scoped NPC projection overwrote shared biography');
try{$products->revise('profile',$firstWorldNpc,$firstPersona,'foreign edit',$now);$assert(false,'manual edit changed foreign world NPC');}
catch(RuntimeException $error){$assert($error->getMessage()==='not_found','wrong foreign profile edit denial');}
try{$products->bindActorProfile(['installation_id'=>$secondCharacter['installation_id'],'playthrough_id'=>$secondCharacter['playthrough_id']],$worldNpcTarget,$firstWorldNpc,$now);$assert(false,'foreign profile binding accepted');}
catch(OutOfBoundsException $error){$assert($error->getMessage()==='not_found','wrong foreign binding denial');}
$returnCharacter=$characterReload;$returnCharacter['message_id']=Uuid::v4();$returnCharacter['generation']=4;
$returnCharacter['profile_id']=$secondAccepted['profile_id'];
$returnSessionId=Uuid::v4();$returnAccepted=$repo->createSession($returnCharacter,$returnSessionId,$tokenHash);
$assert($returnAccepted['profile_id']===$characterSession['profile_id'],'returning save did not resolve its canonical owner');
$assert((new \LorkhanServer\Infrastructure\ProfileOwnershipRepository($db))->activePlaythrough($characterSession['installation_id'])===$characterSession['playthrough_id'],'selected playthrough did not follow accepted save');
$worldNpcTurn['session_id']=$returnSessionId;$worldNpcTurn['generation']=4;
$ownedNpc=$products->ensureMorrowindActorProfile($worldNpcTurn,null,$now);
$assert($ownedNpc===$firstWorldNpc&&$products->getRevisioned('profile',$ownedNpc)['content']['personality']==='First world remembers kindness','returning save lost its discovered NPC persona');
$scopeBackup=$products->configurationBackupState($characterSession['installation_id']);
$backupNpc=array_values(array_filter($scopeBackup['profiles'],static fn(array $row):bool=>$row['profile_id']===$ownedNpc))[0];
$assert($backupNpc['playthrough_id']===$characterSession['playthrough_id'],'configuration backup dropped ownership');
$backupNpc['playthrough_id']=$secondCharacter['playthrough_id'];
try{$products->restoreConfigurationBackup(['installation_id'=>$characterSession['installation_id'],'data'=>['profiles'=>[$backupNpc]]],$now);$assert(false,'backup reassigned profile owner');}
catch(RuntimeException $error){$assert($error->getMessage()==='backup_scope_conflict','wrong backup owner rejection');}
try{$products->restoreScope(['scope'=>['installation_id'=>$characterSession['installation_id'],'profile_id'=>$ownedNpc,'playthrough_id'=>$secondCharacter['playthrough_id']],'data'=>['memories'=>[],'relationships'=>[],'narratives'=>[]]],$now);$assert(false,'scope restore accepted foreign profile');}
catch(RuntimeException $error){$assert($error->getMessage()==='backup_scope_conflict','wrong scope restore rejection');}
try{$products->createRevisioned('playthrough',['installation_id'=>$characterSession['installation_id'],'profile_id'=>$ownedNpc,'name'=>'Unsafe manual world','content'=>[]],$now);$assert(false,'manual world reused scoped profile');}
catch(InvalidArgumentException $error){$assert($error->getMessage()==='scope_mismatch','wrong manual world rejection');}
try{(new \LorkhanServer\Infrastructure\EventLogRepository($db))->injectProfileEvent($ownedNpc,$secondCharacter['playthrough_id'],['event'=>'Must not enter another world']);$assert(false,'cross-character injected event accepted');}
catch(InvalidArgumentException $error){$assert($error->getMessage()==='invalid_eventlog_scope','wrong cross-character event rejection');}
try{(new \LorkhanServer\Infrastructure\NpcMemoryDigestRepository($db))->enqueue($characterSession['installation_id'],$secondCharacter['playthrough_id'],$ownedNpc);$assert(false,'cross-character digest accepted');}
catch(RuntimeException $error){$assert($error->getMessage()==='digest_profile_unavailable','wrong cross-character digest rejection');}
$assert(!(new \LorkhanServer\Infrastructure\ProfileEvolutionScheduler($db))->active(['profile_id'=>$ownedNpc,'playthrough_id'=>$secondCharacter['playthrough_id']]),'profile evolution accepted mismatched world');
$scopedUi=new \LorkhanServer\Infrastructure\ManagementUiRepository($db);
foreach(['characters','player','profiles','relationship_profiles','observed_npcs','action_policies'] as $view){
    $visible=$scopedUi->rows($view,$characterSession['installation_id']);
    $assert(is_array($visible),'scoped management query failed: '.$view);
    foreach($visible as $row)$assert(($row['profile_id']??null)!==$secondAccepted['profile_id'],'management list exposed foreign character profile: '.$view);
}
$legacyCharacter=$session;$legacyCharacter['installation_id']=Uuid::v4();$legacyCharacter['profile_id']=Uuid::v4();
$legacyCharacter['playthrough_id']=Uuid::v4();$legacyCharacter['message_id']=Uuid::v4();$legacyCharacter['generation']=1;unset($legacyCharacter['loaded_save']);
$repo->createSession($legacyCharacter,Uuid::v4(),$tokenHash);
$legacyNpc=Uuid::v4();$sharedNarrator=Uuid::v4();
foreach([$legacyNpc=>'npc',$sharedNarrator=>'narrator'] as $profile=>$kind){
    $db->prepare('INSERT INTO profiles(profile_id,installation_id,name,actor_identity,created_at) VALUES(:profile,:installation,:name,CAST(:identity AS jsonb),clock_timestamp())')
        ->execute(['profile'=>$profile,'installation'=>$legacyCharacter['installation_id'],'name'=>'Adoption '.$kind,'identity'=>json_encode(['kind'=>$kind,'record_id'=>'fargoth'])]);
    $db->prepare("INSERT INTO profile_revisions(profile_id,revision,content,change_reason,created_at) VALUES(:profile,1,'{\"personality\":\"Preserve me\"}','adoption fixture',clock_timestamp())")->execute(['profile'=>$profile]);
}
$legacyCharacter['character_id']=Uuid::v4();$legacyCharacter['character_binding']='existing';$legacyCharacter['generation']=2;$legacyCharacter['message_id']=Uuid::v4();
$before=$guardCounts();$repo->createSession($legacyCharacter,Uuid::v4(),$tokenHash);$after=$guardCounts();
$assert($after['profiles']===$before['profiles']&&$after['playthroughs']===$before['playthroughs']
    &&$products->characterPlaythroughState($legacyCharacter['installation_id'])['bindings'][0]['binding_mode']==='existing','explicit adoption replaced existing profiles or playthrough history');
$adopted=$db->prepare('SELECT p.playthrough_id,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id=:profile');
$adopted->execute(['profile'=>$legacyNpc]);$adoptedNpc=$adopted->fetch();
$assert($adoptedNpc['playthrough_id']===$legacyCharacter['playthrough_id']&&json_decode($adoptedNpc['content'],true)['personality']==='Preserve me','adoption lost NPC identity or persona');
$adopted->execute(['profile'=>$sharedNarrator]);$assert($adopted->fetch()['playthrough_id']===null,'adoption incorrectly scoped shared Narrator');
$olderUntagged=$legacyCharacter;$olderUntagged['character_id']=Uuid::v4();$olderUntagged['message_id']=Uuid::v4();$olderUntagged['generation']=3;
$recoveredCharacter=$repo->createSession($olderUntagged,Uuid::v4(),$tokenHash);
$assert(($recoveredCharacter['character_id']??null)===$legacyCharacter['character_id']
    &&count($products->characterPlaythroughState($legacyCharacter['installation_id'])['bindings'])===1,
    'explicit older-save adoption did not reuse the canonical character identity');

// A known saved character remains authoritative even when an older save carries another world hint.
$foreignWorld=(new \LorkhanServer\Infrastructure\CharacterPlaythroughRepository($db))->createEmpty($legacyCharacter['installation_id'],'Unselected preserved legacy world');
$foreignPlaythrough=$foreignWorld['playthrough_id'];$foreignCharacter=Uuid::v4();
$db->prepare("INSERT INTO character_playthrough_bindings(installation_id,character_id,playthrough_id,binding_mode) VALUES(:installation,:character,:playthrough,'existing')")
    ->execute(['installation'=>$legacyCharacter['installation_id'],'character'=>$foreignCharacter,'playthrough'=>$foreignPlaythrough]);
$foreignRecovery=$olderUntagged;$foreignRecovery['character_id']=$foreignCharacter;$foreignRecovery['generation']=4;$foreignRecovery['message_id']=Uuid::v4();
$foreignAccepted=$repo->createSession($foreignRecovery,Uuid::v4(),$tokenHash);
$assert($foreignAccepted['playthrough_id']===$foreignPlaythrough&&$foreignAccepted['profile_id']===$foreignWorld['profile_id'],'known character was reassigned using an older world hint');

// Multiple unpartitioned legacy worlds cannot be assigned to a character by guessing.
$ambiguous=$session;unset($ambiguous['loaded_save'],$ambiguous['character_id'],$ambiguous['character_binding']);
$ambiguous['installation_id']=Uuid::v4();$ambiguous['profile_id']=Uuid::v4();$ambiguous['playthrough_id']=Uuid::v4();
$ambiguous['message_id']=Uuid::v4();$ambiguous['generation']=1;$repo->createSession($ambiguous,Uuid::v4(),$tokenHash);
$db->prepare('INSERT INTO playthroughs(playthrough_id,installation_id,profile_id,name,created_at) VALUES(:id,:installation,:profile,:name,clock_timestamp())')
    ->execute(['id'=>Uuid::v4(),'installation'=>$ambiguous['installation_id'],'profile'=>$ambiguous['profile_id'],'name'=>'Other legacy save']);
$ambiguous['character_id']=Uuid::v4();$ambiguous['character_binding']='existing';$ambiguous['generation']=2;$ambiguous['message_id']=Uuid::v4();
$before=$guardCounts();
try{$repo->createSession($ambiguous,Uuid::v4(),$tokenHash);$assert(false,'ambiguous legacy profiles were reassigned');}
catch(DomainException $error){$assert($error->getMessage()==='playthrough_isolation_required','wrong ambiguous adoption reason');}
$assert($guardCounts()===$before,'ambiguous adoption mutated persisted owners');

$archiveService=new \LorkhanServer\Infrastructure\PlaythroughArchive($db);
$archiveScope=['installation_id'=>$characterSession['installation_id'],'playthrough_id'=>$characterSession['playthrough_id'],'profile_id'=>$ownedNpc];
$archiveMemory=$products->createMemory($archiveScope+['tier'=>'long','content'=>'Archive memory retained','provenance'=>['source'=>'manual']],['archive','memory'],\LorkhanServer\Application\DeterministicRetrieval::fakeVector('Archive memory retained'),$now);
$products->setRelationship($archiveScope+['actor_identity'=>$characterTurn['payload']['speaker'],'disposition'=>25,'affinity'=>10,'source_mode'=>'manual','reason'=>'Archive relationship retained'],$now);
$products->createNarrative($archiveScope+['kind'=>'diary','title'=>'Archive diary','content'=>'Retained personal diary','provenance'=>['source'=>'manual']],$now);
$products->createKnowledge($archiveScope+['title'=>'Archive knowledge','content'=>'Character-specific fact','topic'=>'archive_fact','aliases'=>'','topic_desc_basic'=>'','knowledge_class'=>'','knowledge_class_basic'=>'','tags'=>'','category'=>'','provenance'=>['source'=>'manual']],['archive','fact'],$now);
(new \LorkhanServer\Infrastructure\NpcMemoryDigestRepository($db))->edit($archiveScope['installation_id'],$archiveScope['playthrough_id'],$ownedNpc,0,'Manually curated NPC memory retained.');
$tablePolicy=\LorkhanServer\Infrastructure\PlaythroughTablePolicy::inventory($db);
$assert(count($tablePolicy)===count(\LorkhanServer\Infrastructure\PlaythroughTablePolicy::tables())&&!array_filter($tablePolicy,static fn($row)=>$row['category']==='unclassified'),'table inventory has unclassified maintained tables');
$db->beginTransaction();
$db->exec("CREATE TABLE public.archive_unknown_probe(id integer); COMMENT ON TABLE lorkhan_internal.profiles IS 'Keep unrelated table note'");
\LorkhanServer\Infrastructure\PlaythroughTablePolicy::synchronize($db);
$policyComment=$db->query("SELECT obj_description('lorkhan_internal.profiles'::regclass,'pg_class')")->fetchColumn();
\LorkhanServer\Infrastructure\PlaythroughTablePolicy::synchronize($db);
$assert(str_contains($policyComment,'Keep unrelated table note')&&$db->query("SELECT obj_description('lorkhan_internal.profiles'::regclass,'pg_class')")->fetchColumn()===$policyComment,'policy sync replaced unrelated comments or was not idempotent');
$unknownPolicy=array_values(array_filter(\LorkhanServer\Infrastructure\PlaythroughTablePolicy::inventory($db),static fn($row)=>$row['table']==='public.archive_unknown_probe'))[0];
$assert($unknownPolicy['portable']===false&&$unknownPolicy['category']==='unclassified','unknown table silently became portable');$db->rollBack();
$archivePendingAction=Uuid::v4();$archivePendingDialogue=Uuid::v4();
$db->prepare("INSERT INTO action_intents(action_id,session_id,turn_id,request_id,generation,action_name,tier,actor,parameters,expires_at,emitted_at) VALUES(:id,:session,:turn,:request,1,'inspect.report',0,CAST(:actor AS jsonb),'{}',clock_timestamp()+interval '1 minute',clock_timestamp())")
    ->execute(['id'=>$archivePendingAction,'session'=>$characterSessionId,'turn'=>$characterTurn['turn_id'],'request'=>Uuid::v4(),'actor'=>json_encode($worldNpcTarget)]);
$db->prepare("INSERT INTO dialogue_utterances(dialogue_message_id,response_line_id,utterance_id,session_id,turn_id,request_id,generation,utterance_index,utterance_count,speaker,addressee,audience,text,emitted_at,delivery_deadline_at) VALUES(:id,:id,:id,:session,:turn,:request,1,1,1,CAST(:speaker AS jsonb),'{}','[]','Preserved archival dialogue',clock_timestamp(),clock_timestamp()+interval '1 minute')")
    ->execute(['id'=>$archivePendingDialogue,'session'=>$characterSessionId,'turn'=>$characterTurn['turn_id'],'request'=>Uuid::v4(),'speaker'=>json_encode($worldNpcTarget)]);
(new \LorkhanServer\Infrastructure\EventLogRepository($db))->injectProfileEvent($ownedNpc,$characterSession['playthrough_id'],['event'=>'Archive scene remains in this character history']);
$publicPlayerBefore=$db->query('SELECT jsonb_agg(to_jsonb(p) ORDER BY id)::text FROM public.core_player p')->fetchColumn();
$archivePackage=$archiveService->export($characterSession['installation_id'],$characterSession['playthrough_id']);
$archiveJson=json_encode($archivePackage,JSON_THROW_ON_ERROR);
$archivePreview=$archiveService->inspect($archiveJson);
$assert($archivePreview['row_counts']['lorkhan_internal.profiles']===2,'archive exported another character profiles');
foreach(['memory_records','memory_record_revisions','relationship_records','relationship_revisions','relationship_audit','narrative_records','knowledge_documents','npc_memory_digests','diarylog_metadata']as$table)$assert($archivePreview['row_counts']['lorkhan_internal.'.$table]>0,'archive fixture lost domain data: '.$table);
$archiveCanonical=new ReflectionMethod($archiveService,'canonical');
foreach(['checksum','foreign_scope','global_revision','column']as$attack){
    $bad=$archivePackage;
    if($attack==='checksum')$bad['sha256']=str_repeat('0',64);
    if($attack==='foreign_scope')$bad['tables']['lorkhan_internal.sessions'][0]['playthrough_id']=$secondCharacter['playthrough_id'];
    if($attack==='global_revision'){$sharedId=Uuid::v4();$bad['shared_profiles'][$sharedId]='narrator';$bad['tables']['lorkhan_internal.profile_revisions'][0]['profile_id']=$sharedId;}
    if($attack==='column')$bad['tables']['lorkhan_internal.profiles'][0]['unsafe_column']='not SQL';
    if($attack!=='checksum'){unset($bad['sha256']);$bad['sha256']=hash('sha256',$archiveCanonical->invoke($archiveService,$bad));}
    try{$archiveService->inspect(json_encode($bad,JSON_THROW_ON_ERROR));$assert(false,'hostile archive accepted: '.$attack);}
    catch(RuntimeException $error){$assert(in_array($error->getMessage(),['archive_checksum_mismatch','archive_scope_mismatch','archive_owner_missing','archive_column_mismatch'],true),'unexpected hostile archive error: '.$error->getMessage());}
}
$beforeArchiveSession=(new \LorkhanServer\Infrastructure\ProfileOwnershipRepository($db))->activePlaythrough($characterSession['installation_id']);
$archiveCopy=$archiveService->importCopy($characterSession['installation_id'],$archiveJson);
$archiveSecondCopy=$archiveService->importCopy($characterSession['installation_id'],$archiveJson);
$assert($archiveSecondCopy['playthrough_id']!==$archiveCopy['playthrough_id'],'repeated import did not create a distinct inactive copy');
$assert($archiveCopy['active']===false&&$archiveCopy['playthrough_id']!==$characterSession['playthrough_id'],'archive did not create inactive copy');
$assert((new \LorkhanServer\Infrastructure\ProfileOwnershipRepository($db))->activePlaythrough($characterSession['installation_id'])===$beforeArchiveSession,'archive changed selected character');
$archivedSessions=$db->prepare('SELECT count(*) FROM sessions WHERE playthrough_id=:world AND archived AND state=\'ended\' AND cardinality(capabilities)=0');$archivedSessions->execute(['world'=>$archiveCopy['playthrough_id']]);
$assert((int)$archivedSessions->fetchColumn()===count($archivePackage['tables']['lorkhan_internal.sessions']),'archive session history is not inert');
$assert($db->query('SELECT jsonb_agg(to_jsonb(p) ORDER BY id)::text FROM public.core_player p')->fetchColumn()===$publicPlayerBefore,'archive changed public Player projection');
$archiveAction=$db->prepare('SELECT a.action_id,a.state FROM action_intents a JOIN sessions s USING(session_id) WHERE s.playthrough_id=:world');$archiveAction->execute(['world'=>$archiveCopy['playthrough_id']]);$archivedAction=$archiveAction->fetch();
$archiveDialogue=$db->prepare('SELECT d.dialogue_message_id,d.delivery_state FROM dialogue_utterances d JOIN sessions s USING(session_id) WHERE s.playthrough_id=:world');$archiveDialogue->execute(['world'=>$archiveCopy['playthrough_id']]);$archivedDialogue=$archiveDialogue->fetch();
$assert($archivedAction['state']==='terminal'&&$archivedDialogue['delivery_state']==='expired','archive restored executable pending outputs');
$archiveSideEffects=fn()=>$db->query('SELECT (SELECT count(*) FROM source_events)::text||\':\'||(SELECT count(*) FROM durable_jobs)::text')->fetchColumn();$beforeReceipts=$archiveSideEffects();
try{$repo->actionResult(['action_id'=>$archivedAction['action_id']]);$assert(false,'archival action receipt accepted');}catch(OutOfBoundsException $error){$assert($error->getMessage()==='unknown_session','wrong archival action receipt rejection');}
try{$repo->dialogueDeliveryResult(['dialogue_message_id'=>$archivedDialogue['dialogue_message_id']]);$assert(false,'archival dialogue receipt accepted');}catch(OutOfBoundsException $error){$assert($error->getMessage()==='unknown_session','wrong archival dialogue receipt rejection');}
$assert($archiveSideEffects()===$beforeReceipts,'archival receipt created source events or work');

// Web lifecycle edits never activate a world; an approved character link applies at the next admission.
$characters=new \LorkhanServer\Infrastructure\CharacterPlaythroughRepository($db);
$localSaves=new \LorkhanServer\Infrastructure\PlaythroughSaveRepository($db);
$sharedHash=static fn()=>$db->query("SELECT md5(string_agg(to_jsonb(c)::text,'' ORDER BY configuration_id)) FROM configuration_sets c")->fetchColumn();
$beforeShared=$sharedHash();
$localSaveId=$localSaves->capture($characterSession['installation_id'],$characterSession['playthrough_id'],'Gameplay-only regression');
$localCopy=$localSaves->restore($characterSession['installation_id'],$characterSession['playthrough_id'],$localSaveId);
$assert($sharedHash()===$beforeShared&&$localCopy['active']===false,'local gameplay restore changed shared settings or activated its copy');
$coreAssignments=$db->prepare('SELECT core_profile_id,count(*) FROM profiles WHERE playthrough_id=:world GROUP BY core_profile_id ORDER BY core_profile_id');
$coreAssignments->execute(['world'=>$characterSession['playthrough_id']]);$originalAssignments=$coreAssignments->fetchAll();
$coreAssignments->execute(['world'=>$localCopy['playthrough_id']]);
$assert($coreAssignments->fetchAll()===$originalAssignments,'local gameplay restore changed Core Profile assignments');
$characters->cancelAssociation($characterSession['installation_id'],$localCopy['association']['association_id']);
$localSaves->delete($characterSession['installation_id'],$localSaveId);
$managed=$characters->createEmpty($characterSession['installation_id'],'Managed empty world');
$renamed=$characters->renamePlaythrough($characterSession['installation_id'],$managed['playthrough_id'],'Renamed inactive world',1);
$assert($renamed['current_revision']===2,'rename did not record a revision');
try{$characters->renamePlaythrough($characterSession['installation_id'],$managed['playthrough_id'],'Stale edit',1);$assert(false,'stale rename accepted');}catch(RuntimeException $error){$assert($error->getMessage()==='revision_conflict','wrong stale rename rejection');}
$characters->deletePlaythrough($characterSession['installation_id'],$managed['playthrough_id'],2);
$softDeleted=$db->prepare('SELECT deleted_at IS NOT NULL FROM playthroughs WHERE playthrough_id=:world');$softDeleted->execute(['world'=>$managed['playthrough_id']]);$assert((bool)$softDeleted->fetchColumn(),'delete did not retain a soft-deleted world');
try{$characters->deletePlaythrough($characterSession['installation_id'],$characterSession['playthrough_id'],1);$assert(false,'bound character world deleted');}catch(RuntimeException $error){$assert($error->getMessage()==='playthrough_in_use','wrong bound world deletion rejection');}
$pendingLink=$characters->queueAssociation($characterSession['installation_id'],$characterSession['character_id'],$characterSession['playthrough_id'],$archiveCopy['playthrough_id']);
$assert($characters->state($characterSession['installation_id'])['current']['playthrough_id']===$characterSession['playthrough_id'],'web association switched running game');
try{$characters->deletePlaythrough($characterSession['installation_id'],$archiveCopy['playthrough_id'],1);$assert(false,'pending association target deleted');}catch(RuntimeException $error){$assert($error->getMessage()==='playthrough_in_use','wrong pending target deletion rejection');}
$staleLink=$returnCharacter;$staleLink['message_id']=Uuid::v4();
try{$repo->createSession($staleLink,Uuid::v4(),$tokenHash);$assert(false,'stale session consumed association');}catch(UnexpectedValueException $error){$assert($error->getMessage()==='stale_generation','wrong stale admission rejection');}
$assert($characters->state($characterSession['installation_id'])['pending_associations'][0]['association_id']===$pendingLink['association_id'],'rejected admission consumed pending association');
$linkedSession=$returnCharacter;$linkedSession['generation']=5;$linkedSession['message_id']=Uuid::v4();$backupWorld=null;
$linkedAccepted=$repo->createSession($linkedSession,Uuid::v4(),$tokenHash,null,function(array $resolved)use(&$backupWorld):void{$backupWorld=$resolved['playthrough_id'];});
$assert($linkedAccepted['playthrough_id']===$archiveCopy['playthrough_id']&&$linkedAccepted['profile_id']===$archiveCopy['profile_id']&&$backupWorld===$archiveCopy['playthrough_id'],'association did not admit canonical imported owners');
$assert($characters->state($characterSession['installation_id'])['pending_associations']===[],'applied association remained pending');
$linkedSession['generation']=6;$linkedSession['message_id']=Uuid::v4();$olderLinked=$repo->createSession($linkedSession,Uuid::v4(),$tokenHash);
$assert($olderLinked['playthrough_id']===$archiveCopy['playthrough_id'],'older save moved linked character back to old world');
$cancelLink=$characters->queueAssociation($characterSession['installation_id'],$characterSession['character_id'],$archiveCopy['playthrough_id'],$archiveSecondCopy['playthrough_id']);
$characters->cancelAssociation($characterSession['installation_id'],$cancelLink['association_id']);
$assert($characters->state($characterSession['installation_id'])['pending_associations']===[],'cancelled association remained queued');

// The wire handshake must return canonical owners before subsequent gameplay requests use them.
$repo->ensureInstallation($characterSession['installation_id'],$tokenHash,$macKey);
$linkedApi=function(string $path,array $message)use($router,$macKey,$characterSession,$jsonAuth):array{
    $body=json_encode($message,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $requestHeaders=$jsonAuth+['Idempotency-Key'=>$message['message_id']];
    $request=new Request('POST',$path,$requestHeaders,[],$body);
    $timestamp=gmdate('Y-m-d\TH:i:s\Z');$nonce=bin2hex(random_bytes(16));$digest=hash('sha256',$body);
    $requestHeaders+=['X-LORKHAN-Auth'=>RequestMac::ALGORITHM,'X-LORKHAN-Installation-Id'=>$characterSession['installation_id'],
        'X-LORKHAN-Timestamp'=>$timestamp,'X-LORKHAN-Nonce'=>$nonce,'X-LORKHAN-Content-SHA256'=>$digest,
        'X-LORKHAN-Signature'=>RequestMac::sign($macKey,$request,$characterSession['installation_id'],$timestamp,$nonce,$requestHeaders['Content-Type'],$digest)];
    $response=$router->dispatch(new Request('POST',$path,$requestHeaders,[],$body));
    return[$response->status,json_decode($response->body,true,64,JSON_THROW_ON_ERROR)];
};
$linkedSession['generation']=7;$linkedSession['message_id']=Uuid::v4();
[$linkedStatus,$linkedWire]=$linkedApi($base.'/sessions',$linkedSession);
$assert($linkedStatus===201&&($linkedWire['playthrough_id']??null)===$archiveCopy['playthrough_id']
    &&($linkedWire['profile_id']??null)===$archiveCopy['profile_id'],'session API omitted canonical linked owners: '.json_encode($linkedWire));
$linkedTurn=$characterTurn;foreach(['session_id','generation','playthrough_id','profile_id'] as $key)$linkedTurn[$key]=$linkedWire[$key];
foreach(['message_id','request_id','turn_id'] as $key)$linkedTurn[$key]=Uuid::v4();
[$linkedStatus,$linkedTurnWire]=$linkedApi($base.'/turns',$linkedTurn);
$assert($linkedStatus===202,'canonical linked turn API failed: '.json_encode($linkedTurnWire));
$linkedStored=$db->prepare('SELECT s.playthrough_id,s.profile_id FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE t.turn_id=:turn');$linkedStored->execute(['turn'=>$linkedTurn['turn_id']]);
$assert($linkedStored->fetch()===['playthrough_id'=>$archiveCopy['playthrough_id'],'profile_id'=>$archiveCopy['profile_id']],
    'followup gameplay request persisted in stale save scope');

// Profile evolution freezes editable field instructions and records application separately from API success.
$frozenFields=array_keys($dynamicPayload['dynamic_field_prompts']??[]);$selectedFields=$dynamicPayload['dynamic_fields']??[];sort($frozenFields);sort($selectedFields);
$assert($frozenFields===$selectedFields,
    'evolution omitted selected editable field prompts');
$receiptQuery=$db->prepare("SELECT payload->>'generation_outcome' FROM durable_jobs WHERE job_id=:job");
$receiptQuery->execute(['job'=>$dynamicJobRow['job_id']]);
$assert($receiptQuery->fetchColumn()==='applied','evolution application receipt missing');
$timelineProbe=new \LorkhanServer\Infrastructure\LoadedSaveTimeline($db);
$assert($timelineProbe->eventSourcesActive([],$installationId,$session['playthrough_id']), 'empty optional witnessed context rejected');
$assert(!$timelineProbe->eventSourcesActive([Uuid::v4()],$installationId,$session['playthrough_id']), 'unknown witnessed source accepted');
$assert(!$timelineProbe->eventSourcesActive(['not-an-id'],$installationId,$session['playthrough_id']), 'malformed witnessed source accepted');
$evolutionHistoryMethod=new ReflectionMethod($products,'evolutionWitnessedEvents');
$evolutionSnapshot=$evolutionHistoryMethod->invoke($products,$installationId,$session['playthrough_id'],null,100);
$assert(count($evolutionSnapshot)<=100&&strlen(json_encode($evolutionSnapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))<=16384,
    'witnessed evolution history exceeded bound');
$evolutionSources=array_values(array_unique(array_column($evolutionSnapshot,'source_event_id')));
$assert($timelineProbe->eventSourcesActive($evolutionSources,$installationId,$session['playthrough_id']), 'frozen event snapshot has invalid provenance');
if($evolutionSources!==[])$assert(!$timelineProbe->eventSourcesActive($evolutionSources,$installationId,Uuid::v4()), 'witnessed evolution history crossed playthrough scope');

fwrite(STDOUT, "integration vertical slice passed\n");
