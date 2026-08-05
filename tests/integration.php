<?php
declare(strict_types=1);

use ALMSIVIserver\Application\ActionPolicyValidator;
use ALMSIVIserver\Application\CancellationToken;
use ALMSIVIserver\Application\FirstPartyJobHandlerFactory;
use ALMSIVIserver\Application\DeterministicClock;
use ALMSIVIserver\Application\MockProvider;
use ALMSIVIserver\Application\MockSpeechProvider;
use ALMSIVIserver\Application\MorrowindVoiceCatalog;
use ALMSIVIserver\Application\Provider;
use ALMSIVIserver\Application\PromptAssembler;
use ALMSIVIserver\Application\ProductService;
use ALMSIVIserver\Application\Worker;
use ALMSIVIserver\Http\Request;
use ALMSIVIserver\Http\Router;
use ALMSIVIserver\Infrastructure\Connection;
use ALMSIVIserver\Infrastructure\ActionCatalogRepository;
use ALMSIVIserver\Infrastructure\DefaultConnectorProvisioner;
use ALMSIVIserver\Infrastructure\JobRepository;
use ALMSIVIserver\Infrastructure\MediaStore;
use ALMSIVIserver\Infrastructure\MigrationRunner;
use ALMSIVIserver\Infrastructure\ProviderAttemptRepository;
use ALMSIVIserver\Infrastructure\ProductRepository;
use ALMSIVIserver\Infrastructure\Repository;
use ALMSIVIserver\Protocol\Validator;
use ALMSIVIserver\Security\PairingToken;
use ALMSIVIserver\Security\RequestMac;

require dirname(__DIR__) . '/src/Autoload.php';

$dsn = getenv('ALMSIVI_TEST_DSN') ?: '';
if ($dsn === '') { fwrite(STDERR, "ALMSIVI_TEST_DSN is required\n"); exit(2); }
$db = Connection::open(['database_dsn' => $dsn, 'database_user' => getenv('ALMSIVI_TEST_DB_USER') ?: '',
    'database_password' => getenv('ALMSIVI_TEST_DB_PASSWORD') ?: '']);
$repo = new Repository($db, 256, new ActionCatalogRepository($db), new ActionPolicyValidator());
(new MigrationRunner($db, dirname(__DIR__) . '/database/migrations'))->up();
$mediaPath = sys_get_temp_dir() . '/almsivi-media-' . bin2hex(random_bytes(8));
$mediaStore = new MediaStore($mediaPath, 33_554_432, 67_108_864);
$token = PairingToken::generate();
$tokenHash = PairingToken::hash($token);$macKey=hex2bin($tokenHash);$installationId='00000000-0000-4000-8000-000000000001';
$attempts = new ProviderAttemptRepository($db);
$products = new ProductRepository($db);
$morrowindVoices=MorrowindVoiceCatalog::bundled();
$router = new Router($repo, new Validator(), new MockProvider(), $tokenHash, rateLimitRequests: 1000,
    mediaStore: $mediaStore, speechProvider: new MockSpeechProvider(), providerAttempts: $attempts,
    products:$products,promptAssembler:new PromptAssembler(),
    morrowindVoices:$morrowindVoices);
$base = '/ALMSIVIserver/api/v1';
$jsonAuth = ['Content-Type' => 'application/json; charset=utf-8'];
$fixture = fn(string $name): array => json_decode(file_get_contents(dirname(__DIR__) . '/protocol/fixtures/v1/valid/' . $name . '.json'), true, 64, JSON_THROW_ON_ERROR)['instance'];
$call = function (Router $target, string $method, string $path, array $headers = [], array $query = [], array|string|null $body = null) use($macKey,$installationId): array {
    $encoded = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : ($body ?? '');
    $request=new Request($method,$path,$headers,$query,$encoded);
    if(!isset($headers['Authorization'])&&$path!=='/ALMSIVIserver/api/v1/health'){$timestamp=gmdate('Y-m-d\TH:i:s\Z');$nonce=bin2hex(random_bytes(16));$digest=hash('sha256',$encoded);$headers+=['X-ALMSIVI-Auth'=>RequestMac::ALGORITHM,'X-ALMSIVI-Installation-Id'=>$installationId,'X-ALMSIVI-Timestamp'=>$timestamp,'X-ALMSIVI-Nonce'=>$nonce,'X-ALMSIVI-Content-SHA256'=>$digest,'X-ALMSIVI-Signature'=>RequestMac::sign($macKey,$request,$installationId,$timestamp,$nonce,(string)($headers['Content-Type']??''),$digest)];$request=new Request($method,$path,$headers,$query,$encoded);}
    $response = $target->dispatch($request);
    $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
    $capture = getenv('ALMSIVI_RESPONSE_CAPTURE') ?: '';
    if ($capture !== '') file_put_contents($capture, $response->body . "\n", FILE_APPEND | LOCK_EX);
    return [$response->status, $decoded];
};
$assert = function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$headers = fn(string $key): array => $jsonAuth + ['Idempotency-Key' => $key];
$newUuid = function (int $n): string { return sprintf('10000000-0000-4000-8000-%012d', $n); };
$defaultInstallationId='00000000-0000-4000-8000-000000000099';
$defaultVoicePath=sys_get_temp_dir().'/almsivi-default-voices-'.bin2hex(random_bytes(8));
mkdir($defaultVoicePath,0700,true);file_put_contents($defaultVoicePath.'/mw_dark_elf_male.wav','test');
$defaultProvisioner=new DefaultConnectorProvisioner($db,$defaultVoicePath);
$defaultRepository=new Repository($db,256,null,null,$defaultProvisioner);
$defaultRepository->ensureInstallation($defaultInstallationId,hash('sha256','almsivi-default-installation'));
$defaultRows=$db->prepare("SELECT c.name,r.content FROM configuration_sets c JOIN configuration_revisions r "
    ."ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.installation_id=:installation "
    ."AND c.deleted_at IS NULL ORDER BY c.kind,c.name");
