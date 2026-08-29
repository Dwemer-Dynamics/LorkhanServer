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
use ALMSIVIserver\Infrastructure\BiographyCatalogImporter;
use ALMSIVIserver\Infrastructure\Connection;
use ALMSIVIserver\Infrastructure\DescriptionCatalogImporter;
use ALMSIVIserver\Infrastructure\EventLogRepository;
use ALMSIVIserver\Infrastructure\JobRepository;
use ALMSIVIserver\Infrastructure\ManagementRepository;
use ALMSIVIserver\Infrastructure\ManagementUiRepository;
use ALMSIVIserver\Infrastructure\MigrationRunner;
use ALMSIVIserver\Infrastructure\OghmaCatalogImporter;
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
$oghmaRowConstraint=(string)$db->query("SELECT pg_get_constraintdef(oid) FROM pg_constraint "
    ."WHERE conrelid='almsivi_internal.oghma_catalogs'::regclass AND conname='oghma_catalogs_row_count_check'")->fetchColumn();
$check(str_contains($oghmaRowConstraint,'row_count >= 1')&&!str_contains($oghmaRowConstraint,'2000'),
    'fresh schema retained the fixed Oghma catalog row ceiling');
$canonicalTurnColumns=$db->query("SELECT column_name FROM information_schema.columns WHERE table_schema='almsivi_internal' "
    . "AND table_name='turns' AND column_name IN ('runtime_generation','response_id','response_payload','response_created_at') ORDER BY column_name")
    ->fetchAll(PDO::FETCH_COLUMN);
$canonicalDialogueColumns=$db->query("SELECT column_name FROM information_schema.columns WHERE table_schema='almsivi_internal' "
    . "AND table_name='dialogue_utterances' AND column_name IN ('response_line_id','utterance_id','runtime_generation') ORDER BY column_name")
    ->fetchAll(PDO::FETCH_COLUMN);
$check($canonicalTurnColumns===['response_created_at','response_id','response_payload','runtime_generation']
    &&$canonicalDialogueColumns===['response_line_id','runtime_generation','utterance_id'],
    'canonical response projection columns are incomplete');
$eventlogColumns=$db->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='eventlog' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
$check($eventlogColumns===['type','data','sess','gamets','localts','ts','rowid','people','location','party','utterance_id','delivery_state'],
    'eventlog does not expose the exact Herika column contract: '.json_encode($eventlogColumns));
$check($db->query("SELECT to_regclass('public.schema_migrations') IS NULL")->fetchColumn()===true,
    'migration authority leaked into the Herika public schema');
$retiredRelations=[
    'almsivi_internal.autonomy_schedules','public.bgl_history','public.core_faction_politics_development',
    'public.core_faction_politics_relation','public.core_faction_politics_state','public.core_itt_connector',
    'public.master_packages','public.npc_commitments','public.quest_asset_group_members','public.quest_asset_groups',
    'public.quest_asset_imports','public.quest_asset_packs','public.quest_assets','public.quest_item_types',
    'public.quest_npc_own_templates','public.quest_npc_templates','public.quest_outfits','public.quest_weapons',
    'public.skyrim_quest_action_outbox','public.skyrim_quest_beat_state','public.skyrim_quest_definitions',
    'public.skyrim_quest_events','public.skyrim_quest_instances','public.sneq_quests','public.sneq_quests_saved',
    'public.visual_context',
];
foreach($retiredRelations as $retiredRelation){
    $check($db->query('SELECT to_regclass('.$db->quote($retiredRelation).') IS NULL')->fetchColumn()===true,
        'retired beta relation remains: '.$retiredRelation);
}
$check((int)$db->query("SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='core_profiles' AND column_name='itt_connector_id'")->fetchColumn()===0,
    'retired ITT profile column remains');
$check($runner->up() === [], 'up was not idempotent');
$status = $runner->status();
$check(count($status) === count($expectedVersions) && !in_array(false, array_column($status, 'applied'), true), 'migration status is incomplete');
$firstMigrationUp = glob(dirname(__DIR__) . '/database/migrations/001_*.up.sql')[0]
    ?? throw new RuntimeException('first source migration missing');
$firstMigrationDown = substr($firstMigrationUp, 0, -7) . '.down.sql';
$toCrlf = static fn(string $sql): string => str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $sql));
$legacyCrlfChecksum = hash(
    'sha256',
    "up\0" . $toCrlf((string) file_get_contents($firstMigrationUp))
        . "\0down\0" . $toCrlf((string) file_get_contents($firstMigrationDown)),
);
$check(!hash_equals($status[0]['checksum'], $legacyCrlfChecksum), 'line-ending compatibility fixture is not distinct');
$setMigrationChecksum = $db->prepare('UPDATE almsivi_internal.schema_migrations SET checksum = :checksum WHERE version = 1');
$setMigrationChecksum->execute(['checksum' => $legacyCrlfChecksum]);
$check(count($runner->status()) === count($expectedVersions), 'historical CRLF migration checksum was rejected');
$setMigrationChecksum->execute(['checksum' => $status[0]['checksum']]);
$check($runner->down(1) === [$latestVersion], 'down did not revert latest migration');
$check($runner->up() === [$latestVersion], 'up did not restore reverted migration');
$check($runner->rerun() === $latestVersion, 'rerun did not cycle latest migration');
$check($runner->fresh() === $expectedVersions, 'fresh did not rebuild all migrations');

// The exact migration from catalog draft #9 must refuse a lossy rollback of a larger catalog.
$db->beginTransaction();
$db->exec("INSERT INTO almsivi_internal.biography_catalogs(catalog_id,catalog_version,source_kind,biographies_sha256,row_count,state,imported_at,activated_at) "
    ."VALUES('30000000-0000-4000-8000-000000000060','capacity-guard-fixture','legacy_snapshot',repeat('0',64),20000,'superseded',now(),now())");
$db->exec('SAVEPOINT capacity_guard');
try{$db->exec("UPDATE almsivi_internal.biography_catalogs SET row_count=20001 WHERE catalog_version='capacity-guard-fixture'");
    throw new RuntimeException('biography capacity became unbounded');}
catch(PDOException $error){$check($error->getCode()==='23514','unexpected biography capacity failure');$db->exec('ROLLBACK TO SAVEPOINT capacity_guard');}
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/060_biography_catalog_capacity.down.sql'));
    throw new RuntimeException('larger biography catalog was rolled back');}
catch(PDOException $error){$check(str_contains($error->getMessage(),'Cannot restore the 10,000-row biography limit'),'unexpected biography rollback failure');$db->exec('ROLLBACK TO SAVEPOINT capacity_guard');}
$check((int)$db->query("SELECT row_count FROM almsivi_internal.biography_catalogs WHERE catalog_version='capacity-guard-fixture'")->fetchColumn()===20000,
    'refused rollback changed the biography catalog');
$db->rollBack();

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
// Upgrade relationship data without merging ambiguous identities or losing existing audit entries.
$db->beginTransaction();
$db->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/063_relationship_record_revisions.down.sql'));
$legacyRelationship=Uuid::v4();$legacyDuplicate=Uuid::v4();
$legacyInsert=$db->prepare("INSERT INTO relationship_records(relationship_id,installation_id,profile_id,playthrough_id,actor_identity,disposition,affinity,source_mode) "
    ."VALUES(:id,:installation,:profile,:playthrough,'{\"record_id\":\"legacy_duplicate\",\"display_name\":\"Legacy actor\"}',17,-3,'manual')");
foreach([$legacyRelationship,$legacyDuplicate] as $id)$legacyInsert->execute(['id'=>$id,'installation'=>$legacyInstallation,'profile'=>$legacyProfile,'playthrough'=>$legacyPlaythrough]);
$db->exec("INSERT INTO relationship_audit(audit_id,relationship_id,mode,after_value,reason) VALUES('".Uuid::v4()."','{$legacyRelationship}','manual','{\"disposition\":17,\"affinity\":-3}','Legacy reason')");
$legacyRows=$db->query('SELECT relationship_id,actor_identity,disposition,affinity FROM relationship_records ORDER BY relationship_id')->fetchAll();
$db->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/063_relationship_record_revisions.up.sql'));
$check($db->query('SELECT relationship_id,actor_identity,disposition,affinity FROM relationship_records ORDER BY relationship_id')->fetchAll()===$legacyRows
    &&(int)$db->query('SELECT count(*) FROM relationship_records WHERE revision=1')->fetchColumn()===2
    &&$db->query("SELECT reason FROM relationship_audit WHERE relationship_id='{$legacyRelationship}'")->fetchColumn()==='Legacy reason',
    'relationship upgrade changed legacy rows or history');
$db->exec("UPDATE relationship_records SET disposition=18 WHERE relationship_id='{$legacyRelationship}'");
$check((int)$db->query("SELECT revision FROM relationship_records WHERE relationship_id='{$legacyRelationship}'")->fetchColumn()===2,'direct relationship writes bypass revision protection');
$db->exec('SAVEPOINT relationship_rollback_guard');
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/063_relationship_record_revisions.down.sql'));
    throw new RuntimeException('edited relationship lost revision protection');}
catch(PDOException $error){$check(str_contains($error->getMessage(),'Cannot remove relationship revision protection'),'unexpected relationship rollback failure');$db->exec('ROLLBACK TO SAVEPOINT relationship_rollback_guard');}
$db->rollBack();
$journalTurn=Uuid::v4();$journalRequest=Uuid::v4();$journalMessage=Uuid::v4();
$journalContext=json_encode(['journal'=>['items'=>[['quest_id'=>'A1_1_FindSpymaster','id'=>'10','text'=>'Report to Caius Cosades.','content_file'=>'Morrowind.esm']]]],JSON_THROW_ON_ERROR);
$db->prepare("INSERT INTO turns (turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,speaker,target,audience,context,state,accepted_at) VALUES (:turn,:request,:message,:session,1,'text','en','journal projection','{}'::jsonb,'{}'::jsonb,'[]'::jsonb,CAST(:context AS jsonb),'complete','2026-01-01T00:00:00Z')")
    ->execute(['turn'=>$journalTurn,'request'=>$journalRequest,'message'=>$journalMessage,'session'=>$legacySession,'context'=>$journalContext]);
$journalProjection=$db->prepare('SELECT count(*) FROM almsivi_internal.questlog_metadata metadata JOIN public.questlog projected ON projected.rowid=metadata.rowid WHERE metadata.source_turn_id=:turn AND metadata.journal_id=:journal');
$journalProjection->execute(['turn'=>$journalTurn,'journal'=>'A1_1_FindSpymaster']);
$check((int)$journalProjection->fetchColumn()===1,'journal-bearing turn did not project into the Herika questlog contract');
$identityTurn=Uuid::v4();$identityRequest=Uuid::v4();$identityMessage=Uuid::v4();
$identityContext=json_encode(['contentFiles'=>['items'=>['morrowind.esm','custom-items.omwaddon']],
    'inventory'=>['items'=>[['record_id'=>'iron_dagger','content_file'=>'custom-items.omwaddon',
        'reference_content_file'=>'morrowind.esm','display_name'=>'Renamed Blade','kind'=>'item','count'=>1]]]],JSON_THROW_ON_ERROR);
$db->prepare("INSERT INTO turns (turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,speaker,target,audience,context,state,accepted_at) VALUES (:turn,:request,:message,:session,1,'text','en','record identity projection','{}'::jsonb,'{}'::jsonb,'[]'::jsonb,CAST(:context AS jsonb),'complete','2026-01-01T00:00:02Z')")
    ->execute(['turn'=>$identityTurn,'request'=>$identityRequest,'message'=>$identityMessage,'session'=>$legacySession,'context'=>$identityContext]);
$manifest=$db->query("SELECT content_file,load_order FROM content_manifest_files WHERE installation_id='{$legacyInstallation}' AND active ORDER BY load_order")->fetchAll();
$check(array_column($manifest,'content_file')===['morrowind.esm','custom-items.omwaddon']
    &&array_map('intval',array_column($manifest,'load_order'))===[0,1],'OpenMW content manifest did not preserve canonical load order');
$discovered=$db->query("SELECT content_file,record_id,display_name,reference_content_file FROM discovered_items WHERE installation_id='{$legacyInstallation}'")->fetch();
$check($discovered&&$discovered['content_file']==='custom-items.omwaddon'&&$discovered['record_id']==='iron_dagger'
    &&$discovered['display_name']==='Renamed Blade'&&$discovered['reference_content_file']==='morrowind.esm',
    'canonical custom item identity was not discovered independently of its display name or live reference source');
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
    $db->prepare("INSERT INTO dialogue_utterances(dialogue_message_id,session_id,turn_id,request_id,generation,utterance_index,utterance_count,response_line_id,utterance_id,speaker,addressee,audience,text,emitted_at,delivery_deadline_at) VALUES(:dialogue,:session,:turn,:request,1,:idx,3,:line,:utterance,'{}'::jsonb,'{}'::jsonb,'[]'::jsonb,:text,'2026-01-01T00:00:00Z','2026-01-01T00:05:00Z')")
        ->execute(['dialogue'=>$dialogueIds[$i],'session'=>$legacySession,'turn'=>$migrationTurn,'request'=>$migrationRequest,
            'idx'=>$i,'line'=>$dialogueIds[$i],'utterance'=>Uuid::v4(),'text'=>'utterance '.$i]);
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
$descriptionTurn=['installation_id'=>$installation,'payload'=>['context'=>[
    'contentFiles'=>['items'=>['morrowind.esm']],
    'inventory'=>['items'=>[['record_id'=>'iron_dagger','content_file'=>'morrowind.esm','count'=>1]]]
]]];
$descriptionContext=$products->itemDescriptionsForTurn($descriptionTurn);
$check(count($descriptionContext)===1&&$descriptionContext[0]['description']==='A serviceable iron blade.','turn context resolves an unambiguous managed record description');
$service->deleteItemDescription($description['description_id'],$installation);
$check($products->itemDescriptionsForTurn($descriptionTurn)===[],'deleted record descriptions are excluded from prompts');
$db->exec("INSERT INTO descriptions(plugin,baseid,name,description) VALUES('Morrowind.esm','iron_dagger','Iron Dagger','A factory iron blade.'),('Tribunal.esm','temple_book','Temple Book','A factory temple volume.')");
$imported=$service->importItemDescriptions($installation,[
    ['plugin'=>'Morrowind.esm','baseid'=>'iron_dagger','name'=>'Iron Dagger','description'=>'A custom iron blade.'],
    ['plugin'=>'Custom.esp','baseid'=>'custom_item','name'=>'Custom Item','description'=>'A custom mod item.'],
]);
$check(count($imported)===2&&count($products->customItemDescriptions($installation))===2,'bounded description batch import failed');
$uiDescriptions=new ManagementUiRepository($db);$descriptionPage=$uiDescriptions->descriptionCatalog($installation,['source'=>'all']);
$sources=[];foreach($descriptionPage['items']as$row)$sources[strtolower($row['content_file']).'|'.strtolower($row['record_id'])]=$row['source'];
$check(($sources['morrowind.esm|iron_dagger']??null)==='custom'&&($sources['tribunal.esm|temple_book']??null)==='default'
    &&($sources['custom.esp|custom_item']??null)==='custom','effective description catalog precedence failed');
$db->exec("INSERT INTO descriptions(plugin,baseid,name,description) SELECT 'Morrowind.esm','bulk_'||value,'Bulk Item '||lpad(value::text,3,'0'),'A bounded catalog test description.' FROM generate_series(1,120) value");
$secondDescriptionPage=$uiDescriptions->descriptionCatalog($installation,['search'=>'Bulk Item','page'=>2]);
$check($secondDescriptionPage['total']===120&&$secondDescriptionPage['pages']===3&&$secondDescriptionPage['page']===2
    &&count($secondDescriptionPage['items'])===50,'description catalog server-side pagination failed');
