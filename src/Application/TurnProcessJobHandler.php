<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Infrastructure\MediaStore;
use ALMSIVIserver\Infrastructure\ProviderAttemptRepository;
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
        $attemptId = Uuid::v4();
        $stagedMedia=[];
        try {
            $providerName = $this->provider instanceof OpenAiCompatibleProvider ? 'openai-compatible' : 'mock';
            $this->attempts?->start($attemptId, 'llm', $providerName, 'complete_turn', $job['attempt'],
                $message['request_id'], $turnId, $job['job_id'], inputBytes: strlen($message['payload']['input']['text']),
                metadata: ['mode' => $providerName, 'job' => true]);
            $result = $this->provider->complete($message, $token);
            $utterances = (new DialoguePlanner())->plan($message, $result);
            $speech = [];
            if ($this->speechProvider !== null && $this->mediaStore !== null
                && in_array('speech.say', $message['_negotiated_capabilities'], true)) {
                foreach ($utterances as $index => $utterance) {
                    $token->throwIfCancellationRequested();
                    $speechAttempt = Uuid::v4();
                    $ttsAttempt=(($job['attempt']-1)*4)+$index+1;
                    $speechProviderName = $this->speechProvider instanceof OpenAiCompatibleSpeechProvider
                        ? 'openai-compatible' : 'mock';
                    $this->attempts?->start($speechAttempt,'tts',$speechProviderName,'synthesize',$ttsAttempt,$message['request_id'],$turnId,$job['job_id'],
                        inputBytes:strlen($utterance['text']),metadata:['mode'=>$speechProviderName,'job'=>true,'utterance_index'=>$index+1]);
                    $generated = $this->speechProvider->synthesize($utterance['text'], $token);
                    $this->attempts?->finish($speechAttempt,'succeeded',strlen($generated['bytes']));
                    $mediaId = Uuid::v4();
                    $sha = $this->mediaStore->put($mediaId, $generated['bytes'], $generated['codec'], $generated['mime_type']);
                    $stagedMedia[]=$mediaId;
                    $speech[] = ['media_id'=>$mediaId,'sha256'=>$sha,'bytes'=>strlen($generated['bytes']),'codec'=>$generated['codec'],
                        'mime_type'=>$generated['mime_type'],'duration_ms'=>$generated['duration_ms'],
                        'expires_at'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z')];
                }
            }
            $token->throwIfCancellationRequested();
            $this->repository->completeTurn($message, $result, $speech, $fence);
            $stagedMedia=[];
            $bytes = array_sum(array_map(static fn(array $u):int => strlen($u['text']), $utterances));
            $this->attempts?->finish($attemptId, 'succeeded', $bytes);
        } catch (OperationCancelled) {
            foreach($stagedMedia as $mediaId)$this->mediaStore?->delete($mediaId);
            $this->attempts?->finish($attemptId, 'cancelled', errorCode: 'operation_cancelled');
            if (!$this->repository->isTurnCancellationRequested($sessionId, $turnId, $generation)) {
                $this->repository->failTurn($message, 'provider_timeout', $fence);
            }
            return;
        } catch (Throwable $error) {
            foreach($stagedMedia as $mediaId)$this->mediaStore?->delete($mediaId);
            try {$this->attempts?->finish($attemptId, 'failed', errorCode: 'provider_unavailable');} catch (Throwable) {}
            // Persisting the terminal failure is the successful handling of this turn job. Retrying
            // the provider after exposing turn.failed would contradict the terminal protocol state.
            $this->repository->failTurn($message, 'provider_unavailable', $fence);
            return;
        }
    }

    private function uuid(array $payload,string $field):string
    {
        $value=$payload[$field]??null;
        if(!is_string($value)||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value)!==1)
            throw new \InvalidArgumentException('invalid_'.$field);
        return $value;
    }
}
