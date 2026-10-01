<?php
declare(strict_types=1);

// Player2 usage heartbeat against PostgreSQL: real Player2 dialogue attempts gate fake-clock, fake-transport and loopback pings.
use LorkhanServer\Application\ActionPolicyValidator;
use LorkhanServer\Application\CallbackCancellationToken;
use LorkhanServer\Application\MockProvider;
use LorkhanServer\Application\TurnProcessJobHandler;
use LorkhanServer\Http\Request;
use LorkhanServer\Http\Router;
use LorkhanServer\Infrastructure\ActionCatalogRepository;
use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\Logger;
use LorkhanServer\Infrastructure\MigrationRunner;
use LorkhanServer\Infrastructure\Player2HealthHeartbeat;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\Repository;
use LorkhanServer\Infrastructure\Uuid;
use LorkhanServer\Protocol\Validator;
use LorkhanServer\Security\PairingToken;
use LorkhanServer\Security\RequestMac;

require dirname(__DIR__) . '/lib/Autoload.php';

$dsn = getenv('LORKHAN_TEST_DSN') ?: '';
if ($dsn === '') { fwrite(STDERR, "LORKHAN_TEST_DSN is required\n"); exit(2); }
$connect = static fn(): PDO => Connection::open(['database_dsn' => $dsn, 'database_user' => getenv('LORKHAN_TEST_DB_USER') ?: '',
    'database_password' => getenv('LORKHAN_TEST_DB_PASSWORD') ?: '']);
$db = $connect();
$assert = function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
(new MigrationRunner($db, dirname(__DIR__) . '/data/migrations'))->up();
$repo = new Repository($db, 256, new ActionCatalogRepository($db), new ActionPolicyValidator());
$products = new ProductRepository($db);
$attempts = new ProviderAttemptRepository($db);
$installationId = '00000000-0000-4000-8000-000000000001';
$tokenHash = PairingToken::hash(PairingToken::generate());
$macKey = hex2bin($tokenHash);
$repo->ensureInstallation($installationId, $tokenHash, $macKey);
$router = new Router($repo, new Validator(), new MockProvider(), $tokenHash, rateLimitRequests: 1000, providerAttempts: $attempts);
$base = '/LorkhanServer/api/v1';
$call = function (string $path, array $body) use ($router, $macKey, $installationId): array {
    $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Idempotency-Key' => (string) $body['message_id']];
    $request = new Request('POST', $path, $headers, [], $encoded);
    $timestamp = gmdate('Y-m-d\TH:i:s\Z'); $nonce = bin2hex(random_bytes(16)); $digest = hash('sha256', $encoded);
    $headers += ['X-LORKHAN-Auth' => RequestMac::ALGORITHM, 'X-LORKHAN-Installation-Id' => $installationId,
        'X-LORKHAN-Timestamp' => $timestamp, 'X-LORKHAN-Nonce' => $nonce, 'X-LORKHAN-Content-SHA256' => $digest,
        'X-LORKHAN-Signature' => RequestMac::sign($macKey, $request, $installationId, $timestamp, $nonce, $headers['Content-Type'], $digest)];
    $response = $router->dispatch(new Request('POST', $path, $headers, [], $encoded));
    return [$response->status, json_decode($response->body, true, 64, JSON_THROW_ON_ERROR)];
};
$fixture = static fn(string $name): array => json_decode((string) file_get_contents(dirname(__DIR__) . '/protocol/fixtures/v1/valid/' . $name),
    true, 64, JSON_THROW_ON_ERROR)['instance'];
$session = $fixture('session-init.json');
unset($session['character_id'], $session['character_binding']);
[$status, $accepted] = $call($base . '/sessions', $session);
$assert($status === 201, 'heartbeat fixture session failed: ' . json_encode($accepted));
$sessionId = (string) $accepted['session_id']; $generation = (int) $accepted['generation'];
$turn = $fixture('turn.json');
$turn = array_replace($turn, ['session_id' => $sessionId, 'generation' => $generation, 'message_id' => Uuid::v4(), 'request_id' => Uuid::v4(),
    'turn_id' => Uuid::v4(), 'created_at' => gmdate('Y-m-d\TH:i:s\Z')]);
