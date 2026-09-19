<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Domain\ProfileId;
use InvalidArgumentException;
use PDO;

/** User-defined exceptions to placed-reference identity, by reference or an explicit exact name. */
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
        $groups=$this->list($installation);
        // Explicit references take precedence over optional name rules.
        usort($groups,static fn(array $a,array $b):int =>
            (int)in_array($reference,[$b['canonical_ref'],...$b['aliases']],true)
            <=>(int)in_array($reference,[$a['canonical_ref'],...$a['aliases']],true));
        $name=mb_strtolower(trim((string)($identity['display_name']??'')),'UTF-8');
        foreach($groups as $group){
            if(!$group['enabled']||(!in_array($reference,[$group['canonical_ref'],...$group['aliases']],true)
                &&($name===''||$name!==mb_strtolower(trim((string)($group['match_name']??'')),'UTF-8'))))continue;
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
        if($key==='')$key='group-'.bin2hex(random_bytes(12));
        if(!Uuid::isValid($installation)||preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D',$key)!==1
            ||$name===''||strlen($name)>160||preg_match('/[\x00-\x1f]/',$name))throw new InvalidArgumentException('invalid_reference_group');
        $matchName=trim((string)($input['match_name']??''));
        if(strlen($matchName)>160||preg_match('/[\x00-\x1f]/',$matchName))throw new InvalidArgumentException('invalid_reference_group_name');
        $canonicalInput=trim((string)($input['canonical_ref']??''));
        if($canonicalInput===''&&$matchName!==''){
            $query=$this->db->prepare("SELECT actor_identity FROM profiles p WHERE installation_id=:installation AND deleted_at IS NULL AND ".ProfileScopeSql::current('p')." AND actor_identity->>'kind' IN ('actor','npc','creature') AND lower(btrim(actor_identity->>'display_name'))=lower(:name) ORDER BY created_at,profile_id LIMIT 1");
            $query->execute(['installation'=>$installation,'name'=>$matchName]);
            $identity=$query->fetchColumn();
            if($identity===false)throw new InvalidArgumentException('reference_group_name_not_observed');
            $canonicalInput=ProfileId::reference(json_decode($identity,true,32,JSON_THROW_ON_ERROR));
        }
        $canonical=$this->normalizeReference($canonicalInput);
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
            $affectedNames=[$matchName];
            foreach($this->list($installation)as$group){
                if($group['group_key']===$key){$affected=[...$affected,$group['canonical_ref'],...$group['aliases']];$affectedNames[]=$group['match_name']??'';continue;}
                if($matchName!==''&&mb_strtolower($matchName,'UTF-8')===mb_strtolower((string)($group['match_name']??''),'UTF-8'))
                    throw new InvalidArgumentException('name_already_in_group');
                if(array_intersect([$canonical,...$aliases],[$group['canonical_ref'],...$group['aliases']]))
                    throw new InvalidArgumentException('reference_already_in_group');
            }
            $this->db->prepare('INSERT INTO npc_reference_groups(installation_id,group_key,name,enabled,canonical_ref,aliases,match_name) VALUES(:installation,:key,:name,:enabled,:canonical,CAST(:aliases AS jsonb),:match_name) ON CONFLICT(installation_id,group_key) DO UPDATE SET name=EXCLUDED.name,enabled=EXCLUDED.enabled,canonical_ref=EXCLUDED.canonical_ref,aliases=EXCLUDED.aliases,match_name=EXCLUDED.match_name')
                ->execute(['installation'=>$installation,'key'=>$key,'name'=>$name,'enabled'=>$enabled?'true':'false','canonical'=>$canonical,'aliases'=>json_encode($aliases,JSON_THROW_ON_ERROR),'match_name'=>$matchName]);
            // Cached physical bindings are rebuilt from the current explicit mapping on the next observation.
            $this->invalidateBindings($installation,$affected,$affectedNames);
            if($owns)$this->db->commit();
            return ['group_key'=>$key,'name'=>$name,'enabled'=>$enabled,'canonical_ref'=>$canonical,'aliases'=>$aliases,'match_name'=>$matchName];
        }catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    public function delete(string $installation,string $group):void
    {
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            $this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:id FOR UPDATE')->execute(['id'=>$installation]);
            foreach($this->list($installation) as $row)if($row['group_key']===$group)$this->invalidateBindings($installation,[$row['canonical_ref'],...$row['aliases']],[$row['match_name']??'']);
            $this->db->prepare('DELETE FROM npc_reference_groups WHERE installation_id=:installation AND group_key=:key')->execute(['installation'=>$installation,'key'=>$group]);
            if($owns)$this->db->commit();
        }catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    /** Drop only bindings whose explicit alias mapping has changed. */
    private function invalidateBindings(string $installation,array $references,array $names=[]):void
    {
        $names=array_values(array_filter(array_map(static fn(string $name):string=>mb_strtolower(trim($name),'UTF-8'),$names)));
        $this->db->prepare("DELETE FROM actor_profile_bindings WHERE installation_id=:installation AND (lower(actor_identity->>'content_file')||'|'||(actor_identity->'refnum'->>'index') IN (SELECT jsonb_array_elements_text(CAST(:refs AS jsonb))) OR lower(btrim(actor_identity->>'display_name')) IN (SELECT jsonb_array_elements_text(CAST(:names AS jsonb))))")
            ->execute(['installation'=>$installation,'refs'=>json_encode(array_values(array_unique($references)),JSON_THROW_ON_ERROR),'names'=>json_encode($names,JSON_THROW_ON_ERROR)]);
    }

    private function normalizeReference(string $reference):string
    {
        $parts=explode('|',strtolower(trim($reference)));
        if(count($parts)!==2||preg_match('/^(0|[1-9][0-9]{0,9})$/D',$parts[1])!==1||(int)$parts[1]>4294967295)
            throw new InvalidArgumentException('invalid_actor_reference');
        return ProfileId::reference(['kind'=>'npc','content_file'=>$parts[0],'refnum'=>['index'=>(int)$parts[1]]]);
    }
}
