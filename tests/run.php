<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/Autoload.php';
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

$recordedCalendar=\LorkhanServer\Application\MorrowindCalendar::parse(['year'=>427,'month'=>7,'day'=>16,'hour'=>9.5]);
$check($recordedCalendar['date']==='0427-08-16' && $recordedCalendar['label']==='16 Last Seed, 3E 427 · 09:30', 'Morrowind zero-based calendar month and hour');
$check(\LorkhanServer\Application\MorrowindCalendar::parse(['year'=>428,'month'=>1,'day'=>29])===null
    &&\LorkhanServer\Application\MorrowindCalendar::parse(['year'=>427,'month'=>12,'day'=>1])===null
    &&\LorkhanServer\Application\MorrowindCalendar::parse(null)===null, 'Morrowind calendar rejects leap days and unknown dates');

$token = PairingToken::generate();
$hash = PairingToken::hash($token);
$check(strlen($token) >= 43, 'pairing token has 256 bits');
$check(PairingToken::verifyAuthorization('Bearer ' . $token, $hash), 'pairing token verifies');
$check(!PairingToken::verifyAuthorization('Bearer wrong', $hash), 'wrong token rejected');
$check(Redactor::value(['authorization' => 'Bearer ' . $token])['authorization'] === '[REDACTED]', 'authorization redacted');
$check(!str_contains((string) Redactor::value('Bearer ' . $token), $token), 'embedded bearer token redacted');
require dirname(__DIR__).'/ui/tmpl/server_log_reader.php';
$logFixture="[2026-09-06T12:00:00Z] [ERROR] failed Authorization: Bearer fixture-bearer\n  stack frame\n".
    '{"timestamp":"2026-09-06T12:01:00Z","level":"warning","message":"retry","api_key":"fixture-json","provider_key":"fixture-provider"}'."\n".
    '[Sun Sep 06 12:02:00.123 2026] [php:error] password="fixture quoted password"';
$logEntries=lorkhan_ui_log_entries($logFixture);
$logProjection=json_encode($logEntries);
$check(count($logEntries)===3 && $logEntries[0]['level']==='error' && $logEntries[0]['iso']===''
    && $logEntries[1]['level']==='warn' && $logEntries[1]['iso']==='2026-09-06T12:01:00+00:00'
    && str_contains($logEntries[2]['message'],'stack frame'), 'server log rows preserve severity, timezone knowledge and multiline entries in newest-first order');
$check(!str_contains($logProjection,'fixture-bearer') && !str_contains($logProjection,'fixture-json')
    && !str_contains($logProjection,'fixture-provider') && !str_contains($logProjection,'fixture quoted password'), 'server log projection redacts bearer, JSON and quoted credentials');
$check(!str_contains(lorkhan_ui_redact_log('GET /?token=fixture-url&next=1 Authorization: Basic fixture-basic'),'fixture-')
    && lorkhan_ui_log_entries('first'."\n".'second',true)[0]['message']==='second', 'raw log rows redact URL and Basic credentials and reverse line order');
$logTailFixture=tempnam(sys_get_temp_dir(),'lorkhan-log-tail-');
file_put_contents($logTailFixture,"oversized first line\nsecond\nthird\n");
$check(lorkhan_ui_log_tail($logTailFixture,18,1)==='third', 'bounded log tail discards partial first line and respects line limit');
unlink($logTailFixture);
require dirname(__DIR__).'/ui/tmpl/oghma_audit_reader.php';
$auditCard=lorkhan_oghma_audit_card(['result_count'=>1,'result_ids'=>['article-one'],'scores'=>['article-one'=>0.95],
    'reasons'=>['article-one'=>['topic'=>'Sixth House','signal'=>'House Dagoth','source'=>'grounded','score'=>0.95,'selected'=>true,'reason'=>'exact topic alias'],
        '_context'=>['extractor_status'=>'grounded','extracted_topics'=>['sixth_house'],'location_signals'=>['Seyda Neen']]],
    'selected_topics'=>[],'profile_name'=>'Fargoth','input_kind'=>'text','query'=>'Tell me about House Dagoth.']);
$check($auditCard['metadata']['Selected Topic']==='Sixth House' && $auditCard['metadata']['Rank']==='0.95'
    && $auditCard['metadata']['Elapsed']==='(not recorded)' && $auditCard['metadata']['Entry ID']==='article-one'
    && str_contains($auditCard['sections']['Signals Used For Ranking'],'signal=House Dagoth'), 'Oghma audit preserves historical topic evidence and real scores without inventing elapsed time');
$check(lorkhan_oghma_audit_card(['result_count'=>0])['metadata']['Status']==='No Match'
    && $auditCard['sections']['Context Snapshot']==='location=Seyda Neen', 'Oghma audit distinguishes unmatched and recorded context');
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
$frontController = (string) file_get_contents(dirname(__DIR__) . '/index.php');
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
$check(\LorkhanServer\Application\RelationshipDetails::validate(['relation'=>'mentor','best'=>str_repeat('古',1024)])===
    ['relation'=>'mentor','note'=>'','best'=>str_repeat('古',1024),'worst'=>''],'relationship detail fields normalize without private notes');
foreach([null,'text',['unknown'=>'x'],['note'=>null],['best'=>str_repeat('x',1025)],['worst'=>"bad\0text"],['relation'=>[]]]as$badDetails){
    try{\LorkhanServer\Application\RelationshipDetails::validate($badDetails);$check(false,'invalid relationship details accepted');}
    catch(\InvalidArgumentException $error){$check($error->getMessage()==='invalid_relationship_details','invalid relationship details rejected');}
}
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
$check(\LorkhanServer\Application\RelationshipBuildPolicy::direction('  Focus on House hierarchy.  ')==='Focus on House hierarchy.'
    &&\LorkhanServer\Application\RelationshipBuildPolicy::direction('')==='', 'history build normalizes optional player direction');
