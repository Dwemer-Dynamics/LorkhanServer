<?php
declare(strict_types=1);
namespace ALMSIVIserver\Application;

/** Validate a bounded TES3 relationship identity, optionally retaining an incomplete legacy record. */
final class RelationshipIdentity
{
    public static function validate(mixed $value,bool $allowLegacy=false):array
    {
        if(!is_array($value)||array_is_list($value)
            ||array_diff(array_keys($value),['kind','record_id','content_file','display_name','refnum','cell'])!==[])
            throw new \InvalidArgumentException('invalid_actor_identity');
        if(!$allowLegacy&&!in_array($value['kind']??null,['npc','creature','player'],true))
            throw new \InvalidArgumentException('invalid_actor_identity');
        if(isset($value['kind'])&&!in_array($value['kind'],['npc','creature','player'],true))
            throw new \InvalidArgumentException('invalid_actor_identity');
        foreach(['record_id','content_file','display_name'] as $field)
            if((!$allowLegacy||$field==='record_id'||isset($value[$field]))
                &&(!is_string($value[$field]??null)||mb_strlen($value[$field],'UTF-8')<1||mb_strlen($value[$field],'UTF-8')>256))
                throw new \InvalidArgumentException('invalid_actor_identity');
        $refnum=$value['refnum']??null;
        if(!$allowLegacy||$refnum!==null){
            if(!is_array($refnum)||count($refnum)!==2||!is_int($refnum['index']??null)||!is_int($refnum['content_file']??null)
                ||$refnum['index']<0||$refnum['index']>4294967295||$refnum['content_file']<0||$refnum['content_file']>2147483647)
                throw new \InvalidArgumentException('invalid_actor_identity');
        }
        if(strlen(json_encode($value,JSON_THROW_ON_ERROR))>4096)throw new \InvalidArgumentException('invalid_actor_identity');
        return$value;
    }
}
