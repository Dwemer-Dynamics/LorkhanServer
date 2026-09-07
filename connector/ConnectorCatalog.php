<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

final class ConnectorCatalog
{
    /** Fetch only Groq's fixed discovery URL; the optional transport permits offline credential-boundary tests. */
    public static function groqModels(string $apiKey, ?\Closure $transport = null): array
    {
        if ($apiKey === '' || strlen($apiKey) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $apiKey)) {
            throw new InvalidArgumentException('invalid_groq_api_key');
        }
        $url = 'https://api.groq.com/openai/v1/models';
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $apiKey];
        if ($transport !== null) $body = $transport($url, $headers);
        else {
            $handle = curl_init($url);
            if ($handle === false) throw new \RuntimeException('groq_catalogue_unavailable');
            $body = '';
            try {
                curl_setopt_array($handle, [
                    CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                    CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                    CURLOPT_CONNECTTIMEOUT_MS=>3000, CURLOPT_TIMEOUT_MS=>8000, CURLOPT_HTTPHEADER=>$headers,
                    CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body): int {
                        if (strlen($body) + strlen($chunk) > 1_048_576) return 0;
                        $body .= $chunk;
                        return strlen($chunk);
                    },
                ]);
                if (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                    throw new \RuntimeException('groq_catalogue_unavailable');
                }
            } finally { curl_close($handle); }
        }
        if (!is_string($body) || strlen($body) > 1_048_576) throw new InvalidArgumentException('invalid_groq_catalogue');
        $payload = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        if (!$payload instanceof \stdClass || !is_array($payload->data ?? null) || count($payload->data) > 5000) {
            throw new InvalidArgumentException('invalid_groq_catalogue');
        }
        $models = [];
        foreach ($payload->data as $model) {
            if (!$model instanceof \stdClass) continue;
            $model = (array)$model;
            $id = $model['id'] ?? null;
            if (!is_string($id) || $id === '' || trim($id) !== $id || strlen($id) > 256
                || !mb_check_encoding($id, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $id)) continue;
            $owner = $model['owned_by'] ?? 'Groq';
            $context = $model['context_window'] ?? null;
            $models[] = ['id'=>$id, 'owned_by'=>is_string($owner) && mb_check_encoding($owner, 'UTF-8') ? mb_substr($owner, 0, 128) : 'Groq',
                'context_window'=>is_int($context) && $context > 0 && $context <= 100_000_000 ? $context : null];
        }
        usort($models, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        return ['data'=>$models];
    }

    /** Keep provider slugs and display text public and bounded, just like the model catalogue. */
    public static function normalizeOpenRouterProviders(array $payload): array
    {
        if (!is_array($payload['data'] ?? null) || !array_is_list($payload['data']) || count($payload['data']) > 5000) {
            throw new InvalidArgumentException('invalid_provider_catalogue');
        }
        $providers = [];
        foreach ($payload['data'] as $provider) {
            if (!is_array($provider)) continue;
            $slug = $provider['slug'] ?? null;
            if (!is_string($slug) || $slug === '' || strlen($slug) > 128 || trim($slug) !== $slug
                || !mb_check_encoding($slug, 'UTF-8') || preg_match('/[\x00-\x1f\x7f,]/', $slug)) continue;
            $row = ['slug' => $slug];
            foreach (['name' => 512, 'privacy_policy_url' => 2048, 'terms_of_service_url' => 2048] as $field => $limit) {
                $row[$field] = is_string($provider[$field] ?? null) ? mb_substr($provider[$field], 0, $limit) : '';
            }
            $providers[] = $row;
        }
        return ['data' => $providers];
    }

    /** Return only bounded public model fields consumed by the OpenRouter picker. */
    public static function normalizeOpenRouterModels(array $payload): array
    {
        if (!is_array($payload['data'] ?? null) || !array_is_list($payload['data']) || count($payload['data']) > 5000) {
            throw new InvalidArgumentException('invalid_model_catalogue');
        }
        $models = [];
        foreach ($payload['data'] as $model) {
            if (!is_array($model)) continue;
            $id = $model['id'] ?? $model['canonical_slug'] ?? null;
            if (!is_string($id) || trim($id) === '' || strlen($id) > 256) continue;
            $prices = [];
            foreach (['prompt', 'completion'] as $key) {
                $value = $model['pricing'][$key] ?? null;
                $prices[$key] = is_numeric($value) && is_finite((float)$value * 1000000) && (float)$value >= 0 ? (string)$value : null;
            }
            $context = $model['top_provider']['context_length'] ?? $model['context_length'] ?? null;
            $models[] = [
                'id' => $id,
                'name' => is_string($model['name'] ?? null) ? mb_substr($model['name'], 0, 512) : '',
                'description' => is_string($model['description'] ?? null) ? mb_substr($model['description'], 0, 4000) : '',
                'pricing' => $prices,
                'context_length' => is_int($context) && $context > 0 ? $context : null,
            ];
        }
        return ['data' => $models];
    }

    private const TTS = [
        'pockettts' => ['PocketTTS', true, 'LORKHAN_TTS_POCKETTTS_API_KEY'],
        'omnivoice' => ['OmniVoice', true, 'LORKHAN_TTS_OMNIVOICE_API_KEY'],
        'chatterbox' => ['Chatterbox', true, 'LORKHAN_TTS_CHATTERBOX_API_KEY'],
        'xtts-fastapi' => ['XTTS FastAPI', true, 'LORKHAN_TTS_XTTS_API_KEY'],
        'xtts' => ['XTTS', true, 'LORKHAN_TTS_XTTS_API_KEY'],
        'kokoro' => ['Kokoro', true, 'LORKHAN_TTS_KOKORO_API_KEY'],
        'openai' => ['OpenAI', false, 'LORKHAN_TTS_OPENAI_API_KEY'],
        '11labs' => ['ElevenLabs', false, 'LORKHAN_TTS_ELEVENLABS_API_KEY'],
        'azure' => ['Azure Speech', false, 'LORKHAN_TTS_AZURE_API_KEY'],
        'cartesia' => ['Cartesia', false, 'LORKHAN_TTS_CARTESIA_API_KEY'],
        'convai' => ['Convai', false, 'LORKHAN_TTS_CONVAI_API_KEY'],
        'coqui-ai' => ['Coqui AI', false, 'LORKHAN_TTS_COQUI_API_KEY'],
        'deepgram' => ['Deepgram', false, 'LORKHAN_TTS_DEEPGRAM_API_KEY'],
        'gcp' => ['Google Cloud', false, 'LORKHAN_TTS_GCP_API_KEY'],
        'inworld' => ['Inworld', false, 'LORKHAN_TTS_INWORLD_API_KEY'],
        'koboldcpp' => ['KoboldCpp', true, 'LORKHAN_TTS_KOBOLDCPP_API_KEY'],
        'melotts' => ['MeloTTS', true, 'LORKHAN_TTS_MELOTTS_API_KEY'],
        'mimic3' => ['Mimic 3', true, 'LORKHAN_TTS_MIMIC3_API_KEY'],
        'piper-tts' => ['Piper TTS', true, 'LORKHAN_TTS_PIPER_API_KEY'],
        'stylettsv2' => ['StyleTTS2', true, 'LORKHAN_TTS_STYLETTS2_API_KEY'],
        'xvasynth' => ['xVASynth', true, 'LORKHAN_TTS_XVASYNTH_API_KEY'],
        'zonos_gradio' => ['Zonos Gradio', true, 'LORKHAN_TTS_ZONOS_API_KEY'],
    ];

    private const STT = [
        'none' => ['Disabled', true, ''],
        'localwhisper' => ['Local Whisper', true, ''],
        'parakeet' => ['Parakeet', true, 'LORKHAN_TTS_OPENAI_API_KEY'],
        'whisper' => ['OpenAI Whisper', false, 'LORKHAN_TTS_OPENAI_API_KEY'],
        'azure' => ['Azure Speech', false, 'LORKHAN_TTS_AZURE_API_KEY'],
        'deepgram' => ['Deepgram', false, 'LORKHAN_TTS_DEEPGRAM_API_KEY'],
        'gemini' => ['Gemini', false, 'LORKHAN_STT_GEMINI_API_KEY'],
        'inworld' => ['Inworld', false, 'LORKHAN_TTS_INWORLD_API_KEY'],
    ];

    /**
     * TTS drivers whose voice is the name of a WAV sample held in this server's local voice
     * library. Every other driver names a voice the provider owns, so a local sample name is
     * not a voice it can speak.
     */
    public const SAMPLE_LIBRARY_TTS_DRIVERS = ['pockettts', 'omnivoice', 'chatterbox', 'xtts-fastapi', 'xtts'];

    /** Keep Morrowind voice names only for adapters that can consume or register their samples. */
    public static function usesLocalVoiceSamples(string $driver):bool
    {
        return in_array($driver,array_merge(self::SAMPLE_LIBRARY_TTS_DRIVERS,['inworld','cartesia','zonos_gradio']),true);
    }

    private const TTS_OPTIONS = [
        'pockettts'=>[['speed','Speed','number',0.25,4.0],['temperature','Temperature','number',0.0,5.0]],
        'omnivoice'=>[['speed','Speed','number',0.25,4.0]],
        'chatterbox'=>[['speed','Speed','number',0.25,4.0],['exaggeration','Exaggeration','number',0.0,5.0],['cfg_weight','CFG weight','number',0.0,20.0]],
        'xtts-fastapi'=>[['speed','Speed','number',0.25,4.0],['temperature','Temperature','number',0.0,5.0],['top_p','Top P','number',0.0,1.0],['top_k','Top K','integer',0,1000],['repetition_penalty','Repetition penalty','number',0.0,20.0]],
        'xtts'=>[['speed','Speed','number',0.25,4.0],['temperature','Temperature','number',0.0,5.0],['top_p','Top P','number',0.0,1.0],['top_k','Top K','integer',0,1000],['repetition_penalty','Repetition penalty','number',0.0,20.0]],
        'melotts'=>[['speed','Speed','number',0.25,4.0]],
        'mimic3'=>[['rate','Rate','number',0.2,4.0]],
        'piper-tts'=>[['length_scale','Length scale','number',0.2,4.0],['noise_scale','Noise scale','number',0.0,2.0],['noise_w_scale','Noise width scale','number',0.0,2.0],['speaker','Speaker','string'],['speaker_id','Speaker ID','integer',0,2147483647]],
        'stylettsv2'=>[['alpha','Alpha','number',0.0,1.0],['beta','Beta','number',0.0,1.0],['diffusion_steps','Diffusion steps','integer',1,100],['embedding_scale','Embedding scale','number',0.0,10.0],['session_id','Session ID','integer',1,2147483647]],
        'openai'=>[['instructions','Instructions','longstring',4096]],
        'kokoro'=>[['speed','Speed','number',0.25,4.0]],
        'deepgram'=>[['bitrate','Bitrate','integer',8000,48000]],
        'azure'=>[['fixedMood','Fixedmood','string'],['region','Region','string'],
            ['volume','Volume','integer',0,100],['rate','Rate','number',0.5,2.0],['countour','Countour','string']],
        '11labs'=>[['optimize_streaming_latency','Optimize Streaming Latency','integer',0,4],
            ['stability','Stability','number',0.0,1.0],['similarity_boost','Similarity Boost','number',0.0,1.0],
            ['style','Style','number',0.0,1.0],['speed','Speed','number',0.25,4.0],
            ['use_speaker_boost','Use Speaker Boost','boolean'],
            ['apply_text_normalization','Apply Text Normalization','select',['auto','on','off']],
            ['apply_language_text_normalization','Apply Language Text Normalization','boolean'],
            ['v3_audio_tags','V3 Audio Tags','longstring',1024]],
        'cartesia'=>[['speed','Speed','select',['slowest','slow','normal','fast','fastest']]],
        'coqui-ai'=>[['speed','Speed','number',0.25,4.0]],
        'inworld'=>[['workspace','Workspace','string'],['temperature','Temperature','number',0.0,2.0],['speed','Speed','number',0.5,1.5]],
        'zonos_gradio'=>[['pitch_std','Pitch standard deviation','number',0.0,200.0],['speaking_rate','Speaking rate','number',1.0,40.0],['cfg_scale','CFG scale','number',0.0,20.0]],
        'xvasynth'=>[['game','Game ID','string'],['voice_prefix','Voice prefix','string'],['model_type','Model type','string'],['version','Model version','string'],['distro','WSL distro','string'],['pace','Pace','number',0.25,4.0],['vocoder','Vocoder','string'],['waveglow_path','WaveGlow path','string']],
    ];

    private const STT_OPTIONS = [
        'localwhisper'=>[['file_field','Audio form field','select',['audio_file','file']]],
        'whisper'=>[['translate','Translate to English','boolean']],
        'azure'=>[['profanity','Profanity handling','select',['masked','removed','raw']]],
        'gemini'=>[['include_tone','Prefix detected vocal tone','boolean']],
    ];

    private const TTS_DEFAULTS = [
        'pockettts'=>['http://127.0.0.1:8086','pocket-tts','default','en'],
        'omnivoice'=>['http://127.0.0.1:8021','k2-fsa/OmniVoice','default','en'],
        'chatterbox'=>['http://127.0.0.1:8020','default','default','en'],
        'xtts-fastapi'=>['http://127.0.0.1:8020','default','default','en'],
        'xtts'=>['http://127.0.0.1:8020','default','default','en'],
        'kokoro'=>['http://127.0.0.1:8880/v1/audio/speech','kokoro','default','en'],
        'openai'=>['https://api.openai.com/v1/audio/speech','tts-1','alloy','en'],
        '11labs'=>['https://api.elevenlabs.io','eleven_multilingual_v2','','en'],
        'azure'=>['https://westeurope.tts.speech.microsoft.com','default','','en-US'],
        'cartesia'=>['https://api.cartesia.ai','sonic-3','','en'],
        'convai'=>['https://api.convai.com/tts','default','','en-US'],
        'coqui-ai'=>['https://app.coqui.ai','default','','en'],
        'deepgram'=>['https://api.deepgram.com','aura-2-thalia-en','aura-2-thalia-en','en-US'],
        'gcp'=>['https://texttospeech.googleapis.com','default','','en-US'],
        'inworld'=>['https://api.inworld.ai','inworld-tts-2','','en-US'],
        'koboldcpp'=>['http://127.0.0.1:5001/api/extra/tts','default','default','en'],
        'melotts'=>['http://127.0.0.1:8084','default','default','EN'],
        'mimic3'=>['http://127.0.0.1:59125','default','default','en_US'],
        'piper-tts'=>['http://127.0.0.1:5000','default','default','en'],
        'stylettsv2'=>['http://127.0.0.1:8000','default','default','en'],
        'xvasynth'=>['http://192.168.0.1:8008','default','default','en'],
        'zonos_gradio'=>['http://127.0.0.1:7860','default','default','en'],
    ];

    private const STT_DEFAULTS = [
        'none'=>['disabled://stt','disabled','','en'],
        'localwhisper'=>['http://127.0.0.1:9876/api/v0/transcribe','whisper-1','','en'],
        'parakeet'=>['http://127.0.0.1:8022','parakeet-tdt-0.6b-v3','','en'],
        'whisper'=>['https://api.openai.com/v1/audio/transcriptions','whisper-1','','en'],
        'azure'=>['https://westeurope.stt.speech.microsoft.com','default','','en-US'],
        'deepgram'=>['https://api.deepgram.com','nova-3','','en-US'],
        'gemini'=>['https://generativelanguage.googleapis.com','gemini-2.5-flash','','en'],
        'inworld'=>['https://api.inworld.ai','groq/whisper-large-v3','','en-US'],
    ];

    /** Return every CHIM-lineage connector exposed by the LORKHAN management UI. */
    public static function all(string $kind): array
    {
        $catalog = self::catalog($kind);
        $rows = [];
        foreach ($catalog as $driver => [$label, $local, $credentialEnvironment]) {
            $rows[] = [
                'driver' => $driver,
                'label' => $label,
                'local' => $local,
                'credential_environment' => $credentialEnvironment,
            ];
        }
        return $rows;
    }

    /** Validate one stored connector preset while keeping credentials outside the database. */
    public static function validate(string $kind, array $content): array
    {
        $catalog = self::catalog($kind);
        $driver = $content['driver'] ?? null;
        if (!is_string($driver) || !isset($catalog[$driver])) throw new InvalidArgumentException('invalid_connector_driver');
        $allowed = ['driver', 'endpoint', 'model', 'voice', 'language', 'timeout_ms', 'options', 'credential'];
        if (array_diff(array_keys($content), $allowed) !== []) throw new InvalidArgumentException('invalid_connector_content');
        $endpoint = trim((string) ($content['endpoint'] ?? ''));
        if ($endpoint === '' || strlen($endpoint) > 2048 || ($driver !== 'none' && parse_url($endpoint, PHP_URL_HOST) === null)) {
            throw new InvalidArgumentException('invalid_connector_endpoint');
        }
        foreach (['model' => 256, 'voice' => 512, 'language' => 35] as $field => $limit) {
            if (isset($content[$field]) && (!is_string($content[$field]) || strlen($content[$field]) > $limit || !mb_check_encoding($content[$field], 'UTF-8'))) {
                throw new InvalidArgumentException('invalid_connector_' . $field);
            }
        }
        $timeout = (int) ($content['timeout_ms'] ?? 30_000);
        if ($timeout < 1000 || $timeout > 120_000) throw new InvalidArgumentException('invalid_connector_timeout');
        $options = $content['options'] ?? [];
        if (!is_array($options) || ($options !== [] && array_is_list($options)) || strlen(json_encode($options, JSON_THROW_ON_ERROR)) > 16_384) {
            throw new InvalidArgumentException('invalid_connector_options');
        }
        if ($kind === 'tts_provider' && $driver === 'inworld' && isset($options['workspace'])) {
            if (!is_string($options['workspace'])) throw new InvalidArgumentException('invalid_inworld_workspace');
            $options['workspace'] = CloudVoiceLibrary::normalizeWorkspace($options['workspace']);
        }
        // Validate newly exposed controls on every ingress, including JSON imports and API revisions.
        $typedFields = $kind === 'tts_provider' ? match ($driver) {
            'openai'=>['instructions'], 'kokoro'=>['speed'],
            'azure'=>['fixedMood','region','volume','rate','countour'],
            'deepgram'=>['bitrate'],
            '11labs'=>['optimize_streaming_latency','speed','apply_text_normalization','apply_language_text_normalization','v3_audio_tags'],
            default=>[],
        } : [];
        foreach (self::optionFields($kind, $driver) as $field) {
            $name = $field['name'];
            if (!in_array($name, $typedFields, true) || !array_key_exists($name, $options)) continue;
            $value = $options[$name];
            $valid = match ($field['type']) {
                'longstring'=>is_string($value) && strlen($value) <= $field['maxlength'] && mb_check_encoding($value, 'UTF-8'),
                'string'=>is_string($value) && strlen($value) <= 512 && mb_check_encoding($value, 'UTF-8'),
                'select'=>is_string($value) && in_array($value, $field['values'], true),
                'boolean'=>is_bool($value),
                'integer'=>is_int($value) && $value >= $field['minimum'] && $value <= $field['maximum'],
                'number'=>(is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= $field['minimum'] && $value <= $field['maximum'],
                default=>false,
            };
            if (!$valid) throw new InvalidArgumentException('invalid_connector_option_' . $name);
        }
        if ($kind === 'tts_provider' && $driver === 'deepgram' && isset($options['bitrate'])
            && !in_array($options['bitrate'], [8000,16000,24000,32000,48000], true)) throw new InvalidArgumentException('invalid_connector_option_bitrate');
        if ($kind === 'tts_provider' && $driver === 'azure' && trim($options['region'] ?? '') !== '') {
            $region = strtolower(trim($options['region']));
            if (!preg_match('/^[a-z][a-z0-9]{1,39}$/D', $region)) throw new InvalidArgumentException('invalid_connector_option_region');
            $options['region'] = $region;
            // Resolve the explicit regional setting before the provider applies its outbound host policy.
            $endpoint = 'https://' . $region . '.tts.speech.microsoft.com';
        }
        $result = [
            'driver' => $driver,
            'endpoint' => $endpoint,
            'model' => (string) ($content['model'] ?? ''),
            'voice' => (string) ($content['voice'] ?? ''),
            'language' => (string) ($content['language'] ?? 'en'),
            'timeout_ms' => $timeout,
            'options' => $options,
        ];
        if (array_key_exists('credential', $content)) {
            $reference = $content['credential'];
            if (!is_string($reference) || ($reference !== 'none' && !CredentialStore::isAllowed($reference))) {
                throw new InvalidArgumentException('invalid_connector_credential');
            }
            $result['credential'] = $reference;
        }
        return $result;
    }

    public static function definition(string $kind, string $driver): array
    {
        $catalog = self::catalog($kind);
        if (!isset($catalog[$driver])) throw new InvalidArgumentException('invalid_connector_driver');
        [$label, $local, $credentialEnvironment] = $catalog[$driver];
        return ['driver' => $driver, 'label' => $label, 'local' => $local,
            'credential_environment' => $credentialEnvironment];
    }

    /** Return labelled, runtime-supported advanced fields for one connector driver. */
    public static function optionFields(string $kind,string $driver):array
    {
        self::definition($kind,$driver);
        $rows=($kind==='tts_provider'?self::TTS_OPTIONS:self::STT_OPTIONS)[$driver]??[];$result=[];
        foreach($rows as$row){$field=['name'=>$row[0],'label'=>$row[1],'type'=>$row[2]];
            if($row[2]==='select')$field['values']=$row[3];elseif($row[2]==='longstring')$field['maxlength']=$row[3];elseif(in_array($row[2],['number','integer'],true)){$field['minimum']=$row[3];$field['maximum']=$row[4];}
            $result[]=$field;}
        return$result;
    }

    /** Return safe create-form defaults without treating them as immutable runtime configuration. */
    public static function defaults(string $kind,string $driver):array
    {
        self::definition($kind,$driver);$row=($kind==='tts_provider'?self::TTS_DEFAULTS:self::STT_DEFAULTS)[$driver]??null;
        if(!is_array($row)||count($row)!==4)throw new InvalidArgumentException('missing_connector_defaults');
        return['endpoint'=>$row[0],'model'=>$row[1],'voice'=>$row[2],'language'=>$row[3]];
    }

    private static function catalog(string $kind): array
    {
        return match ($kind) {
            'tts_provider' => self::TTS,
            'stt_provider' => self::STT,
            default => throw new InvalidArgumentException('invalid_connector_kind'),
        };
    }
}
