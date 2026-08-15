<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoload.php';
require __DIR__ . '/Support/StateStore.php';

use ALMSIVIserver\Config\Settings;
use ALMSIVIserver\Application\ConnectorCatalog;
use ALMSIVIserver\Application\CredentialStore;
use ALMSIVIserver\Application\CloudSpeechConnectorProvider;
use ALMSIVIserver\Application\CloudSpeechToTextConnectorProvider;
use ALMSIVIserver\Application\CanonicalResponseNormalizer;
use ALMSIVIserver\Application\MockSpeechProvider;
use ALMSIVIserver\Application\LocalSpeechConnectorProvider;
use ALMSIVIserver\Application\MockOghmaTopicExtractor;
use ALMSIVIserver\Application\MorrowindGeographyCatalog;
use ALMSIVIserver\Application\OghmaGroundedRetriever;
use ALMSIVIserver\Application\NeverCancelledToken;
use ALMSIVIserver\Application\OpenAiCompatibleProvider;
use ALMSIVIserver\Application\StreamingDialogueText;
use ALMSIVIserver\Application\OpenAiCompatibleSpeechProvider;
use ALMSIVIserver\Application\OpenAiCompatibleSpeechToTextProvider;
use ALMSIVIserver\Application\PromptAssembler;
use ALMSIVIserver\Application\InlineNarrationRouter;
use ALMSIVIserver\Application\DialoguePlanner;
use ALMSIVIserver\Application\EffectiveSettingsResolver;
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
$geography=MorrowindGeographyCatalog::bundled();
$fargoth=['kind'=>'npc','record_id'=>'fargoth','content_file'=>'Morrowind.esm',
    'refnum'=>['index'=>128964,'content_file'=>0],
    'cell'=>['kind'=>'interior','name'=>'Balmora, Guild of Mages']];
$resolvedLocality=$geography->resolve($fargoth,'Morrowind.esm');
$check(($resolvedLocality['tags']??null)===['bitter_coast']&&($resolvedLocality['source']??null)==='official_refnum',
    'Morrowind geography prefers immutable official RefNum locality over the actor current cell');
$cellLocality=$geography->resolve([...$fargoth,'refnum'=>['index'=>999999,'content_file'=>0]],'Morrowind.esm');
$check(($cellLocality['tags']??null)===['west_gash']&&($cellLocality['source']??null)==='current_cell_fallback',
    'Morrowind geography falls back to the first observed official cell for unlisted references');
$ravenRock=$geography->resolve(['kind'=>'npc','record_id'=>'Baro Egnatius','content_file'=>'Bloodmoon.esm',
    'refnum'=>['index'=>10134,'content_file'=>2],'cell'=>['kind'=>'exterior','grid_x'=>0,'grid_y'=>0]],'Bloodmoon.esm');
$check(($ravenRock['tags']??null)===['solstheim','raven_rock'],
    'Morrowind geography preserves the reviewed Raven Rock supplemental locality');
$check($geography->resolve([...$fargoth,'cell'=>['kind'=>'interior','name'=>'Unknown Cell']],null)===null,
    'Morrowind geography abstains when neither RefNum nor current cell is recognized');
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
foreach (['inventory.inspect'=>0,'ai.approach'=>1,'ai.wait'=>1,'ai.travel'=>1,'ai.escort'=>1,'ai.face'=>1] as $name=>$tier) {
    $parameters=$name==='ai.wait'?['duration_seconds'=>3600]:(in_array($name,['ai.travel','ai.escort'],true)
        ?['destination_x'=>1,'destination_y'=>2,'destination_z'=>3,'destination_cell'=>'exterior:0:0']:[]);
    $normalized=$normalizeAction->invoke($actionProvider,
        ['utterances'=>[['text'=>'Ready.']],'action'=>['name'=>$name,'parameters'=>$parameters]],
        ['payload'=>['target'=>['record_id'=>'fargoth'],'speaker'=>['record_id'=>'player']]]);
    $check(($normalized['action']['name']??null)===$name&&($normalized['action']['tier']??null)===$tier,
        "OpenAI-compatible provider exposes {$name} with its canonical tier");
}
$check(new OpenAiCompatibleSpeechProvider('https://api.openai.com/v1/audio/speech', ['api.openai.com'], 'tts-test', 'alloy') instanceof OpenAiCompatibleSpeechProvider,
    'OpenAI-compatible TTS accepts a vetted HTTPS endpoint');
$check(new OpenAiCompatibleSpeechToTextProvider('https://api.openai.com/v1/audio/transcriptions', ['api.openai.com'], 'stt-test') instanceof OpenAiCompatibleSpeechToTextProvider,
    'OpenAI-compatible STT accepts a vetted HTTPS endpoint');
