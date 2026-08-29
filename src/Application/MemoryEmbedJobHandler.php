<?php
declare(strict_types=1);

namespace LORKHANserver\Application;

use LORKHANserver\Infrastructure\MemoryEmbeddingRepository;
use LORKHANserver\Infrastructure\ProviderAttemptRepository;
use LORKHANserver\Infrastructure\Uuid;
use InvalidArgumentException;
use Throwable;

/** Generate one optional semantic projection outside database write transactions. */
final class MemoryEmbedJobHandler implements JobHandler
{
    public const TYPE='memory.embed';

    public function __construct(private readonly MemoryEmbeddingRepository $memories,
        private readonly ProviderAttemptRepository $attempts,private readonly ?EmbeddingProvider $testProvider=null) {}

    public function supports(string $jobType,int $schemaVersion):bool
    {
        return$jobType===self::TYPE&&$schemaVersion===1;
    }

    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        unset($idempotencyKey);
        foreach(['installation_id','profile_id','playthrough_id','memory_id','policy_configuration_id']as$key){
            if(!is_string($payload[$key]??null)
                ||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$payload[$key])!==1)
                throw new InvalidArgumentException('invalid_memory_embedding_scope');
        }
        foreach(['memory_revision','policy_revision']as$key)
            if(!is_int($payload[$key]??null)||$payload[$key]<1)
                throw new InvalidArgumentException('invalid_memory_embedding_revision');
        if(!is_string($payload['input_sha256']??null)
            ||preg_match('/^[0-9a-f]{64}$/D',$payload['input_sha256'])!==1)
            throw new InvalidArgumentException('invalid_memory_embedding_hash');
        $job=$payload['_job']??null;
        if(!is_array($job)||!is_string($job['job_id']??null)||!is_string($job['lease_token']??null)
            ||!is_int($job['attempt']??null)||$job['attempt']<1)throw new InvalidArgumentException('invalid_job_fence');
        if(!$heartbeat())throw new OperationCancelled('lease_lost');
        $input=$this->memories->input($payload);if($input===null)return;
        $policy=$input['policy_content'];
        $provider=$this->testProvider??new MiniMeEmbeddingProvider($policy['endpoint'],$policy['timeout_ms']);
        $deadline=hrtime(true)+$policy['timeout_ms']*1000000;$lastCheck=0;
        $token=new CallbackCancellationToken(function()use(&$lastCheck,$deadline,$heartbeat,$payload):bool{
            $now=hrtime(true);if($now>=$deadline)return true;
            if($now-$lastCheck<250000000)return false;$lastCheck=$now;
            return!$heartbeat()||$this->memories->input($payload)===null;
        });
        $attempt=Uuid::v4();
        $this->attempts->start($attempt,'embedding','minime','embed_memory',$job['attempt'],jobId:$job['job_id'],
            model:$provider->model(),configRevision:(string)$payload['policy_revision'],
            inputBytes:strlen($input['content']),metadata:['memory_id'=>$payload['memory_id'],
                'memory_revision'=>$payload['memory_revision'],'policy_configuration_id'=>$payload['policy_configuration_id']]);
        try{
            $embedding=$provider->embed($input['content'],$token);$token->throwIfCancellationRequested();
            $saved=$this->memories->save($payload,$embedding,$provider->model(),gmdate('Y-m-d\TH:i:s\Z'));
            $this->attempts->finish($attempt,$saved?'succeeded':'cancelled',
                strlen(json_encode($embedding,JSON_THROW_ON_ERROR)));
        }catch(OperationCancelled$error){
            $this->attempts->finish($attempt,'cancelled',errorCode:'operation_cancelled');
            if($this->memories->input($payload)!==null)throw$error;
        }catch(Throwable$error){
            try{$this->attempts->finish($attempt,'failed',errorCode:'provider_unavailable');}catch(Throwable){}
            throw$error;
        }
    }
}
