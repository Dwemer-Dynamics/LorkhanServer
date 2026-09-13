<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\{NpcMemoryDigestRepository,ProductRepository,ProviderAttemptRepository,Uuid};
use RuntimeException;
use Throwable;

/** Extend NPC canon from frozen witnessed scenes; never overwrite source memories. */
final class MemoryDigestJobHandler implements JobHandler
{
    public const TYPE='memory.digest';
    public function __construct(private readonly NpcMemoryDigestRepository $digests,private readonly ProductRepository $products,
        private readonly ProviderAttemptRepository $attempts,private readonly array $config=[],private readonly ?ProfileGenerationProvider $testProvider=null){}
    public function supports(string $type,int $version):bool{return $type===self::TYPE&&$version===1;}
    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        unset($idempotencyKey);if(!$heartbeat())throw new OperationCancelled('lease_lost');
        $input=$this->digests->input($payload);if($input===null)return;
        $slot=$this->products->providerRevisionForInstallation($payload['installation_id'],$payload['provider_configuration_id'],$payload['provider_revision']);
        $provider=$this->testProvider??ProviderFactory::profileGenerationForSlot($this->config,$slot);
        $deadline=hrtime(true)+max(1000,min(120000,(int)($slot['content']['timeout_ms']??30000)))*1000000;$last=0;
        $token=new CallbackCancellationToken(function()use(&$last,$deadline,$heartbeat):bool{$now=hrtime(true);if($now>=$deadline)return true;if($now-$last<500000000)return false;$last=$now;return !$heartbeat();});
        $job=$payload['_job'];$attempt=Uuid::v4();$this->attempts->start($attempt,'llm',$provider instanceof OpenAiCompatibleProfileGenerationProvider?'openai-compatible':'mock',
            'memory_digest',$job['attempt'],jobId:$job['job_id'],model:(string)$slot['content']['model'],configRevision:(string)$payload['provider_revision'],
            inputBytes:strlen(json_encode($input,JSON_THROW_ON_ERROR)),metadata:['profile_id'=>$payload['profile_id'],'base_revision'=>$payload['base_revision']]);
        try{
            $result=$provider->generate($input,$token);$token->throwIfCancellationRequested();
            if(array_keys($result)!==['summary'])throw new RuntimeException('provider_invalid_output');
            $text=MemoryDigestPolicy::content($result['summary']);$saved=$this->digests->save($payload,$text);
            $this->attempts->finish($attempt,$saved?'succeeded':'cancelled',strlen($text));
        }catch(Throwable $error){try{$this->attempts->finish($attempt,'failed',errorCode:$error instanceof OperationCancelled?'operation_cancelled':'provider_unavailable');}catch(Throwable){}throw $error;}
    }
}
