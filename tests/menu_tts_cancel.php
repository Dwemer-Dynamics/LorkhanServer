<?php
declare(strict_types=1);

// Menu/book speech cancellation against PostgreSQL, the real cURL speech adapter and a slow loopback TTS mock.
use LorkhanServer\Application\ActionPolicyValidator;
use LorkhanServer\Application\MockProvider;
use LorkhanServer\Application\MockSpeechProvider;
use LorkhanServer\Application\OpenAiCompatibleSpeechProvider;
use LorkhanServer\Application\SpeechProvider;
use LorkhanServer\Http\Request;
use LorkhanServer\Http\Router;
use LorkhanServer\Infrastructure\ActionCatalogRepository;
use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\FirstPartyJobRepository;
use LorkhanServer\Infrastructure\MediaStore;
use LorkhanServer\Infrastructure\MigrationRunner;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\Repository;
use LorkhanServer\Infrastructure\Uuid;
use LorkhanServer\Protocol\Validator;
use LorkhanServer\Security\PairingToken;
use LorkhanServer\Security\RequestMac;

require dirname(__DIR__) . '/lib/Autoload.php';

$dsn = getenv('LORKHAN_TEST_DSN') ?: '';
if ($dsn === '') { fwrite(STDERR, "LORKHAN_TEST_DSN is required\n"); exit(2); }
$child = ($argv[1] ?? '') === '--speak';
$db = Connection::open(['database_dsn' => $dsn, 'database_user' => getenv('LORKHAN_TEST_DB_USER') ?: '',
    'database_password' => getenv('LORKHAN_TEST_DB_PASSWORD') ?: '']);
$repo = new Repository($db, 256, new ActionCatalogRepository($db), new ActionPolicyValidator());
$installationId = '00000000-0000-4000-8000-000000000001';
$tokenHash = $child ? (string) getenv('LORKHAN_CANCEL_TOKEN_HASH') : PairingToken::hash(PairingToken::generate());
$macKey = hex2bin($tokenHash);
$mediaPath = $child ? (string) getenv('LORKHAN_CANCEL_MEDIA') : sys_get_temp_dir() . '/lorkhan-cancel-media-' . bin2hex(random_bytes(8));
$mediaStore = new MediaStore($mediaPath, 33_554_432, 67_108_864);
$attempts = new ProviderAttemptRepository($db);
$router = fn(SpeechProvider $speech): Router => new Router($repo, new Validator(), new MockProvider(), $tokenHash,
    rateLimitRequests: 1000, mediaStore: $mediaStore, speechProvider: $speech, providerAttempts: $attempts);
$base = '/LorkhanServer/api/v1';
$call = function (Router $target, string $path, array $body, ?string $principal = null, bool $sign = true) use ($macKey, $installationId): array {
    $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Idempotency-Key' => (string) $body['message_id']];
    if ($sign) {
        $principal ??= $installationId;
        $request = new Request('POST', $path, $headers, [], $encoded);
        $timestamp = gmdate('Y-m-d\TH:i:s\Z'); $nonce = bin2hex(random_bytes(16)); $digest = hash('sha256', $encoded);
        $headers += ['X-LORKHAN-Auth' => RequestMac::ALGORITHM, 'X-LORKHAN-Installation-Id' => $principal,
            'X-LORKHAN-Timestamp' => $timestamp, 'X-LORKHAN-Nonce' => $nonce, 'X-LORKHAN-Content-SHA256' => $digest,
            'X-LORKHAN-Signature' => RequestMac::sign($macKey, $request, $principal, $timestamp, $nonce, $headers['Content-Type'], $digest)];
    }
    $response = $target->dispatch(new Request('POST', $path, $headers, [], $encoded));
    return [$response->status, json_decode($response->body, true, 64, JSON_THROW_ON_ERROR)];
};

