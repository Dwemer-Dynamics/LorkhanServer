<?php
declare(strict_types=1);

namespace LorkhanServer\Http;

use LorkhanServer\Application\MorrowindVoiceCatalog;
use LorkhanServer\Application\EffectiveSettingsResolver;
use LorkhanServer\Application\MemoryEmbeddingPolicy;
use LorkhanServer\Application\MiniMeEmbeddingProvider;
use LorkhanServer\Application\NeverCancelledToken;
use LorkhanServer\Application\PromptAssembler;
use LorkhanServer\Application\Provider;
use LorkhanServer\Application\ProviderFactory;
use LorkhanServer\Application\RechatCoordinator;
use LorkhanServer\Application\SettingsCatalog;
use LorkhanServer\Application\SpeechProvider;
use LorkhanServer\Application\SpeechToTextProvider;
use LorkhanServer\Application\TranslationPolicy;
use LorkhanServer\Infrastructure\ManagementRepository;
use LorkhanServer\Infrastructure\MediaStore;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\Repository;
use LorkhanServer\Infrastructure\Uuid;
use LorkhanServer\Infrastructure\Logger;
use LorkhanServer\Protocol\ValidationException;
use LorkhanServer\Protocol\Validator;
use LorkhanServer\Security\RequestMac;
use DomainException;
use OutOfBoundsException;
use Throwable;
use UnexpectedValueException;

final class Router
{
    private ?string $authenticatedInstallation=null;
    public function __construct(
        private readonly Repository $repository,
        private readonly Validator $validator,
        private readonly Provider $provider,
        private readonly string $pairingTokenHash,
        private readonly string $basePath = '/LorkhanServer/api/v1',
        private readonly int $maxJsonBytes = 2_097_152,
        private readonly int $eventLimit = 100,
        private readonly int $rateLimitRequests = 120,
        private readonly int $rateLimitWindowSeconds = 60,
        private readonly ?MediaStore $mediaStore = null,
        private readonly ?SpeechProvider $speechProvider = null,
        private readonly ?ProviderAttemptRepository $providerAttempts = null,
        private readonly int $eventMaxWaitSeconds = 15,
        private readonly ?ManagementRepository $management = null,
        private readonly ?ProductRepository $products = null,
        private readonly ?PromptAssembler $promptAssembler = null,
        private readonly ?MorrowindVoiceCatalog $morrowindVoices = null,
        private readonly ?RechatCoordinator $rechatCoordinator = null,
        private readonly ?SpeechToTextProvider $sttProviderOverride = null,
        private readonly array $providerConfig = [],
    ) {
        if (($products === null) !== ($promptAssembler === null)) throw new \InvalidArgumentException('Incomplete prompt composition.');
    }

    public function dispatch(Request $request): Response
    {
        $candidate=$request->header('X-LORKHAN-Request-Id')??$request->header('Idempotency-Key');
        if(str_contains(strtolower((string)$request->header('Content-Type')),'application/json')){try{$raw=json_decode($request->body,true,8,JSON_THROW_ON_ERROR);if(is_array($raw)&&is_string($raw['request_id']??null))$candidate=$raw['request_id'];}catch(Throwable){}}
        $correlation=is_string($candidate)&&$this->uuid($candidate)?$candidate:Uuid::v4();
        try {
            $path = $this->path($request->path);
            if ($request->method === 'GET' && $path === '/health') {
                return Response::json(200, ['schema' => 'lorkhan.health.v1']);
            }
            $this->authenticatedInstallation=null;
            $this->authenticate($request);
            if (!$this->repository->consumeRateLimit($this->pairingTokenHash . '|' . $this->rateLimitRoute($path),
                $this->rateLimitRequests, $this->rateLimitWindowSeconds)) {
                throw new ApiException(429, 'rate_limited', 'Request rate exceeded.', true, 1000);
            }
            if ($request->method === 'POST' && $path === '/sessions') return $this->createSession($request);
            if ($request->method === 'DELETE' && preg_match('#^/sessions/([0-9a-f-]{36})$#D', $path, $m)) return $this->endSession($request, $m[1]);
            if ($request->method === 'POST' && $path === '/turns') return $this->createTurn($request);
            if ($request->method === 'POST' && $path === '/gamedata') return $this->gameData($request);
            if ($request->method === 'POST' && $path === '/controls/query') return $this->controlsQuery($request);
            if ($request->method === 'POST' && $path === '/controls/select') return $this->controlsSelect($request);
            if ($request->method === 'POST' && $path === '/diary-books/query') return $this->diaryBookQuery($request);
            if ($request->method === 'POST' && $path === '/diary-book-results') return $this->diaryBookResult($request);
            if ($request->method === 'POST' && $path === '/debug-commands/query') return $this->debugCommandQuery($request);
            if ($request->method === 'POST' && $path === '/debug-command-results') return $this->debugCommandResult($request);
            if ($request->method === 'GET' && $path === '/events') return $this->events($request);
            if ($request->method === 'GET' && preg_match('#^/media/([0-9a-f-]{36})$#D', $path, $m)) return $this->media($m[1]);
            if ($request->method === 'POST' && $path === '/interruptions') return $this->interrupt($request);
            if ($request->method === 'POST' && $path === '/action-results') return $this->actionResult($request);
            if ($request->method === 'POST' && $path === '/stt') return $this->stt($request);
            if ($request->method === 'POST' && $path === '/dialogue-delivery-results') return $this->deliveryResult($request);
            if ($request->method === 'POST' && $path === '/menu-dialogue-tts') return $this->menuDialogueTts($request);
            if ($request->method === 'POST' && $path === '/book/read-aloud') return $this->menuDialogueTts($request,true);
            if ($request->method === 'POST' && $path === '/player-autochat') return $this->playerAutochat($request);
            throw new ApiException(404, 'not_found', 'Route not found.');
        } catch (ApiException $error) {
            return $this->error($error, $correlation);
        } catch (ValidationException $error) {
            return Response::error($error->getMessage() === 'payload_too_large' ? 413 : 422, $error->getMessage(), $correlation);
        } catch (DomainException $error) {
            return Response::error(409, $this->publicCode($error->getMessage()), $correlation);
        } catch (UnexpectedValueException $error) {
            return Response::error(409, $this->publicCode($error->getMessage()), $correlation);
        } catch (OutOfBoundsException $error) {
            return Response::error(404, $this->publicCode($error->getMessage()), $correlation);
        } catch (Throwable $error) {
            $message=preg_replace('/[\r\n\t]+/',' ',trim($error->getMessage()))??'unavailable';
            error_log(sprintf('[LORKHAN] API internal_error correlation=%s method=%s path=%s exception=%s message=%s',
                $correlation,$request->method,$request->path,$error::class,substr($message,0,1000)));
            return Response::error(500, 'internal_error', $correlation, true);
        }
    }