$catalogFixtureRoot=sys_get_temp_dir().'/almsivi-description-catalog-'.bin2hex(random_bytes(6));
if(!mkdir($catalogFixtureRoot,0700,true)&&!is_dir($catalogFixtureRoot))throw new RuntimeException('description catalog fixture directory failed');
$writeCatalogFixture=static function(string$version,array$rows)use($catalogFixtureRoot):array{
    $csvPath=$catalogFixtureRoot.'/'.$version.'.csv';$manifestPath=$catalogFixtureRoot.'/'.$version.'.json';
    $csv=fopen($csvPath,'wb');if($csv===false)throw new RuntimeException('description catalog fixture CSV failed');
    fwrite($csv,"\xEF\xBB\xBF");fputcsv($csv,['plugin','baseid','name','description'],',','"','');
    foreach($rows as$row)fputcsv($csv,$row,',','"','');fclose($csv);
    $items=array_map(static fn(array$row):array=>['content_file'=>$row[0],'record_id'=>$row[1],'status'=>'complete','error'=>null],$rows);
    $manifest=['format'=>'almsivi.morrowind-item-description-preflight.v1','model'=>'fixture/model',
        'prompt_sha256'=>hash('sha256','fixture prompt'),'official_content_sha256'=>[
            'Morrowind.esm'=>str_repeat('a',64),'Tribunal.esm'=>str_repeat('b',64),'Bloodmoon.esm'=>str_repeat('c',64)],
        'selected_count'=>count($rows),'completed_count'=>count($rows),'pending_count'=>0,'items'=>$items];
    file_put_contents($manifestPath,json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    return[$csvPath,$manifestPath];
};
$factoryV1Rows=[
    ['Morrowind.esm','iron_dagger','Iron Dagger','A narrow iron blade meets a plain leather grip beneath a small, undecorated crossguard.'],
    ['Bloodmoon.esm','nordic_axe','Nordic Axe','A broad steel axe head rests on a sturdy wooden haft wrapped with dark hide.'],
];
[$factoryV1Csv,$factoryV1Manifest]=$writeCatalogFixture('fixture-v1',$factoryV1Rows);
$catalogImporter=new DescriptionCatalogImporter($db);$factoryPlan=$catalogImporter->plan($factoryV1Csv,$factoryV1Manifest,'fixture-v1');
$check($factoryPlan['valid']===true&&$factoryPlan['row_count']===2&&$factoryPlan['duplicate_count']===0,
    'factory description catalog dry-run failed');
$factoryApplied=$catalogImporter->apply($factoryV1Csv,$factoryV1Manifest,'fixture-v1');
$check($factoryApplied['applied']===true&&(int)$db->query("SELECT count(*) FROM descriptions WHERE plugin IN ('Morrowind.esm','Tribunal.esm','Bloodmoon.esm')")->fetchColumn()===2,
    'factory description catalog apply did not replace official defaults atomically');
$factoryIdempotent=$catalogImporter->apply($factoryV1Csv,$factoryV1Manifest,'fixture-v1');
$check($factoryIdempotent['applied']===false&&$factoryIdempotent['idempotent']===true
    &&(int)$db->query("SELECT count(*) FROM description_catalogs WHERE catalog_version='fixture-v1'")->fetchColumn()===1,
    'factory description catalog repeat apply was not idempotent');
$factoryV1Crlf=$catalogFixtureRoot.'/fixture-v1-crlf.csv';
file_put_contents($factoryV1Crlf,str_replace("\n","\r\n",(string)file_get_contents($factoryV1Csv)));
$factoryCrlfProvision=$catalogImporter->provision($factoryV1Crlf,$factoryV1Manifest,'fixture-v1');
$check($factoryCrlfProvision['applied']===false&&$factoryCrlfProvision['idempotent']===true,
    'factory description catalog checksum changed across LF and CRLF line endings');
$effectiveAfterFactory=$uiDescriptions->descriptionCatalog($installation,['search'=>'Iron Dagger']);
$check(count($effectiveAfterFactory['items'])===1&&$effectiveAfterFactory['items'][0]['source']==='custom'
    &&$effectiveAfterFactory['items'][0]['description']==='A custom iron blade.',
    'installation custom description did not override the active factory catalog');
$factoryV2Rows=[
    ['Tribunal.esm','temple_book','Temple Book','A slim parchment volume bears a simple cloth cover reinforced by neat stitching along its spine.'],
];
[$factoryV2Csv,$factoryV2Manifest]=$writeCatalogFixture('fixture-v2',$factoryV2Rows);
$catalogImporter->apply($factoryV2Csv,$factoryV2Manifest,'fixture-v2');
$factoryRollback=$catalogImporter->rollback();
$check($factoryRollback['rolled_back']===true&&$factoryRollback['catalog_version']==='fixture-v1'
    &&(int)$db->query("SELECT count(*) FROM descriptions WHERE plugin IN ('Morrowind.esm','Tribunal.esm','Bloodmoon.esm')")->fetchColumn()===2
    &&$db->query("SELECT description FROM descriptions WHERE plugin='Bloodmoon.esm' AND baseid='nordic_axe'")->fetchColumn()===$factoryV1Rows[1][3],
    'factory description catalog rollback did not restore the prior catalog');
$factoryProvisionAfterRollback=$catalogImporter->provision($factoryV2Csv,$factoryV2Manifest,'fixture-v2');
$check($factoryProvisionAfterRollback['applied']===false&&$factoryProvisionAfterRollback['idempotent']===true
    &&$factoryProvisionAfterRollback['state']==='superseded'
    &&$db->query("SELECT catalog_version FROM description_catalogs WHERE state='active'")->fetchColumn()==='fixture-v1',
    'routine factory provisioning overrode an explicit catalog rollback');
$factoryReactivated=$catalogImporter->apply($factoryV2Csv,$factoryV2Manifest,'fixture-v2');
$check($factoryReactivated['applied']===true&&$factoryReactivated['reactivated']===true
    &&$db->query("SELECT catalog_version FROM description_catalogs WHERE state='active'")->fetchColumn()==='fixture-v2',
    'explicit factory catalog apply did not reactivate a reviewed prior version');
$catalogImporter->rollback('fixture-v1');
$duplicateRows=[$factoryV1Rows[0],['morrowind.esm','IRON_DAGGER','Iron Dagger Copy','A narrow iron blade carries a plain hide grip and a small crossguard without any ornament.']];
[$duplicateCsv,$duplicateManifest]=$writeCatalogFixture('fixture-duplicate',$duplicateRows);
$duplicatePlan=$catalogImporter->plan($duplicateCsv,$duplicateManifest,'fixture-duplicate');
$check($duplicatePlan['valid']===false&&$duplicatePlan['duplicate_count']===1&&$duplicatePlan['invalid_count']>0,
    'factory description catalog duplicate identity was not quarantined');
try{$catalogImporter->apply($duplicateCsv,$duplicateManifest,'fixture-duplicate');throw new RuntimeException('invalid factory catalog applied');}
catch(InvalidArgumentException$error){$check(str_starts_with($error->getMessage(),'invalid_catalog_package:'),'unexpected invalid factory catalog error');}
$check($db->query("SELECT catalog_version FROM description_catalogs WHERE state='active'")->fetchColumn()==='fixture-v1'
    &&(int)$db->query("SELECT count(*) FROM descriptions WHERE plugin IN ('Morrowind.esm','Tribunal.esm','Bloodmoon.esm')")->fetchColumn()===2,
    'invalid factory catalog changed the active projection');
foreach(glob($catalogFixtureRoot.'/*')?:[]as$fixturePath)unlink($fixturePath);rmdir($catalogFixtureRoot);
$biographyFixtureRoot=sys_get_temp_dir().'/almsivi-biography-catalog-'.bin2hex(random_bytes(6));
if(!mkdir($biographyFixtureRoot,0700,true)&&!is_dir($biographyFixtureRoot))throw new RuntimeException('biography catalog fixture directory failed');
$writeBiographyFixture=static function(string$version,array$rows)use($biographyFixtureRoot):array{
    $biographiesPath=$biographyFixtureRoot.'/'.$version.'.json';$manifestPath=$biographyFixtureRoot.'/'.$version.'-manifest.json';
    file_put_contents($biographiesPath,json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    $items=array_map(static fn(array$row):array=>['record_id'=>$row['refid'],'display_name'=>ucwords(str_replace('_',' ',$row['npc_name'])),
        'content_file'=>'Morrowind.esm','generation_status'=>'complete'],$rows);
    $manifest=['format'=>'almsivi.morrowind-biography-preflight.v1','selected_count'=>count($rows),'completed_count'=>count($rows),
        'failed_count'=>0,'model'=>'fixture/model','builder_sha256'=>hash('sha256','fixture biography builder'),
        'official_content_sha256'=>['Morrowind.esm'=>str_repeat('a',64),'Tribunal.esm'=>str_repeat('b',64),'Bloodmoon.esm'=>str_repeat('c',64)],'items'=>$items];
    file_put_contents($manifestPath,json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));return[$biographiesPath,$manifestPath];
};
$biographyRow=static fn(string$name,string$record,string$bio):array=>['npc_name'=>$name,'oghma_knowledge_tags'=>'',
    'core'=>ucwords(str_replace('_',' ',$name)).' is a resident of Vvardenfell with a clearly defined local role.',
    'npc_static_bio'=>$bio,'appearance'=>'A practical traveler with weathered clothing and an attentive bearing.',
    'personality'=>'Patient, observant, and direct when speaking with unfamiliar travelers.','relationships'=>'{}',
    'occupation'=>'A local resident who performs useful work for the surrounding settlement.',
    'skills'=>'* Navigating nearby roads\n* Recognizing local dangers\n* Speaking with visiting travelers',
    'speechstyle'=>'Speaks in concise, practical phrases with a measured and cautious tone.',
    'goals'=>'* Remain safe\n* Complete daily work\n* Protect the local community','voiceid'=>null,'gender'=>'male','race'=>'Dark Elf','refid'=>$record];
$biographyV1Rows=[$biographyRow('fixture_dunmer','fixture_dunmer','The fixture Dunmer lives near Balmora and knows the roads leading through the surrounding hills.')];
[$biographyV1Json,$biographyV1Manifest]=$writeBiographyFixture('biography-v1',$biographyV1Rows);
$biographyImporter=new BiographyCatalogImporter($db);$biographyPlan=$biographyImporter->plan($biographyV1Json,$biographyV1Manifest,'biography-v1');
$check($biographyPlan['valid']===true&&$biographyPlan['row_count']===1,'factory biography catalog dry-run failed');
$biographyApplied=$biographyImporter->apply($biographyV1Json,$biographyV1Manifest,'biography-v1');
$check($biographyApplied['applied']===true&&$db->query("SELECT npc_static_bio FROM bio_templates WHERE npc_name='fixture_dunmer'")->fetchColumn()===$biographyV1Rows[0]['npc_static_bio'],
    'factory biography catalog did not project into the CHIM table');
$db->exec("INSERT INTO bio_templates_custom(npc_name,core,npc_static_bio,relationships,refid) VALUES('fixture_dunmer','Custom core','Custom biography','{}','fixture_dunmer')");
$check($db->query("SELECT npc_static_bio FROM combined_bio_templates WHERE npc_name='fixture_dunmer'")->fetchColumn()==='Custom biography',
    'custom CHIM biography did not override the active factory catalog');
$biographyV2Rows=[$biographyRow('fixture_argonian','fixture_argonian','The fixture Argonian works beside the river and watches for travelers approaching the nearby crossing.')];
[$biographyV2Json,$biographyV2Manifest]=$writeBiographyFixture('biography-v2',$biographyV2Rows);$biographyImporter->apply($biographyV2Json,$biographyV2Manifest,'biography-v2');
$biographyRollback=$biographyImporter->rollback();
$check($biographyRollback['rolled_back']===true&&$biographyRollback['catalog_version']==='biography-v1'
    &&(int)$db->query("SELECT count(*) FROM bio_templates WHERE npc_name='fixture_dunmer'")->fetchColumn()===1,
    'factory biography catalog rollback did not restore the prior CHIM projection');
$biographyProvisionAfterRollback=$biographyImporter->provision($biographyV2Json,$biographyV2Manifest,'biography-v2');
$check($biographyProvisionAfterRollback['applied']===false&&$biographyProvisionAfterRollback['state']==='superseded',
    'routine biography provisioning overrode an explicit rollback');
foreach(glob($biographyFixtureRoot.'/*')?:[]as$fixturePath)unlink($fixturePath);rmdir($biographyFixtureRoot);
$check($service->resetItemDescriptions($installation)===2&&$products->customItemDescriptions($installation)===[],'description override reset failed');
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
$providerProjection=$db->prepare('SELECT count(*) FROM llm_connector_metadata metadata JOIN public.core_llm_connector connector ON connector.id=metadata.connector_id WHERE metadata.configuration_id=:configuration');
$providerProjection->execute(['configuration'=>$providerConfig['configuration_id']]);
$check((int)$providerProjection->fetchColumn()===1,'provider did not project into the Herika connector contract');
$actionPolicy=$service->createRevisioned('action_policy',['installation_id'=>$installation,'name'=>'Safe actions',
    'content'=>['enabled'=>true,'max_tier'=>1,'denied_actions'=>['item.give']]]);
$check($actionPolicy['content']['max_tier']===1,'bounded action policy config failed');
try{$service->revise('action_policy',$actionPolicy['configuration_id'],['enabled'=>'yes'],'unsafe edit');throw new RuntimeException('invalid action policy accepted');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='invalid_action_policy','unexpected action policy boundary error');}
$ttsConfig=$service->createRevisioned('tts_provider',['installation_id'=>$installation,'name'=>'Local OmniVoice',
    'content'=>['driver'=>'omnivoice','endpoint'=>'http://127.0.0.1:8021','model'=>'k2-fsa/OmniVoice',
        'voice'=>'test-voice','language'=>'en','timeout_ms'=>30000,'options'=>[]]]);
$service->selectConnector(['installation_id'=>$installation,'kind'=>'tts_provider','configuration_id'=>$ttsConfig['configuration_id']]);
$check(count($products->listRevisioned('provider',$installation))===1
    &&$products->connectorForInstallation($installation,'tts_provider')['configuration_id']===$ttsConfig['configuration_id']
    &&count($products->connectorSelections($installation))===1,'TTS connector preset was not isolated and selectable');
$sttConfig=$service->createRevisioned('stt_provider',['installation_id'=>$installation,'name'=>'Local Parakeet','content'=>[
    'driver'=>'parakeet','endpoint'=>'http://127.0.0.1:8022','model'=>'parakeet-tdt-0.6b-v3','voice'=>'',
    'language'=>'en','timeout_ms'=>30000,'options'=>[]]]);
$service->selectConnector(['installation_id'=>$installation,'kind'=>'stt_provider','configuration_id'=>$sttConfig['configuration_id']]);
$check($products->connectorForInstallation($installation,'stt_provider')['configuration_id']===$sttConfig['configuration_id'],
    'installation-global STT connector was not writable and selectable');
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
$promptProjection=$db->prepare('SELECT count(*) FROM prompt_metadata metadata JOIN public.prompts prompt ON prompt.prompt_key=metadata.prompt_key WHERE metadata.source_configuration_id=:configuration');
$promptProjection->execute(['configuration'=>$selectedPrompt['configuration_id']]);
$check((int)$promptProjection->fetchColumn()===1,'prompt did not project into the Herika prompt contract');
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
$memoryRevision=$db->query("SELECT current_revision FROM memory_records WHERE memory_id='{$memory['memory_id']}'")->fetchColumn();
$memoryRevisionCount=$db->query("SELECT count(*) FROM memory_record_revisions WHERE memory_id='{$memory['memory_id']}'")->fetchColumn();
$check((int)$memoryRevision===2&&(int)$memoryRevisionCount===2,'memory edit did not preserve an auditable revision history');
$memoryProjection=$db->prepare('SELECT memory.message FROM memory_metadata metadata JOIN public.memory memory ON memory.rowid=metadata.rowid WHERE metadata.memory_id=:memory');
$memoryProjection->execute(['memory'=>$memory['memory_id']]);
$check($memoryProjection->fetchColumn()==='Nalcarya sells potions.','memory did not project into the Herika memory contract');
$knowledge=$service->ingestKnowledge($scope+['title'=>'Balmora services','content'=>'Nalcarya operates an alchemy shop.',
    'provenance'=>['source'=>'authored-test']]);
$knowledgeSearch=$service->searchKnowledge($scope,'alchemy shop');
$check($knowledgeSearch['results'][0]['id'] === $knowledge['document_id'], 'knowledge retrieval failed');
$knowledgeProjection=$db->prepare('SELECT oghma.topic_desc FROM oghma_metadata metadata JOIN public.oghma oghma ON oghma.topic=metadata.topic WHERE metadata.document_id=:document');
$knowledgeProjection->execute(['document'=>$knowledge['document_id']]);
$check($knowledgeProjection->fetchColumn()==='Nalcarya operates an alchemy shop.','knowledge did not project into the Herika Oghma contract');
    $products->setOghmaSettings($installation,['enabled'=>true,'knowledge_tags'=>'common','racial_context_enabled'=>true,
        'location_context_enabled'=>true,'topic_count'=>2,'result_limit'=>4,'extractor_enabled'=>true,
        'extractor_timeout_ms'=>900],$clock->iso());
