<?php
declare(strict_types=1);

use ALMSIVIserver\Application\CancellationToken;
use ALMSIVIserver\Application\FirstPartyJobHandlerFactory;
use ALMSIVIserver\Application\MockProvider;
use ALMSIVIserver\Application\MockSpeechProvider;
use ALMSIVIserver\Application\MockSpeechToTextProvider;
use ALMSIVIserver\Application\Provider;
use ALMSIVIserver\Application\PromptAssembler;
use ALMSIVIserver\Application\Worker;
use ALMSIVIserver\Http\Request;
use ALMSIVIserver\Http\Router;
use ALMSIVIserver\Infrastructure\Connection;
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
$repo = new Repository($db);
(new MigrationRunner($db, dirname(__DIR__) . '/database/migrations'))->up();
$mediaPath = sys_get_temp_dir() . '/almsivi-media-' . bin2hex(random_bytes(8));
$mediaStore = new MediaStore($mediaPath, 33_554_432, 67_108_864);
$token = PairingToken::generate();
$tokenHash = PairingToken::hash($token);$macKey=hex2bin($tokenHash);$installationId='00000000-0000-4000-8000-000000000001';
$attempts = new ProviderAttemptRepository($db);
$products = new ProductRepository($db);
$router = new Router($repo, new Validator(), new MockProvider(), $tokenHash, rateLimitRequests: 1000,
    mediaStore: $mediaStore, speechProvider: new MockSpeechProvider(), providerAttempts: $attempts,
    products:$products,promptAssembler:new PromptAssembler(),sttProvider: new MockSpeechToTextProvider());
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
$runWorker = function (array $types, ?Provider $provider = null) use ($db,$mediaStore): array {
    return (new Worker(new JobRepository($db), FirstPartyJobHandlerFactory::registry($db,$mediaStore,
        provider:$provider,speechProvider:$provider === null ? null : new MockSpeechProvider(),providerTimeoutMs:1000,
        sttProvider:new MockSpeechToTextProvider()), 'integration-worker',5,10,100,0,10,$types,
        static fn(int $microseconds):mixed=>null))->run();
};
$runTurnWorker = fn(Provider $provider): array => $runWorker(['turn.process'], $provider);

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
    && $accepted['capabilities'] === ['dialogue.text', 'speech.say', 'speech.listen', 'action.inspect.report', 'action.ai.follow',
        'action.ai.stop', 'action.ai.wander', 'action.combat.start', 'action.combat.stop',
        'action.animation.play', 'action.item.equip', 'action.item.unequip', 'action.item.use'], 'session create failed');
$sessionId = $accepted['session_id'];
[$status, $duplicateSession] = $call($router, 'POST', $base . '/sessions', $headers($session['message_id']), [], $session);
$assert($status === 201 && $duplicateSession == $accepted, 'session duplicate failed');
$conflict = $session; $conflict['profile_id'] = $newUuid(4);
[$status, $body] = $call($router, 'POST', $base . '/sessions', $headers($session['message_id']), [], $conflict);
$assert($status === 409 && $body['code'] === 'duplicate_conflict', 'session duplicate conflict failed');
$staleSession = $session; $staleSession['message_id'] = $newUuid(5); $staleSession['generation'] = 6;
[$status, $body] = $call($router, 'POST', $base . '/sessions', $headers($staleSession['message_id']), [], $staleSession);
$assert($status === 409 && $body['code'] === 'stale_generation', 'non-monotonic generation accepted');

