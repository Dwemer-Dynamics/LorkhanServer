<?php
declare(strict_types=1);
namespace LorkhanServer\Domain;

use InvalidArgumentException;
use LorkhanServer\Infrastructure\Uuid;

/**
 * NPC profile keys identify a placed reference, or a runtime-generated actor's saved UUID (actor.identity.dynamic.v1),
 * within its installation and playthrough.
 */
final class ProfileId
{
    public const DYNAMIC_CONTENT_FILE='lorkhan:dynamic';
    private const NIL_UUID='00000000-0000-0000-0000-000000000000';

    public static function isValid(mixed $value):bool
    {
        if(!is_string($value)||strlen($value)>300)return false;
        if(Uuid::isValid($value))return true;
        $parts=explode(':',$value,4);
        if(count($parts)===4&&$parts[0]==='dyn')return Uuid::isValid($parts[1])&&Uuid::isValid($parts[2])&&self::dynamicUuid($parts[3]);
        if(count($parts)!==4||$parts[0]!=='ref'||!Uuid::isValid($parts[1])||!Uuid::isValid($parts[2]))return false;
        $reference=explode('|',$parts[3]);
        if(count($reference)!==2||!self::validFile($reference[0])||preg_match('/^(0|[1-9][0-9]{0,9})$/D',$reference[1])!==1)return false;
        return (int)$reference[1]<=4294967295;
    }

    public static function isDynamic(mixed $identity):bool
    {
        return is_array($identity)&&array_key_exists('dynamic',$identity);
    }

    /** True for a structurally exact dynamic identity: npc/creature, zero RefNum and dynamic sentinel, non-nil UUID and runtime_ref. */
    public static function validDynamic(mixed $identity):bool
    {
        if(!self::isDynamic($identity))return false;
        $dynamic=$identity['dynamic'];$refnum=$identity['refnum']??null;
        return is_array($dynamic)&&!array_is_list($dynamic)&&count($dynamic)===2&&self::dynamicUuid($dynamic['uuid']??null)
            &&is_string($dynamic['runtime_ref']??null)&&preg_match('/^@0x[1-9a-f][0-9a-f]{0,7}$/D',$dynamic['runtime_ref'])===1
            &&in_array($identity['kind']??null,['npc','creature'],true)&&($identity['content_file']??null)===self::DYNAMIC_CONTENT_FILE
            &&is_array($refnum)&&count($refnum)===2&&($refnum['index']??null)===0&&($refnum['content_file']??null)===0;
    }

    public static function dynamicUuid(mixed $value):bool
    {
        return is_string($value)&&preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$value)===1&&$value!==self::NIL_UUID;
    }

    /** Placed reference only; a dynamic identity has no placed reference and never aliases one. */
    public static function reference(array $identity):string
    {
        if(self::isDynamic($identity)||($identity['content_file']??null)===self::DYNAMIC_CONTENT_FILE)throw new InvalidArgumentException('invalid_actor_reference');
        $file=$identity['content_file']??null;$index=$identity['refnum']['index']??null;
        if(!is_string($file)||!is_int($index)||$index<0||$index>4294967295)throw new InvalidArgumentException('invalid_actor_reference');
        $file=mb_strtolower($file,'UTF-8');
        if(!self::validFile($file))throw new InvalidArgumentException('invalid_actor_reference');
        return $file.'|'.$index;
    }

    /**
     * Durable history key for JSON containment: kind, base record, content file and local RefNum index.
     * The RefNum load-order slot, cell and display name are runtime snapshots. Untyped, fractional, negative or
     * out-of-range legacy references stay exact, as in SQL durable_actor_identity_key (015; durable_actor_identity_key_v2 from 017 adds dynamic.uuid).
     */
    public static function durableIdentity(array $identity):array
    {
        $durable=array_intersect_key($identity,array_flip(['kind','record_id','content_file','refnum']));
        $index=is_array($durable['refnum']??null)?($durable['refnum']['index']??null):null;
        if(is_int($index)&&$index>=0&&$index<=4294967295)$durable['refnum']=['index'=>$index];
        // Dynamic actors are durable by saved UUID; only the runtime_ref snapshot is dropped, as in durable_actor_identity_key_v2.
        // Like SQL, only a JSON object is reduced; malformed legacy lists and scalars stay exact. JSON {} and [] both decode
        // to PHP [] and are kept as [], so only a stored empty-object dynamic member ({"uuid":null} in SQL) cannot match.
        if(array_key_exists('dynamic',$identity)){
            $dynamic=$identity['dynamic'];
            $durable['dynamic']=is_array($dynamic)&&$dynamic!==[]&&!array_is_list($dynamic)?['uuid'=>$dynamic['uuid']??null]:$dynamic;
        }
        return $durable;
    }

    /** Add only the durable dynamic UUID of $identity to a placed-field selector; runtime_ref stays out of history keys. */
    public static function withDynamicUuid(array $selector,array $identity):array
    {
        if(is_array($identity['dynamic']??null)&&array_key_exists('uuid',$identity['dynamic']))$selector['dynamic']=['uuid'=>$identity['dynamic']['uuid']];
        return $selector;
    }

    /** Durable actor key within one installation/playthrough: dynamic UUID or placed reference, never a runtime slot. */
    public static function actorReference(array $identity):string
    {
        if(!self::isDynamic($identity))return self::reference($identity);
        if(!self::validDynamic($identity))throw new InvalidArgumentException('invalid_actor_reference');
        return 'dyn|'.$identity['dynamic']['uuid'];
    }

    public static function forActor(string $installation,string $playthrough,array $identity):string
    {
        if(self::isDynamic($identity)){
            $id='dyn:'.$installation.':'.$playthrough.':'.(self::validDynamic($identity)?$identity['dynamic']['uuid']:'');
            if(!self::isValid($id))throw new InvalidArgumentException('invalid_actor_reference');
            return $id;
        }
        $id='ref:'.$installation.':'.$playthrough.':'.self::reference($identity);
        if(!self::isValid($id))throw new InvalidArgumentException('invalid_profile_id');
        return $id;
    }

    private static function validFile(string $file):bool
    {
        return $file!==''&&strlen($file)<=200&&mb_check_encoding($file,'UTF-8')&&trim($file)===$file
            &&$file===mb_strtolower($file,'UTF-8')&&preg_match('/[\x00-\x1f\x7f\\\\\/|:]/',$file)!==1
            &&!in_array($file,['.','..'],true);
    }
}
