<?php
declare(strict_types=1);

namespace ALMSIVIserver\Application;

use InvalidArgumentException;

/** Bounded score changes for one witnessed exchange; never accepts actor IDs from a model. */
final class RelationshipEvaluationPolicy
{
    public static function output(array $output):array
    {
        $keys=array_keys($output);sort($keys);
        if($keys!==['affinity_delta','disposition_delta','reason']
            &&$keys!==['affinity_delta','disposition_delta','reason','relationship_type'])
            throw new InvalidArgumentException('invalid_relationship_output');
        foreach(['affinity_delta','disposition_delta']as$field){
            if(!is_int($output[$field])||$output[$field]<-10||$output[$field]>10)
                throw new InvalidArgumentException('invalid_relationship_output');
        }
        if(!is_string($output['reason'])||trim($output['reason'])===''||strlen($output['reason'])>1024
            ||str_contains($output['reason'],"\0")||!mb_check_encoding($output['reason'],'UTF-8'))
            throw new InvalidArgumentException('invalid_relationship_output');
        if(array_key_exists('relationship_type',$output)
            &&(!is_string($output['relationship_type'])||strlen($output['relationship_type'])>50
                ||str_contains($output['relationship_type'],"\0")||!mb_check_encoding($output['relationship_type'],'UTF-8')))
            throw new InvalidArgumentException('invalid_relationship_output');
        $output['reason']=trim($output['reason']);return $output;
    }

    /** A stable per-response draw prevents retries or duplicate delivery from rerolling eligibility. */
    public static function eligible(int $chance,string $key):bool
    {
        if($chance<0||$chance>100)throw new InvalidArgumentException('invalid_relationship_chance');
        if($chance===0)return false;
        if($chance===100)return true;
        return (hexdec(substr(hash('sha256',$key),0,8))%100)<$chance;
    }
}
