<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use InvalidArgumentException;

final class ConnectorCatalog
{
    private const TTS = [
        'pockettts' => ['PocketTTS', true, 'ALMSIVI_TTS_POCKETTTS_API_KEY'],
        'omnivoice' => ['OmniVoice', true, 'ALMSIVI_TTS_OMNIVOICE_API_KEY'],
        'chatterbox' => ['Chatterbox', true, 'ALMSIVI_TTS_CHATTERBOX_API_KEY'],
        'xtts-fastapi' => ['XTTS FastAPI', true, 'ALMSIVI_TTS_XTTS_API_KEY'],
        'xtts' => ['XTTS', true, 'ALMSIVI_TTS_XTTS_API_KEY'],
        'kokoro' => ['Kokoro', true, 'ALMSIVI_TTS_KOKORO_API_KEY'],
        'openai' => ['OpenAI', false, 'ALMSIVI_TTS_OPENAI_API_KEY'],
        '11labs' => ['ElevenLabs', false, 'ALMSIVI_TTS_ELEVENLABS_API_KEY'],
        'azure' => ['Azure Speech', false, 'ALMSIVI_TTS_AZURE_API_KEY'],
        'cartesia' => ['Cartesia', false, 'ALMSIVI_TTS_CARTESIA_API_KEY'],
        'convai' => ['Convai', false, 'ALMSIVI_TTS_CONVAI_API_KEY'],
        'coqui-ai' => ['Coqui AI', false, 'ALMSIVI_TTS_COQUI_API_KEY'],
        'deepgram' => ['Deepgram', false, 'ALMSIVI_TTS_DEEPGRAM_API_KEY'],
        'gcp' => ['Google Cloud', false, 'ALMSIVI_TTS_GCP_API_KEY'],
        'inworld' => ['Inworld', false, 'ALMSIVI_TTS_INWORLD_API_KEY'],
        'koboldcpp' => ['KoboldCpp', true, 'ALMSIVI_TTS_KOBOLDCPP_API_KEY'],
        'melotts' => ['MeloTTS', true, 'ALMSIVI_TTS_MELOTTS_API_KEY'],
        'mimic3' => ['Mimic 3', true, 'ALMSIVI_TTS_MIMIC3_API_KEY'],
        'piper-tts' => ['Piper TTS', true, 'ALMSIVI_TTS_PIPER_API_KEY'],
        'stylettsv2' => ['StyleTTS2', true, 'ALMSIVI_TTS_STYLETTS2_API_KEY'],
        'xvasynth' => ['xVASynth', true, 'ALMSIVI_TTS_XVASYNTH_API_KEY'],
        'zonos_gradio' => ['Zonos Gradio', true, 'ALMSIVI_TTS_ZONOS_API_KEY'],
    ];

    private const STT = [
        'localwhisper' => ['Local Whisper', true, 'ALMSIVI_STT_LOCALWHISPER_API_KEY'],
        'parakeet' => ['Parakeet', true, 'ALMSIVI_STT_PARAKEET_API_KEY'],
        'whisper' => ['OpenAI Whisper', false, 'ALMSIVI_STT_OPENAI_API_KEY'],
        'azure' => ['Azure Speech', false, 'ALMSIVI_STT_AZURE_API_KEY'],
        'deepgram' => ['Deepgram', false, 'ALMSIVI_STT_DEEPGRAM_API_KEY'],
        'gemini' => ['Gemini', false, 'ALMSIVI_STT_GEMINI_API_KEY'],
        'inworld' => ['Inworld', false, 'ALMSIVI_STT_INWORLD_API_KEY'],
    ];

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
        '11labs'=>[['stability','Stability','number',0.0,1.0],['similarity_boost','Similarity boost','number',0.0,1.0],['style','Style','number',0.0,1.0],['use_speaker_boost','Use speaker boost','boolean']],
        'cartesia'=>[['speed','Speed','select',['slowest','slow','normal','fast','fastest']]],
        'coqui-ai'=>[['speed','Speed','number',0.25,4.0]],
        'inworld'=>[['speed','Speed','number',0.5,1.5],['temperature','Temperature','number',0.0,2.0]],
        'zonos_gradio'=>[['pitch_std','Pitch standard deviation','number',0.0,200.0],['speaking_rate','Speaking rate','number',1.0,40.0],['cfg_scale','CFG scale','number',0.0,20.0]],
        'xvasynth'=>[['game','Game ID','string'],['voice_prefix','Voice prefix','string'],['model_type','Model type','string'],['version','Model version','string'],['distro','WSL distro','string'],['pace','Pace','number',0.25,4.0],['vocoder','Vocoder','string'],['waveglow_path','WaveGlow path','string']],
    ];

    private const STT_OPTIONS = [
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
        'localwhisper'=>['http://127.0.0.1:9876/api/v0/transcribe','whisper-1','','en'],
        'parakeet'=>['http://127.0.0.1:8022','parakeet-tdt-0.6b-v3','','en'],
        'whisper'=>['https://api.openai.com/v1/audio/transcriptions','whisper-1','','en'],
        'azure'=>['https://westeurope.stt.speech.microsoft.com','default','','en-US'],
        'deepgram'=>['https://api.deepgram.com','nova-3','','en-US'],
        'gemini'=>['https://generativelanguage.googleapis.com','gemini-2.5-flash','','en'],
        'inworld'=>['https://api.inworld.ai','groq/whisper-large-v3','','en-US'],
    ];

    /** Return every CHIM-lineage connector exposed by the ALMSIVI management UI. */
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
        $allowed = ['driver', 'endpoint', 'model', 'voice', 'language', 'timeout_ms', 'options'];
        if (array_diff(array_keys($content), $allowed) !== []) throw new InvalidArgumentException('invalid_connector_content');
        $endpoint = trim((string) ($content['endpoint'] ?? ''));
        if ($endpoint === '' || strlen($endpoint) > 2048) throw new InvalidArgumentException('invalid_connector_endpoint');
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
        return [
            'driver' => $driver,
            'endpoint' => $endpoint,
            'model' => (string) ($content['model'] ?? ''),
            'voice' => (string) ($content['voice'] ?? ''),
            'language' => (string) ($content['language'] ?? 'en'),
            'timeout_ms' => $timeout,
            'options' => $options,
        ];
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
            if($row[2]==='select')$field['values']=$row[3];elseif(in_array($row[2],['number','integer'],true)){$field['minimum']=$row[3];$field['maximum']=$row[4];}
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
