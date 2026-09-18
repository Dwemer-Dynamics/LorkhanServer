<?php
declare(strict_types=1);
namespace LorkhanServer\Domain;

use InvalidArgumentException;
use LorkhanServer\Infrastructure\Uuid;

/** NPC profile keys identify a placed reference within its installation and playthrough. */
final class ProfileId
{
    public static function isValid(mixed $value):bool
    {
        if(!is_string($value)||strlen($value)>300)return false;
        if(Uuid::isValid($value))return true;
        $parts=explode(':',$value,4);
        if(count($parts)!==4||$parts[0]!=='ref'||!Uuid::isValid($parts[1])||!Uuid::isValid($parts[2]))return false;
        $reference=explode('|',$parts[3]);
        if(count($reference)!==2||!self::validFile($reference[0])||preg_match('/^(0|[1-9][0-9]{0,9})$/D',$reference[1])!==1)return false;
        return (int)$reference[1]<=4294967295;
    }

    public static function reference(array $identity):string
    {
        $file=$identity['content_file']??null;$index=$identity['refnum']['index']??null;
        if(!is_string($file)||!is_int($index)||$index<0||$index>4294967295)throw new InvalidArgumentException('invalid_actor_reference');
        $file=mb_strtolower($file,'UTF-8');
        if(!self::validFile($file))throw new InvalidArgumentException('invalid_actor_reference');
        return $file.'|'.$index;
    }

    public static function forActor(string $installation,string $playthrough,array $identity):string
    {
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
