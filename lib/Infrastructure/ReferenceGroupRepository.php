<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Domain\ProfileId;
use InvalidArgumentException;
use PDO;

/**
 * User-defined linked characters: placed references that share one keeper profile by explicit reference or an exact
 * name rule. Grouping changes profile ownership only; events, commands and speech keep the physical actor.
 */
final class ReferenceGroupRepository
{
    /** Automatic name-rule members per group; explicit aliases have their own limit of 64. */
    private const NAME_MEMBER_LIMIT=64;

    public function __construct(private readonly PDO $db) {}

    public function list(string $installation):array
    {
        $query=$this->db->prepare("SELECT g.*,
            COALESCE((SELECT jsonb_agg(o.member_ref ORDER BY o.member_ref) FROM npc_reference_group_optouts o
                WHERE o.installation_id=g.installation_id AND o.group_key=g.group_key),'[]') AS unlinked,
            COALESCE((SELECT jsonb_agg(m.member_ref ORDER BY m.member_ref) FROM npc_reference_group_members m
                WHERE m.installation_id=g.installation_id AND m.group_key=g.group_key AND m.source='name_rule'),'[]') AS observed
            FROM npc_reference_groups g WHERE g.installation_id=:installation ORDER BY g.name,g.group_key");
        $query->execute(['installation'=>$installation]);
        return array_map(function(array $row):array {
            foreach(['aliases','unlinked','observed'] as $field)$row[$field]=$this->json($row[$field]);
            $row['enabled']=filter_var($row['enabled'],FILTER_VALIDATE_BOOL);
            $row['revision']=(int)$row['revision'];
            return $row;
        },$query->fetchAll());
    }

    /**
     * Return only the profile's canonical reference; callers retain the physical actor for commands and speech.
     * An in-game observation records membership. Other lookups apply explicit or already observed membership only,
     * so a name rule never captures dynamic, keyless or profile-only actors.
     * An observation holds the installation key-share lock until commit: group edits, unlinks, relinks, deletes and new
     * groups (installation FOR UPDATE) finish first and wait for the caller's profile and binding writes. Take it before
     * any session lock (Repository::createSession order) and before group, member or profile locks; a caller already
     * holding it re-requests it here at no cost. Do no provider I/O while it is held.
     */
    public function resolve(string $installation,array $identity,bool $observe=false):array
    {
        if($observe){
            if(!$this->db->inTransaction())throw new \LogicException('reference_group_observation_requires_transaction');
            $this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:id FOR KEY SHARE')->execute(['id'=>$installation]);
        }
        $group=$this->owner($installation,$identity,$observe);
        if($group===null)return $identity;
        [$file,$index]=explode('|',$group['canonical_ref'],2);
        $identity['content_file']=$file;
        $identity['refnum']['index']=(int)$index;
        return $identity;
    }

    private function owner(string $installation,array $identity,bool $observe):?array
    {
        $reference=ProfileId::reference($identity);
        if(in_array($identity['kind']??'npc',['player','narrator','template'],true))return null;
        $query=$this->db->prepare("SELECT g.group_key,g.canonical_ref,g.aliases,g.enabled,g.match_name,m.source,m.actor_identity AS member_identity,
                EXISTS(SELECT 1 FROM npc_reference_group_optouts o WHERE o.installation_id=g.installation_id
                    AND o.group_key=g.group_key AND o.member_ref=:optout) AS unlinked
            FROM npc_reference_groups g LEFT JOIN npc_reference_group_members m ON m.installation_id=g.installation_id
                AND m.group_key=g.group_key AND m.member_ref=:member
            WHERE g.installation_id=:installation AND (g.canonical_ref=:canonical OR jsonb_exists(g.aliases,:alias)
                OR m.member_ref IS NOT NULL OR g.match_name<>'') ORDER BY g.group_key");
        $query->execute(['installation'=>$installation,'optout'=>$reference,'member'=>$reference,'canonical'=>$reference,'alias'=>$reference]);
        $rows=$query->fetchAll();$durable=$this->memberIdentity($identity);
        // Explicit membership owns the reference, even while its group is disabled.
        foreach($rows as$row){
            $keeper=$row['canonical_ref']===$reference;
            if(!$keeper&&!in_array($reference,$this->json($row['aliases']),true))continue;
            if(!$this->bool($row['enabled'])||(!$keeper&&$this->bool($row['unlinked'])))return null;
            if($observe&&$durable!==null&&!$this->observe($installation,$row['group_key'],$reference,$keeper?'canonical':'alias',$durable,$row['source'],$row['member_identity']))return null;
            return $row;
        }
        $name=self::fold((string)($identity['display_name']??''));
        foreach($rows as$row){
            if($row['source']===null)continue;
            $known=$this->json($row['member_identity']);$rule=self::fold((string)$row['match_name']);
            $current=$row['source']==='name_rule'&&$rule!==''&&$durable!==null
                &&strtolower((string)($known['record_id']??''))===strtolower((string)$durable['record_id'])
                &&(!$observe||$name===$rule);
            if($current)return $this->bool($row['enabled'])&&!$this->bool($row['unlinked'])?$row:null;
            // A renamed actor or a changed base record at the same reference is no longer this rule's member.
            if($observe)$this->forget($installation,[$row['group_key']],[$reference]);
        }
        if(!$observe||$name===''||$durable===null)return null;
        foreach($rows as$row){
            if(self::fold((string)$row['match_name'])!==$name||!$this->bool($row['enabled'])||$this->bool($row['unlinked']))continue;
            $count=$this->db->prepare("SELECT count(*) FROM npc_reference_group_members WHERE installation_id=:installation AND group_key=:group AND source='name_rule'");
            $count->execute(['installation'=>$installation,'group'=>$row['group_key']]);
            if((int)$count->fetchColumn()>=self::NAME_MEMBER_LIMIT)return null;
            return $this->observe($installation,$row['group_key'],$reference,'name_rule',$durable,null,null,$name)?$row:null;
        }
        return null;
    }

    /** Record one observed member; a real change advances every affected group revision. */
    private function observe(string $installation,string $group,string $reference,string $source,array $identity,?string $knownSource,?string $knownIdentity,?string $name=null):bool
    {
        if($knownSource===$source&&$knownIdentity!==null&&$this->json($knownIdentity)==$identity)return true;
        // Exclusive against lockMembership(): a commit holding this reference's fence finishes first.
        $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')->execute(['key'=>self::memberLock($installation,$reference)]);
        $previous=$this->db->prepare('SELECT group_key FROM npc_reference_group_members WHERE installation_id=:installation AND member_ref=:ref');
        $previous->execute(['installation'=>$installation,'ref'=>$reference]);$groups=array_values(array_unique(array_filter([$group,$previous->fetchColumn()])));
        sort($groups);
        $lock=$this->db->prepare('SELECT group_key,enabled,match_name FROM npc_reference_groups WHERE installation_id=:installation '
            .'AND group_key IN (SELECT jsonb_array_elements_text(CAST(:groups AS jsonb))) ORDER BY group_key FOR UPDATE');
        $lock->execute(['installation'=>$installation,'groups'=>json_encode($groups,JSON_THROW_ON_ERROR)]);
        $target=null;foreach($lock->fetchAll()as$row)if($row['group_key']===$group)$target=$row;
        // Revalidate after the lock: a concurrent edit may have disabled the group or changed its rule.
        if($target===null||!$this->bool($target['enabled'])||($name!==null&&self::fold((string)$target['match_name'])!==$name))return false;
        $this->db->prepare('INSERT INTO npc_reference_group_members(installation_id,member_ref,group_key,source,actor_identity) '
            .'VALUES(:installation,:ref,:group,:source,CAST(:identity AS jsonb)) ON CONFLICT(installation_id,member_ref) DO UPDATE '
            .'SET group_key=EXCLUDED.group_key,source=EXCLUDED.source,actor_identity=EXCLUDED.actor_identity,observed_at=clock_timestamp()')
            ->execute(['installation'=>$installation,'ref'=>$reference,'group'=>$group,'source'=>$source,'identity'=>json_encode($identity,JSON_THROW_ON_ERROR)]);
        $this->bump($installation,$groups);
        return true;
    }

    /**
     * Physical history owners of one character: the profile's own placed reference plus, for an enabled group keeper,
     * each linked member observed in game or holding a dormant independent profile. Unlinked members are excluded.
     * Stored rows are read in place; nothing is rewritten or merged.
     * @return array{identities:list<array>,profile_ids:list<string>}
     */
    public function characterScope(string $profileId,array $identity):array
    {
        $identities=[ProfileId::durableIdentity($identity)];$profiles=[$profileId];
        $parts=explode(':',$profileId,4);
        if(count($parts)!==4||$parts[0]!=='ref')return ['identities'=>$identities,'profile_ids'=>$profiles];
        [,$installation,$playthrough,$reference]=$parts;
        $query=$this->db->prepare('SELECT g.group_key,g.aliases,
                COALESCE((SELECT jsonb_agg(o.member_ref) FROM npc_reference_group_optouts o WHERE o.installation_id=g.installation_id AND o.group_key=g.group_key),\'[]\') AS unlinked,
                COALESCE((SELECT jsonb_agg(jsonb_build_array(m.member_ref,m.actor_identity) ORDER BY m.member_ref) FROM npc_reference_group_members m
                    WHERE m.installation_id=g.installation_id AND m.group_key=g.group_key),\'[]\') AS members
            FROM npc_reference_groups g WHERE g.installation_id=:installation AND g.canonical_ref=:reference AND g.enabled');
        $query->execute(['installation'=>$installation,'reference'=>$reference]);$group=$query->fetch();
        if(!$group)return ['identities'=>$identities,'profile_ids'=>$profiles];
        $unlinked=array_flip($this->json($group['unlinked']));$references=[];
        foreach($this->json($group['aliases'])as$alias)if(!isset($unlinked[$alias]))$references[$alias]=true;
        foreach($this->json($group['members'])as[$member,$memberIdentity]){
            if(isset($unlinked[$member]))continue;
            $references[$member]=true;$identities[]=ProfileId::durableIdentity($memberIdentity);
        }
        unset($references[$reference]);
        $ids=array_map(static fn(string $member):string=>'ref:'.$installation.':'.$playthrough.':'.$member,array_keys($references));
        // The keeper's own stored identity joins too: callers may pass its sole bound alias, and the keeper may be unobserved since linking.
        $dormant=$this->db->prepare('SELECT profile_id,actor_identity FROM profiles WHERE installation_id=:installation AND deleted_at IS NULL '
            .'AND profile_id IN (SELECT jsonb_array_elements_text(CAST(:ids AS jsonb))) ORDER BY profile_id');
        $dormant->execute(['installation'=>$installation,'ids'=>json_encode([$profileId,...$ids],JSON_THROW_ON_ERROR)]);
        foreach($dormant->fetchAll()as$row){
            if($row['profile_id']!==$profileId)$profiles[]=(string)$row['profile_id'];
            $identities[]=ProfileId::durableIdentity($this->json($row['actor_identity']));
        }
        $unique=[];foreach($identities as$value)if($value!==[]){$sorted=$value;ksort($sorted);$unique[json_encode($sorted,JSON_THROW_ON_ERROR)]=$value;}
        return ['identities'=>array_values($unique),'profile_ids'=>$profiles];
    }

    /** SQL witness test against a bound JSON list of durable identities; aliases and parameter names are internal. */
    public static function witnessSql(string $alias,string $parameter):string
    {
        return "EXISTS(SELECT 1 FROM jsonb_array_elements(CAST(:$parameter AS jsonb)) owner_scope(identity) WHERE $alias.speaker @> owner_scope.identity "
            ."OR $alias.target @> owner_scope.identity OR $alias.audience @> jsonb_build_array(owner_scope.identity))";
    }

    /** Groups that hold a reference as keeper, alias or observed member; binds :installation, :canonical, :alias, :member. */
    private const MEMBERSHIP_SQL='SELECT g.group_key,g.revision,g.enabled FROM npc_reference_groups g WHERE g.installation_id=:installation '
        .'AND (g.canonical_ref=:canonical OR jsonb_exists(g.aliases,:alias) OR EXISTS(SELECT 1 FROM npc_reference_group_members m '
        .'WHERE m.installation_id=g.installation_id AND m.group_key=g.group_key AND m.member_ref=:member)) ORDER BY g.group_key';

    /**
     * Membership fence for queued work owned by a profile: every group that has the profile's reference as keeper, alias
     * or observed member, with its revision and state. Any membership edit, unlink, relink or observation changes it.
     */
    public function fence(string $profileId):string
    {
        $parts=explode(':',$profileId,4);$rows=[];
        if(count($parts)===4&&$parts[0]==='ref'){
            $query=$this->db->prepare(self::MEMBERSHIP_SQL);
            $query->execute(['installation'=>$parts[1],'canonical'=>$parts[3],'alias'=>$parts[3],'member'=>$parts[3]]);
            $rows=array_map(fn(array $row):array=>[$row['group_key'],(int)$row['revision'],$this->bool($row['enabled'])],$query->fetchAll());
        }
        return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
    }

    /** Fence of a profile outside every group. */
    public static function independentFence():string{return hash('sha256','[]');}

    /**
     * Whether queued work still sees the membership it froze. Work queued before fences existed carries none; it is
     * applied only while the profile belongs to no group, so a keeper, alias or member never uses a stale frozen context.
     */
    public function fenceHolds(array $payload,string $profileId):bool
    {
        return hash_equals(array_key_exists('membership_fence',$payload)?(string)$payload['membership_fence']:self::independentFence(),
            $this->fence($profileId));
    }

    /**
     * Hold a profile's membership until commit, so a fence checked afterwards stays true for the final write. Observations
     * of its reference wait on the shared advisory lock and edits of its groups wait on the row locks; new groups are
     * serialized by the caller's installation lock. Take it after installation/session locks and before profile rows.
     */
    public function lockMembership(string $profileId):void
    {
        $parts=explode(':',$profileId,4);
        if(count($parts)!==4||$parts[0]!=='ref')return;
        if(!$this->db->inTransaction())throw new \LogicException('reference_group_lock_requires_transaction');
        $this->db->prepare('SELECT pg_advisory_xact_lock_shared(hashtextextended(:key,0))')->execute(['key'=>self::memberLock($parts[1],$parts[3])]);
        $this->db->prepare(self::MEMBERSHIP_SQL.' FOR SHARE OF g')
            ->execute(['installation'=>$parts[1],'canonical'=>$parts[3],'alias'=>$parts[3],'member'=>$parts[3]]);
    }

    private static function memberLock(string $installation,string $reference):string{return 'reference-group-member:'.$installation.':'.$reference;}

    public function save(string $installation,array $input):array
    {
        $key=trim((string)($input['group_key']??''));
        $name=trim((string)($input['name']??''));
        if($key==='')$key='group-'.bin2hex(random_bytes(12));
        if(!Uuid::isValid($installation)||preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D',$key)!==1
            ||$name===''||strlen($name)>160||preg_match('/[\x00-\x1f]/',$name))throw new InvalidArgumentException('invalid_reference_group');
        $matchName=trim((string)($input['match_name']??''));
        if(strlen($matchName)>160||preg_match('/[\x00-\x1f]/',$matchName))throw new InvalidArgumentException('invalid_reference_group_name');
        $expected=trim((string)($input['revision']??''));
        if($expected!==''&&preg_match('/^[1-9][0-9]{0,18}$/D',$expected)!==1)throw new InvalidArgumentException('invalid_reference_group');
        $aliases=$input['aliases']??[];
        if(is_string($aliases))$aliases=preg_split('/\R/',$aliases,-1,PREG_SPLIT_NO_EMPTY);
        if(!is_array($aliases)||count($aliases)>64)throw new InvalidArgumentException('invalid_reference_group_aliases');
        $aliases=array_values(array_unique(array_map(fn($ref)=>$this->normalizeReference((string)$ref),$aliases)));
        $enabled=filter_var($input['enabled']??false,FILTER_VALIDATE_BOOL);
        return $this->write($installation,function()use($installation,$key,$name,$matchName,$expected,$aliases,$enabled,$input):array{
            $groups=$this->list($installation);$previous=null;$others=[];
            foreach($groups as$group){if($group['group_key']===$key)$previous=$group;else $others[]=$group;}
            if($expected!==''&&(int)$expected!==($previous['revision']??0))throw new InvalidArgumentException('reference_group_revision_conflict');
            $canonicalInput=trim((string)($input['canonical_ref']??''));
            if($canonicalInput===''&&$matchName!=='')$canonicalInput=$this->observedReferenceByName($installation,$matchName,$others);
            $canonical=$this->normalizeReference($canonicalInput);
            $aliases=array_values(array_diff($aliases,[$canonical]));$explicit=[$canonical,...$aliases];
            foreach($others as$group){
                if($matchName!==''&&self::fold($matchName)===self::fold((string)$group['match_name']))
                    throw new InvalidArgumentException('name_already_in_group');
                if(array_intersect($explicit,[$group['canonical_ref'],...$group['aliases']]))
                    throw new InvalidArgumentException('reference_already_in_group');
            }
            $this->db->prepare("INSERT INTO npc_reference_groups(installation_id,group_key,name,enabled,canonical_ref,aliases,match_name) VALUES(:installation,:key,:name,:enabled,:canonical,CAST(:aliases AS jsonb),:match_name) ON CONFLICT(installation_id,group_key) DO UPDATE SET name=EXCLUDED.name,enabled=EXCLUDED.enabled,canonical_ref=EXCLUDED.canonical_ref,aliases=EXCLUDED.aliases,match_name=EXCLUDED.match_name,revision=nextval('lorkhan_internal.npc_reference_group_revision_seq')")
                ->execute(['installation'=>$installation,'key'=>$key,'name'=>$name,'enabled'=>$enabled?'true':'false','canonical'=>$canonical,'aliases'=>json_encode($aliases,JSON_THROW_ON_ERROR),'match_name'=>$matchName]);
            $oldExplicit=$previous===null?[]:[$previous['canonical_ref'],...$previous['aliases']];
            $affected=[...array_diff($oldExplicit,$explicit),...array_diff($explicit,$oldExplicit)];
            $ruleChanged=$previous===null||self::fold((string)$previous['match_name'])!==self::fold($matchName)||$previous['canonical_ref']!==$canonical;
            $toggled=$previous!==null&&$previous['enabled']!==$enabled;
            if($toggled)$affected=[...$affected,...$explicit,...$oldExplicit,...$previous['observed']];
            // Exact groups take precedence: another group's observed name-rule member moves on its next observation.
            $moved=$this->db->prepare('DELETE FROM npc_reference_group_members WHERE installation_id=:installation AND group_key<>:key '
                .'AND member_ref IN (SELECT jsonb_array_elements_text(CAST(:refs AS jsonb))) RETURNING group_key,member_ref');
            $moved->execute(['installation'=>$installation,'key'=>$key,'refs'=>json_encode($explicit,JSON_THROW_ON_ERROR)]);
            $movedRows=$moved->fetchAll();
            $this->bump($installation,array_values(array_unique(array_column($movedRows,'group_key'))));
            $affected=[...$affected,...array_column($movedRows,'member_ref')];
            // Removed explicit references and members of a replaced name rule are stale; unrelated manual state stays.
            $stale=$this->db->prepare("DELETE FROM npc_reference_group_members WHERE installation_id=:installation AND group_key=:key "
                ."AND ((source<>'name_rule' AND member_ref NOT IN (SELECT jsonb_array_elements_text(CAST(:refs AS jsonb)))) OR (source='name_rule' AND CAST(:rule_changed AS boolean))) RETURNING member_ref");
            $stale->execute(['installation'=>$installation,'key'=>$key,'refs'=>json_encode($explicit,JSON_THROW_ON_ERROR),'rule_changed'=>$ruleChanged?'true':'false']);
            $affected=[...$affected,...$stale->fetchAll(PDO::FETCH_COLUMN)];
            if($matchName!==''&&($ruleChanged||$toggled))$affected=[...$affected,...$this->boundReferencesByName($installation,$matchName,$others)];
            // Cached physical bindings are rebuilt from the current mapping on the next observation.
            $this->invalidateBindings($installation,$affected);
            return ['group_key'=>$key,'name'=>$name,'enabled'=>$enabled,'canonical_ref'=>$canonical,'aliases'=>$aliases,'match_name'=>$matchName];
        });
    }

    public function delete(string $installation,string $group):void
    {
        $this->write($installation,function()use($installation,$group):void{
            foreach($this->list($installation) as $row)if($row['group_key']===$group){
                $members=$this->db->prepare('SELECT member_ref FROM npc_reference_group_members WHERE installation_id=:installation AND group_key=:key');
                $members->execute(['installation'=>$installation,'key'=>$group]);
                $this->invalidateBindings($installation,[$row['canonical_ref'],...$row['aliases'],...$members->fetchAll(PDO::FETCH_COLUMN)]);
            }
            // Members and unlinks cascade; every former member's independent profile is used again.
            $this->db->prepare('DELETE FROM npc_reference_groups WHERE installation_id=:installation AND group_key=:key')->execute(['installation'=>$installation,'key'=>$group]);
        });
    }

    /** Durable individual opt-out: the member returns to its own profile and stays out through rediscovery. */
    public function unlink(string $installation,string $group,string $reference):void
    {
        $reference=$this->normalizeReference($reference);
        $this->write($installation,function()use($installation,$group,$reference):void{
            $row=$this->lockGroup($installation,$group);
            if($row['canonical_ref']===$reference)throw new InvalidArgumentException('reference_group_keeper_unlink');
            $member=$this->db->prepare('SELECT 1 FROM npc_reference_group_members WHERE installation_id=:installation AND group_key=:key AND member_ref=:ref');
            $member->execute(['installation'=>$installation,'key'=>$group,'ref'=>$reference]);
            if(!in_array($reference,$this->json($row['aliases']),true)&&!$member->fetchColumn())throw new InvalidArgumentException('reference_group_member_not_found');
            $insert=$this->db->prepare('INSERT INTO npc_reference_group_optouts(installation_id,group_key,member_ref) VALUES(:installation,:key,:ref) ON CONFLICT DO NOTHING');
            $insert->execute(['installation'=>$installation,'key'=>$group,'ref'=>$reference]);
            if($insert->rowCount()===0)return;
            $this->db->prepare('DELETE FROM npc_reference_group_members WHERE installation_id=:installation AND group_key=:key AND member_ref=:ref')
                ->execute(['installation'=>$installation,'key'=>$group,'ref'=>$reference]);
            $this->bump($installation,[$group]);$this->invalidateBindings($installation,[$reference]);
        });
    }

    /** Remove only the intended opt-out; a member owned by another enabled group is rejected. */
    public function relink(string $installation,string $group,string $reference):void
    {
        $reference=$this->normalizeReference($reference);
        $this->write($installation,function()use($installation,$group,$reference):void{
            $this->lockGroup($installation,$group);
            $owned=$this->db->prepare('SELECT 1 FROM npc_reference_groups g WHERE g.installation_id=:installation AND g.group_key<>:key AND g.enabled '
                .'AND (g.canonical_ref=:canonical OR jsonb_exists(g.aliases,:alias) OR EXISTS(SELECT 1 FROM npc_reference_group_members m '
                .'WHERE m.installation_id=g.installation_id AND m.group_key=g.group_key AND m.member_ref=:member))');
            $owned->execute(['installation'=>$installation,'key'=>$group,'canonical'=>$reference,'alias'=>$reference,'member'=>$reference]);
            if($owned->fetchColumn())throw new InvalidArgumentException('reference_already_in_group');
            $delete=$this->db->prepare('DELETE FROM npc_reference_group_optouts WHERE installation_id=:installation AND group_key=:key AND member_ref=:ref');
            $delete->execute(['installation'=>$installation,'key'=>$group,'ref'=>$reference]);
            if($delete->rowCount()!==1)throw new InvalidArgumentException('reference_group_member_not_unlinked');
            $this->bump($installation,[$group]);$this->invalidateBindings($installation,[$reference]);
        });
    }

    /** The earliest observed placed reference with this exact name that no other group explicitly owns. */
    private function observedReferenceByName(string $installation,string $name,array $others):string
    {
        $owned=[];foreach($others as$group)foreach([$group['canonical_ref'],...$group['aliases']]as$ref)$owned[$ref]=true;
        $query=$this->db->prepare("SELECT actor_identity FROM actor_profile_bindings WHERE installation_id=:installation "
            ."AND actor_identity->>'kind' NOT IN ('player','narrator') AND lower(btrim(actor_identity->>'display_name'))=lower(:name) "
            ."ORDER BY created_at,actor_key LIMIT 50");
        $query->execute(['installation'=>$installation,'name'=>$name]);
        foreach($query->fetchAll(PDO::FETCH_COLUMN)as$identity){
            $identity=$this->json($identity);
            if(self::fold((string)($identity['display_name']??''))!==self::fold($name)||$this->memberIdentity($identity)===null)continue;
            $reference=ProfileId::reference($identity);
            if(!isset($owned[$reference]))return $reference;
        }
        throw new InvalidArgumentException('reference_group_name_not_observed');
    }

    /** Bound actors a new or re-enabled name rule may capture, excluding references another group explicitly owns. */
    private function boundReferencesByName(string $installation,string $name,array $others):array
    {
        $owned=[];foreach($others as$group)foreach([$group['canonical_ref'],...$group['aliases']]as$ref)$owned[$ref]=true;
        $query=$this->db->prepare("SELECT actor_identity FROM actor_profile_bindings WHERE installation_id=:installation "
            ."AND lower(btrim(actor_identity->>'display_name'))=lower(:name) LIMIT 500");
        $query->execute(['installation'=>$installation,'name'=>trim($name)]);$references=[];
        foreach($query->fetchAll(PDO::FETCH_COLUMN)as$identity){
            $identity=$this->json($identity);
            if(self::fold((string)($identity['display_name']??''))!==self::fold($name)||$this->memberIdentity($identity)===null)continue;
            $reference=ProfileId::reference($identity);if(!isset($owned[$reference]))$references[]=$reference;
        }
        return $references;
    }

    /** Durable key of an in-game placed reference, or null for player, keyless or recordless identities. */
    private function memberIdentity(array $identity):?array
    {
        if(in_array($identity['kind']??'npc',['player','narrator','template'],true)||!is_string($identity['record_id']??null)
            ||trim($identity['record_id'])==='')return null;
        try{ProfileId::reference($identity);}catch(InvalidArgumentException){return null;}
        return ProfileId::durableIdentity($identity);
    }

    private function lockGroup(string $installation,string $group):array
    {
        if(preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D',$group)!==1)throw new InvalidArgumentException('invalid_reference_group');
        $query=$this->db->prepare('SELECT * FROM npc_reference_groups WHERE installation_id=:installation AND group_key=:key FOR UPDATE');
        $query->execute(['installation'=>$installation,'key'=>$group]);
        return $query->fetch()?:throw new InvalidArgumentException('reference_group_not_found');
    }

    private function forget(string $installation,array $groups,array $references):void
    {
        foreach($references as$reference)
            $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')->execute(['key'=>self::memberLock($installation,$reference)]);
        $this->db->prepare('DELETE FROM npc_reference_group_members WHERE installation_id=:installation '
            .'AND group_key IN (SELECT jsonb_array_elements_text(CAST(:groups AS jsonb))) AND member_ref IN (SELECT jsonb_array_elements_text(CAST(:refs AS jsonb)))')
            ->execute(['installation'=>$installation,'groups'=>json_encode($groups,JSON_THROW_ON_ERROR),'refs'=>json_encode($references,JSON_THROW_ON_ERROR)]);
        $this->bump($installation,$groups);
    }

    private function bump(string $installation,array $groups):void
    {
        if($groups===[])return;
        $this->db->prepare("UPDATE npc_reference_groups SET revision=nextval('lorkhan_internal.npc_reference_group_revision_seq') "
            .'WHERE installation_id=:installation AND group_key IN (SELECT jsonb_array_elements_text(CAST(:groups AS jsonb)))')
            ->execute(['installation'=>$installation,'groups'=>json_encode(array_values($groups),JSON_THROW_ON_ERROR)]);
    }

    /** Drop only bindings of physical references whose mapping changed. */
    private function invalidateBindings(string $installation,array $references):void
    {
        $references=array_values(array_unique($references));if($references===[])return;
        $this->db->prepare("DELETE FROM actor_profile_bindings WHERE installation_id=:installation AND lower(actor_identity->>'content_file')||'|'||(actor_identity->'refnum'->>'index') IN (SELECT jsonb_array_elements_text(CAST(:refs AS jsonb)))")
            ->execute(['installation'=>$installation,'refs'=>json_encode($references,JSON_THROW_ON_ERROR)]);
    }

    private function write(string $installation,callable $work):mixed
    {
        if(!Uuid::isValid($installation))throw new InvalidArgumentException('invalid_installation_id');
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            $this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:id FOR UPDATE')->execute(['id'=>$installation]);
            $result=$work();
            if($owns)$this->db->commit();
            return $result;
        }catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    private function normalizeReference(string $reference):string
    {
        $parts=explode('|',strtolower(trim($reference)));
        if(count($parts)!==2||preg_match('/^(0|[1-9][0-9]{0,9})$/D',$parts[1])!==1||(int)$parts[1]>4294967295)
            throw new InvalidArgumentException('invalid_actor_reference');
        return ProfileId::reference(['kind'=>'npc','content_file'=>$parts[0],'refnum'=>['index'=>(int)$parts[1]]]);
    }

    private static function fold(string $name):string{return mb_strtolower(trim($name),'UTF-8');}
    private function bool(mixed $value):bool{return in_array($value,[true,1,'1','t','true'],true);}
    private function json(mixed $value):array{return is_array($value)?$value:json_decode((string)$value,true,32,JSON_THROW_ON_ERROR);}
}
