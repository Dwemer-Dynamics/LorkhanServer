<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\Uuid;
use Throwable;

/** Build provider-backed context on the durable worker, never the HTTP acceptance path. */
final class TurnContextPreparation
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ?ProviderAttemptRepository $providerAttempts,
        private readonly array $providerConfig,
        private readonly CancellationToken $cancellation,
        private readonly ?PluginHooks $plugins = null,
    ) {}

    public function prepare(array $m): array
    {
        $this->cancellation->throwIfCancellationRequested();
        $providerInput=$m;
        $knowledgeTurn=$m;
        $oghmaExtraction=isset($m['_director_response'])?[]:$this->oghmaExtraction($knowledgeTurn);$semanticMemory=isset($m['_director_response'])?[]:$this->semanticMemory($m);
        $selection = $this->products->promptContext($knowledgeTurn,gmdate('Y-m-d\TH:i:s\Z'),$oghmaExtraction,$semanticMemory);
        $providerInput['_selected_profile_id']=$selection['selected_profile_id'];
        // Freeze thought ownership with the prepared prompt so retries and fallbacks use the same owner.
        if(is_array($selection['private_thought']??null))$providerInput['_private_thought']=$selection['private_thought'];
        if(is_array($selection['player_profile']??null))$providerInput['_player_profile']=$selection['player_profile'];
        if(is_array($selection['narrator_profile']??null))$providerInput['_narrator_profile']=$selection['narrator_profile'];
        if(is_array($selection['narrator_event_prompts']??null))$providerInput['_narrator_event_prompts']=$selection['narrator_event_prompts'];
        if(is_array($selection['nearby_actor_profiles']??null)&&$selection['nearby_actor_profiles']!==[])$providerInput['_nearby_actor_profiles']=$selection['nearby_actor_profiles'];
        if(is_array($selection['power_observations']??null)&&$selection['power_observations']!==[])$providerInput['_power_observations']=$selection['power_observations'];
        if(is_array($selection['item_descriptions']??null)&&$selection['item_descriptions']!==[])$providerInput['_item_descriptions']=$selection['item_descriptions'];
        if(is_array($selection['scene_classification']??null))$providerInput['_scene_classification']=$selection['scene_classification'];
        // Addon text is frozen with the prepared prompt, so retries and fallbacks reuse the same bounded contributions.
        if($this->plugins!==null&&PluginHooks::negotiated($m)){
            try{$contributions=$this->plugins->promptContributions($m);}
            catch(Throwable $error){$contributions=[];error_log('[LORKHAN] plugin prompt hooks unavailable: '.$error::class);}
            if($contributions!==[])$providerInput['_plugin_context']=$contributions;
        }
        $assembled = (new PromptAssembler())->assemble($providerInput, $selection);
        $providerInput['_prompt'] = $assembled['provider_input'];
        $this->cancellation->throwIfCancellationRequested();
        unset($providerInput['_deferred_context']);
        return ['message'=>$providerInput,'trace'=>$assembled['trace']];
    }

    /** Ground topics locally first, then make one guarded connector fallback for unresolved explicit requests. */
    private function oghmaExtraction(array $turn):array
    {
        if($this->products===null)return['status'=>'unavailable','topics'=>[]];
        $grounded=$this->products->groundedOghmaExtraction($turn);
        if(($grounded['topics']??[])!==[])return[
            'status'=>'grounded','topics'=>$grounded['topics'],'matches'=>$grounded['matches']??[],
             'rejected'=>$grounded['rejected']??[],'tag_decisions'=>$grounded['tag_decisions']??[],
            'context_fallback'=>$grounded['context_fallback']??['eligible'=>false,'attempted'=>false,'used'=>false],
             'request_eligible'=>($grounded['request_eligible']??false)===true,'fallback_eligible'=>false,
        ];
        $runtime=$grounded['runtime']??$this->products->oghmaRuntime($turn);$connector=$runtime['connector']??null;
        $groundedStatus=(string)($grounded['status']??'no_match');
        $base=['status'=>$groundedStatus,'topics'=>[],'matches'=>[],'rejected'=>$grounded['rejected']??[],
             'tag_decisions'=>$grounded['tag_decisions']??[],'request_eligible'=>($grounded['request_eligible']??false)===true,
            'context_fallback'=>$grounded['context_fallback']??['eligible'=>false,'attempted'=>false,'used'=>false],
            'fallback_eligible'=>($grounded['fallback_eligible']??false)===true];
        if(in_array($groundedStatus,['disabled','ineligible','unavailable'],true))return$base;
        if(!$base['fallback_eligible'])return$base;
        if(($runtime['status']??null)!=='ready'||!is_array($connector))return array_merge($base,
            ['status'=>'fallback_'.(string)($runtime['status']??'unavailable')]);
        $content=is_array($connector['content']??null)?$connector['content']:[];$attemptId=Uuid::v4();$context=(string)($runtime['context']??'');
        $result=array_merge($base,['status'=>'fallback_failed','configuration_id'=>$connector['configuration_id']??null,
            'revision'=>$connector['revision']??null]);
        try{
            $this->providerAttempts?->start($attemptId,'llm','oghma-extractor','extract_oghma_topics',1,
                $turn['request_id']??null,$turn['turn_id']??null,model:is_string($content['model']??null)?$content['model']:null,
                configRevision:'r'.(int)($connector['revision']??0),inputBytes:strlen($context),
                metadata:['configuration_id'=>$connector['configuration_id'],'topic_limit'=>(int)($runtime['settings']['topic_count']??1)]);
            $suggestions=ProviderFactory::oghmaTopicExtractorForSlot($this->providerConfig,$connector,
                (int)($runtime['settings']['extractor_timeout_ms']??1500))->extract(
                $context,(int)($runtime['settings']['topic_count']??1),$this->cancellation);
            $topics=$this->products->resolveOghmaSuggestions($turn,$suggestions,(int)($runtime['settings']['topic_count']??1));
            $encoded=json_encode($topics,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$this->providerAttempts?->finish($attemptId,'succeeded',strlen($encoded));
            return array_merge($result,['status'=>$topics===[]?'fallback_unresolved':'fallback_succeeded',
                'topics'=>$topics,'suggested_topics'=>$suggestions]);
        }catch(OperationCancelled $error){throw $error;}catch(Throwable){
            try{$this->providerAttempts?->finish($attemptId,'failed',errorCode:'provider_unavailable');}catch(OperationCancelled $error){throw $error;}catch(Throwable){}
            return$result;
        }
    }

    /** Add one explicit MiniMe query signal; unavailable service falls back to local deterministic ranking. */
    private function semanticMemory(array $turn):array
    {
        if($this->products===null)return[];
        $runtime=$this->products->memoryEmbeddingRuntime((string)$turn['installation_id']);
        if(($runtime['status']??null)!=='ready'||!is_array($runtime['policy']??null))return[];
        $policy=$runtime['policy'];$content=$policy['content'];$base=[
            'status'=>'failed','policy_configuration_id'=>$policy['configuration_id'],
            'policy_revision'=>(int)$policy['current_revision'],'model'=>MemoryEmbeddingPolicy::MODEL,
        ];
        $query=MemoryEmbeddingPolicy::queryText($turn);$attempt=Uuid::v4();
        try{
            $this->providerAttempts?->start($attempt,'embedding','minime','query_memory',1,
                $turn['request_id']??null,$turn['turn_id']??null,model:MemoryEmbeddingPolicy::MODEL,
                configRevision:'r'.(int)$policy['current_revision'],inputBytes:strlen($query),
                metadata:['policy_configuration_id'=>$policy['configuration_id']]);
            $provider=new MiniMeEmbeddingProvider($content['endpoint'],$content['timeout_ms']);
            $embedding=$provider->embed($query,$this->cancellation);
            $encoded=json_encode($embedding,JSON_THROW_ON_ERROR);$this->providerAttempts?->finish($attempt,'succeeded',strlen($encoded));
            return array_replace($base,['status'=>'succeeded','embedding'=>$embedding]);
        }catch(OperationCancelled $error){throw $error;}catch(Throwable){
            try{$this->providerAttempts?->finish($attempt,'failed',errorCode:'provider_unavailable');}catch(OperationCancelled $error){throw $error;}catch(Throwable){}
            return$base;
        }
    }

}
