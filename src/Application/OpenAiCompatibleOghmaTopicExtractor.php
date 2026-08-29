<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

use LORKHANserver\Security\OutboundUrlPolicy;
use RuntimeException;

/** Bounded OpenAI-compatible adapter for CHIM-style Oghma topic extraction. */
final class OpenAiCompatibleOghmaTopicExtractor implements OghmaTopicExtractor
{
    /** @param list<string> $allowedHosts */
    public function __construct(private readonly string $endpoint,private readonly array $allowedHosts,
        private readonly string $model,private readonly string $apiKey,private readonly int $timeoutMs=15_000,
        private readonly bool $disableReasoning=false,private readonly array $options=[],
        private readonly bool $allowLoopbackHttp=false,private readonly bool $directConnection=false)
    {
        OutboundUrlPolicy::validate($endpoint,$allowedHosts,$allowLoopbackHttp);
        LlmConnector::validateOptions($options);
        if($model===''||strlen($model)>256||$timeoutMs<250||$timeoutMs>30_000)throw new \InvalidArgumentException('invalid_oghma_extractor_configuration');
    }

    public function extract(string $context,int $limit,CancellationToken $cancellation):array
    {
        $cancellation->throwIfCancellationRequested();$limit=max(1,min(3,$limit));
        if(strlen($context)>16_384)$context=mb_strcut($context,0,16_384,'UTF-8');
        $request=LlmConnector::requestOptions($this->options,$this->directConnection?null:0.0,$this->disableReasoning)+['model'=>$this->model,'messages'=>[
            ['role'=>'system','content'=>'Extract up to '.$limit.' distinct Morrowind lore topics relevant to the supplied current conversation. Return exactly one JSON object with one key, topics, whose value is an array of short canonical topic names. Do not answer the conversation, explain, or invent topics.'],
            ['role'=>'user','content'=>$context],
        ]];
        $body=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $networkOptions=OutboundUrlPolicy::curlOptions($this->endpoint,$this->allowedHosts,$this->allowLoopbackHttp,$this->directConnection);
        $handle=curl_init($this->endpoint);if($handle===false)throw new RuntimeException('provider_unavailable');
        $headers=['Content-Type: application/json','Accept: application/json'];if($this->apiKey!=='')$headers[]='Authorization: Bearer '.$this->apiKey;
        curl_setopt_array($handle,$networkOptions+[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT_MS=>min(5000,$this->timeoutMs),CURLOPT_TIMEOUT_MS=>$this->timeoutMs,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers,CURLOPT_NOPROGRESS=>false,
            CURLOPT_XFERINFOFUNCTION=>static function($handle,$downloadTotal,$downloaded,$uploadTotal,$uploaded)use($cancellation):int{
                unset($handle,$downloadTotal,$downloaded,$uploadTotal,$uploaded);return$cancellation->isCancellationRequested()?1:0;}]);
        try{$response=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if($cancellation->isCancellationRequested())throw new OperationCancelled('operation_cancelled');
            if(!is_string($response)||$status<200||$status>=300||strlen($response)>65_536)throw new RuntimeException('provider_unavailable');
        }finally{curl_close($handle);}
        try{$decoded=json_decode($response,true,32,JSON_THROW_ON_ERROR);$content=$decoded['choices'][0]['message']['content']??null;
            if(!is_string($content)||$content==='')throw new RuntimeException('provider_invalid_output');
            $content=ReasoningOutputCleaner::clean($content,($this->options['reasoning_model']??false)===true);
            $result=json_decode($content,true,8,JSON_THROW_ON_ERROR);
        }catch(\JsonException){throw new RuntimeException('provider_invalid_output');}
        if(!is_array($result)||array_keys($result)!==['topics']||!is_array($result['topics'])||!array_is_list($result['topics']))throw new RuntimeException('provider_invalid_output');
        $topics=[];$normalized=[];foreach($result['topics']as$topic){
            if(!is_string($topic)||!mb_check_encoding($topic,'UTF-8'))throw new RuntimeException('provider_invalid_output');
            $topic=trim($topic);if($topic===''||mb_strlen($topic,'UTF-8')>128)throw new RuntimeException('provider_invalid_output');
            $key=mb_strtolower($topic,'UTF-8');if(!isset($normalized[$key])){$topics[]=$topic;$normalized[$key]=true;}
            if(count($topics)>=$limit)break;
        }
        return$topics;
    }
}
