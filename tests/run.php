<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoload.php';
require __DIR__ . '/Support/StateStore.php';

use LorkhanServer\Config\Settings;
use LorkhanServer\Application\ActionPolicyValidator;
use LorkhanServer\Application\ConnectorCatalog;
use LorkhanServer\Application\CredentialStore;
use LorkhanServer\Application\LlmConnector;
use LorkhanServer\Application\CloudSpeechConnectorProvider;
use LorkhanServer\Application\CloudSpeechToTextConnectorProvider;
use LorkhanServer\Application\CanonicalResponseNormalizer;
use LorkhanServer\Application\MockSpeechProvider;
use LorkhanServer\Application\PocketTtsSpeechProvider;
use LorkhanServer\Application\LocalSpeechConnectorProvider;
use LorkhanServer\Application\MockOghmaTopicExtractor;
use LorkhanServer\Application\MorrowindGeographyCatalog;
use LorkhanServer\Application\OghmaGroundedRetriever;
use LorkhanServer\Application\NeverCancelledToken;
use LorkhanServer\Application\OpenAiCompatibleProvider;
use LorkhanServer\Application\StreamingDialogueText;
use LorkhanServer\Application\OpenAiCompatibleSpeechProvider;
use LorkhanServer\Application\OpenAiCompatibleSpeechToTextProvider;
use LorkhanServer\Application\PromptAssembler;
use LorkhanServer\Application\SpeechPreviewCatalog;
use LorkhanServer\Application\PlayerMoodPolicy;
use LorkhanServer\Application\MemoryPromptSelection;
use LorkhanServer\Application\InlineNarrationRouter;
use LorkhanServer\Application\DialoguePlanner;
use LorkhanServer\Application\DeepLTranslationProvider;
use LorkhanServer\Application\EffectiveSettingsResolver;
use LorkhanServer\Application\SettingsCatalog;
use LorkhanServer\Application\ProviderFactory;
use LorkhanServer\Application\TranslationPolicy;
use LorkhanServer\Application\ZonosGradioSpeechProvider;
use LorkhanServer\Application\XvaSynthSpeechProvider;
use LorkhanServer\Http\Response;
use LorkhanServer\Infrastructure\MediaStore;
use LorkhanServer\Tests\Support\StateStore;
use LorkhanServer\Protocol\ValidationException;
use LorkhanServer\Protocol\Validator;
use LorkhanServer\Security\PairingToken;
use LorkhanServer\Security\Redactor;
use LorkhanServer\Security\RequestMac;
use LorkhanServer\Http\Request;

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
$unsigned=new Request('POST','/LorkhanServer/api/v1/turns',['Content-Type'=>'application/json; charset=utf-8'],[],'{}');$digest=RequestMac::bodyDigest($unsigned->body);$signature=RequestMac::sign($key,$unsigned,$installation,$timestamp,$nonce,'application/json; charset=utf-8',$digest);
$signed=new Request($unsigned->method,$unsigned->path,$unsigned->headers+['X-LORKHAN-Auth'=>RequestMac::ALGORITHM,'X-LORKHAN-Installation-Id'=>$installation,'X-LORKHAN-Timestamp'=>$timestamp,'X-LORKHAN-Nonce'=>$nonce,'X-LORKHAN-Content-SHA256'=>$digest,'X-LORKHAN-Signature'=>$signature],[],$unsigned->body);
$check(RequestMac::verify($signed,$key)===$installation,'request MAC binds method target content installation timestamp and nonce');
$check(RequestMac::verify(new Request('GET',$signed->path,$signed->headers,[],$signed->body),$key)===false,'request MAC rejects method tampering');

$settings = Settings::fromArray(['pairing_token_hash' => $hash, 'storage_path' => sys_get_temp_dir() . '/lorkhan-test']);
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
$inheritedLlm=['driver'=>'configured','model'=>'existing-model'];
$relationshipOutput=['disposition_delta'=>2,'affinity_delta'=>-1,'reason'=>'A witnessed disagreement.'];
$check(\LorkhanServer\Application\RelationshipEvaluationPolicy::output($relationshipOutput)===$relationshipOutput
    &&!\LorkhanServer\Application\RelationshipEvaluationPolicy::eligible(0,'one-response')
    &&\LorkhanServer\Application\RelationshipEvaluationPolicy::eligible(100,'one-response'),
    'relationship output and automatic chance boundaries');
foreach([['disposition_delta'=>11],['affinity_delta'=>'1'],['reason'=>"bad\0reason"],['actor'=>'somebody else']]as$invalidChange){
    try{\LorkhanServer\Application\RelationshipEvaluationPolicy::output(array_replace($relationshipOutput,$invalidChange));$check(false,'unsafe relationship output accepted');}
    catch(InvalidArgumentException){$check(true,'unsafe relationship output rejected');}
}
$availableTypes=\LorkhanServer\Application\RelationshipType::available(['trusted_companion','romance']);
$check(\LorkhanServer\Application\RelationshipType::manual(' Married ')==='romantic'
    &&in_array('trusted_companion',$availableTypes,true)
    &&\LorkhanServer\Application\RelationshipType::model('trusted_companion',$availableTypes,40,'Earned trust')==='trusted_companion'
    &&\LorkhanServer\Application\RelationshipType::model('invented_by_model',$availableTypes,90,'Invented')===null
    &&\LorkhanServer\Application\RelationshipType::model('romantic',$availableTypes,55,'Too soon')===null
    &&\LorkhanServer\Application\RelationshipType::model('romantic',$availableTypes,56,'A defining confession')==='romantic'
    &&\LorkhanServer\Application\RelationshipType::model('crush',$availableTypes,10,'Changed nuance','romantic')==='crush',
    'relationship types canonicalize manual aliases and fence model choices');
foreach([null,'two words','-enemy',str_repeat('x',51)]as$invalidType){
    try{\LorkhanServer\Application\RelationshipType::manual($invalidType);$check(false,'invalid manual relationship type accepted');}
    catch(InvalidArgumentException){$check(true,'invalid manual relationship type rejected');}
}
$buildRow=['target_key'=>str_repeat('a',64),'disposition'=>-100,'affinity'=>100,'reason'=>'A witnessed pattern.'];
$check(\LorkhanServer\Application\RelationshipCustomInfo::validate('')===''
    &&\LorkhanServer\Application\RelationshipCustomInfo::validate(str_repeat('古',2000))===str_repeat('古',2000),
    'private relationship text is optional and Unicode bounded');
foreach([null,[],"bad\0note",str_repeat('x',2001)] as $badNote){
    try{\LorkhanServer\Application\RelationshipCustomInfo::validate($badNote);$check(false,'invalid custom info accepted');}
    catch(InvalidArgumentException){$check(true,'invalid custom info rejected');}
}
$legacyRelationshipIdentity=['record_id'=>'legacy_actor','display_name'=>'Legacy actor'];
$check(\LorkhanServer\Application\RelationshipIdentity::validate($legacyRelationshipIdentity,true)===$legacyRelationshipIdentity,
    'restore accepts a bounded legacy relationship identity');
foreach([[$legacyRelationshipIdentity,false],[['kind'=>'invented','record_id'=>'bad'],true]]as[$badIdentity,$allowLegacy]){
    try{\LorkhanServer\Application\RelationshipIdentity::validate($badIdentity,$allowLegacy);$check(false,'invalid relationship identity accepted');}
    catch(InvalidArgumentException){$check(true,'invalid relationship identity rejected');}
}
$check(\LorkhanServer\Application\RelationshipBuildPolicy::output(['relationships'=>[$buildRow]])===['relationships'=>[$buildRow]],
    'history build accepts bounded absolute scores');
$typedBuildRow=$buildRow+['relationship_type'=>'rival'];
$check(\LorkhanServer\Application\RelationshipBuildPolicy::output(['relationships'=>[$typedBuildRow]])===['relationships'=>[$typedBuildRow]]
    &&\LorkhanServer\Application\RelationshipEvaluationPolicy::output($relationshipOutput+['relationship_type'=>'suspicious'])
        ===$relationshipOutput+['relationship_type'=>'suspicious'],
    'relationship workers accept one optional bounded type proposal');
foreach([['relationships'=>[$buildRow,$buildRow]],['relationships'=>[array_replace($buildRow,['disposition'=>101])]],
    ['relationships'=>[array_replace($buildRow,['target_key'=>'Fargoth'])]],['relationships'=>[],'action'=>'follow']] as $invalidBuild){
    try{\LorkhanServer\Application\RelationshipBuildPolicy::output($invalidBuild);$check(false,'unsafe history build accepted');}
    catch(InvalidArgumentException){$check(true,'unsafe history build rejected');}
}
$conversionMock=(new \LorkhanServer\Application\MockProfileGenerationProvider())->generate(
    ['generation_mode'=>'relationship_text_conversion'],new \LorkhanServer\Application\NeverCancelledToken());
$check($conversionMock===['relationships'=>[]]
    &&in_array('relationship.convert',\LorkhanServer\Application\FirstPartyJobHandlerFactory::jobTypes(),true),
    'relationship text conversion is not registered as a bounded first-party job');
$diaryDefaults=\LorkhanServer\Application\DiaryGenerationPolicy::defaults();
$diaryOverrides=['enabled'=>true,'include_in_context'=>false,'context_turn_limit'=>12,'prompt'=>'Remember only what was witnessed.'];
$diaryMock=(new \LorkhanServer\Application\MockProfileGenerationProvider())->generate(
    ['generation_mode'=>'diary_generation','name'=>'Fargoth','witnessed_context'=>[['type'=>'inputtext']]],new NeverCancelledToken());
$check($diaryDefaults['enabled']===false&&$diaryDefaults['include_in_context']===true&&$diaryDefaults['context_turn_limit']===20
    &&\LorkhanServer\Application\DiaryGenerationPolicy::validateOverrides($diaryOverrides)===$diaryOverrides
    &&$diaryMock===['title'=>'Fargoth diary','content'=>'Fargoth records 1 witnessed Morrowind event.']
    &&in_array('narrative.generate',\LorkhanServer\Application\FirstPartyJobHandlerFactory::jobTypes(),true),
    'manual diary generation is opt-in, bounded, deterministic under the mock provider, and registered as durable work');