$check(ProviderFactory::dialogue([]) instanceof \ALMSIVIserver\Application\MockProvider
    && ProviderFactory::speech([]) instanceof MockSpeechProvider
    && ProviderFactory::speechToText([]) instanceof \ALMSIVIserver\Application\MockSpeechToTextProvider,
    'shared provider factory gives HTTP and worker the same safe defaults');
$mockExtractor=new MockOghmaTopicExtractor();
$check($mockExtractor->extract('Recent line [oghma: House Redoran, Vivec, Dagoth Ur, ignored]',3,new NeverCancelledToken())
    ===['House Redoran','Vivec','Dagoth Ur'],'Oghma extractor returns distinct bounded UTF-8 topics');
$check(ProviderFactory::oghmaTopicExtractorForSlot([],['configuration_id'=>'00000000-0000-4000-8000-000000000123',
    'revision'=>1,'content'=>['driver'=>'mock','model'=>'deterministic-mock-v1']])instanceof MockOghmaTopicExtractor,
    'profile-routed mock connector builds the dedicated Oghma extractor contract');
$groundedOghma=new OghmaGroundedRetriever();
$groundedCatalog=[
    ['topic'=>'sixth_house','aliases'=>'House Dagoth','category'=>'factions'],
    ['topic'=>'dagoth_ur','aliases'=>'Voryn Dagoth','category'=>'figures'],
    ['topic'=>'vivec','aliases'=>'Warrior-Poet','category'=>'figures'],
];
$groundedResult=$groundedOghma->extract('Tell me about House Dagoth and Vivec.',$groundedCatalog,2);$groundedTopics=$groundedResult['topics'];
$check($groundedTopics===['sixth_house','vivec'],'grounded Oghma preserves canonical alias and multi-topic mention order: '
    .json_encode($groundedResult['matches'],JSON_UNESCAPED_UNICODE));
$check($groundedOghma->extract('Vivec: We should leave now.',$groundedCatalog,1)['topics']===[],
    'grounded Oghma does not treat a catalog-shaped speaker label as conversation lore');
$followUpPolicy=array_map([OghmaGroundedRetriever::class,'shouldUsePreviousExchange'],[
    'What about their leader?','Tell me more about it.','What happened there?','Thanks.','What are we doing now?','It started raining.']);
$followUpCurrent=$groundedOghma->extract('What about their leader?',$groundedCatalog,3);
$followUpPrevious=$groundedOghma->extract('We were discussing House Dagoth.',$groundedCatalog,1);
$check($followUpPolicy===[true,true,true,false,false,false]&&$followUpCurrent['topics']===[]
    &&$followUpPrevious['topics']===['sixth_house'],
    'grounded Oghma carries one topic only for an unresolved referential follow-up');
$check($groundedOghma->resolveSuggestions(['House Dagoth','invented topic'],$groundedCatalog,2)===['sixth_house'],
    'Oghma fallback suggestions resolve only to exact unambiguous catalog entities');
$unicodeCatalog=[['topic'=>'maesa_áran','aliases'=>'Máesa Aran','category'=>'figures']];
$check($groundedOghma->extract('Tell me about Máesa Aran.',$unicodeCatalog,1)['topics']===['maesa_áran'],
    'grounded Oghma preserves UTF-8 canonical topics and aliases');
$ambiguousCatalog=[
    ['topic'=>'first_subject','aliases'=>'Shared Lore','category'=>'history'],
    ['topic'=>'second_subject','aliases'=>'Shared Lore','category'=>'history'],
];
$check($groundedOghma->extract('Tell me about Shared Lore.',$ambiguousCatalog,1)['topics']===[]
    &&$groundedOghma->resolveSuggestions(['Shared Lore'],$ambiguousCatalog,1)===[],
    'grounded Oghma abstains from ambiguous catalog aliases in local and fallback extraction');
$mockActionProvider=new \ALMSIVIserver\Application\MockProvider();
foreach ([
    ['Check your inventory.','action.inventory.inspect','inventory.inspect',[]],
    ['Come closer.','action.ai.approach','ai.approach',[]],
    ['Wait here.','action.ai.wait','ai.wait',['duration_seconds'=>3600]],
] as [$text,$capability,$name,$parameters]) {
    $mockAction=$mockActionProvider->complete(['payload'=>[
        'input'=>['text'=>$text],'target'=>['record_id'=>'fargoth'],'speaker'=>['record_id'=>'player'],
    ],'_negotiated_capabilities'=>[$capability]],new \ALMSIVIserver\Application\NeverCancelledToken())['action']??null;
    $check(is_array($mockAction)&&($mockAction['name']??null)===$name&&($mockAction['parameters']??null)===$parameters,
        "mock provider emits {$name} only through its negotiated capability");
}
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

