<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\MediaStore;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\Repository;
use LorkhanServer\Infrastructure\Uuid;
use DomainException;
use Throwable;

final class TurnProcessJobHandler implements JobHandler
{
    public const TYPE = 'turn.process';
    private const INLINE_SPEECH_TIMEOUT_MS = 5_000;

    public function __construct(
        private readonly Repository $repository,
        private readonly Provider $provider,
        private readonly ?MediaStore $mediaStore,
        private readonly ?ProviderAttemptRepository $attempts,
        private readonly int $timeoutMs = 1000,
        private readonly array $providerConfig = [],
        private readonly ?TranslationProvider $translationProvider = null,
        private readonly ?SpeechProvider $speechProvider = null,
        private readonly ?ProductRepository $products = null,
    ) {}

    public function supports(string $jobType, int $schemaVersion): bool
    {
        return $jobType === self::TYPE && $schemaVersion === 1;
    }

    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        $turnId = $this->uuid($payload, 'turn_id');
        $sessionId = $this->uuid($payload, 'session_id');
        $generation = $payload['generation'] ?? null;
        if (!is_int($generation) || $generation < 0) throw new \InvalidArgumentException('invalid_generation');
        $job=$payload['_job']??null;
        if(!is_array($job)||!is_string($job['job_id']??null)||!is_string($job['lease_token']??null)||!is_int($job['attempt']??null))
            throw new \InvalidArgumentException('invalid_job_fence');
        $fence=['job_id'=>$job['job_id'],'lease_token'=>$job['lease_token'],'attempt'=>$job['attempt']];
        try {
            $turn = $this->repository->claimTurnForProcessing($turnId,$job['job_id'],$job['lease_token'],$job['attempt']);
        } catch (DomainException $error) {
            // A worker may have committed the terminal turn state and then lost its job lease or
            // exited before acknowledging the durable job. Treat that replay as completed work.
            if ($error->getMessage() === 'turn_terminal') return;
            throw $error;
        }
        $message = $this->repository->turnMessage($turnId);
        $deadline = hrtime(true) + max(1, $this->timeoutMs) * 1_000_000;
        $lastCheck = 0;
        $cancelled = false;
        $token = new CallbackCancellationToken(function() use (&$lastCheck,&$cancelled,$deadline,$heartbeat,$sessionId,$turnId,$generation):bool {
            $now=hrtime(true);if($cancelled||$now>=$deadline)return true;if($now-$lastCheck<100_000_000)return false;$lastCheck=$now;
            return $cancelled=!$heartbeat()||$this->repository->isTurnCancellationRequested($sessionId,$turnId,$generation);
        });
        try {
            $policy=$this->translationPolicy($message);
            $streamedDialogues=[];$pendingInlineSpeech=null;
            $streamSpeech=$this->canStreamSpeech($message,$policy);
            $progress = function (string $delta, ?string $language=null, ?string $mood=null) use ($message, $fence, $policy, $job, $heartbeat, $streamSpeech,
                &$streamedDialogues,&$pendingInlineSpeech): void {
                if($policy['content']['translate_text'])return;
                if($delta==='')return;
                $this->repository->appendDialogueDelta($message,$delta,$fence);
                if(!$streamSpeech||count($streamedDialogues)>=DialoguePlanner::MAX_UTTERANCES)return;
                $index=count($streamedDialogues)+1;
                $dialogue=$this->repository->appendStreamedDialogue($message,$delta,$fence,$index)+SpeechLanguage::payload($language)+($mood===null?[]:['mood'=>$mood]);
                $streamedDialogues[]=$dialogue;
                if($index===1){
                    if(!$this->synthesizeStreamedDialogue($message,$dialogue,$fence,$job,$heartbeat,1))$pendingInlineSpeech=$dialogue;
                    return;
                }
                if($pendingInlineSpeech!==null){
                    if(!$this->synthesizeStreamedDialogue($message,$pendingInlineSpeech,$fence,$job,$heartbeat))
                        $this->repository->queueStreamedDialogueSpeech($message,$pendingInlineSpeech,$fence);
                    $pendingInlineSpeech=null;
                }
                $this->repository->queueStreamedDialogueSpeech($message,$dialogue,$fence);
            };
            $result = (new InlineNarrationRouter())->route($message,$this->completeWithFallback($message,$job,$token,$progress));
            if($pendingInlineSpeech!==null)$this->repository->queueStreamedDialogueSpeech($message,$pendingInlineSpeech,$fence);
            $token->throwIfCancellationRequested();
            $result=$this->translateResult($message,$result,$policy,$job,$token);
            if($streamedDialogues!==[])$result=$this->reconcileStreamedResult($message,$result,$streamedDialogues);
            $queueSpeech = $this->mediaStore !== null
                && in_array('speech.say', $message['_negotiated_capabilities'], true);
            $this->repository->completeTurn($message,$result,null,$fence,$queueSpeech,$streamedDialogues);
            if($this->products!==null){
                try{$this->products->maybeEnqueueAutomaticProfileBackfillForTurn($message);}
                catch(Throwable $error){error_log('[LORKHAN] automatic profile backfill scheduling failed: '.$error::class);}
            }
        } catch (OperationCancelled) {
            if (!$this->repository->isTurnCancellationRequested($sessionId, $turnId, $generation)) {
                $this->repository->failTurn($message, 'provider_timeout', $fence);
            }
            return;
        } catch (Throwable $error) {
            // Persisting the terminal failure is the successful handling of this turn job. Retrying
            // the provider after exposing turn.failed would contradict the terminal protocol state.
            $this->repository->failTurn($message, $this->providerFailureCode($error), $fence);
            return;
        }
    }

    /** Stream speech only where speaker routing and presentation text cannot change after provider completion. */
    private function canStreamSpeech(array $message,array $policy):bool
    {
        if($this->mediaStore===null||$this->products===null
            ||!in_array('speech.say',$message['_negotiated_capabilities'],true)
            ||$policy['content']['translate_text']||$policy['content']['translate_audio'])return false;
        $mode=(string)($message['_narrator_profile']['content']['inline_narration_mode']??'Disabled');
        if($mode!=='Disabled')return false;
        if(($message['_narrator_profile']['content']['narration_filters']['remove_npc_output_asterisks']??false)===true)return false;
        $planner=new DialoguePlanner();$identities=[];
        try{
            foreach(array_merge([$message['payload']['target']],$message['payload']['audience'])as$identity)
                $identities[$planner->identityKey($identity)]=true;
        }catch(Throwable){return false;}
        return count($identities)===1;
    }

    /** Generate a streamed sentence inline, optionally retrying one transient provider failure immediately. */
    private function synthesizeStreamedDialogue(array $message,array $dialogue,array $fence,array $job,callable $heartbeat,
        int $unavailableRetries=0):bool
    {
        $mediaId=null;$attemptId=Uuid::v4();$timedOut=false;$aborted=false;$lastCheck=0;
        $deadline=hrtime(true)+self::INLINE_SPEECH_TIMEOUT_MS*1_000_000;
        $token=new CallbackCancellationToken(function()use(&$timedOut,&$aborted,&$lastCheck,$deadline,$heartbeat,$message):bool{
            $now=hrtime(true);if($now>=$deadline)return $timedOut=true;
            if($now-$lastCheck<100_000_000)return false;$lastCheck=$now;
            return $aborted=!$heartbeat()||$this->repository->isTurnCancellationRequested(
                (string)$message['session_id'],(string)$message['turn_id'],(int)$message['generation']);
        });
        try{
            $preset=$this->products?->connectorForActor((string)$message['installation_id'],
                (string)$message['playthrough_id'],(array)$dialogue['speaker'],'tts_provider');
            $preset??=$this->products?->connectorForInstallation((string)$message['installation_id'],'tts_provider');
            $provider=$preset===null?$this->speechProvider:ProviderFactory::speechForPreset($this->providerConfig,$preset);
            if($provider===null)return false;
            $context=$this->products?->speechContext((string)$message['installation_id'],
                (string)$message['playthrough_id'],(array)$dialogue['speaker'],$preset)??[];
            $context+=array_intersect_key($dialogue,['mood'=>true]);
            $context=SpeechLanguage::context($context,$preset,$dialogue['tts_language']??null);
            $pronunciationContext=$this->products?->ttsPronunciationContext((string)$message['installation_id'],
                (string)$message['playthrough_id'],(array)$dialogue['speaker'])??[];
            $ttsText=$this->products?->applyTtsPronunciation((string)$dialogue['text'],$pronunciationContext)??(string)$dialogue['text'];
            $providerName=match(true){$provider instanceof PocketTtsSpeechProvider=>'pockettts',
                $provider instanceof XttsCompatibleSpeechProvider=>'xtts-compatible',
                $provider instanceof CloudSpeechConnectorProvider=>'cloud-speech',
                $provider instanceof OpenAiCompatibleSpeechProvider=>'openai-compatible',default=>'mock'};
            $this->attempts?->start($attemptId,'tts',$providerName,'synthesize_streamed',(int)$dialogue['utterance_index'],
                $message['request_id'],$message['turn_id'],$job['job_id'],inputBytes:strlen($ttsText),
                metadata:['job'=>true,'streamed'=>true,'utterance_index'=>$dialogue['utterance_index'],
                    'unavailable_retries_remaining'=>$unavailableRetries,
                    'configuration_id'=>$preset['configuration_id']??null,'configuration_revision'=>$preset['revision']??null]);
            $generated=$provider->synthesize($ttsText,$token,$context);$token->throwIfCancellationRequested();
            $mediaId=Uuid::v4();$sha=$this->mediaStore->put($mediaId,$generated['bytes'],$generated['codec'],$generated['mime_type']);
            $speech=['media_id'=>$mediaId,'sha256'=>$sha,'bytes'=>strlen($generated['bytes']),'codec'=>$generated['codec'],
                'mime_type'=>$generated['mime_type'],'duration_ms'=>$generated['duration_ms'],
                'expires_at'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z')];
            $event=$this->repository->completeStreamedDialogueSpeech($message,$dialogue,$speech,$fence);
            if($event===[]){$this->mediaStore->delete($mediaId);$mediaId=null;}
            $this->attempts?->finish($attemptId,'succeeded',strlen($generated['bytes']));
            return true;
        }catch(OperationCancelled $error){
            if($mediaId!==null)$this->mediaStore?->delete($mediaId);
            try{$this->attempts?->finish($attemptId,$timedOut?'failed':'cancelled',
                errorCode:$timedOut?'provider_timeout':'operation_cancelled');}catch(Throwable){}
            if($aborted)throw$error;
            return false;
        }catch(Throwable){
            if($mediaId!==null)$this->mediaStore?->delete($mediaId);
            try{$this->attempts?->finish($attemptId,'failed',errorCode:'provider_unavailable');}catch(Throwable){}
            if($aborted)throw new OperationCancelled();
            if($unavailableRetries>0)return$this->synthesizeStreamedDialogue(
                $message,$dialogue,$fence,$job,$heartbeat,$unavailableRetries-1);
            return false;
        }
    }

    /** Reuse streamed sentence identities only when they exactly reconstruct the validated provider text. */
    private function reconcileStreamedResult(array $message,array $result,array $streamedDialogues):array
    {
        $planned=(new DialoguePlanner())->plan($message,$result);
        $finalText=preg_replace('/\s+/u',' ',trim(implode("\n",array_column($planned,'_history_text'))));
        $streamedText=preg_replace('/\s+/u',' ',trim(implode("\n",array_column($streamedDialogues,'text'))));
        if($finalText!==$streamedText)throw new DomainException('provider_invalid_output');
        $utterances=[];
        foreach($streamedDialogues as$dialogue)$utterances[]=['text'=>$dialogue['text']]+array_intersect_key($dialogue,['mood'=>true])+SpeechLanguage::payload($dialogue['tts_language']??null);
        return['utterances'=>$utterances,'action'=>$result['action']??null];
    }

    /** Resolve only the frozen policy snapshot accepted with this turn. */
    private function translationPolicy(array $message):array
    {
        $snapshot=$message['_translation_policy']??null;
        if(!is_array($snapshot)||array_is_list($snapshot))
            return['configuration_id'=>null,'revision'=>0,'content'=>TranslationPolicy::defaults()];
        $keys=array_keys($snapshot);sort($keys);
        if($keys!==['configuration_id','content','revision']
            ||(!is_null($snapshot['configuration_id'])&&(!is_string($snapshot['configuration_id'])||!Uuid::isValid($snapshot['configuration_id'])))
            ||!is_int($snapshot['revision'])||$snapshot['revision']<0
            ||(($snapshot['configuration_id']===null)!==($snapshot['revision']===0))
            ||!is_array($snapshot['content'])||array_is_list($snapshot['content']))
            throw new DomainException('provider_invalid_output');
        $snapshot['content']=TranslationPolicy::validate($snapshot['content']);return$snapshot;
    }

    /** Validate provider utterances, translate once in a batch, and keep history/subtitle/TTS choices independent. */
    private function translateResult(array $message,array $result,array $policy,array $job,CancellationToken $token):array
    {
        $planned=(new DialoguePlanner())->plan($message,$result);$clean=[];
        foreach($planned as$utterance)$clean[]=['speaker'=>$utterance['speaker'],'addressee'=>$utterance['addressee'],
            'text'=>$utterance['text'],'speech_enabled'=>$utterance['speech_enabled'],
            '_history_text'=>$utterance['_history_text'],'_subtitle'=>$utterance['_subtitle'],'_tts_text'=>$utterance['_tts_text']]+array_intersect_key($utterance,['mood'=>true])+SpeechLanguage::payload($utterance['tts_language']??null);
        $content=$policy['content'];$enabled=$content['translate_text']||$content['translate_audio'];
        if(!$enabled)return['utterances'=>$clean,'action'=>$result['action']??null];

        $attemptId=Uuid::v4();$texts=array_column($clean,'text');$inputBytes=array_sum(array_map('strlen',$texts));
        $this->attempts?->start($attemptId,'translation','deepl','translate_dialogue',(int)$job['attempt'],
            $message['request_id'],$message['turn_id'],$job['job_id'],configRevision:'r'.(int)$policy['revision'],inputBytes:$inputBytes,
            metadata:['job'=>true,'configuration_id'=>$policy['configuration_id'],'configuration_revision'=>$policy['revision'],
                'utterance_count'=>count($texts),'source_language'=>$content['source_language'],'target_language'=>$content['target_language']]);
        try{
            $provider=$this->translationProvider??ProviderFactory::translation($this->providerConfig,$content);
            $translated=$provider->translate($texts,$content['source_language'],$content['target_language'],$token);
            $token->throwIfCancellationRequested();
            if(!array_is_list($translated)||count($translated)!==count($texts))
                throw new DomainException('provider_invalid_output');
            foreach($translated as$translation)
                if(!is_string($translation)||$translation===''||!mb_check_encoding($translation,'UTF-8')
                    ||mb_strlen($translation,'UTF-8')>4096||strlen($translation)>16_384)
                    throw new DomainException('provider_invalid_output');
            $outputBytes=array_sum(array_map('strlen',$translated));$this->attempts?->finish($attemptId,'succeeded',$outputBytes);
            foreach($clean as$index=>&$utterance){$translation=$translated[$index];
                $utterance['_history_text']=$content['save_translated_text']?$translation:$utterance['_history_text'];
                $utterance['_subtitle']=$content['translate_text']?$translation:$utterance['_subtitle'];
                $utterance['_tts_text']=$content['translate_audio']?$translation:$utterance['_tts_text'];
                if($content['translate_audio']&&isset($utterance['tts_language'])){
                    unset($utterance['tts_language']);
                    $utterance+=SpeechLanguage::payload($content['target_language']);
                }
            }unset($utterance);
        }catch(OperationCancelled$error){
            try{$this->attempts?->finish($attemptId,'cancelled',errorCode:'operation_cancelled');}catch(Throwable){}
            throw$error;
        }catch(Throwable){
            try{$this->attempts?->finish($attemptId,'failed',errorCode:'provider_unavailable');}catch(Throwable){}
            // Translation is presentation support: an unavailable adapter must not discard valid model dialogue.
        }
        return['utterances'=>$clean,'action'=>$result['action']??null];
    }

    /** Run the selected LLM once, retrying only with the profile's explicit CHIM-style fallback slot. */
    private function completeWithFallback(array $message,array $job,CancellationToken $token,callable $onDialogueDelta):array
    {
        $primary=$message['_provider_configuration']??null;$fallback=$message['_fallback_provider_configuration']??null;
        $routes=[['snapshot'=>is_array($primary)?$primary:null,'fallback'=>false]];
        if(is_array($fallback)&&($fallback['configuration_id']??null)!==($primary['configuration_id']??null))
            $routes[]=['snapshot'=>$fallback,'fallback'=>true];
        $lastError=null;
        foreach($routes as$index=>$route){
            $snapshot=$route['snapshot'];$provider=$snapshot===null?$this->provider:ProviderFactory::dialogueForSlot($this->providerConfig,$snapshot);
            $providerName=$provider instanceof OpenAiCompatibleProvider?'openai-compatible':'mock';$attemptId=Uuid::v4();
            $this->attempts?->start($attemptId,'llm',$providerName,'complete_turn',(($job['attempt']-1)*2)+$index+1,
                $message['request_id'],$message['turn_id'],$job['job_id'],inputBytes:strlen($message['payload']['input']['text']),
                metadata:['mode'=>$providerName,'job'=>true,'fallback'=>$route['fallback'],
                    'configuration_id'=>$snapshot['configuration_id']??null,'configuration_revision'=>$snapshot['revision']??null,
                    'configuration_name'=>$snapshot['name']??null,
                    'model'=>$snapshot['content']['model']??null]);
            try{
                $result=$provider instanceof StreamingProvider
                    ?$provider->completeStreaming($message,$token,$onDialogueDelta)
                    :$provider->complete($message,$token);
                $bytes=strlen(json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'');
                if($provider instanceof OpenAiCompatibleProvider)$this->attempts?->recordUsage($attemptId,$provider->reportedUsage());
                $this->attempts?->finish($attemptId,'succeeded',$bytes);return$result;
            }catch(OperationCancelled$error){
                if($provider instanceof OpenAiCompatibleProvider)$this->attempts?->recordUsage($attemptId,$provider->reportedUsage());
                $this->attempts?->finish($attemptId,'cancelled',errorCode:'operation_cancelled');throw$error;
            }catch(Throwable$error){
                if($provider instanceof OpenAiCompatibleProvider)$this->attempts?->recordUsage($attemptId,$provider->reportedUsage());
                try{$this->attempts?->finish($attemptId,'failed',errorCode:$this->providerFailureCode($error));}catch(Throwable){}
                $lastError=$error;
            }
        }
        throw $lastError??new \RuntimeException('provider_unavailable');
    }

    private function providerFailureCode(Throwable $error): string
    {
        return match ($error->getMessage()) {
            'provider_invalid_output', 'provider_speaker_not_allowed', 'provider_addressee_not_allowed',
            'provider_invalid_identity' => 'provider_invalid_output',
            'provider_invalid_action' => 'provider_invalid_action',
            'provider_action_not_allowed' => 'provider_action_not_allowed',
            'provider_timeout' => 'provider_timeout',
            default => 'provider_unavailable',
        };
    }

    private function uuid(array $payload,string $field):string
    {
        $value=$payload[$field]??null;
        if(!is_string($value)||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value)!==1)
            throw new \InvalidArgumentException('invalid_'.$field);
        return $value;
    }
}
