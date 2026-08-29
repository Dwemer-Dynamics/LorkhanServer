<?php
declare(strict_types=1);

namespace LORKHANserver\Application;

use InvalidArgumentException;

/** Validate the explicit MiniMe endpoint used only for optional semantic memory recall. */
final class MemoryEmbeddingPolicy
{
    public const SCHEMA='lorkhan.memory-embedding-policy.v1';
    public const MODEL='sentence-transformers/all-MiniLM-L6-v2';

    public static function defaults():array
    {
        return ['schema'=>self::SCHEMA,'enabled'=>false,'endpoint'=>'','timeout_ms'=>1500];
    }

    public static function validate(mixed $value):array
    {
        if(!is_array($value)||array_is_list($value)){
            throw new InvalidArgumentException('invalid_memory_embedding_policy');
        }
        $keys=array_keys($value);sort($keys);
        if($keys!==['enabled','endpoint','schema','timeout_ms']||($value['schema']??null)!==self::SCHEMA
            ||!is_bool($value['enabled'])||!is_string($value['endpoint'])
            ||!is_int($value['timeout_ms'])||$value['timeout_ms']<250||$value['timeout_ms']>5000){
            throw new InvalidArgumentException('invalid_memory_embedding_policy');
        }
        $endpoint=rtrim(trim($value['endpoint']),'/');
        if(strlen($endpoint)>2048||preg_match('/[\x00-\x20\x7f]/',$endpoint)){
            throw new InvalidArgumentException('invalid_memory_embedding_endpoint');
        }
        if($endpoint===''){
            if($value['enabled'])throw new InvalidArgumentException('memory_embedding_endpoint_required');
            return ['schema'=>self::SCHEMA,'enabled'=>false,'endpoint'=>'','timeout_ms'=>$value['timeout_ms']];
        }
        $parts=parse_url($endpoint);
        if(!is_array($parts)||!in_array($parts['scheme']??null,['https','http'],true)||!isset($parts['host'])
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])){
            throw new InvalidArgumentException('invalid_memory_embedding_endpoint');
        }
        $host=strtolower(rtrim((string)$parts['host'],'.'));
        $loopback=$host==='localhost'||$host==='::1'
            ||(filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false&&str_starts_with($host,'127.'));
        if(($parts['scheme']??null)==='http'&&!$loopback){
            throw new InvalidArgumentException('invalid_memory_embedding_endpoint');
        }
        return ['schema'=>self::SCHEMA,'enabled'=>$value['enabled'],'endpoint'=>$endpoint,
            'timeout_ms'=>$value['timeout_ms']];
    }

    public static function queryText(array $turn):string
    {
        $query=trim((string)($turn['payload']['input']['text']??''));
        if($query===''){
            $target=$turn['payload']['target']??[];
            $name=is_array($target)?trim((string)($target['display_name']??$target['record_id']??'')):'';
            $query='Continue the current conversation'.($name===''?'':' with '.$name);
        }
        return mb_strcut($query,0,4096,'UTF-8');
    }
}