foreach([
    ['enabled'=>'true'],['include_in_context'=>1],['context_turn_limit'=>0],['context_turn_limit'=>101],['prompt'=>''],['unknown'=>true],
]as$invalidDiary){
    try{\LorkhanServer\Application\DiaryGenerationPolicy::validateOverrides($invalidDiary);$check(false,'invalid diary settings accepted');}
    catch(InvalidArgumentException){$check(true,'invalid diary settings rejected');}
}
foreach([
    ['title'=>'','content'=>'entry'],['title'=>'entry','content'=>''],['title'=>str_repeat('x',257),'content'=>'entry'],
    ['title'=>'entry','content'=>'entry','action'=>'wait'],
]as$invalidDiaryOutput){
    try{\LorkhanServer\Application\DiaryGenerationPolicy::output($invalidDiaryOutput);$check(false,'invalid diary provider output accepted');}
    catch(RuntimeException){$check(true,'invalid diary provider output rejected');}
}
$memoryPolicy=['schema'=>'lorkhan.memory-policy.v1','enabled'=>false,'provider_configuration_id'=>''];
$check(\LorkhanServer\Application\MemorySummaryPolicy::validate($memoryPolicy)===$memoryPolicy,
    'model memory defaults can stay off without a provider');
foreach([array_replace($memoryPolicy,['enabled'=>true]),array_replace($memoryPolicy,['enabled'=>'true']),
    array_replace($memoryPolicy,['provider_configuration_id'=>'not-a-uuid'])]as$invalidPolicy){
    try{\LorkhanServer\Application\MemorySummaryPolicy::validate($invalidPolicy);$check(false,'invalid model memory policy accepted');}
    catch(InvalidArgumentException){$check(true,'invalid model memory policy rejected');}
}
$embeddingPolicy=\LorkhanServer\Application\MemoryEmbeddingPolicy::defaults();
$loopbackEmbeddingPolicy=['schema'=>\LorkhanServer\Application\MemoryEmbeddingPolicy::SCHEMA,'enabled'=>true,
    'endpoint'=>'http://127.0.0.1:8085/','timeout_ms'=>1500];
$check(\LorkhanServer\Application\MemoryEmbeddingPolicy::validate($embeddingPolicy)===$embeddingPolicy
    &&\LorkhanServer\Application\MemoryEmbeddingPolicy::validate($loopbackEmbeddingPolicy)['endpoint']==='http://127.0.0.1:8085'
    &&in_array('memory.embed',\LorkhanServer\Application\FirstPartyJobHandlerFactory::jobTypes(),true),
    'semantic memory is opt-in, accepts loopback MiniMe, and registers bounded durable work');
foreach([
    array_replace($embeddingPolicy,['enabled'=>true]),
    array_replace($embeddingPolicy,['endpoint'=>'http://192.168.1.5:8085']),
    array_replace($embeddingPolicy,['endpoint'=>'https://user:pass@example.com']),
    array_replace($embeddingPolicy,['timeout_ms'=>5001]),
]as$invalidEmbeddingPolicy){
    try{\LorkhanServer\Application\MemoryEmbeddingPolicy::validate($invalidEmbeddingPolicy);
        $check(false,'invalid semantic memory policy accepted');}
    catch(InvalidArgumentException){$check(true,'invalid semantic memory policy rejected');}
}
$semanticScore=\LorkhanServer\Application\DeterministicRetrieval::promptScore('red mountain',['red','mountain'],[1,0,0,0,0,0,0,0],
    [1,0,0,0,0,0,0,0],[1,0,0,0,0,0,0,0]);
$fallbackScore=\LorkhanServer\Application\DeterministicRetrieval::promptScore('red mountain',['red','mountain'],[1,0,0,0,0,0,0,0],
    [1,0,0,0,0,0,0,0],[1,0]);
$check($semanticScore===['score'=>1.0,'lexical_score'=>1.0,'semantic_score'=>1.0,'source'=>'minime']
    &&$fallbackScore['source']==='deterministic-fallback'
    &&$fallbackScore['score']===\LorkhanServer\Application\DeterministicRetrieval::score('red mountain',['red','mountain'],[1,0,0,0,0,0,0,0]),
    'semantic recall uses cosine only for matching vectors and preserves exact deterministic fallback');
foreach([['summary'=>''],['summary'=>str_repeat('古',1400)],['summary'=>"bad\0text"],['summary'=>'fact','action'=>'follow']]as$invalidSummary){
    try{\LorkhanServer\Application\MemorySummaryPolicy::summary($invalidSummary);$check(false,'invalid model summary accepted');}
    catch(InvalidArgumentException){$check(true,'invalid model summary rejected');}
}
$check(LlmConnector::validate($inheritedLlm)===$inheritedLlm
    &&LlmConnector::requestOptions([],0.7,false)===['temperature'=>0.7,'response_format'=>['type'=>'json_object']],
    'legacy LLM slots retain their content and request defaults without materialized overrides');
$directLlm=['driver'=>'openai-compatible','model'=>'local-model','endpoint'=>'http://127.0.0.1:1234/v1/chat/completions',
    'options'=>['temperature'=>0,'top_p'=>0,'stream'=>false,'json_mode'=>false,'disable_reasoning'=>false,'reasoning_model'=>true]];
$validatedLlm=LlmConnector::validate($directLlm);
$check($validatedLlm['credential']==='none'&&$validatedLlm['timeout_ms']===30000
    &&$validatedLlm['options']['reasoning_model']===true
    &&LlmConnector::requestOptions($validatedLlm['options'],null,true)===['temperature'=>0,'top_p'=>0]
    &&LlmConnector::requestOptions([],null,false)===['response_format'=>['type'=>'json_object']],
    'explicit LLM connectors preserve zero and false while leaving absent sampling parameters to the provider');
$directSlot=['configuration_id'=>'00000000-0000-4000-8000-000000000123','revision'=>1,'content'=>$directLlm];
$check(ProviderFactory::dialogueForSlot(['provider'=>['api_key_env'=>'UNRELATED_SECRET']],$directSlot) instanceof OpenAiCompatibleProvider
    &&ProviderFactory::oghmaTopicExtractorForSlot([],$directSlot) instanceof \LorkhanServer\Application\OpenAiCompatibleOghmaTopicExtractor
    &&ProviderFactory::profileGenerationForSlot(['provider'=>['driver'=>'invalid-runtime','api_key_env'=>'UNRELATED_SECRET']],$directSlot) instanceof \LorkhanServer\Application\OpenAiCompatibleProfileGenerationProvider,
    'dialogue, Oghma and profile generation resolve explicit connectors without inheriting runtime credentials');
$pinned=\LorkhanServer\Security\OutboundUrlPolicy::curlOptions('http://localhost:1234/v1/chat/completions',['localhost'],true,true);
$check($pinned[CURLOPT_RESOLVE]===['localhost:1234:127.0.0.1']&&$pinned[CURLOPT_PROXY]==='',
    'explicit connector requests pin validated addresses and bypass unchecked proxy resolution');
foreach(['127.0.0.1','[::1]','[::ffff:127.0.0.1]','[::ffff:192.168.1.1]']as$privateHost){
    try{\LorkhanServer\Security\OutboundUrlPolicy::validate('https://'.$privateHost.'/v1/chat/completions',[$privateHost]);$check(false,'private HTTPS provider rejected');}
    catch(InvalidArgumentException){$check(true,'private HTTPS provider rejected');}
}
foreach([
    ['endpoint'=>'http://192.168.1.4/v1/chat/completions'],
    ['endpoint'=>'https://api.openai.com/v1/chat/completions?api_key=not-a-real-key'],
    ['endpoint'=>'https://user:password@api.openai.com/v1/chat/completions'],
    ['credential'=>'LORKHAN_PAIRING_TOKEN_HASH'],
    ['options'=>['temperature'=>'0']],['options'=>['temperature'=>INF]],['options'=>['stream'=>0]],
    ['options'=>['max_tokens'=>10,'max_completion_tokens'=>10]],['options'=>['messages'=>[]]],
]as$invalidLlm){
    try{LlmConnector::validate(array_replace($directLlm,$invalidLlm));$check(false,'unsafe or untyped LLM connector rejected');}
    catch(InvalidArgumentException){$check(true,'unsafe or untyped LLM connector rejected');}
}
$streamText=new StreamingDialogueText();$streamChunks=[];
foreach(['{"utterances":[{"text":"Hello there, ','traveler.\\n\\nWelcome to ','Balmora!"}],"action":null}'] as $index=>$chunk)
    foreach($streamText->push($chunk,$index===2) as $delta)$streamChunks[]=$delta;
$check(implode(' ',$streamChunks)==='Hello there, traveler. Welcome to Balmora!',
    'streaming dialogue exposes only decoded utterance text in bounded deltas');
$check($streamChunks===['Hello there, traveler.','Welcome to Balmora!'],
    'streaming dialogue releases complete CHIM-style sentence chunks without blank subtitle lines');
$shortParagraph=new StreamingDialogueText();
$shortParagraphChunks=$shortParagraph->push('{"utterances":[{"text":"First line.\\n\\nSecond line."}],"action":null}',true);
$check($shortParagraphChunks===['First line. Second line.'],
    'short streamed paragraphs collapse internal line breaks instead of creating blank menu subtitles');
$longStreamText=new StreamingDialogueText();
$longStreamSource=implode(' ',[
    'First sentence arrives without delay.','Second sentence stays independently queued.',
    'Third sentence remains a readable subtitle.','Fourth sentence does not absorb the rest.',
    'Fifth sentence continues the same stream.','Sixth sentence is still its own audio item.',
    'Seventh sentence remains separately visible.','Eighth sentence finishes the response.',
]);
$longStreamJson=json_encode(['utterances'=>[['text'=>$longStreamSource]],'action'=>null],JSON_THROW_ON_ERROR);
$longStreamChunks=$longStreamText->push($longStreamJson,true);
$check(count($longStreamChunks)===8&&implode(' ',$longStreamChunks)===$longStreamSource,
    'streaming dialogue keeps long replies sentence-sized after the fourth chunk');
