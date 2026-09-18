<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\NpcEvolutionReportRepository;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\Uuid;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Summarize frozen NPC personality history without revising the NPC or issuing game actions. */
final class NpcEvolutionReportJobHandler implements JobHandler
{
    public const TYPE='profile.report';
    public function __construct(private readonly NpcEvolutionReportRepository $reports,private readonly ProductRepository $products,
        private readonly ProviderAttemptRepository $attempts,private readonly array $config=[],private readonly ?ProfileGenerationProvider $testProvider=null){}
    public function supports(string $jobType,int $schemaVersion):bool{return $jobType===self::TYPE&&$schemaVersion===1;}
    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        unset($idempotencyKey);
        foreach(['installation_id','profile_id','provider_configuration_id'] as $key)
            if(!is_string($payload[$key]??null)||!($key === 'profile_id' ? \LorkhanServer\Domain\ProfileId::isValid($payload[$key]) : Uuid::isValid($payload[$key])))throw new InvalidArgumentException('invalid_report_scope');
        $job=$payload['_job']??null;
        if(!is_int($payload['provider_revision']??null)||$payload['provider_revision']<1||!is_array($job)
            ||!is_string($job['job_id']??null)||!Uuid::isValid($job['job_id'])||!is_int($job['attempt']??null)||!is_string($job['lease_token']??null))throw new InvalidArgumentException('invalid_report_job');
        if(!$heartbeat())throw new OperationCancelled('lease_lost');
        $globals=$this->products->globalSettingsForInstallation($payload['installation_id'])['content']??[];
        if(($globals['task_availability']['background_memory']??true)!==true)throw new RuntimeException('report_connector_disabled');
        $route=(string)($globals['system_routing']['background_memory_configuration_id']??'');
        if($route==='')throw new RuntimeException('report_connector_disabled');
        $input=$this->reports->input($payload['installation_id'],$payload['profile_id'],$job['job_id']);
        $slot=$this->products->providerRevisionForInstallation($payload['installation_id'],$payload['provider_configuration_id'],$payload['provider_revision']);
        $provider=$this->testProvider??ProviderFactory::profileGenerationForSlot($this->config,$slot);
        $deadline=hrtime(true)+max(1000,min(120000,(int)($slot['content']['timeout_ms']??30000)))*1000000;$last=0;
        $token=new CallbackCancellationToken(function()use(&$last,$deadline,$heartbeat):bool{$now=hrtime(true);if($now>=$deadline)return true;if($now-$last<100000000)return false;$last=$now;return !$heartbeat();});
        $attempt=Uuid::v4();$this->attempts->start($attempt,'llm',$provider instanceof OpenAiCompatibleProfileGenerationProvider?'openai-compatible':'mock','npc_evolution_report',$job['attempt'],jobId:$job['job_id'],model:(string)$slot['content']['model'],configRevision:(string)$payload['provider_revision'],inputBytes:strlen(json_encode($input,JSON_THROW_ON_ERROR)),metadata:['profile_id'=>$payload['profile_id']]);
        try{
            $output=$provider->generate(['generation_mode'=>'npc_evolution_report']+$input,$token);$token->throwIfCancellationRequested();
            if(array_keys($output)!==['report']||!is_string($output['report']))throw new RuntimeException('provider_invalid_output');
            $this->reports->save($job['job_id'],$job['attempt'],$job['lease_token'],$output['report']);
            $this->attempts->finish($attempt,'succeeded',strlen($output['report']));
        }catch(Throwable $error){try{$this->attempts->finish($attempt,'failed',errorCode:$error instanceof OperationCancelled?'operation_cancelled':'provider_unavailable');}catch(Throwable){}throw $error;}
    }
}
