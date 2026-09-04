<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;
use RuntimeException;

/** Explicit TTS Studio operations derived from Herika's Cartesia and Inworld voice libraries. */
final class CloudVoiceLibrary
{
    public function __construct(private readonly CredentialStore $credentials) {}

    public function discover(string $driver): array
    {
        $voices=[];$cursor='';
        for($page=0;$page<6;$page++){
            $path=$driver==='cartesia'?'/voices?limit=100'.($cursor===''?'':'&starting_after='.rawurlencode($cursor))
                :'/voices/v1/voices?pageSize=100'.($cursor===''?'':'&pageToken='.rawurlencode($cursor));
            $payload=$this->request($driver,$path);
            $rows=$payload['data']??$payload['voices']??$payload;
            if(!is_array($rows)||!array_is_list($rows))throw new RuntimeException('voice_discovery_failed');
            foreach($rows as$row){if(!is_array($row))continue;
                $id=(string)($row['voiceId']??$row['id']??'');if($id==='')continue;
                $voices[]=['voice_id'=>$id,'display_name'=>(string)($row['displayName']??$row['name']??$id),
                    'language'=>str_replace('_','-',strtolower((string)($row['languageCode']??$row['language']??$row['langCode']??'en'))),
                    'custom_voice'=>$driver==='cartesia'?($row['is_owner']??!($row['is_public']??true)):($row['source']??'')==='IVC'];
            }
            $cursor=$driver==='cartesia'?(($payload['has_more']??false)?(string)($rows[array_key_last($rows)]['id']??''):''):(string)($payload['nextPageToken']??'');
            if(count($voices)>512)throw new RuntimeException('voice_catalog_too_large');
            if($cursor==='')return $voices;
        }
        // Do not present a truncated catalog as complete, especially before deciding what to upload.
        throw new RuntimeException('voice_catalog_too_large');
    }

    public function clone(string $driver,string $path,string $name,string $language): array
    {
        $size=is_file($path)?filesize($path):false;
        if($size===false||$size<44||$size>16_777_216)throw new InvalidArgumentException('invalid_voice_sample');
        if($driver==='cartesia'){
            $payload=$this->request($driver,'/voices/clone',['clip'=>new \CURLFile($path,'audio/wav',basename($path)),
                'name'=>$name,'description'=>'Lorkhan voice sample','language'=>$language,'mode'=>'similarity']);
            $voice=$payload;
        }else{
            $payload=$this->request($driver,'/voices/v1/voices:clone',json_encode(['displayName'=>$name,
                'languageCode'=>$language,'voiceSamples'=>[['audioData'=>base64_encode((string)file_get_contents($path))]],
                'description'=>'Lorkhan voice sample'],JSON_THROW_ON_ERROR));
            $voice=$payload['voice']??[];
        }
        $id=trim((string)($voice['voiceId']??$voice['id']??''));
        if($id===''||strlen($id)>512)throw new RuntimeException('voice_clone_failed');
        return ['id'=>$id,'display'=>$name,'language'=>$language,'status'=>'available','custom'=>true];
    }

    /** Pin credential-bearing requests to official providers; never follow redirects or return their errors. */
    private function request(string $driver,string $path,array|string|null $body=null): array
    {
        if(!in_array($driver,['cartesia','inworld'],true))throw new InvalidArgumentException('voice_sync_unsupported');
        $key=$this->credentials->resolve($driver==='cartesia'?'LORKHAN_TTS_CARTESIA_API_KEY':'LORKHAN_TTS_INWORLD_API_KEY');
        if($key==='')throw new RuntimeException('voice_credential_missing');
        $headers=['Accept: application/json','Authorization: '.($driver==='cartesia'?'Bearer ':'Basic ').$key];
        if($driver==='cartesia')$headers[]='Cartesia-Version: 2026-03-01';
        if(is_string($body))$headers[]='Content-Type: application/json';
        $handle=curl_init('https://api.'.$driver.'.ai'.$path);
        if($handle===false)throw new RuntimeException('voice_sync_unavailable');
        $response='';
        curl_setopt_array($handle,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>3000,
            CURLOPT_TIMEOUT_MS=>$body===null?15000:60000,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{
                if(strlen($response)+strlen($chunk)>32_000_000)return 0;$response.=$chunk;return strlen($chunk);
            }]);
        if($body!==null)curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body]);
        try{$ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if($ok===false||$status<200||$status>=300)throw new RuntimeException('voice_provider_http_'.$status);
        }finally{curl_close($handle);}
        try{$payload=json_decode($response,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('voice_provider_invalid_response');}
        if(!is_array($payload))throw new RuntimeException('voice_provider_invalid_response');
        return $payload;
    }
}