    private function createSession(Request $request): Response
    {
        $m = $this->json($request, 'lorkhan.session.init.v1');
        $this->assertPrincipal($m['installation_id']);
        $this->requireIdempotency($request, $m['message_id']);
        return $this->repository->serializedIdempotency($m['installation_id'], $m['message_id'], '/sessions', function () use ($m): Response {
            return $this->idempotent($m['installation_id'], $m['message_id'], '/sessions', $m, function () use ($m): array {
                $sessionId = Uuid::v4();
                $beforeReplace=isset($m['loaded_save'])?fn(array $resolved)=>(new \LorkhanServer\Infrastructure\DragonBreakSnapshot($this->providerConfig))->capture($resolved):null;
                $session = $this->repository->createSession($m,$sessionId,$this->pairingTokenHash,$this->bootstrapMacKey(),$beforeReplace);
                if(isset($session['playthrough_id']))$m['playthrough_id']=$session['playthrough_id'];
                if(isset($session['profile_id']))$m['profile_id']=$session['profile_id'];
                // The installation is materialized by createSession, so the player profile can now satisfy its foreign key.
                $this->products?->ensurePlayerProfile((string)$m['installation_id'],(string)$m['created_at'],(string)$m['playthrough_id']);
                $settings=$this->clientSettings((string)$m['installation_id']);
                return [201, ['schema' => 'lorkhan.session.accepted.v1', 'message_id' => $m['message_id'],
                    'session_id' => $sessionId, 'generation' => $session['generation'],
                    'capabilities' => $session['capabilities'], 'config_revision' => $settings['revision'],
                    'client_settings'=>$settings['content'],'event_cursor' => 0]+(isset($session['character_id'])?['character_id'=>$session['character_id'],'profile_id'=>$session['profile_id'],'playthrough_id'=>$session['playthrough_id']]:[])];
            });
        });
    }

    /** Return the current typed settings revision or conservative first-run defaults. */
    private function clientSettings(string $installationId):array
    {
        $saved=$this->products?->globalSettingsForInstallation($installationId);
        if($saved!==null){
            // Upgrade older saved settings before enforcing the current client handshake contract.
            $content=EffectiveSettingsResolver::validateGlobalSettings($saved['content'])['client'];
            return['revision'=>'global-settings-r'.(int)$saved['current_revision'],'content'=>$content];}
        return['revision'=>'global-settings-default-v1','content'=>SettingsCatalog::clientDefaults()];
    }

    private function endSession(Request $request, string $sessionId): Response
    {
        if (!$this->uuid($sessionId)) throw new ApiException(404, 'not_found', 'Route not found.');
        $this->assertPrincipal($this->repository->sessionInstallation($sessionId));
        $requestId = $this->requireIdempotency($request);
        [$status, $body] = $this->repository->endSession($sessionId, $requestId);
        return Response::json($status, $body);
    }

    private function createTurn(Request $request): Response
    {
        $m = $this->json($request, 'lorkhan.turn.v1');
        $this->assertPrincipal($m['installation_id']);
        $this->requireIdempotency($request, $m['message_id']);
        return $this->repository->serializedIdempotency($m['installation_id'], $m['message_id'], '/turns', function () use ($m): Response {
            $hash = $this->semanticHash($m);
            $cached = $this->repository->idempotent($m['installation_id'], $m['message_id'], '/turns', $hash);
            if ($cached !== null) return Response::json($cached['status'], $cached['body']);

            $this->repository->assertAiEnabled($m['installation_id']);
            \LorkhanServer\Application\ExecutionModePolicy::mode($m['payload']);
            if(isset($m['payload']['director_instruction_id'])){
                $trusted=$this->repository->directorChildInput($m);
                $m['payload']['input']['text']=$trusted['instruction'];
                $m['payload']['context']['director']=['plan_id'=>$trusted['plan_id'],'scene_note'=>$trusted['scene_note']];
            }
            if(($m['payload']['execution_mode']??'standard')==='injection_log'){
                $body=['schema'=>'lorkhan.turn.accepted.v1','message_id'=>$m['message_id'],'turn_id'=>$m['turn_id'],
                    'request_id'=>$m['request_id'],'session_id'=>$m['session_id'],'generation'=>$m['generation']];
                $accepted=$this->repository->acceptTurn($m,null,null,$hash,$body,null,[]);
                $body['event_cursor']=$accepted['sequence'];
                return Response::json(202,$body);
            }
            if(($m['payload']['execution_mode']??'standard')==='director'){
                if(($m['payload']['speaker']['kind']??null)!=='player' || isset($m['payload']['action_request'])
                    || isset($m['payload']['director_instruction_id'])
                    || !in_array($m['payload']['ui_source']??null,['lorkhan_text','lorkhan_voice','lorkhan_open_mic'],true))
                    throw new DomainException('director_route_invalid');
                $scene=$this->products?->promptContext($m,gmdate('Y-m-d\TH:i:s\Z'))??[];
                $scene['_director_actions']=[];
                foreach(\LorkhanServer\Application\DirectorPolicy::actors($m['payload']) as $selector=>$actor){
                    if(!in_array($actor['kind'],['npc','creature'],true))continue;
                    $scene['_director_actions'][$selector]=$this->repository->allowedPromptActions($m['session_id'],$m['generation'],
                        array_replace($m['payload'],['target'=>$actor,'execution_mode'=>'standard','director_instruction_id'=>'planning']));
                }
                $body=['schema'=>'lorkhan.turn.accepted.v1','message_id'=>$m['message_id'],'turn_id'=>$m['turn_id'],
                    'request_id'=>$m['request_id'],'session_id'=>$m['session_id'],'generation'=>$m['generation']];
                $accepted=$this->repository->acceptTurn($m,null,null,$hash,$body,null,[],$scene);
                $body['event_cursor']=$accepted['sequence'];
                return Response::json(202,$body);
            }
            if (($m['payload']['ui_source'] ?? null) === 'lorkhan_rechat') {
                if ($this->rechatCoordinator === null) throw new DomainException('rechat_unavailable');
                $m = $this->rechatCoordinator->resolve($m);
            }
            if($this->products!==null)$m=$this->products->enrichTurnInventory($m);
            if (($m['payload']['ui_source']??null)==='lorkhan_action_followup') {
                $results=$m['payload']['recent_action_results']??[];
                $continuation=count($results)===1?$this->repository->actionContinuation((string)$results[0]['action_id'],
                    (string)$m['session_id'],(int)$m['generation']):null;
                if($continuation===null)
                    throw new DomainException('action_followup_not_allowed');
                $m['_action_continuation']=$continuation;
                if(trim((string)($continuation['prompt']??''))!=='')
                    $m['payload']['input']['text']=(string)$continuation['prompt'];
            }

            if($this->products!==null&&is_array($m['payload']['target']??null)
                &&$this->repository->conversationCooldownActive($m['installation_id'],$m['playthrough_id'],$m['payload']['target'],300)){
                $targetSettings=$this->products->effectiveSettingsForActor($m['installation_id'],$m['playthrough_id'],$m['payload']['target']);
                $cooldown=(int)($targetSettings['settings']['behavior']['end_conversation_cooldown_seconds']??60);
                if($this->repository->conversationCooldownActive($m['installation_id'],$m['playthrough_id'],$m['payload']['target'],$cooldown))
                    throw new DomainException('conversation_cooldown');
            }

            $dynamicPlan=$this->products?->dynamicOghma()->plan($m)??[];
            $knowledgeTurn=$m+['_dynamic_oghma_plan'=>$dynamicPlan];
            $directAction = $m['payload']['action_request'] ?? null;
            $providerInput = $directAction === null ? $m : null;
            if($providerInput!==null&&isset($trusted['authored_response']))$providerInput['_director_response']=$trusted['authored_response'];
            if ($providerInput !== null) {
                $source=$m['payload']['ui_source']??null;
                $rechatActions=$source==='lorkhan_rechat'
                    &&($m['payload']['context']['rechat']['allow_actions']??false)===true;
                $providerInput['_allowed_action_definitions'] = in_array($m['payload']['execution_mode']??'standard',['injection_log','injection_chat'],true)
                    ||($source==='lorkhan_rechat'&&!$rechatActions)
                    ||in_array($source,['lorkhan_auto_greeting','lorkhan_auto_boredom','lorkhan_auto_combat_bark','lorkhan_rpg_event','lorkhan_quest_event'],true)
                    ||str_starts_with((string)$source,'lorkhan_narrator_')
                    ||($source==='lorkhan_action_followup'&&!($m['_action_continuation']['allow_action']??false))
                    ?[]:$this->repository->allowedPromptActions($m['session_id'],$m['generation'],$m['payload']);
                if (($m['payload']['execution_mode'] ?? 'standard') === 'narrator') {
                    $providerInput['_narrator_action_executors']=$this->repository->narratorExecutors($m['session_id'],$m['generation'],$m['payload']);
                    $providerInput['_allowed_action_definitions']=[];
                } elseif (($m['payload']['target']['kind'] ?? null) === 'narrator') {
                    $providerInput['_allowed_action_definitions']=[];
                }
            }
            $assembled = null;
            if ($directAction === null && $this->products !== null && $this->promptAssembler !== null) {
                $target=(array)$m['payload']['target'];
                $resolvedVoice=$this->morrowindVoices?->resolve($target,(array)$m['payload']['context']);
                if($resolvedVoice!==null)$resolvedVoice=$this->products->preferExactProviderActorVoice(
                        (string)$m['installation_id'],$target,$resolvedVoice);
                if(in_array($target['kind']??null,['creature','npc'],true)){
                    $this->repository->session((string)$m['session_id'],(int)$m['generation']);
                    $this->products->ensureMorrowindActorProfile($m,$resolvedVoice,gmdate('Y-m-d\TH:i:s\Z'));}
                $oghmaExtraction=isset($trusted['authored_response'])?[]:$this->oghmaExtraction($knowledgeTurn);$semanticMemory=isset($trusted['authored_response'])?[]:$this->semanticMemory($m);
                $selection = $this->products->promptContext($knowledgeTurn,gmdate('Y-m-d\TH:i:s\Z'),$oghmaExtraction,$semanticMemory);
                $providerInput['_selected_profile_id']=$selection['selected_profile_id'];
                if(is_array($selection['player_profile']??null))$providerInput['_player_profile']=$selection['player_profile'];
                if(is_array($selection['narrator_profile']??null))$providerInput['_narrator_profile']=$selection['narrator_profile'];
                if(is_array($selection['narrator_event_prompts']??null))$providerInput['_narrator_event_prompts']=$selection['narrator_event_prompts'];
                if(is_array($selection['nearby_actor_profiles']??null)&&$selection['nearby_actor_profiles']!==[])$providerInput['_nearby_actor_profiles']=$selection['nearby_actor_profiles'];
                if(is_array($selection['power_observations']??null)&&$selection['power_observations']!==[])$providerInput['_power_observations']=$selection['power_observations'];
                if(is_array($selection['item_descriptions']??null)&&$selection['item_descriptions']!==[])$providerInput['_item_descriptions']=$selection['item_descriptions'];
                if(is_array($selection['scene_classification']??null))$providerInput['_scene_classification']=$selection['scene_classification'];
                $assembled = $this->promptAssembler->assemble($providerInput, $selection);
                $providerInput['_prompt'] = $assembled['provider_input'];
                $providerConfiguration=$this->products->providerContext($m);
                if($providerConfiguration!==null)$providerInput['_provider_configuration']=$providerConfiguration;
                $fallbackConfiguration=$this->products->fallbackProviderContext($m,$providerConfiguration['configuration_id']??null);
                if($fallbackConfiguration!==null)$providerInput['_fallback_provider_configuration']=$fallbackConfiguration;
                $translation=$this->products->translationPolicyForInstallation((string)$m['installation_id']);
                $providerInput['_translation_policy']=['configuration_id'=>$translation['configuration_id'],
                    'revision'=>(int)$translation['current_revision'],'content'=>TranslationPolicy::validate($translation['content'])];
            }
            $body = ['schema' => 'lorkhan.turn.accepted.v1', 'message_id' => $m['message_id'], 'turn_id' => $m['turn_id'],
                'request_id' => $m['request_id'], 'session_id' => $m['session_id'], 'generation' => $m['generation']];
            $accepted = $this->repository->acceptTurn($m, $providerInput, $assembled['trace'] ?? null, $hash, $body, $directAction, $dynamicPlan);
            $body['event_cursor']=$accepted['sequence'];
            return Response::json(202, $body);
        });
    }

