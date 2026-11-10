<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Security\OutboundUrlPolicy;
use RuntimeException;

/** Prepare a server-held sample on the selected XTTS-family service before its first synthesis. */
final class LocalVoiceResolver
{
    /** A listed registration is trusted briefly; rejected synthesis or expiry asks the live service again. */
    private const REGISTRATION_TTL_SECONDS=120;

    private readonly string $base;
    private readonly string $host;

    public function __construct(string $endpoint,private readonly string $driver,private readonly string $voiceRoot,
        private readonly string $apiKey='',private readonly int $timeoutMs=30000,private readonly ?\Closure $transport=null)
    {
        $this->base=preg_replace('#/(?:tts_to_audio|tts_stream)/?$#','',rtrim($endpoint,'/'));
        $this->host=(string)parse_url($endpoint,PHP_URL_HOST);
        if(!in_array($driver,ConnectorCatalog::SAMPLE_LIBRARY_TTS_DRIVERS,true))throw new \InvalidArgumentException('voice_sync_unsupported');
        OutboundUrlPolicy::validate($this->base,[$this->host],true);
    }

    public function resolve(string $voice,string $language,CancellationToken $cancellation):array
    {
        return $this->prepare($voice,$language,$cancellation,false)['voice'];
    }

    /** After synthesis rejects a sample voice, recheck the live list; return a payload only if it was re-registered. */
    public function recover(string $voice,string $language,CancellationToken $cancellation):?array
    {
        if($this->driver==='xtts')return null;
        $result=$this->prepare($voice,$language,$cancellation,true);
        return $result['uploaded']?$result['voice']:null;
    }

