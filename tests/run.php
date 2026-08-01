<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoload.php';
require __DIR__ . '/Support/StateStore.php';

use ALMSIVIserver\Config\Settings;
use ALMSIVIserver\Application\MockSpeechProvider;
use ALMSIVIserver\Application\NeverCancelledToken;
use ALMSIVIserver\Application\OpenAiCompatibleProvider;
use ALMSIVIserver\Application\OpenAiCompatibleSpeechProvider;
use ALMSIVIserver\Application\OpenAiCompatibleSpeechToTextProvider;
use ALMSIVIserver\Application\PromptAssembler;
use ALMSIVIserver\Application\ProviderFactory;
use ALMSIVIserver\Http\Response;
use ALMSIVIserver\Infrastructure\MediaStore;
use ALMSIVIserver\Tests\Support\StateStore;
use ALMSIVIserver\Protocol\ValidationException;
use ALMSIVIserver\Protocol\Validator;
use ALMSIVIserver\Security\PairingToken;
use ALMSIVIserver\Security\Redactor;
use ALMSIVIserver\Security\RequestMac;
use ALMSIVIserver\Http\Request;

$failures = 0;
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    ++$checks;
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, "not ok - {$message}\n");
    }
};

$token = PairingToken::generate();
$hash = PairingToken::hash($token);
$check(strlen($token) >= 43, 'pairing token has 256 bits');
$check(PairingToken::verifyAuthorization('Bearer ' . $token, $hash), 'pairing token verifies');
$check(!PairingToken::verifyAuthorization('Bearer wrong', $hash), 'wrong token rejected');
$check(Redactor::value(['authorization' => 'Bearer ' . $token])['authorization'] === '[REDACTED]', 'authorization redacted');
$check(!str_contains((string) Redactor::value('Bearer ' . $token), $token), 'embedded bearer token redacted');
$key=random_bytes(32);$installation='00000000-0000-4000-8000-000000000001';$timestamp=gmdate('Y-m-d\\TH:i:s\\Z');$nonce=bin2hex(random_bytes(16));
$unsigned=new Request('POST','/ALMSIVIserver/api/v1/turns',['Content-Type'=>'application/json; charset=utf-8'],[],'{}');$digest=RequestMac::bodyDigest($unsigned->body);$signature=RequestMac::sign($key,$unsigned,$installation,$timestamp,$nonce,'application/json; charset=utf-8',$digest);
$signed=new Request($unsigned->method,$unsigned->path,$unsigned->headers+['X-ALMSIVI-Auth'=>RequestMac::ALGORITHM,'X-ALMSIVI-Installation-Id'=>$installation,'X-ALMSIVI-Timestamp'=>$timestamp,'X-ALMSIVI-Nonce'=>$nonce,'X-ALMSIVI-Content-SHA256'=>$digest,'X-ALMSIVI-Signature'=>$signature],[],$unsigned->body);
$check(RequestMac::verify($signed,$key)===$installation,'request MAC binds method target content installation timestamp and nonce');
$check(RequestMac::verify(new Request('GET',$signed->path,$signed->headers,[],$signed->body),$key)===false,'request MAC rejects method tampering');

$settings = Settings::fromArray(['pairing_token_hash' => $hash, 'storage_path' => sys_get_temp_dir() . '/almsivi-test']);
$check($settings->maxJsonBytes === 2_097_152, 'safe size default');
$frontController = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$check(str_contains($frontController, "Provider factory is test-only."), 'custom provider factory is test-only');
$check(new OpenAiCompatibleProvider('https://api.openai.com/v1/chat/completions', ['api.openai.com'], 'gpt-test', 'test-key') instanceof OpenAiCompatibleProvider, 'OpenAI-compatible provider accepts a vetted HTTPS endpoint');
try {
    new OpenAiCompatibleProvider('http://api.openai.com/v1/chat/completions', ['api.openai.com'], 'gpt-test', 'test-key');
    $check(false, 'OpenAI-compatible provider rejects plaintext HTTP');
} catch (InvalidArgumentException) {
    $check(true, 'OpenAI-compatible provider rejects plaintext HTTP');
}
$check(new OpenAiCompatibleProvider('https://api.openai.com/v1/chat/completions', ['api.openai.com'], 'gpt-test', '') instanceof OpenAiCompatibleProvider,
    'OpenAI-compatible provider permits endpoints that do not require a key');
$check(new OpenAiCompatibleSpeechProvider('https://api.openai.com/v1/audio/speech', ['api.openai.com'], 'tts-test', 'alloy') instanceof OpenAiCompatibleSpeechProvider,
    'OpenAI-compatible TTS accepts a vetted HTTPS endpoint');
$check(new OpenAiCompatibleSpeechToTextProvider('https://api.openai.com/v1/audio/transcriptions', ['api.openai.com'], 'stt-test') instanceof OpenAiCompatibleSpeechToTextProvider,
    'OpenAI-compatible STT accepts a vetted HTTPS endpoint');
$check(ProviderFactory::dialogue([]) instanceof \ALMSIVIserver\Application\MockProvider
    && ProviderFactory::speech([]) instanceof MockSpeechProvider
    && ProviderFactory::speechToText([]) instanceof \ALMSIVIserver\Application\MockSpeechToTextProvider,
    'shared provider factory gives HTTP and worker the same safe defaults');
$response = Response::error(401, 'unauthorized', 'correlation');
$decodedError = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
$check($decodedError['message'] === 'Request rejected', 'generic client error');