$defaultRows->execute(['installation'=>$defaultInstallationId]);$defaultConfigurations=$defaultRows->fetchAll();
$defaultModels=[];foreach($defaultConfigurations as$configuration){$content=json_decode((string)$configuration['content'],true,16,JSON_THROW_ON_ERROR);
    if(($content['driver']??null)==='configured')$defaultModels[(string)$configuration['name']]=$content['model']??null;}
$assert($defaultModels===[
    'DeepSeek Chat V3.2'=>'deepseek/deepseek-v3.2','GLM 4.7'=>'z-ai/glm-4.7','GLM 5'=>'z-ai/glm-5',
    'Gemini 2.5 Flash Lite'=>'google/gemini-2.5-flash-lite'],
    'new installation did not receive the pinned CHIM LLM connector set: '.json_encode($defaultModels));
$defaultCore=(new ProductRepository($db))->defaultCoreProfileForInstallation($defaultInstallationId);
$defaultRouting=$defaultCore['content']['routing']??[];
$assert(count(array_filter($defaultRouting,static fn(mixed$value,string$key):bool=>str_starts_with($key,'llm_')
    &&str_ends_with($key,'_configuration_id'),ARRAY_FILTER_USE_BOTH))===4
    &&!array_key_exists('llm_fallback_configuration_id',$defaultRouting)
    &&isset($defaultRouting['tts_configuration_id'],$defaultRouting['prompt_configuration_id']),
    'new installation Core Profile routing did not match CHIM slots');
$defaultPrompt=$db->prepare("SELECT p.prompt_key,p.default_prompt,p.custom_prompt,p.description FROM prompts p WHERE p.installation_id=:installation AND p.prompt_key='roleplay_dialogue'");
$defaultPrompt->execute(['installation'=>$defaultInstallationId]);$defaultPromptRow=$defaultPrompt->fetch();
$assert($defaultPromptRow&&$defaultPromptRow['custom_prompt']===null
    &&str_contains((string)$defaultPromptRow['default_prompt'],'selected Morrowind actor')
    &&str_contains((string)$defaultPromptRow['description'],'CHIM-style roleplay prompt'),
    'new installation did not receive the editable CHIM-style default roleplay prompt');
$excludedCount=$db->prepare("SELECT count(*) FROM configuration_sets WHERE installation_id=:installation AND kind='stt_provider' AND deleted_at IS NULL");
$excludedCount->execute(['installation'=>$defaultInstallationId]);
$voiceCount=$db->prepare('SELECT count(*) FROM speech_connector_voices v JOIN configuration_sets c ON c.configuration_id=v.configuration_id WHERE c.installation_id=:installation');
$voiceCount->execute(['installation'=>$defaultInstallationId]);
$assert((int)$excludedCount->fetchColumn()===0&&(int)$voiceCount->fetchColumn()===1,
    'excluded STT or unavailable Morrowind voices were provisioned');
$beforeRevision=(int)$defaultCore['current_revision'];$beforeConfigurations=count($defaultConfigurations);
$defaultProvisioner->provision($defaultInstallationId);
$defaultRows->execute(['installation'=>$defaultInstallationId]);
$defaultCore=(new ProductRepository($db))->defaultCoreProfileForInstallation($defaultInstallationId);
$assert(count($defaultRows->fetchAll())===$beforeConfigurations&&(int)$defaultCore['current_revision']===$beforeRevision,
    'default connector provisioning was not idempotent');
unlink($defaultVoicePath.'/mw_dark_elf_male.wav');rmdir($defaultVoicePath);
$runWorker = function (array $types, ?Provider $provider = null) use ($db,$mediaStore): array {
    return (new Worker(new JobRepository($db), FirstPartyJobHandlerFactory::registry($db,$mediaStore,
        provider:$provider,speechProvider:$provider === null ? null : new MockSpeechProvider(),providerTimeoutMs:1000),
        'integration-worker',5,10,100,0,10,$types,
        static fn(int $microseconds):mixed=>null))->run();
};
$runTurnWorker = function(Provider $provider) use($runWorker):array {
    $turnStats=$runWorker(['turn.process'],$provider);
    $runWorker(['speech.synthesize'],$provider);
    return $turnStats;
};

[$status, $health] = $call($router, 'GET', $base . '/health');
$assert($status === 200 && $health['schema'] === 'almsivi.health.v1', 'health failed');
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
$session['runtime']['capabilities'][] = 'action.inspect.report';
[$status] = $call($router, 'POST', $base . '/sessions', $jsonAuth, [], $session);
$assert($status === 422, 'missing session idempotency key accepted');
[$status] = $call($router, 'POST', $base . '/sessions', $headers($newUuid(3)), [], $session);
$assert($status === 422, 'incoherent session idempotency key accepted');
[$status, $accepted] = $call($router, 'POST', $base . '/sessions', $headers($session['message_id']), [], $session);
$assert($status === 201 && $accepted['generation'] === 7
    && $accepted['capabilities'] === ['dialogue.text', 'speech.say', 'speech.listen', 'controls.session', 'action.inspect.report', 'action.ai.follow',
        'action.ai.stop', 'action.ai.travel', 'action.ai.escort', 'action.ai.face', 'action.ai.wander', 'action.combat.start', 'action.combat.stop',
        'action.animation.play', 'action.item.equip', 'action.item.unequip', 'action.item.use']
    &&$accepted['config_revision']==='global-settings-default-v1'
    &&($accepted['client_settings']['schema']??null)==='almsivi.client-settings.v1'
    &&($accepted['client_settings']['behavior']['rechat']??null)===false, 'session create failed');