$savedOghmaSettings=$products->oghmaSettings($installation);
    $check($savedOghmaSettings['enabled']===true&&$savedOghmaSettings['topic_count']===2&&$savedOghmaSettings['extractor_enabled']===true
    &&$savedOghmaSettings['result_limit']===4&&$savedOghmaSettings['extractor_timeout_ms']===900
    &&$savedOghmaSettings['knowledge_tags']===''
    &&$savedOghmaSettings['racial_context_enabled']===true&&$savedOghmaSettings['location_context_enabled']===true,
    'installation-global Oghma runtime controls were not persisted');
$oghmaRows=[];foreach([
    ['00000000-0000-4000-8000-000000000301','Dunmer'],['00000000-0000-4000-8000-000000000302','Balmora'],
    ['00000000-0000-4000-8000-000000000303','Vivec'],['00000000-0000-4000-8000-000000000304','Tribunal'],
    ['00000000-0000-4000-8000-000000000319','Ascadian Isles'],
    ]as[$id,$topic])$oghmaRows[]=['id'=>$id,'topic'=>$topic,'title'=>$topic,'aliases'=>'','content'=>$topic.' advanced lore.',
        'topic_desc_basic'=>$topic.' basic lore.','knowledge_class'=>'','knowledge_class_basic'=>'',
        'tags'=>$topic==='Vivec'?'warrior poet god':'','category'=>'Lore'];
foreach($oghmaRows as$row)$products->createKnowledge([
    'installation_id'=>$installation,'profile_id'=>null,'playthrough_id'=>null,'title'=>$row['title'],'content'=>$row['content'],
    'provenance'=>['source'=>'authored-test'],'topic'=>$row['topic'],'aliases'=>$row['aliases'],
    'topic_desc_basic'=>$row['topic_desc_basic'],'knowledge_class'=>$row['knowledge_class'],
    'knowledge_class_basic'=>$row['knowledge_class_basic'],'tags'=>$row['tags'],'category'=>$row['category'],
],[(string)$row['topic']],$clock->iso());
    $groundedTurn=$scope+['turn_id'=>'20000000-0000-4000-8000-000000000098','payload'=>[
        'input'=>['kind'=>'text','language'=>'en','text'=>'Tell me about Vivec and the Tribunal.'],'ui_source'=>'almsivi_text',
        'target'=>['kind'=>'npc','record_id'=>'fargoth','content_file'=>'Morrowind.esm']]];
$groundedSelection=$products->groundedOghmaExtraction($groundedTurn);
$check($groundedSelection['status']==='grounded'&&$groundedSelection['topics']===['Vivec','Tribunal']
    &&$groundedSelection['fallback_eligible']===false,'database-backed grounded Oghma extraction did not preserve topic order');
$noMatchSelection=$products->groundedOghmaExtraction(array_replace_recursive($groundedTurn,
    ['payload'=>['input'=>['text'=>'We should leave before sunset.']]]));
$check($noMatchSelection['topics']===[]&&$noMatchSelection['fallback_eligible']===false,
    'ordinary dialogue unexpectedly selected Oghma knowledge or connector fallback');
$fallbackSelection=$products->groundedOghmaExtraction(array_replace_recursive($groundedTurn,
    ['payload'=>['input'=>['text'=>'Tell me about an unknown forgotten island.']]]));
    $check($fallbackSelection['topics']===[]&&$fallbackSelection['fallback_eligible']===true,
        'unresolved explicit lore request did not become eligible for one connector fallback');
    $tagSelection=$products->groundedOghmaExtraction(array_replace_recursive($groundedTurn,
        ['payload'=>['input'=>['text'=>'We encountered a warrior poet god during the journey.']]]));
    $check($tagSelection['topics']===[]&&$tagSelection['fallback_eligible']===false,
        'ordinary descriptive tags unexpectedly created an Oghma topic');
    $rankedTagSelection=$products->groundedOghmaExtraction(array_replace_recursive($groundedTurn,
        ['payload'=>['input'=>['text'=>'Tell me about Vivec, the warrior poet god.']]]));
    $check($rankedTagSelection['topics']===['Vivec']
        &&in_array('warrior poet god',$rankedTagSelection['matches'][0]['relational_tag_phrases']??[],true),
        'descriptive tags did not strengthen an already grounded Oghma topic');
    $ineligibleSelection=$products->groundedOghmaExtraction(array_replace_recursive($groundedTurn,
        ['payload'=>['ui_source'=>'almsivi_autonomy']]));
    $check($ineligibleSelection['status']==='ineligible'&&$ineligibleSelection['topics']===[],
        'Oghma request allowlist accepted an autonomy source');
$selectOghma=new ReflectionMethod($products,'selectPromptKnowledge');
$oghmaSelection=$selectOghma->invoke($products,
    ['installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id'],'payload'=>[
        'input'=>['text'=>'Tell me about Vivec and the Tribunal.'],'target'=>['kind'=>'npc','record_id'=>'fargoth','content_file'=>'Morrowind.esm'],
        'context'=>['world'=>['cell'=>'Balmora','region'=>'Ascadian Isles']]]],['content'=>['race'=>'Dark Elf']],$scope,$oghmaRows,'',4,
    $savedOghmaSettings,['status'=>'fallback_succeeded','request_eligible'=>true,'topics'=>['Vivec','Tribunal'],'configuration_id'=>'00000000-0000-4000-8000-000000000305'],$clock->iso());
