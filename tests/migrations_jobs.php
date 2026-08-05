<?php

declare(strict_types=1);

use ALMSIVIserver\Application\ActionPolicyValidator;
use ALMSIVIserver\Application\DeterministicClock;
use ALMSIVIserver\Application\FirstPartyJobHandlerFactory;
use ALMSIVIserver\Application\JobHandler;
use ALMSIVIserver\Application\JobHandlerRegistry;
use ALMSIVIserver\Application\ProductService;
use ALMSIVIserver\Application\Worker;
use ALMSIVIserver\Http\ManagementRouter;
use ALMSIVIserver\Http\Request;
use ALMSIVIserver\Infrastructure\ActionCatalogRepository;
use ALMSIVIserver\Infrastructure\Connection;
use ALMSIVIserver\Infrastructure\EventLogRepository;
use ALMSIVIserver\Infrastructure\JobRepository;
use ALMSIVIserver\Infrastructure\ManagementRepository;
use ALMSIVIserver\Infrastructure\MigrationRunner;
use ALMSIVIserver\Infrastructure\ProductRepository;
use ALMSIVIserver\Infrastructure\ProviderAttemptRepository;
use ALMSIVIserver\Infrastructure\Uuid;

require dirname(__DIR__) . '/src/Autoload.php';

$dsn = getenv('ALMSIVI_TEST_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "ALMSIVI_TEST_DSN is required\n");
    exit(2);
}
$db = Connection::open([
    'database_dsn' => $dsn,
    'database_user' => getenv('ALMSIVI_TEST_DB_USER') ?: '',
    'database_password' => getenv('ALMSIVI_TEST_DB_PASSWORD') ?: '',
]);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$runner = new MigrationRunner($db, dirname(__DIR__) . '/database/migrations');
$expectedVersions = array_map(
    static fn(string $path): int => (int) substr(basename($path), 0, 3),
    glob(dirname(__DIR__) . '/database/migrations/*.up.sql') ?: [],
);
sort($expectedVersions, SORT_NUMERIC);
$latestVersion = $expectedVersions[array_key_last($expectedVersions)] ?? throw new RuntimeException('no source migrations found');
$check($runner->up() === $expectedVersions, 'fresh up did not apply ordered migrations');
$eventlogColumns=$db->query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='eventlog' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
$check($eventlogColumns===['rowid','type','data','sess','gamets','localts','ts','people','location','party','utterance_id','delivery_state'],
    'eventlog does not expose the exact compact CHIM column contract: '.json_encode($eventlogColumns));
$check($runner->up() === [], 'up was not idempotent');
$status = $runner->status();
$check(count($status) === count($expectedVersions) && !in_array(false, array_column($status, 'applied'), true), 'migration status is incomplete');
$check($runner->down(1) === [$latestVersion], 'down did not revert latest migration');
$check($runner->up() === [$latestVersion], 'up did not restore reverted migration');
$check($runner->rerun() === $latestVersion, 'rerun did not cycle latest migration');
$check($runner->fresh() === $expectedVersions, 'fresh did not rebuild all migrations');

// Upgrade a populated 004 database: preserve the legacy session while materializing scoped owners.
$upgradeVersions = array_values(array_filter($expectedVersions, static fn(int $version): bool => $version > 4));
$downVersions = array_reverse($upgradeVersions);
$check($runner->down(count($downVersions)) === $downVersions, 'could not prepare populated 004 upgrade fixture');
$legacyInstallation=Uuid::v4();$legacyProfile=Uuid::v4();$legacyPlaythrough=Uuid::v4();$legacySession=Uuid::v4();
$db->prepare('INSERT INTO installations (installation_id,token_fingerprint) VALUES (:id,:token)')->execute(['id'=>$legacyInstallation,'token'=>hash('sha256','legacy')]);
$db->prepare("INSERT INTO sessions (session_id,installation_id,profile_id,playthrough_id,generation,content_fingerprint,openmw_version,openmw_commit,lua_api_revision,client_version,platform,created_at) VALUES (:session,:installation,:profile,:playthrough,1,:fingerprint,'0.51.0',:commit,129,'legacy-test','linux','2025-01-01T00:00:00Z')")->execute(['session'=>$legacySession,'installation'=>$legacyInstallation,'profile'=>$legacyProfile,'playthrough'=>$legacyPlaythrough,'fingerprint'=>'sha256:'.str_repeat('a',64),'commit'=>str_repeat('b',40)]);
$check($runner->up() === $upgradeVersions, 'populated 004 upgrade did not apply product migrations');
$check((int)$db->query("SELECT count(*) FROM sessions WHERE session_id='{$legacySession}'")->fetchColumn()===1, 'legacy session was lost');
$check((int)$db->query("SELECT count(*) FROM profiles WHERE profile_id='{$legacyProfile}' AND installation_id='{$legacyInstallation}'")->fetchColumn()===1, 'legacy profile owner missing');
$check((int)$db->query("SELECT count(*) FROM playthroughs WHERE playthrough_id='{$legacyPlaythrough}' AND profile_id='{$legacyProfile}'")->fetchColumn()===1, 'legacy playthrough owner missing');
$check($runner->down(count($downVersions)) === $downVersions, 'product migration down failed after upgrade');
$check((int)$db->query("SELECT count(*) FROM profiles WHERE profile_id='{$legacyProfile}'")->fetchColumn()===1, '005 down deleted backfilled profile');
$check((int)$db->query("SELECT count(*) FROM playthroughs WHERE playthrough_id='{$legacyPlaythrough}'")->fetchColumn()===1, '005 down deleted backfilled playthrough');
$check($runner->up() === $upgradeVersions, 'product migrations could not reapply after preservation down');

// Populate migration-008-only structures, then prove the documented lossy-compatible down policy:
// all data is archived while 007 can expose only one media per turn, processing STT is returned to
// accepted, legacy delivery rows survive NOT VALID FKs, and 008 reapply restores every row.
$migrationTurn=Uuid::v4();$migrationRequest=Uuid::v4();$migrationMessage=Uuid::v4();
$db->prepare("INSERT INTO turns (turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,speaker,target,audience,context,state,accepted_at) VALUES (:turn,:request,:message,:session,1,'text','en','multi','{}'::jsonb,'{}'::jsonb,'[]'::jsonb,'{}'::jsonb,'complete','2026-01-01T00:00:00Z')")
    ->execute(['turn'=>$migrationTurn,'request'=>$migrationRequest,'message'=>$migrationMessage,'session'=>$legacySession]);
