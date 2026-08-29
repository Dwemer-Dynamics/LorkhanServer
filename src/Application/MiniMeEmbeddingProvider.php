<?php
declare(strict_types=1);

namespace LORKHANserver\Application;

use LORKHANserver\Security\OutboundUrlPolicy;
use RuntimeException;

/** Strict adapter for the DwemerDistro MiniMe POST /embed contract. */
final class MiniMeEmbeddingProvider implements EmbeddingProvider
{
    private readonly string $url;
    /** @var list<string> */
    private readonly array $allowedHosts;
    private readonly bool $allowLoopbackHttp;

    public function __construct(string $endpoint,private readonly int $timeoutMs)
    {
        $endpoint=rtrim($endpoint,'/');
        $this->url=str_ends_with($endpoint,'/embed')?$endpoint:$endpoint.'/embed';
        $parts=parse_url($this->url);$host=is_array($parts)?strtolower(rtrim((string)($parts['host']??''),'.')):'';
        $this->allowedHosts=[$host];$this->allowLoopbackHttp=($parts['scheme']??null)==='http';
        OutboundUrlPolicy::validate($this->url,$this->allowedHosts,$this->allowLoopbackHttp);
        if($timeoutMs<250||$timeoutMs>5000)throw new \InvalidArgumentException('invalid_memory_embedding_timeout');
    }

    public function model():string
    {
        return MemoryEmbeddingPolicy::MODEL;
    }

    public function embed(string $text,CancellationToken $cancellation):array
    {
        $cancellation->throwIfCancellationRequested();
        if($text===''||strlen($text)>32768||!mb_check_encoding($text,'UTF-8')){
            throw new RuntimeException('embedding_input_invalid');
        }
        $body=json_encode(['text'=>$text],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $handle=curl_init($this->url);if($handle===false)throw new RuntimeException('provider_unavailable');
        curl_setopt_array($handle,OutboundUrlPolicy::curlOptions($this->url,$this->allowedHosts,$this->allowLoopbackHttp,true)+[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT_MS=>min(1000,$this->timeoutMs),CURLOPT_TIMEOUT_MS=>$this->timeoutMs,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json','Accept: application/json'],
            CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>static function($handle,$downloadTotal,$downloaded,$uploadTotal,$uploaded)
                use($cancellation):int{
                unset($handle,$downloadTotal,$downloaded,$uploadTotal,$uploaded);
                return $cancellation->isCancellationRequested()?1:0;
            },
        ]);
        try{
            $response=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if($cancellation->isCancellationRequested())throw new OperationCancelled('operation_cancelled');
            if(!is_string($response)||$status<200||$status>=300||strlen($response)>1048576){
                throw new RuntimeException('provider_unavailable');
            }
        }finally{curl_close($handle);}
        try{$decoded=json_decode($response,true,8,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new RuntimeException('provider_invalid_output');}
        $vector=$decoded['embedding']??null;
        if(!is_array($vector)||!array_is_list($vector)||count($vector)<8||count($vector)>1536){
            throw new RuntimeException('provider_invalid_output');
        }
        $normalized=[];$length=0.0;
        foreach($vector as$value){
            if(!is_int($value)&&!is_float($value))throw new RuntimeException('provider_invalid_output');
            $number=(float)$value;
            if(!is_finite($number)||abs($number)>1000000)throw new RuntimeException('provider_invalid_output');
            $normalized[]=$number;$length+=$number*$number;
        }
        $length=sqrt($length);if($length<=0.0||!is_finite($length))throw new RuntimeException('provider_invalid_output');
        return array_map(static fn(float $value):float=>round($value/$length,8),$normalized);
    }
}
