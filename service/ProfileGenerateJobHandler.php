<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\Uuid;
use RuntimeException;
use Throwable;

/** Generates an NPC, narrator, or player speech-style revision without overwriting a later human edit. */
final class ProfileGenerateJobHandler implements JobHandler
{
    public const TYPE='profile.generate';

    public function __construct(private readonly ProductRepository $repository,private readonly ?ProfileGenerationProvider $testProvider,
        private readonly ?ProviderAttemptRepository $attempts=null,private readonly int $timeoutMs=30_000,
        private readonly array $providerConfig=[]){}

    public function supports(string $jobType,int $schemaVersion):bool{return$jobType===self::TYPE&&$schemaVersion===1;}

    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        unset($idempotencyKey);$profileId=$payload['profile_id']??null;$baseRevision=$payload['base_revision']??null;$job=$payload['_job']??null;$mode=$payload['mode']??'npc_profile';
        if(!is_string($profileId)||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$profileId)!==1)
            throw new \InvalidArgumentException('invalid_profile_id');
        if(!is_int($baseRevision)||$baseRevision<1)throw new \InvalidArgumentException('invalid_base_revision');
        if(!in_array($mode,['npc_profile','npc_profile_backfill','profile_evolution','narrator_profile','narrator_profile_evolution','player_speech_style'],true))throw new \InvalidArgumentException('invalid_generation_mode');
        $guidance=$payload['speech_style_guidance']??'';
        if(!is_string($guidance)||strlen($guidance)>4000||!mb_check_encoding($guidance,'UTF-8')||($guidance!==''&&$mode!=='player_speech_style'))throw new \InvalidArgumentException('invalid_speech_style_guidance');
        $stylePrompt=$payload['speech_style_prompt']??null;
        if($stylePrompt!==null&&(!is_string($stylePrompt)||trim($stylePrompt)===''||strlen($stylePrompt)>32768||!mb_check_encoding($stylePrompt,'UTF-8')||$mode!=='player_speech_style'))throw new \InvalidArgumentException('invalid_speech_style_prompt');
        $currentStyle=$payload['current_speech_style']??null;
        if($currentStyle!==null&&(!is_string($currentStyle)||strlen($currentStyle)>8192||!mb_check_encoding($currentStyle,'UTF-8')||$mode!=='player_speech_style'))throw new \InvalidArgumentException('invalid_current_speech_style');
        if(!is_array($job)||!is_string($job['job_id']??null)||!is_int($job['attempt']??null))throw new \InvalidArgumentException('invalid_job_fence');
        if(!$heartbeat())throw new RuntimeException('lease_lost');
        $profile=$this->repository->getRevisioned('profile',$profileId);if((int)$profile['current_revision']!==$baseRevision)return;
        if(!$this->repository->profileTasksEnabled((string)$profile['installation_id']))throw new RuntimeException('profile_tasks_disabled');
        $currentContent=is_array($profile['content']??null)?$profile['content']:[];
        $management=is_array($currentContent['management']??null)?$currentContent['management']:[];
        if(($management['locked']??false)===true)return;
        $identity=$profile['actor_identity']??[];if(is_string($identity))$identity=json_decode($identity,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($identity)||array_is_list($identity))throw new RuntimeException('profile_not_generatable');
        if(in_array($mode,['npc_profile','npc_profile_backfill','profile_evolution'],true)&&in_array($identity['kind']??'actor',['player','narrator'],true))throw new RuntimeException('profile_not_generatable');
        if(in_array($mode,['narrator_profile','narrator_profile_evolution'],true)&&($identity['kind']??null)!=='narrator')throw new RuntimeException('profile_not_narrator');
        if($mode==='player_speech_style'&&($identity['kind']??null)!=='player')throw new RuntimeException('profile_not_player');
        $slot=null;$timeout=$this->timeoutMs;
        if(array_key_exists('provider_configuration_id',$payload)||array_key_exists('provider_revision',$payload)){
            $configurationId=$payload['provider_configuration_id']??null;$revision=$payload['provider_revision']??null;
            if(!is_string($configurationId)||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$configurationId)!==1
                ||!is_int($revision)||$revision<1)throw new \InvalidArgumentException('invalid_profile_provider_revision');
            $slot=$this->repository->providerRevisionForInstallation((string)$profile['installation_id'],$configurationId,$revision);
            $provider=$this->testProvider??ProviderFactory::profileGenerationForSlot($this->providerConfig,$slot);
            $timeout=(int)($slot['content']['timeout_ms']??$timeout);
        }else{
            // A job must carry its selected connector revision; never fall through to a runtime provider.
            throw new RuntimeException('profile_generation_connector_unavailable');
        }
        $deadline=hrtime(true)+max(1000,min(120_000,$timeout))*1_000_000;$lastCheck=0;
        $token=new CallbackCancellationToken(function()use(&$lastCheck,$deadline,$heartbeat):bool{$now=hrtime(true);if($now>=$deadline)return true;
            if($now-$lastCheck<100_000_000)return false;$lastCheck=$now;return!$heartbeat();});
        $attemptId=Uuid::v4();$providerName=$provider instanceof OpenAiCompatibleProfileGenerationProvider?'openai-compatible':'mock';
        $input=['generation_mode'=>$mode,'name'=>(string)$profile['name'],'actor_identity'=>$identity,'content'=>$profile['content']??[]];
        if($mode==='player_speech_style'&&$stylePrompt!==null)$input['speech_style_prompt']=$stylePrompt;
        if($mode==='player_speech_style'&&$guidance!=='')$input['speech_style_guidance']=$guidance;
        if($mode==='player_speech_style'&&$currentStyle!==null)$input['current_speech_style']=$currentStyle;
        if($mode==='player_speech_style'){$sample=[];$sampleBytes=0;foreach($this->repository->recentPlayerInputs((string)$profile['installation_id'],200)as$text){$text=mb_strcut($text,0,2048,'UTF-8');$bytes=strlen($text);if($sampleBytes+$bytes>65_536)break;$sample[]=$text;$sampleBytes+=$bytes;}if($sample===[])throw new RuntimeException('player_inputs_unavailable');$input['recent_player_inputs']=$sample;}
        $evolution=in_array($mode,['profile_evolution','narrator_profile_evolution'],true);
        if($mode==='npc_profile_backfill'||$evolution){$events=$payload['recent_events']??null;$sources=$payload['source_turn_ids']??null;
            $historyLimit=$evolution?400:100;
            if(!is_array($events)||!array_is_list($events)||$events===[]||count($events)>$historyLimit
                ||!is_array($sources)||!array_is_list($sources)||count($sources)!==count($events)||count($sources)>$historyLimit
                ||strlen(json_encode($events,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>65_536)
                throw new \InvalidArgumentException('invalid_profile_backfill_context');
            foreach($sources as$index=>$source){$event=$events[$index]??null;$keys=is_array($event)?array_keys($event):[];sort($keys);
                if(!is_string($source)||!Uuid::isValid($source)||$keys!==['npc_responses','player_input','turn_id']
                    ||($event['turn_id']??null)!==$source||!is_string($event['player_input']??null)
                    ||!mb_check_encoding($event['player_input'],'UTF-8')||!is_array($event['npc_responses']??null)
                    ||!array_is_list($event['npc_responses'])||$event['npc_responses']===[])
                    throw new \InvalidArgumentException('invalid_profile_backfill_context');
                foreach($event['npc_responses']as$text)if(!is_string($text)||trim($text)===''||!mb_check_encoding($text,'UTF-8'))
                    throw new \InvalidArgumentException('invalid_profile_backfill_context');}
            $input['recent_events']=$events;$input['source_turn_ids']=$sources;
            if($evolution){$fields=$payload['dynamic_fields']??null;$allowed=EffectiveSettingsResolver::DYNAMIC_PROFILE_FIELDS;
                if(!is_array($fields)||!array_is_list($fields)||$fields===[]||count($fields)>count($allowed)||count(array_unique($fields))!==count($fields))
                    throw new \InvalidArgumentException('invalid_profile_evolution_fields');
                foreach($fields as$field)if(!is_string($field)||!in_array($field,$allowed,true))throw new \InvalidArgumentException('invalid_profile_evolution_fields');
                $input['dynamic_fields']=$fields;}}
        $operation=match($mode){'player_speech_style'=>'generate_player_speech_style','narrator_profile','narrator_profile_evolution'=>'generate_narrator_profile',default=>'generate_profile'};
        $this->attempts?->start($attemptId,'llm',$providerName,$operation,$job['attempt'],jobId:$job['job_id'],
            model:$slot===null?null:(string)$slot['content']['model'],configRevision:$slot===null?null:(string)$slot['revision'],
            inputBytes:strlen(json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),metadata:['profile_id'=>$profileId,'base_revision'=>$baseRevision]
                +($slot===null?[]:['provider_configuration_id'=>$slot['configuration_id']]));
        try{$generated=$provider->generate($input,$token);$token->throwIfCancellationRequested();$content=$currentContent;
            if($mode==='player_speech_style'){$speechStyle=trim((string)($generated['speech_style']??''));if($speechStyle===''||strlen($speechStyle)>8192||!mb_check_encoding($speechStyle,'UTF-8'))throw new RuntimeException('provider_invalid_output');$content['speech_style']=$speechStyle;}
            elseif($evolution){foreach($input['dynamic_fields']as$field){$value=trim((string)($generated[$field]??''));
                if($value===''||strlen($value)>8192||!mb_check_encoding($value,'UTF-8'))throw new RuntimeException('provider_invalid_output');$content[$field]=$value;}}
            else foreach($generated as$field=>$value)$content[$field]=$value;
            $reason=match($mode){'player_speech_style'=>'AI player speech-style generation','narrator_profile'=>'AI narrator profile generation',
                'profile_evolution'=>'automatic NPC profile evolution','narrator_profile_evolution'=>'automatic narrator profile evolution',
                'npc_profile_backfill'=>'automatic AI profile backfill',default=>'AI profile generation'};
            if($mode==='player_speech_style')$this->repository->storePlayerSpeechStyleDraft($job['job_id'],$job['attempt'],$profileId,$baseRevision,$content['speech_style']);
            else $this->repository->reviseGeneratedProfileIfCurrent($profileId,$baseRevision,$content,$reason,gmdate('Y-m-d\TH:i:s\Z'),$payload['source_turn_ids']??[],$job['job_id'],$job['attempt']);
            $this->attempts?->finish($attemptId,'succeeded',strlen(json_encode($generated,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)));
        }catch(OperationCancelled $error){$this->attempts?->finish($attemptId,'cancelled',errorCode:'operation_cancelled');throw$error;
        }catch(Throwable $error){try{$this->attempts?->finish($attemptId,'failed',errorCode:'provider_unavailable');}catch(Throwable){}throw$error;}
    }
}
