<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;
use RuntimeException;

/** Explicit TTS Studio operations derived from Herika's Cartesia and Inworld voice libraries. */
final class CloudVoiceLibrary
{
    public function __construct(private readonly CredentialStore $credentials, private readonly ?\Closure $transport = null,
        private readonly array $credentialReferences = [], private readonly string $inworldWorkspace = '')
    {
        self::normalizeWorkspace($inworldWorkspace);
    }

    /** Scope explicit Studio operations to the same credential badge and workspace as playback. */
    public function forPreset(array $content): self
    {
        $driver = (string) ($content['driver'] ?? '');
        if (!in_array($driver, ['cartesia', 'inworld'], true)) {
            throw new InvalidArgumentException('voice_discovery_unsupported');
        }
        $definition = ConnectorCatalog::definition('tts_provider', $driver);
        $reference = (string) ($content['credential'] ?? $definition['credential_environment']);
        $workspace = $driver === 'inworld' ? (string) ($content['options']['workspace'] ?? '') : '';
        return new self($this->credentials, $this->transport, [$driver => $reference], $workspace);
    }

    /** Accept an Inworld workspace ID, never an arbitrary URL or path. Empty keeps existing account routing. */
    public static function normalizeWorkspace(string $workspace): string
    {
        $workspace = preg_replace('#^workspaces/#', '', trim($workspace));
        if ($workspace !== '' && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $workspace) !== 1) {
            throw new InvalidArgumentException('invalid_inworld_workspace');
        }
        return $workspace;
    }

    public function discover(string $driver, ?CancellationToken $cancellation = null): array
    {
        $voices=[];$cursor='';
        for($page=0;$page<6;$page++){
            $path=$driver==='cartesia'?'/voices?limit=100'.($cursor===''?'':'&starting_after='.rawurlencode($cursor))
                :'/voices/v1/voices?pageSize=100'.($cursor===''?'':'&pageToken='.rawurlencode($cursor));
            $payload=$this->request($driver,$path,null,$cancellation);
            $rows=$payload['data']??$payload['voices']??$payload;
            if(!is_array($rows)||!array_is_list($rows))throw new RuntimeException('voice_discovery_failed');
            foreach($rows as$row){if(!is_array($row))continue;
                $id=(string)($row['voiceId']??$row['id']??'');if($id==='')continue;
                $workspace = self::normalizeWorkspace($this->inworldWorkspace);
                if ($driver === 'inworld' && $workspace !== ''
                    && !str_starts_with($id, $workspace . '__')
                    && !str_starts_with((string)($row['name'] ?? ''), 'workspaces/' . $workspace . '/')
                    && array_intersect([$workspace, 'workspaces/' . $workspace], array_filter(
                        [$row['workspace'] ?? '', $row['workspaceId'] ?? '', $row['workspace_id'] ?? ''], 'is_string')) === []) continue;
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

    public function clone(string $driver,string $path,string $name,string $language,
        string $referenceText='',?CancellationToken $cancellation=null): array
    {
        $size=is_file($path)?filesize($path):false;
        if($size===false||$size<44||$size>16_777_216)throw new InvalidArgumentException('invalid_voice_sample');
        if($driver==='cartesia'){
            $payload=$this->request($driver,'/voices/clone',['clip'=>new \CURLFile($path,'audio/wav',basename($path)),
                'name'=>$name,'description'=>'Lorkhan voice sample','language'=>$language,'mode'=>'similarity'],$cancellation);
            $voice=$payload;
        }else{
            $sample=['audioData'=>base64_encode((string)file_get_contents($path))];
            if($referenceText!=='')$sample['transcription']=$referenceText;
            $languageCode=strtoupper(str_replace('-','_',$language));
            $languageCode=['EN'=>'EN_US','ZH'=>'ZH_CN','KO'=>'KO_KR','JA'=>'JA_JP','RU'=>'RU_RU',
                'IT'=>'IT_IT','ES'=>'ES_ES','PT'=>'PT_BR','DE'=>'DE_DE','FR'=>'FR_FR','AR'=>'AR_SA',
                'PL'=>'PL_PL','NL'=>'NL_NL','HI'=>'HI_IN','HE'=>'HE_IL'][$languageCode]??$languageCode;
            $workspace = self::normalizeWorkspace($this->inworldWorkspace);
            $clonePath = '/voices/v1/' . ($workspace === '' ? '' : 'workspaces/' . $workspace . '/') . 'voices:clone';
            $payload=$this->request($driver,$clonePath,json_encode(['displayName'=>$name,
                'langCode'=>$languageCode,'voiceSamples'=>[$sample],
                'description'=>'Lorkhan voice sample'],JSON_THROW_ON_ERROR),$cancellation);
            $voice=$payload['voice']??[];
        }
        $id=trim((string)($voice['voiceId']??$voice['id']??''));
        if($id===''||strlen($id)>512)throw new RuntimeException('voice_clone_failed');
        return ['id'=>$id,'display'=>$name,'language'=>$language,'status'=>'available','custom'=>true];
    }

    /** Pin credential-bearing requests to official providers; never follow redirects or return their errors. */
    private function request(string $driver,string $path,array|string|null $body=null,?CancellationToken $cancellation=null): array
    {
        $cancellation?->throwIfCancellationRequested();
        if(!in_array($driver,['cartesia','inworld'],true))throw new InvalidArgumentException('voice_sync_unsupported');
        $reference=$this->credentialReferences[$driver]??($driver==='cartesia'?'LORKHAN_TTS_CARTESIA_API_KEY':'LORKHAN_TTS_INWORLD_API_KEY');
        $key=in_array($reference,['','none'],true)?'':$this->credentials->resolve($reference);
        if($key==='')throw new RuntimeException('voice_credential_missing');
        $headers=['Accept: application/json','Authorization: '.($driver==='cartesia'?'Bearer ':'Basic ').$key];
        if($driver==='cartesia')$headers[]='Cartesia-Version: 2026-03-01';
        if(is_string($body))$headers[]='Content-Type: application/json';
        if($this->transport!==null)return ($this->transport)($driver,$path,$body,$headers);
        $handle=curl_init('https://api.'.$driver.'.ai'.$path);
        if($handle===false)throw new RuntimeException('voice_sync_unavailable');
        $response='';
        curl_setopt_array($handle,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>3000,
            CURLOPT_TIMEOUT_MS=>$body===null?15000:60000,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>static fn()=>($cancellation?->isCancellationRequested()??false)?1:0,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{
                if(strlen($response)+strlen($chunk)>32_000_000)return 0;$response.=$chunk;return strlen($chunk);
            }]);
        if($body!==null)curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body]);
        try{$ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            $cancellation?->throwIfCancellationRequested();
            if($ok===false||$status<200||$status>=300)throw new RuntimeException('voice_provider_http_'.$status);
        }finally{curl_close($handle);}
        try{$payload=json_decode($response,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('voice_provider_invalid_response');}
        if(!is_array($payload))throw new RuntimeException('voice_provider_invalid_response');
        return $payload;
    }
}
