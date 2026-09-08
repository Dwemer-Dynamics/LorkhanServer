<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;
use RuntimeException;

final class CredentialStore
{
    /** One primary badge per provider; other identities remain independent additional keys. */
    public const PRESET_LABELS = [
        'LORKHAN_LLM_API_KEY'=>'OpenRouter', 'LORKHAN_TTS_OPENAI_API_KEY'=>'OpenAI',
        'LORKHAN_TTS_DEEPGRAM_API_KEY'=>'Deepgram', 'LORKHAN_TTS_GCP_API_KEY'=>'Google',
        'LORKHAN_TTS_AZURE_API_KEY'=>'Azure', 'LORKHAN_TTS_ELEVENLABS_API_KEY'=>'ElevenLabs',
        'LORKHAN_TTS_CARTESIA_API_KEY'=>'Cartesia', 'LORKHAN_TTS_INWORLD_API_KEY'=>'Inworld',
        'LORKHAN_LLM_GROQ_API_KEY'=>'Groq', 'LORKHAN_LLM_NANOGPT_API_KEY'=>'Nano-GPT',
        'LORKHAN_DEEPL_API_KEY'=>'DeepL',
    ];

    public function __construct(private readonly string $path)
    {
        $absolute=str_starts_with($path,DIRECTORY_SEPARATOR)||preg_match('/^[A-Za-z]:[\\\\\/]/D',$path)===1;
        if($path===''||!$absolute||str_contains($path,"\0"))throw new InvalidArgumentException('invalid_credential_store_path');
    }

    /** Return the server-controlled credential names accepted by the management UI and provider factory. */
    public static function allowedVariables(): array
    {
        $variables=['LORKHAN_DEEPL_API_KEY','LORKHAN_LLM_API_KEY','LORKHAN_TTS_API_KEY','LORKHAN_STT_API_KEY'];
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
        $stored=$this->read();$rows=[];$labels=(new self($this->path.'.labels.json'))->read();$badgeLabels=self::badgeLabels();
        foreach(array_unique(array_merge(self::allowedVariables(),array_keys($stored)))as$variable){$environment=getenv($variable);
            $source=is_string($environment)&&$environment!==''?'environment':(isset($stored[$variable])?'managed store':'not configured');
            $row=['variable'=>$variable,'configured'=>$source!=='not configured','source'=>$source,
                'label'=>$badgeLabels[$variable]??ucwords(strtolower(str_replace('_',' ',preg_replace('/^LORKHAN_|_API_KEY$/','',$variable))))];
            if(!isset(self::PRESET_LABELS[$variable])&&isset($labels[$variable]))$row['label']=$labels[$variable];
            $rows[]=$row;}
        return$rows;
    }

    /** Share provider spelling and distinguish existing independent keys across every badge picker. */
    public static function badgeLabels(): array
    {
        $labels=[];
        foreach(['tts_provider','stt_provider'] as $kind)foreach(ConnectorCatalog::all($kind) as $definition){
            if($definition['credential_environment']!=='')$labels[$definition['credential_environment']]=$definition['label'];
        }
        return array_replace($labels,[
            QuickstartLocalLlm::CREDENTIAL=>'Quickstart Local LLM',
            'LORKHAN_LLM_API_KEY'=>'Default LLM key (OpenRouter)',
            'LORKHAN_TTS_API_KEY'=>'Default TTS key','LORKHAN_STT_API_KEY'=>'Default STT key',
            'LORKHAN_LLM_OPENAI_API_KEY'=>'OpenAI LLM key','LORKHAN_LLM_OPENROUTER_API_KEY'=>'OpenRouter LLM key',
            'LORKHAN_LLM_CUSTOM_API_KEY'=>'Custom LLM key','LORKHAN_LLM_GROQ_API_KEY'=>'Groq',
            'LORKHAN_LLM_NANOGPT_API_KEY'=>'Nano-GPT','LORKHAN_LLM_GOOGLE_API_KEY'=>'Google LLM',
            'LORKHAN_TTS_OPENAI_API_KEY'=>'OpenAI speech key','LORKHAN_TTS_GCP_API_KEY'=>'Google',
            'LORKHAN_STT_GEMINI_API_KEY'=>'Google Gemini STT','LORKHAN_TTS_AZURE_API_KEY'=>'Azure',
            'LORKHAN_DEEPL_API_KEY'=>'DeepL',
        ],self::PRESET_LABELS);
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
        if(is_file($this->path.'.labels.json'))(new self($this->path.'.labels.json'))->delete($variable);
    }

    /** Rename display metadata only; connector identifiers and secrets remain unchanged. */
    public function setLabel(string $variable,string $label): void
    {
        $this->variable($variable);$label=trim($label);
        if(isset(self::PRESET_LABELS[$variable])||!isset($this->read()[$variable]))throw new InvalidArgumentException('invalid_credential_variable');
        if($label===''||mb_strlen($label)>80||!mb_check_encoding($label,'UTF-8')||preg_match('/[\x00-\x1F\x7F]/',$label))throw new InvalidArgumentException('invalid_credential_label');
        (new self($this->path.'.labels.json'))->set($variable,$label);
    }

    public static function isAllowed(string $variable): bool
    {
        return in_array($variable,self::allowedVariables(),true)
            || preg_match('/^LORKHAN_CUSTOM_[A-Z][A-Z0-9_]{0,39}_API_KEY$/D',$variable)===1;
    }

    private function variable(string $variable): void
    {
        if(!self::isAllowed($variable))throw new InvalidArgumentException('invalid_credential_variable');
    }

    private function read(): array
    {
        if(!is_file($this->path))return[];
        if(is_link($this->path))throw new RuntimeException('credential_store_symlink_rejected');
        $bytes=file_get_contents($this->path);if(!is_string($bytes)||strlen($bytes)>262144)throw new RuntimeException('credential_store_unavailable');
        try{$values=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('credential_store_invalid');}
        if(!is_array($values)||($values!==[]&&array_is_list($values)))throw new RuntimeException('credential_store_invalid');
        $result=[];
        foreach($values as$variable=>$value){if(!self::isAllowed($variable)||!is_string($value)||$value===''||strlen($value)>8192)throw new RuntimeException('credential_store_invalid');$result[$variable]=$value;}
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
