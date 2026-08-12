<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Infrastructure\ProductRepository;
use ALMSIVIserver\Infrastructure\ProviderAttemptRepository;
use ALMSIVIserver\Infrastructure\Uuid;
use RuntimeException;
use Throwable;

/** Generates an NPC, narrator, or player speech-style revision without overwriting a later human edit. */
final class ProfileGenerateJobHandler implements JobHandler
{
    public const TYPE='profile.generate';

    public function __construct(private readonly ProductRepository $repository,private readonly ProfileGenerationProvider $provider,
        private readonly ?ProviderAttemptRepository $attempts=null,private readonly int $timeoutMs=30_000){}

    public function supports(string $jobType,int $schemaVersion):bool{return$jobType===self::TYPE&&$schemaVersion===1;}

    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        unset($idempotencyKey);$profileId=$payload['profile_id']??null;$baseRevision=$payload['base_revision']??null;$job=$payload['_job']??null;$mode=$payload['mode']??'npc_profile';
        if(!is_string($profileId)||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$profileId)!==1)
            throw new \InvalidArgumentException('invalid_profile_id');
        if(!is_int($baseRevision)||$baseRevision<1)throw new \InvalidArgumentException('invalid_base_revision');
        if(!in_array($mode,['npc_profile','narrator_profile','player_speech_style'],true))throw new \InvalidArgumentException('invalid_generation_mode');
        if(!is_array($job)||!is_string($job['job_id']??null)||!is_int($job['attempt']??null))throw new \InvalidArgumentException('invalid_job_fence');
        if(!$heartbeat())throw new RuntimeException('lease_lost');
        $profile=$this->repository->getRevisioned('profile',$profileId);if((int)$profile['current_revision']!==$baseRevision)return;
        $currentContent=is_array($profile['content']??null)?$profile['content']:[];
        $management=is_array($currentContent['management']??null)?$currentContent['management']:[];
        if(($management['locked']??false)===true)return;
        $identity=$profile['actor_identity']??[];if(is_string($identity))$identity=json_decode($identity,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($identity)||array_is_list($identity))throw new RuntimeException('profile_not_generatable');
        if($mode==='npc_profile'&&in_array($identity['kind']??'actor',['player','narrator'],true))throw new RuntimeException('profile_not_generatable');
        if($mode==='narrator_profile'&&($identity['kind']??null)!=='narrator')throw new RuntimeException('profile_not_narrator');
        if($mode==='player_speech_style'&&($identity['kind']??null)!=='player')throw new RuntimeException('profile_not_player');
        $deadline=hrtime(true)+max(1000,$this->timeoutMs)*1_000_000;$lastCheck=0;
        $token=new CallbackCancellationToken(function()use(&$lastCheck,$deadline,$heartbeat):bool{$now=hrtime(true);if($now>=$deadline)return true;
            if($now-$lastCheck<100_000_000)return false;$lastCheck=$now;return!$heartbeat();});
        $attemptId=Uuid::v4();$providerName=$this->provider instanceof OpenAiCompatibleProfileGenerationProvider?'openai-compatible':'mock';
        $input=['generation_mode'=>$mode,'name'=>(string)$profile['name'],'actor_identity'=>$identity,'content'=>$profile['content']??[]];
        if($mode==='player_speech_style'){$sample=[];$sampleBytes=0;foreach($this->repository->recentPlayerInputs((string)$profile['installation_id'],200)as$text){$text=mb_strcut($text,0,2048,'UTF-8');$bytes=strlen($text);if($sampleBytes+$bytes>65_536)break;$sample[]=$text;$sampleBytes+=$bytes;}if($sample===[])throw new RuntimeException('player_inputs_unavailable');$input['recent_player_inputs']=$sample;}
        $operation=match($mode){'player_speech_style'=>'generate_player_speech_style','narrator_profile'=>'generate_narrator_profile',default=>'generate_profile'};
        $this->attempts?->start($attemptId,'llm',$providerName,$operation,$job['attempt'],jobId:$job['job_id'],
            inputBytes:strlen(json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),metadata:['profile_id'=>$profileId,'base_revision'=>$baseRevision]);
        try{$generated=$this->provider->generate($input,$token);$token->throwIfCancellationRequested();$content=$currentContent;
            if($mode==='player_speech_style'){$speechStyle=trim((string)($generated['speech_style']??''));if($speechStyle===''||strlen($speechStyle)>8192||!mb_check_encoding($speechStyle,'UTF-8'))throw new RuntimeException('provider_invalid_output');$content['speech_style']=$speechStyle;}
            else foreach($generated as$field=>$value)$content[$field]=$value;
            $reason=match($mode){'player_speech_style'=>'AI player speech-style generation','narrator_profile'=>'AI narrator profile generation',default=>'AI profile generation'};
            $this->repository->reviseGeneratedProfileIfCurrent($profileId,$baseRevision,$content,$reason,gmdate('Y-m-d\TH:i:s\Z'));
            $this->attempts?->finish($attemptId,'succeeded',strlen(json_encode($generated,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)));
        }catch(OperationCancelled $error){$this->attempts?->finish($attemptId,'cancelled',errorCode:'operation_cancelled');throw$error;
        }catch(Throwable $error){try{$this->attempts?->finish($attemptId,'failed',errorCode:'provider_unavailable');}catch(Throwable){}throw$error;}
    }
}
