<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use Closure;
use RuntimeException;

/** Bounded DeepL adapter restricted to the official Free and Pro translation endpoints. */
final class DeepLTranslationProvider implements TranslationProvider
{
    private const MAX_RESPONSE_BYTES=131_072;

    /** @param null|Closure(string,array<string,string>,string,int,CancellationToken):string $transport */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly int $timeoutMs=30_000,
        private readonly ?Closure $transport=null,
    ){
        if(!in_array($endpoint,[TranslationPolicy::FREE_ENDPOINT,TranslationPolicy::PRO_ENDPOINT],true))
            throw new RuntimeException('provider_unavailable');
    }

    public function translate(array $texts,string $sourceLanguage,string $targetLanguage,CancellationToken $token):array
    {
        if($this->apiKey===''||count($texts)<1||count($texts)>4||$targetLanguage==='')
            throw new RuntimeException('provider_unavailable');
        $fields=[];$total=0;
        foreach($texts as$text){
            if(!is_string($text)||$text===''||!mb_check_encoding($text,'UTF-8')||strlen($text)>16_384)
                throw new RuntimeException('provider_unavailable');
            $total+=strlen($text);if($total>32_768)throw new RuntimeException('provider_unavailable');
            $fields[]='text='.rawurlencode($text);
        }
        if($sourceLanguage!=='')$fields[]='source_lang='.rawurlencode($sourceLanguage);
        $fields[]='target_lang='.rawurlencode($targetLanguage);
        $body=implode('&',$fields);$headers=['Authorization'=>'DeepL-Auth-Key '.$this->apiKey,
            'Content-Type'=>'application/x-www-form-urlencoded','Accept'=>'application/json'];
        $token->throwIfCancellationRequested();
        $response=$this->transport!==null
            ?($this->transport)($this->endpoint,$headers,$body,$this->timeoutMs,$token)
            :$this->request($headers,$body,$token);
        if(strlen($response)>self::MAX_RESPONSE_BYTES)throw new RuntimeException('provider_unavailable');
        try{$decoded=json_decode($response,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('provider_unavailable');}
        $translations=$decoded['translations']??null;
        if(!is_array($translations)||!array_is_list($translations)||count($translations)!==count($texts))
            throw new RuntimeException('provider_unavailable');
        $result=[];$outputBytes=0;
        foreach($translations as$item){
            $text=is_array($item)?($item['text']??null):null;
            if(!is_string($text)||$text===''||!mb_check_encoding($text,'UTF-8')||strlen($text)>16_384)
                throw new RuntimeException('provider_unavailable');
            $outputBytes+=strlen($text);if($outputBytes>32_768)throw new RuntimeException('provider_unavailable');
            $result[]=$text;
        }
        return$result;
    }

    /** @param array<string,string> $headers */
    private function request(array $headers,string $body,CancellationToken $token):string
    {
        $handle=curl_init($this->endpoint);if($handle===false)throw new RuntimeException('provider_unavailable');
        $response='';
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>min(5000,$this->timeoutMs),CURLOPT_TIMEOUT_MS=>$this->timeoutMs,
            CURLOPT_HTTPHEADER=>array_map(static fn(string$key,string$value):string=>$key.': '.$value,array_keys($headers),array_values($headers)),
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_NOPROGRESS=>false,
            CURLOPT_XFERINFOFUNCTION=>static function()use($token):int{return$token->isCancellationRequested()?1:0;},
            CURLOPT_WRITEFUNCTION=>static function(mixed$handle,string$chunk)use(&$response):int{
                unset($handle);if(strlen($response)+strlen($chunk)>self::MAX_RESPONSE_BYTES)return 0;$response.=$chunk;return strlen($chunk);
            }]);
        try{$ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if($token->isCancellationRequested())throw new OperationCancelled();
            if($ok===false||$status<200||$status>=300)throw new RuntimeException('provider_unavailable');
        }finally{curl_close($handle);}
        return$response;
    }
}
