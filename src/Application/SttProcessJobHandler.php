<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Infrastructure\MediaStore;
use ALMSIVIserver\Infrastructure\ProviderAttemptRepository;
use ALMSIVIserver\Infrastructure\Repository;
use ALMSIVIserver\Infrastructure\Uuid;
use Throwable;

final class SttProcessJobHandler implements JobHandler
{
    public const TYPE='stt.process';
    public function __construct(private readonly Repository $repository,private readonly SpeechToTextProvider $provider,
        private readonly MediaStore $media,private readonly ProviderAttemptRepository $attempts){}
    public function supports(string $jobType,int $schemaVersion):bool{return $jobType===self::TYPE&&$schemaVersion===1;}
    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        $messageId=$payload['message_id']??null;$job=$payload['_job']??null;
        if(!is_string($messageId)||!is_array($job)||!is_int($job['attempt']??null))throw new \InvalidArgumentException('invalid_stt_job');
        if(!$heartbeat())throw new \RuntimeException('lease_lost');
        if(!is_string($job['job_id']??null)||!is_string($job['lease_token']??null))throw new \InvalidArgumentException('invalid_stt_job');
        $fence=['job_id'=>$job['job_id'],'lease_token'=>$job['lease_token'],'attempt'=>$job['attempt']];
        $request=$this->repository->claimStt($messageId,$job['job_id'],$job['lease_token'],$job['attempt']);
        if($request===null)return;
        $attempt=Uuid::v4();
        try{
            $bytes=$this->media->read($request['storage_media_id'],(int)$request['audio_bytes'],(string)$request['sha256']);
            $providerName=$this->provider instanceof OpenAiCompatibleSpeechToTextProvider?'openai-compatible':'mock';
            $this->attempts->start($attempt,'stt',$providerName,'transcribe',$job['attempt'],$request['request_id'],$request['turn_id'],$job['job_id'],inputBytes:strlen($bytes));
            $result=$this->provider->transcribe($bytes,$request['codec'],$request['language'],new CallbackCancellationToken(fn():bool=>!$heartbeat()));
            $this->repository->completeStt($messageId,$result,$fence);
            $this->media->delete($request['storage_media_id']);
            $this->attempts->finish($attempt,'succeeded',strlen($result['text']));
        }catch(Throwable $error){
            $code=$error->getMessage()==='invalid_audio'?'invalid_audio':'provider_unavailable';
            try{$this->attempts->finish($attempt,'failed',errorCode:$code);}catch(Throwable){}
            if($code==='invalid_audio'||$job['attempt']>=3){$this->repository->failStt($messageId,$code,$fence);$this->media->delete($request['storage_media_id']);return;}
            throw new \RuntimeException('stt_transient');
        }
    }
}