$sessionId = $accepted['session_id'];
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
$actorProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Fargoth scholar',
    'actor_identity'=>['record_id'=>'fargoth'],'content'=>['persona'=>'A cautious Dwemer scholar.',
        'routing'=>['llm_configuration_id'=>$profileModelSlot['configuration_id']]]],$now);
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
$automaticTarget=['kind'=>'npc','record_id'=>'automatic_bosmer','refnum'=>['index'=>101,'content_file'=>0],
    'content_file'=>'Morrowind.esm','cell'=>['kind'=>'exterior','grid_x'=>-2,'grid_y'=>-9],'display_name'=>'Automatic Bosmer'];
$automaticContext=['targetState'=>['identity'=>['race'=>'Wood Elf','gender'=>'Male','is_male'=>true]]];
$automaticVoice=$morrowindVoices->resolve($automaticTarget,$automaticContext);
$assert(($automaticVoice['id']??null)==='mw_wood_elf_male','Morrowind voice catalog did not resolve Wood Elf male');
$assert(($morrowindVoices->resolve(['kind'=>'actor','record_id'=>'fargoth'],
    ['targetState'=>['identity'=>['race'=>'','gender'=>'']]])['id']??null)==='mw_wood_elf_male',
    'known legacy NPC fallback was hidden by empty profile metadata');
$exactFargothVoice=$products->preferExactProviderActorVoice($installationId,['kind'=>'npc','record_id'=>'fargoth'],
    $morrowindVoices->resolve(['kind'=>'actor','record_id'=>'fargoth'],['targetState'=>['identity'=>['race'=>'','gender'=>'']]]));
$assert(($exactFargothVoice['id']??null)==='fargoth'&&($exactFargothVoice['source']??null)==='actor_provider_catalog'
    &&($exactFargothVoice['race']??null)==='wood elf'&&($exactFargothVoice['gender']??null)==='Male',
    'active provider exact actor voice did not override the race and gender fallback');
$automaticProfileId=$products->ensureMorrowindActorProfile(['session_id'=>$sessionId,'generation'=>7,
    'installation_id'=>$installationId,'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id'],
    'payload'=>['target'=>$automaticTarget]],$automaticVoice,$now);
$automaticProfile=$products->getRevisioned('profile',$automaticProfileId);
$assert(($automaticProfile['content']['voice']['id']??null)==='mw_wood_elf_male'
    &&($automaticProfile['content']['voice']['source']??null)==='morrowind_race_gender_catalog'
    &&($automaticProfile['content']['biography']??null)==='A Bosmer raised beneath the great graht-oaks.'
    &&($automaticProfile['content']['personality']??null)==='Observant and quick-witted.',
    'first-seen NPC profile did not retain its catalog voice and matching biography template');
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
$automaticSpeech=$products->speechContext($installationId,$session['playthrough_id'],$automaticTarget,$profileTtsPreset);
$assert($automaticSpeech===['voice'=>'mw_wood_elf_male','language'=>'en'],
    'catalog voice was not selected when the routed connector contained it');
$db->prepare("DELETE FROM installation_provider_selections WHERE installation_id=:installation AND provider_kind='tts_provider'")
    ->execute(['installation'=>$installationId]);
$speechProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Jiub speech route',
    'actor_identity'=>['record_id'=>'jiub'],'content'=>['gender'=>'Female','race'=>'Dunmer',
        'routing'=>['tts_configuration_id'=>$profileTtsPreset['configuration_id']]]],$now);
$narratorProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'The Test Narrator',
    'actor_identity'=>['kind'=>'narrator','record_id'=>'almsivi:narrator','content_file'=>'ALMSIVI'],
    'content'=>['enabled'=>true,'inline_narration_mode'=>'Narrator','speech_style'=>'Measured narration.']],$now);
$controlsQuery=$fixture('controls-query');
$controlsQuery['message_id']=$newUuid(6);$controlsQuery['request_id']=$newUuid(7);
$controlsQuery['session_id']=$sessionId;$controlsQuery['generation']=7;
[$status,$controls]=$call($router,'POST',$base.'/controls/query',$jsonAuth,[],$controlsQuery);
$assert($status===200&&$controls['schema']==='almsivi.controls.v1'
    &&in_array($modelSlot['configuration_id'],array_column($controls['model_slots'],'configuration_id'),true)
    &&in_array($actorProfile['profile_id'],array_column($controls['profiles'],'profile_id'),true)
    &&!in_array($narratorProfile['profile_id'],array_column($controls['profiles'],'profile_id'),true)
    &&$controls['narrator_profile_id']===$narratorProfile['profile_id']
    &&$controls['selected_model_slot_id']===null&&$controls['selected_profile_id']===null
    &&($controls['effective_settings']['schema']??null)==='almsivi.effective-settings.v1'
    &&preg_match('/^[0-9a-f]{64}$/D',(string)($controls['effective_settings']['change_token']??''))===1
    &&($controls['effective_settings']['profile_id']??null)===null
    &&isset($controls['effective_settings']['settings']['memory'],$controls['effective_settings']['settings']['narrator'],$controls['effective_settings']['settings']['safety'])
    &&!isset($controls['effective_settings']['settings']['behavior'],$controls['effective_settings']['settings']['presentation']),
    'in-game controls query did not return safe model/profile choices');