$dialogueIds=[];$mediaIds=[];
for($i=1;$i<=3;++$i){$dialogueIds[$i]=Uuid::v4();$mediaIds[$i]=Uuid::v4();
    $db->prepare("INSERT INTO dialogue_utterances(dialogue_message_id,session_id,turn_id,request_id,generation,utterance_index,utterance_count,speaker,addressee,audience,text,emitted_at,delivery_deadline_at) VALUES(:dialogue,:session,:turn,:request,1,:idx,3,'{}'::jsonb,'{}'::jsonb,'[]'::jsonb,:text,'2026-01-01T00:00:00Z','2026-01-01T00:05:00Z')")
        ->execute(['dialogue'=>$dialogueIds[$i],'session'=>$legacySession,'turn'=>$migrationTurn,'request'=>$migrationRequest,'idx'=>$i,'text'=>'utterance '.$i]);
    $db->prepare("INSERT INTO media_objects(media_id,installation_id,session_id,turn_id,generation,sha256,byte_count,codec,mime_type,duration_ms,expires_at,dialogue_message_id) VALUES(:media,:installation,:session,:turn,1,:sha,44,'wav','audio/wav',1,'2026-01-01T00:05:00Z',:dialogue)")
        ->execute(['media'=>$mediaIds[$i],'installation'=>$legacyInstallation,'session'=>$legacySession,'turn'=>$migrationTurn,'sha'=>hash('sha256','media-'.$i),'dialogue'=>$dialogueIds[$i]]);}
$deliverySource=Uuid::v4();$deliveryMessage=Uuid::v4();
$db->prepare("INSERT INTO source_events(source_event_id,installation_id,session_id,generation,event_kind,occurred_at,schema_name,request_id,turn_id,payload) VALUES(:source,:installation,:session,1,'dialogue.delivery','2026-01-01T00:00:01Z','almsivi.dialogue-delivery-result.v1',:request,:turn,'{}'::jsonb)")
    ->execute(['source'=>$deliverySource,'installation'=>$legacyInstallation,'session'=>$legacySession,'request'=>$migrationRequest,'turn'=>$migrationTurn]);
$db->prepare("INSERT INTO dialogue_delivery_results(dialogue_message_id,source_event_id,message_id,request_id,turn_id,session_id,generation,speaker,status,reason_code,completed_at) VALUES(:dialogue,:source,:message,:request,:turn,:session,1,'{}'::jsonb,'played','ok','2026-01-01T00:00:01Z')")
    ->execute(['dialogue'=>$dialogueIds[1],'source'=>$deliverySource,'message'=>$deliveryMessage,'request'=>$migrationRequest,'turn'=>$migrationTurn,'session'=>$legacySession]);
$sttMessage=Uuid::v4();$sttRequest=Uuid::v4();$sttTurn=Uuid::v4();$sttMedia=Uuid::v4();
$db->prepare("INSERT INTO stt_requests(message_id,request_id,turn_id,session_id,generation,codec,language,audio_bytes,sha256,state,created_at,storage_media_id,semantic_hash,accepted_cursor) VALUES(:message,:request,:turn,:session,1,'wav','en',44,:sha,'processing','2026-01-01T00:00:00Z',:media,:semantic,0)")
    ->execute(['message'=>$sttMessage,'request'=>$sttRequest,'turn'=>$sttTurn,'session'=>$legacySession,'sha'=>hash('sha256','stt-audio'),'media'=>$sttMedia,'semantic'=>hash('sha256','stt-semantic')]);
$acceptedSttMessage=Uuid::v4();$acceptedSttRequest=Uuid::v4();$acceptedSttTurn=Uuid::v4();$acceptedSttMedia=Uuid::v4();
$db->prepare("INSERT INTO stt_requests(message_id,request_id,turn_id,session_id,generation,codec,language,audio_bytes,sha256,state,created_at,storage_media_id,semantic_hash,accepted_cursor) VALUES(:message,:request,:turn,:session,1,'wav','en',44,:sha,'accepted','2026-01-01T00:00:00Z',:media,:semantic,0)")
    ->execute(['message'=>$acceptedSttMessage,'request'=>$acceptedSttRequest,'turn'=>$acceptedSttTurn,'session'=>$legacySession,'sha'=>hash('sha256','accepted-stt-audio'),'media'=>$acceptedSttMedia,'semantic'=>hash('sha256','accepted-stt-semantic')]);
$legacyDialogue=Uuid::v4();$legacySource=Uuid::v4();$legacyDeliveryMessage=Uuid::v4();
$db->prepare("INSERT INTO source_events(source_event_id,installation_id,session_id,generation,event_kind,occurred_at,schema_name,request_id,turn_id,payload) VALUES(:source,:installation,:session,1,'dialogue.delivery','2025-01-01T00:00:01Z','almsivi.dialogue-delivery-result.v1',:request,:turn,'{}'::jsonb)")
    ->execute(['source'=>$legacySource,'installation'=>$legacyInstallation,'session'=>$legacySession,'request'=>$migrationRequest,'turn'=>$migrationTurn]);
$db->exec('ALTER TABLE dialogue_delivery_results DISABLE TRIGGER ALL');
$db->prepare("INSERT INTO dialogue_delivery_results(dialogue_message_id,source_event_id,message_id,request_id,turn_id,session_id,generation,speaker,status,reason_code,completed_at) VALUES(:dialogue,:source,:message,:request,:turn,:session,1,'{}'::jsonb,'played','legacy','2025-01-01T00:00:01Z')")
    ->execute(['dialogue'=>$legacyDialogue,'source'=>$legacySource,'message'=>$legacyDeliveryMessage,'request'=>$migrationRequest,'turn'=>$migrationTurn,'session'=>$legacySession]);
$db->exec('ALTER TABLE dialogue_delivery_results ENABLE TRIGGER ALL');
$downThroughEight=range($latestVersion,8);
$check($runner->down(count($downThroughEight))===$downThroughEight,'populated 008 down failed');
$check((int)$db->query("SELECT count(*) FROM media_objects WHERE turn_id='{$migrationTurn}'")->fetchColumn()===1,'008 down did not expose one 007 media row');
$check((int)$db->query("SELECT count(*) FROM migration_008_media_archive WHERE turn_id='{$migrationTurn}'")->fetchColumn()===3,'008 down lost multi-utterance media archive');
$check($db->query("SELECT state FROM stt_requests WHERE message_id='{$sttMessage}'")->fetchColumn()==='accepted','processing STT was not safely downgraded');
$check($db->query("SELECT state FROM stt_requests WHERE message_id='{$acceptedSttMessage}'")->fetchColumn()==='accepted','accepted STT changed during downgrade');
$check((int)$db->query("SELECT count(*) FROM dialogue_delivery_results WHERE dialogue_message_id='{$legacyDialogue}'")->fetchColumn()===1,'legacy delivery row was lost on down');
try{$db->prepare("INSERT INTO dialogue_delivery_results(dialogue_message_id,source_event_id,message_id,request_id,turn_id,session_id,generation,speaker,status,reason_code,completed_at) VALUES(:dialogue,:source,:message,:request,:turn,:session,1,'{}'::jsonb,'played','invalid','2026-01-01T00:00:01Z')")
    ->execute(['dialogue'=>Uuid::v4(),'source'=>Uuid::v4(),'message'=>Uuid::v4(),'request'=>$migrationRequest,'turn'=>$migrationTurn,'session'=>$legacySession]);throw new RuntimeException('new orphan dialogue delivery accepted');}