$check(max(array_map('strlen',$longStreamChunks))<80,
    'streaming dialogue does not collapse a long final tail into one subtitle');
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
$check(ProviderFactory::dialogue([]) instanceof \LorkhanServer\Application\MockProvider
    && ProviderFactory::speech([]) instanceof MockSpeechProvider
    && ProviderFactory::speechToText([]) instanceof \LorkhanServer\Application\MockSpeechToTextProvider,
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
$mockActionProvider=new \LorkhanServer\Application\MockProvider();
foreach ([
    ['Check your inventory.','action.inventory.inspect','inventory.inspect',[]],
    ['Come closer.','action.ai.approach','ai.approach',[]],
    ['Wait here.','action.ai.wait','ai.wait',['duration_seconds'=>3600]],
] as [$text,$capability,$name,$parameters]) {
    $mockAction=$mockActionProvider->complete(['payload'=>[
        'input'=>['text'=>$text],'target'=>['record_id'=>'fargoth'],'speaker'=>['record_id'=>'player'],
    ],'_negotiated_capabilities'=>[$capability]],new \LorkhanServer\Application\NeverCancelledToken())['action']??null;
    $check(is_array($mockAction)&&($mockAction['name']??null)===$name&&($mockAction['parameters']??null)===$parameters,
        "mock provider emits {$name} only through its negotiated capability");
}
$response = Response::error(401, 'unauthorized', 'correlation');
$decodedError = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
$check($decodedError['message'] === 'Request rejected', 'generic client error');

$validator = new Validator();
$fixtureRoot = dirname(__DIR__) . '/protocol/fixtures/v1/valid';
foreach ([
    'session-init.json' => 'lorkhan.session.init.v1',
    'turn.json' => 'lorkhan.turn.v1',
    'gamedata-captured-dialogue.json' => 'lorkhan.gamedata.v1',
    'interrupt.json' => 'lorkhan.interrupt.v1',
    'controls-query.json' => 'lorkhan.controls.query.v1',
    'controls-select.json' => 'lorkhan.controls.select.v1',
    'debug-command-query.json' => 'lorkhan.debug-command.query.v1',
    'debug-command-result.json' => 'lorkhan.debug-command-result.v1',
] as $fixture => $schema) {
    $document = json_decode((string) file_get_contents($fixtureRoot . '/' . $fixture), true, 64, JSON_THROW_ON_ERROR);
    $validator->validate($document['instance'], $schema);
    $check(true, $fixture . ' validates');
}
$actorProfileGameData=json_decode((string)file_get_contents($fixtureRoot.'/gamedata-captured-dialogue.json'),true,64,JSON_THROW_ON_ERROR)['instance'];
$actorProfileGameData['type']='actor_profile';
$actorProfileGameData['payload']=['actor'=>$actorProfileGameData['payload']['speaker'],'race'=>'Wood Elf',
    'class'=>'Commoner','gender'=>'male','level'=>1,'disposition'=>50,'factions'=>['fighters guild']];
$validator->validate($actorProfileGameData,'lorkhan.gamedata.v1');
$check(true,'auto-activated NPC profile snapshot validates');
$creatureActorProfile=$actorProfileGameData;$creatureActorProfile['payload']['actor']['kind']='creature';
$creatureActorProfile['payload']['race']='Creature';$creatureActorProfile['payload']['class']='';
$creatureActorProfile['payload']['gender']='none';$creatureActorProfile['payload']['disposition']=0;
$validator->validate($creatureActorProfile,'lorkhan.gamedata.v1');
$check(true,'auto-activated creature profile snapshot validates');
$menuDialogueRequest = [
    'schema' => 'lorkhan.menu-dialogue-tts.v1',
    'message_id' => '00000000-0000-4000-8000-000000000031',
    'request_id' => '00000000-0000-4000-8000-000000000032',
    'session_id' => '00000000-0000-4000-8000-000000000033',
    'generation' => 7,
    'created_at' => '2026-08-29T12:00:00Z',
    'actor' => array_replace($fargoth, ['display_name' => 'Fargoth']),
    'text' => 'I have a feeling you and I are about to become very close.',
];
$validator->validate($menuDialogueRequest, 'lorkhan.menu-dialogue-tts.v1');
$check(true, 'bounded menu dialogue TTS request validates');
try {
    $validator->validate(array_replace($menuDialogueRequest, ['text' => '']), 'lorkhan.menu-dialogue-tts.v1');
    $check(false, 'empty menu dialogue TTS request rejected');
} catch (ValidationException $exception) {
    $check($exception->getMessage() === 'invalid_schema', 'empty menu dialogue TTS request rejected');
}
$directActionTurn=json_decode((string)file_get_contents($fixtureRoot.'/turn.json'),true,64,JSON_THROW_ON_ERROR)['instance'];
$secondaryTarget=$directActionTurn['payload']['target'];$secondaryTarget['record_id']='mudcrab';
$secondaryTarget['display_name']='Mudcrab';$secondaryTarget['kind']='creature';$secondaryTarget['refnum']['index']=113;
$directActionTurn['payload']['action_request']=['name'=>'combat.start','tier'=>2,'parameters'=>[],'target'=>$secondaryTarget];
$validator->validate($directActionTurn,'lorkhan.turn.v1');
$check(true,'typed player action request validates inside turn envelope');
$moodTurn=$directActionTurn;$moodTurn['payload']['input']['mood']=['kind'=>'playful'];
$validator->validate($moodTurn,'lorkhan.turn.v1');
$customMoodTurn=$directActionTurn;$customMoodTurn['payload']['input']['mood']=['kind'=>'custom','custom'=>'with quiet resolve'];
$validator->validate($customMoodTurn,'lorkhan.turn.v1');
$check(PlayerMoodPolicy::decorate('Come here',$moodTurn['payload']['input']['mood'])
    ==='Come here (speaks in a playful tone.)'
    &&PlayerMoodPolicy::decorate('Come here',$customMoodTurn['payload']['input']['mood'])
    ==='Come here (speaks with quiet resolve.)','typed player moods resolve to bounded prompt cues');
$editableMoodPrompts=PlayerMoodPolicy::defaultTemplates();
$editableMoodPrompts['playful']='({PLAYER_NAME} sounds {MOOD}.)';
$editableMoodPrompts['custom']='({PLAYER_NAME} speaks {CUSTOM_MOOD}.)';
$check(PlayerMoodPolicy::cue(['kind'=>'playful'],$editableMoodPrompts,'RANGROO')==='(RANGROO sounds playful.)'
    &&PlayerMoodPolicy::cue(['kind'=>'custom','custom'=>'with quiet resolve'],$editableMoodPrompts,'RANGROO')
        ==='(RANGROO speaks with quiet resolve.)',
    'revision-owned player mood prompts resolve only documented placeholders');
foreach([array_merge($editableMoodPrompts,['invented'=>'unsafe']),array_merge($editableMoodPrompts,['happy'=>"two\nlines"]),
    array_merge($editableMoodPrompts,['happy'=>'{UNKNOWN}'])]as$invalidMoodPrompts){
    try{PlayerMoodPolicy::validateTemplates($invalidMoodPrompts);$check(false,'invalid player mood prompts rejected');}
    catch(InvalidArgumentException $exception){$check(str_starts_with($exception->getMessage(),'invalid_player_mood_prompt'),'invalid player mood prompts rejected');}
}
foreach ([
    ['kind'=>'unknown'],
    ['kind'=>'happy','custom'=>'extra'],
    ['kind'=>'custom'],
    ['kind'=>'custom','custom'=>"two\nlines"],
    ['kind'=>'custom','custom'=>str_repeat('x',81)],
] as $invalidMood) {
    try{$invalidMoodTurn=$directActionTurn;$invalidMoodTurn['payload']['input']['mood']=$invalidMood;
        $validator->validate($invalidMoodTurn,'lorkhan.turn.v1');$check(false,'invalid player mood rejected');}
    catch(ValidationException $exception){$check($exception->getMessage()==='invalid_schema','invalid player mood rejected');}
}
try{
    $invalidDirectAction=$directActionTurn;$invalidDirectAction['payload']['action_request']['name']='../execute';
    $validator->validate($invalidDirectAction,'lorkhan.turn.v1');
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
$validator->validate($action, 'lorkhan.action-result.v1');
$check(true, 'expanded action-result validates');
$check($validator->decode('{}', 8) === [], 'empty JSON object decodes');

$promptTurn=['schema'=>'lorkhan.turn.v1','request_id'=>'r','turn_id'=>'t','installation_id'=>'i','profile_id'=>'p','playthrough_id'=>'w','session_id'=>'s','generation'=>1,'content_fingerprint'=>'sha256:'.str_repeat('a',64),'payload'=>['input'=>['kind'=>'text','language'=>'en','text'=>'Hello'],'speaker'=>['record_id'=>'player','display_name'=>'RANGROO'],'target'=>['record_id'=>'npc','display_name'=>'Fargoth'],'audience'=>[],'context'=>[],'ui_source'=>'chat']];
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
    &&str_contains((string)($systemMessage['content']??''),'- **Roleplay Instructions:**')
    &&str_contains((string)($systemMessage['content']??''),'### Character')
    &&strpos((string)$systemMessage['content'],'## Output Contract')<strpos((string)$systemMessage['content'],'## NPC Context')
    &&strpos((string)$systemMessage['content'],'## NPC Context')<strpos((string)$systemMessage['content'],'## Current Turn')
    &&($finalMessage['role']??null)==='user', 'compact Markdown prompt assembly is deterministic and role-separated');
$check(str_contains($assembled['provider_input']['_assembled_prompt'],'### Player Character')
    &&str_contains($assembled['provider_input']['_assembled_prompt'],'Freed from the Imperial prison.')
    &&!str_contains($assembled['provider_input']['_assembled_prompt'],'not prompt-safe'),
    'server-owned player profile is included in turn context with an explicit field allowlist');
$restrictedPlayerTurn=$promptTurn;$restrictedPlayerTurn['_player_profile']['content']['biography_known_by_all']=false;
$restrictedPlayerPrompt=$assembler->assemble($restrictedPlayerTurn,$promptSelection)['provider_input']['_assembled_prompt'];
$check(!str_contains($restrictedPlayerPrompt,'Freed from the Imperial prison.')&&str_contains($restrictedPlayerPrompt,'Curious'),
    'restricted player biography is hidden from NPC prompts without hiding the remaining player profile');
$narratorPlayerTurn=$restrictedPlayerTurn;$narratorPlayerTurn['payload']['target']['kind']='narrator';
$narratorPlayerPrompt=$assembler->assemble($narratorPlayerTurn,$promptSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($narratorPlayerPrompt,'Freed from the Imperial prison.'),
    'restricted player biography remains available to the Narrator');
$check(str_contains($assembled['provider_input']['_assembled_prompt'],'### Record Descriptions')
    &&str_contains($assembled['provider_input']['_assembled_prompt'],'A short iron blade.')
    &&!str_contains($assembled['provider_input']['_assembled_prompt'],'not prompt-safe either'),
    'server-owned record descriptions are included with an explicit field allowlist');
$check(str_contains((string)$systemMessage['content'],'Curious & wary <Bosmer>.')
    &&str_contains((string)$systemMessage['content'],'- **Name:** RANGROO')
    &&!str_contains((string)$systemMessage['content'],'- **Name:** Nerevarine')
    &&!str_contains((string)$systemMessage['content'],'DISABLED NARRATOR SENTINEL'),
    'Markdown presentation, live player identity, and disabled narrator filtering are stable');
$moodPromptTurn=$promptTurn;$moodPromptTurn['payload']['input']['mood']=['kind'=>'playful'];
$moodPromptSelection=$promptSelection;$moodPromptSelection['prompt']['content']['player_mood_prompts']=$editableMoodPrompts;
$moodAssembled=(new PromptAssembler(4096,1024))->assemble($moodPromptTurn,$moodPromptSelection);
$moodPrompt=$moodAssembled['provider_input'];
$check(str_contains($moodPrompt['_assembled_prompt'],'Hello (RANGROO sounds playful.)')
    &&($moodPrompt['payload']['input']['text']??null)==='Hello'
    &&($moodPrompt['payload']['input']['mood']['kind']??null)==='playful'
    &&($moodAssembled['trace']['player_mood_cue']??null)==='(RANGROO sounds playful.)',
    'player mood cues decorate the model prompt, freeze the resolved cue, and keep authored input intact');
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
$check(str_contains($contextPrompt,'### World')&&str_contains($contextPrompt,'- **Location:** Seyda Neen')
    &&str_contains($contextPrompt,"- **Date:** 16 Sun's Height 3E 427")
    &&str_contains($contextPrompt,'### People Present')&&str_contains($contextPrompt,'### Nearby Actors')
    &&str_contains($contextPrompt,'- **Current Activity:** wander')
    &&str_contains($contextPrompt,'- **Basic Summary:** A watchful Imperial guard.')
    &&str_contains($contextPrompt,'### Nearby Items')&&str_contains($contextPrompt,'- **Count:** 2')
    &&str_contains($contextPrompt,'### Points Of Interest')&&str_contains($contextPrompt,'- **Lock Level:** 20')
    &&!str_contains($contextPrompt,'**Position:**')&&!str_contains($contextPrompt,'**X:**'),
    'OpenMW world, actors, items, and points of interest render as bounded semantic Markdown');
$knowledgeSelection=$promptSelection;
$knowledgeSelection['knowledge_retrieval']=['status'=>'fallback_grounded'];
$knowledgeSelection['knowledge']=[['document_id'=>'oghma-auriel','topic'=>'auriel_s_bow','access_level'=>'basic',
    'content'=>'Auriel\'s Bow is an ancient artifact associated with the elven god Auri-El.'],
    ['document_id'=>'oghma-sixth-house','topic'=>'sixth_house','access_level'=>'denied','content'=>'']];
$knowledgePrompt=(new PromptAssembler(16384,1024))->assemble($promptTurn,$knowledgeSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($knowledgePrompt,"## Oghma Context\n\n- **Contract:** oghma-parity-v1\n- **Status:** fallback_grounded")
    &&str_contains($knowledgePrompt,'### Article: auriel_s_bow')
    &&str_contains($knowledgePrompt,"- **Content:** Auriel's Bow is an ancient artifact")
    &&str_contains($knowledgePrompt,'### Article: sixth_house')
    &&str_contains($knowledgePrompt,'- **Denial Reason:** knowledge_classes_not_authorized'),
    'authorized and denied Oghma knowledge render as compact Markdown');
$markdownSelection=$knowledgeSelection;
unset($markdownSelection['prompt']['content']['format']);
$markdownSelection['history']=[['history_id'=>'markdown-history','content'=>['kind'=>'speech',
    'text'=>'I remember our last conversation.','speaker'=>'Fargoth',
    'speaker_identity'=>['record_id'=>'npc','display_name'=>'Fargoth']]]];
$markdownAssembled=(new PromptAssembler(16384,1024))->assemble($promptTurn,$markdownSelection);
$markdownPrompt=$markdownAssembled['provider_input']['_assembled_prompt'];
$check(str_contains($markdownPrompt,"# Roleplay Context\n\n## Output Contract\n\n- **Response Contract:**")
    &&str_contains($markdownPrompt,'## NPC Context')
    &&str_contains($markdownPrompt,'- **Roleplay Instructions:** You are Fargoth')
    &&str_contains($markdownPrompt,'## Conversation Context')
    &&str_contains($markdownPrompt,'- **Message:** Fargoth: I remember our last conversation.')
    &&str_contains($markdownPrompt,"## Oghma Context\n\n- **Contract:** oghma-parity-v1")
    &&str_contains($markdownPrompt,'- **Action Contract:**')
    &&!str_contains($markdownPrompt,'<roleplay_context>')
    &&!str_contains($markdownPrompt,'<npc_context>')
    &&!str_contains($markdownPrompt,'<response_contract>')
    &&!str_contains($markdownPrompt,'<action_contract>')
    &&!str_contains($markdownPrompt,'<oghma ')
    &&$markdownAssembled['trace']['algorithm']==='chim-compact-roleplay-prompt-v3-markdown'
    &&$markdownAssembled['trace']['prompt_format']==='markdown'
    &&array_column($markdownAssembled['trace']['sections'],'section_key')===array_column($assembled['trace']['sections'],'section_key'),
    'compact chat and all prompt contracts use the single Markdown presentation');
$providerMessages=(new ReflectionMethod($actionProvider,'promptMessages'))->invoke($actionProvider,
    ['_prompt'=>$assembled['provider_input']]);
$check(array_column($providerMessages,'role')===array_column($assembled['provider_input']['_messages'],'role')
    &&str_contains($providerMessages[0]['content'],'- **Action Contract:**')
    &&str_contains($providerMessages[0]['content'],'exactly one key named "text"')
    &&strpos($providerMessages[0]['content'],'- **Action Contract:**')<strpos($providerMessages[0]['content'],'## Current Turn')
    &&str_starts_with($providerMessages[0]['content'],'# Roleplay Context'),
    'OpenAI-compatible provider sends frozen split messages with compact Markdown prompt context');
$validateProviderResult=new ReflectionMethod($actionProvider,'validateResultShape');
$policy = new ActionPolicyValidator();
$actionDefinitions = [];
foreach (['inspect.report'=>0, 'ai.follow'=>1, 'ai.stop'=>1, 'item.use'=>2] as $name=>$tier) {
    $actionDefinitions[] = ['name'=>$name, 'tier'=>$tier, 'client_capability'=>'action.'.$name,
        'available_to_npc'=>true,'available_to_narrator'=>false,'game_function'=>true,'source'=>'base',
        'description'=>'', 'continuation_capable'=>$name==='ai.follow',
        'display_name'=>ucwords(str_replace('.',' ',$name)),'confirmation_mode'=>$tier>=2?'required':($tier===0?'none':'optional'),
        'followup_default'=>false,'followup_prompt'=>'React to the completed action result.',
        'followup_actions_supported'=>$name==='ai.follow','cooldown_seconds'=>0,
        'parameter_schema'=>['type'=>'object', 'additionalProperties'=>false]];
}
$actionContext = ['definitions'=>$actionDefinitions,
    'session'=>['capabilities'=>['action.inspect.report','action.ai.follow','action.ai.stop'],
        'enabled_actions'=>array_column($actionDefinitions,'name')],
    'policy'=>['content'=>['max_tier'=>1,'denied_actions'=>['ai.stop']]]];
$allowedActions = $policy->allowedDefinitions($actionContext);
$check(array_column($allowedActions,'name')===['inspect.report','ai.follow'],
    'prompt actions intersect negotiated capabilities, enabled actions, and policy');
$overrideContext=$actionContext;
$overrideContext['session']['capabilities'][]='action.confirmation';
$overrideContext['session']['capabilities'][]='action.result-followup';
$overrideContext['policy']=['configuration_id'=>'policy-id','revision'=>4,'content'=>['max_tier'=>1,'actions'=>[
    'ai.follow'=>['enabled'=>true,'display_name'=>'Follow Player','description'=>'Stay near the player.',
        'confirmation_required'=>true,'followup_enabled'=>true,'followup_prompt'=>'React to following the player.',
        'allow_followup_action'=>true,'cooldown_seconds'=>5]]]];
$overrideAllowed=$policy->allowedDefinitions($overrideContext);
$overrideFollow=array_values(array_filter($overrideAllowed,static fn(array $definition):bool=>$definition['name']==='ai.follow'))[0]??[];
$proposal=['name'=>'ai.follow','tier'=>1,'actor'=>['kind'=>'npc'],'target'=>['kind'=>'player'],'parameters'=>[]];
$normalized=$policy->validate($proposal,$overrideContext);
$narratorProposal=array_replace($proposal,['actor'=>['kind'=>'narrator']]);
try{$policy->validate($narratorProposal,$overrideContext);throw new RuntimeException('narrator received an NPC action');}
catch(DomainException $error){$check($error->getMessage()==='provider_action_not_allowed','unexpected narrator action scope error');}
$legacyContext=$overrideContext;
$legacyContext['session']['capabilities']=array_values(array_diff($legacyContext['session']['capabilities'],
    ['action.confirmation','action.result-followup']));
$legacyNormalized=$policy->validate($proposal,$legacyContext);
$check($overrideFollow['display_name']==='Follow Player'&&$overrideFollow['description']==='Stay near the player.'
    &&$overrideFollow['confirmation_required']===true&&$overrideFollow['followup_enabled']===true
    &&$overrideFollow['followup_prompt']==='React to following the player.'
    &&$overrideFollow['followup_actions_allowed']===true&&$overrideFollow['cooldown_seconds']===5
    &&$normalized['policy_configuration_id']==='policy-id'&&$normalized['policy_revision']===4
    &&$normalized['display_name']==='Follow Player'&&$normalized['confirmation_required']===true
    &&$normalized['followup_enabled']===true&&$normalized['followup_prompt']==='React to following the player.'
    &&$normalized['followup_actions_allowed']===true
    &&!array_key_exists('display_name',$legacyNormalized)
    &&!array_key_exists('confirmation_required',$legacyNormalized)&&!array_key_exists('followup_enabled',$legacyNormalized),
    'action presentation, scope, confirmation, and result follow-up require negotiated client capabilities');
$herikaContext=$overrideContext;$herikaDefinition=array_values(array_filter($actionDefinitions,
    static fn(array$definition):bool=>$definition['name']==='ai.follow'))[0];
$herikaContext['policy']['content']=['max_tier'=>1,'actions'=>['ai.follow'=>[
    'code_name'=>'ai.follow','action_name'=>'Herika-shaped Follow','description'=>'Use the complete action row.',
    'return_message'=>'Following.','available_to_npc'=>true,'available_to_followers'=>false,
    'available_to_narrator'=>false,'is_activated'=>true,'parameters_json'=>$herikaDefinition['parameter_schema'],
    'metadata'=>['followup'=>['prompt'=>'Base prompt.'],'custom_config'=>['confirmation_required'=>true,
        'followup_enabled'=>true,'followup_prompt'=>'React to the typed result.','followup_use_functions_again'=>true],
        'cooldown_seconds'=>7],
    'game_function'=>true,'import_version'=>1,'script_proxy_program'=>null]]];
$herikaAllowed=$policy->allowedDefinitions($herikaContext);
$herikaFollow=array_values(array_filter($herikaAllowed,static fn(array$definition):bool=>$definition['name']==='ai.follow'))[0]??[];
$check($herikaFollow['display_name']==='Herika-shaped Follow'&&$herikaFollow['description']==='Use the complete action row.'
    &&$herikaFollow['confirmation_required']===true&&$herikaFollow['followup_enabled']===true
    &&$herikaFollow['followup_prompt']==='React to the typed result.'
    &&$herikaFollow['followup_actions_allowed']===true&&$herikaFollow['cooldown_seconds']===7,
    'complete Herika action rows normalize into the bounded LORKHAN runtime contract');
$continuationContext=$overrideContext;$continuationContext['continuation']=['depth'=>1,'allow_action'=>true];
$continued=$policy->validate($proposal,$continuationContext);
$requiredContext=$overrideContext;$requiredContext['session']['capabilities'][]='action.item.use';
$requiredContext['policy']['content']=['actions'=>['item.use'=>['enabled'=>true,'confirmation_required'=>false]]];
$required=$policy->validate(['name'=>'item.use','tier'=>2,'actor'=>['kind'=>'npc'],'target'=>['kind'=>'player'],
    'parameters'=>[]],$requiredContext);
$check($continued['followup_enabled']===false&&$continued['followup_actions_allowed']===false
    &&$continued['followup_depth']===1&&$required['confirmation_required']===true,
    'one extra action is the hard follow-up cap and required confirmations cannot be disabled');
$actionTurn = $promptTurn;
$actionTurn['_allowed_action_definitions'] = $allowedActions;
$actionTurn['_prompt'] = (new PromptAssembler())->assemble($actionTurn,$promptSelection)['provider_input'];
$filteredMessages = (new ReflectionMethod($actionProvider,'promptMessages'))->invoke($actionProvider,$actionTurn);
$check(str_contains($filteredMessages[0]['content'],'`ai.follow()`')
    &&!str_contains($filteredMessages[0]['content'],'ai.stop')
    &&!str_contains($filteredMessages[0]['content'],'item.use')
    &&!str_contains($filteredMessages[0]['content'],'additionalProperties'),
    'assembler and provider expose only compact filtered action signatures');
$actionContext['policy']['content']=['enabled'=>false];
$check($policy->allowedDefinitions($actionContext)===[]
    &&str_contains($policy->promptContract([]),'action must be null'),
    'disabled policies and snapshots without server action authority fail closed');
$actionTurn['payload']['ui_source']='lorkhan_rechat';
$actionTurn['_allowed_action_definitions']=[];
$actionTurn['_prompt']=(new PromptAssembler())->assemble($actionTurn,$promptSelection)['provider_input'];
$rechatMessages=(new ReflectionMethod($actionProvider,'promptMessages'))->invoke($actionProvider,$actionTurn);
$check(str_contains($rechatMessages[0]['content'],'action must be null')
    &&!str_contains($rechatMessages[0]['content'],'`ai.follow()`'),
    'rechat cannot inherit an action contract from a prior turn');
$actionTurn['payload']['ui_source']='lorkhan_action_followup';
$actionTurn['_prompt']=(new PromptAssembler())->assemble($actionTurn,$promptSelection)['provider_input'];
$followupMessages=(new ReflectionMethod($actionProvider,'promptMessages'))->invoke($actionProvider,$actionTurn);
$check(str_contains($followupMessages[0]['content'],'action must be null')
    &&!str_contains($followupMessages[0]['content'],'`ai.follow()`'),
    'result-aware action follow-up defaults to dialogue only');
$actionTurn['_allowed_action_definitions']=$allowedActions;
$actionTurn['_prompt']=(new PromptAssembler())->assemble($actionTurn,$promptSelection)['provider_input'];
$allowedFollowupMessages=(new ReflectionMethod($actionProvider,'promptMessages'))->invoke($actionProvider,$actionTurn);
$check(str_contains($allowedFollowupMessages[0]['content'],'`ai.follow()`'),
    'an explicitly authorized result follow-up can expose one filtered action contract');
$noActionTurn=$actionTurn;$noActionTurn['_allowed_action_definitions']=[];
$noActionPrompt=(new PromptAssembler())->assemble($noActionTurn,$promptSelection)['provider_input'];
$legacyMessages=(new ReflectionMethod($actionProvider,'promptMessages'))->invoke($actionProvider,['_prompt'=>$noActionPrompt]);
$rawMessages=(new ReflectionMethod($actionProvider,'promptMessages'))->invoke($actionProvider,[]);
$check(!str_contains($legacyMessages[0]['content'],'`ai.follow()`')
    &&str_contains($legacyMessages[0]['content'],'action must be null')
    &&str_contains($rawMessages[0]['content'],'action must be null'),
    'legacy and raw provider fallbacks do not restore the old unrestricted action list');
$validateProviderResult->invoke($actionProvider,['utterances'=>[['text'=>'Hello, outlander.']],'action'=>null]);
$decodeProviderContent=new ReflectionMethod($actionProvider,'decodeStructuredContent');
$wrappedProviderResult=$decodeProviderContent->invoke($actionProvider,'[{"utterances":[{"text":"Wrapped hello."}],"action":{"name":"ai.follow","parameters":{"distance":192}}}]');
$validateProviderResult->invoke($actionProvider,$wrappedProviderResult);
$check($wrappedProviderResult['utterances'][0]['text']==='Wrapped hello.'&&($wrappedProviderResult['action']['name']??null)==='ai.follow',
    'OpenAI-compatible provider unwraps one structured response object from a top-level array');
$reasoningProvider=new OpenAiCompatibleProvider('https://api.openai.com/v1/chat/completions',['api.openai.com'],
    'gpt-test','test-key',30_000,false,['reasoning_model'=>true]);
$decodeReasoningContent=new ReflectionMethod($reasoningProvider,'decodeStructuredContent');
$reasoningResult=$decodeReasoningContent->invoke($reasoningProvider,
    " \n<THINK>considering a response</think>\n{\"utterances\":[{\"text\":\"After thought\"}],\"action\":null}");
$check($reasoningResult['utterances'][0]['text']==='After thought',
    'opted-in provider removes one leading balanced reasoning block before strict JSON decoding');
try{$decodeProviderContent->invoke($actionProvider,
        '<think>not enabled</think>{"utterances":[{"text":"No"}],"action":null}');
    $check(false,'provider cleaned a reasoning block without an explicit connector override');
}catch(RuntimeException$error){$check($error->getMessage()==='provider_invalid_output',
    'reasoning cleanup stays disabled when the connector override is absent');}
foreach([
    '<think>unfinished{"utterances":[{"text":"No"}],"action":null}',
    'prose<think>hidden</think>{"utterances":[{"text":"No"}],"action":null}',
    '<think>first</think><reasoning>second</reasoning>{"utterances":[{"text":"No"}],"action":null}',
]as$invalidReasoning){
    try{$decodeReasoningContent->invoke($reasoningProvider,$invalidReasoning);
        $check(false,'provider accepted malformed or ambiguous reasoning output');
    }catch(RuntimeException$error){$check($error->getMessage()==='provider_invalid_output',
        'provider rejects malformed, non-leading, and repeated reasoning blocks');}
}
try{$decodeProviderContent->invoke($actionProvider,'[{"utterances":[{"text":"First"}],"action":null},{"utterances":[{"text":"Second"}],"action":null}]');
    $check(false,'provider accepted a multi-object top-level response array');
}catch(RuntimeException$error){$check($error->getMessage()==='provider_invalid_output',
    'provider rejects ambiguous multi-object top-level response arrays');}
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
    ['history_id'=>'smoke-line','content'=>['kind'=>'event','type'=>'turn.requested','turn_id'=>'smoke-turn','input'=>['text'=>'Automated LORKHAN smoke test.'],'speaker'=>['display_name'=>'RANGROO']]],
];
$rolePrompt=(new PromptAssembler(8192,1024))->assemble($promptTurn,$roleHistory)['provider_input'];
$roleMessages=$rolePrompt['_messages'];
$check(array_column($roleMessages,'role')===['system','user']
    &&str_contains($roleMessages[0]['content'],'- **Message:** RANGROO: Where is my ring?')
    &&str_contains($roleMessages[0]['content'],'- **Message:** Fargoth: I have not seen it.')
    &&str_contains($roleMessages[0]['content'],'- **Message:** Guard: Move along.')
    &&substr_count($rolePrompt['_assembled_prompt'],'Where is my ring?')===1
    &&!str_contains(json_encode($roleMessages,JSON_THROW_ON_ERROR),'smoke test'),
    'compact chat history is included once with explicit speakers and control noise filtered');