if ($child) {
    // One speech request blocked inside cURL; the parent cancels it from a separate PHP process and connection.
    $speech = new OpenAiCompatibleSpeechProvider((string) getenv('LORKHAN_CANCEL_ENDPOINT'), ['127.0.0.1'], 'slow-mock', 'fixture-voice',
        timeoutMs: 20_000, allowLoopbackHttp: true);
    $message = json_decode((string) getenv('LORKHAN_CANCEL_MESSAGE'), true, 64, JSON_THROW_ON_ERROR);
    $path = $base . (($message['schema'] ?? '') === 'lorkhan.book.read-aloud.v1' ? '/book/read-aloud' : '/menu-dialogue-tts');
    [$status, $body] = $call($router($speech), $path, $message);
    fwrite(STDOUT, json_encode(['status' => $status, 'body' => $body, 'finished_at' => microtime(true)], JSON_THROW_ON_ERROR));
    exit(0);
}

$assert = function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
(new MigrationRunner($db, dirname(__DIR__) . '/data/migrations'))->up();
$repo->ensureInstallation($installationId, $tokenHash, $macKey);
$mock = $router(new MockSpeechProvider());
$session = json_decode((string) file_get_contents(dirname(__DIR__) . '/protocol/fixtures/v1/valid/session-init.json'), true, 64, JSON_THROW_ON_ERROR)['instance'];
unset($session['character_id'], $session['character_binding']);
[$status, $accepted] = $call($mock, $base . '/sessions', $session);
$assert($status === 201 && in_array('speech.say', $accepted['capabilities'], true), 'cancel fixture session failed: ' . json_encode($accepted));
$sessionId = (string) $accepted['session_id']; $generation = (int) $accepted['generation'];
$actor = ['kind' => 'npc', 'record_id' => 'fargoth', 'content_file' => 'Morrowind.esm', 'display_name' => 'Fargoth',
    'refnum' => ['index' => 128964, 'content_file' => 0], 'cell' => ['kind' => 'interior', 'name' => 'Seyda Neen, Arrille\'s Tradehouse']];
$speak = fn(?string $text = null): array => ['schema' => 'lorkhan.menu-dialogue-tts.v1', 'message_id' => Uuid::v4(), 'request_id' => Uuid::v4(),
    'session_id' => $sessionId, 'generation' => $generation, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'actor' => $actor,
    'text' => $text ?? 'I have a feeling you and I are about to become very close.'];
