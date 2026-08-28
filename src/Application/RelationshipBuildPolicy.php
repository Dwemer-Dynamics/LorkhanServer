<?php
declare(strict_types=1);
namespace ALMSIVIserver\Application;

/** Manual history analysis accepts scores only for server-selected interlocutors. */
final class RelationshipBuildPolicy
{
    public static function output(array $output):array
    {
        if(array_keys($output)!==['relationships']||!is_array($output['relationships'])
            ||!array_is_list($output['relationships'])||count($output['relationships'])>20)
            throw new \InvalidArgumentException('invalid_relationship_build_output');
        $seen=[];
        foreach($output['relationships'] as $row){
            if(!is_array($row))throw new \InvalidArgumentException('invalid_relationship_build_output');
            $keys=array_keys($row);sort($keys);
            if($keys!==['affinity','disposition','reason','target_key']||!is_string($row['target_key'])
                ||preg_match('/^[0-9a-f]{64}$/D',$row['target_key'])!==1||isset($seen[$row['target_key']]))
                throw new \InvalidArgumentException('invalid_relationship_build_output');
            foreach(['disposition','affinity'] as $field)
                if(!is_int($row[$field])||$row[$field]<-100||$row[$field]>100)
                    throw new \InvalidArgumentException('invalid_relationship_build_output');
            if(!is_string($row['reason'])||trim($row['reason'])===''||strlen($row['reason'])>512
                ||str_contains($row['reason'],"\0")||!mb_check_encoding($row['reason'],'UTF-8'))
                throw new \InvalidArgumentException('invalid_relationship_build_output');
            $seen[$row['target_key']]=true;
        }
        return $output;
    }
}