$semanticHistory=$promptSelection;$semanticHistory['memory']=[];$semanticHistory['recent_action_results']=[];
$coveredHistory=$roleHistory;
$coveredHistory['memory']=[['memory_id'=>'heard-line','content'=>'Fargoth: I have not seen it.']];
$coveredHistory['memory_retrieval']=['result_ids'=>['heard-line'],'scores'=>['heard-line'=>1]];
$coveredPrompt=(new PromptAssembler(8192,1024))->assemble($promptTurn,$coveredHistory);
$coveredSources=array_column(array_filter($coveredPrompt['trace']['sources'],static fn(array $row):bool=>$row['source_kind']==='memory'),null,'source_id');
$check(substr_count($coveredPrompt['provider_input']['_assembled_prompt'],'Fargoth: I have not seen it.')===1
    &&$coveredSources['heard-line']['reason']==='covered_by_history'&&!$coveredSources['heard-line']['included']
    &&$coveredPrompt['trace']['memory_retrieval']['result_ids']===[], 'fully retained history covers a memory without a false inclusion trace');
$restoredHistory=$coveredHistory;
$restoredHistory['history'][]=['history_id'=>'history-pressure','content'=>['kind'=>'speech','text'=>str_repeat('H',1000),'speaker'=>'Guard']];
$restoredPrompt=(new PromptAssembler(3072,1024))->assemble($promptTurn,$restoredHistory);
$check(!str_contains($restoredPrompt['provider_input']['_assembled_prompt'],'## Conversation Context')
    &&str_contains($restoredPrompt['provider_input']['_assembled_prompt'],'## Memory Context')
    &&str_contains($restoredPrompt['provider_input']['_assembled_prompt'],'- **Item:** Fargoth: I have not seen it.'),
    'dropping history for the total prompt budget restores its otherwise-covered memory');