catch(PDOException $error){$check($error->getCode()==='23503','unexpected legacy delivery FK error');}
$check($runner->up()===range(8,$latestVersion),'populated 008 reapply failed');
$check((int)$db->query("SELECT count(*) FROM media_objects WHERE turn_id='{$migrationTurn}'")->fetchColumn()===3,'008 reapply did not restore multi-utterance media');
$check((int)$db->query("SELECT count(*) FROM media_objects WHERE turn_id='{$migrationTurn}' AND dialogue_message_id IS NOT NULL")->fetchColumn()===3,'008 reapply lost media dialogue links');
$restoredStt=$db->query("SELECT state,storage_media_id,semantic_hash FROM stt_requests WHERE message_id='{$sttMessage}'")->fetch();
$check($restoredStt['state']==='accepted'&&$restoredStt['storage_media_id']===$sttMedia&&rtrim($restoredStt['semantic_hash'])===hash('sha256','stt-semantic'),'008 reapply did not restore processing STT metadata');
$restoredAcceptedStt=$db->query("SELECT state,storage_media_id,semantic_hash FROM stt_requests WHERE message_id='{$acceptedSttMessage}'")->fetch();
$check($restoredAcceptedStt['state']==='accepted'&&$restoredAcceptedStt['storage_media_id']===$acceptedSttMedia&&rtrim($restoredAcceptedStt['semantic_hash'])===hash('sha256','accepted-stt-semantic'),'008 reapply did not restore accepted STT metadata');
$check((int)$db->query("SELECT count(*) FROM dialogue_delivery_results WHERE dialogue_message_id='{$legacyDialogue}'")->fetchColumn()===1,'legacy delivery row was lost on reapply');

$driftDirectory = sys_get_temp_dir() . '/almsivi-migrations-' . bin2hex(random_bytes(8));
mkdir($driftDirectory, 0700, true);
foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [] as $migrationFile) {
    copy($migrationFile, $driftDirectory . '/' . basename($migrationFile));
}
$driftRunner = new MigrationRunner($db, $driftDirectory);
$check(count($driftRunner->status()) === count($expectedVersions), 'copied source migration status failed');
$driftTarget = glob($driftDirectory . '/*.up.sql')[0] ?? throw new RuntimeException('copied source migration missing');
file_put_contents($driftTarget, "\n-- unauthorized drift\n", FILE_APPEND);
try {
    $driftRunner->status();
    throw new RuntimeException('migration drift was accepted');
} catch (RuntimeException $error) {
    $check(str_contains($error->getMessage(), 'drift detected'), 'unexpected migration drift error');
}
foreach (glob($driftDirectory . '/*.sql') ?: [] as $migrationFile) {
    unlink($migrationFile);
}
rmdir($driftDirectory);

// Product foundations: revisions, deterministic retrieval, scope isolation, provenance,
// relationship audit, narrative export/restore, autonomy clock, browser CSRF, and token rotation.
$installation = '20000000-0000-4000-8000-000000000001';
$tokenHash = hash('sha256', 'initial');
$db->prepare('INSERT INTO installations (installation_id,token_fingerprint) VALUES (:id,:token)')->execute(['id'=>$installation,'token'=>$tokenHash]);
$products = new ProductRepository($db);
$clock = new DeterministicClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
$service = new ProductService($products, $clock);
$check($products->profileAutoLockEnabled($installation),'profile auto-lock did not default on');
$products->setProfileAutoLock($installation,false,$clock->iso());$check(!$products->profileAutoLockEnabled($installation),'profile auto-lock preference did not persist off');
$products->setProfileAutoLock($installation,true,$clock->iso());$check($products->profileAutoLockEnabled($installation),'profile auto-lock preference did not persist on');
$profile = $service->createRevisioned('profile', ['installation_id'=>$installation,'name'=>'Nerevarine','actor_identity'=>['record_id'=>'player'],
    'content'=>['role'=>'player'],'change_reason'=>'created']);
$description=$service->saveItemDescription(['installation_id'=>$installation,'content_file'=>'Morrowind.esm','record_id'=>'iron_dagger','display_name'=>'Iron Dagger','description'=>'A serviceable iron blade.']);
$descriptionTurn=['installation_id'=>$installation,'payload'=>['context'=>['inventory'=>['items'=>[['record_id'=>'iron_dagger','count'=>1]]]]]];
$descriptionContext=$products->itemDescriptionsForTurn($descriptionTurn);
$check(count($descriptionContext)===1&&$descriptionContext[0]['description']==='A serviceable iron blade.','turn context resolves an unambiguous managed record description');
$service->deleteItemDescription($description['description_id']);
$check($products->itemDescriptionsForTurn($descriptionTurn)===[],'deleted record descriptions are excluded from prompts');
$check($profile['current_revision'] === 1, 'profile creation failed');
$profileRevised = $service->revise('profile', $profile['profile_id'], ['role'=>'hero'], 'refined');
$check($profileRevised['current_revision'] === 2 && $profileRevised['content']['role'] === 'hero', 'profile revision failed');
$profileRolled = $service->rollback('profile', $profile['profile_id'], 1, 'restore base');
$check($profileRolled['current_revision'] === 3 && $profileRolled['content']['role'] === 'player', 'profile rollback failed');
$playthrough = $service->createRevisioned('playthrough', ['installation_id'=>$installation,'profile_id'=>$profile['profile_id'],
    'name'=>'Vvardenfell','content'=>['chapter'=>1],'change_reason'=>'created']);
$providerConfig = $service->createRevisioned('provider', ['installation_id'=>$installation,'profile_id'=>$profile['profile_id'],
    'name'=>'Local mock','content'=>['driver'=>'mock','model'=>'deterministic-mock-v1'],'change_reason'=>'created']);
$check($providerConfig['content']['driver'] === 'mock', 'mock provider config failed');
$actionPolicy=$service->createRevisioned('action_policy',['installation_id'=>$installation,'name'=>'Safe actions',
    'content'=>['enabled'=>true,'max_tier'=>1,'denied_actions'=>['item.give']]]);
$check($actionPolicy['content']['max_tier']===1,'bounded action policy config failed');
try{$service->revise('action_policy',$actionPolicy['configuration_id'],['enabled'=>'yes'],'unsafe edit');throw new RuntimeException('invalid action policy accepted');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='invalid_action_policy','unexpected action policy boundary error');}
$ttsConfig=$service->createRevisioned('tts_provider',['installation_id'=>$installation,'name'=>'Local OmniVoice',
    'content'=>['driver'=>'omnivoice','endpoint'=>'http://127.0.0.1:8021','model'=>'k2-fsa/OmniVoice',
        'voice'=>'test-voice','language'=>'en','timeout_ms'=>30000,'options'=>[]]]);
$sttConfig=$service->createRevisioned('stt_provider',['installation_id'=>$installation,'name'=>'Local Parakeet',
    'content'=>['driver'=>'parakeet','endpoint'=>'http://127.0.0.1:8022/v1/audio/transcriptions',
        'model'=>'parakeet-tdt-0.6b-v3','voice'=>'','language'=>'en','timeout_ms'=>30000,'options'=>[]]]);
