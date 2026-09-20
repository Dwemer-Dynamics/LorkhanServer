<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\MediaStore;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\Repository;
use LorkhanServer\Infrastructure\Uuid;
use Throwable;
use LorkhanServer\Infrastructure\Logger;

final class SttProcessJobHandler implements JobHandler
{
    public const TYPE='stt.process';
    public function __construct(private readonly Repository $repository,private readonly ?SpeechToTextProvider $provider,
        private readonly MediaStore $media,private readonly ProviderAttemptRepository $attempts,
        private readonly ?ProductRepository $products=null,private readonly array $providerConfig=[] ){}
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
        $attempt=Uuid::v4();$started=hrtime(true);
        try{
            $bytes=$this->media->read($request['storage_media_id'],(int)$request['audio_bytes'],(string)$request['sha256']);
            $provider=$this->provider;$preset=$this->products?->connectorForSession((string)$request['session_id'],'stt_provider');
            if($provider===null&&$preset!==null)$provider=ProviderFactory::speechToTextForPreset($this->providerConfig,$preset);
            if($provider===null)throw new \RuntimeException('provider_unavailable');
            $providerName=is_array($preset['content']??null)?(string)($preset['content']['driver']??'stt'):
                ($provider instanceof OpenAiCompatibleSpeechToTextProvider?'openai-compatible':'mock');
            Logger::info(sprintf('STT job starting: request_id=%s job_id=%s attempt=%d provider=%s audio_bytes=%d codec=%s language=%s',
                (string)$request['request_id'],(string)$job['job_id'],(int)$job['attempt'],$providerName,strlen($bytes),
                (string)$request['codec'],(string)$request['language']), 'stt.log');
            $this->attempts->start($attempt,'stt',$providerName,'transcribe',$job['attempt'],$request['request_id'],$request['turn_id'],$job['job_id'],inputBytes:strlen($bytes));
            $result=$provider->transcribe($bytes,$request['codec'],$request['language'],new CallbackCancellationToken(fn():bool=>!$heartbeat()));
            $this->repository->completeStt($messageId,$result,$fence);
            $this->media->delete($request['storage_media_id']);
            $this->attempts->finish($attempt,'succeeded',strlen($result['text']));
            Logger::info(sprintf('STT job succeeded: request_id=%s job_id=%s transcript_chars=%d language=%s elapsed_ms=%d',
                (string)$request['request_id'],(string)$job['job_id'],mb_strlen($result['text']),(string)$result['language'],
                (int)((hrtime(true)-$started)/1_000_000)).' Transcript: '.$result['text'], 'stt.log');
        }catch(Throwable $error){
            $code=in_array($error->getMessage(),['invalid_audio','provider_invalid_output','provider_timeout','provider_unavailable'],true)
                ?$error->getMessage():'provider_unavailable';
            try{$this->attempts->finish($attempt,'failed',errorCode:$code);}catch(Throwable){}
            Logger::error(sprintf('STT job failed: request_id=%s job_id=%s attempt=%d code=%s exception=%s',
                (string)($request['request_id']??'unknown'),(string)($job['job_id']??'unknown'),(int)($job['attempt']??0),
                $code,$error::class), 'stt.log');
            if(in_array($code,['invalid_audio','provider_invalid_output'],true)||$job['attempt']>=3){$this->repository->failStt($messageId,$code,$fence);$this->media->delete($request['storage_media_id']);return;}
            throw new \RuntimeException('stt_transient');
        }
    }
}