$selectModel=$fixture('controls-select');
$selectModel['message_id']=$newUuid(8);$selectModel['request_id']=$newUuid(9);
$selectModel['session_id']=$sessionId;$selectModel['generation']=7;$selectModel['created_at']=$now;
$selectModel['target']=$controlsQuery['target'];$selectModel['kind']='model_slot';
$selectModel['selection_id']=$modelSlot['configuration_id'];
[$status,$modelSelected]=$call($router,'POST',$base.'/controls/select',$headers($selectModel['message_id']),[],$selectModel);
$assert($status===200&&$modelSelected['selected_model_slot_id']===$modelSlot['configuration_id'],
    'in-game model slot selection failed');
[$status,$modelReplay]=$call($router,'POST',$base.'/controls/select',$headers($selectModel['message_id']),[],$selectModel);
$assert($status===200&&$modelReplay==$modelSelected,'in-game model slot selection was not idempotent');

$selectProfile=$selectModel;$selectProfile['message_id']=$newUuid(10);$selectProfile['request_id']=$newUuid(11);
$selectProfile['kind']='actor_profile';$selectProfile['selection_id']=$actorProfile['profile_id'];
[$status,$profileSelected]=$call($router,'POST',$base.'/controls/select',$headers($selectProfile['message_id']),[],$selectProfile);
    $assert($status===200&&$profileSelected['selected_profile_id']===$actorProfile['profile_id']
        &&($profileSelected['effective_settings']['profile_id']??null)===$actorProfile['profile_id']
        &&($profileSelected['effective_settings']['change_token']??null)!==($controls['effective_settings']['change_token']??null),
        'in-game actor profile selection failed');
$turnLike=['session_id'=>$sessionId,'generation'=>7,'installation_id'=>$installationId,
    'playthrough_id'=>$session['playthrough_id'],'payload'=>['target'=>$controlsQuery['target']]];
$explicitContext=$products->providerContext($turnLike);
$assert(($explicitContext['configuration_id']??null)===$modelSlot['configuration_id'],
    'explicit in-game model slot did not override profile routing');
$clearModel=$selectModel;$clearModel['message_id']=$newUuid(706);$clearModel['request_id']=$newUuid(707);$clearModel['selection_id']=null;
[$status]=$call($router,'POST',$base.'/controls/select',$headers($clearModel['message_id']),[],$clearModel);
$profileContext=$products->providerContext($turnLike);
$assert($status===200&&($profileContext['configuration_id']??null)===$profileModelSlot['configuration_id'],
    'NPC profile did not supply its primary LLM model slot');
$coreModelSlot=$products->createRevisioned('provider',['installation_id'=>$installationId,'name'=>'Core-profile mock',
    'content'=>['driver'=>'mock','model'=>'deterministic-core-v1','mock_prefix'=>'[core] ']],$now);
$coreProfile=$products->defaultCoreProfileForInstallation($installationId);
$coreProfile=$products->revise('core_profile',$coreProfile['core_profile_id'],[
    'schema'=>'almsivi.core-profile.v1','prompt'=>'CORE PROFILE INSTRUCTION SENTINEL',
    'routing'=>['llm_configuration_id'=>$coreModelSlot['configuration_id']],
    'settings_overrides'=>['behavior'=>['rechat'=>true],'memory'=>['knowledge_limit'=>0]],
],'integration layered settings',$now);
$inheritedContent=$actorProfile['content'];$inheritedContent['routing']=[];
$actorProfile=$products->revise('profile',$actorProfile['profile_id'],$inheritedContent,'inherit Core Profile routing',$now);
$inheritedContext=$products->providerContext($turnLike);
$effectiveSettings=$products->effectiveSettingsForActor($installationId,$session['playthrough_id'],$controlsQuery['target']);
$effectiveControlsQuery=$controlsQuery;$effectiveControlsQuery['message_id']=$newUuid(712);$effectiveControlsQuery['request_id']=$newUuid(713);
[$effectiveControlsStatus,$effectiveControls]=$call($router,'POST',$base.'/controls/query',$jsonAuth,[],$effectiveControlsQuery);
$assert(($inheritedContext['configuration_id']??null)===$coreModelSlot['configuration_id']
    &&$effectiveSettings['settings']['behavior']['rechat']===true
    &&$effectiveSettings['settings']['memory']['knowledge_limit']===0
    &&($effectiveSettings['sources']['settings.behavior.rechat']??null)==='core_profile'
    &&$effectiveControlsStatus===200
    &&($effectiveControls['effective_settings']['profile_id']??null)===$actorProfile['profile_id']
    &&($effectiveControls['effective_settings']['core_profile_id']??null)===$coreProfile['core_profile_id']
    &&($effectiveControls['effective_settings']['settings']['memory']['knowledge_limit']??null)===0
    &&($effectiveControls['effective_settings']['source_map']['settings.memory.knowledge_limit']??null)==='core_profile',
    'Core Profile routing and typed setting overrides did not reach runtime resolution');
$maskedContent=$actorProfile['content'];$maskedContent['routing']=['llm_configuration_id'=>''];
$actorProfile=$products->revise('profile',$actorProfile['profile_id'],$maskedContent,'explicit NPC route disable',$now);
$assert($products->providerContext($turnLike)===null,
    'explicit empty NPC routing did not mask the inherited Core Profile connector');
$randomizedContent=$actorProfile['content'];$randomizedContent['routing']=[
    'llm_configuration_id'=>$profileModelSlot['configuration_id'],
    'llm_fast_configuration_id'=>$fastModelSlot['configuration_id'],
    'llm_randomizer_enabled'=>true,
];
$actorProfile=$products->revise('profile',$actorProfile['profile_id'],$randomizedContent,'integration LLM routing',$now);
$randomizedSelections=[];
for($i=720;$i<752;$i++){$candidate=$turnLike;$candidate['turn_id']=$newUuid($i);
    $context=$products->providerContext($candidate);$randomizedSelections[$context['configuration_id']??'']=true;}
