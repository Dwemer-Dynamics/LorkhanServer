<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\RelationshipBuildRepository;
use LorkhanServer\Infrastructure\Uuid;

/** One explicit, bounded history analysis with no external I/O inside the write transaction. */
final class RelationshipBuildJobHandler implements JobHandler
{
    public const TYPE='relationship.build';
    public function __construct(private readonly RelationshipBuildRepository $builds,
        private readonly ProductRepository $products,private readonly ProviderAttemptRepository $attempts,
        private readonly array $providerConfig=[],private readonly ?ProfileGenerationProvider $testProvider=null) {}

    public function supports(string $jobType,int $schemaVersion):bool
    {
        return $jobType===self::TYPE&&$schemaVersion===1;
    }

    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        foreach(['installation_id','profile_id','playthrough_id','request_id','provider_configuration_id'] as $field)
            if(!is_string($payload[$field]??null)||!Uuid::isValid($payload[$field]))
                throw new \InvalidArgumentException('invalid_relationship_build_job');
        if($idempotencyKey!=='relationship.build:'.$payload['request_id']||!is_int($payload['provider_revision']??null)
            ||$payload['provider_revision']<1||!is_array($payload['source_ids']??null)||!array_is_list($payload['source_ids'])
            ||count($payload['source_ids'])<1||count($payload['source_ids'])>100||!is_array($payload['targets']??null)
            ||count($payload['targets'])<1||count($payload['targets'])>20||!is_array($payload['source_hashes']??null))
            throw new \InvalidArgumentException('invalid_relationship_build_job');
        foreach($payload['source_ids'] as $id)if(!is_string($id)||!Uuid::isValid($id))
            throw new \InvalidArgumentException('invalid_relationship_build_job');
        $job=$payload['_job']??[];
        foreach(['job_id','lease_token'] as $field)if(!is_string($job[$field]??null)||!Uuid::isValid($job[$field]))
            throw new \InvalidArgumentException('invalid_job_fence');
        if(!is_int($job['attempt']??null)||$job['attempt']<1)throw new \InvalidArgumentException('invalid_job_fence');
        if(!$heartbeat())throw new OperationCancelled('lease_lost');
        $input=$this->builds->input($payload);if($input===null)return;
        $slot=$this->products->providerRevisionForInstallation($payload['installation_id'],$payload['provider_configuration_id'],$payload['provider_revision']);
        $provider=$this->testProvider??ProviderFactory::profileGenerationForSlot($this->providerConfig,$slot);
        $deadline=hrtime(true)+max(1000,min(120000,(int)($slot['content']['timeout_ms']??30000)))*1000000;
        $lastCheck=0;
        $token=new CallbackCancellationToken(function()use(&$lastCheck,$deadline,$heartbeat,$payload):bool{
            $now=hrtime(true);if($now>=$deadline)return true;
            if($now-$lastCheck<500000000)return false;$lastCheck=$now;
            return !$heartbeat()||!$this->builds->current($payload);
        });
        $attempt=Uuid::v4();
        $this->attempts->start($attempt,'llm',$provider instanceof OpenAiCompatibleProfileGenerationProvider?'openai-compatible':'mock',
            'build_relationships',$job['attempt'],jobId:$job['job_id'],model:(string)$slot['content']['model'],
            configRevision:(string)$payload['provider_revision'],inputBytes:strlen(json_encode($input['model'],JSON_THROW_ON_ERROR)),
            metadata:['source_count'=>count($payload['source_ids']),'provider_configuration_id'=>$payload['provider_configuration_id']]);
        try{
            $output=RelationshipBuildPolicy::output($provider->generate($input['model'],$token));
            $token->throwIfCancellationRequested();
            $saved=$this->builds->save($payload,$output,gmdate('Y-m-d\TH:i:s\Z'));
            $this->attempts->finish($attempt,$saved?'succeeded':'cancelled',strlen(json_encode($output,JSON_THROW_ON_ERROR)));
        }catch(OperationCancelled $error){
            $this->attempts->finish($attempt,'cancelled',errorCode:'operation_cancelled');
            if($this->builds->current($payload))throw $error;
        }catch(\Throwable $error){
            try{$this->attempts->finish($attempt,'failed',errorCode:'provider_unavailable');}catch(\Throwable){}
            throw $error;
        }
    }
}
