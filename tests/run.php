<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoload.php';
require __DIR__ . '/Support/StateStore.php';

use ALMSIVIserver\Config\Settings;
use ALMSIVIserver\Application\ConnectorCatalog;
use ALMSIVIserver\Application\CredentialStore;
use ALMSIVIserver\Application\CloudSpeechConnectorProvider;
use ALMSIVIserver\Application\CloudSpeechToTextConnectorProvider;
use ALMSIVIserver\Application\MockSpeechProvider;
use ALMSIVIserver\Application\LocalSpeechConnectorProvider;
use ALMSIVIserver\Application\NeverCancelledToken;
use ALMSIVIserver\Application\OpenAiCompatibleProvider;
use ALMSIVIserver\Application\StreamingDialogueText;
use ALMSIVIserver\Application\OpenAiCompatibleSpeechProvider;
use ALMSIVIserver\Application\OpenAiCompatibleSpeechToTextProvider;
use ALMSIVIserver\Application\PromptAssembler;
use ALMSIVIserver\Application\InlineNarrationRouter;
use ALMSIVIserver\Application\DialoguePlanner;
use ALMSIVIserver\Application\ProviderFactory;
use ALMSIVIserver\Application\ZonosGradioSpeechProvider;
use ALMSIVIserver\Application\XvaSynthSpeechProvider;
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
$streamText=new StreamingDialogueText();$streamChunks=[];
foreach(['{"utterances":[{"text":"Hello ','there. Welcome to ','Balmora!"}],"action":null}'] as $index=>$chunk)
    foreach($streamText->push($chunk,$index===2) as $delta)$streamChunks[]=$delta;
$check(implode('',$streamChunks)==='Hello there. Welcome to Balmora!',
    'streaming dialogue exposes only decoded utterance text in bounded deltas');
$actionProvider = new OpenAiCompatibleProvider('https://api.openai.com/v1/chat/completions', ['api.openai.com'], 'gpt-test', 'test-key');
$normalizeAction = new ReflectionMethod($actionProvider, 'normalizeAction');
$normalizedAction = $normalizeAction->invoke($actionProvider,
    ['utterances' => [['text' => 'Hello']], 'action' => ['function' => 'animation.play', 'parameters' => ['group' => 'idle2']]],
    ['payload' => ['target' => ['record_id' => 'fargoth'], 'speaker' => ['record_id' => 'player']]]);
$check(($normalizedAction['action']['name'] ?? null) === 'animation.play'
    && ($normalizedAction['action']['tier'] ?? null) === 1
    && ($normalizedAction['action']['actor']['record_id'] ?? null) === 'fargoth',
    'OpenAI-compatible provider normalizes compact actions with trusted identities and canonical tiers');
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
    'controls-query.json' => 'almsivi.controls.query.v1',
    'controls-select.json' => 'almsivi.controls.select.v1',
] as $fixture => $schema) {
    $document = json_decode((string) file_get_contents($fixtureRoot . '/' . $fixture), true, 64, JSON_THROW_ON_ERROR);
    $validator->validate($document['instance'], $schema);
    $check(true, $fixture . ' validates');
}
$directActionTurn=json_decode((string)file_get_contents($fixtureRoot.'/turn.json'),true,64,JSON_THROW_ON_ERROR)['instance'];
$secondaryTarget=$directActionTurn['payload']['target'];$secondaryTarget['record_id']='mudcrab';
$secondaryTarget['display_name']='Mudcrab';$secondaryTarget['kind']='creature';$secondaryTarget['refnum']['index']=113;
$directActionTurn['payload']['action_request']=['name'=>'combat.start','tier'=>2,'parameters'=>[],'target'=>$secondaryTarget];
$validator->validate($directActionTurn,'almsivi.turn.v1');
$check(true,'typed player action request validates inside turn envelope');
try{
    $invalidDirectAction=$directActionTurn;$invalidDirectAction['payload']['action_request']['name']='../execute';
    $validator->validate($invalidDirectAction,'almsivi.turn.v1');
    $check(false,'unsafe player action name rejected');
}catch(ValidationException $exception){$check($exception->getMessage()==='invalid_schema','unsafe player action name rejected');}
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
$promptTurn['_player_profile']=['profile_id'=>'player-profile','name'=>'Nerevarine','revision'=>3,
    'actor_identity'=>['kind'=>'player','display_name'=>'Nerevarine'],
    'content'=>['biography'=>'Freed from the Imperial prison.','personality'=>'Curious','ignored'=>'not prompt-safe']];