$fallbackPrompt=(new PromptAssembler(512,256))->assemble($promptTurn,$coveredHistory);
$check($fallbackPrompt['trace']['memory_retrieval']['result_ids']===[]
    &&$fallbackPrompt['trace']['memory_retrieval']['coverage']['covered_by_history']===0,
    'minimal prompt fallback cannot claim memory or history coverage');
$pool=[];foreach(range(1,12)as$index)$pool[]=['id'=>'duplicate-'.$index,'text'=>'A remembered fact.'];
$pool[]=['id'=>'uncovered','text'=>'A different fact.'];
$dedup=MemoryPromptSelection::select($pool,'',1024);
$check(array_keys($dedup['texts'])===['duplicate-1','uncovered']&&$dedup['counts']['covered_by_memory']===11,
    'duplicate memories do not crowd out a lower-ranked uncovered record');
$summary=MemoryPromptSelection::select([['id'=>'child','text'=>'A remembered fact.'],
    ['id'=>'parent','text'=>"A remembered fact.\nA different fact."]],'',1024);
$check(array_keys($summary['texts'])===['parent']&&$summary['reasons']['child']==='covered_by_memory',
    'a fully retained summary can replace its exact child without losing facts');
$partial=MemoryPromptSelection::select([['id'=>'partial','text'=>"A remembered fact.\nA different fact."],
    ['id'=>'missing','text'=>'A different fact.']],'A remembered fact.',24);