foreach([[],null,"bad\0text","\xff",str_repeat('x',2001)]as$badDirection){
    try{\LorkhanServer\Application\RelationshipBuildPolicy::direction($badDirection);$check(false,'invalid build direction accepted');}
    catch(InvalidArgumentException){$check(true,'invalid build direction rejected');}
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
$diaryOverrides=['enabled'=>true,'automatic_enabled'=>true,'automatic_wait_enabled'=>false,
    'automatic_interval_seconds'=>120,'include_in_context'=>false,'latest_entry_in_context'=>false,'context_turn_limit'=>12,
    'prompt'=>'Remember only what was witnessed.'];
$check(\LorkhanServer\Application\DiaryGenerationPolicy::validateOverrides(['context_turn_limit'=>0])===['context_turn_limit'=>0]
    &&\LorkhanServer\Application\DiaryGenerationPolicy::validateOverrides(['context_turn_limit'=>400])===['context_turn_limit'=>400],
    'diary history accepts inheritance and the reference upper limit');
$check(\LorkhanServer\Application\DiaryGenerationPolicy::validateOverrides(['automatic_interval_seconds'=>10])===['automatic_interval_seconds'=>10]
    &&\LorkhanServer\Application\DiaryGenerationPolicy::validateOverrides(['automatic_interval_seconds'=>86400])===['automatic_interval_seconds'=>86400],
    'diary cooldown accepts the reference minimum and preserves existing long intervals');
$diaryMock=(new \LorkhanServer\Application\MockProfileGenerationProvider())->generate(
    ['generation_mode'=>'diary_generation','name'=>'Fargoth','witnessed_context'=>[['type'=>'inputtext']]],new NeverCancelledToken());
$check($diaryDefaults['enabled']===false&&$diaryDefaults['automatic_enabled']===false
    &&$diaryDefaults['automatic_wait_enabled']===false&&$diaryDefaults['automatic_interval_seconds']===120
    &&$diaryDefaults['include_in_context']===true&&$diaryDefaults['latest_entry_in_context']===false&&$diaryDefaults['context_turn_limit']===20
    &&\LorkhanServer\Application\DiaryGenerationPolicy::validateOverrides($diaryOverrides)===$diaryOverrides
    &&$diaryMock===['title'=>'Fargoth diary','content'=>'Fargoth records 1 witnessed Morrowind event.']
    &&in_array('narrative.generate',\LorkhanServer\Application\FirstPartyJobHandlerFactory::jobTypes(),true),
    'manual and automatic diary generation are opt-in, bounded, deterministic under the mock provider, and registered as durable work');
foreach([
    ['enabled'=>'true'],['automatic_enabled'=>1],['automatic_wait_enabled'=>'true'],['automatic_interval_seconds'=>9],
    ['automatic_interval_seconds'=>86401],['include_in_context'=>1],['latest_entry_in_context'=>'true'],['context_turn_limit'=>-1],
    ['context_turn_limit'=>401],['prompt'=>''],['unknown'=>true],
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
$customLlm=LlmConnector::validate($directLlm+['service'=>'custom']);
$check($customLlm['service']==='custom' && !isset($validatedLlm['service']) && $customLlm['options']===$validatedLlm['options'], 'custom editor service survives validation without changing legacy transport');
try { LlmConnector::validate($directLlm+['service'=>'unknown']); $check(false,'unknown editor service rejected'); }
catch(InvalidArgumentException $error) { $check($error->getMessage()==='invalid_provider_service','unknown editor service rejected'); }

$check($validatedLlm['credential']==='none'&&$validatedLlm['timeout_ms']===30000
    &&$validatedLlm['options']['reasoning_model']===true
    &&LlmConnector::requestOptions($validatedLlm['options'],null,true)===['temperature'=>0,'top_p'=>0]
    &&LlmConnector::requestOptions([],null,false)===['response_format'=>['type'=>'json_object']],
    'explicit LLM connectors preserve zero and false while leaving absent sampling parameters to the provider');
$directSlot=['configuration_id'=>'00000000-0000-4000-8000-000000000123','revision'=>1,'content'=>$directLlm];
$providerOrder=['google-vertex', 'together', 'google-vertex/us-east5'];
$check(LlmConnector::validateOptions(['provider_order'=>$providerOrder])===['provider_order'=>$providerOrder]
    &&LlmConnector::requestOptions(['provider_order'=>$providerOrder,'json_mode'=>false],null,false)===['provider'=>['order'=>$providerOrder]]
    &&LlmConnector::requestOptions(['provider_order'=>[],'json_mode'=>false],null,false)===[],
    'provider preferences preserve order and map only to provider.order without disabling fallbacks');
$auditProvider=new \LorkhanServer\Application\OpenAiCompatibleProfileGenerationProvider('http://127.0.0.1:9/v1/chat/completions',
    ['127.0.0.1'],'fixture-model','fixture-secret',allowLoopbackHttp:true,directConnection:true);
foreach(['relationship_evaluation','relationship_build','player_speech_style']as$auditMode){
$auditMessages=[];$auditInput=['generation_mode'=>$auditMode,'input'=>'A recorded exchange.','user_direction'=>'Focus on House hierarchy.'];
if($auditMode==='player_speech_style')$auditInput+=['name'=>'Test Player','speech_style_prompt'=>'Describe {PLAYER_NAME}: {PLAYER_GUIDANCE}; {CURRENT_SPEECH_STYLE}; {DIALOGUE_SAMPLES}.','speech_style_guidance'=>'Terse vocabulary.','current_speech_style'=>'Short sentences.','recent_player_inputs'=>['Where is the guild?']];
try{$auditProvider->generate($auditInput,new NeverCancelledToken(),static function(array $messages)use(&$auditMessages):void{
    $auditMessages=$messages;throw new RuntimeException('audit-observed-before-network');
});$check(false,'request observer must run before network');}
catch(RuntimeException $error){$check($error->getMessage()==='audit-observed-before-network'
    &&array_column($auditMessages,'role')===['system','user']&&json_decode($auditMessages[1]['content'],true)===$auditInput
    &&!str_contains(json_encode($auditMessages),'fixture-secret')
    &&($auditMode!=='relationship_build'||str_contains($auditMessages[0]['content'],'user_direction'))
    &&($auditMode!=='player_speech_style'||(str_contains($auditMessages[0]['content'],'Describe Test Player: Terse vocabulary.; Short sentences.; - Where is the guild?.')&&str_contains($auditMessages[0]['content'],'exactly one non-empty string key: speech_style'))),
    'relationship request observer captures exact messages without credentials or network I/O');}
}
try{$auditProvider->generate(['generation_mode'=>'player_speech_style','speech_style_prompt'=>str_repeat('{DIALOGUE_SAMPLES}',100),'recent_player_inputs'=>[str_repeat('a',1000)]],new NeverCancelledToken());$check(false,'oversized template expansion accepted');}
catch(RuntimeException $error){$check($error->getMessage()==='profile_input_too_large','template expansion is bounded before provider I/O');}
$schema=LlmConnector::objectSchema(['text'=>['type'=>'string']]);
$bodyYaml="temperature: 0.25\nprovider:\n  order: [together, google-vertex]\nstop: [END, 'a,b']\nlogit_bias: {}\nmetadata:\n  label: 'literal <img src=x>'\n";
$bodyOptions=['extra_parameters_yaml'=>$bodyYaml,'extra_parameters_enabled'=>true,'json_mode'=>false];
$bodyRequest=LlmConnector::requestOptions($bodyOptions,0.7,false);
$check($bodyRequest['temperature']===0.25&&$bodyRequest['provider']->order===['together','google-vertex']
    &&$bodyRequest['stop']===['END','a,b']&&$bodyRequest['logit_bias'] instanceof \stdClass
    &&$bodyRequest['metadata']->label==='literal <img src=x>','YAML body parameters retain nested maps, lists, empty objects and literal text');
$check(LlmConnector::requestOptions(array_replace($bodyOptions,['extra_parameters_enabled'=>false]),0.7,false)===['temperature'=>0.7]
    &&LlmConnector::validateOptions($bodyOptions)['extra_parameters_yaml']===$bodyYaml,
    'disabled YAML is retained verbatim without request injection');
$check(LlmConnector::requestOptions(['extra_parameters_yaml'=>'temperature: 0','json_mode'=>false],0.7,false)===['temperature'=>0.7]
    &&LlmConnector::requestOptions(['extra_parameters_enabled'=>true,'extra_parameters_yaml'=>'','json_mode'=>false],null,false)===[],
    'YAML is opt-in and an empty document adds no parameters');
$check(\LorkhanServer\Application\LlmBodyParameters::parse("# A saved note\n  # More notes\n")===[], 'comment-only YAML is an empty body extension');
$check(LlmConnector::requestOptions(['extra_parameters_enabled'=>true,'extra_parameters_yaml'=>"response_format: {type: text}\nreasoning: {enabled: true}",'json_schema'=>true,'disable_reasoning'=>true],null,false,$schema)
    ===['response_format'=>['type'=>'json_schema','json_schema'=>['name'=>'response','strict'=>true,'schema'=>$schema]],'reasoning'=>['exclude'=>true,'enabled'=>false]],
    'Enforce JSON and Disable reasoning override YAML as in the reference request flow');
foreach(["key: [broken",'- list',"!!php/object 'O:8:stdClass:0:{}'",'key: !php/const PHP_VERSION',
    "a: &a [1, 2]\nb: *a",'key: .inf','key: .nan','null',str_repeat('a',16385),
    'messages: []','stream: false','tools: []','n: 2',"provider:\n  api_key: never-store-this",'headers: {Authorization: hidden}',
    'url: https://example.invalid',"a: null\na: 1",implode("\n",array_map(static fn(int $i):string=>str_repeat(' ',2*$i).'a:',range(0,20)))]as$invalidYaml){
    try{LlmConnector::validateOptions(['extra_parameters_yaml'=>$invalidYaml]);$check(false,'unsafe or malformed YAML rejected');}
    catch(InvalidArgumentException $error){$check(in_array($error->getMessage(),['invalid_provider_body_yaml','reserved_provider_body_parameter'],true),'unsafe or malformed YAML rejected without echoing input');}
}
$check(LlmConnector::requestOptions(['json_schema'=>true],null,false,$schema)['response_format']['json_schema']['schema']===$schema
    &&!isset(LlmConnector::requestOptions(['json_schema'=>true,'json_mode'=>false],null,false)['response_format']),
    'schema output is opt-in and Enforce JSON off suppresses it without discarding the preference');
$prefillMessages=[['role'=>'user','content'=>'Fixture input']];
$prefix=LlmConnector::prefillMessages($prefillMessages,['prefill_json'=>true],'utterances');
$check($prefix==='{"utterances":'&&$prefillMessages[1]===['role'=>'assistant','content'=>$prefix]
    &&LlmConnector::decodeResponse('[{"text":"Hello."}],"action":null}',$prefix)===['utterances'=>[['text'=>'Hello.']],'action'=>null]
    &&LlmConnector::decodeResponse('{"utterances":[{"text":"Hello."}],"action":null}',$prefix)===['utterances'=>[['text'=>'Hello.']],'action'=>null],
    'prefill restores continuation JSON but does not duplicate a complete provider response');
try{LlmConnector::decodeResponse('not JSON',$prefix);$check(false,'invalid prefilled response rejected');}
catch(\JsonException){$check(true,'invalid prefilled response rejected');}
$prefillProvider=new \LorkhanServer\Application\OpenAiCompatibleProfileGenerationProvider('http://127.0.0.1:9/v1/chat/completions',
    ['127.0.0.1'],'fixture-model','fixture-secret',options:['prefill_json'=>true,'json_schema'=>true],allowLoopbackHttp:true,directConnection:true);
foreach(['relationship_evaluation'=>'disposition_delta','relationship_build'=>'relationships','diary_generation'=>'title']as$mode=>$field){
    try{$prefillProvider->generate(['generation_mode'=>$mode],new NeverCancelledToken(),static function(array $messages)use($field,$check):void{
        $check(array_column($messages,'role')===['system','user','assistant']&&$messages[2]['content']==='{'.json_encode($field).':'
            &&!str_contains(json_encode($messages),'fixture-secret'),'generation observer includes the exact prefill before network without credentials');
        throw new RuntimeException('observed-prefill');
    });$check(false,'prefill observer did not run');}
    catch(RuntimeException $error){if($error->getMessage()!=='observed-prefill')throw $error;}
}
$check(ProviderFactory::dialogueForSlot(['provider'=>['api_key_env'=>'UNRELATED_SECRET']],$directSlot) instanceof OpenAiCompatibleProvider
    &&ProviderFactory::oghmaTopicExtractorForSlot([],$directSlot) instanceof \LorkhanServer\Application\OpenAiCompatibleOghmaTopicExtractor
    &&ProviderFactory::profileGenerationForSlot(['provider'=>['driver'=>'invalid-runtime','api_key_env'=>'UNRELATED_SECRET']],$directSlot) instanceof \LorkhanServer\Application\OpenAiCompatibleProfileGenerationProvider,
    'dialogue, Oghma and profile generation resolve explicit connectors without inheriting runtime credentials');
$runtimeKeyBefore=getenv('LORKHAN_TEST_RUNTIME_API_KEY');$selectedKeyBefore=getenv('LORKHAN_CUSTOM_PARITY_FIXTURE_API_KEY');
putenv('LORKHAN_TEST_RUNTIME_API_KEY=runtime-fixture-key');putenv('LORKHAN_CUSTOM_PARITY_FIXTURE_API_KEY=selected-fixture-key');
try {
    $keyConfig=['provider'=>['driver'=>'openai-compatible','endpoint'=>'http://127.0.0.1:9/v1/chat/completions',
        'allowed_hosts'=>['127.0.0.1'],'allow_loopback_http'=>true,'model'=>'runtime-model','api_key_env'=>'LORKHAN_TEST_RUNTIME_API_KEY']];
    foreach ([null=>'runtime-fixture-key','none'=>'','custom:PARITY_FIXTURE'=>'selected-fixture-key','badge:LORKHAN_CUSTOM_PARITY_FIXTURE_API_KEY'=>'selected-fixture-key'] as $reference=>$expectedKey) {
        $keyContent=['driver'=>'configured','model'=>'selected-model'];if($reference!=='')$keyContent['credential']=$reference;
        $keySlot=array_replace($directSlot,['content'=>LlmConnector::validate($keyContent)]);
        foreach (['dialogueForSlot','profileGenerationForSlot','oghmaTopicExtractorForSlot'] as $factory) {
            $keyProvider=ProviderFactory::$factory($keyConfig,$keySlot);
            $check((new \ReflectionProperty($keyProvider,'apiKey'))->getValue($keyProvider)===$expectedKey,
                $factory.' respects inherited, explicit None and selected configured credentials');
        }
    }
    $check(LlmConnector::credentialVariable('badge:LORKHAN_TTS_OPENAI_API_KEY')==='LORKHAN_TTS_OPENAI_API_KEY',
        'LLM connector can select an existing global speech badge without copying its secret');
    foreach (['UNRELATED_SECRET','badge:UNRELATED_SECRET','badge:',42] as $badReference) {
        try { LlmConnector::validate(['driver'=>'configured','model'=>'fixture','credential'=>$badReference]);$check(false,'invalid configured key rejected'); }
        catch(InvalidArgumentException){$check(true,'invalid configured key rejected');}
    }
} finally {
    putenv($runtimeKeyBefore===false?'LORKHAN_TEST_RUNTIME_API_KEY':'LORKHAN_TEST_RUNTIME_API_KEY='.$runtimeKeyBefore);
    putenv($selectedKeyBefore===false?'LORKHAN_CUSTOM_PARITY_FIXTURE_API_KEY':'LORKHAN_CUSTOM_PARITY_FIXTURE_API_KEY='.$selectedKeyBefore);
}
$diagnosticProvider=new OpenAiCompatibleProvider('http://127.0.0.1:9/v1/chat/completions',['127.0.0.1'],'fixture','diagnostic-fixture-key',
    options:['extra_parameters_enabled'=>true,'extra_parameters_yaml'=>"metadata:\n  label: diagnostic-fixture-key\nlogit_bias: {}",'json_schema'=>true],allowLoopbackHttp:true);
try {
    $diagnosticProvider->completeStreaming(['payload'=>['input'=>['text'=>'Hello']]],new NeverCancelledToken(),static function(string $delta):void{},
        static function(string $stage,array $body)use($check):void{
            $check($stage==='request'&&$body['metadata']->label==='[REDACTED]'&&$body['logit_bias'] instanceof \stdClass
                &&isset($body['messages'],$body['response_format'])&&!str_contains(json_encode($body),'diagnostic-fixture-key'),
                'explicit diagnostic observer captures request shape and redacts nested object values without headers');
            throw new RuntimeException('diagnostic-observed');
        });
    $check(false,'diagnostic observer did not run');
}catch(RuntimeException $error){if($error->getMessage()!=='diagnostic-observed')throw$error;}
$networkRoutes=[['dst'=>'default','dev'=>'eth0','gateway'=>'192.168.160.1']];
$networkInterfaces=[['ifname'=>'eth0','addr_info'=>[['family'=>'inet','local'=>'192.168.169.218']]]];
$networkClass=\LorkhanServer\Application\QuickstartLocalLlm::class;
$check($networkClass::networkAddresses('nat',$networkRoutes,$networkInterfaces)===['host_ip'=>'192.168.160.1','wsl_ip'=>'192.168.169.218'],'Local LLM WSL NAT shortcuts use the default interface');
$check($networkClass::networkAddresses('mirrored',$networkRoutes,$networkInterfaces)['host_ip']==='127.0.0.1','mirrored WSL uses Windows loopback');
$check($networkClass::networkAddresses('unknown',$networkRoutes,$networkInterfaces)['host_ip']==='','unknown networking does not guess a Windows host');
$check($networkClass::networkAddresses('nat',[],[])===['host_ip'=>'','wsl_ip'=>''],'unavailable network addresses keep shortcuts disabled');
$check($networkClass::networkAddresses('nat',[['dst'=>'default','dev'=>'eth0','gateway'=>'not-an-ip']],[])===['host_ip'=>'','wsl_ip'=>''],'invalid network data is not emitted into shortcuts');
$localSetup=\LorkhanServer\Application\QuickstartLocalLlm::normalize(['model'=>' fixture ','endpoint'=>'http://127.0.0.1:1234/v1/chat/completions']);
$check($localSetup['server_type']==='lm_studio'&&$localSetup['scope']==='conversations'&&$localSetup['content']['timeout_ms']===30000
    &&$localSetup['content']['options']['stream']===true&&$localSetup['content']['credential']==='none','Local LLM setup defaults match reference');
foreach([['timeout_seconds'=>4],['timeout_seconds'=>121],['server_type'=>'unknown'],['scope'=>'selected'],['disable_streaming'=>'false'],['api_key'=>'secret-fixture']]as$invalidSetup){
    try{\LorkhanServer\Application\QuickstartLocalLlm::normalize($invalidSetup+['model'=>'fixture','endpoint'=>'http://localhost:1234/v1/chat/completions']);$check(false,'invalid local setup rejected');}
    catch(InvalidArgumentException){$check(true,'invalid local setup rejected');}
}
// Explicit Local LLM transport must never widen legacy/public connector access.
foreach(['localhost','127.0.0.1','10.0.0.2','172.16.1.2','172.31.255.254','192.168.1.4','[::1]','[fd12::1]','[fc00::2]'] as $localHost){
    $localContent=LlmConnector::validate(['driver'=>'openai-compatible','service'=>'local','model'=>'fixture','endpoint'=>'http://'.$localHost.':1234/v1/chat/completions','credential'=>'none']);
    $network=\LorkhanServer\Security\OutboundUrlPolicy::curlOptions($localContent['endpoint'],[$localHost],false,false,true);
    $check($network[CURLOPT_PROXY]==='', 'local LLM literal/localhost transport bypasses proxy resolution');
    $slot=['configuration_id'=>'00000000-0000-4000-8000-000000000001','revision'=>1,'content'=>$localContent];
    $check(ProviderFactory::dialogueForSlot([], $slot) instanceof OpenAiCompatibleProvider
        && ProviderFactory::profileGenerationForSlot([], $slot) instanceof \LorkhanServer\Application\OpenAiCompatibleProfileGenerationProvider
        && ProviderFactory::oghmaTopicExtractorForSlot([], $slot) instanceof \LorkhanServer\Application\OpenAiCompatibleOghmaTopicExtractor,
        'dialogue, profile and topic adapters retain explicit local connector policy');
}
foreach(['8.8.8.8','169.254.169.254','100.100.100.200','0.0.0.0','224.0.0.1','172.32.0.1','example.com','[fe80::1]','[fd00:ec2::254]','[::ffff:192.168.1.1]'] as $unsafeHost){
    try{LlmConnector::validate(['driver'=>'openai-compatible','service'=>'local','model'=>'fixture','endpoint'=>'http://'.$unsafeHost.'/v1/chat/completions']);$check(false,'local connector rejects non-local/metadata hosts');}
    catch(InvalidArgumentException){$check(true,'local connector rejects non-local/metadata hosts');}
    try{\LorkhanServer\Security\OutboundUrlPolicy::curlOptions('https://'.$unsafeHost.'/v1/chat/completions',[$unsafeHost],false,true,true);$check(false,'local transport rejects non-local/metadata hosts');}
    catch(InvalidArgumentException){$check(true,'local transport rejects non-local/metadata hosts');}
}
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
    ['options'=>['provider_order'=>'together']],['options'=>['provider_order'=>['a'=>'together']]],
    ['options'=>['provider_order'=>array_fill(0,17,'together')]],['options'=>['provider_order'=>['']]],
    ['options'=>['provider_order'=>[str_repeat('a',129)]]],['options'=>['provider_order'=>["bad\nslug"]]],
    ['options'=>['provider_order'=>[' leading']]],['options'=>['provider_order'=>['a,b']]],
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
$prefilledStream=new StreamingDialogueText();
$check($prefilledStream->push('[{"text":"The first sentence arrives immediately. More words')===['The first sentence arrives immediately.'],
    'assistant continuation streams its first complete sentence without waiting for the closing JSON');
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
foreach (['gpt-4o-mini-tts','tts-1','tts-1-hd'] as $speechModel) {
    $speechProvider = new OpenAiCompatibleSpeechProvider('http://127.0.0.1:8999/v1/audio/speech', ['127.0.0.1'], $speechModel, 'alloy', '', 30000, true, null, null, ['instructions'=>"Speak softly.\nTake your time."]);
    $speechPayload = (new ReflectionMethod($speechProvider,'requestPayload'))->invoke($speechProvider,'Hello.','alloy');
    $check(($speechPayload['instructions'] ?? null) === ($speechModel === 'gpt-4o-mini-tts' ? "Speak softly.\nTake your time." : null)
        &&$speechPayload['response_format']==='wav', $speechModel.' only receives supported instructions and retains WAV output');
}
foreach (['eleven_v3','eleven_multilingual_v2'] as $speechModel) {
    $speechProvider = new CloudSpeechConnectorProvider('https://93.184.216.34','11labs',$speechModel,'fixture','ja-JP',
        ['optimize_streaming_latency'=>2,'speed'=>0.9,'use_speaker_boost'=>true,'apply_text_normalization'=>'off',
            'apply_language_text_normalization'=>true,'v3_audio_tags'=>'[whispers]'],'fake-test-key');
    [$speechUrl,$speechBody] = (new ReflectionMethod($speechProvider,'request'))->invoke($speechProvider,'Hello.','fixture','ja-JP');
    $speechPayload = json_decode($speechBody,true,512,JSON_THROW_ON_ERROR);
    $check(str_ends_with($speechUrl,'output_format=wav_22050&optimize_streaming_latency=2')
        &&$speechPayload['text']===($speechModel==='eleven_v3'?'[whispers] Hello.':'Hello.')
        &&isset($speechPayload['voice_settings']['use_speaker_boost'])===($speechModel!=='eleven_v3')
        &&$speechPayload['voice_settings']['speed']===0.9&&$speechPayload['apply_text_normalization']==='off'
        &&$speechPayload['apply_language_text_normalization']===true,
        $speechModel.' maps editor controls to the native query/body and applies model-specific tags and boost');
}
$playerElevenProvider = new CloudSpeechConnectorProvider('https://93.184.216.34','11labs','eleven_multilingual_v2','fixture','en',
    ['speed'=>0.9,'stability'=>0.75,'use_speaker_boost'=>true],'fake-test-key');
$playerElevenRequest = new ReflectionMethod($playerElevenProvider,'request');
[, $playerElevenBody] = $playerElevenRequest->invoke($playerElevenProvider,'Hello.','fixture','en',
    ['player_elevenlabs'=>['model_id'=>'eleven_v3','speed'=>1.1,'style'=>0.0,'v3_audio_tags'=>'[curious]']]);
$playerElevenPayload=json_decode($playerElevenBody,true,512,JSON_THROW_ON_ERROR);
$check($playerElevenPayload['model_id']==='eleven_v3' && $playerElevenPayload['text']==='[curious] Hello.'
    && $playerElevenPayload['voice_settings']['speed']===1.1 && $playerElevenPayload['voice_settings']['stability']===0.75
    && !isset($playerElevenPayload['voice_settings']['use_speaker_boost']), 'player overrides inherit unspecified options and apply v3 semantics');
[, $defaultElevenBody] = $playerElevenRequest->invoke($playerElevenProvider,'Hello.','fixture','en');
$defaultElevenPayload=json_decode($defaultElevenBody,true,512,JSON_THROW_ON_ERROR);
$check($defaultElevenPayload['model_id']==='eleven_multilingual_v2' && $defaultElevenPayload['text']==='Hello.'
    && $defaultElevenPayload['voice_settings']['speed']===0.9, 'player overrides do not mutate shared provider defaults');
foreach ([['speed'=>0],['stability'=>2],['style'=>'0.5'],['model_id'=>''],['use_speaker_boost'=>'false'],['api_key'=>'secret']] as $invalidPlayerVoice) {
    try { CloudSpeechConnectorProvider::validatePlayerOverrides($invalidPlayerVoice); $check(false,'invalid player voice override rejected'); }
    catch (InvalidArgumentException) { $check(true,'invalid player voice override rejected'); }
}
foreach ([null,8000,16000,24000,32000,48000] as $sampleRate) {
    $speechOptions = $sampleRate===null ? [] : ['bitrate'=>$sampleRate];
    ConnectorCatalog::validate('tts_provider',ConnectorCatalog::defaults('tts_provider','deepgram')+['driver'=>'deepgram','options'=>$speechOptions]);
    $speechProvider = new CloudSpeechConnectorProvider('https://93.184.216.34','deepgram','default','fixture','en-US',$speechOptions,'fake-test-key');
    [$speechUrl] = (new ReflectionMethod($speechProvider,'request'))->invoke($speechProvider,'Hello.','fixture','en-US');
    parse_str((string)parse_url($speechUrl,PHP_URL_QUERY),$speechQuery);
    $check(($speechQuery['sample_rate']??null)===($sampleRate===null?null:(string)$sampleRate)
        &&$speechQuery['encoding']==='linear16'&&$speechQuery['container']==='wav','Deepgram maps configured sample rates while retaining WAV and absent-option behavior');
}
$azureContent = ConnectorCatalog::validate('tts_provider',ConnectorCatalog::defaults('tts_provider','azure')+['driver'=>'azure',
    'options'=>['region'=>' EastUS ','fixedMood'=>'angry','volume'=>20,'rate'=>1.25,'countour'=>'(11%, +15%)']]);
$check($azureContent['endpoint']==='https://eastus.tts.speech.microsoft.com' && $azureContent['options']['region']==='eastus',
    'Azure explicit region resolves to a bounded Microsoft hostname before outbound policy');
foreach ([$azureContent['options'],[]] as $azureOptions) {
    $speechProvider = new CloudSpeechConnectorProvider('https://93.184.216.34','azure','default','en-US-JennyNeural','en-US',$azureOptions,'fake-test-key');
    [$speechUrl,$speechBody,$speechHeaders] = (new ReflectionMethod($speechProvider,'request'))->invoke($speechProvider,'A < B & C','en-US-JennyNeural','en-US');
    $xml = new DOMDocument(); $xml->loadXML($speechBody);
    $prosody = $xml->getElementsByTagName('prosody');
    $check($xml->documentElement->textContent==='A < B & C' && str_ends_with($speechUrl,'/cognitiveservices/v1')
        &&($azureOptions===[] ? $prosody->length===0 : $prosody->item(0)->getAttribute('rate')==='1.25'
            &&$prosody->item(0)->getAttribute('volume')==='20'&&$prosody->item(0)->getAttribute('contour')==='(11%, +15%)'
            &&$xml->getElementsByTagNameNS('https://www.w3.org/2001/mstts','express-as')->item(0)->getAttribute('style')==='angry'),
        'Azure escapes speech text and only emits configured prosody and fixed style');
}
foreach ([['openai','instructions',str_repeat('x',4097)],['openai','instructions',['invalid']],
    ['azure','region','eastus/../../evil'],['azure','rate',0],['azure','volume',101],['azure','fixedMood',[]],
    ['deepgram','bitrate',22050],['deepgram','bitrate','32000'],
    ['11labs','optimize_streaming_latency',5],['11labs','apply_text_normalization','invalid'],
    ['11labs','apply_language_text_normalization','false'],['11labs','v3_audio_tags',str_repeat('x',1025)],
    ['kokoro','speed',0]] as [$speechDriver,$speechField,$invalidValue]) {
    try { ConnectorCatalog::validate('tts_provider',ConnectorCatalog::defaults('tts_provider',$speechDriver)+['driver'=>$speechDriver,'options'=>[$speechField=>$invalidValue]]); $check(false,'invalid TTS field rejected'); }
    catch (InvalidArgumentException) { $check(true,$speechDriver.' rejects invalid '.$speechField.' before saving'); }
}
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
    'gamedata-automatic-diary.json' => 'lorkhan.gamedata.v1',
    'interrupt.json' => 'lorkhan.interrupt.v1',
    'controls-query.json' => 'lorkhan.controls.query.v1',
    'controls-select.json' => 'lorkhan.controls.select.v1',
    'debug-command-query.json' => 'lorkhan.debug-command.query.v1',
    'debug-command-result.json' => 'lorkhan.debug-command-result.v1',
    'player-autochat.json' => 'lorkhan.player-autochat.v1',
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
$journalData=$actorProfileGameData;$journalData['type']='journal';
$journalEntry=['journal_id'=>'test_quest','title'=>'Test quest','stage'=>10,'status'=>'active','text'=>'An observed journal entry.'];
$journalData['payload']=['entries'=>[$journalEntry]];
$validator->validate($journalData,'lorkhan.gamedata.v1');$check(true,'typed journal stage observation validates');
$privateStoryArticle=['topic'=>'test_topic','content'=>'Private NPC story.','story_profile_override'=>true];
$storyOverlay=\LorkhanServer\Infrastructure\DynamicOghmaRepository::overlay([$privateStoryArticle],
    [['document'=>['topic'=>'test_topic','content'=>'Shared story.']]]);
$check($storyOverlay===[$privateStoryArticle],'Dynamic Oghma preview preserves the more specific NPC story override');
foreach([['stage'=>'10'],['stage'=>-1],['stage'=>2147483648],['stage'=>1.5],['status'=>'invented'],['extra'=>'field']]as$invalidJournal){
    $badJournal=$journalData;$badJournal['payload']['entries']=[array_replace($journalEntry,$invalidJournal)];
    try{$validator->validate($badJournal,'lorkhan.gamedata.v1');$check(false,'invalid typed journal entry rejected');}
    catch(ValidationException $exception){$check($exception->getMessage()==='invalid_schema','invalid typed journal entry rejected');}
}
$journalData['payload']['entries']=[$journalEntry,$journalEntry];
try{$validator->validate($journalData,'lorkhan.gamedata.v1');$check(false,'duplicate journal entry rejected');}
catch(ValidationException $exception){$check($exception->getMessage()==='invalid_schema','duplicate journal entry rejected');}
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
$playerAutochatRequest=json_decode((string)file_get_contents($fixtureRoot.'/player-autochat.json'),true,64,JSON_THROW_ON_ERROR)['instance'];
$validator->validate($playerAutochatRequest,'lorkhan.player-autochat.v1');
$check(true,'bounded player Auto Chat request validates');
try{
    $validator->validate(array_replace($playerAutochatRequest,['intent'=>'']),'lorkhan.player-autochat.v1');
    $check(false,'empty player Auto Chat intent rejected');
}catch(ValidationException $exception){
    $check($exception->getMessage()==='invalid_schema','empty player Auto Chat intent rejected');
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
$globalPromptSelection=$promptSelection;
$tagSelection=$promptSelection;
$tagSelection['speech_style']=['installation_id'=>$promptTurn['installation_id'],'driver'=>'chatterbox',
    'options'=>['paralinguistic_tags_enabled'=>true,'paralinguistic_tags_prompt'=>'Use [sigh] sparingly.']];
$tagPrompt=(new PromptAssembler())->assemble($promptTurn,$tagSelection);
$check(str_contains($tagPrompt['provider_input']['_assembled_prompt'],'Use [sigh] sparingly.'),'selected expressive speech instructions reach the bounded system prompt');
$tagSelection['speech_style']['options']['paralinguistic_tags_enabled']=false;
$check(!str_contains((new PromptAssembler())->assemble($promptTurn,$tagSelection)['provider_input']['_assembled_prompt'],'Use [sigh] sparingly.'),'disabled expressive speech instructions are omitted');
foreach ([
    [[], '[SIGH] Hello [unknown].'],
    [['paralinguistic_tags_enabled'=>true,'paralinguistic_tags_list'=>'[sigh]'], '[SIGH] Hello .'],
    [['paralinguistic_tags_enabled'=>false], 'Hello .'],
] as [$tagOptions,$expectedSpeech]) $check(\LorkhanServer\Application\ParalinguisticSpeech::speech('[SIGH] Hello [unknown].',$tagOptions)===$expectedSpeech,'speech tag filtering preserves selected case-insensitive cues and legacy absence behavior');
$wordSelection=$promptSelection;
$wordSelection['core_profile']=['core_profile_id'=>'word-profile','revision'=>1,'content'=>['prompt'=>'','settings_overrides'=>['response'=>['max_words'=>60]]]];
$wordPrompt=$assembler->assemble($promptTurn,$wordSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($wordPrompt,'Keep the combined spoken dialogue across all utterances within 60 words.')
    &&!str_contains($assembled['provider_input']['_assembled_prompt'],'combined spoken dialogue'),'profile word limit reaches compact prompt while absent limits preserve the prompt');
$wordResolved=(new EffectiveSettingsResolver())->resolve([],['settings_overrides'=>['response'=>['max_words'=>60]]],[]);
$npcWordSelection=$wordSelection;
$npcWordSelection['effective_settings']=(new EffectiveSettingsResolver())->resolve([], $npcWordSelection['core_profile']['content'],
    ['settings_overrides'=>['response'=>['max_words'=>17]]]);
$npcWordPrompt=$assembler->assemble($promptTurn,$npcWordSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($npcWordPrompt,'within 17 words.')&&!str_contains($npcWordPrompt,'within 60 words.'),
    'Resolved NPC response limit reaches the assembled prompt ahead of the Core Profile limit');
$npcWordSelection['effective_settings']['settings']['response']['max_words']=0;
$check(!str_contains($assembler->assemble($promptTurn,$npcWordSelection)['provider_input']['_assembled_prompt'],'combined spoken dialogue'),
    'Explicit zero NPC word limit removes the inherited prompt instruction');
foreach (['de'=>'Du bist', 'es'=>'Eres', 'fr'=>'Tu es', 'jp'=>'あなたは'] as $language=>$expectedInstruction) {
    $languageSelection=$wordSelection;
    $languageSelection['core_profile']['content']['settings_overrides']['response']['core_lang']=$language;
    $languageSelection['effective_settings']=(new EffectiveSettingsResolver())->resolve([], $languageSelection['core_profile']['content'], []);
    $languagePrompt=$assembler->assemble($promptTurn,$languageSelection)['provider_input']['_assembled_prompt'];
    $check(str_contains($languagePrompt,$expectedInstruction)&&str_contains($languagePrompt,'within 60 words.'),'Core language reaches frozen compact prompt without changing word limit');
}
foreach ([null, 'pl', '../de', [], 'DE'] as $invalidLanguage) {
    try { EffectiveSettingsResolver::validateSettingsOverrides(['response'=>['max_words'=>0,'core_lang'=>$invalidLanguage]]); $check(false,'invalid Core language rejected'); }
    catch (InvalidArgumentException) { $check(true,'invalid Core language rejected'); }
}
$check(\LorkhanServer\Application\CoreProfilePreset::capture($languageSelection['core_profile']['content'])['settings_overrides']['response']['core_lang']==='jp','Core language survives named preset capture');
$speechLanguageSelection=$wordSelection;
$speechLanguageSelection['core_profile']['content']['settings_overrides']['response']['lang_llm_xtts']=true;
$speechLanguagePrompt=$assembler->assemble($promptTurn,$speechLanguageSelection)['provider_input'];
$check(($speechLanguagePrompt['_llm_tts_language']??false)===true&&str_contains($speechLanguagePrompt['_assembled_prompt'],'exactly three keys: "language"'),'speech language opt-in freezes flag and language-first output contract');
$check(!isset($assembler->assemble($promptTurn,$wordSelection)['provider_input']['_llm_tts_language']),'legacy profiles do not request language metadata');
$check(\LorkhanServer\Application\SpeechLanguage::fromJsonPrefix('{"language":"fr","utterances":[')==='fr'
    &&\LorkhanServer\Application\SpeechLanguage::fromJsonPrefix('{"utterances":[{"text":"language: fr"}]}')===null
    &&\LorkhanServer\Application\SpeechLanguage::fromJsonPrefix('{"language":"fr')===null,'stream metadata parser waits for complete leading field and ignores dialogue content');
$npcMemorySelection=$promptSelection;
$npcMemorySelection['memory']=[['memory_id'=>'npc-memory','tier'=>'mid','content'=>'NPC_MEMORY_SENTINEL']];
$npcMemorySelection['effective_settings']=(new EffectiveSettingsResolver())->resolve([], [],
    ['settings_overrides'=>['memory'=>['mid_term_enabled'=>false]]]);
$check(!str_contains($assembler->assemble($promptTurn,$npcMemorySelection)['provider_input']['_assembled_prompt'],'NPC_MEMORY_SENTINEL'),
    'Resolved NPC memory switch excludes the disabled tier from the actual prompt');
$npcMemorySelection['effective_settings']['settings']['memory']['mid_term_enabled']=true;
$check(str_contains($assembler->assemble($promptTurn,$npcMemorySelection)['provider_input']['_assembled_prompt'],'NPC_MEMORY_SENTINEL'),
    'Enabled resolved NPC memory tier is retained in the actual prompt');
$evolutionDefaults=['enabled'=>true,'fields'=>EffectiveSettingsResolver::DYNAMIC_PROFILE_FIELDS,'history_limit'=>20];
$corePresetSource=['schema'=>'lorkhan.core-profile.v1','prompt'=>'Keep the profile prompt.',
    'routing'=>['llm_configuration_id'=>'00000000-0000-4000-8000-000000000001','llm_randomizer_enabled'=>true],
    'settings_overrides'=>['response'=>['max_words'=>70],'profile_evolution'=>$evolutionDefaults]];
$corePresetSource['settings_overrides']['rpg_comments']=['events'=>['sleep'],'chance_percent'=>73];
$corePreset=\LorkhanServer\Application\CoreProfilePreset::capture($corePresetSource);
$check($corePreset['settings_overrides']['rpg_comments']===$corePresetSource['settings_overrides']['rpg_comments'],'Named Core presets capture RPG event and probability choices');
$check(!isset($corePreset['prompt'])&&!isset($corePreset['routing']['llm_configuration_id'])
    &&$corePreset['routing']['llm_randomizer_enabled']===true,'Core presets exclude prompts and connector bindings');
$corePreset['settings_overrides']['profile_evolution']['fields']=['skills'];
$corePreset['settings_overrides']['rpg_comments']=['events'=>[],'chance_percent'=>0];
$corePresetApplied=\LorkhanServer\Application\CoreProfilePreset::apply($corePreset,$corePresetSource);
$check($corePresetApplied['settings_overrides']['rpg_comments']===['events'=>[],'chance_percent'=>0],'Named Core preset application preserves explicit RPG off');
$check($corePresetApplied['prompt']===$corePresetSource['prompt']
    &&$corePresetApplied['routing']['llm_configuration_id']===$corePresetSource['routing']['llm_configuration_id']
    &&$corePresetApplied['settings_overrides']['profile_evolution']['fields']===['skills'],
    'Applying a Core preset preserves identity-independent content and replaces field lists without leftover entries');
$diaryPresetSource=$corePresetSource;
$diaryPresetSource['settings_overrides']['diary']=['latest_entry_in_context'=>true];
$diaryPreset=\LorkhanServer\Application\CoreProfilePreset::capture($diaryPresetSource);
$check($diaryPreset['settings_overrides']['diary']['latest_entry_in_context']===true,
    'Named Core presets capture latest diary context');
$diaryPreset['settings_overrides']['diary']['latest_entry_in_context']=false;
$check(\LorkhanServer\Application\CoreProfilePreset::apply($diaryPreset,$diaryPresetSource)['settings_overrides']['diary']['latest_entry_in_context']===false,
    'Named Core preset Apply preserves an explicit disabled latest diary context switch');
$check(\LorkhanServer\Application\CoreProfilePreset::apply($corePreset,$diaryPresetSource)['settings_overrides']['diary']['latest_entry_in_context']===true,
    'Older named Core presets preserve latest diary context when omitted');
foreach([array_replace($corePreset,['prompt'=>'forbidden']),
    array_replace($corePreset,['routing'=>['llm_configuration_id'=>'00000000-0000-4000-8000-000000000001']]),
    array_replace($corePreset,['settings_overrides'=>['behavior'=>['boredom'=>true]]])] as $invalidCorePreset){
    try{\LorkhanServer\Application\CoreProfilePreset::validate($invalidCorePreset);$check(false,'nonportable Core preset rejected');}
    catch(InvalidArgumentException){$check(true,'nonportable Core preset rejected');}
}
foreach(['builtin:default'=>[75,100,50,0,2,50,true,false],
    'builtin:local_llm'=>[20,20,20,60,1,50,false,false],
    'builtin:follower'=>[100,150,100,0,4,60,true,true],
    'builtin:passive'=>[75,100,50,0,1,10,false,false]] as $builtin=>$expected){
    $applied=\LorkhanServer\Application\CoreProfilePreset::applyBuiltIn($builtin,$corePresetSource);
    $rpgChance=['builtin:default'=>50,'builtin:local_llm'=>0,'builtin:follower'=>75,'builtin:passive'=>20][$builtin];
    $check($applied['settings_overrides']['rpg_comments']===['events'=>['sleep'],'chance_percent'=>$rpgChance],
        'Built-in Core presets match CHIM RPG probabilities without replacing event choices');
    $settings=$applied['settings_overrides'];
    $autonomyExpected=['builtin:default'=>[30,30],'builtin:local_llm'=>[30,100],'builtin:follower'=>[50,20],'builtin:passive'=>[5,120]][$builtin];
    $check([$settings['bored_event']['chance_percent'],$settings['behavior']['combat_bark_period_seconds']]===$autonomyExpected,
        $builtin.' matches reference bored probability and combat cooldown');
    $check([$settings['memory']['recent_turn_limit'],$settings['diary']['context_turn_limit'],
        $settings['profile_evolution']['history_limit'],$settings['response']['max_words'],
        $settings['behavior']['rechat_max_depth'],$settings['behavior']['rechat_probability_percent'],
        $settings['behavior']['rechat_allow_actions'],$settings['profile_evolution']['enabled']]===$expected
        &&$settings['memory']['mid_term_enabled']===$expected[7]
        &&$settings['diary']['automatic_enabled']===$expected[7]
        &&$settings['diary']['automatic_wait_enabled']===$expected[7]
        &&$settings['diary']['latest_entry_in_context']===$expected[7]
        &&$settings['quest_comments']['enabled']===$expected[7],$builtin.' shared profile values match reference');
    $check($applied['prompt']===$corePresetSource['prompt']
        &&$applied['routing']['llm_configuration_id']===$corePresetSource['routing']['llm_configuration_id']
        &&$settings['profile_evolution']['fields']===$corePresetSource['settings_overrides']['profile_evolution']['fields']
        &&$applied['routing']['llm_randomizer_enabled']===false,$builtin.' preserves prompt, connector and evolution field ownership');
}
$disabledPresetSource=$corePresetSource;
$disabledPresetSource['settings_overrides']['diary']['enabled']=false;
$disabledPresetSource['settings_overrides']['behavior']['rechat']=false;
$followerPreset=\LorkhanServer\Application\CoreProfilePreset::applyBuiltIn('builtin:follower',$disabledPresetSource);
$check($followerPreset['settings_overrides']['diary']['enabled']===true
    &&$followerPreset['settings_overrides']['behavior']['rechat']===true,'Follower opens the native diary and Rechat gates');
foreach(['builtin:default','builtin:local_llm','builtin:passive'] as $builtin){
    $after=\LorkhanServer\Application\CoreProfilePreset::applyBuiltIn($builtin,$followerPreset);
    $check($after['settings_overrides']['diary']['enabled']===true
        &&$after['settings_overrides']['diary']['automatic_enabled']===false
        &&$after['settings_overrides']['behavior']['rechat']===true,$builtin.' stops automatic diaries while retaining manual generation and configured Rechat');
}
try{\LorkhanServer\Application\CoreProfilePreset::applyBuiltIn('builtin:unknown',$corePresetSource);$check(false,'unknown builtin rejected');}
catch(InvalidArgumentException){$check(true,'unknown builtin rejected');}
$evolutionResolved=(new EffectiveSettingsResolver())->resolve([],['settings_overrides'=>['profile_evolution'=>$evolutionDefaults]],[]);
$check(EffectiveSettingsResolver::validateSettingsOverrides(['profile_evolution'=>$evolutionDefaults])['profile_evolution']===$evolutionDefaults
    &&!isset($evolutionResolved['settings']['profile_evolution'])
    &&!isset(EffectiveSettingsResolver::controlsProjection($evolutionResolved)['settings']['profile_evolution']),
    'Core Profile evolution defaults retain all five fields without leaking into the client contract');
foreach([['enabled'=>'true','fields'=>['personality']],['enabled'=>true,'fields'=>[]],
    ['enabled'=>true,'fields'=>['skills'],'history_limit'=>-1],['enabled'=>true,'fields'=>['skills'],'history_limit'=>401],['enabled'=>true,'fields'=>['skills'],'history_limit'=>'20'],
    ['enabled'=>true,'fields'=>['notes']],['enabled'=>true,'fields'=>['skills','skills']],
    ['enabled'=>true,'fields'=>['skills'],'extra'=>true]] as $invalidEvolution){
    try{EffectiveSettingsResolver::validateSettingsOverrides(['profile_evolution'=>$invalidEvolution]);$check(false,'invalid evolution defaults rejected');}
    catch(InvalidArgumentException){$check(true,'invalid evolution defaults rejected');}
}
$check($wordResolved['settings']['response']['max_words']===60
    &&$wordResolved['sources']['settings.response.max_words']==='core_profile'
    &&!isset(EffectiveSettingsResolver::controlsProjection($wordResolved)['settings']['response']),'response limits are traced but do not change the client controls schema');
foreach([-1,10001,'60']as$invalidWords){
    try{EffectiveSettingsResolver::validateSettingsOverrides(['response'=>['max_words'=>$invalidWords]]);$check(false,'invalid word limit rejected');}
    catch(InvalidArgumentException){$check(true,'invalid word limit rejected');}
}
$globalPromptSelection['effective_settings']['prompt']=['prompt_head'=>'Global roleplay sentinel.', 'emote_moods'=>'curious, guarded'];
$globalPromptText=$assembler->assemble($promptTurn,$globalPromptSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($globalPromptText,'Global roleplay sentinel.')&&str_contains($globalPromptText,'curious, guarded'),
    'Global Prompt Head and Emote Moods fill absent NPC fields');
$globalPromptSelection['profile']['content']['prompt_head']='NPC roleplay sentinel.';
$globalPromptSelection['profile']['content']['emote_moods']='defiant';
$npcPromptText=$assembler->assemble($promptTurn,$globalPromptSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($npcPromptText,'NPC roleplay sentinel.')&&str_contains($npcPromptText,'defiant')
    &&!str_contains($npcPromptText,'Global roleplay sentinel.')&&!str_contains($npcPromptText,'curious, guarded'),
    'NPC Prompt Head and Emote Moods take precedence over global defaults');
$automaticCues=['lorkhan_auto_greeting'=>'Automatic greeting for Fargoth',
    'lorkhan_auto_boredom'=>'Automatic idle remark for Fargoth',
    'lorkhan_auto_combat_bark'=>'Automatic combat bark for Fargoth'];
foreach($automaticCues as$source=>$expectedCue){$automaticTurn=$promptTurn;$automaticTurn['payload']['ui_source']=$source;
    $automaticTurn['payload']['input']['text']='[Autonomy:test]';
    $automaticMessages=$assembler->assemble($automaticTurn,$promptSelection)['provider_input']['_messages'];
    $automaticFinal=$automaticMessages[array_key_last($automaticMessages)]['content']??'';
    $check(str_contains((string)$automaticFinal,$expectedCue)&&!str_contains((string)$automaticFinal,'RANGROO: [Autonomy:test]'),
        $source.' uses a server-owned action-free prompt cue');}
foreach (\LorkhanServer\Application\NarratorEventPrompts::SOURCES as $source => $key) {
    $narratorTurn = $promptTurn; $narratorTurn['payload']['ui_source'] = $source;
    $messages = $assembler->assemble($narratorTurn, $promptSelection)['provider_input']['_messages'];
    $expected = strtr(\LorkhanServer\Application\NarratorEventPrompts::definitions()[$key]['default_prompt'], ['{PLAYER_NAME}' => 'Nerevarine']);
    $check($messages[array_key_last($messages)]['content'] === $expected, $key.' preserves the prior event instruction by default');
    $narratorTurn['_narrator_event_prompts'] = [$key => 'Observe the scene for {PLAYER_NAME}, without inventing events.'];
    $messages = $assembler->assemble($narratorTurn, $promptSelection)['provider_input']['_messages'];
    $check($messages[array_key_last($messages)]['content'] === 'Observe the scene for Nerevarine, without inventing events.', $key.' applies the frozen custom instruction');
}
foreach(['lorkhan_narrator_quest','lorkhan_quest_event'] as $questSource){
    $groundedQuest=$promptTurn;$groundedQuest['payload']['ui_source']=$questSource;
    $groundedQuest['payload']['input']['text']=($questSource==='lorkhan_narrator_quest'?"[Narrator:quest]\n":'[Quest update] ').'Quest mq_test, stage 20: Find the missing package.';
    $groundedQuest['_narrator_event_prompts']=[\LorkhanServer\Application\NarratorEventPrompts::SOURCES['lorkhan_narrator_quest']=>'Observe the quest for {PLAYER_NAME}.'];
    $questMessages=$assembler->assemble($groundedQuest,$promptSelection)['provider_input']['_messages'];
    $questFinal=$questMessages[array_key_last($questMessages)]['content'];
    $check(str_contains($questFinal,'Quest mq_test, stage 20: Find the missing package.')
        &&str_contains($questFinal,'scene context, not')&&!str_contains($questFinal,'Nerevarine: [Quest update]')
        &&($questSource!=='lorkhan_narrator_quest'||str_contains($questFinal,'Observe the quest for Nerevarine.')),
        $questSource.' retains actual journal text as scene context');
}
foreach (['Narrator'=>'narrator','NPC'=>'npc','Text Only'=>'npc','Disabled'=>''] as $mode=>$suffix) {
    $inlineTurn=$promptTurn;$inlineTurn['_narrator_profile']['content']['enabled']=true;
    $inlineTurn['_narrator_profile']['content']['inline_narration_mode']=$mode;
    $inlineTurn['_narrator_profile']['name']='Fixture Narrator';
    $inlineTurn['_narrator_event_prompts']=['dialogue_line_inline_response_narrator'=>'Narrator template for {NPC_NAME} and {NARRATOR_NAME}.{MAXIMUM_WORDS}.',
        'inline_narration_prompt_narrator'=>'Narrator block sentinel.', 'dialogue_line_inline_response_npc'=>'NPC template for {NPC_NAME}.{MAXIMUM_WORDS}.',
        'inline_narration_prompt_npc'=>'NPC block sentinel.'];
    $inlineMessages=$assembler->assemble($inlineTurn,$wordSelection)['provider_input']['_messages'];
    $inlineFinal=$inlineMessages[array_key_last($inlineMessages)]['content'];
    $check(($suffix===''&&!str_contains($inlineFinal,'template for')&&!str_contains($inlineFinal,'block sentinel'))
        ||($suffix==='narrator'&&str_contains($inlineFinal,'Narrator block sentinel.')&&str_contains($inlineFinal,'Fixture Narrator')&&!str_contains($inlineFinal,'NPC block sentinel'))
        ||($suffix==='npc'&&str_contains($inlineFinal,'NPC block sentinel.')&&!str_contains($inlineFinal,'Narrator block sentinel')),
        $mode.' selects only its inline prompt pair');
    $check(!str_contains($inlineFinal,'{NPC_NAME}')&&!str_contains($inlineFinal,'{NARRATOR_NAME}')&&!str_contains($inlineFinal,'{MAXIMUM_WORDS}')
        &&($suffix===''||(str_contains($inlineFinal,'Fargoth')&&str_contains($inlineFinal,'within 60 words'))),'inline names and word limits are substituted');
    $inlineTurn['payload']['target']['kind']='narrator';
    $directMessages=$assembler->assemble($inlineTurn,$promptSelection)['provider_input']['_messages'];
    $check(!str_contains($directMessages[array_key_last($directMessages)]['content'],'block sentinel'),'direct Narrator dialogue skips inline templates');
}
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
    &&str_contains((string)$systemMessage['content'],'- **Name:** Nerevarine')
    &&!str_contains((string)$systemMessage['content'],'- **Name:** RANGROO')
    &&!str_contains((string)$systemMessage['content'],'DISABLED NARRATOR SENTINEL'),
    'Markdown presentation uses the configured player name and preserves disabled narrator filtering');
$unconfiguredPlayer=$promptTurn;unset($unconfiguredPlayer['_player_profile']);
$check(str_contains($assembler->assemble($unconfiguredPlayer,$promptSelection)['provider_input']['_assembled_prompt'],'RANGROO'),'player name falls back to the game identity without a configured persona');
$moodPromptTurn=$promptTurn;$moodPromptTurn['payload']['input']['mood']=['kind'=>'playful'];
$moodPromptSelection=$promptSelection;$moodPromptSelection['prompt']['content']['player_mood_prompts']=$editableMoodPrompts;
$moodAssembled=(new PromptAssembler(4096,1024))->assemble($moodPromptTurn,$moodPromptSelection);
$moodPrompt=$moodAssembled['provider_input'];
$check(str_contains($moodPrompt['_assembled_prompt'],'Hello (Nerevarine sounds playful.)')
    &&($moodPrompt['payload']['input']['text']??null)==='Hello'
    &&($moodPrompt['payload']['input']['mood']['kind']??null)==='playful'
    &&($moodAssembled['trace']['player_mood_cue']??null)==='(Nerevarine sounds playful.)',
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
$inventoryTurn=$contextTurn;
$inventoryTurn['payload']['context']['inventory']=['items'=>[['record_id'=>'native_inventory_ring','display_name'=>'Native Inventory Ring','count'=>3]]];
$inventoryPrompt=(new PromptAssembler(16384,1024))->assemble($inventoryTurn,$promptSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($inventoryPrompt,'Native Inventory Ring x3'), 'player prompt consumes the actual top-level OpenMW inventory lane');
$filteredInventorySelection=$promptSelection;
$filteredInventorySelection['effective_settings']['context']=\LorkhanServer\Application\SettingsCatalog::globalDefaults()['context'];
$filteredInventorySelection['effective_settings']['context']['inventory_items_descriptions_only']=true;
$filteredInventorySelection['effective_settings']['context']['sections']['record_descriptions']=false;
$filteredInventoryTurn=$inventoryTurn;
$filteredInventoryTurn['payload']['context']['playerState']['equipment']=[['record_id'=>'equipped_ring','display_name'=>'Equipped Ring']];
$filteredInventoryPrompt=(new PromptAssembler(16384,1024))->assemble($filteredInventoryTurn,$filteredInventorySelection)['provider_input']['_assembled_prompt'];
$check(!str_contains($filteredInventoryPrompt,'Native Inventory Ring') && str_contains($filteredInventoryPrompt,'Equipped Ring')
    && str_contains($filteredInventoryPrompt,"Bungler's Bane"), 'inventory description filtering leaves equipment and ground items intact');
$filteredInventoryTurn['_item_descriptions']=[['record_id'=>'native_inventory_ring','content_file'=>'Morrowind.esm','description'=>'Hidden ring description.']];
$filteredInventoryPrompt=(new PromptAssembler(16384,1024))->assemble($filteredInventoryTurn,$filteredInventorySelection)['provider_input']['_assembled_prompt'];
$check(str_contains($filteredInventoryPrompt,'Native Inventory Ring x3') && !str_contains($filteredInventoryPrompt,'Hidden ring description.'),
    'inventory availability filtering retains counts independently of description text visibility');
$filteredInventoryTurn['payload']['context']['inventory']['items'][0]['count']=6;
$filteredInventoryPrompt=(new PromptAssembler(16384,1024))->assemble($filteredInventoryTurn,$filteredInventorySelection)['provider_input']['_assembled_prompt'];
$check(!str_contains($filteredInventoryPrompt,'Native Inventory Ring'), 'inventory descriptions-only matches Herika omission of stacks larger than five');
$inventorySelection=$promptSelection;
$inventorySelection['effective_settings']['context']=\LorkhanServer\Application\SettingsCatalog::globalDefaults()['context'];
$inventorySelection['effective_settings']['context']['details']['npc_equipment_inventory']=false;
$inventoryPrompt=(new PromptAssembler(16384,1024))->assemble($inventoryTurn,$inventorySelection)['provider_input']['_assembled_prompt'];
$check(!str_contains($inventoryPrompt,'Native Inventory Ring'), 'inventory context selection also gates the native top-level inventory lane');
$inventoryTurn['payload']['context']['playerState']['inventory']=[];
$inventoryPrompt=(new PromptAssembler(16384,1024))->assemble($inventoryTurn,$promptSelection)['provider_input']['_assembled_prompt'];
$check(!str_contains($inventoryPrompt,'Native Inventory Ring'), 'explicit nested player inventory takes precedence over top-level fallback');
$groundSelection=$promptSelection;
$groundSelection['effective_settings']['context']=\LorkhanServer\Application\SettingsCatalog::globalDefaults()['context'];
$groundSelection['effective_settings']['context']['ground_items_descriptions_only']=true;
$groundSelection['effective_settings']['context']['sections']['record_descriptions']=false;
$groundTurn=$contextTurn;
$groundTurn['payload']['context']['nearbyObjects']['items'][]=['kind'=>'items','record_id'=>'undescribed_ring','display_name'=>'Undescribed Ring'];
$groundTurn['_item_descriptions']=[['record_id'=>'ingred_bc_bungler_bane_01','content_file'=>'Morrowind.esm','description'=>'Secret mushroom description.']];
$groundPrompt=(new PromptAssembler(16384,1024))->assemble($groundTurn,$groundSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($groundPrompt,"Bungler's Bane") && str_contains($groundPrompt,'- **Count:** 2')
    && !str_contains($groundPrompt,'Undescribed Ring') && !str_contains($groundPrompt,'Secret mushroom description.')
    && str_contains($groundPrompt,'### Points Of Interest'),
    'ground description filtering preserves described counts and points of interest without exposing hidden descriptions');
$groundTurn['payload']['context']['nearbyObjects']['items'][0]['content_file']='Other.esp';
$groundPrompt=(new PromptAssembler(16384,1024))->assemble($groundTurn,$groundSelection)['provider_input']['_assembled_prompt'];
$check(!str_contains($groundPrompt,'### Nearby Items'), 'ground descriptions cannot match an item from another content file');
$groundTurn['payload']['context']['nearbyObjects']['items'][0]['content_file']='morrowind.ESM';
$groundPrompt=(new PromptAssembler(16384,1024))->assemble($groundTurn,$groundSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($groundPrompt,'### Nearby Items'), 'ground description identity comparison normalizes content file case');
$groundTurn['_item_descriptions'][0]['description']='   ';
$groundPrompt=(new PromptAssembler(16384,1024))->assemble($groundTurn,$groundSelection)['provider_input']['_assembled_prompt'];
$check(!str_contains($groundPrompt,'### Nearby Items'), 'blank descriptions do not qualify ground items');
$groundSelection['effective_settings']['context']['ground_items_descriptions_only']=false;
$groundPrompt=(new PromptAssembler(16384,1024))->assemble($groundTurn,$groundSelection)['provider_input']['_assembled_prompt'];
$check(str_contains($groundPrompt,'Undescribed Ring'), 'disabled ground description filtering retains undescribed items');
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
$dialogueSchema=(new ReflectionMethod($actionProvider,'responseSchema'))->invoke($actionProvider,$actionTurn);
$schemaActions=$dialogueSchema['properties']->action['anyOf'];
$check(count($schemaActions)===3&&$schemaActions[0]===['type'=>'null']
    &&$schemaActions[1]['properties']->name['enum']===['inspect.report']
    &&$schemaActions[2]['properties']->name['enum']===['ai.follow']
    &&$schemaActions[1]['properties']->parameters['properties'] instanceof stdClass
    &&$schemaActions[1]['properties']->parameters['required']===[],
    'structured dialogue exposes only negotiated actions and encodes empty parameters as an object');
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
$check(($budgetedTurn['provider_input']['_messages'][array_key_last($budgetedTurn['provider_input']['_messages'])]['content']??null)==="Nerevarine: Hello\n\nRespond as Fargoth. Write Fargoth's next dialogue line; do not write dialogue for Nerevarine."
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
$timedHistory = $roleHistory;
$timedHistory['effective_settings']['context'] = \LorkhanServer\Application\SettingsCatalog::globalDefaults()['context'];
$timedTurn = $promptTurn;
$timedTurn['payload']['context']['world']['game_time'] = 200000;
$timedHistory['history'][0]['content']['game_time'] = 200000 - 7200;
$timedHistory['history'][1]['content']['game_time'] = 200000 - 180;
$timedHistory['history'][2]['content']['game_time'] = 200000 - 30;
$untimedPrompt = (new PromptAssembler(8192,1024))->assemble($timedTurn,$timedHistory)['provider_input']['_assembled_prompt'];
$timedHistory['effective_settings']['context']['prompt_timestamp'] = true;
$timedPrompt = (new PromptAssembler(8192,1024))->assemble($timedTurn,$timedHistory)['provider_input']['_assembled_prompt'];
$check(!str_contains($untimedPrompt, '--- Moments Ago ---')
    && str_contains($timedPrompt, "--- Moments Ago ---\n  Fargoth: I have not seen it.")
    && str_contains($timedPrompt, "--- Happened Recently ---\n  Guard: Move along.")
    && !str_contains($timedPrompt, '--- A couple of hours ago ---'),
    'optional temporal dividers match Herika categories using OpenMW seconds and preserve speakers');
unset($timedTurn['payload']['context']['world']['game_time']);
$missingTimePrompt = (new PromptAssembler(8192,1024))->assemble($timedTurn,$timedHistory)['provider_input']['_assembled_prompt'];
$check(!str_contains($missingTimePrompt, '--- Moments Ago ---'), 'missing game time does not invent temporal context');
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
$languageContext=['voice'=>'existing-voice','language'=>'de'];
foreach (['xtts','xtts-fastapi','chatterbox'] as $driver) {
    $check(\LorkhanServer\Application\SpeechLanguage::context($languageContext,['content'=>['driver'=>$driver]],' FR ')
        ===['voice'=>'existing-voice','language'=>'fr'],'supported speech drivers receive normalized language without changing voice');
}
foreach ([null, [], 'xx', 'fr/text', str_repeat('a',17)] as $invalidLanguage) {
    $check(\LorkhanServer\Application\SpeechLanguage::context($languageContext,['content'=>['driver'=>'xtts']],$invalidLanguage)===$languageContext
        &&\LorkhanServer\Application\SpeechLanguage::payload($invalidLanguage)===[],'missing or invalid speech language preserves configured fallback and legacy job shape');
}
foreach (['inworld','openai','pockettts','omnivoice'] as $driver) {
    $check(\LorkhanServer\Application\SpeechLanguage::context($languageContext,['content'=>['driver'=>$driver]],'fr')===$languageContext,'model language does not alter other connectors');
}
$languagePlanned=(new DialoguePlanner())->plan($canonicalTurn,['utterances'=>[['text'=>'Bonjour.','tts_language'=>'FR']]]);
$check($languagePlanned[0]['tts_language']==='fr'&&$languagePlanned[0]['_subtitle']==='Bonjour.'&&$languagePlanned[0]['_tts_text']==='Bonjour.','speech language survives planning without becoming visible text');
$check(\LorkhanServer\Application\SpeechLanguage::payload('jp')===['tts_language'=>'ja']&&\LorkhanServer\Application\SpeechLanguage::payload('zh')===['tts_language'=>'zh-cn'],'profile language aliases map to supported speech codes');
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
$publicProviders=ConnectorCatalog::normalizeOpenRouterProviders(['data'=>[
    ['slug'=>'together','name'=>'<img src=x>','privacy_policy_url'=>str_repeat('é',2050),'unknown'=>'discard'],
    ['slug'=>''],['slug'=>str_repeat('a',129)],['slug'=>"bad\nslug"],['slug'=>'a,b'],['name'=>'Missing slug'],null,
]]);
$check(count($publicProviders['data'])===1 && $publicProviders['data'][0]['slug']==='together'
    && $publicProviders['data'][0]['name']==='<img src=x>' && mb_strlen($publicProviders['data'][0]['privacy_policy_url'])===2048
    && $publicProviders['data'][0]['terms_of_service_url']==='' && !isset($publicProviders['data'][0]['unknown'])
    && ConnectorCatalog::normalizeOpenRouterProviders(['data'=>[]])===['data'=>[]],
    'provider catalogue retains bounded public display text, rejects invalid slugs and permits an empty list');
foreach ([[], ['data'=>array_fill(0,5001,[])]] as $invalidCatalogue) {
    try { ConnectorCatalog::normalizeOpenRouterProviders($invalidCatalogue); $check(false,'invalid provider catalogue rejected'); }
    catch (InvalidArgumentException) { $check(true,'invalid provider catalogue rejected'); }
}
$groqTransportCalls=[];
$groqModels=ConnectorCatalog::groqModels('fixture-groq-secret',static function(string $url,array $headers)use(&$groqTransportCalls):string{
    $groqTransportCalls[]=[$url,$headers];
    return json_encode(['private'=>'fixture-groq-secret','data'=>[
        ['id'=>'z-model','owned_by'=>'Groq','context_window'=>131072,'api_key'=>'fixture-groq-secret'],
        ['id'=>'a-model','owned_by'=>'<img src=x>','context_window'=>'invalid'],
        ['id'=>"invalid\nmodel"],['id'=>''],null,
    ]],JSON_THROW_ON_ERROR);
});
$check($groqTransportCalls===[['https://api.groq.com/openai/v1/models',['Accept: application/json','Authorization: Bearer fixture-groq-secret']]]
    &&$groqModels===['data'=>[['id'=>'a-model','owned_by'=>'<img src=x>','context_window'=>null],['id'=>'z-model','owned_by'=>'Groq','context_window'=>131072]]]
    &&!str_contains(json_encode($groqModels),'fixture-groq-secret'),
    'Groq discovery uses the fixed HTTPS URL and selected private key, returning only bounded public model fields');
foreach(['{}','invalid','{"data":{}}',str_repeat('x',1048577),json_encode(['data'=>array_fill(0,5001,[])])]as$invalidGroq){
    try{ConnectorCatalog::groqModels('fixture-key',static fn():string=>$invalidGroq);$check(false,'invalid Groq catalogue rejected');}
    catch(InvalidArgumentException|\JsonException){$check(true,'invalid Groq catalogue rejected');}
}
try{ConnectorCatalog::groqModels("bad\r\nheader",static function():string{throw new RuntimeException('must not send invalid header');});$check(false,'invalid Groq header rejected');}
catch(InvalidArgumentException){$check(true,'invalid Groq header rejected before transport');}
$check(ConnectorCatalog::groqModels('fixture-key',static fn():string=>'{"data":[]}')===['data'=>[]],
    'Groq empty catalogue remains a valid empty selection');
$publicModels=ConnectorCatalog::normalizeOpenRouterModels(['data'=>[
    ['id'=>'example/model','name'=>'<img src=x>','description'=>str_repeat('é',4001),
     'pricing'=>['prompt'=>'0','completion'=>'-1'],'top_provider'=>['context_length'=>64000],
     'private_field'=>'must-not-leave-server'],
    ['canonical_slug'=>'example/fallback','pricing'=>['prompt'=>'1e309']], ['id'=>str_repeat('x',257)], null,
]]);
$check(count($publicModels['data'])===2 && $publicModels['data'][0]['pricing']===['prompt'=>'0','completion'=>null]
    && $publicModels['data'][0]['context_length']===64000 && $publicModels['data'][1]['pricing']['prompt']===null,
    'public model catalogue retains free prices and rejects invalid entries and numeric metadata');
$check($publicModels['data'][0]['name']==='<img src=x>' && mb_strlen($publicModels['data'][0]['description'])===4000
    && !isset($publicModels['data'][0]['private_field']) && ConnectorCatalog::normalizeOpenRouterModels(['data'=>[]])===['data'=>[]],
    'model catalogue limits Unicode text, drops unknown fields and permits an empty catalogue');
foreach ([[], ['data'=>array_fill(0,5001,[])]] as $invalidCatalogue) {
    try { ConnectorCatalog::normalizeOpenRouterModels($invalidCatalogue); $check(false,'invalid model catalogue rejected'); }
    catch (InvalidArgumentException) { $check(true,'invalid model catalogue rejected'); }
}
$check(count($ttsCatalog)===22 && count($sttCatalog)===8
    &&in_array('none',array_column($sttCatalog,'driver'),true), 'CHIM-lineage TTS and STT connector catalogs are complete');
$check(array_column(ConnectorCatalog::optionFields('tts_provider','xtts-fastapi'),'name')===
    ['speed','temperature','top_p','top_k','repetition_penalty','paralinguistic_tags_enabled','paralinguistic_tags_prompt','paralinguistic_tags_list']
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
$additionalKey='LORKHAN_LLM_OPENAI_API_KEY';$credentialStore->set($additionalKey,'separate-llm-key');
$credentialStore->setLabel($additionalKey,'Separate OpenAI account');
$additionalStatus=array_column($credentialStore->statuses(),null,'variable');
$check($additionalStatus[$additionalKey]['label']==='Separate OpenAI account'
    &&$credentialStore->resolve($additionalKey)==='separate-llm-key'
    &&LlmConnector::credentialVariable('openai')===$additionalKey
    &&!str_contains(json_encode($additionalStatus),'separate-llm-key'),
    'additional built-in badge labels preserve credential values and alias identities');
$credentialStore->delete($additionalKey);
$customLabelKey='LORKHAN_CUSTOM_LABEL_TEST_API_KEY';$credentialStore->set($customLabelKey,'hidden-custom-key');
$credentialStore->setLabel($customLabelKey,'My Voice Service');
$labelStatuses=$credentialStore->statuses();
$badgeStatusMap=array_column($labelStatuses,null,'variable');
$check($badgeStatusMap['LORKHAN_TTS_OPENAI_API_KEY']['label']==='OpenAI'
    &&$badgeStatusMap['LORKHAN_LLM_OPENAI_API_KEY']['label']==='OpenAI LLM key'
    &&$badgeStatusMap['LORKHAN_STT_GEMINI_API_KEY']['label']==='Google Gemini STT',
    'global badge labels retain provider spelling and distinguish separate saved keys');
$labelRow=array_values(array_filter($labelStatuses,static fn(array $row):bool=>$row['variable']===$customLabelKey))[0];
$check($labelRow['label']==='My Voice Service'&&$credentialStore->resolve($customLabelKey)==='hidden-custom-key'
    &&!str_contains(json_encode($labelStatuses),'hidden-custom-key'),'display label preserves the stable key and secret redaction');
putenv($customLabelKey.'=environment-custom-secret');
$check(!str_contains(json_encode($credentialStore->statuses()),'environment-custom-secret'),'label metadata never resolves credential environment values');
putenv($customLabelKey);$credentialStore->delete($customLabelKey);unlink($credentialPath.'.labels.json');
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
// Inworld follows the local sample name through discovery, cloning and credential-scoped reuse.
$inworldRoot=sys_get_temp_dir().'/lorkhan-inworld-'.bin2hex(random_bytes(4));mkdir($inworldRoot);
$inworldCredentials=new CredentialStore($inworldRoot.'/keys.json');
$inworldCredentials->set('LORKHAN_TTS_INWORLD_API_KEY','test-account-one');
$inworldCalls=[];
$inworldLibrary=new \LorkhanServer\Application\CloudVoiceLibrary($inworldCredentials,
    static function(string $driver,string $path,array|string|null $body)use(&$inworldCalls):array{
        $inworldCalls[]=['path'=>$path,'body'=>$body];
        if($body===null)return ['voices'=>[['voiceId'=>'workspace__existing','displayName'=>'existing_voice']]];
        $request=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        return ['voice'=>['voiceId'=>'workspace__'.$request['displayName']]];
    });
$inworldResolver=new \LorkhanServer\Application\InworldVoiceResolver($inworldLibrary,$inworldCredentials,$inworldRoot);
$wav=(new MockSpeechProvider())->synthesize('test',new NeverCancelledToken())['bytes'];
file_put_contents($inworldRoot.'/mw_dark_elf_male.wav',$wav);
$resolvedVoice=$inworldResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken());
$cloneRequest=json_decode($inworldCalls[1]['body'],true);
$check($resolvedVoice==='workspace__mw_dark_elf_male'&&count($inworldCalls)===2
    &&$cloneRequest['langCode']==='EN_US'&&base64_decode($cloneRequest['voiceSamples'][0]['audioData'])===$wav
    &&$cloneRequest['voiceSamples'][0]['transcription']==='Are you all right, outlander? You might have a healer tend to those wounds.',
    'Inworld clones the selected Morrowind WAV with its matching catalog transcription');
$check($inworldResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken())===$resolvedVoice&&count($inworldCalls)===2,
    'Inworld cached voice resolution makes no further discovery or clone requests');
$check($inworldResolver->resolve('workspace__dagoth_ur','en',new NeverCancelledToken())==='workspace__dagoth_ur'&&count($inworldCalls)===2,
    'explicit custom Inworld IDs remain unchanged');
$inworldCredentials->set('LORKHAN_TTS_INWORLD_API_KEY','test-account-two');
$inworldResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken());
$check(count($inworldCalls)===4,'Inworld cached IDs cannot cross credential scopes');
$existingVoiceResolver=new \LorkhanServer\Application\InworldVoiceResolver($inworldLibrary,$inworldCredentials,$inworldRoot);
try{$existingVoiceResolver->resolve('unregistered_voice','en',new NeverCancelledToken());$check(false,'Inworld missing samples fail explicitly');}
catch(RuntimeException $e){$check($e->getMessage()==='voice_sample_not_found','Inworld missing samples fail explicitly');}
$check($existingVoiceResolver->resolve('existing_voice','en',new NeverCancelledToken())==='workspace__existing',
    'Inworld discovers an existing exact-name voice without uploading a sample');
$beforeCalls=count($inworldCalls);
try{$inworldResolver->resolve('../escape','en',new NeverCancelledToken());$check(false,'Inworld sample path traversal rejected');}
catch(RuntimeException $e){$check($e->getMessage()==='invalid_voice_name'&&count($inworldCalls)===$beforeCalls,'Inworld sample path traversal rejected');}
try{$inworldResolver->resolve('mw_dark_elf_male','en',new \LorkhanServer\Application\CallbackCancellationToken(static fn()=>true));$check(false,'Inworld cancellation precedes registration');}
catch(\LorkhanServer\Application\OperationCancelled){$check(count($inworldCalls)===$beforeCalls,'Inworld cancellation precedes registration');}
$cartesiaCalls=[];$cartesiaId='12345678-1234-1234-1234-123456789abc';
$inworldCredentials->set('LORKHAN_TTS_CARTESIA_API_KEY','cartesia-account');
$cartesiaLibrary=new \LorkhanServer\Application\CloudVoiceLibrary($inworldCredentials,
    static function(string $driver,string $path,array|string|null $body)use(&$cartesiaCalls,$cartesiaId):array{
        $cartesiaCalls[]=['driver'=>$driver,'path'=>$path,'body'=>$body];
        return $body===null?['data'=>[]]:['id'=>$cartesiaId];
    });
$cartesiaResolver=new \LorkhanServer\Application\InworldVoiceResolver($cartesiaLibrary,$inworldCredentials,$inworldRoot,'cartesia');
$check($cartesiaResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken())===$cartesiaId
    &&$cartesiaCalls[1]['path']==='/voices/clone'&&$cartesiaCalls[1]['body']['mode']==='similarity'
    &&$cartesiaCalls[1]['body']['clip']->getFilename()===$inworldRoot.'/mw_dark_elf_male.wav',
    'Cartesia automatically clones the selected Morrowind sample with the existing multipart contract');
$check($cartesiaResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken())===$cartesiaId
    &&$cartesiaResolver->resolve($cartesiaId,'en',new NeverCancelledToken())===$cartesiaId&&count($cartesiaCalls)===2,
    'Cartesia reuses cached clones and preserves explicit provider IDs without additional API calls');
$inworldCredentials->set('LORKHAN_TTS_CARTESIA_API_KEY','cartesia-other-account');
$cartesiaResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken());
$check(count($cartesiaCalls)===4,'Cartesia clone cache is isolated by credential and from Inworld');
$ttsReference='LORKHAN_CUSTOM_TTS_PARITY_API_KEY';
$inworldCredentials->set($ttsReference,'selected-tts-fixture-key');
foreach(['inworld','cartesia']as$badgeDriver){
    $badgeCalls=[];
    $badgeLibrary=new \LorkhanServer\Application\CloudVoiceLibrary($inworldCredentials,
        static function(string $driver,string $path,array|string|null $body,array $headers)use(&$badgeCalls,$cartesiaId):array{
            $badgeCalls[]=$headers;
            return $body===null?['data'=>[]]:($driver==='cartesia'?['id'=>$cartesiaId]:['voice'=>['voiceId'=>'badge__clone']]);
        },[$badgeDriver=>$ttsReference]);
    $badgeResolver=new \LorkhanServer\Application\InworldVoiceResolver($badgeLibrary,$inworldCredentials,$inworldRoot,$badgeDriver,$ttsReference);
    $badgeResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken());
    $expectedHeader='Authorization: '.($badgeDriver==='cartesia'?'Bearer ':'Basic ').'selected-tts-fixture-key';
    $check(count($badgeCalls)===2&&in_array($expectedHeader,$badgeCalls[0],true)&&in_array($expectedHeader,$badgeCalls[1],true)
        &&is_file($inworldRoot.'/.'.$badgeDriver.'-cache/'.hash_hmac('sha256','mw_dark_elf_male','selected-tts-fixture-key').'.json'),
        $badgeDriver.' uses the selected badge for discovery, cloning and credential-scoped cache identity');
    $badgeResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken());
    $check(count($badgeCalls)===2,$badgeDriver.' selected-badge cache does not repeat the upload');
}
// Studio selects a connector independently of playback; every explicit operation must share its scope.
foreach (['cartesia','inworld'] as $studioDriver) {
    $studioCalls=[];
    $studioLibrary=new \LorkhanServer\Application\CloudVoiceLibrary($inworldCredentials,
        static function(string $driver,string $path,array|string|null $body,array $headers)use(&$studioCalls):array{
            $studioCalls[]=['path'=>$path,'headers'=>$headers];
            return $body===null?['voices'=>[['voiceId'=>'fixture__voice','displayName'=>'Fixture'],['voiceId'=>'other__voice','displayName'=>'Other']]]
                :($driver==='cartesia'?['id'=>'fixture-clone']:['voice'=>['voiceId'=>'fixture__clone']]);
        });
    $scopedStudio=$studioLibrary->forPreset(['driver'=>$studioDriver,'credential'=>$ttsReference,'options'=>['workspace'=>'workspaces/fixture']]);
    $studioVoices=$scopedStudio->discover($studioDriver);
    $scopedStudio->clone($studioDriver,$inworldRoot.'/mw_dark_elf_male.wav','fixture','en');
    $expectedHeader='Authorization: '.($studioDriver==='cartesia'?'Bearer ':'Basic ').'selected-tts-fixture-key';
    $check(count($studioCalls)===2&&in_array($expectedHeader,$studioCalls[0]['headers'],true)
        &&in_array($expectedHeader,$studioCalls[1]['headers'],true)
        &&($studioDriver!=='inworld'||(count($studioVoices)===1&&$studioCalls[1]['path']==='/voices/v1/workspaces/fixture/voices:clone')),
        $studioDriver.' Studio discovery and upload use the selected badge and workspace');
    foreach (['','none'] as $emptyBadge) {
        try {$studioLibrary->forPreset(['driver'=>$studioDriver,'credential'=>$emptyBadge])->discover($studioDriver);$check(false,'Studio empty badge rejected');}
        catch(RuntimeException $error){$check($error->getMessage()==='voice_credential_missing'&&count($studioCalls)===2,
            $studioDriver.' Studio empty badge cannot fall back to the global account');}
    }
}
$noKeyCalls=0;
$workspaceCalls=[];
$workspaceLibrary=new \LorkhanServer\Application\CloudVoiceLibrary($inworldCredentials,
    static function(string $driver,string $path,array|string|null $body)use(&$workspaceCalls):array{
        $workspaceCalls[]=$path;
        return $body===null?['voices'=>[['voiceId'=>'elsewhere__voice','displayName'=>'mw_dark_elf_male']]]
            :['voice'=>['voiceId'=>'fixture__clone']];
    },['inworld'=>$ttsReference],'workspaces/fixture');