$validator = new Validator();
$fixtureRoot = dirname(__DIR__) . '/protocol/fixtures/v1/valid';
foreach ([
    'session-init.json' => 'almsivi.session.init.v1',
    'turn.json' => 'almsivi.turn.v1',
    'interrupt.json' => 'almsivi.interrupt.v1',
] as $fixture => $schema) {
    $document = json_decode((string) file_get_contents($fixtureRoot . '/' . $fixture), true, 64, JSON_THROW_ON_ERROR);
    $validator->validate($document['instance'], $schema);
    $check(true, $fixture . ' validates');
}
$legacyAction = json_decode((string) file_get_contents($fixtureRoot . '/action-result.json'), true, 64, JSON_THROW_ON_ERROR)['instance'];
$action = $legacyAction + [
    'message_id' => '00000000-0000-4000-8000-000000000021',
    'request_id' => '00000000-0000-4000-8000-000000000006',
    'turn_id' => '00000000-0000-4000-8000-000000000008',
    'session_id' => '00000000-0000-4000-8000-000000000007',
    'generation' => 7,
];
$validator->validate($action, 'almsivi.action-result.v1');
$check(true, 'expanded action-result validates');
$check($validator->decode('{}', 8) === [], 'empty JSON object decodes');

$promptTurn=['schema'=>'almsivi.turn.v1','request_id'=>'r','turn_id'=>'t','installation_id'=>'i','profile_id'=>'p','playthrough_id'=>'w','session_id'=>'s','generation'=>1,'content_fingerprint'=>'sha256:'.str_repeat('a',64),'payload'=>['input'=>['kind'=>'text','language'=>'en','text'=>'Hello'],'speaker'=>['record_id'=>'player'],'target'=>['record_id'=>'npc'],'audience'=>[],'context'=>[],'ui_source'=>'chat']];
$promptSelection=['profile'=>['profile_id'=>'p','revision'=>1,'content'=>['role'=>'hero']],'prompt'=>['configuration_id'=>'c','revision'=>2,'content'=>['instruction'=>'Stay in character']],'memory'=>[['memory_id'=>'m','content'=>'A memory']],'relationship'=>[],'knowledge'=>[],'narrative'=>[],'recent_action_results'=>[['action_id'=>'a','status'=>'succeeded','reason_code'=>'ok','observed'=>[],'completed_at'=>'2026-01-01T00:00:00Z']]];
$assembler=new PromptAssembler(4096,1024);$assembled=$assembler->assemble($promptTurn,$promptSelection);$repeat=$assembler->assemble($promptTurn,$promptSelection);
$check($assembled===$repeat && str_starts_with($assembled['provider_input']['_assembled_prompt'],'[PROFILE]'), 'prompt assembly is deterministic and ordered');
$check($assembled['trace']['input_bytes']<=4096 && !array_key_exists('content',$assembled['trace']['sources'][0]) && $assembled['trace']['sources'][0]['redacted_preview']==='', 'prompt trace is bounded and metadata-only');
$check($assembled['trace']['sources'][2]['source_kind']==='memory' && $assembled['trace']['sources'][3]['source_kind']==='action_result', 'prompt source order is stable');

try {
    $turn = json_decode((string) file_get_contents($fixtureRoot . '/turn.json'), true, 64, JSON_THROW_ON_ERROR)['instance'];
    $turn['unexpected'] = true;
    $validator->validate($turn, 'almsivi.turn.v1');
    $check(false, 'unknown field rejected');
} catch (ValidationException $exception) {
    $check($exception->getMessage() === 'invalid_schema', 'unknown field rejected');
}
try {
    $validator->decode(str_repeat('x', 9), 8);
    $check(false, 'oversized body rejected');
} catch (ValidationException $exception) {
    $check($exception->getMessage() === 'payload_too_large', 'oversized body rejected');
}

$temporary = sys_get_temp_dir() . '/almsivi-state-' . bin2hex(random_bytes(8));
$store = new StateStore($temporary);
$store->mutate(static function (array &$state): void {
    $state['events'][] = ['id' => 'event-1'];
});
$check(count($store->load()['events']) === 1, 'state mutation persists');
$stateFile = $temporary . '/development-state.json';
$check((fileperms($stateFile) & 0777) === 0600, 'state file is private');
unlink($stateFile);
rmdir($temporary);

$mediaRoot = sys_get_temp_dir() . '/almsivi-media-unit-' . bin2hex(random_bytes(8));
$media = new MediaStore($mediaRoot, 1024, 2048);
$speech = (new MockSpeechProvider())->synthesize('deterministic', new NeverCancelledToken());
$check(strlen($speech['bytes']) === 204 && substr($speech['bytes'], 0, 4) === 'RIFF', 'mock TTS emits legal tiny WAV');
$check(OpenAiCompatibleSpeechProvider::wavDurationMs($speech['bytes']) === 20, 'live TTS validates WAV framing and duration');
$mediaId = '00000000-0000-4000-8000-000000000099';
$mediaHash = $media->put($mediaId, $speech['bytes'], $speech['codec'], $speech['mime_type']);
$check(hash_equals($mediaHash, hash('sha256', $media->read($mediaId, 204, $mediaHash))), 'private media verifies bytes and hash');
try {
    $media->put('../escape', $speech['bytes'], $speech['codec'], $speech['mime_type']);
    $check(false, 'media path traversal rejected');
} catch (RuntimeException) {
    $check(true, 'media path traversal rejected');
}
try {
    $media->put('00000000-0000-4000-8000-000000000098', 'not-a-wav', 'wav', 'audio/wav');
    $check(false, 'media MIME signature rejected');
} catch (RuntimeException) {
    $check(true, 'media MIME signature rejected');
}
$media->delete($mediaId);
rmdir($mediaRoot);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} server checks failed\n");
    exit(1);
}
echo "{$checks} server checks passed\n";