$promptTurn=['schema'=>'almsivi.turn.v1','request_id'=>'r','turn_id'=>'t','installation_id'=>'i','profile_id'=>'p','playthrough_id'=>'w','session_id'=>'s','generation'=>1,'content_fingerprint'=>'sha256:'.str_repeat('a',64),'payload'=>['input'=>['kind'=>'text','language'=>'en','text'=>'Hello'],'speaker'=>['record_id'=>'player','display_name'=>'RANGROO'],'target'=>['record_id'=>'npc','display_name'=>'Fargoth'],'audience'=>[],'context'=>[],'ui_source'=>'chat']];
$promptSelection=['profile'=>['profile_id'=>'p','revision'=>1,'name'=>'Fargoth','actor_identity'=>['record_id'=>'npc','display_name'=>'Fargoth'],'content'=>['biography'=>'Curious & wary <Bosmer>.','personality'=>'Cautious']],'prompt'=>['configuration_id'=>'c','revision'=>2,'content'=>['instruction'=>'Stay in character']],'memory'=>[['memory_id'=>'m','content'=>'A memory']],'relationship'=>[],'knowledge'=>[],'narrative'=>[],'recent_action_results'=>[['action_id'=>'a','status'=>'succeeded','reason_code'=>'ok','observed'=>[],'completed_at'=>'2026-01-01T00:00:00Z']]];
$promptTurn['_player_profile']=['profile_id'=>'player-profile','name'=>'Nerevarine','revision'=>3,
    'actor_identity'=>['kind'=>'player','display_name'=>'Nerevarine'],
    'content'=>['biography'=>'Freed from the Imperial prison.','personality'=>'Curious','ignored'=>'not prompt-safe']];
$promptTurn['_narrator_profile']=['actor_identity'=>['kind'=>'narrator','display_name'=>'The Narrator'],
    'content'=>['enabled'=>false,'biography'=>'DISABLED NARRATOR SENTINEL']];
$promptTurn['_item_descriptions']=[['description_id'=>'private','record_id'=>'iron_dagger','content_file'=>'Morrowind.esm','name'=>'Iron Dagger','description'=>'A short iron blade.','ignored'=>'not prompt-safe either']];
$assembler=new PromptAssembler(4096,1024);$assembled=$assembler->assemble($promptTurn,$promptSelection);$repeat=$assembler->assemble($promptTurn,$promptSelection);
$systemMessage=$assembled['provider_input']['_messages'][0]??[];$finalMessage=$assembled['provider_input']['_messages'][array_key_last($assembled['provider_input']['_messages'])]??[];
$check($assembled===$repeat && ($systemMessage['role']??null)==='system'
    &&str_contains((string)($systemMessage['content']??''),'<roleplay_instructions>')
    &&str_contains((string)($systemMessage['content']??''),'<character>')
    &&strpos((string)$systemMessage['content'],'<output_contract>')<strpos((string)$systemMessage['content'],'<npc_context>')
    &&strpos((string)$systemMessage['content'],'<npc_context>')<strpos((string)$systemMessage['content'],'<current_turn>')
    &&($finalMessage['role']??null)==='user', 'CHIM XML prompt assembly is deterministic and role-separated');
$check(str_contains($assembled['provider_input']['_assembled_prompt'],'<player_character>')
    &&str_contains($assembled['provider_input']['_assembled_prompt'],'Freed from the Imperial prison.')
    &&!str_contains($assembled['provider_input']['_assembled_prompt'],'not prompt-safe'),
    'server-owned player profile is included in turn context with an explicit field allowlist');
$check(str_contains($assembled['provider_input']['_assembled_prompt'],'<record_descriptions>')
    &&str_contains($assembled['provider_input']['_assembled_prompt'],'A short iron blade.')
    &&!str_contains($assembled['provider_input']['_assembled_prompt'],'not prompt-safe either'),
    'server-owned record descriptions are included with an explicit field allowlist');
$check(str_contains((string)$systemMessage['content'],'Curious &amp; wary &lt;Bosmer&gt;')
    &&str_contains((string)$systemMessage['content'],'<name>RANGROO</name>')
    &&!str_contains((string)$systemMessage['content'],'<name>Nerevarine</name>')
    &&!str_contains((string)$systemMessage['content'],'DISABLED NARRATOR SENTINEL'),
    'XML escaping, live player identity, and disabled narrator filtering are stable');