$workspaceResolver=new \LorkhanServer\Application\InworldVoiceResolver($workspaceLibrary,$inworldCredentials,$inworldRoot,'inworld',$ttsReference,'fixture');
$check($workspaceResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken())==='fixture__clone'
    &&$workspaceCalls===['/voices/v1/voices?pageSize=100','/voices/v1/workspaces/fixture/voices:clone'],
    'Inworld workspace excludes another workspace voice and routes cloning to the selected workspace');
$workspaceResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken());
$check(count($workspaceCalls)===2&&is_file($inworldRoot.'/.inworld-cache/'.hash_hmac('sha256',"fixture\nmw_dark_elf_male",'selected-tts-fixture-key').'.json'),
    'Inworld workspace reuses its own cache without reusing the account-default clone');
foreach(['inworld','cartesia'] as $forgetDriver){
    $forgetLibrary=$forgetDriver==='inworld'?$inworldLibrary:$cartesiaLibrary;
    $forgetResolver=new \LorkhanServer\Application\InworldVoiceResolver($forgetLibrary,$inworldCredentials,$inworldRoot,$forgetDriver,$ttsReference);
    $cacheBase=$inworldRoot.'/.'.$forgetDriver.'-cache/'.hash_hmac('sha256','mw_dark_elf_male','selected-tts-fixture-key');
    $held=fopen($cacheBase.'.lock','c');flock($held,LOCK_EX);
    try{$forgetResolver->forget('mw_dark_elf_male');$check(false,'Busy cache forget must fail');}
    catch(RuntimeException $error){$check($error->getMessage()==='voice_registration_busy'&&is_file($cacheBase.'.json'),$forgetDriver.' forget cannot race a running registration');}
    finally{flock($held,LOCK_UN);fclose($held);}
    $beforeForgetCalls=count($inworldCalls)+count($cartesiaCalls)+count($workspaceCalls);
    $forgetResolver->forget('mw_dark_elf_male');$forgetResolver->forget('mw_dark_elf_male');
    $check(!is_file($cacheBase.'.json')&&file_get_contents($inworldRoot.'/mw_dark_elf_male.wav')===$wav
        &&count($inworldCalls)+count($cartesiaCalls)+count($workspaceCalls)===$beforeForgetCalls
        &&is_file($inworldRoot.'/.inworld-cache/'.hash_hmac('sha256',"fixture\nmw_dark_elf_male",'selected-tts-fixture-key').'.json'),
        $forgetDriver.' forgetting is idempotent, preserves other workspaces and sample bytes, and never contacts the provider');
}
$workspaceResolver->forget('mw_dark_elf_male');
$check(!is_file($inworldRoot.'/.inworld-cache/'.hash_hmac('sha256',"fixture\nmw_dark_elf_male",'selected-tts-fixture-key').'.json'),
    'Studio can forget the selected workspace mapping as well as the account default');