$check(array_column($oghmaSelection['rows'],'topic')===['Vivec','Tribunal','Balmora','Ascadian Isles']
        &&$oghmaSelection['trace']['algorithm']==='oghma-parity-v1'
    &&$oghmaSelection['trace']['reasons']['_context']['extracted_topics']===['Vivec','Tribunal'],
        'multi-topic Oghma retrieval did not prioritize conversation, exact location, and region context');
    $boundedLimitSelection=$selectOghma->invoke($products,
        ['installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id'],'payload'=>[
            'input'=>['text'=>'Tell me about Vivec and the Tribunal.'],'target'=>['kind'=>'npc','record_id'=>'fargoth','content_file'=>'Morrowind.esm'],
            'context'=>['world'=>['cell'=>'Balmora','region'=>'Ascadian Isles']]]],['content'=>['race'=>'Dark Elf']],$scope,$oghmaRows,'',20,
        $savedOghmaSettings,['status'=>'grounded','request_eligible'=>true,'topics'=>['Vivec','Tribunal']],$clock->iso());
    $check(($boundedLimitSelection['trace']['reasons']['_context']['knowledge_limit']??null)===5,
        'Oghma selection did not enforce the shared five-result maximum');
    $conversationBudgetSelection=$selectOghma->invoke($products,
        ['installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id'],'payload'=>[
            'input'=>['text'=>'Tell me about Vivec and the Tribunal.'],'target'=>['kind'=>'npc','record_id'=>'fargoth','content_file'=>'Morrowind.esm'],
            'context'=>['location'=>['name'=>'Balmora']]]],['content'=>['race'=>'Dark Elf']],$scope,$oghmaRows,'',2,
        $savedOghmaSettings,['status'=>'grounded','request_eligible'=>true,'topics'=>['Vivec','Tribunal']],$clock->iso());
    $check(array_column($conversationBudgetSelection['rows'],'topic')===['Vivec','Tribunal'],
        'conversation topics did not consume the shared result budget before forced context');
    $deduplicatedSelection=$selectOghma->invoke($products,
        ['installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id'],'payload'=>[
            'input'=>['text'=>'Tell me about Balmora.'],'target'=>['kind'=>'npc','record_id'=>'fargoth','content_file'=>'Morrowind.esm'],
            'context'=>['location'=>['name'=>'Balmora']]]],['content'=>['race'=>'Dark Elf']],$scope,$oghmaRows,'',3,
        $savedOghmaSettings,['status'=>'grounded','request_eligible'=>true,'topics'=>['Balmora']],$clock->iso());
    $check(array_column($deduplicatedSelection['rows'],'topic')===['Balmora','Dunmer']
        &&($deduplicatedSelection['rows'][0]['source']??null)==='conversation',
        'conversation ownership was not retained when forced location matched the same article');
    $deniedRow=['id'=>'00000000-0000-4000-8000-000000000306','topic'=>'Forbidden Lore','title'=>'Forbidden Lore',
        'aliases'=>'','content'=>'Forbidden advanced lore.','topic_desc_basic'=>'Forbidden basic lore.',
        'knowledge_class'=>'secret','knowledge_class_basic'=>'initiate','tags'=>'','category'=>'Lore'];
    $deniedSettings=array_merge($savedOghmaSettings,['racial_context_enabled'=>false,'location_context_enabled'=>false]);
    $deniedSelection=$selectOghma->invoke($products,
        ['installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id'],'payload'=>[
            'input'=>['kind'=>'text','language'=>'en','text'=>'Tell me about Forbidden Lore.'],'ui_source'=>'almsivi_text',
            'target'=>['kind'=>'npc','record_id'=>'fargoth','content_file'=>'Morrowind.esm'],'context'=>[]]],
        ['content'=>[]],$scope,[$deniedRow],'',4,$deniedSettings,
        ['status'=>'grounded','request_eligible'=>true,'topics'=>['Forbidden Lore']],$clock->iso());
    $check(($deniedSelection['rows'][0]['access_level']??null)==='denied'
        &&($deniedSelection['rows'][0]['content']??null)===''
        &&($deniedSelection['trace']['reasons']['_context']['denied_topics']??[])===['Forbidden Lore'],
        'recognized unauthorized Oghma topic was not preserved as structured denied prompt context');
    $products->setOghmaSettings($installation,array_merge($savedOghmaSettings,['enabled'=>false]),$clock->iso());
    $disabledSelection=$products->groundedOghmaExtraction($groundedTurn);
    $check($disabledSelection['status']==='disabled'&&$disabledSelection['topics']===[]
        &&$disabledSelection['fallback_eligible']===false,'Oghma master switch did not disable all retrieval');
    $products->setOghmaSettings($installation,$savedOghmaSettings,$clock->iso());
    $oghmaFixtureRoot=sys_get_temp_dir().'/almsivi-oghma-catalog-'.bin2hex(random_bytes(6));
    if(!mkdir($oghmaFixtureRoot,0700,true)&&!is_dir($oghmaFixtureRoot))throw new RuntimeException('Oghma catalog fixture directory failed');
    $writeOghmaCatalogFixture=static function(string$version,array$rows)use($oghmaFixtureRoot):array{
        $articlesPath=$oghmaFixtureRoot.'/'.$version.'.articles.json';$manifestPath=$oghmaFixtureRoot.'/'.$version.'.manifest.json';
        $articles=json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        file_put_contents($articlesPath,$articles);
        $contentHashes=['Morrowind.esm'=>str_repeat('5',64)];
        foreach($rows as$row)if(is_string($row['mod_source']??null)&&trim($row['mod_source'])!=='')$contentHashes[trim($row['mod_source'])]=str_repeat('6',64);
        $manifest=['format'=>'almsivi.morrowind-oghma-catalog.v1','catalog_version'=>$version,
            'row_count'=>count($rows),'articles_sha256'=>hash('sha256',$articles),'ontology_sha256'=>str_repeat('1',64),
            'topic_seeds_sha256'=>str_repeat('2',64),'generator_sha256'=>str_repeat('3',64),
            'builder_sha256'=>str_repeat('4',64),'official_content_sha256'=>$contentHashes];
        file_put_contents($manifestPath,json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return[$articlesPath,$manifestPath];
    };
    $oghmaV1Rows=[['topic'=>'fixture_lore','title'=>'Fixture Lore','aliases'=>['fixture legend'],
        'topic_desc'=>'Factory v1 preserves Vvardenfell’s reviewed lore.','knowledge_class'=>['scholar'],
        'topic_desc_basic'=>'Factory v1 basic lore.','knowledge_class_basic'=>['common'],
        'tags'=>['reviewed fixture lore'],'category'=>'lore']];
    $oghmaV2Rows=[['topic'=>'fixture_lore','title'=>'Fixture Lore','aliases'=>['fixture legend'],
        'topic_desc'=>'Factory v2 updates Vvardenfell’s reviewed lore.','knowledge_class'=>['scholar'],
        'topic_desc_basic'=>'Factory v2 basic lore.','knowledge_class_basic'=>['common'],
        'tags'=>['reviewed fixture lore'],'category'=>'lore'],
        ['topic'=>'fixture_place','title'=>'Fixture Place','aliases'=>[],
            'topic_desc'=>'Factory v2 adds one reviewed location.','knowledge_class'=>['scholar'],
            'topic_desc_basic'=>'Factory v2 location basics.','knowledge_class_basic'=>['common'],
            'tags'=>['reviewed fixture location'],'category'=>'locations','mod_source'=>'TR_Mainland.esm']];
    [$oghmaV1Articles,$oghmaV1Manifest]=$writeOghmaCatalogFixture('oghma-fixture-v1',$oghmaV1Rows);
    [$oghmaV2Articles,$oghmaV2Manifest]=$writeOghmaCatalogFixture('oghma-fixture-v2',$oghmaV2Rows);
    $customOghma=$products->createKnowledge(['installation_id'=>$installation,'profile_id'=>null,'playthrough_id'=>null,
        'title'=>'Fixture Lore Custom','content'=>'Installation-authored Oghma knowledge must survive factory changes.',
        'provenance'=>['source'=>'authored-test'],'topic'=>'fixture_lore','aliases'=>'custom fixture',
        'topic_desc_basic'=>'Custom fixture basics.','knowledge_class'=>'scholar','knowledge_class_basic'=>'common',
        'tags'=>'custom fixture knowledge','category'=>'lore'],['fixture','custom'],$clock->iso());
    $oghmaImporter=new OghmaCatalogImporter($db);$oghmaPlan=$oghmaImporter->plan($oghmaV1Articles,$oghmaV1Manifest,'oghma-fixture-v1');
    $check($oghmaPlan['valid']===true&&$oghmaPlan['row_count']===1,'factory Oghma catalog dry-run failed');
    $largeOghmaRows=[];for($index=1;$index<=2001;++$index)$largeOghmaRows[]=[
        'topic'=>'capacity_fixture_'.$index,'title'=>'Capacity Fixture '.$index,'aliases'=>[],
        'topic_desc'=>'Reviewed knowledge remains valid beyond the former fixed catalog allocation.',
        'knowledge_class'=>['scholar'],'topic_desc_basic'=>'Reviewed capacity fixture basics.',
        'knowledge_class_basic'=>['common'],'tags'=>['reviewed capacity fixture'],'category'=>'lore'];
    [$largeOghmaArticles,$largeOghmaManifest]=$writeOghmaCatalogFixture('oghma-capacity-fixture',$largeOghmaRows);
    $largeOghmaPlan=$oghmaImporter->plan($largeOghmaArticles,$largeOghmaManifest,'oghma-capacity-fixture');
    $check($largeOghmaPlan['valid']===true&&$largeOghmaPlan['row_count']===2001,
        'factory Oghma importer retained the former 2,000-row ceiling');
    unset($largeOghmaRows);
    $oghmaImporter->apply($oghmaV1Articles,$oghmaV1Manifest,'oghma-fixture-v1');
    $customOghmaId=(string)$customOghma['document_id'];
    $check($db->query("SELECT content FROM knowledge_documents WHERE document_id='{$customOghmaId}'")->fetchColumn()==='Installation-authored Oghma knowledge must survive factory changes.'
        &&(int)$db->query("SELECT count(*) FROM oghma_factory_documents WHERE installation_id='{$installation}' AND catalog_id=(SELECT catalog_id FROM oghma_catalogs WHERE catalog_version='oghma-fixture-v1')")->fetchColumn()===1,
        'factory Oghma v1 did not preserve installation-authored knowledge');
    $oghmaImporter->apply($oghmaV2Articles,$oghmaV2Manifest,'oghma-fixture-v2');
    $oghmaCatalog=$db->query("SELECT catalog_version,state,previous_catalog_id FROM oghma_catalogs")->fetch();
    $check((int)$db->query('SELECT count(*) FROM oghma_catalogs')->fetchColumn()===1
        &&($oghmaCatalog['catalog_version']??null)==='oghma-fixture-v2'
        &&($oghmaCatalog['state']??null)==='active'
        &&($oghmaCatalog['previous_catalog_id']??null)===null
        &&(int)$db->query("SELECT count(*) FROM oghma_factory_documents WHERE installation_id='{$installation}' AND catalog_id=(SELECT catalog_id FROM oghma_catalogs)")->fetchColumn()===2
        &&$db->query("SELECT content FROM knowledge_documents d JOIN oghma_factory_documents f ON f.document_id=d.document_id WHERE f.topic='fixture_lore'")->fetchColumn()==='Factory v2 updates Vvardenfell’s reviewed lore.'
        &&$db->query("SELECT d.provenance->>'mod_source' FROM knowledge_documents d JOIN oghma_factory_documents f ON f.document_id=d.document_id WHERE f.topic='fixture_place'")->fetchColumn()==='TR_Mainland.esm'
        &&$db->query("SELECT content FROM knowledge_documents WHERE document_id='{$customOghmaId}'")->fetchColumn()==='Installation-authored Oghma knowledge must survive factory changes.',
        'current Oghma sync did not replace v1 while preserving custom knowledge');
    $vanillaFiles=['morrowind.esm'];$tamrielRebuiltFiles=['morrowind.esm','tamriel_data.esm','tr_mainland.esm'];
    $vanillaCandidates=$products->knowledgeCandidates([
        'installation_id'=>$installation,'profile_id'=>$profile['profile_id'],'playthrough_id'=>$playthrough['playthrough_id'],
    ],$vanillaFiles);
    $tamrielRebuiltCandidates=$products->knowledgeCandidates([
        'installation_id'=>$installation,'profile_id'=>$profile['profile_id'],'playthrough_id'=>$playthrough['playthrough_id'],
    ],$tamrielRebuiltFiles);
    $vanillaTopics=array_map('strtolower',array_column($vanillaCandidates,'topic'));
    $tamrielRebuiltTopics=array_map('strtolower',array_column($tamrielRebuiltCandidates,'topic'));
    $check(!in_array('fixture_place',$vanillaTopics,true)&&in_array('fixture_place',$tamrielRebuiltTopics,true)
        &&in_array('fixture_lore',$vanillaTopics,true),'factory Oghma mod sources were not scoped to the current OpenMW content list');
    $hiddenModTurn=array_replace_recursive($groundedTurn,['payload'=>[
        'input'=>['text'=>'Tell me about Fixture Place.'],'context'=>['contentFiles'=>['items'=>['Morrowind.esm']]]]]);
    $loadedModTurn=array_replace_recursive($hiddenModTurn,['payload'=>[
        'context'=>['contentFiles'=>['items'=>['Morrowind.esm','Tamriel_Data.esm','TR_Mainland.esm']]]]]);
    $check($products->groundedOghmaExtraction($hiddenModTurn)['topics']===[]
        &&$products->groundedOghmaExtraction($loadedModTurn)['topics']===['fixture_place'],
        'first-turn Oghma grounding did not use the current request content list');
    $clock->advance(1);$revisedCustomOghma=$products->createKnowledge([
        'installation_id'=>$installation,'profile_id'=>null,'playthrough_id'=>null,
        'title'=>'Fixture Lore Revised','content'=>'Revised installation-authored Oghma knowledge overrides the factory article.',
        'provenance'=>['source'=>'management-csv'],'topic'=>'FIXTURE_LORE','aliases'=>'revised custom fixture',
        'topic_desc_basic'=>'Revised custom fixture basics.','knowledge_class'=>'scholar','knowledge_class_basic'=>'common',
        'tags'=>'revised custom fixture knowledge','category'=>'lore'],['fixture','revised','custom'],$clock->iso());
    $check(($revisedCustomOghma['document_id']??null)===$customOghmaId,
        'saving the same custom Oghma topic created a duplicate instead of updating the override');
    $effectiveFixtureRows=array_values(array_filter($products->knowledgeCandidates([
        'installation_id'=>$installation,'profile_id'=>$profile['profile_id'],'playthrough_id'=>$playthrough['playthrough_id'],
    ]),static fn(array$row):bool=>strtolower((string)$row['topic'])==='fixture_lore'));
    $check(count($effectiveFixtureRows)===1
        &&($effectiveFixtureRows[0]['id']??null)===$customOghmaId
        &&($effectiveFixtureRows[0]['content']??null)==='Revised installation-authored Oghma knowledge overrides the factory article.',
        'custom Oghma article did not override the matching factory topic');
    $uiFixtureRows=array_values(array_filter($uiDescriptions->oghmaCatalog([
        'installation_id'=>$installation,'page_size'=>500,
    ])['rows'],static fn(array$row):bool=>strtolower((string)$row['topic'])==='fixture_lore'));
    $check(count($uiFixtureRows)===1&&($uiFixtureRows[0]['document_id']??null)===$customOghmaId,
        'Oghma editor did not display the effective custom article');
    $oghmaProvision=$oghmaImporter->provision($oghmaV2Articles,$oghmaV2Manifest,'oghma-fixture-v2');
    $check($oghmaProvision['applied']===false&&$oghmaProvision['idempotent']===true
        &&(int)$db->query('SELECT count(*) FROM oghma_catalogs')->fetchColumn()===1,
        'current Oghma provisioning was not idempotent');
    $invalidModRows=[$oghmaV1Rows[0]+['mod_source'=>'../TR_Mainland.esm']];
    [$invalidModArticles,$invalidModManifest]=$writeOghmaCatalogFixture('oghma-invalid-mod-source',$invalidModRows);
    $invalidModPlan=$oghmaImporter->plan($invalidModArticles,$invalidModManifest,'oghma-invalid-mod-source');
    $check($invalidModPlan['valid']===false&&in_array('article fixture_lore mod_source is invalid',$invalidModPlan['errors'],true),
        'Oghma catalog accepted a path-shaped mod source');
    $products->deleteKnowledge($customOghmaId,$clock->iso());
    $restoredFixtureRows=array_values(array_filter($products->knowledgeCandidates([
        'installation_id'=>$installation,'profile_id'=>$profile['profile_id'],'playthrough_id'=>$playthrough['playthrough_id'],
    ]),static fn(array$row):bool=>strtolower((string)$row['topic'])==='fixture_lore'));
    $check(count($restoredFixtureRows)===1
        &&($restoredFixtureRows[0]['content']??null)==='Factory v2 updates Vvardenfell’s reviewed lore.',
        'deleting a custom Oghma override did not reveal the factory topic');
    $uiRestoredFixtureRows=array_values(array_filter($uiDescriptions->oghmaCatalog([
        'installation_id'=>$installation,'page_size'=>500,
    ])['rows'],static fn(array$row):bool=>strtolower((string)$row['topic'])==='fixture_lore'));
    $check(count($uiRestoredFixtureRows)===1
        &&($uiRestoredFixtureRows[0]['content']??null)==='Factory v2 updates Vvardenfell’s reviewed lore.',
        'Oghma editor did not reveal the factory article after deleting its custom override');
    $factoryFixtureId=(string)$db->query("SELECT f.document_id FROM oghma_factory_documents f WHERE f.topic='fixture_lore' AND f.installation_id='{$installation}'")->fetchColumn();
    $factoryFixtureBefore=$db->query("SELECT topic,title,content,knowledge_class,provenance->>'source' AS source FROM knowledge_documents WHERE document_id='{$factoryFixtureId}'")->fetch();
    $clock->advance(1);$factoryOverride=$service->updateKnowledge($factoryFixtureId,[
        'topic'=>'renamed_fixture_lore','title'=>'Fixture Lore Override','aliases'=>'overridden fixture',
        'content'=>'Editing the factory article saved a custom override instead.','knowledge_class'=>'scholar',
        'topic_desc_basic'=>'Overridden fixture basics.','knowledge_class_basic'=>'common',
        'tags'=>'overridden fixture knowledge','category'=>'lore','provenance'=>['source'=>'management']]);
    $factoryOverrideId=(string)$factoryOverride['document_id'];
    $factoryFixtureAfter=$db->query("SELECT topic,title,content,knowledge_class,provenance->>'source' AS source FROM knowledge_documents WHERE document_id='{$factoryFixtureId}' AND deleted_at IS NULL")->fetch();
    $uiOverrideRows=array_values(array_filter($uiDescriptions->oghmaCatalog([
        'installation_id'=>$installation,'page_size'=>500,
    ])['rows'],static fn(array$row):bool=>strtolower((string)$row['topic'])==='fixture_lore'));
    $check($factoryOverrideId!==$factoryFixtureId
        &&($factoryOverride['installation_id']??null)===$installation
        &&($factoryOverride['topic']??null)==='fixture_lore'
        &&($factoryOverride['profile_id']??null)===null&&($factoryOverride['playthrough_id']??null)===null
        &&($factoryOverride['provenance']['source']??null)==='management'
        &&$factoryFixtureAfter===$factoryFixtureBefore
        &&count($uiOverrideRows)===1&&($uiOverrideRows[0]['document_id']??null)===$factoryOverrideId
        &&($uiOverrideRows[0]['content']??null)==='Editing the factory article saved a custom override instead.',
        'editing a factory Oghma article did not create a custom override that leaves the factory row unchanged');
    $products->deleteKnowledge($factoryOverrideId,$clock->iso());
    $uiOverrideDeletedRows=array_values(array_filter($uiDescriptions->oghmaCatalog([
        'installation_id'=>$installation,'page_size'=>500,
    ])['rows'],static fn(array$row):bool=>strtolower((string)$row['topic'])==='fixture_lore'));
    $check(count($uiOverrideDeletedRows)===1
        &&($uiOverrideDeletedRows[0]['document_id']??null)===$factoryFixtureId
        &&($uiOverrideDeletedRows[0]['content']??null)==='Factory v2 updates Vvardenfell’s reviewed lore.',
        'deleting the override saved from a factory edit did not reveal the factory article');
    $coverageInsert=$db->prepare("INSERT INTO knowledge_documents
        (document_id,installation_id,title,content,content_sha256,lexical_terms,provenance,created_at,
         topic,aliases,topic_desc_basic,knowledge_class,knowledge_class_basic,tags,category)
        SELECT ('10000000-0000-4000-8000-'||lpad(sequence::text,12,'0'))::uuid,:installation,
               'Coverage Topic '||sequence,'Coverage article '||sequence,repeat('a',64),
               ARRAY['coverage','topic',sequence::text],'{\"source\":\"coverage-test\"}'::jsonb,:created_at,
               'coverage_topic_'||sequence,'','Coverage basics '||sequence,'','common','','lore'
        FROM generate_series(1,1300) AS sequence");
    $coverageInsert->execute(['installation'=>$installation,'created_at'=>$clock->iso()]);
    $coverageCatalogPages=[];
    foreach([1,2,3]as$coveragePage){
        $coverageCatalogPages[]=$uiDescriptions->oghmaCatalog([
            'installation_id'=>$installation,'search'=>'coverage topic','page'=>$coveragePage,'page_size'=>999,
        ]);
    }
    $coverageCatalogIds=array_merge(...array_map(
        static fn(array$page):array=>array_column($page['rows'],'document_id'),
        $coverageCatalogPages
    ));
    $coveragePastEnd=$uiDescriptions->oghmaCatalog([
        'installation_id'=>$installation,'search'=>'coverage topic','page'=>999,'page_size'=>999,
    ]);
    $check(
        array_map(static fn(array$page):int=>count($page['rows']),$coverageCatalogPages)===[500,500,300]
        &&array_map(static fn(array$page):int=>(int)$page['page_size'],$coverageCatalogPages)===[500,500,500]
        &&count(array_unique($coverageCatalogIds))===1300
        &&(int)$coverageCatalogPages[0]['total']===1300
        &&(int)$coverageCatalogPages[0]['pages']===3
        &&(int)$coveragePastEnd['page']===3
        &&count($coveragePastEnd['rows'])===300,
        'Oghma catalog pagination did not enforce the 500-article cap or expose every matching article'
    );
    $coverageCandidates=$products->knowledgeCandidates([
        'installation_id'=>$installation,'profile_id'=>$profile['profile_id'],'playthrough_id'=>$playthrough['playthrough_id'],
    ]);
    $coverageTopics=array_filter(array_column($coverageCandidates,'topic'),static fn(string$topic):bool=>str_starts_with($topic,'coverage_topic_'));
    $coverageTurn=$groundedTurn;$coverageTurn['payload']['input']['text']='Tell me about coverage topic 1300.';
    $coverageExtraction=$products->groundedOghmaExtraction($coverageTurn);
    $check(count($coverageTopics)===1300&&in_array('coverage_topic_1300',$coverageExtraction['topics'],true),
        'Oghma retrieval did not scan the complete catalog beyond 1,000 articles');
    $db->exec("DELETE FROM knowledge_documents WHERE provenance->>'source'='coverage-test'");
    foreach(glob($oghmaFixtureRoot.'/*')?:[]as$fixturePath)unlink($fixturePath);rmdir($oghmaFixtureRoot);
$npcProfile=$service->createRevisioned('profile',['installation_id'=>$installation,'name'=>'Nalcarya',
    'actor_identity'=>['kind'=>'npc','record_id'=>'nalcarya','display_name'=>'Nalcarya','content_file'=>'Morrowind.esm'],
    'content'=>['role'=>'npc','management'=>['locked'=>true,'favorite'=>false]],'change_reason'=>'created']);
$npcScope=['installation_id'=>$installation,'profile_id'=>$npcProfile['profile_id'],'playthrough_id'=>$playthrough['playthrough_id']];
$relationship=$service->setRelationship($npcScope+['actor_identity'=>['kind'=>'player','record_id'=>'player','display_name'=>'Nerevarine','content_file'=>'Morrowind.esm','refnum'=>['index'=>1,'content_file'=>0]],'disposition'=>20,'affinity'=>5,
    'source_mode'=>'manual','reason'=>'test']);
$check($relationship['disposition'] === 20 && count($products->relationships($npcScope)) === 1, 'relationship audit foundation failed');
$relationshipProjection=$db->prepare("SELECT npc.extended_data#>>'{relationships,player,disposition}' FROM npc_metadata metadata JOIN public.core_npc_master npc ON npc.id=metadata.npc_id WHERE metadata.source_profile_id=:profile");
$relationshipProjection->execute(['profile'=>$npcProfile['profile_id']]);
$check($relationshipProjection->fetchColumn()==='20','relationship did not project into the Herika NPC contract');
$service->deleteRevisioned('profile',$npcProfile['profile_id']);
$narrative=$service->createNarrative($scope+['kind'=>'diary','title'=>'Arrival','content'=>'I reached Balmora.',
    'provenance'=>['source'=>'authored-test']]);
$narrativeProjection=$db->prepare('SELECT diary.content FROM diarylog_metadata metadata JOIN public.diarylog diary ON diary.rowid=metadata.rowid WHERE metadata.narrative_id=:narrative');
$narrativeProjection->execute(['narrative'=>$narrative['narrative_id']]);
$check($narrativeProjection->fetchColumn()==='I reached Balmora.','narrative did not project into the Herika diary contract');
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
$historyOwnerIdentity=['kind'=>'npc','record_id'=>'history_owner','display_name'=>'History Owner','content_file'=>'Morrowind.esm',
    'refnum'=>['index'=>62001,'content_file'=>0]];
$historyRecipientIdentity=['kind'=>'npc','record_id'=>'history_recipient','display_name'=>'History Recipient','content_file'=>'Morrowind.esm',
    'refnum'=>['index'=>62002,'content_file'=>0]];
$historyOutsiderIdentity=['kind'=>'npc','record_id'=>'history_outsider','display_name'=>'History Outsider','content_file'=>'Morrowind.esm',
    'refnum'=>['index'=>62003,'content_file'=>0]];
$historyOwner=$service->createRevisioned('profile',['installation_id'=>$installation,'name'=>'History Owner',
    'actor_identity'=>$historyOwnerIdentity,'content'=>['role'=>'npc']]);
$historyRecipient=$service->createRevisioned('profile',['installation_id'=>$installation,'name'=>'History Recipient',
    'actor_identity'=>$historyRecipientIdentity,'content'=>['role'=>'npc']]);
$historyOutsider=$service->createRevisioned('profile',['installation_id'=>$installation,'name'=>'History Outsider',
    'actor_identity'=>$historyOutsiderIdentity,'content'=>['role'=>'npc']]);
$baseEventRow=(int)$db->query('SELECT COALESCE(max(rowid),0) FROM eventlog')->fetchColumn();
$insertEvent=$db->prepare("INSERT INTO eventlog(type,data,sess,gamets,localts,ts,people) VALUES('death',:data,NULL,:gamets,:localts,:ts,'|Player|') RETURNING rowid");
$insertMetadata=$db->prepare("INSERT INTO eventlog_metadata(rowid,installation_id,playthrough_id,profile_id,projection_kind,projection_key,speaker,target,audience,payload) VALUES(:rowid,:installation,:playthrough,:profile,'cursor_test',:key,'{}'::jsonb,'{}'::jsonb,'[]'::jsonb,'{}'::jsonb)");
for($index=1;$index<=12;$index++){$insertEvent->execute(['data'=>'cursor event '.$index,'gamets'=>$index,'localts'=>1_700_000_000+$index,'ts'=>1_700_000_000_000+$index]);$rowid=(int)$insertEvent->fetchColumn();$insertMetadata->execute(['rowid'=>$rowid,'installation'=>$installation,'playthrough'=>$playthrough['playthrough_id'],'profile'=>$profile['profile_id'],'key'=>'cursor-test:'.$index]);}
$firstCursorPage=$eventLogs->page(['installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id'],'since_rowid'=>$baseEventRow,'limit'=>10]);
$firstCursorIds=array_column($firstCursorPage['data'],'rowid');$nextCursor=max($firstCursorIds);
$secondCursorPage=$eventLogs->page(['installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id'],'since_rowid'=>$nextCursor,'limit'=>10]);
$check(count($firstCursorIds)===10&&$nextCursor===$baseEventRow+10&&count($secondCursorPage['data'])===2,
    'eventlog live cursor skipped or duplicated a burst window');
