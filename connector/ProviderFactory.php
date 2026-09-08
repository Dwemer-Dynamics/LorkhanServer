<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

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
                self::apiKey($provider, 'LORKHAN_LLM_API_KEY', $config),
                (int) ($provider['timeout_ms'] ?? 30_000),
                (bool) ($provider['disable_reasoning'] ?? false),
                (array) ($provider['options'] ?? []),
                (bool) ($provider['allow_loopback_http'] ?? false),
                (bool) ($provider['direct_connection'] ?? false),
            ),
            default => throw new RuntimeException('Unsupported dialogue provider driver.'),
        };
    }

    /** Build a turn-scoped provider while resolving only server-held credential references. */
    public static function dialogueForSlot(array $config, array $slot): Provider
    {
        $config['provider']=self::slotSection($config,$slot);
        return self::dialogue($config);
    }

    /** Resolve a frozen connector revision without letting an explicit endpoint inherit a runtime key. */
    private static function slotSection(array $config,array $slot):array
    {
        // The optional historical label is display metadata, never a provider option.
        if(array_key_exists('name',$slot)){
            if(!is_string($slot['name'])||strlen($slot['name'])>256||!mb_check_encoding($slot['name'],'UTF-8'))
                throw new RuntimeException('Provider slot snapshot is invalid.');
            unset($slot['name']);
        }
        $keys=array_keys($slot);sort($keys);
        if($keys!==['configuration_id','content','revision']
            ||!is_string($slot['configuration_id'])
            ||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$slot['configuration_id'])!==1
            ||!is_int($slot['revision'])||$slot['revision']<1
            ||!is_array($slot['content'])||array_is_list($slot['content']))
            throw new RuntimeException('Provider slot snapshot is invalid.');
        $content=LlmConnector::validate($slot['content']);
        if($content['driver']==='mock')return$content;
        if($content['driver']==='configured'){
            $section=self::section($config,'provider');$section['model']=$content['model'];
            if(isset($content['timeout_ms']))$section['timeout_ms']=$content['timeout_ms'];
            if(isset($content['options']))$section['options']=array_replace((array)($section['options']??[]),$content['options']);
            if(array_key_exists('credential',$content))$section['credential']=$content['credential'];
            return$section;
        }
        return$content+['allowed_hosts'=>[(string)parse_url($content['endpoint'],PHP_URL_HOST)],
            'allow_loopback_http'=>str_starts_with($content['endpoint'],'http://'),'direct_connection'=>true];
    }

    /** Build the profile-routed extractor without exposing connector credentials or dialogue contracts. */
    public static function oghmaTopicExtractorForSlot(array $config,array $slot,?int $timeoutMs=null):OghmaTopicExtractor
    {
        $provider=self::slotSection($config,$slot);
        if(($provider['driver']??'mock')==='mock')return new MockOghmaTopicExtractor();
        if($provider['driver']!=='openai-compatible')throw new RuntimeException('Unsupported topic extraction provider driver.');
        $timeout=$timeoutMs===null
            ?max(1000,min(15_000,(int)($provider['timeout_ms']??15_000)))
            :max(250,min(3000,$timeoutMs));
        return new OpenAiCompatibleOghmaTopicExtractor((string)($provider['endpoint']??''),self::hosts($provider),(string)$provider['model'],
            self::apiKey($provider,'LORKHAN_LLM_API_KEY',$config),$timeout,(bool)($provider['disable_reasoning']??false),
            (array)($provider['options']??[]),(bool)($provider['allow_loopback_http']??false),(bool)($provider['direct_connection']??false));
    }

    /** Apply one frozen connector revision to the strict profile-generation adapter. */
    public static function profileGenerationForSlot(array $config, array $slot): ProfileGenerationProvider
    {
        $config['provider'] = self::slotSection($config, $slot);
        return self::profileGeneration($config);
    }

    /** Build the task-specific profile generator from the same server-owned LLM configuration. */
    public static function profileGeneration(array $config):ProfileGenerationProvider
    {
        $provider=self::section($config,'provider');
        return match((string)($provider['driver']??'mock')){
            'mock'=>new MockProfileGenerationProvider(),
            'openai-compatible'=>new OpenAiCompatibleProfileGenerationProvider((string)($provider['endpoint']??''),self::hosts($provider),
                (string)($provider['model']??''),self::apiKey($provider,'LORKHAN_LLM_API_KEY',$config),(int)($provider['timeout_ms']??30_000),
                (bool)($provider['disable_reasoning']??false),(array)($provider['options']??[]),
                (bool)($provider['allow_loopback_http']??false),(bool)($provider['direct_connection']??false)),
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
                self::apiKey($provider, 'LORKHAN_TTS_API_KEY', $config),
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
                self::apiKey($provider, 'LORKHAN_STT_API_KEY', $config),
                (int) ($provider['timeout_ms'] ?? 30_000),
            ),
            default => throw new RuntimeException('Unsupported speech-to-text provider driver.'),
        };
    }

    /** Build the server-held DeepL adapter for one already-validated policy snapshot. */
    public static function translation(array $config,array $policy):TranslationProvider
    {
        $content=TranslationPolicy::validate($policy);
        if($content['provider']!=='deepl')throw new RuntimeException('provider_unavailable');
        return new DeepLTranslationProvider($content['endpoint'],self::environment('LORKHAN_DEEPL_API_KEY',$config),
            max(1000,min(120_000,(int)($config['translation_timeout_ms']??30_000))));
    }

    /** Build an installation-selected TTS preset with a private, allowlisted credential reference. */
    public static function speechForPreset(array $config,array $preset):SpeechProvider
    {
        $content=self::preset($preset,'tts_provider');$definition=ConnectorCatalog::definition('tts_provider',(string)$content['driver']);
        $endpoint=(string)$content['endpoint'];$driver=(string)$content['driver'];$parts=parse_url($endpoint);
        $host=is_array($parts)?(string)($parts['host']??''):'';$loopback=($parts['scheme']??null)==='http';
        $credentialVariable=(string)($content['credential']??$definition['credential_environment']);
        $apiKey=in_array($credentialVariable,['','none'],true)?'':self::environment($credentialVariable,$config);
        $voiceReferenceRoot=(string)($config['voice_storage_path']??'/var/lib/lorkhanserver/voices');
        if($driver==='pockettts')return new PocketTtsSpeechProvider($endpoint,(string)($content['model']?:'pocket-tts'),
            (string)$content['voice'],(string)$content['language'],(array)$content['options'],$apiKey,(int)$content['timeout_ms'],
            is_dir($voiceReferenceRoot)?$voiceReferenceRoot:null);
        if(in_array($driver,['omnivoice','chatterbox','xtts-fastapi','xtts'],true)){
            return new XttsCompatibleSpeechProvider($endpoint,$driver,(string)$content['voice'],(string)$content['language'],
                (array)$content['options'],$apiKey,(int)$content['timeout_ms'],
                new LocalVoiceResolver($endpoint,$driver,$voiceReferenceRoot,$apiKey,(int)$content['timeout_ms']));
        }
        if(in_array($driver,['openai','kokoro','koboldcpp'],true)){
            $url=rtrim($endpoint,'/');
            return new OpenAiCompatibleSpeechProvider($url,[$host],(string)($content['model']?:'tts-1'),
                (string)$content['voice'],$apiKey,(int)$content['timeout_ms'],$loopback,null,null,
                array_intersect_key((array)$content['options'],array_flip($driver==='openai'?['instructions']:($driver==='kokoro'?['speed']:[]))));
        }
        if(in_array($driver,['melotts','mimic3','piper-tts','stylettsv2'],true)){
            return new LocalSpeechConnectorProvider($endpoint,$driver,(string)$content['voice'],
                (string)$content['language'],(array)$content['options'],$apiKey,(int)$content['timeout_ms']);
        }
        if(in_array($driver,['11labs','azure','cartesia','convai','coqui-ai','deepgram','gcp','inworld'],true)){
            $resolveVoice=null;
            if(in_array($driver,['inworld','cartesia'],true)){
                $credentials=new CredentialStore((string)($config['credential_storage_path']??'/var/lib/lorkhanserver/credentials/provider-keys.json'));
                $workspace = $driver === 'inworld' ? (string)($content['options']['workspace'] ?? '') : '';
                $resolver=new InworldVoiceResolver(new CloudVoiceLibrary($credentials,null,[$driver=>$credentialVariable],$workspace),$credentials,$voiceReferenceRoot,$driver,$credentialVariable,$workspace);
                $resolveVoice=$resolver->resolve(...);
            }
            return new CloudSpeechConnectorProvider($endpoint,$driver,(string)$content['model'],
                (string)$content['voice'],(string)$content['language'],(array)$content['options'],$apiKey,(int)$content['timeout_ms'],$resolveVoice);
        }
        if($driver==='zonos_gradio')return new ZonosGradioSpeechProvider($endpoint,(string)$content['voice'],
            (string)$content['language'],(string)$content['model'],(string)($config['voice_storage_path']??'/var/lib/lorkhanserver/voices'),
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
        // Old records retain their provider default; an explicit badge or None overrides it.
        $credentialVariable=(string)($content['credential']??$definition['credential_environment']);
        $sttApiKey=in_array($credentialVariable,['','none'],true)?'':self::environment($credentialVariable,$config);
        if($driver==='parakeet'&&!str_ends_with($endpoint,'/v1/audio/transcriptions'))$endpoint.='/v1/audio/transcriptions';
        $translate=$driver==='whisper'&&($options['translate']??false)===true;
        if($translate&&str_ends_with($endpoint,'/v1/audio/transcriptions'))$endpoint=substr($endpoint,0,-strlen('transcriptions')).'translations';
        if(in_array($driver,['parakeet','localwhisper','whisper'],true))return new OpenAiCompatibleSpeechToTextProvider(
            $endpoint,[$host],$driver==='parakeet'?'whisper-1':(string)($content['model']?:'whisper-1'),
            $sttApiKey,(int)$content['timeout_ms'],$loopback,
            $driver==='localwhisper'?(string)($options['file_field']??'audio_file'):'file',$driver!=='localwhisper',
            !$translate&&$driver!=='localwhisper'?(string)($options['prompt']??'LORKHAN,Nerevarine,Morrowind'):'',!$translate);
        if(in_array($driver,['azure','deepgram','gemini','inworld'],true))return new CloudSpeechToTextConnectorProvider(
            $endpoint,$driver,(string)$content['model'],$sttApiKey,
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

    /** Resolve the same private credential for provider requests and authenticated model discovery; never serialize it. */
    public static function apiKey(array $provider, string $defaultVariable, array $config): string
    {
        if(array_key_exists('credential',$provider)){
            $reference=$provider['credential'];
            if(!is_string($reference)||($variable=LlmConnector::credentialVariable($reference))===null)throw new RuntimeException('Invalid LLM credential reference.');
            return$variable===''?'':self::environment($variable,$config);
        }
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
        return$path===''||!CredentialStore::isAllowed($variable)?'':(new CredentialStore($path))->resolve($variable);
    }
}
