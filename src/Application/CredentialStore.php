<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use InvalidArgumentException;
use RuntimeException;

final class CredentialStore
{
    public function __construct(private readonly string $path)
    {
        $absolute=str_starts_with($path,DIRECTORY_SEPARATOR)||preg_match('/^[A-Za-z]:[\\\\\/]/D',$path)===1;
        if($path===''||!$absolute||str_contains($path,"\0"))throw new InvalidArgumentException('invalid_credential_store_path');
    }

    /** Return the server-controlled credential names accepted by the management UI and provider factory. */
    public static function allowedVariables(): array
    {
        $variables=['ALMSIVI_DEEPL_API_KEY','ALMSIVI_LLM_API_KEY','ALMSIVI_TTS_API_KEY','ALMSIVI_STT_API_KEY'];
        foreach(LlmConnector::CREDENTIALS as$variable)if($variable!=='')$variables[]=$variable;
        foreach(['tts_provider','stt_provider']as$kind)foreach(ConnectorCatalog::all($kind)as$definition){
            $variable=(string)$definition['credential_environment'];if($variable!=='')$variables[]=$variable;
        }
        $variables=array_values(array_unique($variables));sort($variables,SORT_STRING);return$variables;
    }

    /** Resolve process configuration first, then the persistent server-side store. */
    public function resolve(string $variable): string
    {
        $this->variable($variable);$environment=getenv($variable);
        if(is_string($environment)&&$environment!=='')return$environment;
        $stored=$this->read();return(string)($stored[$variable]??'');
    }

    /** Return status metadata only; secret values never leave this class. */
    public function statuses(): array
    {
        $stored=$this->read();$rows=[];
        foreach(self::allowedVariables()as$variable){$environment=getenv($variable);
            $source=is_string($environment)&&$environment!==''?'environment':(isset($stored[$variable])?'managed store':'not configured');
            $rows[]=['variable'=>$variable,'configured'=>$source!=='not configured','source'=>$source];}
        return$rows;
    }

    /** Store one credential atomically without returning or logging it. */
    public function set(string $variable,string $value): void
    {
        $this->variable($variable);$value=trim($value);
        if($value===''||strlen($value)>8192||preg_match('/[\x00-\x1F\x7F]/',$value)===1)throw new InvalidArgumentException('invalid_credential_value');
        $values=$this->read();$values[$variable]=$value;$this->write($values);
    }

    /** Remove only the managed value; process environment values remain externally controlled. */
    public function delete(string $variable): void
    {
        $this->variable($variable);$values=$this->read();unset($values[$variable]);$this->write($values);
    }

    private function variable(string $variable): void
    {
        if(!in_array($variable,self::allowedVariables(),true))throw new InvalidArgumentException('invalid_credential_variable');
    }

    private function read(): array
    {
        if(!is_file($this->path))return[];
        if(is_link($this->path))throw new RuntimeException('credential_store_symlink_rejected');
        $bytes=file_get_contents($this->path);if(!is_string($bytes)||strlen($bytes)>262144)throw new RuntimeException('credential_store_unavailable');
        try{$values=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('credential_store_invalid');}
        if(!is_array($values)||($values!==[]&&array_is_list($values)))throw new RuntimeException('credential_store_invalid');
        $allowed=array_flip(self::allowedVariables());$result=[];
        foreach($values as$variable=>$value){if(!isset($allowed[$variable])||!is_string($value)||$value===''||strlen($value)>8192)throw new RuntimeException('credential_store_invalid');$result[$variable]=$value;}
        return$result;
    }

    private function write(array $values): void
    {
        $directory=dirname($this->path);
        if(!is_dir($directory)||is_link($directory)||!is_writable($directory))throw new RuntimeException('credential_store_unavailable');
        ksort($values,SORT_STRING);$json=json_encode($values===[]?(object)[]:$values,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
        $temporary=tempnam($directory,'.credentials-');if($temporary===false)throw new RuntimeException('credential_store_unavailable');
        try{if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)||!chmod($temporary,0640))throw new RuntimeException('credential_store_unavailable');
            if(!rename($temporary,$this->path))throw new RuntimeException('credential_store_unavailable');}
        finally{if(is_file($temporary))@unlink($temporary);}
    }
}