$promptTurn['_item_descriptions']=[['description_id'=>'private','record_id'=>'iron_dagger','content_file'=>'Morrowind.esm','name'=>'Iron Dagger','description'=>'A short iron blade.','ignored'=>'not prompt-safe either']];
$assembler=new PromptAssembler(4096,1024);$assembled=$assembler->assemble($promptTurn,$promptSelection);$repeat=$assembler->assemble($promptTurn,$promptSelection);
$check($assembled===$repeat && str_starts_with($assembled['provider_input']['_assembled_prompt'],'[PROFILE]'), 'prompt assembly is deterministic and ordered');
$check(str_contains($assembled['provider_input']['_assembled_prompt'],'player_profile')
    &&str_contains($assembled['provider_input']['_assembled_prompt'],'Freed from the Imperial prison.')
    &&!str_contains($assembled['provider_input']['_assembled_prompt'],'not prompt-safe'),
    'server-owned player profile is included in turn context with an explicit field allowlist');
$check(str_contains($assembled['provider_input']['_assembled_prompt'],'record_descriptions')
    &&str_contains($assembled['provider_input']['_assembled_prompt'],'A short iron blade.')
    &&!str_contains($assembled['provider_input']['_assembled_prompt'],'not prompt-safe either'),
    'server-owned record descriptions are included with an explicit field allowlist');
$check($assembled['trace']['input_bytes']<=4096 && !array_key_exists('content',$assembled['trace']['sources'][0]) && $assembled['trace']['sources'][0]['redacted_preview']==='', 'prompt trace is bounded and metadata-only');
$check($assembled['trace']['sources'][2]['source_kind']==='memory' && $assembled['trace']['sources'][3]['source_kind']==='action_result', 'prompt source order is stable');

$identity=static fn(string$kind,string$id,int$index,string$name):array=>['kind'=>$kind,'record_id'=>$id,
    'refnum'=>['index'=>$index,'content_file'=>0],'content_file'=>'Morrowind.esm',
    'cell'=>['kind'=>'exterior','grid_x'=>-2,'grid_y'=>-9],'display_name'=>$name];
$narrator=$identity('narrator','almsivi:narrator',0,'The Narrator');$narrator['content_file']='ALMSIVI';
$narrationTurn=['payload'=>['speaker'=>$identity('player','player',2,'Nerevarine'),
    'target'=>$identity('npc','fargoth',1,'Fargoth'),'audience'=>[]],
    '_narrator_profile'=>['actor_identity'=>$narrator,'content'=>['enabled'=>true,'inline_narration_mode'=>'Narrator']]];
$routed=(new InlineNarrationRouter())->route($narrationTurn,['utterances'=>[['text'=>'*The swamp falls quiet.* Welcome, outlander.']],'action'=>null]);
$planned=(new DialoguePlanner())->plan($narrationTurn,$routed);
$check(count($planned)===2&&$planned[0]['speaker']['kind']==='narrator'&&$planned[0]['text']==='The swamp falls quiet.'
    &&$planned[1]['speaker']['record_id']==='fargoth'&&$planned[1]['text']==='Welcome, outlander.',
    'enabled inline narration routes a leading block through the narrator before NPC speech');
$narrationTurn['_narrator_profile']['content']['inline_narration_mode']='Text Only';
$planned=(new DialoguePlanner())->plan($narrationTurn,(new InlineNarrationRouter())->route($narrationTurn,
    ['utterances'=>[['text'=>'*A distant silt strider calls.* Hello.']],'action'=>null]));
$check($planned[0]['speech_enabled']===false&&$planned[1]['speech_enabled']===true,
    'text-only narration remains visible without synthesizing narrator audio');

$ttsCatalog=ConnectorCatalog::all('tts_provider');$sttCatalog=ConnectorCatalog::all('stt_provider');
$check(count($ttsCatalog)===22 && count($sttCatalog)===7, 'CHIM-lineage TTS and STT connector catalogs are complete');
$check(array_column(ConnectorCatalog::optionFields('tts_provider','xtts-fastapi'),'name')===
    ['speed','temperature','top_p','top_k','repetition_penalty']
    &&array_column(ConnectorCatalog::optionFields('stt_provider','azure'),'name')===['profanity']
    &&array_column(ConnectorCatalog::optionFields('stt_provider','gemini'),'name')===['include_tone'],
    'connector catalog exposes labelled fields for every runtime-supported advanced option');
$check(ConnectorCatalog::defaults('tts_provider','pockettts')['endpoint']==='http://127.0.0.1:8086'
    &&ConnectorCatalog::defaults('tts_provider','openai')['model']==='tts-1'
    &&ConnectorCatalog::defaults('stt_provider','parakeet')['endpoint']==='http://127.0.0.1:8022'
    &&ConnectorCatalog::defaults('stt_provider','gemini')['model']==='gemini-2.5-flash',
    'connector catalog exposes driver-specific create defaults for local and cloud providers');
