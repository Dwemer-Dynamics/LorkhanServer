<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Infrastructure\MediaStore;
use ALMSIVIserver\Infrastructure\ProviderAttemptRepository;
use ALMSIVIserver\Infrastructure\ProductRepository;
use ALMSIVIserver\Infrastructure\Repository;
use ALMSIVIserver\Infrastructure\Uuid;
use DomainException;
use Throwable;

final class TurnProcessJobHandler implements JobHandler
{
    public const TYPE = 'turn.process';

    public function __construct(
        private readonly Repository $repository,
        private readonly Provider $provider,
        private readonly ?SpeechProvider $speechProvider,
        private readonly ?MediaStore $mediaStore,
        private readonly ?ProviderAttemptRepository $attempts,
        private readonly int $timeoutMs = 1000,
        private readonly array $providerConfig = [],
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
        $stagedMedia=[];
        try {
            $result = (new InlineNarrationRouter())->route($message,$this->completeWithFallback($message,$job,$token));
            $utterances = (new DialoguePlanner())->plan($message, $result);
            $speech = [];
            if ($this->mediaStore !== null && in_array('speech.say', $message['_negotiated_capabilities'], true)) {
                foreach ($utterances as $index => $utterance) {
                    $token->throwIfCancellationRequested();
                    if(($utterance['speech_enabled']??true)===false){$speech[$index]=null;continue;}
                    $speechPreset=$this->products?->connectorForActor((string)$message['installation_id'],
                        (string)$message['playthrough_id'],(array)$utterance['speaker'],'tts_provider');
                    $speechPreset??=$this->products?->connectorForInstallation((string)$message['installation_id'],'tts_provider');
                    $speechProvider=$speechPreset===null?$this->speechProvider:ProviderFactory::speechForPreset($this->providerConfig,$speechPreset);
                    if($speechProvider===null){$speech[$index]=null;continue;}
                    $speechAttempt = Uuid::v4();
                    $ttsAttempt=(($job['attempt']-1)*4)+$index+1;
                    $speechProviderName = match(true){$speechProvider instanceof XttsCompatibleSpeechProvider=>'xtts-compatible',
                        $speechProvider instanceof OpenAiCompatibleSpeechProvider=>'openai-compatible',default=>'mock'};
                    $speechContext=$this->products?->speechContext((string)$message['installation_id'],(string)$message['playthrough_id'],(array)$utterance['speaker'],$speechPreset)??[];
                    $this->attempts?->start($speechAttempt,'tts',$speechProviderName,'synthesize',$ttsAttempt,$message['request_id'],$turnId,$job['job_id'],
                        inputBytes:strlen($utterance['text']),metadata:['mode'=>$speechProviderName,'job'=>true,'utterance_index'=>$index+1,
                            'configuration_id'=>$speechPreset['configuration_id']??null,'configuration_revision'=>$speechPreset['revision']??null,
                            'profile_voice'=>isset($speechContext['voice'])]);
                    $generated = $speechProvider->synthesize($utterance['text'], $token,$speechContext);
                    $this->attempts?->finish($speechAttempt,'succeeded',strlen($generated['bytes']));
                    $mediaId = Uuid::v4();
                    $sha = $this->mediaStore->put($mediaId, $generated['bytes'], $generated['codec'], $generated['mime_type']);
                    $stagedMedia[]=$mediaId;
                    $speech[$index] = ['media_id'=>$mediaId,'sha256'=>$sha,'bytes'=>strlen($generated['bytes']),'codec'=>$generated['codec'],
                        'mime_type'=>$generated['mime_type'],'duration_ms'=>$generated['duration_ms'],
                        'expires_at'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z')];
                }
            }
            $token->throwIfCancellationRequested();
            $this->repository->completeTurn($message, $result, $speech, $fence);
            $stagedMedia=[];
        } catch (OperationCancelled) {
            foreach($stagedMedia as $mediaId)$this->mediaStore?->delete($mediaId);
            if (!$this->repository->isTurnCancellationRequested($sessionId, $turnId, $generation)) {
                $this->repository->failTurn($message, 'provider_timeout', $fence);
            }
            return;
        } catch (Throwable $error) {
            foreach($stagedMedia as $mediaId)$this->mediaStore?->delete($mediaId);
            // Persisting the terminal failure is the successful handling of this turn job. Retrying
            // the provider after exposing turn.failed would contradict the terminal protocol state.
            $this->repository->failTurn($message, 'provider_unavailable', $fence);
            return;
        }
    }

    /** Run the selected LLM once, retrying only with the profile's explicit CHIM-style fallback slot. */
    private function completeWithFallback(array $message,array $job,CancellationToken $token):array
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
                $result=$provider->complete($message,$token);
                $bytes=strlen(json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'');
                $this->attempts?->finish($attemptId,'succeeded',$bytes);return$result;
            }catch(OperationCancelled$error){
                $this->attempts?->finish($attemptId,'cancelled',errorCode:'operation_cancelled');throw$error;
            }catch(Throwable$error){
                try{$this->attempts?->finish($attemptId,'failed',errorCode:'provider_unavailable');}catch(Throwable){}
                $lastError=$error;
            }
        }
        throw $lastError??new \RuntimeException('provider_unavailable');
    }

    private function uuid(array $payload,string $field):string
    {
        $value=$payload[$field]??null;
        if(!is_string($value)||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value)!==1)
            throw new \InvalidArgumentException('invalid_'.$field);
        return $value;
    }
}