$check(isset($partial['texts']['partial'],$partial['texts']['missing'])&&$partial['reasons']['missing']==='included',
    'partial history overlap and a truncated summary never suppress an uncovered fact');
$smallSummary=MemoryPromptSelection::select([['id'=>'child','text'=>'A remembered fact.'],
    ['id'=>'parent','text'=>"A different fact.\nA remembered fact."]],'',1024,45);
$check(isset($smallSummary['texts']['child']), 'a summary cut by the section budget cannot replace a child it no longer covers');
$escapedMemory=MemoryPromptSelection::select([['id'=>'escaped','text'=>str_repeat('é<&',6000)]],'',16384);
$check(strlen($escapedMemory['xml'])<=16384&&mb_check_encoding($escapedMemory['xml'],'UTF-8')
    &&$escapedMemory['reasons']['escaped']==='byte_limit'&&!str_contains($escapedMemory['xml'],'é<&'),
    'memory enforces the actual escaped XML section budget and retains valid UTF-8');
$candidateSelection=$roleHistory;$candidateSelection['history']=[];$candidateSelection['memory_candidates']=[];
foreach($pool as$rank=>$item)$candidateSelection['memory_candidates'][]=['memory_id'=>$item['id'],'content'=>$item['text'],'_prompt_score'=>1-$rank/100];
$candidateSelection['memory']=array_slice($candidateSelection['memory_candidates'],0,10);
$candidateSelection['memory_retrieval']=['result_ids'=>[],'scores'=>[]];
$candidatePrompt=(new PromptAssembler(8192,1024))->assemble($promptTurn,$candidateSelection);
$candidateTrace=array_values(array_filter($candidatePrompt['trace']['sources'],static fn(array $row):bool=>$row['source_kind']==='memory'));
$check($candidatePrompt['trace']['memory_retrieval']['result_ids']===['duplicate-1','uncovered']
    &&count($candidateTrace)===11&&$candidateTrace[10]['source_id']==='uncovered'&&$candidateTrace[10]['included'],
    'candidate refill audits actual survivors beyond the initial top ten with bounded trace rows');
$extendedHistory=$roleHistory;$extendedHistory['history']=[];
foreach(range(1,45)as$index)$extendedHistory['history'][]=['id'=>'history-limit-'.$index,
    'content'=>['kind'=>'speech','text'=>'Distinct history line '.$index,'speaker'=>'Fargoth',
        'speaker_identity'=>$promptTurn['payload']['target']]];
$extendedPrompt=(new PromptAssembler(16384,1024))->assemble($promptTurn,$extendedHistory)['provider_input']['_assembled_prompt'];
$check(substr_count($extendedPrompt,'- **Message:**')===45&&str_contains($extendedPrompt,'Distinct history line 45'),
    'profile-selected history above 32 messages was silently capped by the assembler');
foreach($extendedHistory['history']as&$entry)$entry['content']['text'].=' '.str_repeat('&',300);
unset($entry);
$boundedPrompt=(new PromptAssembler())->assemble($promptTurn,$extendedHistory)['provider_input']['_assembled_prompt'];
preg_match('~\n## Conversation Context\n\n(.*?)(?:\n\n## Audience Speaker Rules)~s',$boundedPrompt,$boundedHistory);
$check(strlen($boundedHistory[1]??'')<=32768
    &&str_contains($boundedHistory[1]??'','Distinct history line 45')
    &&!str_contains($boundedHistory[1]??'','Distinct history line 1 '),
    'expanded history must keep the newest lines within the compact chat byte budget');
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
    &&str_contains($protectedKnowledge['provider_input']['_assembled_prompt'],"## Oghma Context\n\n- **Contract:** oghma-parity-v1")
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
$validator->validate($canonicalResult,'lorkhan.response.v1');
$check($canonicalResult['request_id']===$canonicalTurn['request_id']&&$canonicalResult['runtime_generation']===4
    &&array_column($canonicalResult['lines'],'action')===['say','rolecommand']
    &&$canonicalResult['lines'][0]['text']==='You found my engraved ring—thank you!'
    &&$canonicalResult['lines'][1]['command_args']===['distance=192'],
    'provider result normalizes once into ordered UTF-8 response lines with full correlation');
$translatedCanonical=(new CanonicalResponseNormalizer())->normalize($canonicalTurn,['utterances'=>[[
    'text'=>'Original history','_history_text'=>'Translated history','_subtitle'=>'Translated subtitle',
    '_tts_text'=>'Translated speech']]]);
$validator->validate($translatedCanonical,'lorkhan.response.v1');
$check($translatedCanonical['lines'][0]['text']==='Translated history'
    &&$translatedCanonical['lines'][0]['subtitle']==='Translated subtitle'
    &&$translatedCanonical['lines'][0]['tts_text']==='Translated speech',
    'canonical dialogue preserves independent history, subtitle, and TTS text');
$failedCanonical=(new CanonicalResponseNormalizer())->failure($canonicalTurn,'provider_unavailable');
$validator->validate($failedCanonical,'lorkhan.response.v1');
$check($failedCanonical['ok']===false&&$failedCanonical['lines']===[]
    &&$failedCanonical['error']==='provider_unavailable','terminal failure response is canonical and empty');
$narrator=$identity('narrator','lorkhan:narrator',0,'The Narrator');$narrator['content_file']='LORKHAN';
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
$narrationTurn['_narrator_profile']['content']['inline_narration_mode']='Disabled';
$planned=(new DialoguePlanner())->plan($narrationTurn,(new InlineNarrationRouter())->route($narrationTurn,
    ['utterances'=>[['text'=>'*Fargoth waves.* Welcome. *He smiles.*'],['text'=>'**He nods.**']],'action'=>null]));
$check($planned[0]['text']==='*Fargoth waves.* Welcome. *He smiles.*'&&$planned[0]['speech_enabled']===true
    &&$planned[1]['text']==='**He nods.**'&&$planned[1]['speech_enabled']===true,
    'disabled narration preserves asterisks as ordinary Markdown dialogue');
$narrationTurn['_narrator_profile']['content']['enabled']=false;
$narrationTurn['_narrator_profile']['content']['inline_narration_mode']='Narrator';
$planned=(new DialoguePlanner())->plan($narrationTurn,(new InlineNarrationRouter())->route($narrationTurn,
    ['text'=>'*The wind rises.* Stay safe.','action'=>null]));
$check(count($planned)===1&&$planned[0]['speaker']['kind']==='npc'
    &&$planned[0]['text']==='*The wind rises.* Stay safe.',
    'disabled narrator profile preserves Markdown without routing it through the narrator');
unset($narrationTurn['_narrator_profile']);
$planned=(new DialoguePlanner())->plan($narrationTurn,(new InlineNarrationRouter())->route($narrationTurn,
    ['utterances'=>[['text'=>'*He bows.* Greetings.'],['text'=>'Plain speech.']],'action'=>null]));
$check(array_column($planned,'text')===['*He bows.* Greetings.','Plain speech.'],
    'missing narrator profile preserves Markdown dialogue');

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
$previewVoiceRoot=sys_get_temp_dir().'/lorkhan-preview-voices-'.bin2hex(random_bytes(4));mkdir($previewVoiceRoot,0700);
file_put_contents($previewVoiceRoot.'/Nerevarine.wav','RIFF');file_put_contents($previewVoiceRoot.'/almalexia.wav','RIFF');
file_put_contents($previewVoiceRoot.'/notes.txt','ignored');
$previewPresets=[
    ['configuration_id'=>'cfg-xtts','name'=>'Local XTTS','content'=>'{"driver":"xtts-fastapi","voice":"almalexia"}'],
    ['id'=>'cfg-cartesia','name'=>'Cartesia','content'=>['driver'=>'cartesia','voice'=>'sonic-en']],
    ['configuration_id'=>'cfg-broken','name'=>'Unsupported','content'=>['driver'=>'not-a-driver','voice'=>'ghost']],
    ['configuration_id'=>'','name'=>'No identity','content'=>['driver'=>'xtts']],
];
$previewCatalogVoices=[['configuration_id'=>'cfg-cartesia','id'=>'Discovered Voice'],['configuration_id'=>'cfg-broken','id'=>'Unreachable Voice'],
    ['configuration_id'=>'cfg-xtts','id'=>"Control\x07Voice"]];
$previewOptions=SpeechPreviewCatalog::options($previewPresets,$previewCatalogVoices,$previewVoiceRoot,'cfg-cartesia');
$check(array_column($previewOptions['connectors'],'id')===['cfg-xtts','cfg-cartesia']
    &&$previewOptions['connectors'][0]['label']==='Local XTTS (XTTS FastAPI)'
    &&$previewOptions['connectors'][0]['voices']===['almalexia','Nerevarine']
    &&$previewOptions['connectors'][1]['voices']===['Discovered Voice','sonic-en']
    &&$previewOptions['voices']===['Discovered Voice','sonic-en']
    &&$previewOptions['default_connector_id']==='cfg-cartesia'&&$previewOptions['default_voice']==='sonic-en',
    'pronunciation preview scopes installed voices to the connector that can speak them');
$check(SpeechPreviewCatalog::options([],[],$previewVoiceRoot)===['connectors'=>[],'voices'=>[],
    'default_connector_id'=>'','default_voice'=>''],
    'pronunciation preview reports no choices when no TTS connector is configured');
$check(SpeechPreviewCatalog::narratorVoice(['content'=>['voice'=>['id'=>' Nerevarine ','language'=>'en']]])==='Nerevarine'
    &&SpeechPreviewCatalog::narratorVoice(['content'=>['voice'=>[]]])===''
    &&SpeechPreviewCatalog::narratorVoice(null)==='',
    'pronunciation preview reads the narrator voice from the narrator profile voice document');