$clock->advance(1);$syncCustom=$products->createKnowledge([
    'installation_id'=>$installation,'profile_id'=>null,'playthrough_id'=>null,'title'=>'Factory Sync Custom',
    'content'=>'A user-authored article that must survive the management factory sync control.',
    'provenance'=>['source'=>'management-csv'],'topic'=>'factory_sync_custom','aliases'=>'',
    'topic_desc_basic'=>'User-authored factory sync fixture.','knowledge_class'=>'scholar','knowledge_class_basic'=>'common',
    'tags'=>'factory sync fixture','category'=>'lore'],['factory','sync','custom'],$clock->iso());
$managementRouter=new ManagementRouter($management,$products,$service,eventLogRepository:$eventLogs,oghmaCatalogImporter:$oghmaImporter);
$denied=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/api/v1/diagnostics'));
$check($denied->status===401, 'management API accepted missing browser session');
$signed=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/quickstart'));
$check($signed->status===303 && ($signed->headers['Location']??'')==='/ALMSIVIserver/ui/home.php', 'legacy management route did not redirect to sibling-style PHP page');
$csrf=$browser['csrf'];$cookie='almsivi_management='.$browser['session'].'; almsivi_csrf='.$csrf;
$descriptionCsv=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/exports/descriptions/example.csv',['Cookie'=>$cookie]));
$check($descriptionCsv->status===200&&str_contains($descriptionCsv->body,'plugin,baseid,name,description')
    &&($descriptionCsv->headers['Content-Type']??'')==='text/csv; charset=utf-8','description example CSV export failed');
$descriptionResetDenied=$managementRouter->dispatch(new Request('POST','/ALMSIVIserver/manage/forms/description-reset',['Cookie'=>$cookie],[],http_build_query(['installation_id'=>$installation,'confirm'=>'Reset'])));
$check($descriptionResetDenied->status===303&&($descriptionResetDenied->headers['Location']??'')==='/ALMSIVIserver/ui/home.php',
    'description reset did not reject missing CSRF');
$factorySyncDenied=$managementRouter->dispatch(new Request('POST','/ALMSIVIserver/manage/forms/oghma-factory-sync',['Cookie'=>$cookie],[],http_build_query(['installation_id'=>$installation])));
$check($factorySyncDenied->status===303&&($factorySyncDenied->headers['Location']??'')==='/ALMSIVIserver/ui/home.php',
    'Oghma factory sync did not reject missing CSRF');
$factorySync=$managementRouter->dispatch(new Request('POST','/ALMSIVIserver/manage/forms/oghma-factory-sync',['Cookie'=>$cookie],[],http_build_query([
    '_csrf'=>$csrf,'installation_id'=>$installation,'embed'=>'1'])));
$expectedSyncLocation='/ALMSIVIserver/ui/worldknowledge_upload.php?status=factory-synced&count=1300&installation_id='.$installation.'&embed=1';
$factorySyncVersion=$db->query("SELECT catalog_version FROM oghma_catalogs WHERE state='active'")->fetchColumn();
$factorySyncRows=(int)$db->query("SELECT count(*) FROM oghma_factory_documents WHERE installation_id='{$installation}'")->fetchColumn();
$factorySyncCustomRows=(int)$db->query("SELECT count(*) FROM knowledge_documents WHERE document_id='{$syncCustom['document_id']}' AND deleted_at IS NULL")->fetchColumn();
$check($factorySync->status===303&&($factorySync->headers['Location']??'')===$expectedSyncLocation
    &&$factorySyncVersion==='morrowind-official-3e427-v5.14'&&$factorySyncRows===1300&&$factorySyncCustomRows===1,
    'Oghma factory sync control did not install the current dataset while preserving custom knowledge');
$home=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/quickstart',['Cookie'=>$cookie]));
$check($home->status===303 && ($home->headers['Location']??'')==='/ALMSIVIserver/ui/home.php', 'authenticated legacy route did not preserve the PHP page redirect');
$diagnostics=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/api/v1/diagnostics',['Cookie'=>$cookie]));
$check($diagnostics->status===200 && !str_contains($diagnostics->body,'manage-secret'), 'management diagnostics auth or redaction failed');
$historyPath='/ALMSIVIserver/manage/api/v1/profiles/'.$historyOwner['profile_id'].'/eventlog';
$historyPayload=['playthrough_id'=>$playthrough['playthrough_id'],'event'=>'(History Owner gave History Recipient a kwama egg.)',
    'recipient_profile_ids'=>[$historyRecipient['profile_id']]];
$historyDenied=$managementRouter->dispatch(new Request('POST',$historyPath,['Cookie'=>$cookie,'Content-Type'=>'application/json'],[],json_encode($historyPayload)));
$check($historyDenied->status===401,'NPC history injection accepted missing CSRF');
$jobsBeforeHistory=(int)$db->query('SELECT count(*) FROM durable_jobs')->fetchColumn();
$attemptsBeforeHistory=(int)$db->query('SELECT count(*) FROM provider_attempts')->fetchColumn();
$historyInjected=$managementRouter->dispatch(new Request('POST',$historyPath,
    ['Cookie'=>$cookie,'X-CSRF-Token'=>$csrf,'Content-Type'=>'application/json'],[],json_encode($historyPayload)));
$historyInjectedBody=json_decode($historyInjected->body,true,32,JSON_THROW_ON_ERROR);
$historyRowId=(int)($historyInjectedBody['data']['rowid']??0);
$check($historyInjected->status===201&&$historyRowId>0
    &&(int)$db->query('SELECT count(*) FROM durable_jobs')->fetchColumn()===$jobsBeforeHistory
    &&(int)$db->query('SELECT count(*) FROM provider_attempts')->fetchColumn()===$attemptsBeforeHistory,
    'explicit NPC history injection failed or triggered provider work');
$historyQuery=['playthrough_id'=>$playthrough['playthrough_id'],'limit'=>'100'];
$ownerHistory=$managementRouter->dispatch(new Request('GET',$historyPath,['Cookie'=>$cookie],$historyQuery));
$ownerHistoryBody=json_decode($ownerHistory->body,true,32,JSON_THROW_ON_ERROR)['data'];
$recipientHistory=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/api/v1/profiles/'.$historyRecipient['profile_id'].'/eventlog',
    ['Cookie'=>$cookie],$historyQuery));
$recipientHistoryBody=json_decode($recipientHistory->body,true,32,JSON_THROW_ON_ERROR)['data'];
$outsiderHistory=$managementRouter->dispatch(new Request('GET','/ALMSIVIserver/manage/api/v1/profiles/'.$historyOutsider['profile_id'].'/eventlog',
    ['Cookie'=>$cookie],$historyQuery));
$outsiderHistoryBody=json_decode($outsiderHistory->body,true,32,JSON_THROW_ON_ERROR)['data'];
$check($ownerHistory->status===200&&$recipientHistory->status===200&&$outsiderHistory->status===200
    &&array_column($ownerHistoryBody['events'],'rowid')===[$historyRowId]
    &&array_column($recipientHistoryBody['events'],'rowid')===[$historyRowId]
    &&$outsiderHistoryBody['events']===[]&&$ownerHistoryBody['events'][0]['deletable']===true
    &&$ownerHistoryBody['events'][0]['data']==='(History Owner gave History Recipient a kwama egg.)'
    &&in_array('inputtext',$ownerHistoryBody['event_types'],true),
    'NPC history was not bounded to exact recipient identities');
$filteredHistory=$managementRouter->dispatch(new Request('GET',$historyPath,['Cookie'=>$cookie],$historyQuery+['type'=>'inputtext']));
$hiddenHistoryType=$managementRouter->dispatch(new Request('GET',$historyPath,['Cookie'=>$cookie],$historyQuery+['type'=>'rechat']));
$check($filteredHistory->status===200
    &&array_column(json_decode($filteredHistory->body,true,32,JSON_THROW_ON_ERROR)['data']['events'],'rowid')===[$historyRowId]
    &&$hiddenHistoryType->status===422,'NPC history event-type filter escaped the visible narrative types');
$historyDeletePath='/ALMSIVIserver/manage/api/v1/profiles/'.$historyRecipient['profile_id'].'/eventlog/'.$historyRowId;
$historyDeleteDenied=$managementRouter->dispatch(new Request('DELETE',$historyDeletePath,
    ['Cookie'=>$cookie,'Content-Type'=>'application/json'],[],json_encode(['playthrough_id'=>$playthrough['playthrough_id']])));
$check($historyDeleteDenied->status===401,'NPC history deletion accepted missing CSRF');
$historyWrongOwner=$managementRouter->dispatch(new Request('DELETE','/ALMSIVIserver/manage/api/v1/profiles/'.$historyOutsider['profile_id'].'/eventlog/'.$historyRowId,
    ['Cookie'=>$cookie,'X-CSRF-Token'=>$csrf,'Content-Type'=>'application/json'],[],json_encode(['playthrough_id'=>$playthrough['playthrough_id']])));
$check($historyWrongOwner->status===422,'NPC history deletion escaped exact identity ownership');
$historyDeleted=$managementRouter->dispatch(new Request('DELETE',$historyDeletePath,
    ['Cookie'=>$cookie,'X-CSRF-Token'=>$csrf,'Content-Type'=>'application/json'],[],json_encode(['playthrough_id'=>$playthrough['playthrough_id']])));
$historyAfterDelete=$managementRouter->dispatch(new Request('GET',$historyPath,['Cookie'=>$cookie],$historyQuery));
$historySuppression=$db->query("SELECT suppression_reason FROM eventlog_metadata WHERE rowid={$historyRowId}")->fetchColumn();
$check($historyDeleted->status===200&&$historySuppression==='npc_history_delete'
    &&json_decode($historyAfterDelete->body,true,32,JSON_THROW_ON_ERROR)['data']['events']===[],
    'NPC history delete did not soft-suppress only the injected projection');
$historyForeignScope=$managementRouter->dispatch(new Request('GET',$historyPath,['Cookie'=>$cookie],
    ['playthrough_id'=>$legacyPlaythrough,'limit'=>'100']));
$check($historyForeignScope->status===422,'NPC history accepted a foreign playthrough');
$service->deleteRevisioned('profile',$historyOwner['profile_id']);
$service->deleteRevisioned('profile',$historyRecipient['profile_id']);
$service->deleteRevisioned('profile',$historyOutsider['profile_id']);
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

// Route an existing manual generation job without changing later connector choices or using runtime credentials.
$generationConnector=$service->createRevisioned('provider',['installation_id'=>$installation,'name'=>'Generation connector',
    'content'=>['driver'=>'mock','model'=>'generation-v1']]);
$generationCore=$service->createRevisioned('core_profile',['installation_id'=>$installation,'name'=>'Generation core',
    'content'=>['schema'=>'almsivi.core-profile.v1','prompt'=>'','settings_overrides'=>[],
        'routing'=>['profile_generation_configuration_id'=>$generationConnector['configuration_id']]]]);
$generationProfile=$service->createRevisioned('profile',['installation_id'=>$installation,'name'=>'Routed generation NPC',
    'core_profile_id'=>$generationCore['core_profile_id'],'actor_identity'=>['kind'=>'npc','record_id'=>'route_test','content_file'=>'Morrowind.esm'],
    'content'=>['biography'=>'Unchanged until generation finishes.']]);
$generationJob=$products->enqueueProfileGeneration($generationProfile['profile_id']);
$generationPayload=json_decode((string)$db->query("SELECT payload FROM durable_jobs WHERE job_id='{$generationJob['job_id']}'")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
$check($generationPayload==['profile_id'=>$generationProfile['profile_id'],'base_revision'=>1,
    'provider_configuration_id'=>$generationConnector['configuration_id'],'provider_revision'=>1],
    'generation job did not freeze only the inherited connector identity and revision');
$service->revise('provider',$generationConnector['configuration_id'],['driver'=>'mock','model'=>'generation-v2'],'new connector revision');
$sameGenerationJob=$products->enqueueProfileGeneration($generationProfile['profile_id']);
$check($sameGenerationJob['job_id']===$generationJob['job_id'],'requeue replaced the frozen generation job');
$service->revise('core_profile',$generationCore['core_profile_id'],array_replace($generationCore['content'],['routing'=>[]]),'use runtime for future jobs');
try{$service->deleteRevisioned('provider',$generationConnector['configuration_id']);throw new RuntimeException('queued generation connector deleted');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='provider_in_use','queued generation deletion guard failed');}
$generationRows=(new \ALMSIVIserver\Infrastructure\ManagementUiRepository($db))->rows('llm');
$generationRow=array_values(array_filter($generationRows,static fn(array$row):bool=>$row['configuration_id']===$generationConnector['configuration_id']))[0];
$check((int)$generationRow['queued_job_usage']===1,'queued generation use was not visible to connector management');
$generationRegistry=FirstPartyJobHandlerFactory::registry($db,new \ALMSIVIserver\Infrastructure\MediaStore($firstPartyMediaRoot,1024,2048),
    $clock,providerConfig:['provider'=>['driver'=>'must-not-use-runtime']]);
$generationStats=(new Worker($jobs,$generationRegistry,'profile-route-test',5,1,1,0,10,['profile.generate'],static fn(int $microseconds):mixed=>null))->run();
$generationAttempt=$db->query("SELECT model,config_revision,metadata FROM provider_attempts WHERE job_id='{$generationJob['job_id']}'")->fetch();
$check($generationStats['succeeded']===1&&$generationAttempt['model']==='generation-v1'&&$generationAttempt['config_revision']==='1',
    'generation ignored the queued revision or used the unrelated runtime');
$generationCurrent=$products->getRevisioned('profile',$generationProfile['profile_id']);
$check((int)$generationCurrent['current_revision']===2&&str_contains($generationCurrent['content']['notes'],'Deterministic mock generation'),
    'routed provider did not produce the profile revision');
