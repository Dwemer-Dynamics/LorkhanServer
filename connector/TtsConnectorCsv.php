<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Herika's seven-column connector CSV, adapted to native speech settings without badge ownership. */
final class TtsConnectorCsv
{
    public const COLUMNS = ['id','label','driver','metadata','api_badge_id','url','voice_field'];
    private const VOICE_FIELDS = ['xvasynth'=>'model','mimic3'=>'voice','azure'=>'voice','11labs'=>'voice_id',
        'openai'=>'voice','koboldcpp'=>'voice','deepgram'=>'model'];
    private const OPTION_NAMES = ['model_type'=>'modelType','waveglow_path'=>'waveglowPath','distro'=>'distroname',
        'paralinguistic_tags_enabled'=>'PARALINGUISTIC_TAGS_ENABLED','paralinguistic_tags_prompt'=>'PARALINGUISTIC_TAGS_PROMPT',
        'paralinguistic_tags_list'=>'PARALINGUISTIC_TAGS_LIST'];

    public static function encode(string $name, array $content): string
    {
        $content = ConnectorCatalog::validate('tts_provider', $content);
        $driver = $content['driver'];
        $metadata = [];
        foreach ($content['options'] as $key=>$value) $metadata[self::OPTION_NAMES[$key] ?? $key] = $value;
        $metadata[$driver === 'xvasynth' ? 'base_lang' : 'language'] = $content['language'];
        $metadata[in_array($driver,['inworld','cartesia','11labs','openai'],true) ? 'model_id' : 'model'] = $content['model'];
        $voiceField = self::VOICE_FIELDS[$driver] ?? 'voiceid';
        if ($voiceField === 'model') $metadata['lorkhan_model'] = $content['model'];
        $metadata[$voiceField] = $content['voice'];
        $metadata['lorkhan_timeout_ms'] = $content['timeout_ms'];
        $stream = fopen('php://temp','w+b');
        try {
            fputcsv($stream,self::COLUMNS,',','"','\\');
            // Source IDs and API badge IDs are intentionally blank: neither is portable ownership.
            fputcsv($stream,['',$name,$driver,json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'',$content['endpoint'],$voiceField],',','"','\\');
            rewind($stream);
            return (string)stream_get_contents($stream);
        } finally { fclose($stream); }
    }

    public static function decode(string $csv): array
    {
        if (strlen($csv)>1_048_576 || !mb_check_encoding($csv,'UTF-8') || str_contains($csv,"\0"))
            throw new InvalidArgumentException('invalid_tts_csv');
        $stream=fopen('php://temp','w+b');$rows=[];
        try {
            fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($stream);
            while (($row=fgetcsv($stream,1_048_577,',','"','\\'))!==false) {
                if (count(array_filter($row,static fn($value):bool=>trim((string)$value)!==''))===0) continue;
                $rows[]=$row;
                if (count($rows)>2) throw new InvalidArgumentException('tts_csv_one_connector_per_file');
            }
        } finally { fclose($stream); }
        if (count($rows)!==2 || count($rows[0])!==7 || count($rows[1])!==7) throw new InvalidArgumentException('invalid_tts_csv');
        $columns=array_map(static fn($key):string=>strtolower(trim((string)$key)),$rows[0]);
        if (count(array_unique($columns))!==7 || array_diff(self::COLUMNS,$columns)!==[]) throw new InvalidArgumentException('invalid_tts_csv_columns');
        $row=array_combine($columns,$rows[1]);$driver=strtolower(trim($row['driver']));
        $driver=['xttsfastapi'=>'xtts-fastapi','pipertts'=>'piper-tts','eleven_labs'=>'11labs'][$driver] ?? $driver;
        $defaults=ConnectorCatalog::defaults('tts_provider',$driver);
        try { $metadata=json_decode(trim($row['metadata']) ?: '{}',true,32,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new InvalidArgumentException('invalid_tts_csv_metadata'); }
        if (!is_array($metadata) || ($metadata!==[] && array_is_list($metadata))) throw new InvalidArgumentException('invalid_tts_csv_metadata');
        $voiceField=self::VOICE_FIELDS[$driver] ?? 'voiceid';
        $modelField=in_array($driver,['inworld','cartesia','11labs','openai'],true) ? 'model_id' : 'model';
        $content=['driver'=>$driver]+$defaults;
        $content['endpoint']=trim($row['url']) ?: ($metadata['endpoint'] ?? $metadata['url'] ?? $metadata['URL'] ?? $defaults['endpoint']);
        $content['model']=$metadata['lorkhan_model'] ?? ($voiceField==='model' ? $defaults['model'] : ($metadata[$modelField] ?? $defaults['model']));
        $content['voice']=$metadata[$voiceField] ?? $defaults['voice'];
        $content['language']=$metadata[$driver==='xvasynth'?'base_lang':'language'] ?? $defaults['language'];
        $content['timeout_ms']=$metadata['lorkhan_timeout_ms'] ?? 30_000;
        $content['credential']='none';
        // A provider cache path cannot be bound to a local sample by copying another server's filename.
        if (trim((string)($metadata['cached_voice_path']??''))!=='') throw new InvalidArgumentException('tts_csv_cached_voice_requires_local_binding');
        foreach (['API_KEY','endpoint','url','URL','lorkhan_model','lorkhan_timeout_ms','language','base_lang',$modelField,$voiceField,'voicelogic','cached_voice_path'] as $key) unset($metadata[$key]);
        $aliases=array_flip(self::OPTION_NAMES);$options=[];
        foreach ($metadata as $key=>$value) $options[$aliases[$key]??$key]=$value;
        foreach (ConnectorCatalog::optionFields('tts_provider',$driver) as $field) {
            $key=$field['name'];$value=$options[$key]??null;
            if (!is_string($value)) continue;
            if ($field['type']==='integer' && preg_match('/^-?\d+$/D',$value)) $options[$key]=(int)$value;
            elseif ($field['type']==='number' && is_numeric($value)) $options[$key]=(float)$value;
            elseif ($field['type']==='boolean' && in_array($value,['true','false'],true)) $options[$key]=$value==='true';
        }
        $content['options']=$options;
        return ['schema'=>'lorkhan.connector-export.v1','exported_at'=>gmdate('c'),'kind'=>'tts_provider',
            'name'=>trim($row['label']) ?: 'Imported TTS Connector','content'=>ConnectorCatalog::validate('tts_provider',$content)];
    }
}