$credentialRoot=sys_get_temp_dir().'/almsivi-credentials-'.bin2hex(random_bytes(4));mkdir($credentialRoot,0700);
$credentialPath=$credentialRoot.'/provider-keys.json';$credentialStore=new CredentialStore($credentialPath);
$credentialStore->set('ALMSIVI_TTS_GCP_API_KEY','managed-secret');
$check($credentialStore->resolve('ALMSIVI_TTS_GCP_API_KEY')==='managed-secret'
    &&count(array_filter($credentialStore->statuses(),static fn(array$row):bool=>$row['variable']==='ALMSIVI_TTS_GCP_API_KEY'&&$row['source']==='managed store'))===1,
    'credential store resolves managed keys while exposing status metadata only');
putenv('ALMSIVI_TTS_GCP_API_KEY=environment-secret');
$check($credentialStore->resolve('ALMSIVI_TTS_GCP_API_KEY')==='environment-secret','process environment overrides browser-managed credentials');
putenv('ALMSIVI_TTS_GCP_API_KEY');$credentialStore->delete('ALMSIVI_TTS_GCP_API_KEY');
$check($credentialStore->resolve('ALMSIVI_TTS_GCP_API_KEY')===''&&(fileperms($credentialPath)&0777)===0640,'credential deletion is persistent and store permissions are restrictive');
unlink($credentialPath);rmdir($credentialRoot);
$preset=ConnectorCatalog::validate('tts_provider',['driver'=>'pockettts','endpoint'=>'http://127.0.0.1:8020','model'=>'default','voice'=>'default','language'=>'en','timeout_ms'=>30000,'options'=>[]]);
$check($preset['driver']==='pockettts' && $preset['timeout_ms']===30000, 'speech connector preset validation is strict and normalized');
$localPreset=static fn(string $driver):array=>['kind'=>'tts_provider','content'=>['driver'=>$driver,
    'endpoint'=>'http://127.0.0.1:8999','model'=>'default','voice'=>'default','language'=>'en','timeout_ms'=>30000,'options'=>[]]];
foreach(['melotts','mimic3','piper-tts','stylettsv2'] as $driver){
    $check(ProviderFactory::speechForPreset([], $localPreset($driver)) instanceof LocalSpeechConnectorProvider,
        $driver . ' selected connector builds a bounded local WAV adapter');
}
$cloudPreset=static fn(string $driver):array=>['kind'=>'tts_provider','content'=>['driver'=>$driver,
    'endpoint'=>'https://example.com','model'=>'default','voice'=>'default','language'=>'en-US','timeout_ms'=>30000,'options'=>[]]];
foreach(['11labs','azure','cartesia','convai','coqui-ai','deepgram','gcp','inworld'] as $driver){
    $check(ProviderFactory::speechForPreset([], $cloudPreset($driver)) instanceof CloudSpeechConnectorProvider,
        $driver . ' selected connector builds a credential-isolated cloud WAV adapter');
}
$cloudSttPreset=static fn(string $driver):array=>['kind'=>'stt_provider','content'=>['driver'=>$driver,
    'endpoint'=>'https://example.com','model'=>'default','voice'=>'default','language'=>'en-US','timeout_ms'=>30000,'options'=>[]]];
foreach(['azure','deepgram','gemini','inworld'] as $driver){
    $check(ProviderFactory::speechToTextForPreset([], $cloudSttPreset($driver)) instanceof CloudSpeechToTextConnectorProvider,
        $driver . ' selected STT connector builds a credential-isolated cloud adapter');
}
$voiceRoot=sys_get_temp_dir().'/almsivi-zonos-'.bin2hex(random_bytes(4));mkdir($voiceRoot);
$zonosPreset=['kind'=>'tts_provider','content'=>['driver'=>'zonos_gradio','endpoint'=>'http://127.0.0.1:8999',
    'model'=>'Zyphra/Zonos-v0.1-hybrid','voice'=>'default','language'=>'en-US','timeout_ms'=>30000,'options'=>[]]];
$check(ProviderFactory::speechForPreset(['voice_storage_path'=>$voiceRoot],$zonosPreset) instanceof ZonosGradioSpeechProvider,
    'Zonos selected connector builds its bounded Gradio job adapter');
rmdir($voiceRoot);
$xvaPreset=['kind'=>'tts_provider','content'=>['driver'=>'xvasynth','endpoint'=>'http://127.0.0.1:8999',
    'model'=>'default','voice'=>'default','language'=>'en-US','timeout_ms'=>30000,'options'=>[]]];
$check(ProviderFactory::speechForPreset([],$xvaPreset) instanceof XvaSynthSpeechProvider,
    'xVASynth selected connector builds its bounded WSL shared-file adapter');

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
