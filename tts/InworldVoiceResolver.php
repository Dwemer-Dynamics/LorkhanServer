<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use RuntimeException;

/** Herika-style Inworld/Cartesia get-or-create resolution, sharing credential-scoped sample caching. */
final class InworldVoiceResolver
{
    public function __construct(
        private readonly CloudVoiceLibrary $library,
        private readonly CredentialStore $credentials,
        private readonly string $voiceRoot,
        private readonly string $driver = 'inworld',
        private readonly ?string $credentialReference = null,
        private readonly string $workspace = '',
    ) {
        if(!in_array($driver,['inworld','cartesia'],true))throw new \InvalidArgumentException('voice_sync_unsupported');
        CloudVoiceLibrary::normalizeWorkspace($workspace);
    }

    public function resolve(string $name,string $language,CancellationToken $cancellation):string
    {
        $cancellation->throwIfCancellationRequested();
        if(preg_match('/^[a-zA-Z0-9_.+-]{1,512}$/D',$name)!==1)throw new RuntimeException('invalid_voice_name');
        // Explicit provider IDs (including existing Dagoth Ur/player clones) are already resolved.
        if(($this->driver==='inworld'&&str_contains($name,'__'))
            ||($this->driver==='cartesia'&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$name)===1))return $name;
        [$root,$cache,$path,$lock]=$this->lockCache($name);
        try{
            if(is_file($path)&&filesize($path)<4096){
                $saved=json_decode((string)file_get_contents($path),true);
                $id=is_array($saved)?($saved['voice_id']??''):'';
                if(is_string($id)&&preg_match('/^[a-zA-Z0-9_.+-]{1,512}$/D',$id)===1)return $id;
            }
            $matches=[];
            foreach($this->library->discover($this->driver,$cancellation)as$voice){
                if(strcasecmp($voice['voice_id'],$name)===0||strcasecmp($voice['display_name'],$name)===0)
                    $matches[$voice['voice_id']]=true;
            }
            if(count($matches)>1)throw new RuntimeException('voice_name_ambiguous');
            $id=(string)(array_key_first($matches)??'');$managed=false;
            if($id===''){
                $voice=$this->cloneSample($root,$name,$language,$cancellation);
                $id=$voice['id'];$managed=true;
            }
            if(preg_match('/^[a-zA-Z0-9_.+-]{1,512}$/D',$id)!==1)throw new RuntimeException('invalid_provider_voice_id');
            $this->saveCache($cache,$path,$id,$managed);
            return $id;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    /** Publish a replacement only after its validation audio succeeds; preserve the old mapping on failure. */
    public function rebuild(string $name,string $language,\Closure $validate):array
    {
        [$root,$cache,$path,$lock]=$this->lockCache($name);
        try{
            $saved=is_file($path)&&filesize($path)<4096?json_decode((string)file_get_contents($path),true):[];
            $oldId=is_array($saved)?(string)($saved['voice_id']??''):'';
            $oldManaged=$oldId!==''&&$this->isManaged($name,$oldId);
            $voice=$this->cloneSample($root,$name,$language,new NeverCancelledToken());
            $id=$voice['id'];
            if(preg_match('/^[a-zA-Z0-9_.+-]{1,512}$/D',$id)!==1)throw new RuntimeException('invalid_provider_voice_id');
            // A clone response that repeats the live ID is not a disposable replacement.
            if($id===$oldId)throw new RuntimeException('voice_clone_reused_id');
            try{
                $audio=$validate($id);
                if(!is_array($audio)||!is_string($audio['bytes']??null)
                    ||OpenAiCompatibleSpeechProvider::wavDurationMs($audio['bytes'])<1)throw new RuntimeException('voice_validation_failed');
                $this->saveCache($cache,$path,$id,true);
            }catch(\Throwable $error){
                try{$this->library->delete($this->driver,$id);}
                catch(\Throwable){throw new RuntimeException('voice_validation_cleanup_failed',0,$error);}
                throw $error;
            }
            $voice['cleanup_failed']=false;
            if($oldManaged){
                try{$this->library->delete($this->driver,$oldId);}
                catch(\Throwable){$voice['cleanup_failed']=true;}
            }
            return $voice;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** Preserve the same bounded sample validation and Morrowind transcript for automatic and manual clones. */
    private function cloneSample(string $root,string $name,string $language,CancellationToken $cancellation):array
    {
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
        $voice=$this->library->clone($this->driver,$sample,$name,$language,$reference,$cancellation);
        return $voice;
    }

    /** Atomically publish a mapping and its installation-ownership flag together. */
    private function saveCache(string $cache,string $path,string $id,bool $managed):void
    {
        $temporary=tempnam($cache,'.voice-');
        if($temporary===false)throw new RuntimeException('voice_cache_unavailable');
        try{
            if(file_put_contents($temporary,json_encode(['voice_id'=>$id,'managed'=>$managed],JSON_THROW_ON_ERROR))===false
                ||!chmod($temporary,0660)||!rename($temporary,$path))throw new RuntimeException('voice_cache_unavailable');
        }finally{if(is_file($temporary))unlink($temporary);}
    }

    /** Forget only this account/workspace's cached mapping; leave sample and remote voice untouched. */
    public function forget(string $name):void
    {
        if(preg_match('/^[\pL\pN_.+-]{1,512}$/uD',$name)!==1)throw new RuntimeException('invalid_voice_name');
        [,, $path,$lock]=$this->lockCache($name);
        try{
            if(is_file($path)&&!unlink($path))throw new RuntimeException('voice_cache_unavailable');
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** A legacy or discovered cache entry is not evidence that this installation owns the remote voice. */
    public function isManaged(string $name,string $voiceId):bool
    {
        [,, $path]=$this->cacheLocation($name);
        if(!is_file($path)||filesize($path)>=4096)return false;
        $saved=json_decode((string)file_get_contents($path),true);
        return is_array($saved)&&($saved['managed']??false)===true&&($saved['voice_id']??'')===$voiceId;
    }

    /** Recheck ownership under the playback lock before deleting a remote clone, then forget its mapping. */
    public function deleteManaged(string $name,string $voiceId):void
    {
        [,, $path,$lock]=$this->lockCache($name);
        try{
            if(!$this->isManaged($name,$voiceId))throw new RuntimeException('voice_not_managed');
            $this->library->delete($this->driver,$voiceId);
            if(!unlink($path))throw new RuntimeException('voice_cache_unavailable');
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** Derive a private cache path without creating files during Studio's read-only ownership display. */
    private function cacheLocation(string $name):array
    {
        if(preg_match('/^[\pL\pN_.+-]{1,512}$/uD',$name)!==1)throw new RuntimeException('invalid_voice_name');
        $reference=$this->credentialReference??($this->driver==='inworld'?'LORKHAN_TTS_INWORLD_API_KEY':'LORKHAN_TTS_CARTESIA_API_KEY');
        $key=in_array($reference,['','none'],true)?'':$this->credentials->resolve($reference);
        if($key==='')throw new RuntimeException('voice_credential_missing');
        $root=realpath($this->voiceRoot);
        if($root===false||!is_dir($root))throw new RuntimeException('voice_storage_unavailable');
        $cache=$root.'/.'.$this->driver.'-cache';
        $workspace=$this->driver==='inworld'?CloudVoiceLibrary::normalizeWorkspace($this->workspace):'';
        $cacheId=hash_hmac('sha256',($workspace===''?'':$workspace."\n").strtolower($name),$key);
        return [$root,$cache,$cache.'/'.$cacheId.'.json'];
    }

    /** Share credential/workspace identity and locking between playback and Studio cache actions. */
    private function lockCache(string $name):array
    {
        [$root,$cache,$path]=$this->cacheLocation($name);
        if(!is_dir($cache)&&!mkdir($cache,0770)&&!is_dir($cache))throw new RuntimeException('voice_cache_unavailable');
        if((fileperms($cache)&07777)!==02770&&!chmod($cache,02770))throw new RuntimeException('voice_cache_unavailable');
        $cacheId=pathinfo($path,PATHINFO_FILENAME);
        $lock=fopen($cache.'/'.$cacheId.'.lock','c');
        if($lock!==false&&(fileperms($cache.'/'.$cacheId.'.lock')&0777)!==0660
            &&!chmod($cache.'/'.$cacheId.'.lock',0660)){fclose($lock);throw new RuntimeException('voice_cache_unavailable');}
        if($lock===false)throw new RuntimeException('voice_cache_unavailable');
        // Neither playback nor Studio may race an upload or wait behind one.
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new RuntimeException('voice_registration_busy');}
        return [$root,$cache,$path,$lock];
    }

}