$service->revise('core_profile',$generationCore['core_profile_id'],$generationCore['content'],'restore inherited generator');
$service->revise('profile',$generationProfile['profile_id'],$generationCurrent['content']+['routing'=>['profile_generation_configuration_id'=>'']],'explicit runtime override');
$runtimeGenerationJob=$products->enqueueProfileGeneration($generationProfile['profile_id']);
$runtimeGenerationPayload=json_decode((string)$db->query("SELECT payload FROM durable_jobs WHERE job_id='{$runtimeGenerationJob['job_id']}'")->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
$check(!array_key_exists('provider_configuration_id',$runtimeGenerationPayload),'explicit runtime override did not bypass the Core Profile generator');
$runtimeGenerationStats=(new Worker($jobs,$firstPartyRegistry,'profile-runtime-route-test',5,1,1,0,10,['profile.generate'],static fn(int $microseconds):mixed=>null))->run();
$check($runtimeGenerationStats['succeeded']===1,'runtime generation compatibility failed');
$foreignGenerator=$service->createRevisioned('provider',['installation_id'=>$legacyInstallation,'name'=>'Foreign generator',
    'content'=>['driver'=>'mock','model'=>'foreign-model']]);
$generationCurrent=$products->getRevisioned('profile',$generationProfile['profile_id']);
$generationCurrent['content']['routing']=['profile_generation_configuration_id'=>$foreignGenerator['configuration_id']];
$service->revise('profile',$generationProfile['profile_id'],$generationCurrent['content'],'invalid foreign route');
try{$products->enqueueProfileGeneration($generationProfile['profile_id']);throw new RuntimeException('foreign generation connector accepted');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='profile_generation_connector_unavailable','generation ownership check failed');}
try{$products->providerRevisionForInstallation($installation,$foreignGenerator['configuration_id'],1);throw new RuntimeException('foreign generation revision loaded');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='profile_generation_connector_unavailable','worker revision ownership check failed');}
foreach([[$playerProfile['profile_id'],'enqueuePlayerSpeechStyleGeneration'],[$narratorProfile['profile_id'],'enqueueNarratorProfileGeneration']]as[$routedProfileId,$enqueueMethod]){
    $routedProfile=$products->getRevisioned('profile',$routedProfileId);$routedContent=$routedProfile['content'];
    $routedContent['routing']['profile_generation_configuration_id']=$foreignGenerator['configuration_id'];
    $service->revise('profile',$routedProfileId,$routedContent,'route existing generation mode');
    $products->$enqueueMethod($routedProfileId);
}
$modeRouteStats=(new Worker($jobs,$generationRegistry,'profile-modes-route-test',5,2,2,0,10,['profile.generate'],static fn(int $microseconds):mixed=>null))->run();
$check($modeRouteStats['succeeded']===2,'narrator or player speech-style generation ignored its routed connector');

// Queue one manual diary only after an explicit opt-in, a dedicated connector route, and witnessed context exist.
$diaryConnector=$service->createRevisioned('provider',['installation_id'=>$installation,'name'=>'Diary connector',
    'content'=>['driver'=>'mock','model'=>'diary-v1']]);
$diaryCoreContent=['schema'=>'almsivi.core-profile.v1','prompt'=>'','settings_overrides'=>[],'routing'=>[]];
$diaryCore=$service->createRevisioned('core_profile',['installation_id'=>$installation,'name'=>'Manual diary core','content'=>$diaryCoreContent]);
$diaryActor=['kind'=>'npc','record_id'=>'diary_test','content_file'=>'Morrowind.esm','display_name'=>'Diary NPC'];
$diaryProfile=$service->createRevisioned('profile',['installation_id'=>$installation,'name'=>'Diary NPC',
    'core_profile_id'=>$diaryCore['core_profile_id'],'actor_identity'=>$diaryActor,
    'content'=>['biography'=>'Witnesses events in Balmora.']]);
$diaryScope=['installation_id'=>$installation,'profile_id'=>$diaryProfile['profile_id'],
    'playthrough_id'=>$playthrough['playthrough_id'],'request_id'=>Uuid::v4()];
try{$products->enqueueDiaryGeneration($diaryScope);throw new RuntimeException('default diary policy queued work');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='diary_generation_disabled','manual diary did not default off');}
$jobsBeforeDiarySave=(int)$db->query("SELECT count(*) FROM durable_jobs WHERE job_type='narrative.generate'")->fetchColumn();
$diaryCoreContent['settings_overrides']['diary']=['enabled'=>true,'include_in_context'=>true,'context_turn_limit'=>12,
    'prompt'=>'Record only witnessed events.'];
$service->revise('core_profile',$diaryCore['core_profile_id'],$diaryCoreContent,'opt in without a connector');
try{$products->enqueueDiaryGeneration($diaryScope);throw new RuntimeException('diary without connector queued work');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='diary_generation_connector_unavailable','manual diary accepted no connector');}
$check((int)$db->query("SELECT count(*) FROM durable_jobs WHERE job_type='narrative.generate'")->fetchColumn()===$jobsBeforeDiarySave,
    'saving diary settings called a provider or queued work');
$diaryTurn=Uuid::v4();
$diaryEvent=$db->prepare("INSERT INTO eventlog(type,data,gamets,localts,ts,people,location) VALUES('inputtext',:data,42,1700000100,1700000100000,'|Diary NPC|','Balmora') RETURNING rowid");
$diaryEvent->execute(['data'=>'Nerevarine: We reached Balmora before dusk.']);$diaryRowId=(int)$diaryEvent->fetchColumn();
$db->prepare("INSERT INTO eventlog_metadata(rowid,installation_id,playthrough_id,profile_id,turn_id,projection_kind,projection_key,speaker,target,audience,payload) VALUES(:rowid,:installation,:playthrough,:profile,:turn,'diary_test',:key,CAST(:speaker AS jsonb),'{}'::jsonb,'[]'::jsonb,'{}'::jsonb)")
    ->execute(['rowid'=>$diaryRowId,'installation'=>$installation,'playthrough'=>$playthrough['playthrough_id'],
        'profile'=>$diaryProfile['profile_id'],'turn'=>$diaryTurn,'key'=>'diary-test:'.$diaryTurn,
        'speaker'=>json_encode($diaryActor,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);
$diaryProfileContent=$products->getRevisioned('profile',$diaryProfile['profile_id'])['content'];
$diaryProfileContent['routing']['diary_generation_configuration_id']=$diaryConnector['configuration_id'];
$service->revise('profile',$diaryProfile['profile_id'],$diaryProfileContent,'route future manual diaries for this NPC');
$diaryJob=$products->enqueueDiaryGeneration($diaryScope);
$diaryPayload=json_decode((string)$db->query("SELECT payload FROM durable_jobs WHERE job_id='{$diaryJob['job_id']}'")->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
$check($diaryPayload['profile_revision']===2&&$diaryPayload['provider_revision']===1
    &&$diaryPayload['source_turn_ids']===[$diaryTurn]&&count($diaryPayload['input']['witnessed_context'])===1
    &&!isset($diaryPayload['input']['endpoint'],$diaryPayload['input']['api_key']),
    'manual diary request was not idempotent, revision-frozen, bounded, or secret-free');
$diaryConnectorRows=(new ManagementUiRepository($db))->rows('llm');
$diaryConnectorRow=array_values(array_filter($diaryConnectorRows,static fn(array$row):bool=>
    $row['configuration_id']===$diaryConnector['configuration_id']))[0];
$check((int)$diaryConnectorRow['queued_job_usage']===1&&(int)$diaryConnectorRow['profile_usage']===1,
    'manual diary connector use was not visible to connector management');
$service->revise('provider',$diaryConnector['configuration_id'],['driver'=>'mock','model'=>'diary-v2'],'new diary connector revision');
$diaryProfileContent['routing']=[];$service->revise('profile',$diaryProfile['profile_id'],$diaryProfileContent,'leave queued diary frozen');
$diaryReplay=$products->enqueueDiaryGeneration($diaryScope);
$check($diaryReplay['job_id']===$diaryJob['job_id']&&$diaryReplay['narrative_id']===$diaryJob['narrative_id']
    &&$diaryReplay['provider_revision']===1,'manual diary replay did not retain its original acceptance after configuration changed');
try{$service->deleteRevisioned('provider',$diaryConnector['configuration_id']);throw new RuntimeException('queued diary connector deleted');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='provider_in_use','queued diary deletion guard failed');}
$diaryRegistry=FirstPartyJobHandlerFactory::registry($db,new \ALMSIVIserver\Infrastructure\MediaStore($firstPartyMediaRoot,1024,2048),
    $clock,providerConfig:['provider'=>['driver'=>'must-not-use-runtime']]);
$diaryStats=(new Worker($jobs,$diaryRegistry,'manual-diary-test',5,1,1,0,10,['narrative.generate'],static fn(int $microseconds):mixed=>null))->run();
$diaryRow=$db->query("SELECT kind,title,content,provenance FROM narrative_records WHERE narrative_id='{$diaryJob['narrative_id']}'")->fetch();
$diaryProvenance=json_decode((string)$diaryRow['provenance'],true,32,JSON_THROW_ON_ERROR);
$diaryAttempt=$db->query("SELECT operation,model,config_revision,state FROM provider_attempts WHERE job_id='{$diaryJob['job_id']}'")->fetch();
$check($diaryStats['succeeded']===1&&$diaryRow['kind']==='diary'&&$diaryRow['title']==='Diary NPC diary'
    &&str_contains($diaryRow['content'],'1 witnessed Morrowind event')
    &&$diaryProvenance['source']==='manual-diary-generation'&&$diaryProvenance['profile_revision']===2
    &&$diaryProvenance['provider_revision']===1&&$diaryProvenance['source_turn_ids']===[$diaryTurn]
    &&$diaryAttempt['operation']==='generate_diary'&&$diaryAttempt['model']==='diary-v1'
    &&$diaryAttempt['config_revision']==='1'&&$diaryAttempt['state']==='succeeded',
    'manual diary worker did not use the frozen provider revision or persist exact scoped provenance');
$service->deleteRevisioned('provider',$diaryConnector['configuration_id']);
$service->createNarrative(['installation_id'=>$installation,'profile_id'=>$diaryProfile['profile_id'],
    'playthrough_id'=>$playthrough['playthrough_id'],'kind'=>'summary','title'=>'Still included','content'=>'A bounded summary.',
    'provenance'=>['source'=>'authored-test']]);
$diaryCoreContent['settings_overrides']['diary']['include_in_context']=false;
$service->revise('core_profile',$diaryCore['core_profile_id'],$diaryCoreContent,'hide diary narratives from prompts');
$products->bindActorProfile(['installation_id'=>$installation,'playthrough_id'=>$playthrough['playthrough_id']],
    $diaryActor,$diaryProfile['profile_id'],$clock->iso());
$diaryPromptContext=$products->promptContext(['installation_id'=>$installation,'profile_id'=>$profile['profile_id'],
    'playthrough_id'=>$playthrough['playthrough_id'],'session_id'=>'20000000-0000-4000-8000-000000000099',
    'payload'=>['target'=>$diaryActor]],$clock->iso());
$check(array_column($diaryPromptContext['narrative'],'kind')===['summary'],
    'diary context opt-out removed non-diary narratives or retained the generated diary');

$derivedMemoryId='30000000-0000-4000-8000-000000000001';
$derivedPayload=['installation_id'=>$legacyInstallation,'profile_id'=>$legacyProfile,'playthrough_id'=>$legacyPlaythrough,
    'memory_id'=>$derivedMemoryId,'tier'=>'recent','content'=>'Deterministic derived memory.','source_event_id'=>$deliverySource,
    'provenance'=>['source'=>'dialogue.delivery','status'=>'played']];
$derive=$firstPartyRegistry->for('memory.derive',1);$derive->handle($derivedPayload,'memory.derive:test',static fn():bool=>true);$derive->handle($derivedPayload,'memory.derive:test',static fn():bool=>true);
$check((int)$db->query("SELECT count(*) FROM memory_records WHERE memory_id='{$derivedMemoryId}'")->fetchColumn()===1, 'first-party memory derive was not idempotent');
$derivePlayedMemory=static function(int $ordinal)use($db,$derive,$legacyInstallation,$legacyProfile,$legacyPlaythrough,$legacySession):void{
    $memoryText='Played memory '.$ordinal.'.'.($ordinal>=15?str_repeat('古',3666):'');
    $source=Uuid::v4();$dialogue=Uuid::v4();$message=Uuid::v4();$memory=Uuid::v4();$turn=Uuid::v4();$request=Uuid::v4();$turnMessage=Uuid::v4();
    $occurred=sprintf('2026-01-01T00:00:%02dZ',$ordinal);
    $db->prepare("INSERT INTO turns(turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,speaker,target,audience,context,state,accepted_at) VALUES(:turn,:request,:message,:session,1,'text','en','memory source','{}'::jsonb,'{}'::jsonb,'[]'::jsonb,'{}'::jsonb,'complete','2026-01-01T00:00:00Z')")
        ->execute(['turn'=>$turn,'request'=>$request,'message'=>$turnMessage,'session'=>$legacySession]);
    $db->prepare("INSERT INTO dialogue_utterances(dialogue_message_id,session_id,turn_id,request_id,generation,utterance_index,utterance_count,response_line_id,utterance_id,speaker,addressee,audience,text,emitted_at,delivery_deadline_at) VALUES(:dialogue,:session,:turn,:request,1,1,1,:line,:utterance,'{}'::jsonb,'{}'::jsonb,'[]'::jsonb,:text,'2026-01-01T00:00:00Z','2026-01-01T00:05:00Z')")
        ->execute(['dialogue'=>$dialogue,'session'=>$legacySession,'turn'=>$turn,'request'=>$request,'line'=>$dialogue,'utterance'=>Uuid::v4(),'text'=>$memoryText]);
    $db->prepare("INSERT INTO source_events(source_event_id,installation_id,session_id,generation,event_kind,occurred_at,schema_name,request_id,turn_id,payload) VALUES(:source,:installation,:session,1,'dialogue.delivery',:occurred,'almsivi.dialogue-delivery-result.v1',:request,:turn,'{}'::jsonb)")
        ->execute(['source'=>$source,'installation'=>$legacyInstallation,'session'=>$legacySession,'occurred'=>$occurred,'request'=>$request,'turn'=>$turn]);
    $db->prepare("INSERT INTO dialogue_delivery_results(dialogue_message_id,source_event_id,message_id,request_id,turn_id,session_id,generation,speaker,status,reason_code,completed_at) VALUES(:dialogue,:source,:message,:request,:turn,:session,1,'{}'::jsonb,'played','ok',:occurred)")
        ->execute(['dialogue'=>$dialogue,'source'=>$source,'message'=>$message,'request'=>$request,'turn'=>$turn,'session'=>$legacySession,'occurred'=>$occurred]);
    $derive->handle(['installation_id'=>$legacyInstallation,'profile_id'=>$legacyProfile,'playthrough_id'=>$legacyPlaythrough,
        'memory_id'=>$memory,'tier'=>'recent','content'=>$memoryText,'source_event_id'=>$source,
        'occurred_at'=>$occurred,'provenance'=>['source'=>'dialogue.delivery','status'=>'played','source_event_ids'=>[$source]]],
        'memory.derive:played:'.$ordinal,static fn():bool=>true);
};
foreach(range(2,4)as$ordinal)$derivePlayedMemory($ordinal);
$consolidationWorker=new Worker($jobs,$firstPartyRegistry,'memory-consolidation-four',5,1,20,0,10,['memory.consolidate'],static fn(int $microseconds):mixed=>null);
$fourStats=$consolidationWorker->run();
$check($fourStats['retried']===0&&$fourStats['dead']===0
    &&(int)$db->query("SELECT count(*) FROM memory_records WHERE installation_id='{$legacyInstallation}' AND profile_id='{$legacyProfile}' AND playthrough_id='{$legacyPlaythrough}' AND tier='mid' AND deleted_at IS NULL")->fetchColumn()===1,
    'four eligible recent memories did not produce exactly one middle memory');
foreach(range(5,16)as$ordinal)$derivePlayedMemory($ordinal);
$allStats=(new Worker($jobs,$firstPartyRegistry,'memory-consolidation-all',5,1,50,0,10,['memory.consolidate'],static fn(int $microseconds):mixed=>null))->run();
$consolidated=$db->query("SELECT memory_id,tier,content,current_revision,provenance FROM memory_records WHERE installation_id='{$legacyInstallation}' AND profile_id='{$legacyProfile}' AND playthrough_id='{$legacyPlaythrough}' AND tier IN('mid','long') AND deleted_at IS NULL ORDER BY tier,memory_id")->fetchAll();
$middleRows=array_values(array_filter($consolidated,static fn(array $row):bool=>$row['tier']==='mid'));
$longRows=array_values(array_filter($consolidated,static fn(array $row):bool=>$row['tier']==='long'));
$check($allStats['retried']===0&&$allStats['dead']===0&&count($middleRows)===4&&count($longRows)===1,
    'sixteen eligible recent memories did not produce four middle and one long memory');
foreach($middleRows as$row){$provenance=json_decode((string)$row['provenance'],true,64,JSON_THROW_ON_ERROR);$check((int)$row['current_revision']===1
    &&$provenance['source']==='memory.consolidate'&&$provenance['provider']==='first-party'
    &&$provenance['model']==='deterministic-extractive-v1'&&$provenance['revision']===1&&$provenance['source_tier']==='recent'
    &&count($provenance['source_memory_ids'])===4&&count($provenance['source_event_ids'])===4
    &&isset($provenance['source_range']['from'],$provenance['source_range']['to']),'middle-memory provenance is incomplete');}
$longProvenance=json_decode((string)$longRows[0]['provenance'],true,64,JSON_THROW_ON_ERROR);
$cappedSummaries=0;
foreach($consolidated as$row){
    $coverage=json_decode((string)$row['provenance'],true,64,JSON_THROW_ON_ERROR)['content_coverage'];
    $capped=strlen($row['content'])>16380;
    if($capped)++$cappedSummaries;
    $check($coverage['algorithm']==='exact-content-v1'&&$coverage['content_sha256']===hash('sha256',$row['content'])
        &&count($coverage['complete_source_memory_ids'])===($capped?3:4)
        &&strlen($row['content'])<=16384&&mb_check_encoding($row['content'],'UTF-8'),
        'consolidation coverage claimed a truncated source or lost valid UTF-8');
}
$check($cappedSummaries===2,'large recent sources did not exercise both consolidation tier caps');
$check((int)$longRows[0]['current_revision']===1&&$longProvenance['source_tier']==='mid'
    &&count($longProvenance['source_memory_ids'])===4&&count($longProvenance['source_event_ids'])===16
    &&$longProvenance['source_range']['from']==='2026-01-01T00:00:04Z'
    &&$longProvenance['source_range']['to']==='2026-01-01T00:00:16Z',
    'long-memory source range or flattened provenance is incomplete');
$consolidate=$firstPartyRegistry->for('memory.consolidate',1);
$consolidate->handle(['installation_id'=>$legacyInstallation,'profile_id'=>$legacyProfile,'playthrough_id'=>$legacyPlaythrough,
    'source_memory_id'=>$derivedMemoryId,'source_tier'=>'recent'],'memory.consolidate:repeat',static fn():bool=>true);
$check((int)$db->query("SELECT count(*) FROM memory_records WHERE installation_id='{$legacyInstallation}' AND profile_id='{$legacyProfile}' AND playthrough_id='{$legacyPlaythrough}' AND tier IN('mid','long') AND deleted_at IS NULL")->fetchColumn()===5
    &&(int)$db->query("SELECT max(current_revision) FROM memory_records WHERE installation_id='{$legacyInstallation}' AND profile_id='{$legacyProfile}' AND playthrough_id='{$legacyPlaythrough}' AND tier IN('mid','long')")->fetchColumn()===1,
    'memory consolidation replay was not idempotent');
$summaryRepository=new \ALMSIVIserver\Infrastructure\MemorySummaryRepository($db);
$summaryMemory=$middleRows[0];
$check($summaryRepository->enqueue($legacyInstallation,$summaryMemory['memory_id'],1)===null
    &&(int)$db->query("SELECT count(*) FROM durable_jobs WHERE job_type='memory.summarize'")->fetchColumn()===0,
    'default memory behavior queued paid generation');
$summaryProvider=$service->createRevisioned('provider',['installation_id'=>$legacyInstallation,'name'=>'Memory mock',
    'content'=>['driver'=>'mock','model'=>'memory-mock-v1']]);
$summaryPolicyContent=['schema'=>'almsivi.memory-policy.v1','enabled'=>true,'provider_configuration_id'=>$summaryProvider['configuration_id']];
$summaryPolicy=$service->createRevisioned('memory_policy',['installation_id'=>$legacyInstallation,'name'=>'Model memory','content'=>$summaryPolicyContent]);
$check((int)$db->query("SELECT count(*) FROM durable_jobs WHERE job_type='memory.summarize'")->fetchColumn()===0,
    'saving memory policy queued historical generation');
$summaryJob=$summaryRepository->enqueue($legacyInstallation,$summaryMemory['memory_id'],1);
$summaryReplay=$summaryRepository->enqueue($legacyInstallation,$summaryMemory['memory_id'],1);
$check($summaryJob['job_id']===$summaryReplay['job_id'],'memory summary enqueue was not idempotent');
$service->revise('provider',$summaryProvider['configuration_id'],['driver'=>'mock','model'=>'memory-mock-v2'],'test frozen revision');
$summaryStats=(new Worker($jobs,$firstPartyRegistry,'model-memory',5,1,10,0,10,['memory.summarize'],static fn(int $microseconds):mixed=>null))->run();
$summaryStored=$db->query("SELECT * FROM memory_model_summaries WHERE memory_id='{$summaryMemory['memory_id']}'")->fetch();
$summaryOriginal=$products->memory($summaryMemory['memory_id']);
$check($summaryStats['succeeded']===1&&(int)$summaryStored['provider_revision']===1
    &&(int)$summaryOriginal['current_revision']===1&&$summaryOriginal['content']===$summaryMemory['content']
    &&$summaryStored['input_sha256']===hash('sha256',$summaryMemory['content']),
    'model summary changed the original or ignored the frozen provider revision');
$check($summaryRepository->enqueue($legacyInstallation,$summaryMemory['memory_id'],1)===null,
    'completed model summary queued another provider call');
$cancelMemory=$middleRows[1];
$cancelJob=$summaryRepository->enqueue($legacyInstallation,$cancelMemory['memory_id'],1);
$summaryPolicyContent['enabled']=false;
$service->revise('memory_policy',$summaryPolicy['configuration_id'],$summaryPolicyContent,'disable model memory');
$attemptCount=(int)$db->query("SELECT count(*) FROM provider_attempts WHERE operation='summarize_memory'")->fetchColumn();
$cancelStats=(new Worker($jobs,$firstPartyRegistry,'model-memory-disabled',5,1,10,0,10,['memory.summarize'],static fn(int $microseconds):mixed=>null))->run();
$check($cancelStats['succeeded']===1
    &&(int)$db->query("SELECT count(*) FROM provider_attempts WHERE operation='summarize_memory'")->fetchColumn()===$attemptCount,
    'disabling model memory did not stop queued paid work');
$summaryPolicyContent['enabled']=true;
$service->revise('memory_policy',$summaryPolicy['configuration_id'],$summaryPolicyContent,'restore opt-in');
$resumeJob=$summaryRepository->enqueue($legacyInstallation,$cancelMemory['memory_id'],1);
$check($resumeJob['job_id']!==$cancelJob['job_id'],'re-enabling summaries could not enqueue previously skipped work');
$resumeStats=(new Worker($jobs,$firstPartyRegistry,'model-memory-resumed',5,1,10,0,10,['memory.summarize'],static fn(int $microseconds):mixed=>null))->run();
$check($resumeStats['succeeded']===1,'re-enabled model memory did not complete');
$attemptCount=(int)$db->query("SELECT count(*) FROM provider_attempts WHERE operation='summarize_memory'")->fetchColumn();
$editMemory=$middleRows[2];
$editJob=$summaryRepository->enqueue($legacyInstallation,$editMemory['memory_id'],1);
$products->updateMemory($editMemory['memory_id'],'Manually corrected memory.',['corrected'],
    \ALMSIVIserver\Application\DeterministicRetrieval::fakeVector('Manually corrected memory.'),$clock->iso());
$editStats=(new Worker($jobs,$firstPartyRegistry,'model-memory-edited',5,1,10,0,10,['memory.summarize'],static fn(int $microseconds):mixed=>null))->run();
$check($editStats['succeeded']===1
    &&(int)$db->query("SELECT count(*) FROM provider_attempts WHERE operation='summarize_memory'")->fetchColumn()===$attemptCount
    &&$products->memory($editMemory['memory_id'])['content']==='Manually corrected memory.',
    'stale memory job called a provider or overwrote an edit');
$liveMemory=$middleRows[3];
$summaryRepository->enqueue($legacyInstallation,$liveMemory['memory_id'],1);
$disablingProvider=new class($service,$summaryPolicy['configuration_id'],$summaryPolicyContent) implements \ALMSIVIserver\Application\ProfileGenerationProvider {
    public function __construct(private $service,private string $policy,private array $content){}
    public function generate(array $input,\ALMSIVIserver\Application\CancellationToken $cancellation):array{
        $cancellation->throwIfCancellationRequested();$this->content['enabled']=false;
        $this->service->revise('memory_policy',$this->policy,$this->content,'disabled during provider execution');
        return ['summary'=>'This late output must be discarded.'];
    }
};
$disablingRegistry=new JobHandlerRegistry([new \ALMSIVIserver\Application\MemorySummaryJobHandler($summaryRepository,$products,
    new ProviderAttemptRepository($db),[],$disablingProvider)]);
$duringStats=(new Worker($jobs,$disablingRegistry,'model-memory-disabled-during-call',5,1,1,0,10,['memory.summarize'],static fn(int $microseconds):mixed=>null))->run();
$check($duringStats['succeeded']===1
    &&(int)$db->query("SELECT count(*) FROM memory_model_summaries WHERE memory_id='{$liveMemory['memory_id']}'")->fetchColumn()===0
    &&$products->memory($liveMemory['memory_id'])['content']===$liveMemory['content'],
    'disabling model memory during the call did not discard its output');
$db->beginTransaction();$db->exec('SAVEPOINT model_downgrade');
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/062_model_memory_summaries.down.sql'));
    throw new RuntimeException('model summary downgrade discarded data');}
catch(PDOException $error){$check(str_contains($error->getMessage(),'Cannot remove model memory support'),'unexpected model downgrade failure');
    $db->exec('ROLLBACK TO SAVEPOINT model_downgrade');}
$check((int)$db->query('SELECT count(*) FROM memory_model_summaries')->fetchColumn()===2,'guarded downgrade changed model summaries');
$db->rollBack();
$summaryPolicyContent['enabled']=true;
$service->revise('memory_policy',$summaryPolicy['configuration_id'],$summaryPolicyContent,'enable future consolidation');
foreach(range(17,20)as$ordinal)$derivePlayedMemory($ordinal);
$beforeEnqueueFailure=(int)$db->query("SELECT count(*) FROM memory_records WHERE installation_id='{$legacyInstallation}' AND tier='mid'")->fetchColumn();
$newRecent=$db->query("SELECT memory_id FROM memory_records WHERE installation_id='{$legacyInstallation}' AND tier='recent' ORDER BY occurred_at DESC LIMIT 1")->fetchColumn();
$db->exec("CREATE FUNCTION pg_temp.reject_model_enqueue() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''model enqueue test failure''; END'");
$db->exec("CREATE TRIGGER reject_model_enqueue BEFORE INSERT ON durable_jobs FOR EACH ROW WHEN (NEW.job_type='memory.summarize') EXECUTE FUNCTION pg_temp.reject_model_enqueue()");
try{(new \ALMSIVIserver\Infrastructure\FirstPartyJobRepository($db))->consolidateMemories(['installation_id'=>$legacyInstallation,'profile_id'=>$legacyProfile,'playthrough_id'=>$legacyPlaythrough],'recent',$newRecent,$clock->iso());
    throw new RuntimeException('model enqueue failure was ignored');}
catch(PDOException $error){$check(str_contains($error->getMessage(),'model enqueue test failure'),'unexpected model enqueue failure');}
$db->exec('DROP TRIGGER reject_model_enqueue ON durable_jobs');
$check((int)$db->query("SELECT count(*) FROM memory_records WHERE installation_id='{$legacyInstallation}' AND tier='mid'")->fetchColumn()===$beforeEnqueueFailure,
    'failed model enqueue left a consolidated record that could not be retried');
$autoConsolidation=(new Worker($jobs,$firstPartyRegistry,'model-memory-auto-consolidation',5,1,20,0,10,['memory.consolidate'],static fn(int $microseconds):mixed=>null))->run();
$autoSummary=(new Worker($jobs,$firstPartyRegistry,'model-memory-auto-summary',5,1,10,0,10,['memory.summarize'],static fn(int $microseconds):mixed=>null))->run();
$check($autoConsolidation['dead']===0&&$autoSummary['succeeded']===1
    &&(int)$db->query('SELECT count(*) FROM memory_model_summaries')->fetchColumn()===3,
    'enabled future consolidation did not produce its model summary');
try{$foreignPolicy=$summaryPolicyContent;$foreignPolicy['provider_configuration_id']=$providerConfig['configuration_id'];
    $service->revise('memory_policy',$summaryPolicy['configuration_id'],$foreignPolicy,'reject foreign connector');
    throw new RuntimeException('foreign memory connector accepted');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='memory_provider_installation_mismatch','unexpected foreign memory connector error');}