    /** Persist one authenticated game observation without starting a model turn. */
    private function gameData(Request $request): Response
    {
        $message = $this->json($request, 'lorkhan.gamedata.v1');
        $this->assertPrincipal((string) $message['installation_id']);
        $this->requireIdempotency($request, (string) $message['request_id']);
        return $this->repository->serializedIdempotency((string) $message['installation_id'],
            (string) $message['request_id'], '/gamedata', function () use ($message): Response {
                return $this->idempotent((string) $message['installation_id'], (string) $message['request_id'],
                    '/gamedata', $message, function () use ($message): array {
                        $profileId=$message['type']==='actor_profile'?$this->materializeActorProfile($message):null;
                        $this->repository->acceptGameData($message);
                        if($message['type']==='automatic_diary')$this->products?->enqueueAutomaticDiaries($message);
                        if($profileId!==null)$this->products?->maybeEnqueueAutomaticProfileBackfill(
                            $profileId,(string)$message['playthrough_id']);
                        $extra=[];
                        if(in_array($message['type'],['bored_event','quest_event'],true)){
                            if($this->products===null)throw new ApiException(503,'provider_unavailable','Profile policy unavailable.',true);
                            $effective=$this->products->effectiveSettingsForActor((string)$message['installation_id'],
                                (string)$message['playthrough_id'],$message['payload']['responder']);
                            // One stable roll per persisted opportunity, before client-side narrator routing.
                            $roll=hexdec(substr(hash('sha256',(string)$message['request_id']),0,6))%100;
                            $policy=$effective['settings'][$message['type']==='quest_event'?'quest_comments':'bored_event'];
                            $extra['comment_requested']=($message['type']!=='quest_event'||$policy['enabled'])&&$roll<$policy['chance_percent'];
                        }
                        if($message['type']==='rpg_event'){
                            $global=$this->products?->globalSettingsForInstallation((string)$message['installation_id'])['content']??[];
                            $policy=$global['rpg_comments']??SettingsCatalog::globalDefaults()['rpg_comments'];
                            if(isset($message['payload']['responder'])&&$this->products!==null){
                                $effective=$this->products->effectiveSettingsForActor((string)$message['installation_id'],
                                    (string)$message['playthrough_id'],$message['payload']['responder']);
                                $policy=$effective['settings']['rpg_comments'];
                            }
                            $roll=hexdec(substr(hash('sha256',(string)$message['request_id']),0,6))%100;
                            $extra['comment_requested']=in_array($message['payload']['kind'],$policy['events'],true)&&$roll<$policy['chance_percent'];
                        }
                        return [202, $extra+['schema'=>'lorkhan.gamedata.accepted.v1',
                            'request_id'=>$message['request_id'],'session_id'=>$message['session_id'],
                            'generation'=>$message['generation'],'type'=>$message['type'],'duplicate'=>false]];
                    });
            });
    }