[$status, $turnAccepted] = $call($base . '/turns', $turn);
$assert($status === 202, 'heartbeat fixture turn failed: ' . json_encode($turnAccepted));

// Loopback Player2 stand-in: chat completions plus /v1/health, recording only the presented game key.
$work = sys_get_temp_dir() . '/lorkhan-player2-health-' . bin2hex(random_bytes(6));
mkdir($work, 0700); mkdir($work . '/logs', 0700);
foreach (Logger::FILES as $file) touch($work . '/logs/' . $file);
putenv('LORKHAN_LOG_DIR=' . $work . '/logs');
file_put_contents($work . '/router.php', '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);'
    . '$mode=static fn(string $f):string=>trim((string)@file_get_contents(__DIR__."/".$f));'
    . 'if($path==="/v1/health"){file_put_contents(__DIR__."/health.log",($_SERVER["REQUEST_METHOD"]??"")." "'
    . '.($_SERVER["HTTP_PLAYER2_GAME_KEY"]??"-")." ".(isset($_SERVER["HTTP_AUTHORIZATION"])?"auth":"noauth")."\n",FILE_APPEND);'
    . 'if($mode("health")==="slow")sleep(8);if($mode("health")==="503"){http_response_code(503);exit;}'
    . 'header("Content-Type: application/json");echo "{\"client_version\":\"fixture\"}";exit;}'
    . 'if($path==="/v1/chat/completions"){if($mode("chat")==="503"){http_response_code(503);exit;}'
    . 'header("Content-Type: application/json");echo json_encode(["choices"=>[["message"=>["content"=>'
    . '"{\"utterances\":[{\"text\":\"Player2 answered.\"}],\"action\":null}"]]]]);exit;}http_response_code(404);');
$socket = stream_socket_server('tcp://127.0.0.1:0'); $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $work . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes, null, ['PHP_CLI_SERVER_WORKERS' => '2'] + getenv());
for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; ++$i) usleep(20_000);
$mode = static function (string $file, string $value) use ($work): void { file_put_contents($work . '/' . $file, $value); };
$healthLog = static fn(): array => array_values(array_filter(explode("\n", (string) @file_get_contents($work . '/health.log'))));

