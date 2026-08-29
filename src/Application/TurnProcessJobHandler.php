<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

use LORKHANserver\Infrastructure\MediaStore;
use LORKHANserver\Infrastructure\ProviderAttemptRepository;
use LORKHANserver\Infrastructure\Repository;
use LORKHANserver\Infrastructure\Uuid;
use DomainException;
use Throwable;

final class TurnProcessJobHandler implements JobHandler
{
    public const TYPE = 'turn.process';

    public function __construct(
        private readonly Repository $repository,
        private readonly Provider $provider,
        private readonly ?MediaStore $mediaStore,
        private readonly ?ProviderAttemptRepository $attempts,
        private readonly int $timeoutMs = 1000,
        private readonly array $providerConfig = [],
        private readonly ?TranslationProvider $translationProvider = null,
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
            $progress = function (string $delta) use ($message, $fence, $policy): void {
                if($policy['content']['translate_text'])return;
                if ($delta !== '') $this->repository->appendDialogueDelta($message, $delta, $fence);
            };
            $result = (new InlineNarrationRouter())->route($message,$this->completeWithFallback($message,$job,$token,$progress));
            $token->throwIfCancellationRequested();
            $result=$this->translateResult($message,$result,$policy,$job,$token);
            $queueSpeech = $this->mediaStore !== null
                && in_array('speech.say', $message['_negotiated_capabilities'], true);
            $this->repository->completeTurn($message, $result, null, $fence, $queueSpeech);
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
            'text'=>$utterance['text'],'speech_enabled'=>$utterance['speech_enabled']];
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
                $utterance['_history_text']=$content['save_translated_text']?$translation:$utterance['text'];
                $utterance['_subtitle']=$content['translate_text']?$translation:$utterance['text'];
                $utterance['_tts_text']=$content['translate_audio']?$translation:$utterance['text'];
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
                    'model'=>$snapshot['content']['model']??null]);
            try{
                $result=$provider instanceof StreamingProvider
                    ?$provider->completeStreaming($message,$token,$onDialogueDelta)
                    :$provider->complete($message,$token);
                $bytes=strlen(json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'');
                $this->attempts?->finish($attemptId,'succeeded',$bytes);return$result;
            }catch(OperationCancelled$error){
                $this->attempts?->finish($attemptId,'cancelled',errorCode:'operation_cancelled');throw$error;
            }catch(Throwable$error){
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