    /** Create and bind the NPC profile represented by one current-session OpenMW snapshot. */
    private function materializeActorProfile(array $message):string
    {
        if($this->products===null||$this->morrowindVoices===null)
            throw new ApiException(503,'provider_unavailable','Actor profiles unavailable.',true);
        $session=$this->repository->session((string)$message['session_id'],(int)$message['generation']);
        if(!hash_equals((string)$session['installation_id'],(string)$message['installation_id'])
            ||!hash_equals((string)$session['playthrough_id'],(string)$message['playthrough_id']))
            throw new ApiException(409,'stale_generation','The session generation is stale.',true);
        $payload=(array)$message['payload'];$actor=(array)$payload['actor'];
        $context=['targetState'=>['identity'=>['race'=>$payload['race'],'class'=>$payload['class'],
            'gender'=>$payload['gender'],'is_male'=>$payload['gender']==='male'],
            'stats'=>['level'=>$payload['level']],'disposition'=>$payload['disposition'],
            'factions'=>array_map(static fn(string$faction):array=>['id'=>$faction],$payload['factions'])]];
        $resolved=$this->morrowindVoices->resolve($actor,$context);
        if(isset($payload['record_provenance']))$context['targetState']['recordProvenance']=$payload['record_provenance'];
        if($resolved!==null)$resolved=$this->products->preferExactProviderActorVoice((string)$message['installation_id'],$actor,$resolved);
        return$this->products->ensureMorrowindActorProfile([
            'installation_id'=>$message['installation_id'],'profile_id'=>$session['profile_id'],
            'playthrough_id'=>$message['playthrough_id'],'session_id'=>$message['session_id'],
            'generation'=>$message['generation'],'payload'=>['target'=>$actor,'context'=>$context],
        ],$resolved,(string)$message['observed_at']);
    }