// Cloud lifecycle checks use synthetic audio and an injected transport, never a real provider account.
file_put_contents($inworldRoot.'/managed_fixture.wav',$wav);
foreach(['inworld','cartesia'] as $managedDriver){
    $lifecycleCalls=[];$cloneNumber=0;$deleteFails=false;$forcedClone='';
    $lifecycleLibrary=new \LorkhanServer\Application\CloudVoiceLibrary($inworldCredentials,
        static function(string $driver,string $path,array|string|null $body,array $headers,string $method)use(&$lifecycleCalls,&$cloneNumber,&$deleteFails,&$forcedClone):array{
            $lifecycleCalls[]=[$method,$path];
            if($method==='DELETE'){if($deleteFails)throw new RuntimeException('voice_provider_http_503');return [];}
            if($method==='GET')return ['voices'=>[]];
            $id=$forcedClone!==''?$forcedClone:($driver==='inworld'?'lifecycle__'.(++$cloneNumber):sprintf('12345678-1234-1234-1234-%012d',++$cloneNumber));
            return $driver==='inworld'?['voice'=>['voiceId'=>$id]]:['id'=>$id];
        },[$managedDriver=>$ttsReference],'lifecycle');
    $lifecycleResolver=new \LorkhanServer\Application\InworldVoiceResolver($lifecycleLibrary,$inworldCredentials,$inworldRoot,$managedDriver,$ttsReference,'lifecycle');
    $liveId=$lifecycleResolver->resolve('managed_fixture','en',new NeverCancelledToken());
    $check($lifecycleResolver->isManaged('managed_fixture',$liveId),$managedDriver.' records installation ownership only after creating its clone');
    $deleteCount=count($lifecycleCalls);
    try{$lifecycleResolver->deleteManaged('managed_fixture','wrong-id');$check(false,'Managed deletion rejects stale ID');}
    catch(RuntimeException $e){$check($e->getMessage()==='voice_not_managed'&&count($lifecycleCalls)===$deleteCount,$managedDriver.' rejects a mismatched remote delete before any network call');}
    $validate=static fn(string $id):array=>['bytes'=>$wav];
    $replacement=$lifecycleResolver->rebuild('managed_fixture','en',$validate);
    $deletePrefix=$managedDriver==='inworld'?'/voices/v1/voices/':'/voices/';
    $check($replacement['id']!==$liveId&&$lifecycleResolver->isManaged('managed_fixture',$replacement['id'])
        &&end($lifecycleCalls)===['DELETE',$deletePrefix.$liveId]&&!$replacement['cleanup_failed'],
        $managedDriver.' publishes a validated replacement then deletes its owned predecessor');
    $liveId=$replacement['id'];
    try{$lifecycleResolver->rebuild('managed_fixture','en',static fn():array=>['bytes'=>'invalid audio']);$check(false,'Invalid replacement audio rejected');}
    catch(RuntimeException){$check($lifecycleResolver->isManaged('managed_fixture',$liveId)&&end($lifecycleCalls)[0]==='DELETE'
        &&end($lifecycleCalls)[1]!==$deletePrefix.$liveId,$managedDriver.' failed validation deletes only the candidate and preserves the live mapping');}
    $forcedClone=$liveId;$deleteCount=count($lifecycleCalls);
    try{$lifecycleResolver->rebuild('managed_fixture','en',$validate);$check(false,'Repeated live clone ID rejected');}
    catch(RuntimeException $e){$check($e->getMessage()==='voice_clone_reused_id'&&count($lifecycleCalls)===$deleteCount+1
        &&$lifecycleResolver->isManaged('managed_fixture',$liveId),$managedDriver.' never cleans up a clone response that repeats the live ID');}
    $forcedClone='';$deleteFails=true;
    try{$lifecycleResolver->deleteManaged('managed_fixture',$liveId);$check(false,'Provider delete failure surfaced');}
    catch(RuntimeException $e){$check($e->getMessage()==='voice_provider_http_503'&&$lifecycleResolver->isManaged('managed_fixture',$liveId),$managedDriver.' failed remote deletion retains its mapping');}
    try{$lifecycleResolver->rebuild('managed_fixture','en',static fn():array=>['bytes'=>'invalid audio']);$check(false,'Failed validation cleanup surfaced');}
    catch(RuntimeException $e){$check($e->getMessage()==='voice_validation_cleanup_failed'&&$lifecycleResolver->isManaged('managed_fixture',$liveId),
        $managedDriver.' reports failed candidate cleanup while preserving the working mapping');}
    $replacement=$lifecycleResolver->rebuild('managed_fixture','en',$validate);
    $check($replacement['cleanup_failed']&&$lifecycleResolver->isManaged('managed_fixture',$replacement['id']),
        $managedDriver.' reports old-clone cleanup failure without discarding the validated replacement');
    $deleteFails=false;$lifecycleResolver->deleteManaged('managed_fixture',$replacement['id']);
    $check(!$lifecycleResolver->isManaged('managed_fixture',$replacement['id'])&&file_get_contents($inworldRoot.'/managed_fixture.wav')===$wav,
        $managedDriver.' successful managed deletion forgets the ID but preserves the local sample');
    $legacyPath=$inworldRoot.'/.'.$managedDriver.'-cache/'.hash_hmac('sha256',($managedDriver==='inworld'?"lifecycle\n":'').'managed_fixture','selected-tts-fixture-key').'.json';
    file_put_contents($legacyPath,json_encode(['voice_id'=>'external-voice']));$deleteCount=count($lifecycleCalls);
    try{$lifecycleResolver->deleteManaged('managed_fixture','external-voice');$check(false,'Legacy voice must not be deleted');}
    catch(RuntimeException $e){$check($e->getMessage()==='voice_not_managed'&&count($lifecycleCalls)===$deleteCount,$managedDriver.' legacy entries do not acquire ownership retroactively');}
    $replacement=$lifecycleResolver->rebuild('managed_fixture','en',$validate);
    $check(!$replacement['cleanup_failed']&&count($lifecycleCalls)===$deleteCount+1,
        $managedDriver.' replacing an external voice never deletes the external predecessor');
    $deleteCount=count($lifecycleCalls);
    $replacement=$lifecycleResolver->rebuild('managed_fixture','en',$validate,false);
    $check($replacement['previous_kept']&&count($lifecycleCalls)===$deleteCount+1&&$lifecycleResolver->isManaged('managed_fixture',$replacement['id']),
        $managedDriver.' retains an explicitly referenced predecessor while publishing the new sample mapping');
}
foreach(['../wrong','workspaces/fixture/extra','https://other.invalid', ['fixture']]as$invalidWorkspace){
    try{ConnectorCatalog::validate('tts_provider',ConnectorCatalog::defaults('tts_provider','inworld')+['driver'=>'inworld','options'=>['workspace'=>$invalidWorkspace]]);$check(false,'invalid workspace rejected');}
    catch(InvalidArgumentException){$check(true,'Inworld workspace rejects paths, URLs and non-string values before save');}
}
$noKeyLibrary=new \LorkhanServer\Application\CloudVoiceLibrary($inworldCredentials,
    static function()use(&$noKeyCalls):array{++$noKeyCalls;return[];},['inworld'=>'none']);