$check(SpeechPreviewCatalog::narratorConnector(['content'=>['routing'=>['tts_configuration_id'=>' cfg-xtts ']]])==='cfg-xtts'
    &&SpeechPreviewCatalog::narratorConnector(['content'=>['routing'=>[]]])===''
    &&SpeechPreviewCatalog::narratorConnector(null)==='',
    'pronunciation preview reads the narrator TTS connector from existing profile routing');
$previewNarrated=SpeechPreviewCatalog::options($previewPresets,$previewCatalogVoices,$previewVoiceRoot,'cfg-xtts','nerevarine');
$check($previewNarrated['default_connector_id']==='cfg-xtts'&&$previewNarrated['default_voice']==='Nerevarine',
    'pronunciation preview opens on the configured narrator voice instead of the connector default');
$previewForeign=SpeechPreviewCatalog::options($previewPresets,$previewCatalogVoices,$previewVoiceRoot,'cfg-cartesia','Nerevarine');
$check($previewForeign['default_connector_id']==='cfg-cartesia'&&$previewForeign['default_voice']==='sonic-en'
    &&!in_array('Nerevarine',$previewForeign['voices'],true),
    'a narrator voice the selected connector cannot speak falls back to that connector default');
$previewNarratorConnector=SpeechPreviewCatalog::options($previewPresets,$previewCatalogVoices,$previewVoiceRoot,
    'cfg-cartesia','Nerevarine','cfg-xtts');
$check($previewNarratorConnector['default_connector_id']==='cfg-xtts'&&$previewNarratorConnector['default_voice']==='Nerevarine',
    'pronunciation preview prefers the narrator TTS connector and voice over installation defaults');
$previewInworld=SpeechPreviewCatalog::options([
    ['configuration_id'=>'cfg-inworld','name'=>'Inworld','content'=>['driver'=>'inworld','voice'=>'']],
    ['configuration_id'=>'cfg-inworld-set','name'=>'Inworld Set','content'=>['driver'=>'inworld','voice'=>'Ashley']],
],[['configuration_id'=>'cfg-inworld','id'=>'workspace__narrator']],$previewVoiceRoot,'cfg-inworld','Nerevarine');
$check(array_column($previewInworld['connectors'],'id')===['cfg-inworld']
    &&$previewInworld['default_connector_id']==='cfg-inworld'&&$previewInworld['default_voice']==='workspace__narrator'
    &&!in_array('Ashley',$previewInworld['voices'],true)&&!in_array('Nerevarine',$previewInworld['voices'],true),
    'Inworld offers only workspace-qualified provider voices and never raw local or preset labels');
array_map('unlink',glob($previewVoiceRoot.'/*')?:[]);rmdir($previewVoiceRoot);
$credentialRoot=sys_get_temp_dir().'/lorkhan-credentials-'.bin2hex(random_bytes(4));mkdir($credentialRoot,0700);
$credentialPath=$credentialRoot.'/provider-keys.json';$credentialStore=new CredentialStore($credentialPath);
$credentialStore->set('LORKHAN_DEEPL_API_KEY','deepl-managed-secret');
$credentialStore->set('LORKHAN_TTS_GCP_API_KEY','managed-secret');
$check($credentialStore->resolve('LORKHAN_TTS_GCP_API_KEY')==='managed-secret'
    &&$credentialStore->resolve('LORKHAN_DEEPL_API_KEY')==='deepl-managed-secret'
    &&count(array_filter($credentialStore->statuses(),static fn(array$row):bool=>$row['variable']==='LORKHAN_TTS_GCP_API_KEY'&&$row['source']==='managed store'))===1,
    'credential store resolves managed keys while exposing status metadata only');
putenv('LORKHAN_TTS_GCP_API_KEY=environment-secret');
$check($credentialStore->resolve('LORKHAN_TTS_GCP_API_KEY')==='environment-secret','process environment overrides browser-managed credentials');
putenv('LORKHAN_TTS_GCP_API_KEY');$credentialStore->delete('LORKHAN_TTS_GCP_API_KEY');
$check($credentialStore->resolve('LORKHAN_TTS_GCP_API_KEY')===''&&(fileperms($credentialPath)&0777)===0640,'credential deletion is persistent and store permissions are restrictive');
$translationPolicy=array_replace(TranslationPolicy::defaults(),['provider'=>'deepl','translate_text'=>true,
    'save_translated_text'=>true,'source_language'=>'en','target_language'=>'de']);
$translationPolicy=TranslationPolicy::validate($translationPolicy);
$deepLRequest=[];$deepL=new DeepLTranslationProvider($translationPolicy['endpoint'],'test-key',5000,
    static function(string$endpoint,array$headers,string$body,int$timeout,\LorkhanServer\Application\CancellationToken$token)use(&$deepLRequest):string{
        $deepLRequest=compact('endpoint','headers','body','timeout');$token->throwIfCancellationRequested();
        return'{"translations":[{"text":"Guten Tag"},{"text":"Auf Wiedersehen"}]}';
    });
$translated=$deepL->translate(['Hello','Goodbye'],$translationPolicy['source_language'],$translationPolicy['target_language'],new NeverCancelledToken());
$check($translated===['Guten Tag','Auf Wiedersehen']
    &&$deepLRequest['endpoint']===TranslationPolicy::FREE_ENDPOINT
    &&substr_count($deepLRequest['body'],'text=')===2
    &&str_contains($deepLRequest['body'],'source_lang=EN')&&str_contains($deepLRequest['body'],'target_lang=DE')
    &&($deepLRequest['headers']['Authorization']??'')==='DeepL-Auth-Key test-key',
    'DeepL adapter batches bounded text against the selected official endpoint');
foreach([
    array_replace(TranslationPolicy::defaults(),['provider'=>'deepl','translate_text'=>true,'target_language'=>'']),
    array_replace(TranslationPolicy::defaults(),['provider'=>'deepl','translate_text'=>true,'target_language'=>'DE','endpoint'=>'https://example.com/translate']),
    array_replace(TranslationPolicy::defaults(),['save_translated_text'=>true]),
]as$invalidTranslationPolicy){
    try{TranslationPolicy::validate($invalidTranslationPolicy);$check(false,'invalid translation policy accepted');}
    catch(InvalidArgumentException){$check(true,'invalid translation policy rejected');}
}
$credentialStore->delete('LORKHAN_DEEPL_API_KEY');unlink($credentialPath);rmdir($credentialRoot);
$preset=ConnectorCatalog::validate('tts_provider',['driver'=>'pockettts','endpoint'=>'http://127.0.0.1:8020','model'=>'default','voice'=>'default','language'=>'en','timeout_ms'=>30000,'options'=>[]]);
$check($preset['driver']==='pockettts' && $preset['timeout_ms']===30000, 'speech connector preset validation is strict and normalized');
$pocketPreset=static fn(string$endpoint):array=>['kind'=>'tts_provider','content'=>['driver'=>'pockettts','endpoint'=>$endpoint,
    'model'=>'pocket-tts','voice'=>'default','language'=>'en','timeout_ms'=>30000,'options'=>[]]];
$check(ProviderFactory::speechForPreset([],$pocketPreset('http://127.0.0.1:8024')) instanceof PocketTtsSpeechProvider
    &&ProviderFactory::speechForPreset([],$pocketPreset('http://127.0.0.1:8086')) instanceof PocketTtsSpeechProvider,
    'PocketTTS selected connectors use one runtime-compatible adapter for both API families');
$pocketAttempts=[];$pocketDetections=[];
$unavailableProvider=new class implements \LorkhanServer\Application\SpeechProvider {
    public function synthesize(string$text,\LorkhanServer\Application\CancellationToken$cancellation,array$context=[]):array
    {throw new RuntimeException('provider_unavailable');}
};
$workingProvider=new MockSpeechProvider();
$pocketFallback=new PocketTtsSpeechProvider('http://127.0.0.1:8024','pocket-tts','default','en',[], '',30000,null,
    static function(string$endpoint,string$mode)use(&$pocketAttempts,$unavailableProvider,$workingProvider):\LorkhanServer\Application\SpeechProvider{
        $pocketAttempts[]=$endpoint.'|'.$mode;return str_contains($endpoint,':8086')?$workingProvider:$unavailableProvider;},
    static function(string$endpoint,\LorkhanServer\Application\CancellationToken$cancellation)use(&$pocketDetections):string{
        $pocketDetections[]=$endpoint;return str_contains($endpoint,':8086')?'audio_cpp':'';});
$fallbackSpeech=$pocketFallback->synthesize('fallback route',new NeverCancelledToken());
$check(substr($fallbackSpeech['bytes'],0,4)==='RIFF'
    &&$pocketAttempts===['http://127.0.0.1:8024|standard','http://127.0.0.1:8086|audio_cpp']
    &&$pocketDetections===['http://127.0.0.1:8024','http://127.0.0.1:8086'],
    'unavailable known PocketTTS ports retry the first detected compatible same-host runtime');
$pocketAttempts=[];$pocketDetections=[];
$pocketHealthyFailure=new PocketTtsSpeechProvider('http://127.0.0.1:8024','pocket-tts','default','en',[], '',30000,null,
    static function(string$endpoint,string$mode)use(&$pocketAttempts,$unavailableProvider):\LorkhanServer\Application\SpeechProvider{
        $pocketAttempts[]=$endpoint.'|'.$mode;return$unavailableProvider;},
    static function(string$endpoint,\LorkhanServer\Application\CancellationToken$cancellation)use(&$pocketDetections):string{
        $pocketDetections[]=$endpoint;return'standard';});
try{$pocketHealthyFailure->synthesize('do not reroute',new NeverCancelledToken());$check(false,'healthy PocketTTS errors stay on the configured runtime');}
catch(RuntimeException$error){$check($error->getMessage()==='provider_unavailable'
    &&$pocketAttempts===['http://127.0.0.1:8024|standard']&&$pocketDetections===['http://127.0.0.1:8024'],
    'a detected configured PocketTTS service does not reroute valid provider failures');}
$customPortDetections=0;$pocketCustomPort=new PocketTtsSpeechProvider('http://127.0.0.1:8999','pocket-tts','default','en',[], '',30000,null,
    static fn(string$endpoint,string$mode):\LorkhanServer\Application\SpeechProvider=>$unavailableProvider,
    static function(string$endpoint,\LorkhanServer\Application\CancellationToken$cancellation)use(&$customPortDetections):string{$customPortDetections++;return'';});