$assert(isset($randomizedSelections[$profileModelSlot['configuration_id']],$randomizedSelections[$fastModelSlot['configuration_id']])
    &&count($randomizedSelections)===2,'profile LLM randomizer did not use every configured general-purpose slot');
$restoreModel=$selectModel;$restoreModel['message_id']=$newUuid(708);$restoreModel['request_id']=$newUuid(709);
[$status]=$call($router,'POST',$base.'/controls/select',$headers($restoreModel['message_id']),[],$restoreModel);
$speechTarget=$controlsQuery['target'];$speechTarget['record_id']='jiub';$speechTarget['display_name']='Jiub';$speechTarget['refnum']['index']=99;
$products->bindActorProfile(['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']],$speechTarget,$speechProfile['profile_id'],$now);
$profileSpeech=$products->connectorForActor($installationId,$session['playthrough_id'],$speechTarget,'tts_provider');
$assert($status===200&&($profileSpeech['configuration_id']??null)===$profileTtsPreset['configuration_id'],
    'NPC profile did not supply its TTS connector');
$profileSpeechContext=$products->speechContext($installationId,$session['playthrough_id'],$speechTarget,$profileSpeech);
$assert($profileSpeechContext===['voice'=>'fallback_female_voice'],
    'NPC profile gender did not select the connector female fallback voice: '.json_encode($profileSpeechContext));

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

$turn = $fixture('turn');
$turn['session_id'] = $sessionId;
$turn['payload']['target']=$controlsQuery['target'];
$turn['payload']['input']['text'] = 'Please follow me.';
// Turn-advertised capabilities cannot add a capability that was not negotiated; stored session policy is authoritative.
$turn['runtime']['capabilities'] = ['dialogue.text'];
[$status] = $call($router, 'POST', $base . '/turns', $jsonAuth, [], $turn);
$assert($status === 422, 'missing turn idempotency accepted');
[$status, $turnAccepted] = $call($router, 'POST', $base . '/turns', $headers($turn['message_id']), [], $turn);
$assert($status === 202 && $turnAccepted['event_cursor'] === 1, 'turn acceptance failed');
$snapshotStatement=$db->prepare('SELECT source_manifest FROM turn_provider_snapshots WHERE turn_id=:turn');
$snapshotStatement->execute(['turn'=>$turn['turn_id']]);
$snapshot=json_decode((string)$snapshotStatement->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$traceStatement=$db->prepare('SELECT core_profile_id,core_profile_revision,effective_settings_sha256,settings_sources FROM prompt_traces WHERE turn_id=:turn');
$traceStatement->execute(['turn'=>$turn['turn_id']]);$layerTrace=$traceStatement->fetch();
$traceSources=$layerTrace?json_decode((string)$layerTrace['settings_sources'],true,64,JSON_THROW_ON_ERROR):[];
$promptMessages=$snapshot['message']['_prompt']['_messages']??[];
$assert(is_string($snapshot['message']['_prompt']['_assembled_prompt']??null)
    &&is_array($promptMessages)&&array_is_list($promptMessages)&&count($promptMessages)>=2
    &&($promptMessages[0]['role']??null)==='system'
    &&str_contains((string)($promptMessages[0]['content']??''),'<roleplay_instructions>')
    &&str_contains((string)($promptMessages[0]['content']??''),'<character>')
    &&str_contains((string)($promptMessages[0]['content']??''),'<general_instructions>')
    &&($promptMessages[array_key_last($promptMessages)]['role']??null)==='user'
    &&str_contains((string)($promptMessages[array_key_last($promptMessages)]['content']??''),'Please follow me.')
    &&str_contains($snapshot['message']['_prompt']['_assembled_prompt'],'CORE PROFILE INSTRUCTION SENTINEL')
    &&str_contains($snapshot['message']['_prompt']['_assembled_prompt'],'Dwemer scholar')
    &&($snapshot['message']['_selected_profile_id']??null)===$actorProfile['profile_id']
    &&($layerTrace['core_profile_id']??null)===$coreProfile['core_profile_id']
    &&(int)($layerTrace['core_profile_revision']??0)===(int)$coreProfile['current_revision']
    &&preg_match('/^[0-9a-f]{64}$/D',(string)($layerTrace['effective_settings_sha256']??''))===1
    &&($traceSources['settings.behavior.rechat']??null)==='core_profile'
    &&($snapshot['message']['_provider_configuration']['configuration_id']??null)===$modelSlot['configuration_id'],
    'accepted turn did not freeze the layered Core Profile prompt, settings trace, and provider input for the worker');
$successfulWorkerStats=$runTurnWorker(new MockProvider());
$assert($successfulWorkerStats === ['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0], 'successful turn job was not acknowledged');
[$status, $turnDuplicate] = $call($router, 'POST', $base . '/turns', $headers($turn['message_id']), [], $turn);
$assert($status === 202 && $turnDuplicate == $turnAccepted, 'turn duplicate failed');
[$status] = $call($router, 'GET', $base . '/events', [], [
    'session_id' => $sessionId, 'generation' => '7', 'after' => '0', 'wait_ms' => '15001']);
$assert($status === 422, 'oversized event wait accepted');
[$status, $events] = $call($router, 'GET', $base . '/events', [], [
    'session_id' => $sessionId, 'generation' => '7', 'after' => '0', 'wait_ms' => '15000']);
$assert($status === 200 && array_column($events['events'], 'type') === ['turn.accepted', 'dialogue.complete', 'action.intent', 'turn.complete', 'speech.ready'], 'event order failed');
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
$assert((int)$immutableSourceCount->fetchColumn()===2,'event projection replaced immutable ALMSIVI source records');
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
$mediaPath=$base.'/media/'.$speech['media_id'];$timestamp=gmdate('Y-m-d\TH:i:s\Z');$nonce=bin2hex(random_bytes(16));$mediaUnsigned=new Request('GET',$mediaPath,[],[],'');$mediaHeaders=['X-ALMSIVI-Auth'=>RequestMac::ALGORITHM,'X-ALMSIVI-Installation-Id'=>$installationId,'X-ALMSIVI-Timestamp'=>$timestamp,'X-ALMSIVI-Nonce'=>$nonce,'X-ALMSIVI-Content-SHA256'=>RequestMac::EMPTY_SHA256,'X-ALMSIVI-Signature'=>RequestMac::sign($macKey,$mediaUnsigned,$installationId,$timestamp,$nonce,'',RequestMac::EMPTY_SHA256)];
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
$cancelRequest = ['schema'=>'almsivi.interrupt.v1','message_id'=>$newUuid(36),'request_id'=>$cancelledTurn['request_id'],
    'turn_id'=>$cancelledTurn['turn_id'],'session_id'=>$sessionId,'generation'=>7,'created_at'=>gmdate('Y-m-d\\TH:i:s\\Z'),'reason'=>'player'];
[$interruptStatus]=$call($router,'POST',$base.'/interruptions',$headers($cancelRequest['message_id']),[],$cancelRequest);
$assert($status === 202 && $interruptStatus === 202, 'active turn interruption failed');
[$status, $cancelledEvents] = $call($router, 'GET', $base . '/events', [], [
    'session_id' => $sessionId, 'generation' => '7', 'after' => (string) $events['next_after']]);
$assert($status === 200 && array_column($cancelledEvents['events'], 'type') === ['turn.accepted', 'turn.cancelled'], 'interruption was not terminal');

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
$assert($status === 200 && array_column($failedEvents['events'], 'type') === ['turn.accepted', 'turn.failed'], 'provider failure not terminally persisted');
$failedAttempt = $db->query("SELECT state, error_code, error_detail FROM provider_attempts WHERE turn_id = " . $db->quote($failedTurn['turn_id']))->fetch();
$assert($failedAttempt === ['state' => 'failed', 'error_code' => 'provider_unavailable', 'error_detail' => null],
    'provider failure attempt was not redacted/reconciled');

// A profile fallback is frozen with the accepted turn and is attempted once after the default provider fails.
$fallbackTarget=$controlsQuery['target'];$fallbackTarget['record_id']='fallback_actor';$fallbackTarget['display_name']='Fallback Actor';
$fallbackTarget['refnum']['index']=733;
$fallbackProfile=$products->createRevisioned('profile',['installation_id'=>$installationId,'name'=>'Fallback-only actor',
    'actor_identity'=>['record_id'=>'fallback_actor'],'content'=>['routing'=>[
        'llm_configuration_id'=>'','llm_fallback_configuration_id'=>$fallbackModelSlot['configuration_id'],
        'llm_fallback_enabled'=>true]]],$now);
$products->bindActorProfile(['installation_id'=>$installationId,'playthrough_id'=>$session['playthrough_id']],
    $fallbackTarget,$fallbackProfile['profile_id'],$now);
$products->selectSessionProvider(['session_id'=>$sessionId,'generation'=>7,'installation_id'=>$installationId],null);
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
    'accepted turn did not freeze the profile fallback connector');
$fallbackWorkerStats=$runTurnWorker($failingProvider);
$assert($fallbackWorkerStats===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0],
    'explicit profile fallback did not complete the durable turn');
[$status,$fallbackEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$failedEvents['next_after']]);
$fallbackTypes=array_column($fallbackEvents['events'],'type');
$assert($status===200&&in_array('dialogue.complete',$fallbackTypes,true)&&in_array('turn.complete',$fallbackTypes,true)
    &&!in_array('turn.failed',$fallbackTypes,true),'fallback turn did not complete normally');
