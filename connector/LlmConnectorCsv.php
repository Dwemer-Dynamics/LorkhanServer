<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Herika's LLM CSV columns with native transport metadata and no portable credential ownership. */
final class LlmConnectorCsv
{
    public const COLUMNS = ['id','label','service','url','model','provider','driver','api_badge_id','max_tokens','temperature','presence_penalty','frequency_penalty','repetition_penalty','top_p','top_k','min_p','top_a','enforce_json','json_schema','prefill_json','reasoning_model','metadata'];
    private const DRIVERS = ['openrouter'=>'openrouterjson','openai'=>'openaijson','google'=>'google_openaijson','groq'=>'groqjson','nanogpt'=>'openrouterjson','player2'=>'player2json','custom'=>'openaijson','local'=>'openaijson'];

    public static function encode(string $name,array $content):string
    {
        $content=LlmConnector::validate($content);$row=array_fill_keys(self::COLUMNS,'');$options=$content['options']??[];
        $row['label']=$name;$row['service']=$content['service']??'';$row['url']=$content['endpoint']??'';$row['model']=$content['model'];
        $row['driver']=self::DRIVERS[$content['service']??'custom'];
        $metadata=['lorkhan_driver'=>$content['driver']];
        foreach(['timeout_ms','mock_prefix'] as $key)if(array_key_exists($key,$content))$metadata['lorkhan_'.$key]=$content[$key];
        if($content['driver']==='openai-compatible'&&!isset($content['service']))$metadata['lorkhan_service_unspecified']=true;
        foreach($options as $key=>$value){
            $column=$key==='json_mode'?'enforce_json':$key;
            if(in_array($column,self::COLUMNS,true))$row[$column]=is_bool($value)?($value?'true':'false'):$value;
            elseif($key==='provider_order')$row['provider']=implode(',',$value);
            elseif($key==='stream')$metadata['disable_streaming']=!$value;
            elseif($key==='extra_parameters_yaml'){
                $metadata['extra_parameters']=LlmBodyParameters::parse($value);
                $metadata['lorkhan_extra_parameters_yaml']=$value;
            }else $metadata[$key]=$value;
        }
        $row['metadata']=json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stream=fopen('php://temp','w+b');
        try{fputcsv($stream,self::COLUMNS,',','"','\\');fputcsv($stream,array_values($row),',','"','\\');rewind($stream);return (string)stream_get_contents($stream);}
        finally{fclose($stream);}
    }

    public static function decode(string $csv):array
    {
        if(strlen($csv)>1_048_576||!mb_check_encoding($csv,'UTF-8')||str_contains($csv,"\0"))throw new InvalidArgumentException('invalid_llm_csv');
        $stream=fopen('php://temp','w+b');$rows=[];
        try{
            fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($stream);
            while(($row=fgetcsv($stream,1_048_577,',','"','\\'))!==false){
                if(!array_filter($row,static fn($value):bool=>trim((string)$value)!==''))continue;
                $rows[]=$row;if(count($rows)>2)throw new InvalidArgumentException('llm_csv_one_connector_per_file');
            }
        }finally{fclose($stream);}
        if(count($rows)!==2||count($rows[0])!==count(self::COLUMNS)||count($rows[1])!==count(self::COLUMNS))throw new InvalidArgumentException('invalid_llm_csv');
        $columns=array_map(static fn($key):string=>strtolower(trim((string)$key)),$rows[0]);
        if(count(array_unique($columns))!==count(self::COLUMNS)||array_diff(self::COLUMNS,$columns))throw new InvalidArgumentException('invalid_llm_csv_columns');
        $row=array_combine($columns,$rows[1]);
        try{$metadata=json_decode(trim($row['metadata'])?:'{}',true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new InvalidArgumentException('invalid_llm_csv_metadata');}
        if(!is_array($metadata)||($metadata!==[]&&array_is_list($metadata)))throw new InvalidArgumentException('invalid_llm_csv_metadata');
        $native=isset($metadata['lorkhan_driver']);$driver=$metadata['lorkhan_driver']??'openai-compatible';$service=strtolower(trim($row['service']));
        if($service===''){
            $source=strtolower($row['driver'].' '.$row['url']);$service='custom';
            foreach(['openai'=>'openai','google'=>'google','groq'=>'groq','nano-gpt'=>'nanogpt','player2'=>'player2','openrouter'=>'openrouter'] as $needle=>$candidate)
                if(str_contains($source,$needle)){$service=$candidate;break;}
        }
        $content=['driver'=>$driver,'model'=>trim($row['model'])];$options=[];
        foreach(self::COLUMNS as $column){
            $key=$column==='enforce_json'?'json_mode':$column;$rule=LlmConnector::OPTION_RULES[$key]??null;$value=trim($row[$column]);
            if(!$rule||$value==='')continue;
            if($rule['type']==='boolean'){
                $bool=filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
                if($bool===null)throw new InvalidArgumentException('invalid_llm_csv_boolean');$options[$key]=$bool;
            }elseif($rule['type']==='integer'){
                if(!preg_match('/^-?\d+$/D',$value))throw new InvalidArgumentException('invalid_llm_csv_number');$options[$key]=(int)$value;
            }else{if(!is_numeric($value))throw new InvalidArgumentException('invalid_llm_csv_number');$options[$key]=(float)$value;}
        }
        if(!$native){$options+=['temperature'=>1,'max_tokens'=>500];}
        if(trim($row['provider'])!=='')$options['provider_order']=array_map('trim',explode(',',$row['provider']));
        if(array_key_exists('disable_streaming',$metadata)){
            if(!is_bool($metadata['disable_streaming']))throw new InvalidArgumentException('invalid_llm_csv_boolean');
            $options['stream']=!$metadata['disable_streaming'];unset($metadata['disable_streaming']);
        }
        if(isset($metadata['extra_parameters'])){
            if(!is_array($metadata['extra_parameters']))throw new InvalidArgumentException('invalid_llm_csv_metadata');
            $options['extra_parameters_yaml']=$metadata['lorkhan_extra_parameters_yaml']??json_encode((object)$metadata['extra_parameters'],JSON_THROW_ON_ERROR);
            if(!$native&&!array_key_exists('extra_parameters_enabled',$metadata))$options['extra_parameters_enabled']=true;
            unset($metadata['extra_parameters'],$metadata['lorkhan_extra_parameters_yaml']);
        }
        foreach(['timeout_ms','mock_prefix'] as $key){if(array_key_exists('lorkhan_'.$key,$metadata))$content[$key]=$metadata['lorkhan_'.$key];unset($metadata['lorkhan_'.$key]);}
        $unspecified=($metadata['lorkhan_service_unspecified']??false)===true;
        // This reference-only switch has no request-side consumer; credentials are never imported.
        unset($metadata['lorkhan_driver'],$metadata['lorkhan_service_unspecified'],$metadata['remove_action_prompt'],$metadata['API_KEY']);
        $options=array_replace($options,$metadata);
        if($driver!=='mock'){$content['credential']='none';if($options!==[])$content['options']=$options;}
        elseif($options!==[])throw new InvalidArgumentException('invalid_llm_csv_mock_options');
        if($driver==='openai-compatible'){$content['endpoint']=trim($row['url']);if(!$unspecified)$content['service']=$service;}
        return ['schema'=>'lorkhan.provider-export.v1','exported_at'=>gmdate('c'),'name'=>trim($row['label'])?:'Imported Connector','content'=>LlmConnector::validate($content)];
    }
}
