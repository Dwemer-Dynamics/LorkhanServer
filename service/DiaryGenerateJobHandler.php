<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\FirstPartyJobRepository;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\Uuid;
use InvalidArgumentException;
use Throwable;

/** Generate one manual or automatic diary from frozen profile, context, and provider revisions. */
final class DiaryGenerateJobHandler implements JobHandler
{
    public const TYPE='narrative.generate';

    public function __construct(private readonly FirstPartyJobRepository $narratives,
        private readonly ProductRepository $products,private readonly ProviderAttemptRepository $attempts,
        private readonly array $providerConfig=[],private readonly ?ProfileGenerationProvider $testProvider=null){}

    public function supports(string $jobType,int $schemaVersion):bool{return$jobType===self::TYPE&&$schemaVersion===1;}

    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        foreach(['request_id','narrative_id','installation_id','profile_id','playthrough_id','provider_configuration_id']as$field)
            if(!is_string($payload[$field]??null)||!($field === 'profile_id' ? \LorkhanServer\Domain\ProfileId::isValid($payload[$field]) : Uuid::isValid($payload[$field])))throw new InvalidArgumentException('invalid_diary_generation_job');
        if($idempotencyKey!=='narrative.generate:'.$payload['request_id']
            ||!is_int($payload['profile_revision']??null)||$payload['profile_revision']<1
            ||!is_int($payload['provider_revision']??null)||$payload['provider_revision']<1
            ||!is_array($payload['source_turn_ids']??null)||!array_is_list($payload['source_turn_ids'])
            ||count($payload['source_turn_ids'])>400)
            throw new InvalidArgumentException('invalid_diary_generation_job');
        foreach($payload['source_turn_ids']as$sourceTurnId)
            if(!is_string($sourceTurnId)||!Uuid::isValid($sourceTurnId))throw new InvalidArgumentException('invalid_diary_generation_job');
        $automatic=array_key_exists('automatic_trigger',$payload);
        if($automatic&&(!in_array($payload['automatic_trigger']??null,['timer','sleep','wait'],true)
            ||!is_string($payload['automatic_source_request_id']??null)||!Uuid::isValid($payload['automatic_source_request_id'])
            ||!is_numeric($payload['trigger_game_time']??null)||$payload['trigger_game_time']<0))
            throw new InvalidArgumentException('invalid_diary_generation_job');
        $input=$payload['input']??null;
        if(!is_array($input)||array_is_list($input)||($input['generation_mode']??null)!=='diary_generation'
            ||!is_string($input['name']??null)||trim($input['name'])===''||!is_array($input['actor_identity']??null)
            ||!is_array($input['profile']??null)||!is_array($input['witnessed_context']??null)
            ||!array_is_list($input['witnessed_context'])||count($input['witnessed_context'])<1||count($input['witnessed_context'])>1600
            ||!is_string($input['instruction']??null)||trim($input['instruction'])===''||strlen($input['instruction'])>8192
            ||!mb_check_encoding($input['instruction'],'UTF-8')
            ||strlen(json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>131_072)
            throw new InvalidArgumentException('invalid_diary_generation_job');
        // Match the producer's finite event budget; multiple witnessed events can belong to one turn.
        $contextBytes=0;
        foreach($input['witnessed_context']as$event){
            if(!is_array($event)||array_is_list($event)
                ||array_diff(array_keys($event),['turn_id','at','type','speaker','target','content','location','game_time','people'])!==[]
                ||!is_string($event['at']??null)||!is_string($event['type']??null))
                throw new InvalidArgumentException('invalid_diary_generation_job');
            foreach($event as$key=>$value){
                if($key==='game_time'){if(!is_int($value))throw new InvalidArgumentException('invalid_diary_generation_job');}
                elseif(!is_string($value)||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException('invalid_diary_generation_job');
            }
            if(isset($event['turn_id'])&&(!Uuid::isValid($event['turn_id'])||!in_array($event['turn_id'],$payload['source_turn_ids'],true)))
                throw new InvalidArgumentException('invalid_diary_generation_job');
            $contextBytes+=strlen(json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            if($contextBytes>65_536)throw new InvalidArgumentException('invalid_diary_generation_job');
        }
        $job=$payload['_job']??null;
        if(!is_array($job)||!is_string($job['job_id']??null)||!Uuid::isValid($job['job_id'])
            ||!is_string($job['lease_token']??null)||!Uuid::isValid($job['lease_token'])
            ||!is_int($job['attempt']??null)||$job['attempt']<1)throw new InvalidArgumentException('invalid_job_fence');
        if(!$heartbeat())throw new OperationCancelled('lease_lost');
        if (!$this->narratives->narrativeSourcesActive($payload['source_turn_ids'],$payload)) return;
        $slot=$this->products->providerRevisionForInstallation($payload['installation_id'],
            $payload['provider_configuration_id'],$payload['provider_revision']);
        $provider=$this->testProvider??ProviderFactory::profileGenerationForSlot($this->providerConfig,$slot);
        $deadline=hrtime(true)+max(1000,min(120000,(int)($slot['content']['timeout_ms']??30000)))*1000000;$lastCheck=0;
        $token=new CallbackCancellationToken(function()use(&$lastCheck,$deadline,$heartbeat):bool{
            $now=hrtime(true);if($now>=$deadline)return true;if($now-$lastCheck<500000000)return false;$lastCheck=$now;return!$heartbeat();
        });
        $attempt=Uuid::v4();$this->attempts->start($attempt,'llm',
            $provider instanceof OpenAiCompatibleProfileGenerationProvider?'openai-compatible':'mock','generate_diary',$job['attempt'],
            jobId:$job['job_id'],model:(string)$slot['content']['model'],configRevision:(string)$payload['provider_revision'],
            inputBytes:strlen(json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
            metadata:['narrative_id'=>$payload['narrative_id'],'profile_id'=>$payload['profile_id'],
                'profile_revision'=>$payload['profile_revision'],'provider_configuration_id'=>$payload['provider_configuration_id']]);
        try{
            $output=DiaryGenerationPolicy::output($provider->generate($input,$token));$token->throwIfCancellationRequested();
            if(!$this->narratives->narrativeSourcesActive($payload['source_turn_ids'],$payload))throw new OperationCancelled('diary_scope_changed');
            $this->narratives->upsertNarrative($payload['narrative_id'],[
                'installation_id'=>$payload['installation_id'],'profile_id'=>$payload['profile_id'],
                'playthrough_id'=>$payload['playthrough_id'],'kind'=>'diary','title'=>$output['title'],'content'=>$output['content'],
                'provenance'=>array_filter(['source'=>$automatic?'automatic-diary-generation':'manual-diary-generation',
                    'request_id'=>$payload['request_id'],'trigger'=>$automatic?$payload['automatic_trigger']:null,
                    'source_request_id'=>$automatic?$payload['automatic_source_request_id']:null,
                    'trigger_game_time'=>$automatic?(float)$payload['trigger_game_time']:null,
                    'profile_revision'=>$payload['profile_revision'],'provider_configuration_id'=>$payload['provider_configuration_id'],
                    'provider_revision'=>$payload['provider_revision'],'source_turn_ids'=>$payload['source_turn_ids']??[]],
                    static fn(mixed$value):bool=>$value!==null),
            ],gmdate('Y-m-d\TH:i:s\Z'));
            $this->attempts->finish($attempt,'succeeded',strlen(json_encode($output,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)));
        }catch(OperationCancelled $error){$this->attempts->finish($attempt,'cancelled',errorCode:'operation_cancelled');throw$error;
        }catch(Throwable $error){try{$this->attempts->finish($attempt,'failed',errorCode:'provider_unavailable');}catch(Throwable){}throw$error;}
    }
}