$contextTurn=$promptTurn;
$contextTurn['payload']['context']=[
    'world'=>['cell'=>'Seyda Neen','cell_identity'=>['kind'=>'exterior','grid_x'=>-2,'grid_y'=>-9],
        'region'=>'Bitter Coast','weather'=>['record_id'=>'cloudy','name'=>'Cloudy','is_storm'=>false],
        'calendar'=>['year'=>427,'month'=>6,'month_name'=>"Sun's Height",'day'=>16,'time'=>'14:30']],
    'nearbyActors'=>['items'=>[
        $contextTurn['payload']['target'],
        ['kind'=>'npc','record_id'=>'chargen_boat_guard_1','content_file'=>'Morrowind.esm','display_name'=>'Guard',
            'distance'=>640,'equipment'=>[['slot'=>'carried_right','record_id'=>'iron_saber','display_name'=>'Iron Saber']]],
    ]],
    'actorActivities'=>['items'=>[['actor'=>['record_id'=>'chargen_boat_guard_1','content_file'=>'Morrowind.esm'],
        'activity'=>'wander']]],
    'nearbyObjects'=>['items'=>[
        ['kind'=>'items','record_id'=>'ingred_bc_bungler_bane_01','display_name'=>'Bungler\'s Bane','count'=>2,'distance'=>120,
            'position'=>['x'=>1,'y'=>2,'z'=>3]],
        ['kind'=>'doors','record_id'=>'in_c_door_arched','display_name'=>'Census and Excise Office','distance'=>300,
            'lock'=>['locked'=>true,'level'=>20]],
    ]],
];
$contextTurn['_nearby_actor_profiles']=[['actor_identity'=>['kind'=>'npc','record_id'=>'chargen_boat_guard_1',
    'content_file'=>'Morrowind.esm','display_name'=>'Guard'],'content'=>['biography'=>'A watchful Imperial guard.']]];
$contextPrompt=(new PromptAssembler(16384,1024))->assemble($contextTurn,$promptSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($contextPrompt,'<world><location>Seyda Neen</location>')
    &&str_contains($contextPrompt,'<date>16 Sun&apos;s Height 3E 427</date>')
    &&str_contains($contextPrompt,'<people_present>')&&str_contains($contextPrompt,'<nearby_actors>')
    &&str_contains($contextPrompt,'<current_activity>wander</current_activity>')
    &&str_contains($contextPrompt,'<basic_summary>A watchful Imperial guard.</basic_summary>')
    &&str_contains($contextPrompt,'<nearby_items>')&&str_contains($contextPrompt,'<count>2</count>')
    &&str_contains($contextPrompt,'<points_of_interest>')&&str_contains($contextPrompt,'<lock_level>20</lock_level>')
    &&!str_contains($contextPrompt,'&quot;position&quot;')&&!str_contains($contextPrompt,'&quot;x&quot;'),
    'OpenMW world, actors, items, and points of interest render as bounded semantic CHIM XML');
$knowledgeSelection=$promptSelection;
$knowledgeSelection['knowledge_retrieval']=['status'=>'fallback_grounded'];
$knowledgeSelection['knowledge']=[['document_id'=>'oghma-auriel','topic'=>'auriel_s_bow','access_level'=>'basic',
    'content'=>'Auriel\'s Bow is an ancient artifact associated with the elven god Auri-El.'],
    ['document_id'=>'oghma-sixth-house','topic'=>'sixth_house','access_level'=>'denied','content'=>'']];
$knowledgePrompt=(new PromptAssembler(16384,1024))->assemble($promptTurn,$knowledgeSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($knowledgePrompt,'<oghma_context><oghma contract="oghma-parity-v1" status="fallback_grounded">')
    &&str_contains($knowledgePrompt,'<article topic="auriel_s_bow" source="conversation" access="basic">')
    &&str_contains($knowledgePrompt,'Auriel&apos;s Bow is an ancient artifact')
    &&str_contains($knowledgePrompt,'<article topic="sixth_house" source="conversation" access="denied">')
    &&str_contains($knowledgePrompt,'<denial reason="knowledge_classes_not_authorized" />'),
    'authorized and denied Oghma knowledge use the parity XML prompt section');
$providerMessages=(new ReflectionMethod($actionProvider,'promptMessages'))->invoke($actionProvider,
    ['_prompt'=>$assembled['provider_input']]);
$check(array_column($providerMessages,'role')===array_column($assembled['provider_input']['_messages'],'role')
    &&str_contains($providerMessages[0]['content'],'<action_contract>')
    &&str_contains($providerMessages[0]['content'],'exactly one key named &quot;text&quot;')
    &&strpos($providerMessages[0]['content'],'<action_contract>')<strpos($providerMessages[0]['content'],'<current_turn>')
    &&str_ends_with($providerMessages[0]['content'],'</roleplay_context>'),
    'OpenAI-compatible provider sends the frozen split messages with its action contract inside the XML root');