try{$noKeyLibrary->discover('inworld');$check(false,'None badge must not use the global voice key');}
catch(RuntimeException $error){$check($error->getMessage()==='voice_credential_missing'&&$noKeyCalls===0,'None badge never falls back to the global voice credential');}
foreach(['openai','11labs','azure','cartesia','convai','coqui-ai','deepgram','gcp','inworld']as$badgeDriver){
    $badgeContent=ConnectorCatalog::defaults('tts_provider',$badgeDriver)+['driver'=>$badgeDriver,'credential'=>$ttsReference];
    $badgeContent['voice']='fixture-voice';
    $badgeContent['endpoint']='https://93.184.216.34/fixture'; // Constructor only; no request or DNS dependency.
    $badgeProvider=ProviderFactory::speechForPreset(['credential_storage_path'=>$inworldRoot.'/keys.json','voice_storage_path'=>$inworldRoot],['kind'=>'tts_provider','content'=>$badgeContent]);
    $check((new \ReflectionProperty($badgeProvider,'apiKey'))->getValue($badgeProvider)==='selected-tts-fixture-key',$badgeDriver.' synthesis resolves the chosen private TTS badge');
}
try{ConnectorCatalog::validate('tts_provider',ConnectorCatalog::defaults('tts_provider','inworld')+['driver'=>'inworld','credential'=>'DATABASE_PASSWORD']);$check(false,'unrelated environment credential rejected');}
catch(InvalidArgumentException){$check(true,'TTS badge cannot name an unrelated environment secret');}
foreach(['pockettts','omnivoice','chatterbox','xtts-fastapi']as$driver){
    $localCalls=[];$registered=false;
    $localResolver=new \LorkhanServer\Application\LocalVoiceResolver('http://127.0.0.1:8999/prefix/tts_to_audio/',$driver,$inworldRoot,'',30000,
        static function(string $url,?array $fields)use(&$localCalls,&$registered):array{
            $localCalls[]=['url'=>$url,'fields'=>$fields];
            if($fields===null)return $registered?['mw_dark_elf_male']:[];
            $registered=true;return ['import_status'=>'runtime_ready'];
        });
    $localVoice=$localResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken());
    $check($localVoice===['speaker_wav'=>'mw_dark_elf_male']&&count($localCalls)===2
        &&$localCalls[1]['url']==='http://127.0.0.1:8999/prefix/upload_sample'
        &&$localCalls[1]['fields']['wavFile']->getPostFilename()==='mw_dark_elf_male.wav',
        $driver.' registers the local sample at the configured service before synthesis');
    if($driver==='omnivoice')$check(str_contains($localCalls[1]['fields']['reference_text'],'outlander')
        &&$localCalls[1]['fields']['force']==='false','OmniVoice includes catalog transcription and never forces replacement');
    $localResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken());
    $check(count($localCalls)===3,$driver.' discovers and preserves an existing remote voice without reuploading');
    $registered=false;$localResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken());
    $check(count($localCalls)===5,$driver.' re-registers automatically after the remote voice library is reset');
    $check($localResolver->resolve('provider_stock_voice','en',new NeverCancelledToken())===['speaker_wav'=>'provider_stock_voice']
        &&count($localCalls)===5,$driver.' passes provider-owned voices through without sample uploads');
    try{$localResolver->resolve('../escape','en',new NeverCancelledToken());$check(false,'local sample traversal rejected');}
    catch(RuntimeException $e){$check($e->getMessage()==='invalid_voice_name','local sample traversal rejected');}
    try{$localResolver->resolve('mw_dark_elf_male','en',new \LorkhanServer\Application\CallbackCancellationToken(static fn()=>true));$check(false,'local cancellation before upload');}
    catch(\LorkhanServer\Application\OperationCancelled){$check(count($localCalls)===5,'local cancellation prevents discovery and upload');}
}
// Another PHP worker can delete a sample while this process retains its realpath cache.
$removedSample=$inworldRoot.'/worker_removed.wav';
copy($inworldRoot.'/mw_dark_elf_male.wav',$removedSample);
realpath($removedSample);
$removeProcess=proc_open([PHP_BINARY,'-r','exit(unlink($argv[1]) ? 0 : 1);',$removedSample],[], $removePipes);
if(!is_resource($removeProcess)||proc_close($removeProcess)!==0)throw new RuntimeException('sample removal fixture failed');
$check($localResolver->resolve('worker_removed','en',new NeverCancelledToken())===['speaker_wav'=>'worker_removed']
    &&count($localCalls)===5,'deleted local sample uses the provider voice despite a stale worker realpath cache');