try{$pocketCustomPort->synthesize('custom port',new NeverCancelledToken());$check(false,'custom PocketTTS ports remain authoritative');}
catch(RuntimeException$error){$check($error->getMessage()==='provider_unavailable'&&$customPortDetections===0,
    'custom PocketTTS ports never trigger known-port discovery');}
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
$multipartPath=tempnam(sys_get_temp_dir(),'lorkhan-stt-fields-');file_put_contents($multipartPath,str_repeat("\0",44));
$multipartFields=new ReflectionMethod(OpenAiCompatibleSpeechToTextProvider::class,'multipartFields');
$localFields=$multipartFields->invoke(new OpenAiCompatibleSpeechToTextProvider('http://127.0.0.1:9876/api/v0/transcribe',
    ['127.0.0.1'],'whisper-1','',30000,true,'audio_file',false),$multipartPath,'en-US');
$parakeetFields=$multipartFields->invoke(new OpenAiCompatibleSpeechToTextProvider('http://127.0.0.1:8022/v1/audio/transcriptions',
    ['127.0.0.1'],'whisper-1','secret',30000,true,'file',true,'LORKHAN,Nerevarine,Morrowind'),$multipartPath,'en-US');
$translationFields=$multipartFields->invoke(new OpenAiCompatibleSpeechToTextProvider('https://api.openai.com/v1/audio/translations',
    ['api.openai.com'],'whisper-1','secret',30000,false,'file',true,'',false),$multipartPath,'fr-FR');
$check(array_keys($localFields)===['audio_file']
    &&array_keys($parakeetFields)===['file','model','prompt','language']
    &&$parakeetFields['model']==='whisper-1'&&$parakeetFields['language']==='en'
    &&array_keys($translationFields)===['file','model'],
    'LocalWhisper, Parakeet, Whisper transcription, and Whisper translation multipart fields match CHIM');
unlink($multipartPath);
$voiceRoot=sys_get_temp_dir().'/lorkhan-zonos-'.bin2hex(random_bytes(4));mkdir($voiceRoot);
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
    $validator->validate($turn, 'lorkhan.turn.v1');
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

$temporary = sys_get_temp_dir() . '/lorkhan-state-' . bin2hex(random_bytes(8));
$store = new StateStore($temporary);
$store->mutate(static function (array &$state): void {
    $state['events'][] = ['id' => 'event-1'];
});
$check(count($store->load()['events']) === 1, 'state mutation persists');
$stateFile = $temporary . '/development-state.json';
$check((fileperms($stateFile) & 0777) === 0600, 'state file is private');
unlink($stateFile);
rmdir($temporary);

$globalSettings=SettingsCatalog::globalDefaults();
$globalSettings['client']['behavior']['rechat']=true;
$globalSettings['client']['behavior']['auto_greeting']=true;
$globalSettings['client']['narrator']['welcome_events']=true;
$globalSettings['oghma']['topic_count']=2;
$globalSettings['oghma']['racial_context_enabled']=false;
$globalSettings['oghma']['knowledge_tags']='Vvardenfell, Tribunal';
$globalSettings['oghma']['extractor_fallback_enabled']=true;
$globalSettings['oghma']['extractor_enabled']=true;
$globalSettings['relationship']=['enabled'=>true,'update_chance_percent'=>75];
$globalSettings['system_routing']=[
    'oghma_configuration_id'=>'00000000-0000-4000-8000-000000000222',
    'profile_generation_configuration_id'=>'00000000-0000-4000-8000-000000000333',
    'relationship_configuration_id'=>'00000000-0000-4000-8000-000000000444'];
$globalSettings['context']['sections']['nearby_items']=false;
$globalSettings['context']['item_blacklist']=['iron dagger'];
$coreLayer=['settings_overrides'=>['behavior'=>['rechat'=>false,'rechat_max_depth'=>4],
        'memory'=>['recent_turn_limit'=>7,'knowledge_limit'=>0],
        'oghma'=>['topic_count'=>3]],
    'routing'=>['llm_configuration_id'=>'00000000-0000-4000-8000-000000000111',
        'oghma_configuration_id'=>'00000000-0000-4000-8000-000000000555']];
$npcLayer=['settings_overrides'=>['behavior'=>['rechat'=>true]],
    'routing'=>['llm_configuration_id'=>''],'oghma_knowledge_tags'=>'Dagoth Ur'];
$effective=(new EffectiveSettingsResolver())->resolve($globalSettings,$coreLayer,$npcLayer);
$check($effective['settings']['behavior']['rechat']===false
    &&$effective['settings']['behavior']['rechat_max_depth']===4
    &&$effective['settings']['memory']['recent_turn_limit']===7
    &&$effective['settings']['memory']['knowledge_limit']===5
    &&$effective['routing']['llm_configuration_id']==='00000000-0000-4000-8000-000000000111'
    &&$effective['routing']['oghma_configuration_id']==='00000000-0000-4000-8000-000000000222',
    'Core Profiles own explicit response settings while NPC behavior and routing overrides stay inert');
$check($effective['settings']['oghma']['topic_count']===2
    &&$effective['settings']['oghma']['racial_context_enabled']===false
    &&($effective['sources']['settings.oghma.topic_count']??null)==='global'
    &&($effective['sources']['settings.oghma.racial_context_enabled']??null)==='global'
    &&($effective['sources']['settings.oghma.result_limit']??null)==='global',
    'Oghma controls are installation-wide Global Settings');
$check($effective['settings']['memory']['oghma_knowledge_tags']==='Dagoth Ur'
    &&($effective['sources']['settings.memory.oghma_knowledge_tags']??null)==='npc',
    'non-empty NPC knowledge tags remain character classification instead of a behavior override');
$check($effective['settings']['behavior']['auto_greeting']===false
    && $effective['settings']['behavior']['rechat_allow_actions']===false
    && $effective['settings']['behavior']['combat_barks']===false
    && $effective['settings']['narrator']['welcome_events']===false
    && ($effective['sources']['settings.behavior.combat_barks']??null)==='excluded',
    'excluded automation compatibility fields cannot become effective');
$check(($effective['sources']['settings.behavior.rechat']??null)==='core_profile'
    &&($effective['sources']['routing.llm_configuration_id']??null)==='core_profile'
    &&$effective['context']['sections']['nearby_items']===false
    &&$effective['context']['item_blacklist']===['iron dagger']
    && preg_match('/^[0-9a-f]{64}$/D',$effective['sha256'])===1,
    'effective settings retain ownership provenance, context policy, and a canonical hash');
$relationshipResolved=(new EffectiveSettingsResolver())->resolve($globalSettings,
    ['routing'=>[],'settings_overrides'=>['relationship'=>['update_chance_percent'=>100,'locked'=>true]]],
    ['routing'=>['relationship_configuration_id'=>''],'settings_overrides'=>['relationship'=>['locked'=>true]]]);
$check($relationshipResolved['settings']['relationship']===['update_chance_percent'=>75,'locked'=>false]
    &&$relationshipResolved['routing']['relationship_configuration_id']==='00000000-0000-4000-8000-000000000444'
    &&$relationshipResolved['sources']['settings.relationship.update_chance_percent']==='global',
    'relationship policy and connector routing are installation-wide Global Settings');
try{EffectiveSettingsResolver::validateSettingsOverrides(['relationship'=>['update_chance_percent'=>101]]);
    $check(false,'relationship chance outside 0-100 rejected');}
catch(InvalidArgumentException){$check(true,'relationship chance outside 0-100 rejected');}
$diaryResolved=(new EffectiveSettingsResolver())->resolve($globalSettings,['routing'=>[
    'diary_generation_configuration_id'=>'00000000-0000-4000-8000-000000000555'],
    'settings_overrides'=>['diary'=>$diaryOverrides]],[]);
$check($diaryResolved['settings']['diary']===$diaryOverrides
    &&$diaryResolved['routing']['diary_generation_configuration_id']==='00000000-0000-4000-8000-000000000555'
    &&$diaryResolved['sources']['settings.diary.enabled']==='core_profile',
    'manual diary policy and dedicated connector route inherit through effective server settings');
$projectionInput=$effective;
$projectionInput['settings']['presentation']=['show_status_hud'=>false,'transcript_rows'=>20,'tts_volume_boost'=>4];
$projectionInput['routing']['profile_generation_configuration_id']='00000000-0000-4000-8000-000000000333';
$projectionInput['routing']['relationship_configuration_id']='00000000-0000-4000-8000-000000000444';
$projectionInput['routing']['diary_generation_configuration_id']='00000000-0000-4000-8000-000000000555';
$projectionInput['settings']['diary']=$diaryOverrides;
$projection=EffectiveSettingsResolver::controlsProjection($projectionInput);
$check($projection['settings']['behavior']['rechat']===false
    &&$projection['settings']['memory']['knowledge_limit']===EffectiveSettingsResolver::defaults()['memory']['knowledge_limit']
    &&$projection['routing']['llm_configuration_id']==='00000000-0000-4000-8000-000000000111'
    &&$projection['source_map']['settings.behavior.rechat']==='core_profile'
    &&!array_key_exists('settings.memory.knowledge_limit',$projection['source_map'])
    &&$projection['settings']['presentation']===EffectiveSettingsResolver::defaults()['presentation'],
    'controls retain typed overrides while presentation remains inert v1 compatibility data');
$check(!isset($projection['settings']['memory']['oghma_knowledge_tags'])
    &&!isset($projection['routing']['oghma_configuration_id'])
    &&!isset($projection['routing']['profile_generation_configuration_id'])
    &&!isset($projection['routing']['relationship_configuration_id'])&&!isset($projection['settings']['relationship'])
    &&!isset($projection['routing']['diary_generation_configuration_id'])&&!isset($projection['settings']['diary'])
    &&!array_key_exists('settings.memory.oghma_knowledge_tags',$projection['source_map'])
    &&!in_array('excluded',$projection['source_map'],true)
    &&$effective['routing']['oghma_configuration_id']==='00000000-0000-4000-8000-000000000222'
    &&$projection['settings']['behavior']['auto_greeting']===false
    &&$projection['settings']['behavior']['rechat_allow_actions']===false,
    'controls omit server-only settings and provenance without altering internal resolution or enabling automation');
try{
    EffectiveSettingsResolver::validateSettingsOverrides(['behavior'=>['unknown_setting'=>true]]);
    $check(false,'unknown layered setting rejected');
}catch(InvalidArgumentException){$check(true,'unknown layered setting rejected');}

$mediaRoot = sys_get_temp_dir() . '/lorkhan-media-unit-' . bin2hex(random_bytes(8));
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
        'install -d -o lorkhan -g www-data -m 2770 /var/lib/lorkhanserver/media'),
        $scriptName . ' preserves shared media write access');
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} server checks failed\n");
    exit(1);
}
echo "{$checks} server checks passed\n";