$validateProviderResult=new ReflectionMethod($actionProvider,'validateResultShape');
$validateProviderResult->invoke($actionProvider,['utterances'=>[['text'=>'Hello, outlander.']],'action'=>null]);
try{$validateProviderResult->invoke($actionProvider,['utterances'=>['Hello, outlander.'],'action'=>null]);
    $check(false,'provider accepted string utterances outside the typed response contract');
}catch(RuntimeException$error){$check($error->getMessage()==='provider_invalid_output',
    'provider rejected malformed utterances with the wrong terminal code');}
$check($assembled['trace']['input_bytes']<=4096 && !array_key_exists('content',$assembled['trace']['sources'][0])
    &&str_contains($assembled['trace']['sources'][0]['redacted_preview'],'content redacted')
    &&array_column($assembled['trace']['sections'],'section_order')===range(1,11)
    &&array_column($assembled['trace']['sections'],'section_key')===['output_contract','npc_context','player_narrator_context',
        'morrowind_context','oghma_context','relationships_factions','memory_context','conversation_context','audience_speaker_rules',
        'negotiated_actions','current_turn'], 'prompt trace is bounded, redacted, and records all ordered sections');
$check($assembled['trace']['sources'][2]['source_kind']==='memory' && $assembled['trace']['sources'][3]['source_kind']==='action_result', 'prompt source order is stable');
$historySelection=$promptSelection;$historySelection['memory']=[];$historySelection['recent_action_results']=[];
$historySelection['history']=[
    ['history_id'=>'old-history','content'=>str_repeat('O',1000)],
    ['history_id'=>'middle-history','content'=>str_repeat('M',1000)],
    ['history_id'=>'recent-history','content'=>'RECENT HISTORY SENTINEL'],
];
$budgetedHistory=(new PromptAssembler(512,256))->assemble($promptTurn,$historySelection);
$recentHistorySource=array_values(array_filter($budgetedHistory['trace']['sources'],
    static fn(array$source):bool=>$source['source_id']==='recent-history'));
$check(!str_contains($budgetedHistory['provider_input']['_assembled_prompt'],'RECENT HISTORY SENTINEL')
    &&count($recentHistorySource)===1&&!$recentHistorySource[0]['included'],
    'compact history yields to the current turn when the prompt budget is exhausted');
$largeContextTurn=$promptTurn;$largeContextTurn['payload']['context']=['inventory'=>str_repeat('X',2048)];
$currentTurnSelection=$promptSelection;$currentTurnSelection['memory']=[];$currentTurnSelection['recent_action_results']=[];
$budgetedTurn=(new PromptAssembler(2048,1024))->assemble($largeContextTurn,$currentTurnSelection);
$check(($budgetedTurn['provider_input']['_messages'][array_key_last($budgetedTurn['provider_input']['_messages'])]['content']??null)==="RANGROO: Hello\n\nRespond as Fargoth. Write Fargoth's next dialogue line; do not write dialogue for RANGROO."
    &&!str_contains($budgetedTurn['provider_input']['_assembled_prompt'],str_repeat('X',128)),
    'current input was displaced by the large OpenMW context snapshot');
$roleHistory=$promptSelection;$roleHistory['memory']=[];$roleHistory['recent_action_results']=[];
$roleHistory['history']=[
    ['history_id'=>'player-line','content'=>['kind'=>'event','type'=>'turn.requested','turn_id'=>'old-turn','input'=>['text'=>'Where is my ring?'],'speaker'=>['record_id'=>'player','display_name'=>'RANGROO']]],
    ['history_id'=>'fargoth-line','content'=>['kind'=>'speech','text'=>'I have not seen it.','speaker'=>'Fargoth','speaker_identity'=>['record_id'=>'npc','display_name'=>'Fargoth']]],
    ['history_id'=>'guard-line','content'=>['kind'=>'speech','text'=>'Move along.','speaker'=>'Guard','speaker_identity'=>['record_id'=>'guard','display_name'=>'Guard']]],
    ['history_id'=>'smoke-line','content'=>['kind'=>'event','type'=>'turn.requested','turn_id'=>'smoke-turn','input'=>['text'=>'Automated ALMSIVI smoke test.'],'speaker'=>['display_name'=>'RANGROO']]],
];
$rolePrompt=(new PromptAssembler(8192,1024))->assemble($promptTurn,$roleHistory)['provider_input'];
$roleMessages=$rolePrompt['_messages'];
$check(array_column($roleMessages,'role')===['system','user']
    &&str_contains($roleMessages[0]['content'],'<message>RANGROO: Where is my ring?</message>')
    &&str_contains($roleMessages[0]['content'],'<message>Fargoth: I have not seen it.</message>')
    &&str_contains($roleMessages[0]['content'],'<message>Guard: Move along.</message>')
    &&substr_count($rolePrompt['_assembled_prompt'],'Where is my ring?')===1
    &&!str_contains(json_encode($roleMessages,JSON_THROW_ON_ERROR),'smoke test'),
    'compact chat history is included once with explicit speakers and control noise filtered');