$legacyCalls=[];$legacyLatents=['speaker_embedding'=>[0.1,0.2],'gpt_cond_latent'=>[[0.3,0.4]]];
$legacyResolver=new \LorkhanServer\Application\LocalVoiceResolver('http://127.0.0.1:8999/tts_stream','xtts',$inworldRoot,'',30000,
    static function(string $url,?array $fields)use(&$legacyCalls,$legacyLatents):array{
        $legacyCalls[]=['url'=>$url,'fields'=>$fields];return $legacyLatents;
    });
$check($legacyResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken())===$legacyLatents
    &&$legacyCalls[0]['url']==='http://127.0.0.1:8999/clone_speaker'
    &&isset($legacyCalls[0]['fields']['wav_file']),
    'legacy XTTS extracts speaker tensors through clone_speaker instead of the FastAPI upload contract');
$check($legacyResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken())===$legacyLatents&&count($legacyCalls)===1,
    'legacy XTTS reuses cached conditioning tensors for subsequent sentences');
$notReadyResolver=new \LorkhanServer\Application\LocalVoiceResolver('http://127.0.0.1:8999','omnivoice',$inworldRoot,'',30000,
    static fn(string $url,?array $fields):array=>$fields===null?[]:['import_status'=>'needs_reference_text']);
try{$notReadyResolver->resolve('mw_dark_elf_male','en',new NeverCancelledToken());$check(false,'incomplete voice registration rejected');}
catch(RuntimeException $e){$check($e->getMessage()==='voice_registration_not_ready','incomplete OmniVoice registration is not reported as usable');}
foreach(['.cartesia-cache','.local-voice-cache']as$directory){foreach(glob($inworldRoot.'/'.$directory.'/*')?:[]as$file)unlink($file);rmdir($inworldRoot.'/'.$directory);}
foreach(glob($inworldRoot.'/.inworld-cache/*')?:[]as$file)unlink($file);
rmdir($inworldRoot.'/.inworld-cache');unlink($inworldRoot.'/keys.json');unlink($inworldRoot.'/mw_dark_elf_male.wav');unlink($inworldRoot.'/managed_fixture.wav');rmdir($inworldRoot);

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

$rpgResolver = new EffectiveSettingsResolver();
$rpgGlobal = SettingsCatalog::globalDefaults();
$rpgGlobal['rpg_comments'] = ['events'=>['sleep','wait'], 'chance_percent'=>73];
$rpgInherited = $rpgResolver->resolve($rpgGlobal, [], []);
$check($rpgInherited['settings']['rpg_comments'] === $rpgGlobal['rpg_comments'], 'Core RPG policy inherits global settings');
$rpgNpcOnly = $rpgResolver->resolve($rpgGlobal, [], ['settings_overrides'=>['rpg_comments'=>['chance_percent'=>0]]]);
$check($rpgNpcOnly['settings']['rpg_comments'] === $rpgGlobal['rpg_comments'], 'RPG policy remains Core Profile owned rather than an NPC override');
foreach ([['events'=>[]], ['chance_percent'=>0], ['events'=>['combat_end'],'chance_percent'=>100]] as $override) {
    $resolvedRpg = $rpgResolver->resolve($rpgGlobal, ['settings_overrides'=>['rpg_comments'=>$override]], []);
    $check($resolvedRpg['settings']['rpg_comments'] === array_replace($rpgGlobal['rpg_comments'], $override),
        'Core RPG policy preserves partial overrides and explicit off');
    foreach ($override as $field => $_) {
        $check($resolvedRpg['sources']['settings.rpg_comments.'.$field] === 'core_profile', 'RPG setting reports Core Profile ownership');
    }
    $check(!isset(EffectiveSettingsResolver::controlsProjection($resolvedRpg)['settings']['rpg_comments']),
        'server RPG policy does not enlarge native controls');
}
foreach ([null, [], ['events'=>['lockpick']], ['events'=>['sleep','sleep']], ['events'=>[1]],
    ['events'=>['x'=>'sleep']], ['chance_percent'=>-1], ['chance_percent'=>101], ['chance_percent'=>'50'],
    ['chance_percent'=>false], ['unknown'=>true]] as $invalidRpg) {
    try { EffectiveSettingsResolver::validateSettingsOverrides(['rpg_comments'=>$invalidRpg]); $check(false, 'invalid Core RPG policy rejected'); }
    catch (InvalidArgumentException $exception) { $check($exception->getMessage() === 'invalid_rpg_comments', 'invalid Core RPG policy rejected'); }
}

$sceneRows=[];
foreach(range(1,12) as $index) $sceneRows[]=['id'=>'scene-'.str_pad((string)$index,2,'0',STR_PAD_LEFT),
    'tier'=>'mid','content'=>'Scene '.$index,'provenance'=>['source'=>'memory.consolidate','source_tier'=>'recent',
        'source_game_time_range'=>['from'=>$index*100-99.5,'to'=>$index*100+0.5]]];
$sceneWindow=\LorkhanServer\Application\MemoryPromptSelection::sceneWindow(array_reverse($sceneRows),200.5,650.5,3);
$check(array_column($sceneWindow,'id')===['scene-05','scene-06','scene-07'],
    'STM picks newest capped scenes after the digest through the straddling bucket, then returns chronological order');
$check(count(\LorkhanServer\Application\MemoryPromptSelection::sceneWindow($sceneRows,0,null))===10
    &&count(\LorkhanServer\Application\MemoryPromptSelection::sceneWindow($sceneRows,0,null,50))===12,
    'STM has its own default ten and maximum fifty summary window');
$check(array_column(\LorkhanServer\Application\MemoryPromptSelection::sceneWindow($sceneRows,100.5,300.5,50),'id')===['scene-02','scene-03'],
    'STM digest boundary is exclusive and a summary ending exactly at the history floor is included');
$check(count(\LorkhanServer\Application\MemoryPromptSelection::sceneWindow($sceneRows,0,2000.5,50))===12,
    'STM with no straddler retains summaries older than the live window');
$legacyScene=$sceneRows[0];unset($legacyScene['provenance']['source_game_time_range']);
$manualScene=$sceneRows[1];$manualScene['provenance']['source']='manual';
$badScene=$sceneRows[2];$badScene['provenance']['source_game_time_range']=['from'=>400,'to'=>300];
$check(\LorkhanServer\Application\MemoryPromptSelection::sceneWindow([$legacyScene,$manualScene,$badScene],0,500)===[],
    'STM cannot assign scene boundaries to unknown-time, manual or malformed memory records');
foreach([0,51] as $badLimit){
    try{\LorkhanServer\Application\MemoryPromptSelection::sceneWindow($sceneRows,0,null,$badLimit);$check(false,'invalid STM limit rejected');}
    catch(InvalidArgumentException){$check(true,'invalid STM limit rejected');}
}

$sceneCandidates=array_map(static fn(array $row):array=>$row+['text'=>$row['content']],$sceneRows);
$sceneGeneric=[];foreach(range(1,10) as $index)$sceneGeneric[]=['id'=>'generic-'.$index,'text'=>'Other memory '.$index];
$sceneContext=MemoryPromptSelection::selectSceneContext([...$sceneGeneric,...$sceneCandidates],'',650.5,1024,3);
$check(count($sceneContext['texts'])===13 && array_slice(array_keys($sceneContext['texts']),-3)===['scene-05','scene-06','scene-07'],
    'rendered STM has an independent summary quota alongside general memories');
$check(($sceneContext['reasons']['scene-08']??'')==='outside_scene_window',
    'scene window exclusions are recorded in retrieval reasons');
$sceneDigest=['id'=>'digest','text'=>"Scene 5\nScene 6",'tier'=>'long'];
$sceneContext=MemoryPromptSelection::selectSceneContext([$sceneDigest,...$sceneCandidates],'',650.5,1024,3);
$check(array_keys($sceneContext['texts'])===['digest','scene-03','scene-04','scene-07'],
    'retained digest removes covered scenes before the cap and fills remaining slots with uncovered history');