$service->selectConnector(['installation_id'=>$installation,'kind'=>'tts_provider','configuration_id'=>$ttsConfig['configuration_id']]);
$service->selectConnector(['installation_id'=>$installation,'kind'=>'stt_provider','configuration_id'=>$sttConfig['configuration_id']]);
$check(count($products->listRevisioned('provider',$installation))===1
    &&$products->connectorForInstallation($installation,'tts_provider')['configuration_id']===$ttsConfig['configuration_id']
    &&count($products->connectorSelections($installation))===2,'speech connector presets are isolated and selectable');
try{$service->deleteRevisioned('stt_provider',$sttConfig['configuration_id']);throw new RuntimeException('active connector deleted');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='connector_in_use','unexpected active connector deletion error');}
$unusedStt=$service->createRevisioned('stt_provider',['installation_id'=>$installation,'name'=>'Unused Parakeet',
    'content'=>['driver'=>'parakeet','endpoint'=>'http://127.0.0.1:8022/v1/audio/transcriptions',
        'model'=>'parakeet-tdt-0.6b-v3','voice'=>'','language'=>'en','timeout_ms'=>30000,'options'=>[]]]);
$service->deleteRevisioned('stt_provider',$unusedStt['configuration_id']);
$check($products->connectorForInstallation($installation,'stt_provider')['configuration_id']===$sttConfig['configuration_id']
    &&count($products->listRevisioned('stt_provider',$installation))===1,'unused connector deletion changed the active selection');
$guardedProvider=$service->createRevisioned('provider',['installation_id'=>$installation,'name'=>'Profile-bound model slot',
    'content'=>['driver'=>'mock','model'=>'deterministic-mock-v1']]);
$guardedProfile=$service->createRevisioned('profile',['installation_id'=>$installation,'name'=>'Profile-bound NPC',
    'actor_identity'=>['kind'=>'npc','record_id'=>'bound_npc','content_file'=>'Morrowind.esm'],
    'content'=>['routing'=>['llm_configuration_id'=>$guardedProvider['configuration_id']]]]);
try{$service->deleteRevisioned('provider',$guardedProvider['configuration_id']);throw new RuntimeException('profile-bound model slot deleted');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='provider_in_use','unexpected model-slot deletion error');}
$service->revise('profile',$guardedProfile['profile_id'],['routing'=>[]],'remove model-slot assignment');
$service->deleteRevisioned('provider',$guardedProvider['configuration_id']);
$guardedPrompt=$service->createRevisioned('prompt',['installation_id'=>$installation,'name'=>'Profile-bound prompt','content'=>['instruction'=>'Use the explicitly selected prompt.']]);
$service->revise('profile',$guardedProfile['profile_id'],['routing'=>['prompt_configuration_id'=>$guardedPrompt['configuration_id']]],'assign prompt');
try{$service->deleteRevisioned('prompt',$guardedPrompt['configuration_id']);throw new RuntimeException('profile-bound prompt deleted');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='prompt_in_use','unexpected prompt deletion error');}
$service->revise('profile',$guardedProfile['profile_id'],['routing'=>[]],'remove prompt assignment');
$service->deleteRevisioned('prompt',$guardedPrompt['configuration_id']);$service->deleteRevisioned('profile',$guardedProfile['profile_id']);
try {$service->createRevisioned('provider', ['installation_id'=>$installation,'name'=>'unsafe','content'=>['driver'=>'remote','api_key'=>'secret']]); throw new RuntimeException('provider secret accepted');}
catch (InvalidArgumentException $error) {$check(in_array($error->getMessage(), ['secret_not_accepted','invalid_provider_driver'], true), 'unexpected provider boundary error');}
$scope=['installation_id'=>$installation,'profile_id'=>$profile['profile_id'],'playthrough_id'=>$playthrough['playthrough_id']];
$selectedPrompt=$service->createRevisioned('prompt',['installation_id'=>$installation,'name'=>'Explicit selected prompt','content'=>['instruction'=>'This exact prompt must win.']]);
$service->revise('profile',$profile['profile_id'],['role'=>'player','routing'=>['prompt_configuration_id'=>$selectedPrompt['configuration_id']]],'select explicit prompt');
$promptContext=$products->promptContext($scope+['session_id'=>'20000000-0000-4000-8000-000000000099','payload'=>['target'=>['kind'=>'npc','record_id'=>'fargoth','content_file'=>'Morrowind.esm']]],$clock->iso());
$check($promptContext['prompt']['configuration_id']===$selectedPrompt['configuration_id']&&$promptContext['prompt']['content']['instruction']==='This exact prompt must win.','profile-selected prompt was not used');
$service->revise('profile',$profile['profile_id'],['role'=>'player'],'remove explicit prompt');$service->deleteRevisioned('prompt',$selectedPrompt['configuration_id']);
$memory=$service->createMemory($scope+['tier'=>'recent','content'=>'Nalcarya sells alchemy supplies in Balmora.',
    'provenance'=>['source'=>'authored-test']]);
$search=$service->searchMemory($scope,'alchemy Balmora');
$check($search['results'][0]['id'] === $memory['memory_id'] && $search['results'][0]['score'] > 0, 'deterministic memory retrieval failed');
$products->updateMemory($memory['memory_id'],'Nalcarya sells potions.', ['nalcarya','potions'], [0,0,0,0,0,0,0,0], $clock->iso());
$check($products->rebuildMemories($scope,$clock->iso()) === 1, 'memory rebuild failed');
$knowledge=$service->ingestKnowledge($scope+['title'=>'Balmora services','content'=>'Nalcarya operates an alchemy shop.',
    'provenance'=>['source'=>'authored-test']]);
$knowledgeSearch=$service->searchKnowledge($scope,'alchemy shop');
$check($knowledgeSearch['results'][0]['id'] === $knowledge['document_id'], 'knowledge retrieval failed');
$relationship=$service->setRelationship($scope+['actor_identity'=>['record_id'=>'nalcarya'],'disposition'=>20,'affinity'=>5,
    'source_mode'=>'manual','reason'=>'test']);
$check($relationship['disposition'] === 20 && count($products->relationships($scope)) === 1, 'relationship audit foundation failed');
$service->createNarrative($scope+['kind'=>'diary','title'=>'Arrival','content'=>'I reached Balmora.',
    'provenance'=>['source'=>'authored-test']]);