$semanticHistory=$promptSelection;$semanticHistory['memory']=[];$semanticHistory['recent_action_results']=[];
$semanticHistory['history']=[
    ['history_id'=>'location-event','content'=>['kind'=>'event','type'=>'location','details'=>['location'=>'Seyda Neen']]],
    ['history_id'=>'weather-event','content'=>['kind'=>'event','type'=>'weather','details'=>['weather'=>'Cloudy']]],
    ['history_id'=>'journal-event','content'=>['kind'=>'event','type'=>'quest','details'=>['text'=>'Report to Caius Cosades.']]],
];
$semanticText=(new PromptAssembler(8192,1024))->assemble($promptTurn,$semanticHistory)['provider_input']['_assembled_prompt'];
$check(str_contains($semanticText,'[Location] The player entered Seyda Neen.')
    &&str_contains($semanticText,'[Weather] The weather changed to Cloudy.')
    &&str_contains($semanticText,'[Journal] Report to Caius Cosades.')
    &&!str_contains($semanticText,'\\"details\\"'),
    'world and journal history is semantic text rather than raw event JSON');

$largeWorldTurn=$promptTurn;
$largeWorldTurn['payload']['context']=['world'=>['cell'=>'WORLD CONTEXT SENTINEL '.str_repeat('W',6000)]];
$protectedKnowledge=(new PromptAssembler(4096,1024))->assemble($largeWorldTurn,$knowledgeSelection);
$protectedSections=array_column($protectedKnowledge['trace']['sections'],'inclusion_reason','section_key');
$check(!str_contains($protectedKnowledge['provider_input']['_assembled_prompt'],'WORLD CONTEXT SENTINEL')
    &&str_contains($protectedKnowledge['provider_input']['_assembled_prompt'],'<oghma_context><oghma')
    &&($protectedSections['morrowind_context']??null)==='byte_limit'
    &&($protectedSections['oghma_context']??null)==='included',
    'Oghma remains in its protected section when lower-priority Morrowind context is trimmed');

$identity=static fn(string$kind,string$id,int$index,string$name):array=>['kind'=>$kind,'record_id'=>$id,
    'refnum'=>['index'=>$index,'content_file'=>0],'content_file'=>'Morrowind.esm',
    'cell'=>['kind'=>'exterior','grid_x'=>-2,'grid_y'=>-9],'display_name'=>$name];
$canonicalTurn=['installation_id'=>'10000000-0000-4000-8000-000000000001',
    'profile_id'=>'10000000-0000-4000-8000-000000000002','playthrough_id'=>'10000000-0000-4000-8000-000000000003',
    'session_id'=>'10000000-0000-4000-8000-000000000004','turn_id'=>'10000000-0000-4000-8000-000000000005',
    'request_id'=>'10000000-0000-4000-8000-000000000006','generation'=>7,'runtime_generation'=>4,
    'payload'=>['speaker'=>$identity('player','player',0,'Nerevarine'),
        'target'=>$identity('npc','fargoth',112,'Fargoth'),'audience'=>[],
        'context'=>['rechat'=>['rechat_depth'=>2]]]];
$canonicalResult=(new CanonicalResponseNormalizer())->normalize($canonicalTurn,
    ['utterances'=>[['text'=>'You found my engraved ring—thank you!']],
        'action'=>['name'=>'ai.follow','tier'=>1,'actor'=>$canonicalTurn['payload']['target'],
            'target'=>$canonicalTurn['payload']['speaker'],'parameters'=>['distance'=>192]]]);
$validator->validate($canonicalResult,'almsivi.response.v1');
$check($canonicalResult['request_id']===$canonicalTurn['request_id']&&$canonicalResult['runtime_generation']===4
    &&array_column($canonicalResult['lines'],'action')===['say','rolecommand']
    &&$canonicalResult['lines'][0]['text']==='You found my engraved ring—thank you!'
    &&$canonicalResult['lines'][1]['command_args']===['distance=192'],
    'provider result normalizes once into ordered UTF-8 response lines with full correlation');
