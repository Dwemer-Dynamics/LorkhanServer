<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\RelationshipEvaluationRepository;
use LorkhanServer\Infrastructure\Uuid;

/** Optional response-driven evaluation with frozen connector selection and checked writes. */
final class RelationshipEvaluateJobHandler implements JobHandler
{
    public const TYPE='relationship.evaluate';

    public function __construct(private readonly RelationshipEvaluationRepository $relationships,
        private readonly ProductRepository $products,private readonly ProviderAttemptRepository $attempts,
        private readonly array $providerConfig=[],private readonly ?ProfileGenerationProvider $testProvider=null) {}

    public function supports(string $jobType,int $schemaVersion):bool
    {
        return $jobType===self::TYPE&&$schemaVersion===1;
    }

    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        unset($idempotencyKey);
        foreach(['installation_id','profile_id','playthrough_id','session_id','turn_id','source_event_id','provider_configuration_id']as$field){
            if(!is_string($payload[$field]??null)||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$payload[$field])!==1)
                throw new \InvalidArgumentException('invalid_relationship_job');
        }
        foreach(['profile_revision','provider_revision']as$field)
            if(!is_int($payload[$field]??null)||$payload[$field]<1)throw new \InvalidArgumentException('invalid_relationship_job');
        if(isset($payload['player2_policy_revision'])&&(!is_int($payload['player2_policy_revision'])||$payload['player2_policy_revision']<0))
            throw new \InvalidArgumentException('invalid_relationship_job');
        if(!is_int($payload['update_chance_percent']??null)||$payload['update_chance_percent']<1||$payload['update_chance_percent']>100
            ||($payload['locked']??null)!==false)throw new \InvalidArgumentException('invalid_relationship_job');
        if(!array_key_exists('core_profile_id',$payload)||!array_key_exists('core_profile_revision',$payload)
            ||($payload['core_profile_id']===null? $payload['core_profile_revision']!==null:
                (!is_string($payload['core_profile_id'])||preg_match('/^[0-9a-f-]{36}$/D',$payload['core_profile_id'])!==1
                    ||!is_int($payload['core_profile_revision'])||$payload['core_profile_revision']<1)))
            throw new \InvalidArgumentException('invalid_relationship_job');
        if(!is_string($payload['relationship_fence']??null)||preg_match('/^[0-9a-f]{64}$/D',$payload['relationship_fence'])!==1)
            throw new \InvalidArgumentException('invalid_relationship_job');
        $job=$payload['_job']??null;
        if(!is_array($job)||!is_string($job['job_id']??null)||!is_string($job['lease_token']??null)
            ||!is_int($job['attempt']??null)||$job['attempt']<1)throw new \InvalidArgumentException('invalid_job_fence');
        if(!$heartbeat())throw new OperationCancelled('lease_lost');
        $input=$this->relationships->input($payload);if($input===null)return;
        $slot=$this->products->providerRevisionForInstallation($payload['installation_id'],$payload['provider_configuration_id'],$payload['provider_revision']);
        $provider=$this->testProvider??ProviderFactory::profileGenerationForSlot($this->providerConfig,$slot);
        $deadline=hrtime(true)+max(1000,min(120000,(int)($slot['content']['timeout_ms']??30000)))*1000000;
        $lastCheck=0;
        $token=new CallbackCancellationToken(function()use(&$lastCheck,$deadline,$heartbeat,$payload):bool{
            $now=hrtime(true);if($now>=$deadline)return true;
            if($now-$lastCheck<500000000)return false;$lastCheck=$now;
            return !$heartbeat()||$this->relationships->input($payload)===null;
        });
        $attempt=Uuid::v4();
        $this->attempts->start($attempt,'llm',$provider instanceof OpenAiCompatibleProfileGenerationProvider?'openai-compatible':'mock',
            'evaluate_relationship',$job['attempt'],jobId:$job['job_id'],model:(string)$slot['content']['model'],
            configRevision:(string)$payload['provider_revision'],inputBytes:strlen(json_encode($input['model'],JSON_THROW_ON_ERROR)),
            metadata:['source_event_id'=>$payload['source_event_id'],'provider_configuration_id'=>$payload['provider_configuration_id']]);
        try{
            $this->attempts->recordRelationshipRequest($attempt,[['role'=>'user','content'=>json_encode($input['model'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]],false);
            $output=RelationshipEvaluationPolicy::output($provider instanceof OpenAiCompatibleProfileGenerationProvider
                ?$provider->generate($input['model'],$token,fn(array $messages)=>$this->attempts->recordRelationshipRequest($attempt,$messages))
                :$provider->generate($input['model'],$token));
            $this->attempts->recordRelationshipProposal($attempt,$output);
            $token->throwIfCancellationRequested();
            $saved=$this->relationships->save($payload,$output,gmdate('Y-m-d\TH:i:s\Z'),$attempt);
            $this->attempts->finish($attempt,$saved?'succeeded':'cancelled',strlen(json_encode($output,JSON_THROW_ON_ERROR)));
        }catch(OperationCancelled $error){
            $this->attempts->finish($attempt,'cancelled',errorCode:'operation_cancelled');
            if($this->relationships->input($payload)!==null)throw $error;
        }catch(\Throwable $error){
            try{$this->attempts->finish($attempt,'failed',errorCode:'provider_unavailable');}catch(\Throwable){}
            throw $error;
        }
    }
}