    /** Ground topics locally first, then make one guarded connector fallback for unresolved explicit requests. */
    private function oghmaExtraction(array $turn):array
    {
        if($this->products===null)return['status'=>'unavailable','topics'=>[]];
        $grounded=$this->products->groundedOghmaExtraction($turn);
        if(($grounded['topics']??[])!==[])return[
            'status'=>'grounded','topics'=>$grounded['topics'],'matches'=>$grounded['matches']??[],
             'rejected'=>$grounded['rejected']??[],'tag_decisions'=>$grounded['tag_decisions']??[],
            'context_fallback'=>$grounded['context_fallback']??['eligible'=>false,'attempted'=>false,'used'=>false],
             'request_eligible'=>($grounded['request_eligible']??false)===true,'fallback_eligible'=>false,
        ];
        $runtime=$grounded['runtime']??$this->products->oghmaRuntime($turn);$connector=$runtime['connector']??null;
        $groundedStatus=(string)($grounded['status']??'no_match');
        $base=['status'=>$groundedStatus,'topics'=>[],'matches'=>[],'rejected'=>$grounded['rejected']??[],
             'tag_decisions'=>$grounded['tag_decisions']??[],'request_eligible'=>($grounded['request_eligible']??false)===true,
            'context_fallback'=>$grounded['context_fallback']??['eligible'=>false,'attempted'=>false,'used'=>false],
            'fallback_eligible'=>($grounded['fallback_eligible']??false)===true];
        if(in_array($groundedStatus,['disabled','ineligible','unavailable'],true))return$base;
        if(!$base['fallback_eligible'])return$base;
        if(($runtime['status']??null)!=='ready'||!is_array($connector))return array_merge($base,
            ['status'=>'fallback_'.(string)($runtime['status']??'unavailable')]);
        $content=is_array($connector['content']??null)?$connector['content']:[];$attemptId=Uuid::v4();$context=(string)($runtime['context']??'');
        $result=array_merge($base,['status'=>'fallback_failed','configuration_id'=>$connector['configuration_id']??null,
            'revision'=>$connector['revision']??null]);
        try{
            $this->providerAttempts?->start($attemptId,'llm','oghma-extractor','extract_oghma_topics',1,
                $turn['request_id']??null,$turn['turn_id']??null,model:is_string($content['model']??null)?$content['model']:null,
                configRevision:'r'.(int)($connector['revision']??0),inputBytes:strlen($context),
                metadata:['configuration_id'=>$connector['configuration_id'],'topic_limit'=>(int)($runtime['settings']['topic_count']??1)]);
            $suggestions=ProviderFactory::oghmaTopicExtractorForSlot($this->providerConfig,$connector,
                (int)($runtime['settings']['extractor_timeout_ms']??1500))->extract(
                $context,(int)($runtime['settings']['topic_count']??1),new NeverCancelledToken());
            $topics=$this->products->resolveOghmaSuggestions($turn,$suggestions,(int)($runtime['settings']['topic_count']??1));
            $encoded=json_encode($topics,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$this->providerAttempts?->finish($attemptId,'succeeded',strlen($encoded));
            return array_merge($result,['status'=>$topics===[]?'fallback_unresolved':'fallback_succeeded',
                'topics'=>$topics,'suggested_topics'=>$suggestions]);
        }catch(Throwable){
            try{$this->providerAttempts?->finish($attemptId,'failed',errorCode:'provider_unavailable');}catch(Throwable){}
            return$result;
        }
    }

    /** Add one explicit MiniMe query signal; unavailable service falls back to local deterministic ranking. */
    private function semanticMemory(array $turn):array
    {
        if($this->products===null)return[];
        $runtime=$this->products->memoryEmbeddingRuntime((string)$turn['installation_id']);
        if(($runtime['status']??null)!=='ready'||!is_array($runtime['policy']??null))return[];
        $policy=$runtime['policy'];$content=$policy['content'];$base=[
            'status'=>'failed','policy_configuration_id'=>$policy['configuration_id'],
            'policy_revision'=>(int)$policy['current_revision'],'model'=>MemoryEmbeddingPolicy::MODEL,
        ];
        $query=MemoryEmbeddingPolicy::queryText($turn);$attempt=Uuid::v4();
        try{
            $this->providerAttempts?->start($attempt,'embedding','minime','query_memory',1,
                $turn['request_id']??null,$turn['turn_id']??null,model:MemoryEmbeddingPolicy::MODEL,
                configRevision:'r'.(int)$policy['current_revision'],inputBytes:strlen($query),
                metadata:['policy_configuration_id'=>$policy['configuration_id']]);
            $provider=new MiniMeEmbeddingProvider($content['endpoint'],$content['timeout_ms']);
            $embedding=$provider->embed($query,new NeverCancelledToken());
            $encoded=json_encode($embedding,JSON_THROW_ON_ERROR);$this->providerAttempts?->finish($attempt,'succeeded',strlen($encoded));
            return array_replace($base,['status'=>'succeeded','embedding'=>$embedding]);
        }catch(Throwable){
            try{$this->providerAttempts?->finish($attempt,'failed',errorCode:'provider_unavailable');}catch(Throwable){}
            return$base;
        }
    }

    private function events(Request $request): Response
    {
        $session = $request->query['session_id'] ?? '';
        $generation = filter_var($request->query['generation'] ?? null, FILTER_VALIDATE_INT);
        $after = filter_var($request->query['after'] ?? '0', FILTER_VALIDATE_INT);
        $wait = filter_var($request->query['wait_ms'] ?? '0', FILTER_VALIDATE_INT);
        if (!$this->uuid($session) || $generation === false || $generation < 0 || $after === false || $after < 0
            || $wait === false || $wait < 0 || $wait > $this->eventMaxWaitSeconds * 1000) {
            throw new ApiException(422, 'invalid_schema', 'Invalid event cursor.');
        }
        $this->assertPrincipal($this->repository->sessionInstallation($session));
        $deadline = microtime(true) + ($wait / 1000);
        do {
            $events = $this->repository->events($session, $generation, $after, $this->eventLimit);
            if ($events !== [] || microtime(true) >= $deadline) break;
            usleep((int) min(100_000, max(1_000, ($deadline - microtime(true)) * 1_000_000)));
        } while (true);
        $next = $events === [] ? $after : $events[array_key_last($events)]['sequence'];
        $body = ['schema' => 'lorkhan.events.v1', 'session_id' => $session,
            'generation' => $generation, 'next_after' => $next, 'events' => $events,'autonomy'=>[]];
        // Log the actual native wire response, never empty polls or a claimed playback acknowledgement.
        if ($events !== []) Logger::write('output_to_plugin.log', json_encode(\LorkhanServer\Security\Redactor::value($body),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\r\n");
        return Response::json(200, $body);
    }

    private function controlsQuery(Request $request): Response
    {
        if($this->products===null)throw new ApiException(503,'provider_unavailable','Controls unavailable.',true);
        $m=$this->json($request,'lorkhan.controls.query.v1');
        $session=$this->repository->session($m['session_id'],$m['generation']);
        $this->assertPrincipal((string)$session['installation_id']);
        return Response::json(200,$this->controlsBody($m,$session));
    }

    private function controlsSelect(Request $request): Response
    {
        if($this->products===null)throw new ApiException(503,'provider_unavailable','Controls unavailable.',true);
        $m=$this->json($request,'lorkhan.controls.select.v1');
        $session=$this->repository->session($m['session_id'],$m['generation']);
        $this->assertPrincipal((string)$session['installation_id']);
        $this->requireIdempotency($request,$m['message_id']);
        return $this->repository->serializedIdempotency((string)$session['installation_id'],$m['message_id'],'/controls/select',function()use($m,$session):Response{
            $hash=$this->semanticHash($m);$cached=$this->repository->idempotent((string)$session['installation_id'],$m['message_id'],'/controls/select',$hash);
            if($cached!==null)return Response::json($cached['status'],$cached['body']);
            if($m['kind']==='setting'){
                if($m['selection_id']!==null||$m['selection_key']!==null||!is_array($m['setting']??null))throw new ApiException(422,'invalid_schema','The selected setting is invalid.');
                try{$this->products->selectInGameSetting($session,$m['target'],$m['setting'],$m['created_at']);}
                catch(\InvalidArgumentException){throw new ApiException(422,'invalid_schema','The selected setting is unavailable or invalid.');}
            }elseif($m['kind']==='model_slot'){
                if($m['selection_id']!==null||!is_string($m['selection_key']))throw new ApiException(422,'invalid_schema','The selected model slot is invalid.');
                $this->products->selectModelSlot($session,$m['selection_key'],$m['created_at']);
            }elseif($m['selection_key']!==null)throw new ApiException(422,'invalid_schema','The selected control is invalid.');
            elseif($m['kind']==='actor_profile')$this->products->bindActorProfile($session,$m['target'],$m['selection_id'],$m['created_at']);
            elseif($m['kind']==='profile_generate')$this->products->enqueueBoundProfileGeneration($session,$m['target'],(string)$m['selection_id']);
            else{$narrator=$this->products->narratorProfileForInstallation((string)$session['installation_id']);
                if($narrator===null||!is_string($m['selection_id'])||!hash_equals((string)$narrator['profile_id'],$m['selection_id']))
                    throw new ApiException(422,'invalid_schema','The selected narrator profile is unavailable.');
                $this->products->maybeEnqueueDynamicProfileEvolution($m['selection_id'],(string)$session['playthrough_id'],(string)$session['session_id'],true);}
            $updated=$this->repository->session($m['session_id'],$m['generation']);
            $body=$this->controlsBody($m,$updated);
            $this->repository->remember((string)$session['installation_id'],$m['message_id'],'/controls/select',$hash,200,$body);
            return Response::json(200,$body);
        });
    }

    private function controlsBody(array $request,array $session):array
    {
        $controls=$this->products?->sessionControls($session,$request['target']);
        if($controls===null)throw new ApiException(503,'provider_unavailable','Controls unavailable.',true);
        if(($request['include_settings_editor']??false)!==true&&($request['kind']??'')!=='setting')unset($controls['settings_editor']);
        return ['schema'=>'lorkhan.controls.v1','message_id'=>$request['message_id'],'request_id'=>$request['request_id'],
            'session_id'=>$request['session_id'],'generation'=>$request['generation'],'target'=>$request['target']]+$controls;
    }

    /** Poll a capability-gated diary snapshot using the authenticated active session. */
    private function diaryBookQuery(Request $request): Response
    {
        $m=$this->json($request,'lorkhan.diary-book.query.v1');
        $this->assertPrincipal($this->repository->sessionInstallation($m['session_id']));
        $book=$this->repository->claimDiaryBook($m);
        return Response::json(200,['schema'=>'lorkhan.diary-book.v1','message_id'=>$m['message_id'],
            'request_id'=>$m['request_id'],'session_id'=>$m['session_id'],'generation'=>$m['generation'],'book'=>$book]);
    }

    /** A receipt records game-side completion, not merely HTTP delivery. */
    private function diaryBookResult(Request $request): Response
    {
        $m=$this->json($request,'lorkhan.diary-book-result.v1');
        $this->assertPrincipal($this->repository->sessionInstallation($m['session_id']));
        $this->requireIdempotency($request,$m['message_id']);
        $duplicate=$this->repository->completeDiaryBook($m);
        return Response::json(200,['schema'=>'lorkhan.diary-book-result.accepted.v1','message_id'=>$m['message_id'],
            'request_id'=>$m['request_id'],'session_id'=>$m['session_id'],'generation'=>$m['generation'],
            'delivery_id'=>$m['delivery_id'],'status'=>$m['status'],'duplicate'=>$duplicate]);
    }

    /** Return at most one current-generation operator debug command. */
    private function debugCommandQuery(Request $request):Response
    {
        $m=$this->json($request,'lorkhan.debug-command.query.v1');
        $this->assertPrincipal($this->repository->sessionInstallation($m['session_id']));
        $command=$this->repository->claimDebugCommand($m);
        return Response::json(200,['schema'=>'lorkhan.debug-command.v1','message_id'=>$m['message_id'],
            'request_id'=>$m['request_id'],'session_id'=>$m['session_id'],'generation'=>$m['generation'],'command'=>$command]);
    }

    /** Accept the terminal observed result of one claimed operator debug command. */
    private function debugCommandResult(Request $request):Response
    {
        $m=$this->json($request,'lorkhan.debug-command-result.v1');
        $this->assertPrincipal($this->repository->sessionInstallation($m['session_id']));
        $this->requireIdempotency($request,$m['message_id']);
        $duplicate=$this->repository->completeDebugCommand($m);
        return Response::json(200,['schema'=>'lorkhan.debug-command-result.accepted.v1','message_id'=>$m['message_id'],
            'request_id'=>$m['request_id'],'command_id'=>$m['command_id'],'session_id'=>$m['session_id'],
            'generation'=>$m['generation'],'status'=>$m['status'],'duplicate'=>$duplicate]);
    }

    private function media(string $mediaId): Response
    {
        if (!$this->uuid($mediaId) || $this->mediaStore === null) throw new ApiException(404, 'media_unavailable', 'Media unavailable.');
        try {
            $media = $this->repository->media($mediaId);
            if($this->authenticatedInstallation!==null){if(!hash_equals($this->authenticatedInstallation,(string)$media['installation_id']))throw new ApiException(404,'media_unavailable','Media unavailable.');}
            elseif (!hash_equals((string) $media['token_fingerprint'], $this->pairingTokenHash)) {
                throw new ApiException(404, 'media_unavailable', 'Media unavailable.');
            }
            $bytes = $this->mediaStore->read($mediaId, (int) $media['byte_count'], (string) $media['sha256']);
        } catch (Throwable) {
            throw new ApiException(404, 'media_unavailable', 'Media unavailable.');
        }
        return new Response(200, $bytes, [
            'Content-Type' => (string) $media['mime_type'],
            'Content-Length' => (string) strlen($bytes),
            'Content-Digest' => 'sha-256=:' . base64_encode(hex2bin((string) $media['sha256'])),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function synthesize(array $message, array $capabilities, string $text): ?array
    {
        if (!in_array('speech.say', $capabilities, true) || $this->mediaStore === null || $this->speechProvider === null) return null;
        $cancellation = new \LorkhanServer\Application\NeverCancelledToken();
        $attemptId = Uuid::v4();
        try {
            $this->providerAttempts?->start($attemptId, 'tts', 'mock', 'synthesize', 1, $message['request_id'], $message['turn_id'],
                inputBytes: strlen($text), metadata: ['mode' => 'deterministic_mock']);
            $result = $this->speechProvider->synthesize($text, $cancellation);
            $mediaId = Uuid::v4();
            $sha = $this->mediaStore->put($mediaId, $result['bytes'], $result['codec'], $result['mime_type']);
            $this->providerAttempts?->finish($attemptId, 'succeeded', strlen($result['bytes']));
            return ['media_id' => $mediaId, 'sha256' => $sha, 'bytes' => strlen($result['bytes']), 'codec' => $result['codec'],
                'mime_type' => $result['mime_type'], 'duration_ms' => $result['duration_ms'],
                'expires_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z')];
        } catch (Throwable $error) {
            try { $this->providerAttempts?->finish($attemptId, 'failed', errorCode: 'provider_unavailable'); } catch (Throwable) {}
            throw $error;
        }
    }

    /** Synthesize one regular Morrowind dialogue response through the actor's normal voice route. */
    private function menuDialogueTts(Request $request,bool $book=false): Response
    {
        if($this->mediaStore===null)throw new ApiException(503,'provider_unavailable','Menu dialogue TTS unavailable.',true,1000);
        $message=$this->json($request,$book?'lorkhan.book.read-aloud.v1':'lorkhan.menu-dialogue-tts.v1');
        $route=$book?'/book/read-aloud':'/menu-dialogue-tts';
        $session=$this->repository->session((string)$message['session_id'],(int)$message['generation']);
        $installation=(string)$session['installation_id'];$this->assertPrincipal($installation);
        $this->requireIdempotency($request,$message['message_id']);
        if(!in_array('speech.say',(array)$session['capabilities'],true))
            throw new ApiException(503,'provider_unavailable','Speech is unavailable.',true,1000);
        return $this->repository->serializedIdempotency($installation,$message['message_id'],$route,
            function()use($installation,$message,$session,$book,$route):Response{
                return $this->idempotent($installation,$message['message_id'],$route,$message,
                    function()use($installation,$message,$session,$book):array{
                        $actor=$book?($this->products?->narratorProfileForInstallation($installation)['actor_identity']??null):$message['actor'];
                        if(!is_array($actor))throw new ApiException(409,'not_found','Configure the Narrator profile before reading aloud.',false);
                        $message['actor']=$actor;
                        $resolvedVoice=$this->morrowindVoices?->resolve($actor);
                        if(($actor['kind']??null)==='npc'&&$resolvedVoice!==null&&$this->products!==null){
                            $resolvedVoice=$this->products->preferExactProviderActorVoice($installation,$actor,$resolvedVoice);
                            $this->products->ensureMorrowindActorProfile([
                                'installation_id'=>$installation,'profile_id'=>$session['profile_id'],
                                'playthrough_id'=>$session['playthrough_id'],'session_id'=>$message['session_id'],
                                'generation'=>$message['generation'],'payload'=>['target'=>$actor],
                            ],$resolvedVoice,gmdate('Y-m-d\TH:i:s\Z'));
                        }
                        $preset=$this->products?->connectorForActor($installation,(string)$session['playthrough_id'],$actor,'tts_provider');
                        if(($actor['kind']??null)==='player'&&$preset===null)
                            throw new ApiException(503,'provider_unavailable','Player speech is disabled.',false);
                        $preset??=$this->products?->connectorForInstallation($installation,'tts_provider');
                        $provider=$preset===null?$this->speechProvider:ProviderFactory::speechForPreset($this->providerConfig,$preset);
                        if($provider===null)throw new ApiException(503,'provider_unavailable','Speech is unavailable.',true,1000);
                        $context=$this->products?->speechContext($installation,(string)$session['playthrough_id'],$actor,$preset)??[];
                        $pronunciationContext=$this->products?->ttsPronunciationContext($installation,
                            (string)$session['playthrough_id'],$actor)??[];
                        $ttsText=$this->products?->applyTtsPronunciation((string)$message['text'],$pronunciationContext)??(string)$message['text'];
                        if(($actor['kind']??'')==='player'&&($this->products?->narratorProfileForInstallation($installation)['content']['narration_filters']['remove_player_input_asterisks']??false)){
                            $ttsText=\LorkhanServer\Application\NarrationTextPolicy::spoken($ttsText);
                            if($ttsText==='')throw new ApiException(422,'invalid_schema','No spoken text remains after narration filtering.',false);
                        }
                        $providerIdentity=$provider instanceof \LorkhanServer\Application\FilteredSpeechProvider?$provider->inner:$provider;
                        $providerName=match(true){$providerIdentity instanceof \LorkhanServer\Application\PocketTtsSpeechProvider=>'pockettts',
                            $providerIdentity instanceof \LorkhanServer\Application\XttsCompatibleSpeechProvider=>'xtts-compatible',
                            $providerIdentity instanceof \LorkhanServer\Application\CloudSpeechConnectorProvider=>'cloud-speech',
                            $providerIdentity instanceof \LorkhanServer\Application\OpenAiCompatibleSpeechProvider=>'openai-compatible',default=>'mock'};
                        $attemptId=Uuid::v4();$mediaId=null;
                        $this->providerAttempts?->start($attemptId,'tts',$providerName,'synthesize',1,
                            (string)$message['request_id'],null,inputBytes:strlen($ttsText),
                            metadata:['mode'=>$book?'book_read_aloud':'menu_dialogue','configuration_id'=>$preset['configuration_id']??null,
                                'configuration_revision'=>$preset['revision']??null,'profile_voice'=>isset($context['voice'])]);
                        try{
                            $generated=$provider->synthesize($ttsText,new NeverCancelledToken(),$context);
                            $mediaId=Uuid::v4();$sha=$this->mediaStore->put($mediaId,$generated['bytes'],$generated['codec'],$generated['mime_type']);
                            $speech=['media_id'=>$mediaId,'sha256'=>$sha,'bytes'=>strlen($generated['bytes']),
                                'codec'=>$generated['codec'],'mime_type'=>$generated['mime_type'],'duration_ms'=>$generated['duration_ms'],
                                'expires_at'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z')];
                            $this->repository->recordMenuDialogueSpeech($message,$session,$speech);
                            $this->providerAttempts?->finish($attemptId,'succeeded',strlen($generated['bytes']));
                            $media=$speech;unset($media['mime_type']);$media['dialogue_message_id']=$message['message_id'];
                            return[201,['schema'=>'lorkhan.menu-dialogue-tts.ready.v1','message_id'=>$message['message_id'],
                                'request_id'=>$message['request_id'],'session_id'=>$message['session_id'],
                                'generation'=>$message['generation'],'actor'=>$actor,'media'=>$media]];
                        }catch(Throwable $error){
                            if($mediaId!==null)$this->mediaStore->delete($mediaId);
                            try{$this->providerAttempts?->finish($attemptId,'failed',errorCode:'provider_unavailable');}catch(Throwable){}
                            throw $error;
                        }
                    });
            });
    }

    /** Rewrite one player intent through the dedicated profile route before the real turn is created. */
    private function playerAutochat(Request $request): Response
    {
        if($this->products===null)throw new ApiException(503,'provider_unavailable','Player Auto Chat unavailable.',false);
        $message=$this->json($request,'lorkhan.player-autochat.v1');
        $session=$this->repository->session((string)$message['session_id'],(int)$message['generation']);
        $installation=(string)$session['installation_id'];$this->assertPrincipal($installation);
        $this->repository->assertAiEnabled($installation);
        $this->requireIdempotency($request,$message['message_id']);
        return $this->repository->serializedIdempotency($installation,$message['message_id'],'/player-autochat',
            function()use($installation,$message,$session):Response{
                return $this->idempotent($installation,$message['message_id'],'/player-autochat',$message,
                    function()use($installation,$message,$session):array{
                        $player=(array)$message['player'];$profile=$this->products?->playerProfileForInstallation($installation,(string)$session['playthrough_id']);
                        if($profile===null)throw new ApiException(503,'provider_unavailable','Player profile unavailable.',false);
                        $slot=$this->products?->connectorForActor($installation,(string)$session['playthrough_id'],$player,
                            'provider','player_autochat_configuration_id');
                        if($slot===null)throw new ApiException(503,'provider_unavailable','Player Auto Chat is disabled.',false);
                        $recent=array_reverse($this->products?->recentPlayerInputs($installation,20,(string)$session['playthrough_id'])??[]);
                        $input=['generation_mode'=>'player_autochat','intent'=>trim((string)$message['intent']),
                            'player'=>['name'=>(string)$profile['name'],'identity'=>$player,
                                'profile'=>array_intersect_key((array)$profile['content'],array_fill_keys([
                                    'appearance','biography','personality','speech_style','goals','notes'],true))],
                            'target'=>$message['target'],'recent_player_dialogue'=>$recent];
                        $attemptId=Uuid::v4();$providerName=(string)($slot['content']['driver']??'mock');
                        $encoded=json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                        $this->providerAttempts?->start($attemptId,'llm',$providerName,'player_autochat',1,
                            (string)$message['request_id'],null,inputBytes:strlen($encoded),metadata:[
                                'mode'=>'player_autochat','configuration_id'=>$slot['configuration_id'],
                                'configuration_revision'=>$slot['revision'],'profile_id'=>$profile['profile_id'],
                                'profile_revision'=>$profile['revision']]);
                        try{
                            $provider=ProviderFactory::profileGenerationForSlot($this->providerConfig,$slot);
                            $generated=$provider->generate($input,new \LorkhanServer\Application\CallbackCancellationToken(function()use($installation):bool{
                                try{$this->repository->assertAiEnabled($installation);return false;}
                                catch(DomainException){return true;}
                            }));
                            $this->repository->assertAiEnabled($installation);
                            $text=trim((string)($generated['text']??''));
                            if(($this->products?->narratorProfileForInstallation($installation)['content']['narration_filters']['remove_player_autochat_asterisks']??false))
                                $text=\LorkhanServer\Application\NarrationTextPolicy::spoken($text);
                            if($text===''||strlen($text)>16_384||mb_strlen($text,'UTF-8')>4096
                                ||!mb_check_encoding($text,'UTF-8')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$text)===1)
                                throw new DomainException('provider_invalid_output');
                            $this->providerAttempts?->finish($attemptId,'succeeded',strlen($text));
                            return[201,['schema'=>'lorkhan.player-autochat.ready.v1','message_id'=>$message['message_id'],
                                'request_id'=>$message['request_id'],'session_id'=>$message['session_id'],
                                'generation'=>$message['generation'],'text'=>$text]];
                        }catch(Throwable $error){
                            // Preserve the master-switch reason rather than reporting a provider outage.
                            if($error instanceof \LorkhanServer\Application\OperationCancelled){
                                try{$this->providerAttempts?->finish($attemptId,'cancelled',errorCode:'operation_cancelled');}catch(Throwable){}
                                $this->repository->assertAiEnabled($installation);
                            }
                            try{$this->providerAttempts?->finish($attemptId,'failed',errorCode:
                                $error instanceof DomainException?'provider_invalid_output':'provider_unavailable');}catch(Throwable){}
                            if($error instanceof DomainException)throw $error;
                            throw new ApiException(503,'provider_unavailable','Player Auto Chat provider unavailable.',true,1000);
                        }
                    });
            });
    }

    private function interrupt(Request $request): Response
    {
        $m = $this->json($request, 'lorkhan.interrupt.v1');
        $this->assertPrincipal($this->repository->sessionInstallation($m['session_id']));
        $this->requireIdempotency($request, $m['message_id']);
        $result = $this->repository->interrupt($m);
        return Response::json(202, ['schema' => 'lorkhan.interruption.accepted.v1', 'message_id' => $m['message_id'],
            'request_id' => $m['request_id'], 'session_id' => $m['session_id'], 'generation' => $m['generation'],
            'turn_id' => $m['turn_id'], 'event_cursor' => $result['cursor'], 'duplicate' => $result['duplicate']]);
    }

    private function actionResult(Request $request): Response
    {
        $m = $this->json($request, 'lorkhan.action-result.v1');
        $this->assertPrincipal($this->repository->sessionInstallation($m['session_id']));
        $this->requireIdempotency($request, $m['message_id']);
        $result = $this->repository->actionResult($m);
        return Response::json(200, ['schema' => 'lorkhan.action-result.accepted.v1', 'message_id' => $m['message_id'],
            'request_id' => $m['request_id'], 'action_id' => $m['action_id'], 'turn_id' => $m['turn_id'],
            'session_id' => $m['session_id'], 'generation' => $m['generation'], 'status' => $m['status'],
            'duplicate' => $result['duplicate']]);
    }

    /** Persist authenticated opaque audio and enqueue one durable transcription job. */
    private function stt(Request $request): Response
    {
        if ($this->mediaStore === null) throw new ApiException(503, 'provider_unavailable', 'STT unavailable.', true, 1000);
        if (strtolower(trim((string) $request->header('Content-Type'))) !== 'application/octet-stream') {
            throw new ApiException(415, 'invalid_schema', 'Binary audio required.');
        }
        $header = fn(string $name): string => (string) ($request->header('X-LORKHAN-' . $name) ?? '');
        $message = [
            'schema' => $header('Schema'), 'message_id' => $header('Message-Id'), 'request_id' => $header('Request-Id'),
            'turn_id' => $header('Turn-Id'), 'session_id' => $header('Session-Id'),
            'generation' => filter_var($header('Generation'), FILTER_VALIDATE_INT), 'created_at' => $header('Created-At'),
            'codec' => $header('Codec'), 'language' => $header('Language'),
            'audio_bytes' => filter_var($header('Audio-Bytes'), FILTER_VALIDATE_INT), 'sha256' => $header('Sha256'),
        ];
        $this->validator->validate($message, 'lorkhan.stt.request.v1');
        $installationId = $this->repository->sessionInstallation($message['session_id']);
        $this->assertPrincipal($installationId);
        $this->requireIdempotency($request, $message['message_id']);
        $session = $this->repository->session($message['session_id'], (int) $message['generation']);
        if (!in_array('speech.listen', $session['capabilities'], true)) {
            throw new ApiException(403, 'forbidden', 'Speech input was not negotiated for this session.');
        }
        $preset = $this->products?->connectorForInstallation($installationId, 'stt_provider');
        if ($this->sttProviderOverride === null && ($preset === null || ($preset['content']['driver'] ?? 'none') === 'none')) {
            throw new ApiException(503, 'provider_unavailable', 'STT unavailable.', true, 1000);
        }
        if (strlen($request->body) !== $message['audio_bytes'] || !hash_equals($message['sha256'], hash('sha256', $request->body))) {
            throw new ValidationException('invalid_schema');
        }
        $semanticHash = $this->semanticHash($message);
        $mediaId = Uuid::v4();
        try {
            $this->mediaStore->put($mediaId, $request->body, 'wav', 'audio/wav');
        } catch (Throwable) {
            throw new ApiException(422, 'invalid_audio', 'Audio is not a valid WAV payload.');
        }
        try {
            $result = $this->repository->acceptStt($message, $mediaId, $semanticHash);
            if ($result['duplicate']) $this->mediaStore->delete($mediaId);
        } catch (Throwable $error) {
            $this->mediaStore->delete($mediaId);
            throw $error;
        }
        return Response::json(202, ['schema' => 'lorkhan.stt.accepted.v1', 'message_id' => $message['message_id'],
            'request_id' => $message['request_id'], 'turn_id' => $message['turn_id'], 'session_id' => $message['session_id'],
            'generation' => $message['generation'], 'event_cursor' => $result['cursor'], 'duplicate' => $result['duplicate']]);
    }

    private function deliveryResult(Request $request):Response
    {
        $m=$this->json($request,'lorkhan.dialogue-delivery-result.v1');$this->assertPrincipal($this->repository->sessionInstallation($m['session_id']));$this->requireIdempotency($request,$m['message_id']);$result=$this->repository->dialogueDeliveryResult($m);return Response::json(200,['schema'=>'lorkhan.dialogue-delivery-result.accepted.v1','message_id'=>$m['message_id'],'request_id'=>$m['request_id'],'dialogue_message_id'=>$m['dialogue_message_id'],'turn_id'=>$m['turn_id'],'session_id'=>$m['session_id'],'generation'=>$m['generation'],'status'=>$m['status'],'duplicate'=>$result['duplicate']]);
    }

    private function authenticate(Request $request): void
    {
        foreach ($request->query as $key => $_) {
            if (in_array(strtolower($key), ['token', 'pairing_token', 'authorization', 'access_token'], true)) {
                throw new ApiException(400, 'invalid_schema', 'Credentials are not accepted in query strings.');
            }
        }
        if($request->header('Authorization')!==null)throw new ApiException(401,'unauthorized','Bearer authentication is not accepted.');
        $principal=$this->management?->requestMacPrincipal($request);
        if($principal===null){$key=$this->bootstrapMacKey();$principal=$key===null?false:RequestMac::verify($request,$key);}
        if(!is_string($principal))throw new ApiException(401,'unauthorized','Authentication failed.');
        $this->authenticatedInstallation=$principal;
    }

    private function assertPrincipal(string $installation):void
    {
        if($this->authenticatedInstallation!==null&&!hash_equals($this->authenticatedInstallation,$installation))
            throw new ApiException(403,'forbidden','Installation does not match authenticated token.');
    }

    private function json(Request $request, string $schema): array
    {
        $type = $request->header('Content-Type');
        if ($type === null || preg_match('#^application/json(?:\s*;\s*charset=utf-8)?$#iD', trim($type)) !== 1) {
            throw new ApiException(415, 'invalid_schema', 'JSON UTF-8 content type required.');
        }
        $message = $this->validator->decode($request->body, $this->maxJsonBytes);
        $this->validator->validate($message, $schema);
        return $message;
    }

    private function requireIdempotency(Request $request, ?string $expected = null): string
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || !$this->uuid($key) || ($expected !== null && !hash_equals($expected, $key))) {
            throw new ApiException(422, 'invalid_idempotency_key', 'A coherent Idempotency-Key is required.');
        }
        return $key;
    }

    private function idempotent(string $installation, string $key, string $route, array $message, callable $callback): Response
    {
        $hash = $this->semanticHash($message);
        $cached = $this->repository->idempotent($installation, $key, $route, $hash);
        if ($cached !== null) return Response::json($cached['status'], $cached['body']);
        [$status, $body] = $callback();
        $this->repository->remember($installation, $key, $route, $hash, $status, $body);
        return Response::json($status, $body);
    }

    private function semanticHash(array $message): string
    {
        return hash('sha256', json_encode($this->canonical($message), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn($v) => $this->canonical($v), $value);
        ksort($value);
        foreach ($value as &$item) $item = $this->canonical($item);
        return $value;
    }

    private function path(string $path): string
    {
        if (!str_starts_with($path, $this->basePath)) throw new ApiException(404, 'not_found', 'Route not found.');
        $value = substr($path, strlen($this->basePath));
        return $value === '' ? '/' : $value;
    }

    private function rateLimitRoute(string $path): string
    {
        if (preg_match('#^/media/[0-9a-f-]{36}$#D', $path)) return '/media/{id}';
        if (preg_match('#^/sessions/[0-9a-f-]{36}$#D', $path)) return '/sessions/{id}';
        return $path;
    }

    private function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function bootstrapMacKey():?string
    {
        $value=getenv('LORKHAN_PAIRING_MAC_KEY');if(!is_string($value)||$value==='')return hex2bin($this->pairingTokenHash)?:null;
        $decoded=base64_decode(strtr($value,'-_','+/'),true);return is_string($decoded)&&strlen($decoded)===32?$decoded:null;
    }

    private function publicCode(string $code):string
    {
        $aliases=['diary_books_unsupported'=>'invalid_schema','diary_book_not_found'=>'not_found','diary_book_mismatch'=>'request_mismatch',
            'diary_book_terminal'=>'duplicate_conflict','diary_book_superseded'=>'request_mismatch','revision_conflict'=>'request_mismatch','dialogue_result_mismatch'=>'request_mismatch','dialogue_result_time_invalid'=>'invalid_schema','unknown_dialogue'=>'not_found'];if(isset($aliases[$code]))return$aliases[$code];
        $allowed=['character_binding_required','character_binding_conflict','playthrough_isolation_required','ai_disabled','action_disabled','action_parameters_invalid','action_result_expired','action_result_mismatch','invalid_audio',
            'action_target_invalid','action_tier_mismatch','cursor_expired','duplicate_conflict','invalid_idempotency_key',
            'invalid_schema','media_unavailable','not_found','provider_action_not_allowed','provider_invalid_action',
            'provider_invalid_output','provider_timeout','provider_unavailable','rate_limited','request_mismatch',
            'rechat_chain_conflict','rechat_complete','rechat_cooldown','conversation_cooldown','rechat_no_responder','rechat_unavailable',
            'invalid_rechat_context','stale_generation','turn_terminal','unauthorized','unknown_action','unknown_session','unknown_turn'];
        return in_array($code,$allowed,true)?$code:'internal_error';
    }

    private function error(ApiException $error, string $correlation): Response
    {
        return Response::error($error->status, $error->errorCode, $correlation, $error->retrySafe, $error->retryAfterMs);
    }
}