$summaryBackup=['installation_id'=>$legacyInstallation,'data'=>$products->configurationBackupState($legacyInstallation)];
foreach($summaryBackup['data']['configurations']as&$configuration)if($configuration['kind']==='memory_policy')
    $configuration['content']['provider_configuration_id']=$providerConfig['configuration_id'];
unset($configuration);
$policyBeforeRestore=$summaryRepository->policy($legacyInstallation);
try{$products->restoreConfigurationBackup($summaryBackup,$clock->iso());throw new RuntimeException('foreign connector restored into memory policy');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='memory_provider_installation_mismatch','unexpected memory backup restore error');}
$check($summaryRepository->policy($legacyInstallation)===$policyBeforeRestore,'rejected memory backup changed policy history');
try{$service->deleteRevisioned('provider',$summaryProvider['configuration_id']);throw new RuntimeException('configured memory provider deleted');}
catch(InvalidArgumentException $error){$check($error->getMessage()==='provider_in_use','unexpected memory provider deletion error');}
$failedSummary=$summaryRepository->enqueue($legacyInstallation,$editMemory['memory_id'],2);
$db->prepare('UPDATE durable_jobs SET max_attempts=1 WHERE job_id=:id')->execute(['id'=>$failedSummary['job_id']]);
$invalidSummaryProvider=new class implements \ALMSIVIserver\Application\ProfileGenerationProvider {
    public function generate(array $input,\ALMSIVIserver\Application\CancellationToken $cancellation):array{return ['summary'=>''];}
};
$invalidSummaryRegistry=new JobHandlerRegistry([new \ALMSIVIserver\Application\MemorySummaryJobHandler($summaryRepository,$products,
    new ProviderAttemptRepository($db),[],$invalidSummaryProvider)]);
$failureStats=(new Worker($jobs,$invalidSummaryRegistry,'model-memory-invalid-output',5,1,1,0,10,['memory.summarize'],static fn(int $microseconds):mixed=>null))->run();
$check($failureStats['dead']===1&&$products->memory($editMemory['memory_id'])['content']==='Manually corrected memory.'
    &&(int)$db->query("SELECT count(*) FROM memory_model_summaries WHERE memory_id='{$editMemory['memory_id']}'")->fetchColumn()===0,
    'invalid model output replaced the deterministic fallback');
$leaseSummary=$summaryRepository->enqueue($legacyInstallation,$longRows[0]['memory_id'],1);
$db->prepare('UPDATE durable_jobs SET max_attempts=1 WHERE job_id=:id')->execute(['id'=>$leaseSummary['job_id']]);
$leaseProvider=new class($db,$leaseSummary['job_id']) implements \ALMSIVIserver\Application\ProfileGenerationProvider {
    public function __construct(private PDO $db,private string $job){}
    public function generate(array $input,\ALMSIVIserver\Application\CancellationToken $cancellation):array{
        $cancellation->throwIfCancellationRequested();
        $this->db->prepare("UPDATE durable_jobs SET lease_expires_at=clock_timestamp()-interval '1 second' WHERE job_id=:id")->execute(['id'=>$this->job]);
        return ['summary'=>'Expired lease output'];
    }
};
$leaseRegistry=new JobHandlerRegistry([new \ALMSIVIserver\Application\MemorySummaryJobHandler($summaryRepository,$products,
    new ProviderAttemptRepository($db),[],$leaseProvider)]);
(new Worker($jobs,$leaseRegistry,'model-memory-lease-lost',5,1,1,0,10,['memory.summarize'],static fn(int $microseconds):mixed=>null))->run();
$check((int)$db->query("SELECT count(*) FROM memory_model_summaries WHERE memory_id='{$longRows[0]['memory_id']}'")->fetchColumn()===0,
    'expired model worker persisted a late summary');
(new Worker($jobs,$firstPartyRegistry,'model-memory-expired-cleanup',5,1,1,0,10,['memory.summarize'],static fn(int $microseconds):mixed=>null))->run();
$summaryPolicyContent['enabled']=false;
$service->revise('memory_policy',$summaryPolicy['configuration_id'],$summaryPolicyContent,'leave model fixture disabled');

$embeddingRepository=new \ALMSIVIserver\Infrastructure\MemoryEmbeddingRepository($db);
$embeddingPolicyContent=['schema'=>\ALMSIVIserver\Application\MemoryEmbeddingPolicy::SCHEMA,'enabled'=>true,
    'endpoint'=>'http://127.0.0.1:8085','timeout_ms'=>1500];
