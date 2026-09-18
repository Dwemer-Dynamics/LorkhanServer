<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Domain\ProfileId;
use InvalidArgumentException;
use PDO;

/** Explicit exceptions to placed-reference identity; never matches NPC names or base records. */
final class ReferenceGroupRepository
{
    public function __construct(private readonly PDO $db) {}

    public function list(string $installation):array
    {
        $query=$this->db->prepare('SELECT * FROM npc_reference_groups WHERE installation_id=:installation ORDER BY name,group_key');
        $query->execute(['installation'=>$installation]);
        return array_map(static function(array $row):array {
            $row['aliases']=json_decode($row['aliases'],true,32,JSON_THROW_ON_ERROR);
            $row['enabled']=filter_var($row['enabled'],FILTER_VALIDATE_BOOL);
            return $row;
        },$query->fetchAll());
    }

    /** Return only the profile's canonical reference; callers retain the physical actor for commands. */
    public function resolve(string $installation,array $identity):array
    {
        $reference=ProfileId::reference($identity);
        foreach($this->list($installation) as $group){
            if(!$group['enabled']||!in_array($reference,[$group['canonical_ref'],...$group['aliases']],true))continue;
            [$file,$index]=explode('|',$group['canonical_ref'],2);
            $identity['content_file']=$file;
            $identity['refnum']['index']=(int)$index;
            return $identity;
        }
        return $identity;
    }

    public function save(string $installation,array $input):array
    {
        $key=trim((string)($input['group_key']??''));
        $name=trim((string)($input['name']??''));
        if(!Uuid::isValid($installation)||preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D',$key)!==1
            ||$name===''||strlen($name)>160||preg_match('/[\x00-\x1f]/',$name))throw new InvalidArgumentException('invalid_reference_group');
        $canonical=$this->normalizeReference((string)($input['canonical_ref']??''));
        $aliases=$input['aliases']??[];
        if(is_string($aliases))$aliases=preg_split('/\R/',$aliases,-1,PREG_SPLIT_NO_EMPTY);
        if(!is_array($aliases)||count($aliases)>64)throw new InvalidArgumentException('invalid_reference_group_aliases');
        $aliases=array_values(array_unique(array_map(fn($ref)=>$this->normalizeReference((string)$ref),$aliases)));
        $aliases=array_values(array_diff($aliases,[$canonical]));
        $enabled=filter_var($input['enabled']??false,FILTER_VALIDATE_BOOL);
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            $this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:id FOR UPDATE')->execute(['id'=>$installation]);
            $affected=[$canonical,...$aliases];
            foreach($this->list($installation)as$group){
                if($group['group_key']===$key){$affected=[...$affected,$group['canonical_ref'],...$group['aliases']];continue;}
                if(array_intersect([$canonical,...$aliases],[$group['canonical_ref'],...$group['aliases']]))
                    throw new InvalidArgumentException('reference_already_in_group');
            }
            $this->db->prepare('INSERT INTO npc_reference_groups(installation_id,group_key,name,enabled,canonical_ref,aliases) VALUES(:installation,:key,:name,:enabled,:canonical,CAST(:aliases AS jsonb)) ON CONFLICT(installation_id,group_key) DO UPDATE SET name=EXCLUDED.name,enabled=EXCLUDED.enabled,canonical_ref=EXCLUDED.canonical_ref,aliases=EXCLUDED.aliases')
                ->execute(['installation'=>$installation,'key'=>$key,'name'=>$name,'enabled'=>$enabled?'true':'false','canonical'=>$canonical,'aliases'=>json_encode($aliases,JSON_THROW_ON_ERROR)]);
            // Cached physical bindings are rebuilt from the current explicit mapping on the next observation.
            $this->invalidateBindings($installation,$affected);
            if($owns)$this->db->commit();
            return ['group_key'=>$key,'name'=>$name,'enabled'=>$enabled,'canonical_ref'=>$canonical,'aliases'=>$aliases];
        }catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    public function delete(string $installation,string $group):void
    {
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            $this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:id FOR UPDATE')->execute(['id'=>$installation]);
            foreach($this->list($installation) as $row)if($row['group_key']===$group)$this->invalidateBindings($installation,[$row['canonical_ref'],...$row['aliases']]);
            $this->db->prepare('DELETE FROM npc_reference_groups WHERE installation_id=:installation AND group_key=:key')->execute(['installation'=>$installation,'key'=>$group]);
            if($owns)$this->db->commit();
        }catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    /** Drop only bindings whose explicit alias mapping has changed. */
    private function invalidateBindings(string $installation,array $references):void
    {
        $this->db->prepare("DELETE FROM actor_profile_bindings WHERE installation_id=:installation AND lower(actor_identity->>'content_file')||'|'||(actor_identity->'refnum'->>'index') IN (SELECT jsonb_array_elements_text(CAST(:refs AS jsonb)))")
            ->execute(['installation'=>$installation,'refs'=>json_encode(array_values(array_unique($references)),JSON_THROW_ON_ERROR)]);
    }

    private function normalizeReference(string $reference):string
    {
        $parts=explode('|',strtolower(trim($reference)));
        if(count($parts)!==2||preg_match('/^(0|[1-9][0-9]{0,9})$/D',$parts[1])!==1||(int)$parts[1]>4294967295)
            throw new InvalidArgumentException('invalid_actor_reference');
        return ProfileId::reference(['kind'=>'npc','content_file'=>$parts[0],'refnum'=>['index'=>(int)$parts[1]]]);
    }
}
