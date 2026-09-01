<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Security\OutboundUrlPolicy;
use RuntimeException;

/** Local/remote XTTS-family JSON adapter shared by PocketTTS, OmniVoice, Chatterbox, and XTTS. */
final class XttsCompatibleSpeechProvider implements SpeechProvider
{
    private const OPTION_FIELDS = ['speed', 'exaggeration', 'repetition_penalty', 'min_p', 'top_p',
        'cfg_weight', 'temperature', 'top_k'];

    private readonly string $url;
    private readonly array $allowedHosts;
    private readonly bool $allowLoopbackHttp;

    public function __construct(
        string $endpoint,
        private readonly string $driver,
        private readonly string $voice,
        private readonly string $language = 'en',
        private readonly array $options = [],
        private readonly string $apiKey = '',
        private readonly int $timeoutMs = 30_000,
    ) {
        $parts=parse_url($endpoint);$host=is_array($parts)?strtolower((string)($parts['host']??'')):'';
        if($host==='')throw new \InvalidArgumentException('invalid_speech_endpoint');
        $this->allowedHosts=[$host];$this->allowLoopbackHttp=($parts['scheme']??null)==='http';
        $base=rtrim($endpoint,'/');
        $path=match($driver){'chatterbox'=>'/tts_to_audio/','xtts'=>'/tts_stream',default=>'/tts_to_audio'};
        $this->url=str_ends_with($base,'/tts_to_audio')||str_ends_with($base,'/tts_to_audio/')||str_ends_with($base,'/tts_stream')?$base:$base.$path;
        OutboundUrlPolicy::validate($this->url,$this->allowedHosts,$this->allowLoopbackHttp);
        if(!in_array($driver,['pockettts','omnivoice','chatterbox','xtts-fastapi','xtts'],true)
            ||$voice===''||strlen($voice)>512||$language===''||strlen($language)>35
            ||$timeoutMs<1000||$timeoutMs>120_000)throw new \InvalidArgumentException('invalid_xtts_configuration');
    }

    public function synthesize(string $text,CancellationToken $cancellation,array $context=[]):array
    {
        $cancellation->throwIfCancellationRequested();$text=trim($text);
        if($text===''||mb_strlen($text)>4096)throw new RuntimeException('provider_invalid_input');
        $voice=trim((string)($context['voice']??$this->voice));
        $language=trim((string)($context['language']??$this->language));
        if($voice===''||strlen($voice)>512||$language===''||strlen($language)>35)throw new RuntimeException('provider_invalid_input');
        $body=['text'=>$text,'speaker_wav'=>$voice,'language'=>$language];
        foreach(self::OPTION_FIELDS as$field)if(array_key_exists($field,$this->options)){
            $value=$this->options[$field];if(!is_int($value)&&!is_float($value))throw new RuntimeException('provider_invalid_input');$body[$field]=$value;
        }
        $encoded=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $handle=curl_init(OutboundUrlPolicy::validate($this->url,$this->allowedHosts,$this->allowLoopbackHttp));
        if($handle===false)throw new RuntimeException('provider_unavailable');
        $headers=['Content-Type: application/json','Accept: audio/wav'];if($this->apiKey!=='')$headers[]='Authorization: Bearer '.$this->apiKey;
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encoded,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>min(5000,$this->timeoutMs),CURLOPT_TIMEOUT_MS=>$this->timeoutMs,
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_NOPROGRESS=>false,
            CURLOPT_XFERINFOFUNCTION=>static fn($handle,$downloadTotal,$downloaded,$uploadTotal,$uploaded):int=>$cancellation->isCancellationRequested()?1:0]);
        try{$bytes=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if($cancellation->isCancellationRequested())throw new OperationCancelled('operation_cancelled');
            if(!is_string($bytes)||$status<200||$status>=300||strlen($bytes)>33_554_432)throw new RuntimeException('provider_unavailable');
        }finally{curl_close($handle);}
        return['bytes'=>$bytes,'codec'=>'wav','mime_type'=>'audio/wav','duration_ms'=>OpenAiCompatibleSpeechProvider::wavDurationMs($bytes)];
    }
}