$coveredBoundary=MemoryPromptSelection::selectSceneContext([['id'=>'boundary-digest','text'=>'Scene 7'],...$sceneCandidates],'',650.5,1024,3);
$check(array_keys($coveredBoundary['texts'])===['boundary-digest','scene-04','scene-05','scene-06']
    &&$coveredBoundary['reasons']['scene-07']==='covered_by_memory'
    &&$coveredBoundary['reasons']['scene-08']==='outside_scene_window',
    'a digest-covered straddler does not move the upper scene boundary into newer live history');
$sceneContext=MemoryPromptSelection::selectSceneContext([$sceneDigest,...$sceneCandidates],'',650.5,8,3);
$check(isset($sceneContext['texts']['scene-05'],$sceneContext['texts']['scene-06'],$sceneContext['texts']['scene-07']),
    'truncated digest cannot hide scene summaries behind a timestamp');
$sceneContext=MemoryPromptSelection::selectSceneContext($sceneCandidates,'',null,1024,3);
$check(array_keys($sceneContext['texts'])===['scene-10','scene-11','scene-12'],
    'removing history recalculates the scene window without its previous upper boundary');
$check(MemoryPromptSelection::sceneWindow([null,['provenance'=>'invalid']],0,null)===[],
    'scene classification tolerates invalid untrusted provenance');

$sceneSelection=$promptSelection;
$sceneSelection['memory']=$sceneRows;
$sceneSelection['history']=[['id'=>'scene-live','content'=>['kind'=>'speech','speaker'=>'Guard','text'=>'Unrelated live dialogue.','game_time'=>650.5]]];
$sceneSelection['recent_action_results']=[];
$sceneSelection['memory_retrieval']=['result_ids'=>[]];
$scenePrompt=(new PromptAssembler(16384,1024))->assemble($promptTurn,$sceneSelection);
$check($scenePrompt['trace']['memory_retrieval']['result_ids']===array_column(array_slice($sceneRows,0,7),'id')
    &&str_contains($scenePrompt['provider_input']['_assembled_prompt'],'Unrelated live dialogue.')
    &&!str_contains($scenePrompt['provider_input']['_assembled_prompt'],'Scene 8'),
    'real prompt assembly selects scene summaries through the live straddler without erasing unrelated history');
$sceneSelection['effective_settings']['settings']['memory']=['short_term_enabled'=>false,'mid_term_enabled'=>true];
$disabledScenePrompt=(new PromptAssembler(16384,1024))->assemble($promptTurn,$sceneSelection);
$check($disabledScenePrompt['trace']['memory_retrieval']['result_ids']===[],
    'Short Term Memory switch controls recent-source scene summaries in actual prompt assembly');
$sceneSelection['effective_settings']['settings']['memory']=['short_term_enabled'=>true,'mid_term_enabled'=>false];
$enabledScenePrompt=(new PromptAssembler(16384,1024))->assemble($promptTurn,$sceneSelection);
$check(count($enabledScenePrompt['trace']['memory_retrieval']['result_ids'])===7,
    'scene summaries are not incorrectly owned by the Middle Term Memory switch');

$sceneSelection['effective_settings']['settings']['memory']['short_term_max_summaries']=3;
$limitedScenePrompt=(new PromptAssembler(16384,1024))->assemble($promptTurn,$sceneSelection);
$check($limitedScenePrompt['trace']['memory_retrieval']['result_ids']===['scene-05','scene-06','scene-07'],
    'profile Max Summaries reaches the real scene selector');
$stmCore=['settings_overrides'=>['memory'=>['short_term_max_summaries'=>37]]];
$stmResolved=(new EffectiveSettingsResolver())->resolve([],$stmCore,[]);
$check($stmResolved['settings']['memory']['short_term_max_summaries']===37
    &&$stmResolved['sources']['settings.memory.short_term_max_summaries']==='core_profile'
    &&!isset(EffectiveSettingsResolver::controlsProjection($stmResolved)['settings']['memory']['short_term_max_summaries']),
    'Max Summaries is a traced server-owned Core field without changing the client contract');
$stmPreset=\LorkhanServer\Application\CoreProfilePreset::capture($stmCore);
$check(\LorkhanServer\Application\CoreProfilePreset::apply($stmPreset,$corePresetSource)['settings_overrides']['memory']['short_term_max_summaries']===37,
    'Max Summaries survives named preset capture and application');
foreach([0,51,1.5,'10',true]as$invalidLimit){
    try{EffectiveSettingsResolver::validateSettingsOverrides(['memory'=>['short_term_max_summaries'=>$invalidLimit]]);$check(false,'invalid Core STM limit rejected');}
    catch(InvalidArgumentException){$check(true,'invalid Core STM limit rejected');}
}

$overlapSelection=$sceneSelection;
$overlapSelection['memory'][6]['content']="Guard: Unrelated live dialogue.\nEarlier quest discovered.";
array_unshift($overlapSelection['memory'],['id'=>'overlap-child','content'=>'Guard: Unrelated live dialogue.']);
$overlapPrompt=(new PromptAssembler(16384,1024))->assemble($promptTurn,$overlapSelection);
$overlapSources=array_column($overlapPrompt['trace']['sources'],null,'source_id');
$check(substr_count($overlapPrompt['provider_input']['_assembled_prompt'],'Guard: Unrelated live dialogue.')===1
    &&!$overlapSources['scene-live']['included']&&$overlapSources['scene-live']['reason']==='covered_by_memory'
    &&$overlapSources['overlap-child']['reason']==='covered_by_memory',
    'retained complete scene replaces its exact dated history line and both source traces name the surviving memory');
$overlapSelection['history'][0]['content']['game_time']=750.5;
$outsideTimePrompt=(new PromptAssembler(16384,1024))->assemble($promptTurn,$overlapSelection);
$outsideTimeSources=array_column($outsideTimePrompt['trace']['sources'],null,'source_id');
$check($outsideTimeSources['scene-live']['included'],
    'repeated words after a summary time range remain live history');
$overlapSelection['history'][0]['content']['game_time']=650.5;
$overlapSelection['memory'][7]['content']='Earlier material '.str_repeat('x',1100)."\nGuard: Unrelated live dialogue.";
$cutScenePrompt=(new PromptAssembler(16384,1024))->assemble($promptTurn,$overlapSelection);
$cutSceneSources=array_column($cutScenePrompt['trace']['sources'],null,'source_id');
$check($cutSceneSources['scene-live']['included']&&str_contains($cutScenePrompt['provider_input']['_assembled_prompt'],'Guard: Unrelated live dialogue.'),
    'a source-byte-truncated scene cannot remove its live source line');
$overlapSelection['memory'][7]['content']="Guard: Unrelated live dialogue.\nEarlier quest discovered.";
$overlapSelection['effective_settings']['settings']['memory']['short_term_enabled']=false;
$offScenePrompt=(new PromptAssembler(16384,1024))->assemble($promptTurn,$overlapSelection);
$check(array_column($offScenePrompt['trace']['sources'],null,'source_id')['scene-live']['included'],
    'disabling STM restores live history rather than leaving the summary overlap hidden');
$overlapSelection['effective_settings']['settings']['memory']['short_term_enabled']=true;
$minimalOverlap=(new PromptAssembler(512,256))->assemble($promptTurn,$overlapSelection);
$check(!in_array('covered_by_memory',array_column($minimalOverlap['trace']['sources'],'reason'),true)
    &&$minimalOverlap['trace']['memory_retrieval']['result_ids']===[],
    'minimal budget fallback cannot claim history was replaced by a summary that was removed');
$pruneScene=$sceneCandidates[6];$pruneScene['content']=$pruneScene['text']="Guard: Heard line.\nOther past fact.";
$pruneHistory=[['_source_id'=>'heard','_line'=>'Guard: Heard line.','_complete'=>true,'_game_time'=>650.5,'_time_heading'=>"--- Moments Ago ---\n"],
    ['_source_id'=>'other','_line'=>'Guard: Keep this line.','_complete'=>true,'_game_time'=>650.6]];
$pruneCandidate=['id'=>'mixed','text'=>"Guard: Heard line.\nGuard: Keep this line."];
$pruneState=MemoryPromptSelection::selectSceneContext([$pruneCandidate,$pruneScene],$pruneCandidate['text'],650.5,1024);
$pruned=MemoryPromptSelection::pruneHistory($pruneHistory,[$pruneCandidate,$pruneScene],$pruneState);
$check(array_column($pruned['history'],'_source_id')===['other']
    &&$pruned['history'][0]['_time_heading']==="--- Moments Ago ---\n"
    &&$pruned['memory']['reasons']['mixed']==='covered_by_context'
    &&$pruned['memory']['counts']['covered_by_history']===0,
    'pruning preserves the next temporal heading and traces combined memory/history coverage accurately');

$questCore=['settings_overrides'=>['quest_comments'=>['enabled'=>false,'chance_percent'=>25]]];
$questGlobal=SettingsCatalog::globalDefaults();$questGlobal['quest_comments']['enabled']=true;
$questResolved=(new EffectiveSettingsResolver())->resolve($questGlobal,$questCore,[]);
$check($questResolved['settings']['quest_comments']===['enabled'=>false,'chance_percent'=>25]
    &&$questResolved['sources']['settings.quest_comments.enabled']==='core_profile'
    &&!isset(EffectiveSettingsResolver::controlsProjection($questResolved)['settings']['quest_comments']),
    'Core Quest Comment disabled overrides global enable without changing narrator controls');
$questNpc=(new EffectiveSettingsResolver())->resolve([],['settings_overrides'=>['quest_comments'=>['enabled'=>true,'chance_percent'=>100]]],$questCore);
$check($questNpc['settings']['quest_comments']===['enabled'=>false,'chance_percent'=>25]
    &&$questNpc['sources']['settings.quest_comments.enabled']==='npc',
    'NPC Quest Comment false overrides Core true and retains discrete chance');
$questPreset=\LorkhanServer\Application\CoreProfilePreset::capture($questCore);
$check(\LorkhanServer\Application\CoreProfilePreset::apply($questPreset,$corePresetSource)['settings_overrides']['quest_comments']===['enabled'=>false,'chance_percent'=>25],
    'Quest Comment false and selected chance survive named presets');
foreach([0,101,'25',null] as $invalidChance){
    try{EffectiveSettingsResolver::validateSettingsOverrides(['quest_comments'=>['chance_percent'=>$invalidChance]]);$check(false,'invalid quest chance rejected');}
    catch(InvalidArgumentException){$check(true,'invalid quest chance rejected');}
}

$boredCore=['settings_overrides'=>['bored_event'=>['chance_percent'=>0]]];
$boredResolved=(new EffectiveSettingsResolver())->resolve([],$boredCore,[]);
$check($boredResolved['settings']['bored_event']['chance_percent']===0
    &&$boredResolved['sources']['settings.bored_event.chance_percent']==='core_profile'
    &&!isset(EffectiveSettingsResolver::controlsProjection($boredResolved)['settings']['bored_event']),
    'bored chance preserves explicit zero and server ownership without changing strict client controls');
$boredNpc=(new EffectiveSettingsResolver())->resolve([],['settings_overrides'=>['bored_event'=>['chance_percent'=>100]]],$boredCore);
$check($boredNpc['settings']['bored_event']['chance_percent']===0
    &&$boredNpc['sources']['settings.bored_event.chance_percent']==='npc',
    'NPC bored zero overrides Core 100 without changing narrator chance');
$boredPreset=\LorkhanServer\Application\CoreProfilePreset::capture($boredCore);
$check(\LorkhanServer\Application\CoreProfilePreset::apply($boredPreset,$corePresetSource)['settings_overrides']['bored_event']['chance_percent']===0,
    'bored chance zero survives named presets');
foreach([-1,101,'50',null] as $invalidChance){
    try{EffectiveSettingsResolver::validateSettingsOverrides(['bored_event'=>['chance_percent'=>$invalidChance]]);$check(false,'invalid bored chance rejected');}
    catch(InvalidArgumentException){$check(true,'invalid bored chance rejected');}
}

$combatCore=['settings_overrides'=>['behavior'=>['combat_bark_period_seconds'=>600]]];
$combatResolved=(new EffectiveSettingsResolver())->resolve([],$combatCore,[]);
$check($combatResolved['settings']['behavior']['combat_bark_period_seconds']===600
    &&$combatResolved['sources']['settings.behavior.combat_bark_period_seconds']==='core_profile'
    &&EffectiveSettingsResolver::controlsProjection($combatResolved)['settings']['behavior']['combat_bark_period_seconds']===600
    &&$combatResolved['settings']['behavior']['combat_barks']===false,
    'Core combat cooldown reaches native controls without enabling global combat barks');
$combatPreset=\LorkhanServer\Application\CoreProfilePreset::capture($combatCore);
$check(\LorkhanServer\Application\CoreProfilePreset::apply($combatPreset,$corePresetSource)['settings_overrides']['behavior']['combat_bark_period_seconds']===600,
    'combat cooldown survives named presets');
$check(EffectiveSettingsResolver::validateSettingsOverrides(['behavior'=>['combat_bark_period_seconds'=>5]])['behavior']['combat_bark_period_seconds']===5,
    'existing five-second combat cooldown values remain valid');
try{EffectiveSettingsResolver::validateSettingsOverrides(['behavior'=>['combat_bark_period_seconds'=>601]]);$check(false,'combat cooldown above range rejected');}
catch(InvalidArgumentException){$check(true,'combat cooldown above range rejected');}

$globalSettings=SettingsCatalog::globalDefaults();
$check($globalSettings['profile_management']===['auto_lock_profile'=>true,
        'autofill_custom_profiles'=>true,'autofill_custom_profiles_trigger'=>40],
    'automatic profile backfill defaults on after forty completed actor turns');
$legacyGlobalSettings=$globalSettings;
unset($legacyGlobalSettings['prompt']);
unset($legacyGlobalSettings['profile_management']['autofill_custom_profiles'],
    $legacyGlobalSettings['profile_management']['autofill_custom_profiles_trigger']);
$normalizedLegacyGlobal=EffectiveSettingsResolver::validateGlobalSettings($legacyGlobalSettings);
$check($normalizedLegacyGlobal['prompt']===['prompt_head'=>'','emote_moods'=>'']
    &&$normalizedLegacyGlobal['profile_management']['autofill_custom_profiles']===true
    &&$normalizedLegacyGlobal['profile_management']['autofill_custom_profiles_trigger']===40,
    'early v2 Global Settings normalize automatic profile backfill defaults');
try{
    $invalidBackfillSettings=$globalSettings;
    $invalidBackfillSettings['profile_management']['autofill_custom_profiles_trigger']=9;
    EffectiveSettingsResolver::validateGlobalSettings($invalidBackfillSettings);
    $check(false,'automatic profile backfill trigger below ten rejected');
}catch(InvalidArgumentException){$check(true,'automatic profile backfill trigger below ten rejected');}
$globalSettings['client']['behavior']['rechat']=true;
$globalSettings['client']['behavior']['auto_greeting']=true;
$globalSettings['client']['behavior']['boredom']=true;
$globalSettings['client']['behavior']['combat_barks']=true;
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
$coreLayer=['settings_overrides'=>['behavior'=>['rechat'=>false,'rechat_max_depth'=>4,'rechat_allow_actions'=>true],
        'memory'=>['recent_turn_limit'=>7,'knowledge_limit'=>0],
        'oghma'=>['topic_count'=>3]],
    'routing'=>['llm_configuration_id'=>'00000000-0000-4000-8000-000000000111',
        'oghma_configuration_id'=>'00000000-0000-4000-8000-000000000555']];
$npcLayer=['settings_overrides'=>['behavior'=>['rechat'=>true]],
    'routing'=>['llm_configuration_id'=>''],'oghma_knowledge_tags'=>'Dagoth Ur'];
$effective=(new EffectiveSettingsResolver())->resolve($globalSettings,$coreLayer,$npcLayer);
$check($effective['settings']['behavior']['rechat']===true
    &&$effective['settings']['behavior']['rechat_max_depth']===4
    &&$effective['settings']['memory']['recent_turn_limit']===7
    &&$effective['settings']['memory']['knowledge_limit']===5
    &&$effective['routing']['llm_configuration_id']==='00000000-0000-4000-8000-000000000111'
    &&$effective['routing']['oghma_configuration_id']==='00000000-0000-4000-8000-000000000222',
    'Explicit NPC Rechat overrides Core Profile behavior while system routing remains separately owned');
$npcExplicit = ['settings_overrides'=>['behavior'=>['rechat'=>false,'rechat_probability_percent'=>0],
    'memory'=>['recent_turn_limit'=>3,'short_term_enabled'=>false], 'response'=>['max_words'=>0]]];
$npcResolved=(new EffectiveSettingsResolver())->resolve($globalSettings,$coreLayer,$npcExplicit);
$check($npcResolved['settings']['behavior']['rechat']===false
    &&$npcResolved['settings']['behavior']['rechat_probability_percent']===0
    &&$npcResolved['settings']['memory']['recent_turn_limit']===3
    &&$npcResolved['settings']['memory']['short_term_enabled']===false
    &&$npcResolved['settings']['response']['max_words']===0
    &&$npcResolved['sources']['settings.response.max_words']==='npc',
    'NPC false and zero overrides survive typed precedence and report their source');
$npcInherited=(new EffectiveSettingsResolver())->resolve($globalSettings,$coreLayer,[]);
$check($npcInherited['settings']['memory']['recent_turn_limit']===7
    &&$npcInherited['sources']['settings.memory.recent_turn_limit']==='core_profile',
    'Removing an NPC override restores Core Profile inheritance');
$check($effective['settings']['oghma']['topic_count']===2
    &&$effective['settings']['oghma']['racial_context_enabled']===false
    &&($effective['sources']['settings.oghma.topic_count']??null)==='global'
    &&($effective['sources']['settings.oghma.racial_context_enabled']??null)==='global'
    &&($effective['sources']['settings.oghma.result_limit']??null)==='global',
    'Oghma controls are installation-wide Global Settings');