$cancel = fn(array $target, array $overrides = []): array => array_replace(['schema' => 'lorkhan.menu-dialogue-tts.cancel.v1',
    'message_id' => Uuid::v4(), 'request_id' => Uuid::v4(), 'session_id' => $target['session_id'], 'generation' => $target['generation'],
    'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'target_message_id' => $target['message_id']], $overrides);
$rows = function (array $target) use ($db): array {
    $media = $db->prepare('SELECT count(*) FROM media_objects WHERE menu_dialogue_message_id=:message');
    $media->execute(['message' => $target['message_id']]);
    $request = $db->prepare('SELECT count(*) FROM menu_dialogue_tts_requests WHERE message_id=:message');
    $request->execute(['message' => $target['message_id']]);
    $attempt = $db->prepare("SELECT state,error_code FROM provider_attempts WHERE request_id=:request AND provider_kind='tts'");
    $attempt->execute(['request' => $target['request_id']]);
    return ['media' => (int) $media->fetchColumn(), 'requests' => (int) $request->fetchColumn(), 'attempts' => $attempt->fetchAll()];
};
$mediaFiles = fn(): int => is_dir($mediaPath) ? iterator_count(new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($mediaPath, FilesystemIterator::SKIP_DOTS))) : 0;

// After: completed speech keeps its media; replay is idempotent and a reused key cannot retarget.
$done = $speak();
[$status, $ready] = $call($mock, $base . '/menu-dialogue-tts', $done);
$assert($status === 201 && $ready['schema'] === 'lorkhan.menu-dialogue-tts.ready.v1', 'completed fixture speech failed');
$late = $cancel($done);
[$status, $body] = $call($mock, $base . '/menu-dialogue-tts/cancel', $late);
$assert($status === 200 && $body === ['schema' => 'lorkhan.menu-dialogue-tts.cancel.accepted.v1', 'message_id' => $late['message_id'],
    'request_id' => $late['request_id'], 'session_id' => $sessionId, 'generation' => $generation,
    'target_message_id' => $done['message_id'], 'status' => 'completed'], 'late cancel did not report completion: ' . json_encode($body));
[$status, $replay] = $call($mock, $base . '/menu-dialogue-tts/cancel', $late);
$assert($status === 200 && $replay == $body, 'cancel replay changed its result');
[$status, $conflict] = $call($mock, $base . '/menu-dialogue-tts/cancel', array_replace($late, ['target_message_id' => Uuid::v4()]));
$assert($status === 409 && $conflict['code'] === 'duplicate_conflict', 'reused cancel key retargeted another message');
$assert($rows($done)['media'] === 1 && $rows($done)['requests'] === 1, 'late cancel removed completed media');
[$status, $readyReplay] = $call($mock, $base . '/menu-dialogue-tts', $done);
$assert($status === 201 && $readyReplay == $ready, 'late cancel changed the completed speech replay');
fwrite(STDOUT, "cancel after completion keeps media and replays\n");

// Before: a cancel that wins the race stops menu and book speech before any provider attempt or media.
$early = $speak();
[$status, $body] = $call($mock, $base . '/menu-dialogue-tts/cancel', $cancel($early));
$assert($status === 200 && $body['status'] === 'cancelled', 'early cancel not accepted: ' . json_encode($body));
[$status, $body] = $call($mock, $base . '/menu-dialogue-tts', $early);
$assert($status === 409 && $body['code'] === 'operation_cancelled' && $body['retriable'] === false,
    'pre-cancelled speech was not rejected as cancelled: ' . json_encode($body));
$assert($rows($early) === ['media' => 0, 'requests' => 0, 'attempts' => []], 'pre-cancelled speech reached the provider');
$book = ['schema' => 'lorkhan.book.read-aloud.v1', 'message_id' => Uuid::v4(), 'request_id' => Uuid::v4(), 'session_id' => $sessionId,
    'generation' => $generation, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'book_id' => 'bk_fixture', 'title' => 'Fixture', 'text' => 'One page.'];
[$status] = $call($mock, $base . '/menu-dialogue-tts/cancel', $cancel($book));
[$bookStatus, $body] = $call($mock, $base . '/book/read-aloud', $book);
$assert($status === 200 && $bookStatus === 409 && $body['code'] === 'operation_cancelled', 'pre-cancelled book speech was not rejected');
fwrite(STDOUT, "cancel before synthesis prevents provider work\n");

// Ownership: only the session's authenticated installation and current generation may cancel.
$owned = $speak();
[$status, $body] = $call($mock, $base . '/menu-dialogue-tts/cancel', $cancel($owned), '00000000-0000-4000-8000-0000000000aa');
$assert($status === 403 && $body['code'] === 'forbidden', 'foreign installation cancelled speech: ' . json_encode($body));
[$status, $body] = $call($mock, $base . '/menu-dialogue-tts/cancel', $cancel($owned), sign: false);
$assert($status === 401 && $body['code'] === 'unauthorized', 'unsigned cancel accepted');
[$status, $body] = $call($mock, $base . '/menu-dialogue-tts/cancel', $cancel($owned, ['generation' => $generation - 1]));
$assert($status === 409 && $body['code'] === 'stale_generation', 'stale generation cancel accepted: ' . json_encode($body));
$self = $cancel($owned); $self['target_message_id'] = $self['message_id'];
[$status, $body] = $call($mock, $base . '/menu-dialogue-tts/cancel', $self);
$assert($status === 422 && $body['code'] === 'invalid_schema', 'self-targeted cancel accepted');
[$status, $body] = $call($mock, $base . '/menu-dialogue-tts', $owned);
$assert($status === 201 && $rows($owned)['media'] === 1, 'rejected cancels still stopped owned speech');
fwrite(STDOUT, "cancel requires owner installation and current generation\n");

// During: a slow loopback provider streams until cURL aborts; the server must abort within the polling bound.
$slowScript = <<<'PY'
import http.server, sys, time
log = open(sys.argv[2], 'a', buffering=1)
class Slow(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_POST(self):
        self.rfile.read(int(self.headers.get('Content-Length', '0')))
        log.write('received %f\n' % time.time())
        self.send_response(200); self.send_header('Content-Type', 'audio/wav'); self.send_header('Content-Length', '1000000'); self.end_headers()
        try:
            for _ in range(300):
                self.wfile.write(b'\0' * 32); self.wfile.flush(); time.sleep(0.05)
            log.write('completed %f\n' % time.time())
        except (BrokenPipeError, ConnectionResetError):
            log.write('disconnected %f\n' % time.time())
server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Slow)
open(sys.argv[1], 'w').write(str(server.server_address[1]))
server.serve_forever()
PY;
$work = sys_get_temp_dir() . '/lorkhan-cancel-slow-' . bin2hex(random_bytes(8)); mkdir($work, 0700);
file_put_contents($work . '/slow.py', $slowScript);
$mockProcess = proc_open(['python3', $work . '/slow.py', $work . '/port', $work . '/events.log'], [1 => ['file', '/dev/null', 'w'], 2 => STDERR], $mockPipes);
$waitFor = function (callable $ready, float $seconds, string $failure): void {
    $deadline = microtime(true) + $seconds;
    while (!$ready()) { if (microtime(true) >= $deadline) throw new RuntimeException($failure); usleep(20_000); }
};
$events = fn(): string => is_file($work . '/events.log') ? (string) file_get_contents($work . '/events.log') : '';
$speakInChild = function (array $message) use ($dsn, $tokenHash, $mediaPath, $work) {
    $env = ['LORKHAN_TEST_DSN' => $dsn, 'LORKHAN_CANCEL_TOKEN_HASH' => $tokenHash, 'LORKHAN_CANCEL_MEDIA' => $mediaPath,
        'LORKHAN_CANCEL_ENDPOINT' => 'http://127.0.0.1:' . trim((string) file_get_contents($work . '/port')) . '/v1/audio/speech',
        'LORKHAN_CANCEL_MESSAGE' => json_encode($message, JSON_THROW_ON_ERROR), 'PATH' => (string) getenv('PATH')];
    foreach (['LORKHAN_TEST_DB_USER', 'LORKHAN_TEST_DB_PASSWORD'] as $name) if (getenv($name) !== false) $env[$name] = (string) getenv($name);
    $process = proc_open([PHP_BINARY, __FILE__, '--speak'], [1 => ['pipe', 'w'], 2 => STDERR], $pipes, null, $env);
    if (!is_resource($process)) throw new RuntimeException('speech child failed to start');
    return [$process, $pipes[1]];
};
$finishChild = function ($process, $stdout): array {
    $output = (string) stream_get_contents($stdout); fclose($stdout); proc_close($process);
    return json_decode($output, true, 64, JSON_THROW_ON_ERROR);
};
try {
    $waitFor(fn(): bool => is_file($work . '/port') && trim((string) file_get_contents($work . '/port')) !== '', 10, 'slow TTS mock did not start');
    $filesBefore = $mediaFiles();

    $inflight = $speak('Wait, outlander. This speculative line will be abandoned.');
    [$process, $stdout] = $speakInChild($inflight);
    $waitFor(fn(): bool => str_contains($events(), 'received'), 10, 'slow TTS mock never received synthesis');
    usleep(300_000);
    $cancelledAt = microtime(true);
    [$status, $body] = $call($mock, $base . '/menu-dialogue-tts/cancel', $cancel($inflight));
    $assert($status === 200 && $body['status'] === 'cancelled', 'in-flight cancel not accepted: ' . json_encode($body));
    $result = $finishChild($process, $stdout);
    $assert($result['status'] === 409 && $result['body']['code'] === 'operation_cancelled' && $result['body']['retriable'] === false,
        'in-flight cancel produced the wrong terminal response: ' . json_encode($result));
    $assert($result['finished_at'] - $cancelledAt < 2.5, sprintf('in-flight synthesis took %.2fs to stop', $result['finished_at'] - $cancelledAt));
    $waitFor(fn(): bool => str_contains($events(), 'disconnected') || str_contains($events(), 'completed'), 5, 'slow TTS mock never saw cURL close');
    $assert(!str_contains($events(), 'completed'), 'cURL kept downloading after cancel');
    $assert($rows($inflight) === ['media' => 0, 'requests' => 0, 'attempts' => [['state' => 'cancelled', 'error_code' => 'operation_cancelled']]],
        'cancelled synthesis left media or a non-cancelled attempt: ' . json_encode($rows($inflight)));
    $assert($mediaFiles() === $filesBefore, 'cancelled synthesis wrote private media');
    fwrite(STDOUT, sprintf("cancel during synthesis aborted cURL in %.2fs without media\n", $result['finished_at'] - $cancelledAt));

    // Generation: a replacement session fences abandoned speech even without an explicit cancel.
    file_put_contents($work . '/events.log', '');
    $stale = $speak('A replaced session must not publish this line.');
    [$process, $stdout] = $speakInChild($stale);
    $waitFor(fn(): bool => str_contains($events(), 'received'), 10, 'slow TTS mock never received stale synthesis');
    $replacement = array_replace($session, ['message_id' => Uuid::v4(), 'generation' => $generation + 1]);
    $replacedAt = microtime(true);
    [$status, $body] = $call($mock, $base . '/sessions', $replacement);
    $assert($status === 201, 'replacement session failed: ' . json_encode($body));
    $result = $finishChild($process, $stdout);
    $assert(in_array($result['status'], [404, 409], true) && in_array($result['body']['code'], ['unknown_session', 'stale_generation'], true),
        'replaced-generation speech returned the wrong terminal response: ' . json_encode($result));
    $assert($result['finished_at'] - $replacedAt < 2.5, 'replaced-generation synthesis was not aborted promptly');
    $assert($rows($stale) === ['media' => 0, 'requests' => 0, 'attempts' => [['state' => 'cancelled', 'error_code' => 'operation_cancelled']]],
        'replaced-generation synthesis left media: ' . json_encode($rows($stale)));
    fwrite(STDOUT, "session replacement aborts in-flight menu speech\n");
} finally {
    proc_terminate($mockProcess); proc_close($mockProcess);
    foreach (glob($work . '/*') ?: [] as $file) unlink($file);
    rmdir($work);
}

// Retention: cancel markers outlive any provider timeout by a day, then the operational sweep removes them.
$markers = fn(): int => (int) $db->query('SELECT count(*) FROM menu_dialogue_tts_cancellations')->fetchColumn();
$before = $markers();
$db->prepare("UPDATE menu_dialogue_tts_cancellations SET cancelled_at=clock_timestamp()-interval '2 days' WHERE message_id=:message")
    ->execute(['message' => $early['message_id']]);
(new FirstPartyJobRepository($db))->retainOperational(30, gmdate('Y-m-d\TH:i:s\Z'), 100);
$assert($before >= 3 && $markers() === $before - 1, 'operational retention did not remove only the expired cancel marker');
fwrite(STDOUT, "expired cancel markers are retained for one day\n");
fwrite(STDOUT, "menu TTS cancellation passed\n");
