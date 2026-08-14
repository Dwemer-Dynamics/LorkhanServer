<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use RuntimeException;

final class ProviderFactory
{
    /** Build the configured dialogue provider for both HTTP fallback work and background workers. */
    public static function dialogue(array $config): Provider
    {
        $provider = self::section($config, 'provider');
        return match ((string) ($provider['driver'] ?? 'mock')) {
            'mock' => new MockProvider((string) ($provider['mock_prefix'] ?? '')),
            'openai-compatible' => new OpenAiCompatibleProvider(
                (string) ($provider['endpoint'] ?? ''),
                self::hosts($provider),
                (string) ($provider['model'] ?? ''),
                self::apiKey($provider, 'ALMSIVI_LLM_API_KEY', $config),
                (int) ($provider['timeout_ms'] ?? 30_000),
                (bool) ($provider['disable_reasoning'] ?? false),
            ),
            default => throw new RuntimeException('Unsupported dialogue provider driver.'),
        };
    }

    /** Build a turn-scoped provider from a server-owned slot; credentials remain in process config. */
    public static function dialogueForSlot(array $config, array $slot): Provider
    {
        $keys=array_keys($slot);sort($keys);
        if($keys!==['configuration_id','content','revision']
            ||!is_string($slot['configuration_id'])
            ||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$slot['configuration_id'])!==1
            ||!is_int($slot['revision'])||$slot['revision']<1
            ||!is_array($slot['content'])||array_is_list($slot['content']))
            throw new RuntimeException('Provider slot snapshot is invalid.');
        $content=$slot['content'];$driver=$content['driver']??null;$model=$content['model']??null;
        if(!is_string($driver)||!in_array($driver,['configured','mock'],true)
            ||!is_string($model)||$model===''||strlen($model)>256)
            throw new RuntimeException('Provider slot content is invalid.');
        if($driver==='mock'){
            $allowed=['driver','mock_prefix','model','timeout_ms'];$keys=array_keys($content);sort($keys);
            if(array_diff($keys,$allowed)!==[]||!is_string($content['mock_prefix']??'')||strlen((string)($content['mock_prefix']??''))>256)
                throw new RuntimeException('Mock provider slot content is invalid.');
            return new MockProvider((string)($content['mock_prefix']??''));
        }
        $keys=array_keys($content);sort($keys);
        if($keys!==['driver','model'])throw new RuntimeException('Configured provider slot content is invalid.');
        $configured=$config;
        $section=self::section($configured,'provider');
        $section['model']=$model;
        $configured['provider']=$section;
        return self::dialogue($configured);
    }

    /** Build the profile-routed extractor without exposing connector credentials or dialogue contracts. */
    public static function oghmaTopicExtractorForSlot(array $config,array $slot,?int $timeoutMs=null):OghmaTopicExtractor
    {
        $keys=array_keys($slot);sort($keys);
        if($keys!==['configuration_id','content','revision']||!is_string($slot['configuration_id'])
            ||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$slot['configuration_id'])!==1
            ||!is_int($slot['revision'])||$slot['revision']<1||!is_array($slot['content'])||array_is_list($slot['content']))
            throw new RuntimeException('Provider slot snapshot is invalid.');
        $content=$slot['content'];$driver=$content['driver']??null;$model=$content['model']??null;
        if(!is_string($driver)||!in_array($driver,['configured','mock'],true)||!is_string($model)||$model===''||strlen($model)>256)
            throw new RuntimeException('Provider slot content is invalid.');
        if($driver==='mock')return new MockOghmaTopicExtractor();
        $keys=array_keys($content);sort($keys);if($keys!==['driver','model'])throw new RuntimeException('Configured provider slot content is invalid.');
        $provider=self::section($config,'provider');$timeout=$timeoutMs===null
            ?max(1000,min(15_000,(int)($provider['timeout_ms']??15_000)))
            :max(250,min(3000,$timeoutMs));
        return new OpenAiCompatibleOghmaTopicExtractor((string)($provider['endpoint']??''),self::hosts($provider),$model,
            self::apiKey($provider,'ALMSIVI_LLM_API_KEY',$config),$timeout,(bool)($provider['disable_reasoning']??false));
    }

    /** Build the task-specific profile generator from the same server-owned LLM configuration. */
    public static function profileGeneration(array $config):ProfileGenerationProvider
    {
        $provider=self::section($config,'provider');
        return match((string)($provider['driver']??'mock')){
            'mock'=>new MockProfileGenerationProvider(),
            'openai-compatible'=>new OpenAiCompatibleProfileGenerationProvider((string)($provider['endpoint']??''),self::hosts($provider),
                (string)($provider['model']??''),self::apiKey($provider,'ALMSIVI_LLM_API_KEY',$config),(int)($provider['timeout_ms']??30_000),
                (bool)($provider['disable_reasoning']??false)),
            default=>throw new RuntimeException('Unsupported profile generation provider driver.'),
        };
    }

    /** Build optional text-to-speech service configuration. */
    public static function speech(array $config): ?SpeechProvider
    {
        $provider = self::section($config, 'speech_provider');
        return match ((string) ($provider['driver'] ?? 'mock')) {
            'disabled' => null,
            'mock' => new MockSpeechProvider(),
            'openai-compatible' => new OpenAiCompatibleSpeechProvider(
                (string) ($provider['endpoint'] ?? ''),
                self::hosts($provider),
                (string) ($provider['model'] ?? ''),
                (string) ($provider['voice'] ?? ''),
                self::apiKey($provider, 'ALMSIVI_TTS_API_KEY', $config),
                (int) ($provider['timeout_ms'] ?? 30_000),
            ),
            default => throw new RuntimeException('Unsupported speech provider driver.'),
        };
    }

    /** Build optional speech-to-text service configuration. */
    public static function speechToText(array $config): ?SpeechToTextProvider
    {
        $provider = self::section($config, 'stt_provider');
        return match ((string) ($provider['driver'] ?? 'mock')) {
            'disabled' => null,
            'mock' => new MockSpeechToTextProvider(),
            'openai-compatible' => new OpenAiCompatibleSpeechToTextProvider(
                (string) ($provider['endpoint'] ?? ''),
                self::hosts($provider),
                (string) ($provider['model'] ?? ''),
                self::apiKey($provider, 'ALMSIVI_STT_API_KEY', $config),
                (int) ($provider['timeout_ms'] ?? 30_000),
            ),
            default => throw new RuntimeException('Unsupported speech-to-text provider driver.'),
        };
    }

    /** Build an installation-selected TTS preset while credentials remain fixed environment references. */
    public static function speechForPreset(array $config,array $preset):SpeechProvider
    {
        $content=self::preset($preset,'tts_provider');$definition=ConnectorCatalog::definition('tts_provider',(string)$content['driver']);
        $endpoint=(string)$content['endpoint'];$driver=(string)$content['driver'];$parts=parse_url($endpoint);
        $host=is_array($parts)?(string)($parts['host']??''):'';$loopback=($parts['scheme']??null)==='http';
        $apiKey=self::environment((string)$definition['credential_environment'],$config);
        $voiceReferenceRoot=(string)($config['voice_storage_path']??'/var/lib/almsiviserver/voices');
        if(in_array($driver,['pockettts','omnivoice','chatterbox','xtts-fastapi','xtts'],true)
            &&!($driver==='pockettts'&&(str_contains($endpoint,':8086')||str_contains($endpoint,'/v1/audio/speech')))){
            return new XttsCompatibleSpeechProvider($endpoint,$driver,(string)$content['voice'],(string)$content['language'],
                (array)$content['options'],$apiKey,(int)$content['timeout_ms']);
        }
        if(in_array($driver,['pockettts','openai','kokoro','koboldcpp'],true)){
            $url=rtrim($endpoint,'/');if($driver==='pockettts'&&!str_ends_with($url,'/v1/audio/speech'))$url.='/v1/audio/speech';
            return new OpenAiCompatibleSpeechProvider($url,[$host],(string)($content['model']?:'tts-1'),
                (string)$content['voice'],$apiKey,(int)$content['timeout_ms'],$loopback,
                $driver==='pockettts'&&is_dir($voiceReferenceRoot)?$voiceReferenceRoot:null,
                $driver==='pockettts'?(string)$content['language']:null);
        }
        if(in_array($driver,['melotts','mimic3','piper-tts','stylettsv2'],true)){
            return new LocalSpeechConnectorProvider($endpoint,$driver,(string)$content['voice'],
                (string)$content['language'],(array)$content['options'],$apiKey,(int)$content['timeout_ms']);
        }
        if(in_array($driver,['11labs','azure','cartesia','convai','coqui-ai','deepgram','gcp','inworld'],true)){
            return new CloudSpeechConnectorProvider($endpoint,$driver,(string)$content['model'],
                (string)$content['voice'],(string)$content['language'],(array)$content['options'],$apiKey,(int)$content['timeout_ms']);
        }
        if($driver==='zonos_gradio')return new ZonosGradioSpeechProvider($endpoint,(string)$content['voice'],
            (string)$content['language'],(string)$content['model'],(string)($config['voice_storage_path']??'/var/lib/almsiviserver/voices'),
            (array)$content['options'],(int)$content['timeout_ms']);
        if($driver==='xvasynth')return new XvaSynthSpeechProvider($endpoint,(string)$content['voice'],
            (string)$content['language'],(array)$content['options'],(int)$content['timeout_ms']);
        throw new RuntimeException('Unsupported selected speech connector driver.');
    }

    /** Build an installation-selected STT preset while credentials remain fixed environment references. */
    public static function speechToTextForPreset(array $config,array $preset):SpeechToTextProvider
    {
        $content=self::preset($preset,'stt_provider');$definition=ConnectorCatalog::definition('stt_provider',(string)$content['driver']);
        $endpoint=rtrim((string)$content['endpoint'],'/');$driver=(string)$content['driver'];$parts=parse_url($endpoint);
        $host=is_array($parts)?(string)($parts['host']??''):'';$loopback=($parts['scheme']??null)==='http';
        if($driver==='none')throw new RuntimeException('provider_unavailable');
        $options=(array)$content['options'];
        if($driver==='parakeet'&&!str_ends_with($endpoint,'/v1/audio/transcriptions'))$endpoint.='/v1/audio/transcriptions';
        $translate=$driver==='whisper'&&($options['translate']??false)===true;
        if($translate&&str_ends_with($endpoint,'/v1/audio/transcriptions'))$endpoint=substr($endpoint,0,-strlen('transcriptions')).'translations';
        if(in_array($driver,['parakeet','localwhisper','whisper'],true))return new OpenAiCompatibleSpeechToTextProvider(
            $endpoint,[$host],$driver==='parakeet'?'whisper-1':(string)($content['model']?:'whisper-1'),
            self::environment((string)$definition['credential_environment'],$config),(int)$content['timeout_ms'],$loopback,
            $driver==='localwhisper'?(string)($options['file_field']??'audio_file'):'file',$driver!=='localwhisper',
            !$translate&&$driver!=='localwhisper'?(string)($options['prompt']??'ALMSIVI,Nerevarine,Morrowind'):'',!$translate);
        if(in_array($driver,['azure','deepgram','gemini','inworld'],true))return new CloudSpeechToTextConnectorProvider(
            $endpoint,$driver,(string)$content['model'],self::environment((string)$definition['credential_environment'],$config),
            $options,(int)$content['timeout_ms']);
        throw new RuntimeException('Unsupported selected speech-to-text connector driver.');
    }

    /** @return array<string,mixed> */
    private static function section(array $config, string $key): array
    {
        $section = $config[$key] ?? [];
        if (!is_array($section)) throw new RuntimeException($key . ' configuration is invalid.');
        return $section;
    }

    /** @param array<string,mixed> $provider @return list<string> */
    private static function hosts(array $provider): array
    {
        $hosts = $provider['allowed_hosts'] ?? [];
        return is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : [];
    }

    /** @param array<string,mixed> $provider */
    private static function apiKey(array $provider, string $defaultVariable, array $config): string
    {
        $variable = (string) ($provider['api_key_env'] ?? $defaultVariable);
        if ($variable === '' || preg_match('/^[A-Z_][A-Z0-9_]*$/D', $variable) !== 1) {
            throw new RuntimeException('Provider API key environment variable is invalid.');
        }
        return self::environment($variable,$config);
    }

    private static function preset(array $preset,string $kind):array
    {
        $content=$preset['content']??null;
        if(!is_array($content)||array_is_list($content))throw new RuntimeException('Connector preset snapshot is invalid.');
        return ConnectorCatalog::validate($kind,$content);
    }

    private static function environment(string $variable,array $config):string
    {
        if($variable===''||preg_match('/^[A-Z_][A-Z0-9_]*$/D',$variable)!==1)throw new RuntimeException('Connector credential environment is invalid.');
        $value=getenv($variable);if(is_string($value)&&$value!=='')return$value;
        $path=(string)($config['credential_storage_path']??'');
        return$path===''||!in_array($variable,CredentialStore::allowedVariables(),true)?'':(new CredentialStore($path))->resolve($variable);
    }
}