$embeddingPolicy=$service->createRevisioned('memory_embedding_policy',['installation_id'=>$legacyInstallation,
    'name'=>'Semantic memory retrieval','content'=>$embeddingPolicyContent]);
$check((int)$db->query("SELECT count(*) FROM durable_jobs WHERE job_type='memory.embed'")->fetchColumn()===0,
    'saving semantic memory policy queued historical provider work');
$embeddingJob=$embeddingRepository->enqueue($legacyInstallation,$summaryMemory['memory_id'],1);
$embeddingReplay=$embeddingRepository->enqueue($legacyInstallation,$summaryMemory['memory_id'],1);
$check(($embeddingJob['created']??false)===true&&($embeddingReplay['created']??true)===false
    &&$embeddingJob['job_id']===$embeddingReplay['job_id'],'semantic memory enqueue was not idempotent');
$embeddingProvider=new class implements \ALMSIVIserver\Application\EmbeddingProvider {
    public int $calls=0;
    public function embed(string $text,\ALMSIVIserver\Application\CancellationToken $cancellation):array{
        ++$this->calls;$cancellation->throwIfCancellationRequested();
        if($text==='')throw new RuntimeException('missing embedding input');
        return[1.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0];
    }
    public function model():string{return'test-minime-v1';}
};
$embeddingRegistry=new JobHandlerRegistry([new \ALMSIVIserver\Application\MemoryEmbedJobHandler($embeddingRepository,
    new ProviderAttemptRepository($db),$embeddingProvider)]);
$embeddingStats=(new Worker($jobs,$embeddingRegistry,'semantic-memory',5,1,1,0,10,['memory.embed'],
    static fn(int $microseconds):mixed=>null))->run();
$storedEmbedding=$db->query("SELECT dimensions,embedding,input_sha256,model FROM memory_embeddings WHERE memory_id='{$summaryMemory['memory_id']}'")->fetch();
$check($embeddingStats['succeeded']===1&&$embeddingProvider->calls===1&&(int)$storedEmbedding['dimensions']===8
    &&json_decode((string)$storedEmbedding['embedding'],true,16,JSON_THROW_ON_ERROR)===[1,0,0,0,0,0,0,0]
    &&$storedEmbedding['input_sha256']===hash('sha256',$summaryMemory['content'])
    &&$storedEmbedding['model']==='test-minime-v1'
    &&$embeddingRepository->enqueue($legacyInstallation,$summaryMemory['memory_id'],1)===null,
    'semantic memory worker did not persist one frozen revision projection');
$products->updateMemory($summaryMemory['memory_id'],'Semantic revision changed.',['semantic','revision'],
    \ALMSIVIserver\Application\DeterministicRetrieval::fakeVector('Semantic revision changed.'),$clock->iso());
$queuedRevision=(int)$db->query("SELECT count(*) FROM durable_jobs WHERE job_type='memory.embed' AND state='queued' "
    ."AND payload->>'memory_id'='{$summaryMemory['memory_id']}' AND payload->>'memory_revision'='2'")->fetchColumn();
$embeddingPolicyContent['enabled']=false;
$service->revise('memory_embedding_policy',$embeddingPolicy['configuration_id'],$embeddingPolicyContent,'disable semantic memory');
$attemptsBeforeDisabled=(int)$db->query("SELECT count(*) FROM provider_attempts WHERE operation='embed_memory'")->fetchColumn();
$disabledEmbeddingStats=(new Worker($jobs,$embeddingRegistry,'semantic-memory-disabled',5,1,1,0,10,['memory.embed'],
    static fn(int $microseconds):mixed=>null))->run();
$check($queuedRevision===1&&$disabledEmbeddingStats['succeeded']===1&&$embeddingProvider->calls===1
    &&(int)$db->query("SELECT count(*) FROM provider_attempts WHERE operation='embed_memory'")->fetchColumn()===$attemptsBeforeDisabled
    &&(int)$db->query("SELECT count(*) FROM memory_embeddings WHERE memory_id='{$summaryMemory['memory_id']}' AND memory_revision=2")->fetchColumn()===0,
    'disabling semantic memory did not cancel queued provider work or preserve the deterministic fallback');
$db->beginTransaction();$db->exec('SAVEPOINT semantic_downgrade');
try{$db->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/069_semantic_memory_embeddings.down.sql'));
    throw new RuntimeException('semantic memory downgrade discarded data');}
catch(PDOException $error){$check(str_contains($error->getMessage(),'Cannot remove semantic memory support'),'unexpected semantic downgrade failure');
    $db->exec('ROLLBACK TO SAVEPOINT semantic_downgrade');}
$check((int)$db->query('SELECT count(*) FROM memory_embeddings')->fetchColumn()===1,'guarded semantic downgrade changed projections');
$db->rollBack();

$failedSource=Uuid::v4();$failedDialogue=Uuid::v4();$failedMessage=Uuid::v4();$failedTurn=Uuid::v4();$failedRequest=Uuid::v4();
$db->prepare("INSERT INTO turns(turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,speaker,target,audience,context,state,accepted_at) VALUES(:turn,:request,:message,:session,1,'text','en','failed memory source','{}'::jsonb,'{}'::jsonb,'[]'::jsonb,'{}'::jsonb,'complete','2026-01-01T00:00:00Z')")
    ->execute(['turn'=>$failedTurn,'request'=>$failedRequest,'message'=>Uuid::v4(),'session'=>$legacySession]);
$db->prepare("INSERT INTO dialogue_utterances(dialogue_message_id,session_id,turn_id,request_id,generation,utterance_index,utterance_count,response_line_id,utterance_id,speaker,addressee,audience,text,emitted_at,delivery_deadline_at) VALUES(:dialogue,:session,:turn,:request,1,1,1,:line,:utterance,'{}'::jsonb,'{}'::jsonb,'[]'::jsonb,'Failed output','2026-01-01T00:00:00Z','2026-01-01T00:05:00Z')")
    ->execute(['dialogue'=>$failedDialogue,'session'=>$legacySession,'turn'=>$failedTurn,'request'=>$failedRequest,'line'=>$failedDialogue,'utterance'=>Uuid::v4()]);
$db->prepare("INSERT INTO source_events(source_event_id,installation_id,session_id,generation,event_kind,occurred_at,schema_name,request_id,turn_id,payload) VALUES(:source,:installation,:session,1,'dialogue.delivery','2026-01-01T00:00:17Z','almsivi.dialogue-delivery-result.v1',:request,:turn,'{}'::jsonb)")
    ->execute(['source'=>$failedSource,'installation'=>$legacyInstallation,'session'=>$legacySession,'request'=>$failedRequest,'turn'=>$failedTurn]);
$db->prepare("INSERT INTO dialogue_delivery_results(dialogue_message_id,source_event_id,message_id,request_id,turn_id,session_id,generation,speaker,status,reason_code,completed_at) VALUES(:dialogue,:source,:message,:request,:turn,:session,1,'{}'::jsonb,'failed','audio_failed','2026-01-01T00:00:17Z')")
    ->execute(['dialogue'=>$failedDialogue,'source'=>$failedSource,'message'=>$failedMessage,'request'=>$failedRequest,'turn'=>$failedTurn,'session'=>$legacySession]);
try{$derive->handle(['installation_id'=>$legacyInstallation,'profile_id'=>$legacyProfile,'playthrough_id'=>$legacyPlaythrough,
    'memory_id'=>Uuid::v4(),'tier'=>'recent','content'=>'Failed output must not persist.','source_event_id'=>$failedSource,
    'provenance'=>['source'=>'dialogue.delivery','status'=>'failed']],'memory.derive:failed',static fn():bool=>true);throw new RuntimeException('failed dialogue entered recent memory');}
catch(RuntimeException $error){$check($error->getMessage()==='memory_source_ineligible','unexpected failed-dialogue memory error');}
$check(!in_array($failedSource,$longProvenance['source_event_ids'],true),'failed dialogue entered consolidated provenance');
$badFirstParty=Uuid::v4();$jobs->enqueue($badFirstParty,'memory.derive',1,'memory.derive:retry',['memory_id'=>'bad'],2);
$retryWorker=new Worker($jobs,$firstPartyRegistry,'first-party-retry',5,1,1,1,10,['memory.derive']);$retryStats=$retryWorker->run();
$retryState=$db->query("SELECT state,attempt_count FROM durable_jobs WHERE job_id='{$badFirstParty}'")->fetch();
$check($retryStats['retried']===1 && $retryState['state']==='queued' && (int)$retryState['attempt_count']===1, 'first-party handler failure did not schedule retry');

$db->prepare("UPDATE sessions SET capabilities=ARRAY['action.inspect.report','action.inventory.inspect','action.ai.follow','action.ai.approach','action.ai.wait','action.ai.travel','action.ai.escort','action.ai.face','action.animation.play','action.item.use'],enabled_actions=ARRAY['inspect.report','inventory.inspect','ai.follow','ai.approach','ai.wait','ai.travel','ai.escort','ai.face','animation.play','item.use'] WHERE session_id=:id")->execute(['id'=>$legacySession]);
$catalog=new ActionCatalogRepository($db);$policy=new ActionPolicyValidator();$loaded=$catalog->loadForSession($legacySession,1);
$proposal=['name'=>'ai.follow','tier'=>1,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>['distance'=>192]];
$check($policy->validate($proposal,$loaded)['name']==='ai.follow', 'catalog-backed action validation failed');
$inspect=['name'=>'inspect.report','tier'=>0,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>[]];
$check($policy->validate($inspect,$loaded)['name']==='inspect.report','empty-object inspect action validation failed');
$inventoryInspect=['name'=>'inventory.inspect','tier'=>0,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>[]];
$check($policy->validate($inventoryInspect,$loaded)['name']==='inventory.inspect','inventory inspection validation failed');
$approach=['name'=>'ai.approach','tier'=>1,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>[]];
$check($policy->validate($approach,$loaded)['name']==='ai.approach','approach action validation failed');
$wait=['name'=>'ai.wait','tier'=>1,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>['duration_seconds'=>3600]];
$check($policy->validate($wait,$loaded)['parameters']['duration_seconds']===3600,'bounded wait action validation failed');
$animation=['name'=>'animation.play','tier'=>1,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>['group'=>'idle2']];
$check($policy->validate($animation,$loaded)['name']==='animation.play','animation action validation failed');
$itemUse=['name'=>'item.use','tier'=>2,'actor'=>['record_id'=>'npc'],'target'=>['record_id'=>'player'],'parameters'=>['record_id'=>'p_restore_health_s','content_file'=>'morrowind.esm']];
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
try{$policy->validate(array_replace($wait,['parameters'=>['duration_seconds'=>3599]]),$loaded);throw new RuntimeException('sub-hour wait accepted');}catch(DomainException $error){$check($error->getMessage()==='action_parameters_invalid','unexpected wait parameter error');}

$traceTurn='40000000-0000-4000-8000-000000000001';$continuationTurn='40000000-0000-4000-8000-000000000002';
foreach([[$traceTurn,'40000000-0000-4000-8000-000000000011','40000000-0000-4000-8000-000000000021'],[$continuationTurn,'40000000-0000-4000-8000-000000000012','40000000-0000-4000-8000-000000000022']] as [$turnId,$requestId,$messageId]){$db->prepare("INSERT INTO turns (turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,speaker,target,audience,context,state,accepted_at) VALUES (:turn,:request,:message,:session,1,'text','en','test','{}'::jsonb,'{}'::jsonb,'[]'::jsonb,'{}'::jsonb,'complete','2026-01-01T00:00:00Z')")->execute(['turn'=>$turnId,'request'=>$requestId,'message'=>$messageId,'session'=>$legacySession]);}
$traceInput=['installation_id'=>$legacyInstallation,'profile_id'=>$legacyProfile,'playthrough_id'=>$legacyPlaythrough,'session_id'=>$legacySession,'turn_id'=>$traceTurn,'request_id'=>'40000000-0000-4000-8000-000000000011'];
$traceMeta=['prompt_configuration_id'=>$legacyProfile,'prompt_revision'=>1,'algorithm'=>'deterministic-prompt-v1','input_sha256'=>hash('sha256','secret prompt body'),'input_bytes'=>18,'truncated'=>false,'sources'=>[['ordinal'=>0,'source_kind'=>'profile','source_id'=>$legacyProfile,'included'=>true,'reason'=>'included','source_sha256'=>hash('sha256','profile body'),'included_bytes'=>12]]];
$traceMeta['sources'][]=['ordinal'=>1,'source_kind'=>'memory','source_id'=>$derivedMemoryId,'included'=>false,
    'reason'=>'covered_by_history','source_sha256'=>hash('sha256','covered memory'),'included_bytes'=>0];
$traceMeta['sources'][]=['ordinal'=>2,'source_kind'=>'memory','source_id'=>$longRows[0]['memory_id'],'included'=>false,
    'reason'=>'covered_by_memory','source_sha256'=>hash('sha256','covered summary'),'included_bytes'=>0];
$traceMeta['sections']=[['section_order'=>7,'section_key'=>'memory_context','source_refs'=>[],
    'inclusion_reason'=>'covered_by_history','source_occurred_at'=>null,'source_characters'=>0,'estimated_tokens'=>0,
    'redacted_preview'=>'','source_sha256'=>hash('sha256','')]];
$traceMeta['memory_retrieval']=['query'=>'fixture','result_ids'=>[],'scores'=>[],'algorithm'=>'fixture',
    'created_at'=>$clock->iso(),'prompt_section'=>'memory_context',
    'reasons'=>['_context'=>['selection'=>'exact-rendered-coverage-v1','coverage'=>['selected'=>0]]]];
$traceId=$products->recordPromptTrace($traceInput,$traceMeta,$clock->iso());
$check((int)$db->query("SELECT count(*) FROM prompt_trace_sources WHERE prompt_trace_id='{$traceId}' AND reason IN('covered_by_history','covered_by_memory') AND included=false")->fetchColumn()===2
    &&$db->query("SELECT reasons->'_context'->>'selection' FROM retrieval_traces WHERE turn_id='{$traceTurn}' AND domain='memory'")->fetchColumn()==='exact-rendered-coverage-v1',
    'coverage source reasons or retrieval metadata were not persisted');
$db->beginTransaction();
$db->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/061_memory_prompt_coverage.down.sql'));
$check((int)$db->query("SELECT count(*) FROM prompt_trace_sources WHERE prompt_trace_id='{$traceId}' AND reason='section_limit'")->fetchColumn()===2
    &&$db->query("SELECT inclusion_reason FROM prompt_trace_sections WHERE prompt_trace_id='{$traceId}'")->fetchColumn()==='empty',
    'coverage migration rollback lost audit rows or left incompatible reasons');
$db->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/061_memory_prompt_coverage.up.sql'));
$db->rollBack();
$storedTrace=$db->query("SELECT input_sha256,input_bytes FROM prompt_traces WHERE prompt_trace_id='{$traceId}'")->fetch();
$check($storedTrace['input_sha256']===hash('sha256','secret prompt body') && (int)$storedTrace['input_bytes']===18, 'prompt trace metadata was not persisted');
$check((int)$db->query("SELECT count(*) FROM information_schema.columns WHERE table_name='prompt_traces' AND column_name IN ('prompt','content','payload')")->fetchColumn()===0, 'prompt trace schema can persist raw prompts');

$db->exec("UPDATE action_catalog SET continuation_capable=true WHERE action_name='ai.follow'");
$actionId='50000000-0000-4000-8000-000000000001';$sourceId='50000000-0000-4000-8000-000000000002';
$db->prepare("INSERT INTO action_intents (action_id,session_id,turn_id,request_id,generation,action_name,tier,actor,target,parameters,expires_at,state,emitted_at) VALUES (:action,:session,:turn,:request,1,'ai.follow',1,'{}'::jsonb,'{}'::jsonb,'{\"distance\":192}'::jsonb,'2026-01-01T00:10:00Z','terminal','2026-01-01T00:00:00Z')")->execute(['action'=>$actionId,'session'=>$legacySession,'turn'=>$traceTurn,'request'=>'40000000-0000-4000-8000-000000000011']);
$actionProjection=$db->prepare('SELECT issued.action FROM action_issued_metadata metadata JOIN public.actions_issued issued ON issued.rowid=metadata.rowid WHERE metadata.action_id=:action');
$actionProjection->execute(['action'=>$actionId]);
$check($actionProjection->fetchColumn()==='ai.follow','action did not project into the Herika action contract');
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