$export=$service->exportPlaythrough($scope);
$check($export['schema'] === 'almsivi.playthrough-export.v1' && count($export['data']['narratives']) === 1, 'playthrough export failed');
$management=new ManagementRepository($db);
$browser=$management->createSession(60);
$check($management->validate($browser['session']) && $management->validate($browser['session'],$browser['csrf']) && !$management->validate($browser['session'],'wrong'), 'browser session or CSRF failed');
$newHash=hash('sha256','rotated');
$rotation=$management->rotatePairingToken($installation,$newHash,60);
$check(is_string($rotation['mac_key']??null)&&$rotation['overlap_seconds']===60, 'pairing token rotation failed');
$management->revokePairingToken($rotation['pairing_token_id']);
$check((int)$db->query("SELECT count(*) FROM pairing_tokens WHERE pairing_token_id='".$rotation['pairing_token_id']."' AND state='revoked'")->fetchColumn()===1,'pairing token revocation failed');
$check($management->authorizePairing('Bearer rotated') === false, 'revoked pairing token authorized');
$eventLogs=new EventLogRepository($db);
$baseEventRow=(int)$db->query('SELECT COALESCE(max(rowid),0) FROM eventlog')->fetchColumn();
$insertEvent=$db->prepare("INSERT INTO eventlog(type,data,sess,gamets,localts,ts,people) VALUES('death',:data,NULL,:gamets,:localts,:ts,'|Player|') RETURNING rowid");
$insertMetadata=$db->prepare("INSERT INTO eventlog_metadata(rowid,installation_id,playthrough_id,profile_id,projection_kind,projection_key,speaker,target,audience,payload) VALUES(:rowid,:installation,:playthrough,:profile,'cursor_test',:key,'{}'::jsonb,'{}'::jsonb,'[]'::jsonb,'{}'::jsonb)");
for($index=1;$index<=12;$index++){$insertEvent->execute(['data'=>'cursor event '.$index,'gamets'=>$index,'localts'=>1_700_000_000+$index,'ts'=>1_700_000_000_000+$index]);$rowid=(int)$insertEvent->fetchColumn();$insertMetadata->execute(['rowid'=>$rowid,'installation'=>$installation,'playthrough'=>$playthrough['playthrough_id'],'profile'=>$profile['profile_id'],'key'=>'cursor-test:'.$index]);}
$firstCursorPage=$eventLogs->page(['installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id'],'since_rowid'=>$baseEventRow,'limit'=>10]);
$firstCursorIds=array_column($firstCursorPage['data'],'rowid');$nextCursor=max($firstCursorIds);
$secondCursorPage=$eventLogs->page(['installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id'],'since_rowid'=>$nextCursor,'limit'=>10]);
$check(count($firstCursorIds)===10&&$nextCursor===$baseEventRow+10&&count($secondCursorPage['data'])===2,
    'eventlog live cursor skipped or duplicated a burst window');
$managementRouter=new ManagementRouter($management,$products,$service,eventLogRepository:$eventLogs);
$denied=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/api/v1/diagnostics'));
$check($denied->status===401, 'management API accepted missing browser session');
$signed=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/quickstart'));
$check($signed->status===303 && ($signed->headers['Location']??'')==='/ALMSIVIserver/ui/home.php', 'legacy management route did not redirect to sibling-style PHP page');
$csrf=$browser['csrf'];$cookie='almsivi_management='.$browser['session'].'; almsivi_csrf='.$csrf;
$home=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/quickstart',['Cookie'=>$cookie]));
$check($home->status===303 && ($home->headers['Location']??'')==='/ALMSIVIserver/ui/home.php', 'authenticated legacy route did not preserve the PHP page redirect');
$diagnostics=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/api/v1/diagnostics',['Cookie'=>$cookie]));
$check($diagnostics->status===200 && !str_contains($diagnostics->body,'manage-secret'), 'management diagnostics auth or redaction failed');
$eventlogResponse=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/api/v1/eventlog',['Cookie'=>$cookie],['installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id'],'limit'=>'10']));
$eventlogBody=json_decode($eventlogResponse->body,true,32,JSON_THROW_ON_ERROR);
$check($eventlogResponse->status===200&&count($eventlogBody['data'])===10,'authenticated CHIM eventlog API failed');
$eventlogFilterDenied=$managementRouter->dispatch(new Request('POST','/ALMSIVIserver/manage/api/v1/eventlog/hidden-types',['Cookie'=>$cookie,'Content-Type'=>'application/json'],[],json_encode(['action'=>'hide','type'=>'death','installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id']])));
$check($eventlogFilterDenied->status===401,'eventlog filter mutation accepted missing CSRF');
$eventlogFilterAccepted=$managementRouter->dispatch(new Request('POST','/ALMSIVIserver/manage/api/v1/eventlog/hidden-types',['Cookie'=>$cookie,'X-CSRF-Token'=>$csrf,'Content-Type'=>'application/json'],[],json_encode(['action'=>'hide','type'=>'death','installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id']])));
$check($eventlogFilterAccepted->status===200,'eventlog filter mutation rejected valid browser CSRF');
$eventLogs->suppress(['mode'=>'latest','count'=>5,'installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id']]);
$hiddenCursorSuppressions=(int)$db->query("SELECT count(*) FROM eventlog_metadata WHERE rowid>{$baseEventRow} AND projection_kind='cursor_test' AND suppressed_at IS NOT NULL")->fetchColumn();
$check($hiddenCursorSuppressions===0,'delete latest suppressed a custom-hidden event type');
$deleteRow=min($firstCursorIds);
$eventlogDeleteDenied=$managementRouter->dispatch(new Request('DELETE','/ALMSIVIserver/manage/api/v1/eventlog',['Cookie'=>$cookie,'Content-Type'=>'application/json'],[],json_encode(['mode'=>'row','rowid'=>$deleteRow,'installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id']])));
$check($eventlogDeleteDenied->status===401,'eventlog delete accepted missing CSRF');
$eventlogDeleteAccepted=$managementRouter->dispatch(new Request('DELETE','/ALMSIVIserver/manage/api/v1/eventlog',['Cookie'=>$cookie,'X-CSRF-Token'=>$csrf,'Content-Type'=>'application/json'],[],json_encode(['mode'=>'row','rowid'=>$deleteRow,'installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id']])));
$check($eventlogDeleteAccepted->status===200&&json_decode($eventlogDeleteAccepted->body,true,8,JSON_THROW_ON_ERROR)['deleted_count']===1,
    'eventlog row delete rejected valid browser CSRF or escaped its scope');
$csrfDenied=$managementRouter->dispatch(new Request('POST','/ALMSIVIserver/manage/api/v1/operations/retention',['Cookie'=>$cookie,'Content-Type'=>'application/json'],[],'{"days":30}'));
$check($csrfDenied->status===401, 'management write accepted missing CSRF');
$csrfAccepted=$managementRouter->dispatch(new Request('POST','/ALMSIVIserver/manage/api/v1/operations/retention',['Cookie'=>$cookie,'X-CSRF-Token'=>$csrf,'Content-Type'=>'application/json'],[],'{"days":30}'));
$check($csrfAccepted->status===200, 'management write rejected valid CSRF');

$jobs = new JobRepository($db);
$jobId = Uuid::v4();
$first = $jobs->enqueue($jobId, 'test.succeed', 1, 'source:1', ['source_id' => 'one'], 3);
$duplicate = $jobs->enqueue(Uuid::v4(), 'test.succeed', 1, 'source:1', ['source_id' => 'one'], 3);
$check($duplicate['job_id'] === $jobId && $first['state'] === 'queued', 'enqueue idempotency failed');
try {
    $jobs->enqueue(Uuid::v4(), 'test.succeed', 1, 'source:1', ['source_id' => 'changed'], 3);
    throw new RuntimeException('conflicting enqueue was accepted');
} catch (RuntimeException $error) {
    $check($error->getMessage() === 'job_idempotency_conflict', 'unexpected enqueue conflict error');
}