    private function prepare(string $voice,string $language,CancellationToken $cancellation,bool $refresh):array
    {
        $cancellation->throwIfCancellationRequested();
        if(preg_match('/^[\pL\pN][\pL\pN _+.-]{0,511}$/uD',$voice)!==1)throw new RuntimeException('invalid_voice_name');
        $name=preg_replace('/\.wav$/i','',preg_replace('/\s+/u','_',$voice));
        $root=realpath($this->voiceRoot);
        $samplePath=$root===false?'':$root.'/'.$name.'.wav';
        // Voice Studio changes samples in other workers; do not trust this worker's cached path.
        if($samplePath!=='')clearstatcache(true,$samplePath);
        $sample=$samplePath===''?false:realpath($samplePath);
        if($sample!==false&&(!str_starts_with($sample,$root.DIRECTORY_SEPARATOR)||!is_file($sample)))throw new RuntimeException('invalid_voice_sample');
        // Provider-owned voices remain authoritative; only local samples require registration.
        if($sample===false&&$this->driver!=='xtts')return ['voice'=>['speaker_wav'=>$voice],'uploaded'=>false];
        if($root===false)throw new RuntimeException('voice_storage_unavailable');
        if($sample!==false){
            $size=filesize($sample);
            if($size===false||$size<44||$size>16_777_216)throw new RuntimeException('invalid_voice_sample');
        }
        $cache=$root.'/.local-voice-cache';
        $scope=hash_hmac('sha256',$this->driver.'|'.$this->base.'|'.$name.'|'.$language,$this->apiKey);
        $entryPath=$cache.'/'.$scope.'.json';$hash=$sample===false||$this->driver==='xtts'?'':(string)hash_file('sha256',$sample);
        // Entries are published by rename, so a confirmed registration needs neither the lock nor a list request.
        if(!$refresh&&$hash!==''&&($hit=$this->registration($entryPath,$name,$hash))!==null)return ['voice'=>$hit,'uploaded'=>false];
        if(!is_dir($cache)&&!mkdir($cache,0770)&&!is_dir($cache))throw new RuntimeException('voice_cache_unavailable');
        if((fileperms($cache)&07777)!==02770&&!chmod($cache,02770))throw new RuntimeException('voice_cache_unavailable');
        $lockPath=$cache.'/'.$scope.'.lock';$lock=fopen($lockPath,'c');
        if($lock===false)throw new RuntimeException('voice_cache_unavailable');
        try{
            if((fileperms($lockPath)&0777)!==0660&&!chmod($lockPath,0660))throw new RuntimeException('voice_cache_unavailable');
            $this->acquire($lock,$cancellation);
            if($this->driver==='xtts')return ['voice'=>$this->legacyVoice($voice,$sample,$entryPath,$cancellation),'uploaded'=>false];
            // A concurrent worker may have confirmed this voice while this one waited.
            if($refresh){clearstatcache(true,$entryPath);if(is_file($entryPath))unlink($entryPath);}
            elseif(($hit=$this->registration($entryPath,$name,$hash))!==null)return ['voice'=>$hit,'uploaded'=>false];
            // Query the live service, so deleted voices and provider resets recover automatically.
            $path=$this->driver==='omnivoice'?'/speakers_list_extended?language='.rawurlencode($language):'/speakers_list';
            $rows=$this->request($path,null,$cancellation);
            if(isset($rows['speakers'])&&is_array($rows['speakers']))$rows=$rows['speakers'];
            elseif(!array_is_list($rows)){
                $flat=[];foreach($rows as$row)if(is_array($row)&&isset($row['speakers'])&&is_array($row['speakers']))$flat=array_merge($flat,$row['speakers']);
                if($flat!==[])$rows=$flat;
            }
            if(!array_is_list($rows))throw new RuntimeException('voice_discovery_failed');
            foreach($rows as$row){
                $id=is_string($row)?$row:(is_array($row)?(string)($row['voice_id']??$row['speaker']??$row['id']??$row['name']??''):'');
                if($id===$name||$id===$name.'.wav'){
                    if($this->driver==='omnivoice'&&is_array($row)&&!in_array($row['status']??'available',['runtime_ready','ready','ok','available'],true))
                        throw new RuntimeException('voice_registration_not_ready');
                    $this->publish($entryPath,['speaker_wav'=>$id,'sample_hash'=>$hash,'confirmed_at'=>time()]);
                    return ['voice'=>['speaker_wav'=>$id],'uploaded'=>false];
                }
            }
            OpenAiCompatibleSpeechProvider::wavDurationMs((string)file_get_contents($sample));
            $fields=['wavFile'=>new \CURLFile($sample,'audio/wav',$name.'.wav')];
            if($this->driver==='omnivoice'){
                $reference='';foreach(MorrowindVoiceCatalog::bundled()->voices()as$row)if($row['voice_id']===$name){$reference=$row['reference_text'];break;}
                $fields+=['language'=>$language,'speaker_name'=>$name,'display_name'=>$name,'reference_text'=>$reference,'force'=>'false'];
            }
            $reply=$this->request('/upload_sample',$fields,$cancellation);
            if($this->driver==='omnivoice'&&!in_array($reply['import_status']??$reply['status']??'',['runtime_ready','ready','ok'],true))
                throw new RuntimeException('voice_registration_not_ready');
            // Cache only after a later list confirms the upload, so an unconfirmed registration is never trusted.
            return ['voice'=>['speaker_wav'=>$name],'uploaded'=>true];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** Legacy XTTS consumes cached conditioning tensors, not the FastAPI speaker_wav field. */
    private function legacyVoice(string $voice,string|false $sample,string $path,CancellationToken $cancellation):array
    {
        $hash=$sample===false?'':hash_file('sha256',$sample);
        if(is_file($path)&&filesize($path)<1_048_576){
            $saved=json_decode((string)file_get_contents($path),true);
            if(is_array($saved)&&($saved['sample_hash']??null)===$hash)return $this->latents($saved['voice']??[]);
        }
        if($sample===false){
            $speakers=$this->request('/studio_speakers',null,$cancellation);
            $data=$speakers[$voice]??$speakers[str_replace('_',' ',$voice)]??[];
        }else{
            OpenAiCompatibleSpeechProvider::wavDurationMs((string)file_get_contents($sample));
            $data=$this->request('/clone_speaker',['wav_file'=>new \CURLFile($sample,'audio/wav',basename($sample))],$cancellation);
        }
        $data=$this->latents($data);
        if(!$this->publish($path,['sample_hash'=>$hash,'voice'=>$data]))throw new RuntimeException('voice_cache_unavailable');
        return $data;
    }

    /** Return an unexpired listed registration for this exact sample, or null so the live service is asked. */
    private function registration(string $path,string $name,string $hash):?array
    {
        clearstatcache(true,$path);
        if(!is_file($path)||filesize($path)>=4096)return null;
        $saved=json_decode((string)file_get_contents($path),true);
        if(!is_array($saved)||!is_int($saved['confirmed_at']??null))return null;
        $id=$saved['speaker_wav']??null;$age=time()-$saved['confirmed_at'];
        if(($id!==$name&&$id!==$name.'.wav')||($saved['sample_hash']??null)!==$hash||$age<0||$age>=self::REGISTRATION_TTL_SECONDS)return null;
        return ['speaker_wav'=>$id];
    }

    /** Atomically publish a private cache entry; callers decide whether a failed write is fatal. */
    private function publish(string $path,array $data):bool
    {
        $temporary=tempnam(dirname($path),'.voice-');
        if($temporary===false)return false;
        try{
            return file_put_contents($temporary,json_encode($data,JSON_THROW_ON_ERROR))!==false&&chmod($temporary,0660)&&rename($temporary,$path);
        }finally{if(is_file($temporary))unlink($temporary);}
    }

    /** Wait behind a bounded registration in another worker instead of failing its speech immediately. */
    private function acquire($lock,CancellationToken $cancellation):void
    {
        // The holder is bounded by one list and one upload request; polling backs off to 20 lock checks per second.
        $deadline=hrtime(true)+($this->timeoutMs+5000)*1_000_000;$delay=10_000;
        while(!flock($lock,LOCK_EX|LOCK_NB)){
            $cancellation->throwIfCancellationRequested();
            if(hrtime(true)>=$deadline)throw new RuntimeException('voice_registration_busy');
            usleep($delay);$delay=min($delay*2,50_000);
        }
        $cancellation->throwIfCancellationRequested();
    }

    /** Allow only bounded numeric conditioning arrays into the synthesis request. */
    private function latents(array $data):array
    {
        $result=[];
        foreach(['speaker_embedding','gpt_cond_latent']as$key){
            $values=$data[$key]??null;
            if(!is_array($values)||!array_is_list($values)||$values===[]||count($values)>32768)throw new RuntimeException('voice_clone_failed');
            foreach($values as$value){
                $numbers=is_array($value)&&$key==='gpt_cond_latent'?$value:[$value];
                if(!array_is_list($numbers)||$numbers===[]||count($numbers)>32768)throw new RuntimeException('voice_clone_failed');
                foreach($numbers as$number)if((!is_int($number)&&!is_float($number))||!is_finite((float)$number))throw new RuntimeException('voice_clone_failed');
            }
            $result[$key]=$values;
        }
        return $result;
    }

    /** Use only the configured service, with cancellation, bounded responses and no redirects. */
    private function request(string $path,?array $fields,CancellationToken $cancellation):array
    {
        $cancellation->throwIfCancellationRequested();
        $url=OutboundUrlPolicy::validate($this->base.$path,[$this->host],true);
        if($this->transport!==null)return ($this->transport)($url,$fields);
        $handle=curl_init($url);if($handle===false)throw new RuntimeException('provider_unavailable');
        $body='';$headers=['Accept: application/json'];if($this->apiKey!=='')$headers[]='Authorization: Bearer '.$this->apiKey;
        curl_setopt_array($handle,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>2000,
            CURLOPT_TIMEOUT_MS=>$fields===null?min(5000,$this->timeoutMs):$this->timeoutMs,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>static fn()=>$cancellation->isCancellationRequested()?1:0,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{
                if(strlen($body)+strlen($chunk)>1_048_576)return 0;$body.=$chunk;return strlen($chunk);
            }]);
        if($fields!==null)curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields]);
        try{
            $ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            $cancellation->throwIfCancellationRequested();
            if($ok===false||$status<200||$status>=300)throw new RuntimeException('provider_unavailable');
        }finally{curl_close($handle);}
        $decoded=json_decode($body,true,32);
        if(!is_array($decoded))throw new RuntimeException('voice_provider_invalid_response');
        return $decoded;
    }
}