$failedCanonical=(new CanonicalResponseNormalizer())->failure($canonicalTurn,'provider_unavailable');
$validator->validate($failedCanonical,'almsivi.response.v1');
$check($failedCanonical['ok']===false&&$failedCanonical['lines']===[]
    &&$failedCanonical['error']==='provider_unavailable','terminal failure response is canonical and empty');
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
$check(count($ttsCatalog)===22 && count($sttCatalog)===8
    &&in_array('none',array_column($sttCatalog,'driver'),true), 'CHIM-lineage TTS and STT connector catalogs are complete');
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
$sttRequest=new ReflectionMethod(CloudSpeechToTextConnectorProvider::class,'request');
$deepgramRequest=$sttRequest->invoke(new CloudSpeechToTextConnectorProvider('https://api.deepgram.com','deepgram','nova-3','secret'),
    str_repeat("\0",44),'en-US');
$check(str_contains($deepgramRequest[0],'/v1/listen?')&&str_contains($deepgramRequest[0],'filler_words=true')
    &&in_array('Authorization: Token secret',$deepgramRequest[2],true)&&$deepgramRequest[1]===str_repeat("\0",44),
    'Deepgram STT uses the CHIM raw-WAV query and Token authorization contract');
$azureRequest=$sttRequest->invoke(new CloudSpeechToTextConnectorProvider('https://westus.stt.speech.microsoft.com','azure','default','secret',['profanity'=>'raw']),
    str_repeat("\0",44),'en-US');
$check(str_contains($azureRequest[0],'/speech/recognition/conversation/cognitiveservices/v1?')
    &&str_contains($azureRequest[0],'language=en-US')&&str_contains($azureRequest[0],'profanity=raw')
    &&in_array('Ocp-Apim-Subscription-Key: secret',$azureRequest[2],true),
    'Azure STT uses the short-audio WAV endpoint and subscription-key contract');
$inworldRequest=$sttRequest->invoke(new CloudSpeechToTextConnectorProvider('https://api.inworld.ai','inworld','groq/whisper-large-v3','secret'),
    str_repeat("\0",44),'en-US');$inworldBody=json_decode($inworldRequest[1],true);
$check($inworldRequest[0]==='https://api.inworld.ai/stt/v1/transcribe'
    &&($inworldBody['transcribeConfig']['sampleRateHertz']??null)===16000
    &&($inworldBody['audioData']['content']??null)===base64_encode(str_repeat("\0",44))
    &&in_array('Authorization: Basic secret',$inworldRequest[2],true),
    'Inworld STT uses the CHIM transcribeConfig and base64 audioData contract');
$geminiRequest=$sttRequest->invoke(new CloudSpeechToTextConnectorProvider('https://generativelanguage.googleapis.com','gemini','gemini-2.5-flash','secret'),
    str_repeat("\0",44),'en');$geminiBody=json_decode($geminiRequest[1],true);
$check(str_contains($geminiRequest[0],'/v1beta/models/gemini-2.5-flash:generateContent?key=secret')
    &&($geminiBody['contents'][0]['parts'][1]['inline_data']['mime_type']??null)==='audio/wav'
    &&($geminiBody['generationConfig']['responseMimeType']??null)==='application/json',
    'Gemini STT uses the CHIM inline-audio generateContent JSON contract');
$multipartPath=tempnam(sys_get_temp_dir(),'almsivi-stt-fields-');file_put_contents($multipartPath,str_repeat("\0",44));
$multipartFields=new ReflectionMethod(OpenAiCompatibleSpeechToTextProvider::class,'multipartFields');
$localFields=$multipartFields->invoke(new OpenAiCompatibleSpeechToTextProvider('http://127.0.0.1:9876/api/v0/transcribe',
    ['127.0.0.1'],'whisper-1','',30000,true,'audio_file',false),$multipartPath,'en-US');
$parakeetFields=$multipartFields->invoke(new OpenAiCompatibleSpeechToTextProvider('http://127.0.0.1:8022/v1/audio/transcriptions',
    ['127.0.0.1'],'whisper-1','secret',30000,true,'file',true,'ALMSIVI,Nerevarine,Morrowind'),$multipartPath,'en-US');
$translationFields=$multipartFields->invoke(new OpenAiCompatibleSpeechToTextProvider('https://api.openai.com/v1/audio/translations',
    ['api.openai.com'],'whisper-1','secret',30000,false,'file',true,'',false),$multipartPath,'fr-FR');
$check(array_keys($localFields)===['audio_file']
    &&array_keys($parakeetFields)===['file','model','prompt','language']
    &&$parakeetFields['model']==='whisper-1'&&$parakeetFields['language']==='en'
    &&array_keys($translationFields)===['file','model'],
    'LocalWhisper, Parakeet, Whisper transcription, and Whisper translation multipart fields match CHIM');
unlink($multipartPath);
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