$claimed = $jobs->claim('worker-a', 1, 5, ['test.succeed']);
$check(count($claimed) === 1 && $claimed[0]['attempt_count'] === 1, 'claim failed');
$check(!$jobs->heartbeat($jobId, Uuid::v4(), 5), 'wrong lease token heartbeat succeeded');
$check($jobs->heartbeat($jobId, $claimed[0]['lease_token'], 5), 'heartbeat failed');
$check($jobs->succeed($jobId, $claimed[0]['lease_token']), 'completion failed');
$check($jobs->claim('worker-b', 1, 5, ['test.succeed']) === [], 'completed job was reclaimed');

$retryId = Uuid::v4();
$jobs->enqueue($retryId, 'test.retry', 1, 'source:2', ['source_id' => 'two'], 2);
$claim = $jobs->claim('worker-a', 1, 5, ['test.retry'])[0];
$check($jobs->fail($retryId, $claim['lease_token'], 'transient', 'safe detail', 0) === 'retry', 'retry was not scheduled');
$claim = $jobs->claim('worker-b', 1, 5, ['test.retry'])[0];
$check($claim['attempt_count'] === 2, 'attempt count did not advance');
$check($jobs->fail($retryId, $claim['lease_token'], 'terminal', 'safe detail', 0) === 'dead', 'max attempts did not dead-letter');
$replayed = $jobs->replayDeadLetter($retryId, Uuid::v4(), 'source:2:replay:1');
$check($replayed['state'] === 'queued' && $replayed['job_id'] !== $retryId, 'dead-letter replay failed');

$reclaimId = Uuid::v4();
$jobs->enqueue($reclaimId, 'test.reclaim', 1, 'source:3', ['source_id' => 'three'], 2);
$old = $jobs->claim('worker-a', 1, 5, ['test.reclaim'])[0];
$db->prepare("UPDATE durable_jobs SET lease_expires_at = clock_timestamp() - interval '1 second' WHERE job_id = :id")
    ->execute(['id' => $reclaimId]);
$new = $jobs->claim('worker-b', 1, 5, ['test.reclaim'])[0];
$check($new['attempt_count'] === 2 && $new['lease_token'] !== $old['lease_token'], 'expired lease was not reclaimed');
try {
    $jobs->succeed($reclaimId, $old['lease_token']);
    throw new RuntimeException('stale lease completed reclaimed job');
} catch (RuntimeException $error) {
    $check($error->getMessage() === 'lease_lost', 'unexpected stale lease error');
}
$jobs->succeed($reclaimId, $new['lease_token']);

$provider = new ProviderAttemptRepository($db);
$providerId = Uuid::v4();
$provider->start($providerId, 'llm', 'mock', 'complete', 1, null, null, null, 'mock-v1', 'config-1', 12, ['redacted' => true]);
$check($provider->finish($providerId, 'succeeded', 24), 'provider attempt did not finish');
$check(!$provider->finish($providerId, 'failed', null, 'late', 'late completion'), 'provider attempt completed twice');

$firstPartyMediaRoot=sys_get_temp_dir().'/almsivi-first-party-'.bin2hex(random_bytes(6));
$firstPartyRegistry=FirstPartyJobHandlerFactory::registry($db,new \ALMSIVIserver\Infrastructure\MediaStore($firstPartyMediaRoot,1024,2048),$clock);
$profileBefore=(int)$db->query("SELECT current_revision FROM profiles WHERE profile_id='{$scope['profile_id']}'")->fetchColumn();
$profileJob=Uuid::v4();$jobs->enqueue($profileJob,'profile.generate',1,'profile.generate:test',
    ['profile_id'=>$scope['profile_id'],'base_revision'=>$profileBefore],3);
$profileStats=(new Worker($jobs,$firstPartyRegistry,'profile-generate-test',5,1,1,0,10,['profile.generate'],static fn(int $microseconds):mixed=>null))->run();
$profileAfter=$db->query("SELECT p.current_revision,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id='{$scope['profile_id']}'")->fetch();
$check($profileStats['succeeded']===1&&(int)$profileAfter['current_revision']===$profileBefore+1&&str_contains((string)$profileAfter['content'],'Deterministic mock generation'), 'profile generation did not create a revision');
$generatedContent=json_decode((string)$profileAfter['content'],true,64,JSON_THROW_ON_ERROR);
$lockedProfile=$service->revise('profile',$scope['profile_id'],$generatedContent+['management'=>['locked'=>true,'favorite'=>true]],'lock generated profile');
try{$products->enqueueProfileGeneration($scope['profile_id']);throw new RuntimeException('locked profile queued automatic generation');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='profile_locked','unexpected locked profile queue error');}
$lockedJob=Uuid::v4();$jobs->enqueue($lockedJob,'profile.generate',1,'profile.generate:locked-test',
    ['profile_id'=>$scope['profile_id'],'base_revision'=>$lockedProfile['current_revision']],3);
$lockedStats=(new Worker($jobs,$firstPartyRegistry,'profile-locked-test',5,1,1,0,10,['profile.generate'],static fn(int $microseconds):mixed=>null))->run();
$lockedRevision=(int)$db->query("SELECT current_revision FROM profiles WHERE profile_id='{$scope['profile_id']}'")->fetchColumn();
$check($lockedStats['succeeded']===1&&$lockedRevision===$lockedProfile['current_revision'],'locked profile generation changed the current revision');
$playerProfile=$service->createRevisioned('profile',['installation_id'=>$legacyInstallation,'name'=>'Test Nerevarine',
    'actor_identity'=>['kind'=>'player','record_id'=>'player','content_file'=>'Morrowind.esm','display_name'=>'Test Nerevarine'],
    'content'=>['biography'=>'Arrived by prison ship.','speech_style'=>'Not analyzed.']]);
$playerTurn=Uuid::v4();$playerRequest=Uuid::v4();$playerMessage=Uuid::v4();
$db->prepare("INSERT INTO turns (turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,speaker,target,audience,context,state,accepted_at) VALUES (:turn,:request,:message,:session,1,'text','en','Can you tell me where the nearest guild is?',CAST(:speaker AS jsonb),'{}'::jsonb,'[]'::jsonb,'{}'::jsonb,'complete','2026-01-01T00:00:01Z')")
    ->execute(['turn'=>$playerTurn,'request'=>$playerRequest,'message'=>$playerMessage,'session'=>$legacySession,'speaker'=>json_encode(['kind'=>'player','record_id'=>'player','content_file'=>'Morrowind.esm'],JSON_THROW_ON_ERROR)]);
