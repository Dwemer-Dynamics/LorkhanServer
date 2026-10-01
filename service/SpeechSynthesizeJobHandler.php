<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\Logger;
use LorkhanServer\Infrastructure\MediaStore;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\Repository;
use LorkhanServer\Infrastructure\Uuid;
use Throwable;

final class SpeechSynthesizeJobHandler implements JobHandler
{
    public const TYPE = 'speech.synthesize';

    public function __construct(
        private readonly Repository $repository,
        private readonly ?SpeechProvider $defaultProvider,
        private readonly MediaStore $mediaStore,
        private readonly ?ProviderAttemptRepository $attempts,
        private readonly ?ProductRepository $products,
        private readonly array $providerConfig = [],
        private readonly int $timeoutMs = 120_000,
    ) {}

    public function supports(string $jobType, int $schemaVersion): bool
    {
        return $jobType === self::TYPE && $schemaVersion === 1;
    }

    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        unset($idempotencyKey);
        $dialogueId=$payload['dialogue_message_id']??null;$job=$payload['_job']??null;
        if(!is_string($dialogueId)||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$dialogueId)!==1)
            throw new \InvalidArgumentException('invalid_dialogue_message_id');
        if(!is_array($job)||!is_string($job['job_id']??null)||!is_string($job['lease_token']??null)||!is_int($job['attempt']??null))
            throw new \InvalidArgumentException('invalid_job_fence');
        $ttsText=$payload['tts_text']??null;
        if($ttsText!==null&&(!is_string($ttsText)||$ttsText===''||!mb_check_encoding($ttsText,'UTF-8')
            ||mb_strlen($ttsText,'UTF-8')>4096||strlen($ttsText)>16_384))throw new \InvalidArgumentException('invalid_tts_text');
        $fence=['job_id'=>$job['job_id'],'lease_token'=>$job['lease_token'],'attempt'=>$job['attempt']];
        // Callers without a known budget keep plain retries; only the final attempt may end the line without audio.
        $finalAttempt=is_int($job['max_attempts']??null)&&$job['attempt']>=$job['max_attempts'];
        $dialogue=$this->repository->claimDialogueForSpeech($dialogueId,$fence);
        if($dialogue===null)return;
        $ttsText??=(string)$dialogue['text'];

        $deadline=hrtime(true)+max(1,$this->timeoutMs)*1_000_000;
        $lastCheck=0;$cancelled=false;
        $token=new CallbackCancellationToken(function()use(&$lastCheck,&$cancelled,$deadline,$heartbeat,$dialogueId):bool{
            $now=hrtime(true);if($cancelled||$now>=$deadline)return true;
            if($now-$lastCheck<100_000_000)return false;$lastCheck=$now;
            return $cancelled=!$heartbeat()||$this->repository->isDialogueCancellationRequested($dialogueId);
        });
        try{
            $preset=$this->products?->connectorForActor((string)$dialogue['installation_id'],
                (string)$dialogue['playthrough_id'],(array)$dialogue['speaker'],'tts_provider');
            $preset??=$this->products?->connectorForInstallation((string)$dialogue['installation_id'],'tts_provider');
            $provider=$preset===null?$this->defaultProvider:ProviderFactory::speechForPreset($this->providerConfig,$preset);
            if($provider===null){$this->failSpeech($dialogue,$fence,'provider_unconfigured');return;}

            $providerIdentity=$provider instanceof \LorkhanServer\Application\FilteredSpeechProvider?$provider->inner:$provider;
            $providerName=match(true){$providerIdentity instanceof PocketTtsSpeechProvider=>'pockettts',
                $providerIdentity instanceof XttsCompatibleSpeechProvider=>'xtts-compatible',
                $providerIdentity instanceof CloudSpeechConnectorProvider=>'cloud-speech',
                $providerIdentity instanceof OpenAiCompatibleSpeechProvider=>'openai-compatible',default=>'mock'};
            $context=$this->products?->speechContext((string)$dialogue['installation_id'],
                (string)$dialogue['playthrough_id'],(array)$dialogue['speaker'],$preset)??[];
            if (isset($payload['mood'])) {
                if (!is_string($payload['mood']) || strlen($payload['mood']) > 64 || !mb_check_encoding($payload['mood'],'UTF-8')) throw new \InvalidArgumentException('invalid_speech_mood');
                $context['mood']=$payload['mood'];
            }
            if (array_key_exists('tones',$payload)) $context['tones']=ZonosGradioSpeechProvider::validateTones($payload['tones']);
            $context=SpeechLanguage::context($context,$preset,$payload['tts_language']??null);
            $pronunciationContext=$this->products?->ttsPronunciationContext((string)$dialogue['installation_id'],
                (string)$dialogue['playthrough_id'],(array)$dialogue['speaker'])??[];
            $ttsText=$this->products?->applyTtsPronunciation($ttsText,$pronunciationContext)??$ttsText;
            $attemptNumber=(($job['attempt']-1)*4)+(int)$dialogue['utterance_index'];
            try{
                $this->synthesize($provider,$providerName,'synthesize',$ttsText,$context,$dialogue,$fence,$job,$token,
                    $attemptNumber,$preset);
            }catch(OperationCancelled $error){
                throw $error;
            }catch(Throwable $error){
                if(!$finalAttempt||$error->getMessage()==='lease_lost')throw $error;
                // Before giving up on audio, try the configured race/gender fallback voice once.
                $fallback=$this->products?->fallbackSpeechContext((string)$dialogue['installation_id'],
                    (string)$dialogue['playthrough_id'],(array)$dialogue['speaker'],$preset)['voice']??null;
                if(!is_string($fallback)||$fallback===''||$fallback===($context['voice']??null))throw $error;
                $this->synthesize($provider,$providerName,'synthesize_fallback_voice',$ttsText,['voice'=>$fallback]+$context,
                    $dialogue,$fence,$job,$token,$attemptNumber,$preset);
            }
        }catch(OperationCancelled $error){
            // Cancellation and lost ownership keep their existing semantics; only a final timeout terminates here.
            if(!$finalAttempt||$cancelled)throw $error;
            $this->failSpeech($dialogue,$fence,'provider_timeout',$error);
        }catch(Throwable $error){
            if(!$finalAttempt||$error->getMessage()==='lease_lost')throw $error;
            $this->failSpeech($dialogue,$fence,'provider_unavailable',$error);
        }
    }

    private function synthesize(SpeechProvider $provider,string $providerName,string $operation,string $ttsText,array $context,
        array $dialogue,array $fence,array $job,CancellationToken $token,int $attemptNumber,?array $preset):void
    {
        $attemptId=Uuid::v4();$mediaId=null;
        $this->attempts?->start($attemptId,'tts',$providerName,$operation,$attemptNumber,
            (string)$dialogue['request_id'],(string)$dialogue['turn_id'],$job['job_id'],inputBytes:strlen($ttsText),
            metadata:['mode'=>$providerName,'job'=>true,'utterance_index'=>(int)$dialogue['utterance_index'],
                'configuration_id'=>$preset['configuration_id']??null,'configuration_revision'=>$preset['revision']??null,
                'profile_voice'=>isset($context['voice'])]);
        try{
            $generated=$provider->synthesize($ttsText,$token,$context);
            $token->throwIfCancellationRequested();
            $mediaId=Uuid::v4();$sha=$this->mediaStore->put($mediaId,$generated['bytes'],$generated['codec'],$generated['mime_type']);
            $speech=['media_id'=>$mediaId,'sha256'=>$sha,'bytes'=>strlen($generated['bytes']),'codec'=>$generated['codec'],
                'mime_type'=>$generated['mime_type'],'duration_ms'=>$generated['duration_ms'],
                'expires_at'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z')];
            $event=$this->repository->completeDialogueSpeech($dialogue,$speech,$fence);
            if($event===[]){$this->mediaStore->delete($mediaId);$mediaId=null;}
            $this->attempts?->finish($attemptId,'succeeded',strlen($generated['bytes']));
        }catch(OperationCancelled $error){
            if($mediaId!==null)$this->mediaStore->delete($mediaId);
            try{$this->attempts?->finish($attemptId,'cancelled',errorCode:'operation_cancelled');}catch(Throwable){}
            throw $error;
        }catch(Throwable $error){
            if($mediaId!==null)$this->mediaStore->delete($mediaId);
            try{$this->attempts?->finish($attemptId,'failed',errorCode:'provider_unavailable');}catch(Throwable){}
            throw $error;
        }
    }

    /** Publish the terminal no-audio outcome; provider attempts already record the failure itself. */
    private function failSpeech(array $dialogue,array $fence,string $code,?Throwable $cause=null):void
    {
        if($this->repository->failDialogueSpeech($dialogue,$code,$fence)===[])return;
        // Provider messages can echo prompts or credentials; the class is enough to locate the failed attempt.
        Logger::warn('Speech unavailable; line continues as subtitle only: dialogue_message_id='
            .$dialogue['dialogue_message_id'].' code='.$code.($cause===null?'':' cause='.$cause::class));
    }
}