$fallbackAttempts=$db->query("SELECT state,metadata->>'fallback' AS fallback FROM provider_attempts WHERE provider_kind='llm' AND turn_id="
    .$db->quote($fallbackTurn['turn_id'])." ORDER BY started_at,provider_attempt_id")->fetchAll();
$assert($fallbackAttempts===[['state'=>'failed','fallback'=>'false'],['state'=>'succeeded','fallback'=>'true']],
    'profile fallback attempts were not recorded as one primary failure and one fallback success');
$products->selectSessionProvider(['session_id'=>$sessionId,'generation'=>7,'installation_id'=>$installationId],$modelSlot['configuration_id']);

// Exercise the lower group bounds through the same durable provider/TTS pipeline.
$groupAfter=(int)$fallbackEvents['next_after'];
foreach([2,3] as $groupCount){$bounded=$turn;$bounded['message_id']=$newUuid(50+$groupCount*3);$bounded['request_id']=$newUuid(51+$groupCount*3);
    $bounded['turn_id']=$newUuid(52+$groupCount*3);$bounded['payload']['input']['text']='[group] Bounded report.';$bounded['payload']['audience']=[];
    for($i=1;$i<$groupCount;++$i){$actor=$bounded['payload']['target'];$actor['record_id']='bounded_'.$groupCount.'_'.$i;
        $actor['display_name']='Bounded Actor '.$groupCount.'-'.$i;$actor['refnum']['index']=150+$groupCount*10+$i;$bounded['payload']['audience'][]=$actor;}
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
for($i=0;$i<3;++$i){$actor=$groupTurn['payload']['target'];$actor['record_id']='group_actor_'.($i+1);$actor['display_name']='Group Actor '.($i+1);$actor['refnum']['index']=200+$i;$groupTurn['payload']['audience'][]=$actor;}
[$status,$groupAccepted]=$call($router,'POST',$base.'/turns',$headers($groupTurn['message_id']),[],$groupTurn);
$assert($status===202,'group turn acceptance failed');
$groupWorker=$runTurnWorker(new MockProvider());
$assert($groupWorker===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0],'group turn worker failed');
[$status,$groupEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$groupAfter]);
$groupTypes=array_column($groupEvents['events'],'type');
$assert($status===200&&$groupTypes===['turn.accepted','dialogue.complete','dialogue.complete','dialogue.complete',
    'dialogue.complete','turn.complete','speech.ready','speech.ready','speech.ready','speech.ready'],'four-utterance group event order failed');
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
    &&$wanderIntents[0]['payload']['parameters']===['distance'=>512,'duration_seconds'=>60],'ai.wander was not emitted E2E');