$turn = $fixture('turn');
$turn['session_id'] = $sessionId;
$turn['payload']['input']['text'] = 'Please follow me.';
// Turn-advertised capabilities cannot add a capability that was not negotiated; stored session policy is authoritative.
$turn['runtime']['capabilities'] = ['dialogue.text'];
[$status] = $call($router, 'POST', $base . '/turns', $jsonAuth, [], $turn);
$assert($status === 422, 'missing turn idempotency accepted');
[$status, $turnAccepted] = $call($router, 'POST', $base . '/turns', $headers($turn['message_id']), [], $turn);
$assert($status === 202 && $turnAccepted['event_cursor'] === 1, 'turn acceptance failed');
$successfulWorkerStats=$runTurnWorker(new MockProvider());
$assert($successfulWorkerStats === ['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0], 'successful turn job was not acknowledged');
[$status, $turnDuplicate] = $call($router, 'POST', $base . '/turns', $headers($turn['message_id']), [], $turn);
$assert($status === 202 && $turnDuplicate == $turnAccepted, 'turn duplicate failed');
[$status] = $call($router, 'GET', $base . '/events', [], [
    'session_id' => $sessionId, 'generation' => '7', 'after' => '0', 'wait_ms' => '15001']);
$assert($status === 422, 'oversized event wait accepted');
[$status, $events] = $call($router, 'GET', $base . '/events', [], [
    'session_id' => $sessionId, 'generation' => '7', 'after' => '0', 'wait_ms' => '15000']);
$assert($status === 200 && array_column($events['events'], 'type') === ['turn.accepted', 'dialogue.complete', 'speech.ready', 'action.intent', 'turn.complete'], 'event order failed');
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
$assert(is_array($speech) && $speech['bytes'] === 204 && $speech['codec'] === 'wav', 'mock speech descriptor missing');
$dialogueEvents=array_values(array_filter($events['events'],static fn(array $e):bool=>$e['type']==='dialogue.complete'));$dialogueEvent=$dialogueEvents[0];
$delivery=$fixture('dialogue-delivery-result');$delivery['message_id']=$newUuid(23);$delivery['request_id']=$turn['request_id'];
$delivery['dialogue_message_id']=$dialogueEvent['message_id'];$delivery['turn_id']=$turn['turn_id'];$delivery['session_id']=$sessionId;
$delivery['speaker']=$dialogueEvent['payload']['speaker'];$delivery['completed_at']=gmdate('Y-m-d\TH:i:s\Z');
[$status,$deliveryAccepted]=$call($router,'POST',$base.'/dialogue-delivery-results',$headers($delivery['message_id']),[],$delivery);
$assert($status===200&&!$deliveryAccepted['duplicate'],'dialogue delivery result failed');
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

// Exercise the lower group bounds through the same durable provider/TTS pipeline.
$groupAfter=(int)$failedEvents['next_after'];
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
    $assert($status===200&&count($boundedDialogues)===$groupCount&&count($boundedSpeech)===$groupCount,'2-3 utterance group coverage failed');
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
$assert($status===200&&$groupTypes===['turn.accepted','dialogue.complete','speech.ready','dialogue.complete','speech.ready',
    'dialogue.complete','speech.ready','dialogue.complete','speech.ready','turn.complete'],'four-utterance group event order failed');
$groupDialogues=array_values(array_filter($groupEvents['events'],static fn(array $e):bool=>$e['type']==='dialogue.complete'));
$groupSpeech=array_values(array_filter($groupEvents['events'],static fn(array $e):bool=>$e['type']==='speech.ready'));
$assert(count($groupDialogues)===4&&count($groupSpeech)===4&&count(array_unique(array_column(array_column($groupSpeech,'payload'),'media_id')))===4,
    'group turn did not produce one distinct speech object per utterance');
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

// STT is deliberately accepted and completed before a turn row with its turn_id exists.
$sttAudio=(new MockSpeechProvider())->synthesize('pre-turn stt',new \ALMSIVIserver\Application\NeverCancelledToken())['bytes'];
$sttMessage=$newUuid(90);$sttRequest=$newUuid(91);$sttTurn=$newUuid(92);$sttCreated=gmdate('Y-m-d\TH:i:s\Z');
$sttHeaders=['Content-Type'=>'application/octet-stream','Idempotency-Key'=>$sttMessage,
    'X-ALMSIVI-Schema'=>'almsivi.stt.request.v1','X-ALMSIVI-Message-Id'=>$sttMessage,'X-ALMSIVI-Request-Id'=>$sttRequest,
    'X-ALMSIVI-Turn-Id'=>$sttTurn,'X-ALMSIVI-Session-Id'=>$sessionId,'X-ALMSIVI-Generation'=>'7','X-ALMSIVI-Created-At'=>$sttCreated,
    'X-ALMSIVI-Codec'=>'wav','X-ALMSIVI-Language'=>'en-US','X-ALMSIVI-Audio-Bytes'=>(string)strlen($sttAudio),
    'X-ALMSIVI-Sha256'=>hash('sha256',$sttAudio)];
[$status,$sttAccepted]=$call($router,'POST',$base.'/stt',$sttHeaders,[],$sttAudio);
$assert($status===202&&!$sttAccepted['duplicate']&&(int)$db->query('SELECT count(*) FROM turns WHERE turn_id='.$db->quote($sttTurn))->fetchColumn()===0,
    'direct pre-turn STT acceptance failed');
$sttStats=$runWorker(['stt.process']);
[$status,$sttEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$combatEvents['next_after']]);
$assert($sttStats===['claimed'=>1,'succeeded'=>1,'retried'=>0,'dead'=>0]&&$status===200&&count($sttEvents['events'])===1
    &&$sttEvents['events'][0]['type']==='stt.transcript'&&$sttEvents['events'][0]['turn_id']===$sttTurn,
    'direct pre-turn STT did not complete through the worker');

$autonomy=$products->scheduleAutonomy(['installation_id'=>$installationId,'profile_id'=>$session['profile_id'],
    'playthrough_id'=>$session['playthrough_id'],'kind'=>'rechat','enabled'=>true,'interval_seconds'=>30,
    'cooldown_seconds'=>30,'current_session_id'=>$sessionId,'confirmed_at'=>gmdate('Y-m-d\TH:i:s\Z')],gmdate('Y-m-d\TH:i:s\Z'));
[$status,$autonomyEvents]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$sttEvents['next_after']]);
$assert($status===200&&count($autonomyEvents['autonomy'])===1
    &&$autonomyEvents['autonomy'][0]['schedule_id']===$autonomy['schedule_id']
    &&$autonomyEvents['autonomy'][0]['kind']==='rechat','due autonomy was not delivered to the active session');
[$status,$autonomyReplay]=$call($router,'GET',$base.'/events',[],[
    'session_id'=>$sessionId,'generation'=>'7','after'=>(string)$autonomyEvents['next_after']]);
$assert($status===200&&$autonomyReplay['autonomy']===[],'autonomy directive replayed inside its cooldown');

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
if (is_dir($mediaPath)) {
    foreach (glob($mediaPath . '/*') ?: [] as $file) unlink($file);
    rmdir($mediaPath);
}

fwrite(STDOUT, "integration vertical slice passed\n");
