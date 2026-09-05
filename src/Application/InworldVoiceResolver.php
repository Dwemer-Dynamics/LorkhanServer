<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use RuntimeException;

/** Herika-style get-or-create resolution for server-held voice samples, scoped to the active credential. */
final class InworldVoiceResolver
{
    public function __construct(
        private readonly CloudVoiceLibrary $library,
        private readonly CredentialStore $credentials,
        private readonly string $voiceRoot,
        private readonly bool $allowClone = false,
    ) {}

    public function resolve(string $name,string $language,CancellationToken $cancellation):string
    {
        $cancellation->throwIfCancellationRequested();
        if(preg_match('/^[a-zA-Z0-9_.+-]{1,512}$/D',$name)!==1)throw new RuntimeException('invalid_voice_name');
        // Explicit provider IDs (including existing Dagoth Ur/player clones) are already resolved.
        if(str_contains($name,'__'))return $name;
        $key=$this->credentials->resolve('LORKHAN_TTS_INWORLD_API_KEY');
        if($key==='')throw new RuntimeException('voice_credential_missing');
        $root=realpath($this->voiceRoot);
        if($root===false||!is_dir($root))throw new RuntimeException('voice_storage_unavailable');
        $cache=$root.'/.inworld-cache';
        if(!is_dir($cache)&&!mkdir($cache,0770)&&!is_dir($cache))throw new RuntimeException('voice_cache_unavailable');
        chmod($cache,02770);
        $cacheId=hash_hmac('sha256',strtolower($name),$key);
        $path=$cache.'/'.$cacheId.'.json';
        $lock=fopen($cache.'/'.$cacheId.'.lock','c');
        if($lock!==false)chmod($cache.'/'.$cacheId.'.lock',0660);
        if($lock===false)throw new RuntimeException('voice_cache_unavailable');
        try{
            // Never wait behind another upload on the dialogue thread; the durable speech job retries.
            if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('voice_registration_busy');
            if(is_file($path)&&filesize($path)<4096){
                $saved=json_decode((string)file_get_contents($path),true);
                $id=is_array($saved)?($saved['voice_id']??''):'';
                if(is_string($id)&&preg_match('/^[a-zA-Z0-9_.+-]{1,512}$/D',$id)===1)return $id;
            }
            $matches=[];
            foreach($this->library->discover('inworld',$cancellation)as$voice){
                if(strcasecmp($voice['voice_id'],$name)===0||strcasecmp($voice['display_name'],$name)===0)
                    $matches[$voice['voice_id']]=true;
            }
            if(count($matches)>1)throw new RuntimeException('voice_name_ambiguous');
            $id=(string)(array_key_first($matches)??'');
            if($id===''){
                if(!$this->allowClone)throw new RuntimeException('voice_upload_confirmation_required');
                $sample=realpath($root.'/'.$name.'.wav');
                if($sample===false||!str_starts_with($sample,$root.DIRECTORY_SEPARATOR)||!is_file($sample))
                    throw new RuntimeException('voice_sample_not_found');
                $size=filesize($sample);
                if($size===false||$size<44||$size>16_777_216)throw new RuntimeException('invalid_voice_sample');
                OpenAiCompatibleSpeechProvider::wavDurationMs((string)file_get_contents($sample));
                $reference='';
                foreach(MorrowindVoiceCatalog::bundled()->voices()as$voice){
                    if($voice['voice_id']===$name){$reference=(string)$voice['reference_text'];break;}
                }
                $voice=$this->library->clone('inworld',$sample,$name,$language,$reference,$cancellation);
                $id=$voice['id'];
            }
            if(preg_match('/^[a-zA-Z0-9_.+-]{1,512}$/D',$id)!==1)throw new RuntimeException('invalid_inworld_voice_id');
            $temporary=tempnam($cache,'.voice-');
            if($temporary===false)throw new RuntimeException('voice_cache_unavailable');
            try{
                if(file_put_contents($temporary,json_encode(['voice_id'=>$id],JSON_THROW_ON_ERROR))===false
                    ||!chmod($temporary,0660)||!rename($temporary,$path))throw new RuntimeException('voice_cache_unavailable');
            }finally{if(is_file($temporary))unlink($temporary);}
            return $id;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