$combatTurn=$turn;$combatTurn['message_id']=$newUuid(183);$combatTurn['request_id']=$newUuid(184);$combatTurn['turn_id']=$newUuid(185);
$combatTurn['payload']['input']['text']='Attack that target.';$combatTurn['payload']['recent_action_results']=[];
[$status]=$call($router,'POST',$base.'/turns',$headers($combatTurn['message_id']),[],$combatTurn);
$assert($status===202,'combat turn acceptance failed');$runTurnWorker(new MockProvider());
[$status,$combatEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$wanderEvents['next_after']]);
$combatIntents=array_values(array_filter($combatEvents['events'],static fn(array $e):bool=>$e['type']==='action.intent'));
$assert($status===200&&count($combatIntents)===1&&$combatIntents[0]['payload']['name']==='combat.start'
    &&$combatIntents[0]['payload']['tier']===2,'combat.start tier-2 proposal was not emitted E2E');

// STT remains visible as an excluded CHIM-parity control but has no runtime API or worker path.
$sttAudio=(new MockSpeechProvider())->synthesize('pre-turn stt',new \ALMSIVIserver\Application\NeverCancelledToken())['bytes'];
$sttMessage=$newUuid(90);$sttRequest=$newUuid(91);$sttTurn=$newUuid(92);$sttCreated=gmdate('Y-m-d\TH:i:s\Z');
$sttHeaders=['Content-Type'=>'application/octet-stream','Idempotency-Key'=>$sttMessage,
    'X-ALMSIVI-Schema'=>'almsivi.stt.request.v1','X-ALMSIVI-Message-Id'=>$sttMessage,'X-ALMSIVI-Request-Id'=>$sttRequest,
    'X-ALMSIVI-Turn-Id'=>$sttTurn,'X-ALMSIVI-Session-Id'=>$sessionId,'X-ALMSIVI-Generation'=>'7','X-ALMSIVI-Created-At'=>$sttCreated,
    'X-ALMSIVI-Codec'=>'wav','X-ALMSIVI-Language'=>'en-US','X-ALMSIVI-Audio-Bytes'=>(string)strlen($sttAudio),
    'X-ALMSIVI-Sha256'=>hash('sha256',$sttAudio)];
[$status,$sttExcluded]=$call($router,'POST',$base.'/stt',$sttHeaders,[],$sttAudio);
$assert($status===404&&$sttExcluded['code']==='not_found'
    &&(int)$db->query('SELECT count(*) FROM turns WHERE turn_id='.$db->quote($sttTurn))->fetchColumn()===0,
    'excluded STT route remained reachable');
