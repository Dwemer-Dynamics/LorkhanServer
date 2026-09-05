<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

/** The same bounded, verbatim player text is accepted by edits and explicit restores. */
final class RelationshipCustomInfo
{
    public static function validate(mixed $value):string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0")
            ||mb_strlen($value,'UTF-8')>2000)throw new \InvalidArgumentException('invalid_relationship_custom_info');
        return $value;
    }
}
