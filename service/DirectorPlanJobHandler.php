<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\DirectorPlanningRepository;
use LorkhanServer\Infrastructure\DirectorRoutingRepository;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\Uuid;
use RuntimeException;

/** Plan a scene once, then deliver instructions for fresh client-observed child turns. */
final class DirectorPlanJobHandler implements JobHandler
{
    public function __construct(private readonly DirectorPlanningRepository $plans,private readonly DirectorRoutingRepository $routes,
        private readonly ProductRepository $products,private readonly ProviderAttemptRepository $attempts,
        private readonly array $config=[],private readonly ?ProfileGenerationProvider $testProvider=null) {}

    public function supports(string $jobType,int $schemaVersion):bool{return $jobType==='director.plan'&&$schemaVersion===1;}

    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        try{$this->run($payload,$idempotencyKey,$heartbeat);}
        catch(\Throwable $error){
            $job=$payload['_job']??[];
            if(is_string($job['job_id']??null)&&Uuid::isValid($job['job_id'])&&is_int($job['attempt']??null)
                &&is_string($job['lease_token']??null)&&Uuid::isValid($job['lease_token'])){
                $this->plans->fail($job['job_id'],$job['attempt'],$job['lease_token']);
            }
            throw $error;
        }
    }

    private function run(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        $job=$payload['_job']??null;
        foreach(['installation_id','provider_configuration_id'] as $field)
            if(!is_string($payload[$field]??null)||!Uuid::isValid($payload[$field]))throw new RuntimeException('invalid_director_job');
        if(!is_array($job)||!is_string($job['job_id']??null)||!Uuid::isValid($job['job_id'])
            ||!is_string($job['lease_token']??null)||!Uuid::isValid($job['lease_token'])||!is_int($job['attempt']??null)
            ||!is_int($payload['provider_revision']??null))throw new RuntimeException('invalid_director_job');
        if(!$heartbeat())throw new OperationCancelled('lease_lost');
        $route=$this->routes->route($payload['installation_id']);
        if(!$route||$route['configuration_id']!==$payload['provider_configuration_id'])throw new RuntimeException('director_connector_disabled');
        $input=$this->plans->input($job['job_id']);
        if(($input['_delivered']??false)===true)return;
        $slot=$this->products->providerRevisionForInstallation($payload['installation_id'],$payload['provider_configuration_id'],$payload['provider_revision']);
        $provider=$this->testProvider??ProviderFactory::profileGenerationForSlot($this->config,$slot);
        $deadline=hrtime(true)+max(1000,min(120000,(int)($slot['content']['timeout_ms']??30000)))*1000000;$last=0;
        $token=new CallbackCancellationToken(function()use(&$last,$deadline,$heartbeat,$job):bool{
            $now=hrtime(true);if($now>=$deadline)return true;if($now-$last<100000000)return false;$last=$now;
            if(!$heartbeat())return true;
            try{$this->plans->input($job['job_id']);return false;}catch(\Throwable){return true;}
        });
        $attempt=Uuid::v4();
        $this->attempts->start($attempt,'llm',$provider instanceof OpenAiCompatibleProfileGenerationProvider?'openai-compatible':'mock',
            'director_plan',$job['attempt'],jobId:$job['job_id'],model:(string)($slot['content']['model']??''),
            configRevision:(string)$payload['provider_revision'],inputBytes:strlen(json_encode($input,JSON_THROW_ON_ERROR)));
        try{
            $output=$provider->generate(['generation_mode'=>'director_plan']+$input,$token);$token->throwIfCancellationRequested();
            $route=$this->routes->route($payload['installation_id']);
            if(!$route||$route['configuration_id']!==$payload['provider_configuration_id'])throw new OperationCancelled('director_connector_disabled');
            $this->plans->deliver($job['job_id'],$job['attempt'],$job['lease_token'],DirectorPolicy::output($output,$input['actors']));
            $this->attempts->finish($attempt,'succeeded',strlen(json_encode($output,JSON_THROW_ON_ERROR)));
        }catch(\Throwable $error){
            try{$this->attempts->finish($attempt,'failed',errorCode:$error instanceof OperationCancelled?'operation_cancelled':'provider_unavailable');}catch(\Throwable){}
            throw $error;
        }
    }
}