// The General editor writes the existing NPC tag document without changing unrelated fields.
$tagRouter=(new ReflectionClass(\LorkhanServer\Http\ManagementRouter::class))->newInstanceWithoutConstructor();
$tagMapper=new ReflectionMethod($tagRouter,'profileContent');
$tagBase=['core'=>'Keep identity','oghma_tags'=>['Old tag'],'oghma_knowledge_tags'=>'Old tag'];
$tagEdited=$tagMapper->invoke($tagRouter,['base_content_json'=>json_encode($tagBase),'oghma_knowledge_tags'=>' Tribunal; Ashlanders, Tribunal, Common, Esoteric ']);
$check($tagEdited['oghma_knowledge_tags']==='Tribunal, Ashlanders'&&!isset($tagEdited['oghma_tags'])&&$tagEdited['core']==='Keep identity','NPC General tag edit normalizes existing tags and retains unrelated content');
$tagCleared=$tagMapper->invoke($tagRouter,['base_content_json'=>json_encode($tagBase),'oghma_knowledge_tags'=>'']);
$check(!isset($tagCleared['oghma_knowledge_tags'],$tagCleared['oghma_tags']),'Clearing NPC tags removes both spellings for inherited lookup');
$tagUntouched=$tagMapper->invoke($tagRouter,['base_content_json'=>json_encode($tagBase),'core'=>'Edited identity']);
$check($tagUntouched['oghma_knowledge_tags']==='Old tag'&&$tagUntouched['oghma_tags']===['Old tag'],'Unrelated NPC forms preserve knowledge tags');
foreach([['invalid'],str_repeat('x',4097),"\xFF"] as $invalidTags){
    $rejected=false;try{$tagMapper->invoke($tagRouter,['oghma_knowledge_tags'=>$invalidTags]);}catch(InvalidArgumentException $e){$rejected=$e->getMessage()==='invalid_oghma_knowledge_tags';}
    $check($rejected,'NPC tag editor rejects non-text, oversized and invalid UTF-8 input');
}
$check($effective['settings']['memory']['oghma_knowledge_tags']==='Dagoth Ur'
    &&($effective['sources']['settings.memory.oghma_knowledge_tags']??null)==='npc',
    'non-empty NPC knowledge tags remain character classification instead of a behavior override');
$check($effective['settings']['behavior']['auto_greeting']===true
    && $effective['settings']['behavior']['boredom']===true
    && $effective['settings']['behavior']['rechat_allow_actions']===true
    && $effective['settings']['behavior']['combat_barks']===true
    && $effective['settings']['narrator']['welcome_events']===false
    && ($effective['sources']['settings.behavior.combat_barks']??null)==='global',
    'automatic dialogue and Rechat actions retain their effective settings');
$narratorEffective=(new EffectiveSettingsResolver())->resolve($globalSettings,$coreLayer,$npcLayer,[],false,[
    'name'=>'The Temple Chronicler','enabled'=>true,'context_visibility'=>false,'inline_narration_mode'=>'Narrator',
    'welcome_events'=>true,'welcome_cooldown_minutes'=>45,'random_events'=>true,
    'random_chance_percent'=>35,'random_cooldown_rounds'=>4,'bored_events'=>true,
    'bored_chance_percent'=>60,'quest_events'=>true,'quest_chance_percent'=>25,
    'quest_cooldown_minutes'=>8,'book_events'=>true,
]);
$check($narratorEffective['settings']['narrator']['name']==='The Temple Chronicler'
    &&$narratorEffective['settings']['narrator']['welcome_cooldown_minutes']===45
    &&$narratorEffective['settings']['narrator']['bored_events']===true
    &&$narratorEffective['settings']['narrator']['quest_chance_percent']===25
    &&($narratorEffective['sources']['settings.narrator.quest_chance_percent']??null)==='narrator_profile'
    &&(EffectiveSettingsResolver::controlsProjection($narratorEffective)['source_map']['settings.narrator.bored_events']??null)==='narrator_profile',
    'Narrator profile exclusively owns projected event chances, cooldowns, and bored routing');
try{(new EffectiveSettingsResolver())->resolve($globalSettings,$coreLayer,$npcLayer,[],false,['random_chance_percent'=>101]);
    $check(false,'Narrator profile event chance above one hundred rejected');}
catch(InvalidArgumentException){$check(true,'Narrator profile event chance above one hundred rejected');}
$check(($effective['sources']['settings.behavior.rechat']??null)==='npc'
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
$npcDiaryResolved=(new EffectiveSettingsResolver())->resolve($globalSettings,
    ['settings_overrides'=>['diary'=>['enabled'=>true,'automatic_enabled'=>true,'automatic_wait_enabled'=>false,'automatic_interval_seconds'=>240]]],
    ['diary'=>['automatic_enabled'=>false,'automatic_wait_enabled'=>true]]);
$check($npcDiaryResolved['settings']['diary']['automatic_enabled']===false
    &&$npcDiaryResolved['settings']['diary']['automatic_wait_enabled']===true
    &&$npcDiaryResolved['settings']['diary']['enabled']===true
    &&$npcDiaryResolved['settings']['diary']['automatic_interval_seconds']===240
    &&$npcDiaryResolved['sources']['settings.diary.automatic_enabled']==='npc',
    'NPC diary toggles override their own leaves without replacing inherited generation and interval settings');
// Narrator content is passed as the selected profile; installation-wide narrator options must not leak its diary override to other NPCs.
foreach ([false,true] as $coreLatestDiary) {
    $latestDiaryCore=['settings_overrides'=>['diary'=>['latest_entry_in_context'=>$coreLatestDiary]]];
    $latestDiaryInherited=(new EffectiveSettingsResolver())->resolve($globalSettings,$latestDiaryCore,[]);
    $check($latestDiaryInherited['settings']['diary']['latest_entry_in_context']===$coreLatestDiary
        &&$latestDiaryInherited['sources']['settings.diary.latest_entry_in_context']==='core_profile',
        'latest diary context inherits the assigned Core Profile default when no author override is saved');
    foreach ([false,true] as $narratorLatestDiary) {
        $latestDiaryNarrator=['diary'=>['latest_entry_in_context'=>$narratorLatestDiary]];
        $latestDiarySelected=(new EffectiveSettingsResolver())->resolve($globalSettings,$latestDiaryCore,$latestDiaryNarrator,[],false,$latestDiaryNarrator);
        $latestDiaryOtherNpc=(new EffectiveSettingsResolver())->resolve($globalSettings,$latestDiaryCore,[],[],false,$latestDiaryNarrator);
        $check($latestDiarySelected['settings']['diary']['latest_entry_in_context']===$narratorLatestDiary
            &&$latestDiarySelected['sources']['settings.diary.latest_entry_in_context']==='npc'
            &&$latestDiaryOtherNpc['settings']['diary']['latest_entry_in_context']===$coreLatestDiary
            &&$latestDiarySelected['settings']['diary']['include_in_context']===true,
            'explicit Narrator latest diary true and false override inheritance without affecting another NPC or existing narrative recall');
    }
}
$projectionInput=$effective;
$projectionInput['settings']['presentation']=['show_status_hud'=>false,'transcript_rows'=>20,'tts_volume_boost'=>4];
$projectionInput['routing']['profile_generation_configuration_id']='00000000-0000-4000-8000-000000000333';
$projectionInput['routing']['relationship_configuration_id']='00000000-0000-4000-8000-000000000444';
$projectionInput['routing']['diary_generation_configuration_id']='00000000-0000-4000-8000-000000000555';
$projectionInput['settings']['diary']=$diaryOverrides;
$projection=EffectiveSettingsResolver::controlsProjection($projectionInput);
$check($projection['settings']['behavior']['rechat']===true
    &&$projection['settings']['memory']['knowledge_limit']===EffectiveSettingsResolver::defaults()['memory']['knowledge_limit']
    &&$projection['routing']['llm_configuration_id']==='00000000-0000-4000-8000-000000000111'
    &&$projection['source_map']['settings.behavior.rechat']==='npc'
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
    &&$projection['settings']['behavior']['auto_greeting']===true
    &&$projection['settings']['behavior']['rechat_allow_actions']===false,
    'controls omit server-only settings and provenance while retaining automatic dialogue settings');
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
try {
    (new MediaStore(dirname(__DIR__) . '/ui'))->put($mediaId, $speech['bytes'], $speech['codec'], $speech['mime_type']);
    $check(false, 'media storage rejects the relocated web root');
} catch (RuntimeException $error) {
    $check($error->getMessage() === 'media_storage_unsafe', 'media storage rejects the relocated web root');
}
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

$presetCurrent = \LorkhanServer\Application\SettingsCatalog::globalDefaults();
$presetCurrent['context']['location_blacklist'] = ['Balmora', 'Vivec'];
$presetCurrent['system_routing']['relationship_configuration_id'] = '00000000-0000-4000-8000-000000000098';
$presetCurrent['client']['presentation']['transcript_rows'] = 12;
$presetSummary = ['schema'=>'lorkhan.memory-policy.v1','enabled'=>false,'provider_configuration_id'=>'00000000-0000-4000-8000-000000000098'];
$presetEmbedding = \LorkhanServer\Application\MemoryEmbeddingPolicy::defaults();
$presetEmbedding['endpoint'] = 'http://127.0.0.1:8181';
$quickLocal=\LorkhanServer\Application\GlobalSettingsPreset::applyBuiltIn('builtin:local_llm',$presetCurrent);
$check(!$quickLocal['profile_management']['autofill_custom_profiles']&&!$quickLocal['relationship']['enabled']&&$quickLocal['relationship']['update_chance_percent']===0,'Local preset stops backfill and relationship updates');
$check($quickLocal['context']['ground_items_descriptions_only']&&$quickLocal['context']['inventory_items_descriptions_only']&&!$quickLocal['context']['prompt_timestamp'],'Local preset uses descriptions without timestamp headings');
$quickDefault=\LorkhanServer\Application\GlobalSettingsPreset::applyBuiltIn('builtin:default',$quickLocal);
$check($quickDefault['profile_management']['autofill_custom_profiles']&&$quickDefault['relationship']['enabled']&&$quickDefault['relationship']['update_chance_percent']===50,'Default preset restores reference backfill and relationship chance');
$check(!$quickDefault['context']['ground_items_descriptions_only']&&!$quickDefault['context']['inventory_items_descriptions_only'],'Default restores full item context');
$check($quickDefault['context']['location_blacklist']===$presetCurrent['context']['location_blacklist']&&$quickDefault['system_routing']===$presetCurrent['system_routing']&&$quickDefault['client']===$presetCurrent['client'],'Quickstart global presets retain blacklists, routes and unrelated controls');
$namedDefault = \LorkhanServer\Application\GlobalSettingsPreset::defaults();
$legacyPreset = $namedDefault;
unset($legacyPreset['settings']['context']['prompt_timestamp']);
unset($legacyPreset['settings']['context']['ground_items_descriptions_only']);
unset($legacyPreset['settings']['context']['inventory_items_descriptions_only']);
$legacyApplied = \LorkhanServer\Application\GlobalSettingsPreset::apply($legacyPreset, $presetCurrent, $presetSummary, $presetEmbedding);
$check($legacyApplied['settings']['context']['prompt_timestamp'] === false, 'older named presets normalize temporal headings to disabled');
$check($legacyApplied['settings']['context']['ground_items_descriptions_only'] === false, 'older named presets leave ground description filtering disabled');
$check($legacyApplied['settings']['context']['inventory_items_descriptions_only'] === false, 'older named presets leave inventory description filtering disabled');
$legacyGlobal = $presetCurrent;
unset($legacyGlobal['context']['prompt_timestamp']);
unset($legacyGlobal['context']['ground_items_descriptions_only']);
unset($legacyGlobal['context']['inventory_items_descriptions_only']);
$check(\LorkhanServer\Application\EffectiveSettingsResolver::validateGlobalSettings($legacyGlobal)['context']['inventory_items_descriptions_only'] === false,
    'older global documents leave inventory description filtering disabled');
$check(\LorkhanServer\Application\EffectiveSettingsResolver::validateGlobalSettings($legacyGlobal)['context']['ground_items_descriptions_only'] === false,
    'older global documents leave ground description filtering disabled');
$check(\LorkhanServer\Application\EffectiveSettingsResolver::validateGlobalSettings($legacyGlobal)['context']['prompt_timestamp'] === false,
    'older global documents retain disabled temporal headings');
$legacyGlobal['context']['prompt_timestamp'] = 'true';
try {
    \LorkhanServer\Application\EffectiveSettingsResolver::validateGlobalSettings($legacyGlobal);
    $check(false, 'temporal heading setting rejects string booleans');
} catch (InvalidArgumentException) { $check(true, 'temporal heading setting rejects string booleans'); }
$presetApplied = \LorkhanServer\Application\GlobalSettingsPreset::apply($namedDefault, $presetCurrent, $presetSummary, $presetEmbedding);
$check($presetApplied['settings']['context']['location_blacklist'] === []
    && $presetApplied['settings']['system_routing'] === $presetCurrent['system_routing']
    && $presetApplied['settings']['client']['presentation'] === $presetCurrent['client']['presentation']
    && $presetApplied['summary']['provider_configuration_id'] === $presetSummary['provider_configuration_id']
    && $presetApplied['embedding']['endpoint'] === $presetEmbedding['endpoint'], 'named preset replaces lists but preserves routing and hidden settings');
$namedDefault['settings']['system_routing'] = ['relationship_configuration_id'=>''];
try {
    \LorkhanServer\Application\GlobalSettingsPreset::apply($namedDefault, $presetCurrent, $presetSummary, $presetEmbedding);
    $check(false, 'named preset rejects connector fields');
} catch (InvalidArgumentException) { $check(true, 'named preset rejects connector fields'); }

// Dashboard worker status must not mistake stale PIDs or an idle systemd timer for a running worker.
$workerFixture = sys_get_temp_dir().'/lorkhan-worker-status-'.bin2hex(random_bytes(4));
mkdir($workerFixture); mkdir($workerFixture.'/1'); mkdir($workerFixture.'/123');
file_put_contents($workerFixture.'/1/comm', "init\n");
$workerStatus = static fn(?callable $query = null): string => \LorkhanServer\Infrastructure\BackgroundWorkerStatus::read($workerFixture, $workerFixture, $query);
$check($workerStatus()==='Stopped', 'missing worker PID is stopped');
file_put_contents($workerFixture.'/lorkhanserver-worker.pid', "123\n");
file_put_contents($workerFixture.'/123/cmdline', "bash\0/usr/local/libexec/lorkhanserver-worker-loop\0");
$check($workerStatus()==='Running', 'worker status verifies the exact supervisor process');
file_put_contents($workerFixture.'/123/cmdline', "bash\0/unrelated-worker\0");
$check($workerStatus()==='Stopped', 'reused PID cannot report running');
file_put_contents($workerFixture.'/lorkhanserver-worker.pid', '../123');
$check($workerStatus()==='Unavailable', 'invalid PID is rejected');
file_put_contents($workerFixture.'/1/comm', "systemd\n");
foreach ([['activating','active','Running'],['inactive','active','Waiting (timer active)'],['failed','active','Failed'],['inactive','inactive','Stopped']] as [$serviceState,$timerState,$expected]) {
    $query=static fn():array=>['lorkhanserver-worker.service'=>['LoadState'=>'loaded','ActiveState'=>$serviceState],
        'lorkhanserver-worker.timer'=>['LoadState'=>'loaded','ActiveState'=>$timerState]];
    $check($workerStatus($query)===$expected, 'systemd worker status '.$expected);
}
$check($workerStatus(static fn()=>null)==='Unavailable', 'failed systemd observation stays unknown');
unlink($workerFixture.'/123/cmdline'); unlink($workerFixture.'/1/comm'); unlink($workerFixture.'/lorkhanserver-worker.pid');
rmdir($workerFixture.'/123'); rmdir($workerFixture.'/1'); rmdir($workerFixture);

$omniRoot=sys_get_temp_dir().'/lorkhan-omni-languages-'.bin2hex(random_bytes(4));
$check(\LorkhanServer\Application\OmniVoiceLanguages::available($omniRoot)===[], 'missing OmniVoice catalogue stays empty');
mkdir($omniRoot,0700);
try {
    file_put_contents($omniRoot.'/en.json',json_encode(['id'=>'en','display_name'=>'English']));
    file_put_contents($omniRoot.'/cs.json',json_encode(['omnivoice_language'=>'Czech']));
    file_put_contents($omniRoot.'/bad.json','{');
    file_put_contents($omniRoot.'/template.json',json_encode(['id'=>'fr','display_name'=>'REPLACE THIS']));
    file_put_contents($omniRoot.'/invalid.json',json_encode(['id'=>'../private','display_name'=>'Private']));
    file_put_contents($omniRoot.'/oversized.json',str_repeat(' ',65537));
    $check(\LorkhanServer\Application\OmniVoiceLanguages::available($omniRoot)===['cs'=>'Czech (cs)','en'=>'English (en)'],
        'OmniVoice profiles are labelled, sorted and reject malformed, placeholder, invalid and oversized entries');
} finally {
    foreach(['en','cs','bad','template','invalid','oversized']as$name)unlink($omniRoot.'/'.$name.'.json');
    rmdir($omniRoot);
}

$diaryCacheRoot=sys_get_temp_dir().'/lorkhan-diary-cache-'.bin2hex(random_bytes(6));
$diaryCache=new \LorkhanServer\Infrastructure\DiaryAudioCache($diaryCacheRoot);
$diaryCalls=0;
$generateDiary=static function()use(&$diaryCalls):array{$diaryCalls++;return ['bytes'=>'fixture audio','mime_type'=>'audio/wav'];};
try {
    $first=$diaryCache->remember('author-one-entry-one',$generateDiary);
    $second=$diaryCache->remember('author-one-entry-one',$generateDiary);
    $changed=$diaryCache->remember('author-one-entry-changed',$generateDiary);
    $check(!$first['cached']&&$second['cached']&&!$changed['cached']&&$diaryCalls===2,'diary cache reuses identical speech and invalidates changed inputs');
    $lock=fopen($diaryCacheRoot.'/cache.lock','c');flock($lock,LOCK_EX);
    try{$busy=false;try{$diaryCache->remember('busy',$generateDiary);}catch(RuntimeException $e){$busy=$e->getMessage()==='diary_audio_busy';}
        $check($busy&&$diaryCalls===2,'diary cache refuses concurrent generation without calling the provider');}
    finally{flock($lock,LOCK_UN);fclose($lock);}
    $bad=false;try{$diaryCache->remember('bad',static fn()=>['bytes'=>'unsafe','mime_type'=>'text/html']);}catch(RuntimeException){$bad=true;}
    $check($bad&&!is_file($diaryCacheRoot.'/'.hash('sha256','bad').'.audio'),'diary cache rejects non-audio output');
    for($i=0;$i<66;$i++)$diaryCache->remember('bounded-'.$i,$generateDiary);
    $check(count(glob($diaryCacheRoot.'/*.audio'))===64,'diary cache retains at most 64 entries');
} finally {foreach(glob($diaryCacheRoot.'/*')?:[]as$file)unlink($file);rmdir($diaryCacheRoot);}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} server checks failed\n");
    exit(1);
}
echo "{$checks} server checks passed\n";