$queuedPlayerStyle=$products->enqueuePlayerSpeechStyleGeneration($playerProfile['profile_id']);
$playerStyleStats=(new Worker($jobs,$firstPartyRegistry,'player-speech-style-test',5,1,1,0,10,['profile.generate'],static fn(int $microseconds):mixed=>null))->run();
$playerStyleRow=$db->query("SELECT p.current_revision,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id='{$playerProfile['profile_id']}'")->fetch();
$playerStyleContent=json_decode((string)$playerStyleRow['content'],true,64,JSON_THROW_ON_ERROR);
$check(($queuedPlayerStyle['mode']??null)==='player_speech_style'&&$playerStyleStats['succeeded']===1
    &&(int)$playerStyleRow['current_revision']===2&&str_contains((string)$playerStyleContent['speech_style'],'1 recent player input')
    &&$playerStyleContent['biography']==='Arrived by prison ship.','player speech-style generation did not preserve non-style fields');
$narratorProfile=$service->createRevisioned('profile',['installation_id'=>$legacyInstallation,'name'=>'Test Narrator',
    'actor_identity'=>['kind'=>'narrator','record_id'=>'almsivi:narrator','content_file'=>'ALMSIVI','display_name'=>'Test Narrator'],
    'content'=>['enabled'=>true,'inline_narration_mode'=>'Narrator','biography'=>'Existing narrator background.',
        'routing'=>['tts_configuration_id'=>Uuid::v4()],'voice'=>['id'=>'narrator-test','language'=>'en']]]);
$queuedNarrator=$products->enqueueNarratorProfileGeneration($narratorProfile['profile_id']);
$narratorStats=(new Worker($jobs,$firstPartyRegistry,'narrator-profile-test',5,1,1,0,10,['profile.generate'],static fn(int $microseconds):mixed=>null))->run();
$narratorRow=$db->query("SELECT p.current_revision,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id='{$narratorProfile['profile_id']}'")->fetch();
$narratorContent=json_decode((string)$narratorRow['content'],true,64,JSON_THROW_ON_ERROR);
$check(($queuedNarrator['mode']??null)==='narrator_profile'&&$narratorStats['succeeded']===1
    &&(int)$narratorRow['current_revision']===2&&str_contains((string)$narratorContent['notes'],'mock narrator generation')
    &&$narratorContent['enabled']===true&&$narratorContent['inline_narration_mode']==='Narrator'
    &&($narratorContent['routing']['tts_configuration_id']??null)===($narratorProfile['content']['routing']['tts_configuration_id']??null)
    &&($narratorContent['voice']['id']??null)==='narrator-test','narrator profile generation did not preserve routing fields');
$switchTarget=$service->createRevisioned('profile',['installation_id'=>$installation,'name'=>'Alternate NPC profile',
    'actor_identity'=>['kind'=>'npc','record_id'=>'alternate','content_file'=>'Morrowind.esm'],
    'content'=>['biography'=>'Alternate profile','management'=>['locked'=>false,'favorite'=>false]]]);
$switchActor=['kind'=>'npc','record_id'=>'nalcarya','content_file'=>'Morrowind.esm'];
$products->bindActorProfile($scope,$switchActor,$scope['profile_id'],$clock->iso());
$skippedSwitch=$products->bulkSwitchNpcProfileBindings($installation,$scope['profile_id'],$switchTarget['profile_id'],false,$clock->iso());
$check($skippedSwitch===['updated'=>0,'skipped_locked'=>1],'bulk profile switch did not respect the source lock');
$appliedSwitch=$products->bulkSwitchNpcProfileBindings($installation,$scope['profile_id'],$switchTarget['profile_id'],true,$clock->iso());
$boundProfile=$db->query("SELECT profile_id FROM actor_profile_bindings WHERE installation_id='{$installation}' AND playthrough_id='{$playthrough['playthrough_id']}'")->fetchColumn();
$check($appliedSwitch===['updated'=>1,'skipped_locked'=>0]&&$boundProfile===$switchTarget['profile_id'],'bulk profile switch did not move the actor binding');
$batchGeneration=$products->bulkEnqueueNpcProfileGeneration($installation);
$batchStats=(new Worker($jobs,$firstPartyRegistry,'profile-bulk-generate-test',5,1,1,0,10,['profile.generate'],static fn(int $microseconds):mixed=>null))->run();
$batchRevision=(int)$db->query("SELECT current_revision FROM profiles WHERE profile_id='{$switchTarget['profile_id']}'")->fetchColumn();
$check($batchGeneration===['queued'=>1,'eligible'=>1,'truncated'=>0]&&$batchStats['succeeded']===1&&$batchRevision===2,
    'bounded bulk profile generation did not queue only the unlocked NPC profile');
$check($products->bulkDeleteUnlockedNpcProfiles($installation,$clock->iso())===1
    &&(int)$db->query("SELECT count(*) FROM actor_profile_bindings WHERE profile_id='{$switchTarget['profile_id']}'")->fetchColumn()===0,
    'bulk delete did not remove only the unlocked NPC profile and its binding');
$check($products->bulkUnlockNpcProfiles($installation,$clock->iso())===1,'bulk unlock did not revise the locked NPC profile');
$derivedMemoryId='30000000-0000-4000-8000-000000000001';$derivedPayload=$scope+['memory_id'=>$derivedMemoryId,'tier'=>'recent','content'=>'Deterministic derived memory.'];
$derive=$firstPartyRegistry->for('memory.derive',1);$derive->handle($derivedPayload,'memory.derive:test',static fn():bool=>true);$derive->handle($derivedPayload,'memory.derive:test',static fn():bool=>true);
$check((int)$db->query("SELECT count(*) FROM memory_records WHERE memory_id='{$derivedMemoryId}'")->fetchColumn()===1, 'first-party memory derive was not idempotent');
$badFirstParty=Uuid::v4();$jobs->enqueue($badFirstParty,'memory.derive',1,'memory.derive:retry',['memory_id'=>'bad'],2);
$retryWorker=new Worker($jobs,$firstPartyRegistry,'first-party-retry',5,1,1,1,10,['memory.derive']);$retryStats=$retryWorker->run();
$retryState=$db->query("SELECT state,attempt_count FROM durable_jobs WHERE job_id='{$badFirstParty}'")->fetch();
$check($retryStats['retried']===1 && $retryState['state']==='queued' && (int)$retryState['attempt_count']===1, 'first-party handler failure did not schedule retry');