$globalSettings=EffectiveSettingsResolver::defaults();
$globalSettings['behavior']['rechat']=true;
$globalSettings['memory']['knowledge_limit']=5;
$coreLayer=['settings_overrides'=>['behavior'=>['rechat'=>false],'memory'=>['knowledge_limit'=>0],
    'oghma'=>['topic_count'=>2,'racial_context_enabled'=>false]],
    'routing'=>['llm_configuration_id'=>'00000000-0000-4000-8000-000000000111','oghma_configuration_id'=>'00000000-0000-4000-8000-000000000222']];
$globalSettings['behavior']['auto_greeting']=true;
$globalSettings['narrator']['welcome_events']=true;
$coreLayer['settings_overrides']['behavior']['rechat_allow_actions']=true;
$npcLayer=['settings_overrides'=>['behavior'=>['combat_barks'=>true],'oghma'=>['topic_count'=>3]],'routing'=>['llm_configuration_id'=>''],
    'oghma_knowledge_tags'=>''];
$effective=(new EffectiveSettingsResolver())->resolve($globalSettings,$coreLayer,$npcLayer,[
    'enabled'=>true,'topic_count'=>1,'result_limit'=>3,'racial_context_enabled'=>true,
    'location_context_enabled'=>true,'extractor_fallback_enabled'=>false,'extractor_timeout_ms'=>1500]);
$check($effective['settings']['behavior']['rechat']===false
    && $effective['settings']['memory']['knowledge_limit']===0
    && $effective['routing']['llm_configuration_id']===''
    && $effective['routing']['oghma_configuration_id']==='00000000-0000-4000-8000-000000000222',
    'Global to Core Profile to NPC resolution preserves explicit false, zero, and empty overrides');
$check($effective['settings']['oghma']['topic_count']===3
    &&$effective['settings']['oghma']['racial_context_enabled']===false
    &&($effective['sources']['settings.oghma.topic_count']??null)==='npc'
    &&($effective['sources']['settings.oghma.racial_context_enabled']??null)==='core_profile'
    &&($effective['sources']['settings.oghma.result_limit']??null)==='global',
    'Oghma controls use Global to Core Profile to NPC inheritance with per-field sources');
$check($effective['settings']['memory']['oghma_knowledge_tags']===''
    &&($effective['sources']['settings.memory.oghma_knowledge_tags']??null)==='server_default',
    'blank generated NPC knowledge tags inherit the empty installation default');
$check($effective['settings']['behavior']['auto_greeting']===false
    && $effective['settings']['behavior']['rechat_allow_actions']===false
    && $effective['settings']['behavior']['combat_barks']===false
    && $effective['settings']['narrator']['welcome_events']===false
    && ($effective['sources']['settings.behavior.combat_barks']??null)==='excluded',
    'excluded automation compatibility fields cannot become effective');
$check(($effective['sources']['settings.behavior.rechat']??null)==='core_profile'
    && ($effective['sources']['routing.llm_configuration_id']??null)==='npc'
    && preg_match('/^[0-9a-f]{64}$/D',$effective['sha256'])===1,
    'effective settings retain per-field provenance and a canonical hash');
try{
    EffectiveSettingsResolver::validateSettingsOverrides(['behavior'=>['unknown_setting'=>true]]);
    $check(false,'unknown layered setting rejected');
}catch(InvalidArgumentException){$check(true,'unknown layered setting rejected');}

$mediaRoot = sys_get_temp_dir() . '/almsivi-media-unit-' . bin2hex(random_bytes(8));
$media = new MediaStore($mediaRoot, 1024, 2048);
$speech = (new MockSpeechProvider())->synthesize('deterministic', new NeverCancelledToken());
$check(strlen($speech['bytes']) === 204 && substr($speech['bytes'], 0, 4) === 'RIFF', 'mock TTS emits legal tiny WAV');
$check(OpenAiCompatibleSpeechProvider::wavDurationMs($speech['bytes']) === 20, 'live TTS validates WAV framing and duration');
$mediaId = '00000000-0000-4000-8000-000000000099';
$mediaHash = $media->put($mediaId, $speech['bytes'], $speech['codec'], $speech['mime_type']);
$check((fileperms($mediaRoot) & 0777) === 0770, 'private media keeps the Apache and worker shared directory writable');
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

foreach (['deploy-local-wsl.sh', 'deploy-wsl.sh'] as $scriptName) {
    $deployScript = file_get_contents(dirname(__DIR__) . '/scripts/' . $scriptName);
    $check($deployScript !== false && str_contains($deployScript,
        'install -d -o almsivi -g www-data -m 2770 /var/lib/almsiviserver/media'),
        $scriptName . ' preserves shared media write access');
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} server checks failed\n");
    exit(1);
}
echo "{$checks} server checks passed\n";
