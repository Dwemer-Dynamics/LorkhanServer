<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\SceneClassificationRepository;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\Uuid;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Classify frozen dialogue after its response completes, without issuing game actions. */
final class SceneClassifyJobHandler implements JobHandler
{
    public const TYPE='scene.classify';
    public function __construct(private readonly SceneClassificationRepository $scenes,private readonly ProductRepository $products,
        private readonly ProviderAttemptRepository $attempts,private readonly array $config=[],private readonly ?ProfileGenerationProvider $testProvider=null){}
    public function supports(string $jobType,int $schemaVersion):bool{return $jobType===self::TYPE&&$schemaVersion===1;}
    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        unset($idempotencyKey);
        foreach(['installation_id','profile_id','provider_configuration_id'] as $key)
            if(!is_string($payload[$key]??null)||!($key === 'profile_id' ? \LorkhanServer\Domain\ProfileId::isValid($payload[$key]) : Uuid::isValid($payload[$key])))throw new InvalidArgumentException('invalid_scene_scope');
        $job=$payload['_job']??null;
        if(!is_int($payload['provider_revision']??null)||$payload['provider_revision']<1||!is_array($job)
            ||!is_string($job['job_id']??null)||!Uuid::isValid($job['job_id'])||!is_int($job['attempt']??null)||!is_string($job['lease_token']??null))throw new InvalidArgumentException('invalid_scene_job');
        if(!$heartbeat())throw new OperationCancelled('lease_lost');
        if($this->scenes->route($payload['installation_id'])===null)throw new RuntimeException('scene_classifier_unavailable');
        $input=$this->scenes->input($payload['installation_id'],$payload['profile_id'],$job['job_id']);
        $slot=$this->products->providerRevisionForInstallation($payload['installation_id'],$payload['provider_configuration_id'],$payload['provider_revision']);
        $provider=$this->testProvider??ProviderFactory::profileGenerationForSlot($this->config,$slot);
        $deadline=hrtime(true)+max(1000,min(120000,(int)($slot['content']['timeout_ms']??30000)))*1000000;$last=0;
        $token=new CallbackCancellationToken(function()use(&$last,$deadline,$heartbeat):bool{$now=hrtime(true);if($now>=$deadline)return true;if($now-$last<100000000)return false;$last=$now;return !$heartbeat();});
        $attempt=Uuid::v4();$this->attempts->start($attempt,'llm',$provider instanceof OpenAiCompatibleProfileGenerationProvider?'openai-compatible':'mock','scene_classification',$job['attempt'],jobId:$job['job_id'],model:(string)$slot['content']['model'],configRevision:(string)$payload['provider_revision'],inputBytes:strlen(json_encode($input,JSON_THROW_ON_ERROR)),metadata:['profile_id'=>$payload['profile_id']]);
        try{
            $output=$provider->generate(['generation_mode'=>'scene_classification']+$input,$token);$token->throwIfCancellationRequested();
            $output=SceneClassificationPolicy::output($output);
            $this->scenes->save($job['job_id'],$job['attempt'],$job['lease_token'],$output['genre']);
            $this->attempts->finish($attempt,'succeeded',strlen($output['genre']));
        }catch(Throwable $error){try{$this->attempts->finish($attempt,'failed',errorCode:$error instanceof OperationCancelled?'operation_cancelled':'provider_unavailable');}catch(Throwable){}throw $error;}
    }
}