$db->prepare("UPDATE sessions SET capabilities=ARRAY['action.inspect.report','action.ai.follow','action.ai.travel','action.ai.escort','action.ai.face','action.animation.play','action.item.use'],enabled_actions=ARRAY['inspect.report','ai.follow','ai.travel','ai.escort','ai.face','animation.play','item.use'] WHERE session_id=:id")->execute(['id'=>$legacySession]);
$catalog=new ActionCatalogRepository($db);$policy=new ActionPolicyValidator();$loaded=$catalog->loadForSession($legacySession,1);
$proposal=['name'=>'ai.follow','tier'=>1,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>['distance'=>192]];
$check($policy->validate($proposal,$loaded)['name']==='ai.follow', 'catalog-backed action validation failed');
$inspect=['name'=>'inspect.report','tier'=>0,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>[]];
$check($policy->validate($inspect,$loaded)['name']==='inspect.report','empty-object inspect action validation failed');
$animation=['name'=>'animation.play','tier'=>1,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>['group'=>'idle2']];
$check($policy->validate($animation,$loaded)['name']==='animation.play','animation action validation failed');
$itemUse=['name'=>'item.use','tier'=>2,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>['record_id'=>'p_restore_health_s']];
$check($policy->validate($itemUse,$loaded)['name']==='item.use','item use action validation failed');
$destination=['destination_x'=>100.5,'destination_y'=>-200,'destination_z'=>8,'destination_cell'=>'exterior:0:0'];
$travel=['name'=>'ai.travel','tier'=>1,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>$destination];
$check($policy->validate($travel,$loaded)['parameters']===$destination,'travel destination validation failed');
$escort=array_replace($travel,['name'=>'ai.escort']);
$check($policy->validate($escort,$loaded)['name']==='ai.escort','escort destination validation failed');
$face=['name'=>'ai.face','tier'=>1,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>[]];
$check($policy->validate($face,$loaded)['name']==='ai.face','face action validation failed');
try{$policy->validate(array_replace($proposal,['parameters'=>['distance'=>64]]),$loaded);throw new RuntimeException('invalid catalog parameters accepted');}catch(DomainException $error){$check($error->getMessage()==='action_parameters_invalid','unexpected catalog parameter error');}
try{$policy->validate(array_replace($travel,['parameters'=>$destination+['teleport'=>true]]),$loaded);throw new RuntimeException('unknown travel parameter accepted');}catch(DomainException $error){$check($error->getMessage()==='action_parameters_invalid','unexpected travel parameter error');}

$traceTurn='40000000-0000-4000-8000-000000000001';$continuationTurn='40000000-0000-4000-8000-000000000002';
foreach([[$traceTurn,'40000000-0000-4000-8000-000000000011','40000000-0000-4000-8000-000000000021'],[$continuationTurn,'40000000-0000-4000-8000-000000000012','40000000-0000-4000-8000-000000000022']] as [$turnId,$requestId,$messageId]){$db->prepare("INSERT INTO turns (turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,speaker,target,audience,context,state,accepted_at) VALUES (:turn,:request,:message,:session,1,'text','en','test','{}'::jsonb,'{}'::jsonb,'[]'::jsonb,'{}'::jsonb,'complete','2026-01-01T00:00:00Z')")->execute(['turn'=>$turnId,'request'=>$requestId,'message'=>$messageId,'session'=>$legacySession]);}
$traceInput=['installation_id'=>$legacyInstallation,'profile_id'=>$legacyProfile,'playthrough_id'=>$legacyPlaythrough,'session_id'=>$legacySession,'turn_id'=>$traceTurn,'request_id'=>'40000000-0000-4000-8000-000000000011'];
$traceMeta=['prompt_configuration_id'=>$legacyProfile,'prompt_revision'=>1,'algorithm'=>'deterministic-prompt-v1','input_sha256'=>hash('sha256','secret prompt body'),'input_bytes'=>18,'truncated'=>false,'sources'=>[['ordinal'=>0,'source_kind'=>'profile','source_id'=>$legacyProfile,'included'=>true,'reason'=>'included','source_sha256'=>hash('sha256','profile body'),'included_bytes'=>12]]];
$traceId=$products->recordPromptTrace($traceInput,$traceMeta,$clock->iso());
$storedTrace=$db->query("SELECT input_sha256,input_bytes FROM prompt_traces WHERE prompt_trace_id='{$traceId}'")->fetch();
$check($storedTrace['input_sha256']===hash('sha256','secret prompt body') && (int)$storedTrace['input_bytes']===18, 'prompt trace metadata was not persisted');
$check((int)$db->query("SELECT count(*) FROM information_schema.columns WHERE table_name='prompt_traces' AND column_name IN ('prompt','content','payload')")->fetchColumn()===0, 'prompt trace schema can persist raw prompts');

$db->exec("UPDATE action_catalog SET continuation_capable=true WHERE action_name='ai.follow'");
$actionId='50000000-0000-4000-8000-000000000001';$sourceId='50000000-0000-4000-8000-000000000002';
$db->prepare("INSERT INTO action_intents (action_id,session_id,turn_id,request_id,generation,action_name,tier,actor,target,parameters,expires_at,state,emitted_at) VALUES (:action,:session,:turn,:request,1,'ai.follow',1,'{}'::jsonb,'{}'::jsonb,'{\"distance\":192}'::jsonb,'2026-01-01T00:10:00Z','terminal','2026-01-01T00:00:00Z')")->execute(['action'=>$actionId,'session'=>$legacySession,'turn'=>$traceTurn,'request'=>'40000000-0000-4000-8000-000000000011']);
$db->prepare("INSERT INTO source_events (source_event_id,installation_id,session_id,generation,event_kind,occurred_at,schema_name,request_id,turn_id,action_id,payload) VALUES (:source,:installation,:session,1,'action.result','2026-01-01T00:00:01Z','almsivi.action-result.v1',:request,:turn,:action,'{}'::jsonb)")->execute(['source'=>$sourceId,'installation'=>$legacyInstallation,'session'=>$legacySession,'request'=>'40000000-0000-4000-8000-000000000011','turn'=>$traceTurn,'action'=>$actionId]);
$db->prepare("INSERT INTO action_results (action_id,source_event_id,message_id,request_id,status,reason_code,observed,completed_at) VALUES (:action,:source,:message,:request,'succeeded','ok','{}'::jsonb,'2026-01-01T00:00:01Z')")->execute(['action'=>$actionId,'source'=>$sourceId,'message'=>'50000000-0000-4000-8000-000000000003','request'=>'40000000-0000-4000-8000-000000000011']);
$db->prepare("INSERT INTO action_delivery (action_id,emitted_at,terminal_at,continuation_state) VALUES (:action,'2026-01-01T00:00:00Z','2026-01-01T00:00:01Z','eligible')")->execute(['action'=>$actionId]);
$check($catalog->claimContinuation($actionId,$continuationTurn), 'terminal continuation was not claimed');
$check(!$catalog->claimContinuation($actionId,$continuationTurn), 'continuation was claimed more than once');

$workerJob = Uuid::v4();
$jobs->enqueue($workerJob, 'test.worker', 1, 'source:4', ['source_id' => 'four'], 1);
$handler = new class implements JobHandler {
    public int $calls = 0;
    public function supports(string $jobType, int $schemaVersion): bool { return $jobType === 'test.worker' && $schemaVersion === 1; }
    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        ++$this->calls;
        if ($payload['source_id'] !== 'four' || $idempotencyKey !== 'source:4' || !$heartbeat()) {
            throw new RuntimeException('handler contract failed');
        }
    }
};
$worker = new Worker($jobs, new JobHandlerRegistry([$handler]), 'bounded-worker', 5, 1, 1, 1, 10, ['test.worker']);
$stats = $worker->run();
$check($stats === ['claimed' => 1, 'succeeded' => 1, 'retried' => 0, 'dead' => 0] && $handler->calls === 1, 'bounded worker failed');

fwrite(STDOUT, "migration and durable job tests passed\n");
