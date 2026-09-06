<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

/** Shared validation for editable AI-visible relationship details and backup restores. */
final class RelationshipDetails
{
    public const FIELDS = ['relation','note','best','worst'];

    public static function validate(mixed $value):array
    {
        if(!is_array($value)||array_diff(array_keys($value),self::FIELDS)!==[])
            throw new \InvalidArgumentException('invalid_relationship_details');
        $result=[];
        foreach(self::FIELDS as$key){
            $text=array_key_exists($key,$value)?$value[$key]:'';
            if(!is_string($text)||!mb_check_encoding($text,'UTF-8')
                ||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$text)===1||mb_strlen($text,'UTF-8')>1024)
                throw new \InvalidArgumentException('invalid_relationship_details');
            $result[$key]=str_replace("\r\n","\n",$text);
        }
        return $result;
    }
}