try {
    $now = gmdate('c');
    $connector = static function (string $service, string $credential = 'none') use ($products, $installationId, $port, $now): array {
        return $products->createRevisioned('provider', ['installation_id' => $installationId, 'name' => 'Heartbeat ' . $service . ' ' . bin2hex(random_bytes(3)),
            'content' => ['driver' => 'openai-compatible', 'service' => $service, 'endpoint' => 'http://127.0.0.1:' . $port . '/v1/chat/completions',
                'model' => $service === 'player2' ? '' : 'fixture', 'credential' => $credential, 'timeout_ms' => 5000, 'options' => ['stream' => false]]], $now);
    };
    $slot = static fn(array $row): array => ['configuration_id' => $row['configuration_id'], 'revision' => (int) $row['current_revision'],
        'name' => $row['name'], 'content' => $row['content']];
    $player2 = $connector('player2');
    $handler = new TurnProcessJobHandler($repo, new MockProvider(), null, $attempts, 20_000);
    $complete = new ReflectionMethod($handler, 'completeWithFallback');
    // A real connector call through the turn handler writes the same complete_turn attempt production dialogue writes.
    $jobAttempt = 0;
    $dialogue = function (array $route) use ($db, $complete, $handler, $turn, &$jobAttempt): ?string {
        $jobId = Uuid::v4(); ++$jobAttempt;
        $db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,state,completed_at) VALUES(:job,'turn.process',1,:key,'{}','succeeded',clock_timestamp())")
            ->execute(['job' => $jobId, 'key' => 'player2-health-' . $jobId]);
        $message = ['request_id' => $turn['request_id'], 'turn_id' => $turn['turn_id'], '_negotiated_capabilities' => [],
            'payload' => ['input' => ['text' => 'Hello.'], 'target' => $turn['payload']['target'], 'speaker' => $turn['payload']['target'], 'audience' => []],
            '_provider_configuration' => $route];
        try {
            return $complete->invoke($handler, $message, ['job_id' => $jobId, 'attempt' => $jobAttempt], new CallbackCancellationToken(static fn(): bool => false),
                static function (): void {}, static fn(): bool => false)['utterances'][0]['text'] ?? null;
        } catch (Throwable) { return null; }
    };
    $t0 = new DateTimeImmutable((string) $db->query('SELECT clock_timestamp()')->fetchColumn());
    $at = static fn(int $seconds): DateTimeImmutable => $t0->modify('+' . $seconds . ' seconds');
    // Fake clock: move the latest Player2 use, game activity and turn to one instant, then tick at a later instant.
    $playedAt = function (int $useSeconds, ?int $activitySeconds = null) use ($db, $at, $sessionId, $turn): void {
        $db->prepare("UPDATE provider_attempts SET started_at=CAST(:t AS timestamptz)-interval '1 second',finished_at=CAST(:t AS timestamptz) WHERE turn_id=:turn")
            ->execute(['t' => $at($useSeconds)->format('c'), 'turn' => $turn['turn_id']]);
        $db->prepare('UPDATE source_events SET received_at=CAST(:t AS timestamptz) WHERE session_id=:session')
            ->execute(['t' => $at($activitySeconds ?? $useSeconds)->format('c'), 'session' => $sessionId]);
        $db->prepare('UPDATE turns SET accepted_at=CAST(:t AS timestamptz) WHERE turn_id=:turn')->execute(['t' => $at($useSeconds)->format('c'), 'turn' => $turn['turn_id']]);
    };
    $sent = []; $status = 200;
    $observer = $connect();
    $fake = function (string $url, array $options) use (&$sent, &$status, $db, $observer, $installationId): int {
        // No transaction or heartbeat row lock spans the network call.
        $lock = $observer->prepare('SELECT 1 FROM player2_health_heartbeats WHERE installation_id=:installation FOR UPDATE NOWAIT');
        $lock->execute(['installation' => $installationId]);
        $sent[] = ['url' => $url, 'options' => $options, 'in_transaction' => $db->inTransaction(), 'row_visible' => $lock->fetchColumn() !== false];
        if ($status === -1) throw new RuntimeException('transport exploded');
        return $status;
    };
    $tick = static fn(int $seconds, array $config = [], ?callable $transport = null): int =>
        (new Player2HealthHeartbeat($db, $config, $transport ?? $fake, static fn(): DateTimeImmutable => $at($seconds)))->tick();
    $row = static fn(): array|false => $db->query("SELECT session_id,generation,configuration_revision,http_status,succeeded_at IS NOT NULL AS succeeded FROM player2_health_heartbeats WHERE installation_id='" . $installationId . "'")->fetch();
    $attemptCount = static fn(): int => (int) $db->query('SELECT count(*) FROM provider_attempts')->fetchColumn();

    // Before: no Player2 use, a non-Player2 connector and a failed Player2 call never ping.
    $assert($tick(1) === 0 && $sent === [], 'heartbeat pinged without any Player2 use');
    $assert($dialogue($slot($connector('custom'))) === 'Player2 answered.', 'non-Player2 fixture call failed');
    $assert($tick(2) === 0 && $sent === [], 'a non-Player2 connector call started the Player2 heartbeat');
    $mode('chat', '503');
    $assert($dialogue($slot($player2)) === null && $db->query("SELECT count(*) FROM provider_attempts WHERE state='failed' AND metadata->>'configuration_id'="
        . $db->quote($player2['configuration_id']))->fetchColumn() === 1, 'failing Player2 fixture call did not record one failed attempt');
    $assert($tick(3) === 0 && $sent === [], 'a failed Player2 call started the heartbeat');
    $db->exec("DELETE FROM provider_attempts WHERE provider_kind='llm'");
    fwrite(STDOUT, "no Player2 use, other connectors and failed calls send no heartbeat\n");

    // After a succeeded Player2 dialogue call: one validated, bounded ping per minute, persisted across worker restarts.
    $mode('chat', 'ok');
    $assert($dialogue($slot($player2)) === 'Player2 answered.', 'Player2 fixture call failed');
    $playedAt(0);
    $attemptsBefore = $attemptCount();
    $assert($tick(10) === 1 && count($sent) === 1, 'heartbeat did not follow a real Player2 call: ' . json_encode($sent));
    $options = $sent[0]['options'];
    $assert($sent[0]['url'] === 'http://127.0.0.1:' . $port . '/v1/health' && $options[CURLOPT_CONNECTTIMEOUT_MS] === 2000
        && $options[CURLOPT_TIMEOUT_MS] === 5000 && $options[CURLOPT_FOLLOWLOCATION] === false && ($options[CURLOPT_PROXY] ?? null) === ''
        && in_array('player2-game-key: LORKHAN', $options[CURLOPT_HTTPHEADER], true)
        && preg_grep('/^Authorization:/i', $options[CURLOPT_HTTPHEADER]) === [] && !$sent[0]['in_transaction'] && $sent[0]['row_visible'],
        'heartbeat request was not the bounded, connector-derived Player2 health call: ' . json_encode($sent[0]));
    $assert($row() == ['session_id' => $sessionId, 'generation' => $generation, 'configuration_revision' => 1, 'http_status' => 200, 'succeeded' => true]
        && $attemptCount() === $attemptsBefore, 'heartbeat state was not recorded apart from provider attempts: ' . json_encode($row()));
    $assert($tick(40) === 0 && (new Player2HealthHeartbeat($db, [], $fake, static fn(): DateTimeImmutable => $at(69)))->tick() === 0
        && count($sent) === 1, 'heartbeat repeated within its one-minute interval or after a worker restart');
    $assert($tick(71) === 1 && count($sent) === 2, 'heartbeat did not repeat after one minute of continued play');
    fwrite(STDOUT, "real Player2 use sends one bounded connector-derived heartbeat per minute\n");

    // Idle or stale: game activity older than 180 s or Player2 use older than 300 s stops pinging.
    $playedAt(0, 0);
    $assert($tick(185) === 0 && count($sent) === 2, 'heartbeat continued after game activity went idle');
    $playedAt(0, 190);
    $assert($tick(200) === 1 && count($sent) === 3, 'fresh game activity within the use window did not resume the heartbeat');
    $playedAt(0, 300);
    $assert($tick(305) === 0 && count($sent) === 3, 'heartbeat continued five minutes after the last Player2 use');
    fwrite(STDOUT, "idle game activity and stale Player2 use stop the heartbeat\n");

    // Session, generation and connector revision must still be the ones that made the Player2 call.
    $playedAt(400);
    $db->prepare("UPDATE sessions SET state='ended',ended_at=clock_timestamp() WHERE session_id=:session")->execute(['session' => $sessionId]);
    $assert($tick(410) === 0, 'heartbeat pinged for an ended session');
    // A reload's new generation is still active play, but its Player2 use belongs to the previous generation.
    $db->prepare("UPDATE sessions SET state='active',ended_at=NULL,generation=generation+1 WHERE session_id=:session")->execute(['session' => $sessionId]);
    $db->prepare('UPDATE source_events SET generation=generation+1 WHERE session_id=:session')->execute(['session' => $sessionId]);
    $assert($tick(411) === 0, 'heartbeat pinged for a stale generation');
    $db->prepare('UPDATE sessions SET generation=generation-1 WHERE session_id=:session')->execute(['session' => $sessionId]);
    $db->prepare('UPDATE source_events SET generation=generation-1 WHERE session_id=:session')->execute(['session' => $sessionId]);
    $assert($tick(412, ['player2_health_heartbeat' => false]) === 0 && count($sent) === 3, 'disabled heartbeat still pinged');
    $products->revise('provider', $player2['configuration_id'], array_replace($player2['content'], ['credential' => 'default']), 'secret game key', $now);
    $assert($tick(413) === 0 && count($sent) === 3, 'heartbeat used a connector revision that never made the Player2 call');
    fwrite(STDOUT, "ended sessions, stale generations, disabled config and revised connectors send no heartbeat\n");

    // Failures: a server-held game key is sent but never logged; errors stay outside provider attempts and recovery.
    $secret = 'p2-heartbeat-secret-' . bin2hex(random_bytes(4));
    putenv('LORKHAN_LLM_API_KEY=' . $secret);
    $db->exec("DELETE FROM provider_attempts WHERE provider_kind='llm'");
    $assert($dialogue($slot($products->getRevisioned('provider', $player2['configuration_id']))) === 'Player2 answered.', 'revised Player2 call failed');
    $playedAt(500);
    $attemptsBefore = $attemptCount(); $status = 503;
    $assert($tick(510) === 0 && count($sent) === 4 && in_array('player2-game-key: ' . $secret, $sent[3]['options'][CURLOPT_HTTPHEADER], true)
        && ($row()['http_status'] ?? null) === 503, 'failed heartbeat was not attempted once with the server-held key: ' . json_encode($row()));
    $status = -1;
    $assert($tick(575) === 0 && count($sent) === 5 && ($row()['http_status'] ?? null) === 0 && $tick(580) === 0 && count($sent) === 5,
        'transport exception escaped, retried early or was not recorded');
    $recovery = $db->query("SELECT count(*) FROM provider_attempts WHERE provider_kind<>'llm' OR operation<>'complete_turn' OR state<>'succeeded'")->fetchColumn();
    $logs = implode('', array_map(static fn(string $file): string => (string) file_get_contents($work . '/logs/' . $file), Logger::FILES));
    $assert($attemptCount() === $attemptsBefore && (int) $recovery === 0 && str_contains($logs, 'Player2 health heartbeat failed')
        && !str_contains($logs, $secret), 'heartbeat failure touched provider attempts or logged the game key');
    putenv('LORKHAN_LLM_API_KEY');
    $playedAt(690);
    $db->prepare('UPDATE configuration_sets SET deleted_at=clock_timestamp() WHERE configuration_id=:id')->execute(['id' => $player2['configuration_id']]);
    $assert($tick(700) === 0 && count($sent) === 5, 'heartbeat used a deleted connector');
    $db->prepare('UPDATE configuration_sets SET deleted_at=NULL WHERE configuration_id=:id')->execute(['id' => $player2['configuration_id']]);
    fwrite(STDOUT, "heartbeat failures are bounded, unlogged secrets and outside provider recovery\n");

    // Real cURL against the loopback stand-in: GET with the game key, and a stalled health check ends within 5 s.
    putenv('LORKHAN_LLM_API_KEY=loopback-game-key');
    $real = static fn(int $seconds): int => (new Player2HealthHeartbeat($db, [], null, static fn(): DateTimeImmutable => $at($seconds)))->tick();
    $playedAt(800);
    $assert($real(801) === 1 && $healthLog() === ['GET loopback-game-key noauth'], 'loopback heartbeat failed: ' . json_encode($healthLog()));
    $mode('health', 'slow'); $started = hrtime(true);
    $assert($real(862) === 0 && ($row()['http_status'] ?? null) === 0, 'stalled loopback heartbeat was not recorded as failed');
    $elapsed = (hrtime(true) - $started) / 1e9;
    $assert($elapsed >= 4.5 && $elapsed < 6.5 && count($healthLog()) === 2, 'stalled heartbeat exceeded its 5 s bound: ' . $elapsed);
    $mode('health', '503');
    $assert($real(923) === 0 && ($row()['http_status'] ?? null) === 503 && count($healthLog()) === 3, 'loopback 503 heartbeat was not recorded');
    putenv('LORKHAN_LLM_API_KEY');
    fwrite(STDOUT, "loopback cURL heartbeat sends GET with the game key and bounds a stalled server\n");

    // Day-old heartbeat timing is removed by operational retention.
    $db->exec("UPDATE player2_health_heartbeats SET attempted_at=clock_timestamp()-interval '2 days'");
    (new \LorkhanServer\Infrastructure\FirstPartyJobRepository($db))->retainOperational(30, gmdate('c'), 100);
    $assert($row() === false, 'operational retention kept stale heartbeat timing');
    fwrite(STDOUT, "operational retention removes stale heartbeat timing\n");
} finally {
    putenv('LORKHAN_LLM_API_KEY'); putenv('LORKHAN_LOG_DIR');
    $pid = (int) proc_get_status($server)['pid']; exec('pkill -TERM -P ' . $pid . ' 2>/dev/null');
    proc_terminate($server); proc_close($server);
    foreach (glob($work . '/logs/*') ?: [] as $file) unlink($file);
    rmdir($work . '/logs');
    array_map('unlink', glob($work . '/*') ?: []); rmdir($work);
}
fwrite(STDOUT, "player2 health heartbeat tests passed\n");
