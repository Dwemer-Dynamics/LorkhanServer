<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

use LORKHANserver\Infrastructure\MemorySummaryRepository;
use LORKHANserver\Infrastructure\ProductRepository;
use LORKHANserver\Infrastructure\ProviderAttemptRepository;
use LORKHANserver\Infrastructure\Uuid;
use InvalidArgumentException;
use Throwable;

/** Optional event-driven projection of one frozen deterministic memory revision. */
final class MemorySummaryJobHandler implements JobHandler
{
    public const TYPE='memory.summarize';

    public function __construct(private readonly MemorySummaryRepository $memories,
        private readonly ProductRepository $products,private readonly ProviderAttemptRepository $attempts,
        private readonly array $providerConfig=[],private readonly ?ProfileGenerationProvider $testProvider=null) {}

    public function supports(string $jobType,int $schemaVersion):bool
    {
        return $jobType===self::TYPE&&$schemaVersion===1;
    }

    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        unset($idempotencyKey);
        foreach(['installation_id','profile_id','playthrough_id','memory_id','policy_configuration_id','provider_configuration_id']as$key){
            if(!is_string($payload[$key]??null)||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$payload[$key])!==1)
                throw new InvalidArgumentException('invalid_memory_job_scope');
        }
        foreach(['memory_revision','policy_revision','provider_revision']as$key)
            if(!is_int($payload[$key]??null)||$payload[$key]<1)throw new InvalidArgumentException('invalid_memory_job_revision');
        $job=$payload['_job']??null;
        if(!is_array($job)||!is_string($job['job_id']??null)||!is_string($job['lease_token']??null)
            ||!is_int($job['attempt']??null)||$job['attempt']<1)throw new InvalidArgumentException('invalid_job_fence');
        if(!$heartbeat())throw new OperationCancelled('lease_lost');
        $input=$this->memories->input($payload);if($input===null)return;
        $slot=$this->products->providerRevisionForInstallation($payload['installation_id'],
            $payload['provider_configuration_id'],$payload['provider_revision']);
        // Reuse the strict JSON text-generation transport with its dedicated memory output contract.
        $provider=$this->testProvider??ProviderFactory::profileGenerationForSlot($this->providerConfig,$slot);
        $deadline=hrtime(true)+max(1000,min(120000,(int)($slot['content']['timeout_ms']??30000)))*1000000;
        $lastCheck=0;
        $token=new CallbackCancellationToken(function()use(&$lastCheck,$deadline,$heartbeat,$payload):bool{
            $now=hrtime(true);if($now>=$deadline)return true;
            if($now-$lastCheck<500000000)return false;$lastCheck=$now;
            return !$heartbeat()||$this->memories->input($payload)===null;
        });
        $attempt=Uuid::v4();
        $this->attempts->start($attempt,'llm',$provider instanceof OpenAiCompatibleProfileGenerationProvider?'openai-compatible':'mock',
            'summarize_memory',$job['attempt'],jobId:$job['job_id'],model:(string)$slot['content']['model'],
            configRevision:(string)$payload['provider_revision'],inputBytes:strlen($input['content']),
            metadata:['memory_id'=>$payload['memory_id'],'memory_revision'=>$payload['memory_revision'],
                'provider_configuration_id'=>$payload['provider_configuration_id']]);
        try{
            $output=$provider->generate(['generation_mode'=>'memory_summary','memory'=>$input['content'],'tier'=>$input['tier']],$token);
            $token->throwIfCancellationRequested();
            $summary=MemorySummaryPolicy::summary($output);
            $saved=$this->memories->save($payload,hash('sha256',$input['content']),$summary,gmdate('Y-m-d\TH:i:s\Z'));
            $this->attempts->finish($attempt,$saved?'succeeded':'cancelled',strlen($summary));
        }catch(OperationCancelled $error){
            $this->attempts->finish($attempt,'cancelled',errorCode:'operation_cancelled');
            // Disabled/stale work is terminal; only a lost lease is left to the worker's lease fence.
            if($this->memories->input($payload)!==null)throw $error;
        }catch(Throwable $error){
            try{$this->attempts->finish($attempt,'failed',errorCode:'provider_unavailable');}catch(Throwable){}
            throw $error;
        }
    }
}