$assert(!in_array('stt.process',FirstPartyJobHandlerFactory::jobTypes(),true),'excluded STT worker remained registered');
[$status,$sttEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$combatEvents['next_after']]);
$assert($status===200&&$sttEvents['events']===[],'excluded STT emitted an event');

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
$menuTurn['payload']['action_request']=['name'=>'ai.wander','tier'=>1,'parameters'=>['distance'=>0,'duration_seconds'=>3600]];
[$status,$menuAccepted]=$call($router,'POST',$base.'/turns',$headers($menuTurn['message_id']),[],$menuTurn);
$assert($status===202,'typed player action turn acceptance failed: '.$status.' '.json_encode($menuAccepted));
[$status,$menuEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$autonomyEvents['next_after']]);
$menuTypes=array_column($menuEvents['events'],'type');
$menuIntents=array_values(array_filter($menuEvents['events'],static fn(array $e):bool=>$e['type']==='action.intent'));
$menuJobs=$db->prepare("SELECT count(*) FROM durable_jobs WHERE job_type='turn.process' AND payload->>'turn_id'=:turn");
$menuJobs->execute(['turn'=>$menuTurn['turn_id']]);
$assert($status===200&&$menuTypes===['turn.accepted','action.intent','turn.complete']
    &&count($menuIntents)===1&&$menuIntents[0]['payload']['name']==='ai.wander'
    &&$menuIntents[0]['payload']['parameters']===['distance'=>0,'duration_seconds'=>3600]
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
$assert($status===200&&array_column($targetedEvents['events'],'type')===['turn.accepted','action.intent','turn.complete']
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
$assert($status===200&&array_column($travelEvents['events'],'type')===['turn.accepted','action.intent','turn.complete']
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
$rechatChainId=$newUuid(820);
$rechatTurn=$turn;
$rechatTurn['message_id']=$newUuid(821);$rechatTurn['request_id']=$newUuid(822);$rechatTurn['turn_id']=$newUuid(823);
$rechatTurn['payload']['ui_source']='almsivi_rechat';
$rechatTurn['payload']['input']['text']='Please follow me again.';
$rechatTurn['payload']['recent_action_results']=[];
$rechatTurn['payload']['context']['rechat']=['chain_id'=>$rechatChainId,'depth'=>1,'max_depth'=>2,
    'origin_turn_id'=>$turn['turn_id'],'previous_speaker'=>$dialogueEvent['payload']['speaker'],
    'previous_listener'=>$turn['payload']['speaker']];
[$status,$rechatAccepted]=$call($router,'POST',$base.'/turns',$headers($rechatTurn['message_id']),[],$rechatTurn);
$assert($status===202,'first typed rechat continuation was rejected: '.$status.' '.json_encode($rechatAccepted));
$rechatPrompt=$db->prepare('SELECT source_manifest FROM turn_provider_snapshots WHERE turn_id=:turn');
$rechatPrompt->execute(['turn'=>$rechatTurn['turn_id']]);
$rechatManifest=json_decode((string)$rechatPrompt->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$rechatWorker=$runTurnWorker(new MockProvider());
$rechatState=$db->prepare('SELECT state,current_depth,max_depth,origin_turn_id,latest_turn_id FROM rechat_chains WHERE chain_id=:chain');
$rechatState->execute(['chain'=>$rechatChainId]);$firstRechatState=$rechatState->fetch();
$rechatActions=$db->prepare("SELECT count(*) FROM response_events WHERE turn_id=:turn AND event_type='action.intent'");
$rechatActions->execute(['turn'=>$rechatTurn['turn_id']]);
$rechatActionCount=(int)$rechatActions->fetchColumn();
    $assembledRechatPrompt=(string)($rechatManifest['message']['_prompt']['_assembled_prompt']??'');
    $rechatMessages=$rechatManifest['message']['_prompt']['_messages']??[];
    $assert($rechatWorker===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0]
    &&is_array($rechatMessages)&&array_is_list($rechatMessages)&&count($rechatMessages)>=3
    &&($rechatMessages[0]['role']??null)==='system'
    &&($rechatMessages[array_key_last($rechatMessages)]['role']??null)==='user'
    &&str_contains($assembledRechatPrompt,'Please follow me.')
    &&!str_contains($assembledRechatPrompt,'"type":"turn.requested"')
    &&!str_contains($assembledRechatPrompt,'[fallback] Continue after the primary provider fails.')
    &&$firstRechatState&&$firstRechatState['state']==='awaiting_playback'
    &&(int)$firstRechatState['current_depth']===1&&(int)$firstRechatState['max_depth']===2
    &&$firstRechatState['origin_turn_id']===$turn['turn_id']&&$firstRechatState['latest_turn_id']===$rechatTurn['turn_id']
    &&$rechatActionCount===0,
    'first rechat did not preserve CHIM history, chain state, or action-free continuation semantics: '.json_encode([
        'worker'=>$rechatWorker,'message_count'=>is_array($rechatMessages)?count($rechatMessages):null,
        'state'=>$firstRechatState,'action_count'=>$rechatActionCount]));

$finalRechat=$rechatTurn;
$finalRechat['message_id']=$newUuid(824);$finalRechat['request_id']=$newUuid(825);$finalRechat['turn_id']=$newUuid(826);
$finalRechat['payload']['input']['text']='Continue the conversation.';
$finalRechat['payload']['context']['rechat']['depth']=2;
$finalRechat['payload']['context']['rechat']['previous_speaker']=$rechatTurn['payload']['target'];
$finalRechat['payload']['context']['rechat']['previous_listener']=$rechatTurn['payload']['speaker'];
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
$settingsDocument=['schema'=>'almsivi.client-settings.v1','behavior'=>[
    'auto_greeting'=>true,'rechat'=>true,'rechat_delay_seconds'=>60,'rechat_max_depth'=>8,
    'boredom'=>true,'boredom_delay_seconds'=>240,'combat_barks'=>true,'combat_bark_period_seconds'=>30],
    'memory'=>['recent_turn_limit'=>24,'knowledge_limit'=>6],
    'narrator'=>['enabled'=>true,'name'=>'The Temple Chronicler','context_visibility'=>true,'inline_mode'=>'Narrator',
        'welcome_events'=>true,'random_events'=>false,'quest_events'=>true,'book_events'=>true],
    'presentation'=>['show_status_hud'=>true,'transcript_rows'=>10,'tts_volume_boost'=>4],
    'safety'=>['actions_enabled'=>true,'allow_hostile'=>false,'allow_creatures'=>true]];
$settingsService=new ProductService($products,new DeterministicClock(new \DateTimeImmutable($now)));
$settingsService->createRevisioned('global_settings',['installation_id'=>$installationId,'name'=>'Global Settings','content'=>$settingsDocument]);
$configuredSession=$session;$configuredSession['message_id']=$newUuid(304);$configuredSession['generation']=8;
[$status,$configuredAccepted]=$call($router,'POST',$base.'/sessions',$headers($configuredSession['message_id']),[],$configuredSession);
$assert($status===201&&$configuredAccepted['config_revision']==='global-settings-r1'
    &&$configuredAccepted['client_settings']==$settingsDocument,
    'revisioned installation settings were not returned by the next OpenMW session handshake');
$configuredDeleteKey=$newUuid(305);
[$status,$configuredEnded]=$call($router,'DELETE',$base.'/sessions/'.$configuredAccepted['session_id'],['Idempotency-Key'=>$configuredDeleteKey]);
$assert($status===200&&$configuredEnded['ended']===true,'configured integration session did not end cleanly');
if (is_dir($mediaPath)) {
    foreach (glob($mediaPath . '/*') ?: [] as $file) unlink($file);
    rmdir($mediaPath);
}

fwrite(STDOUT, "integration vertical slice passed\n");
