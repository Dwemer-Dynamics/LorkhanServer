<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use DomainException;
use PDO;
use RuntimeException;

/** Save-owned character identity; history and mutable profile isolation are distinct concerns. */
final class CharacterPlaythroughRepository
{
    public function __construct(private readonly PDO $db) {}

    /** An approved web link is consumed only inside the next installation-locked handshake. */
    public function resolveSessionMessage(array $message):array
    {
        if(!$this->db->inTransaction())throw new RuntimeException('character_binding_transaction_required');
        if(!isset($message['character_id']))return$message;
        $query=$this->db->prepare('SELECT playthrough_id FROM character_playthrough_bindings WHERE installation_id=:installation AND character_id=:character FOR UPDATE');
        $scope=['installation'=>$message['installation_id'],'character'=>$message['character_id']];$query->execute($scope);$current=$query->fetchColumn();
        if($current===false)return$message;
        $query=$this->db->prepare("SELECT * FROM playthrough_associations WHERE installation_id=:installation AND character_id=:character AND state='pending' FOR UPDATE");$query->execute($scope);$pending=$query->fetch();
        if($pending){
            if($pending['from_playthrough_id']!==$current)throw new DomainException('character_binding_conflict');
            $query=$this->db->prepare("SELECT 1 FROM playthroughs w JOIN profiles p ON p.profile_id=w.profile_id AND p.playthrough_id=w.playthrough_id WHERE w.installation_id=:installation AND w.playthrough_id=:world AND w.deleted_at IS NULL AND p.deleted_at IS NULL AND NOT EXISTS(SELECT 1 FROM character_playthrough_bindings b WHERE b.installation_id=w.installation_id AND b.playthrough_id=w.playthrough_id) AND NOT EXISTS(SELECT 1 FROM sessions s WHERE s.playthrough_id=w.playthrough_id AND s.state='active' AND NOT s.archived)");
            $query->execute(['installation'=>$message['installation_id'],'world'=>$pending['to_playthrough_id']]);if(!$query->fetchColumn())throw new DomainException('character_binding_conflict');
            $current=$pending['to_playthrough_id'];
            $this->db->prepare('UPDATE character_playthrough_bindings SET playthrough_id=:world,binding_mode=\'existing\' WHERE installation_id=:installation AND character_id=:character')->execute($scope+['world'=>$current]);
            $this->db->prepare("UPDATE playthrough_associations SET state='applied',completed_at=clock_timestamp() WHERE association_id=:id")->execute(['id'=>$pending['association_id']]);
        }
        // The saved character identity is authoritative; older saves may still carry its previous world ID.
        $message['playthrough_id']=$current;
        return$message;
    }

    public function queueAssociation(string $installation,string $character,string $expectedPlaythrough,string $target):array
    {
        foreach([$installation,$character,$expectedPlaythrough,$target]as$id)if(!Uuid::isValid($id))throw new RuntimeException('scope_mismatch');
        return$this->locked($installation,function()use($installation,$character,$expectedPlaythrough,$target):array{
            $q=$this->db->prepare('SELECT playthrough_id FROM character_playthrough_bindings WHERE installation_id=:installation AND character_id=:character');$q->execute(['installation'=>$installation,'character'=>$character]);
            if($q->fetchColumn()!==$expectedPlaythrough)throw new RuntimeException('revision_conflict');
            if($target===$expectedPlaythrough)throw new RuntimeException('association_conflict');
            $q=$this->db->prepare("SELECT 1 FROM playthroughs w JOIN profiles p ON p.profile_id=w.profile_id AND p.playthrough_id=w.playthrough_id WHERE w.installation_id=:installation AND w.playthrough_id=:target AND w.deleted_at IS NULL AND p.deleted_at IS NULL AND NOT EXISTS(SELECT 1 FROM character_playthrough_bindings b WHERE b.installation_id=w.installation_id AND b.playthrough_id=w.playthrough_id) AND NOT EXISTS(SELECT 1 FROM sessions s WHERE s.playthrough_id=w.playthrough_id AND s.state='active' AND NOT s.archived)");
            $q->execute(['installation'=>$installation,'target'=>$target]);if(!$q->fetchColumn())throw new RuntimeException('playthrough_in_use');
            $q=$this->db->prepare("SELECT 1 FROM playthrough_associations WHERE installation_id=:installation AND state='pending' AND (character_id=:character OR to_playthrough_id=:target)");$q->execute(['installation'=>$installation,'character'=>$character,'target'=>$target]);if($q->fetchColumn())throw new RuntimeException('association_conflict');
            $id=Uuid::v4();$this->db->prepare('INSERT INTO playthrough_associations(association_id,installation_id,character_id,from_playthrough_id,to_playthrough_id) VALUES(:id,:installation,:character,:source,:target)')
                ->execute(['id'=>$id,'installation'=>$installation,'character'=>$character,'source'=>$expectedPlaythrough,'target'=>$target]);
            return['association_id'=>$id,'state'=>'pending','from_playthrough_id'=>$expectedPlaythrough,'to_playthrough_id'=>$target];
        });
    }

    public function cancelAssociation(string $installation,string $association):void
    {
        if(!Uuid::isValid($association))throw new RuntimeException('scope_mismatch');
        $this->locked($installation,function()use($installation,$association):void{
            $q=$this->db->prepare("UPDATE playthrough_associations SET state='cancelled',completed_at=clock_timestamp() WHERE installation_id=:installation AND association_id=:id AND state='pending'");$q->execute(['installation'=>$installation,'id'=>$association]);if($q->rowCount()!==1)throw new RuntimeException('revision_conflict');
        });
    }

    public function createEmpty(string $installation,string $name):array
    {
        $name=$this->name($name);
        return$this->locked($installation,function()use($installation,$name):array{
            $world=Uuid::v4();$profile=(new ProfileOwnershipRepository($this->db))->prepareSessionOwners(['installation_id'=>$installation,'playthrough_id'=>$world,'profile_id'=>Uuid::v4(),'created_at'=>gmdate('c'),'content_fingerprint'=>'sha256:'.str_repeat('0',64)]);
            $this->db->prepare('UPDATE playthroughs SET name=:name WHERE playthrough_id=:world')->execute(['name'=>$name,'world'=>$world]);
            $this->db->prepare("UPDATE playthrough_revisions SET content=content||jsonb_build_object('name',CAST(:name AS text)) WHERE playthrough_id=:world AND revision=1")->execute(['name'=>$name,'world'=>$world]);
            return['playthrough_id'=>$world,'profile_id'=>$profile,'name'=>$name,'current_revision'=>1,'active'=>false];
        });
    }

    public function copyPlaythrough(string $installation,string $playthrough):array
    {
        $archive=new PlaythroughArchive($this->db);return$archive->importCopy($installation,json_encode($archive->export($installation,$playthrough),JSON_THROW_ON_ERROR));
    }

    public function renamePlaythrough(string $installation,string $playthrough,string $name,int $expectedRevision):array
    {
        $name=$this->name($name);
        return$this->locked($installation,function()use($installation,$playthrough,$name,$expectedRevision):array{
            $world=$this->editable($installation,$playthrough,$expectedRevision);
            $q=$this->db->prepare('UPDATE playthroughs SET name=:name,current_revision=current_revision+1 WHERE playthrough_id=:world RETURNING current_revision');$q->execute(['name'=>$name,'world'=>$playthrough]);$revision=(int)$q->fetchColumn();
            $this->db->prepare("INSERT INTO playthrough_revisions(playthrough_id,revision,content,change_reason,created_at) SELECT playthrough_id,:next,content||jsonb_build_object('name',CAST(:name AS text)),'renamed',clock_timestamp() FROM playthrough_revisions WHERE playthrough_id=:world AND revision=:previous")
                ->execute(['next'=>$revision,'name'=>$name,'world'=>$playthrough,'previous'=>$world['current_revision']]);
            return['playthrough_id'=>$playthrough,'name'=>$name,'current_revision'=>$revision];
        });
    }

    public function deletePlaythrough(string $installation,string $playthrough,int $expectedRevision):void
    {
        $this->locked($installation,function()use($installation,$playthrough,$expectedRevision):void{
            $this->editable($installation,$playthrough,$expectedRevision);
            if((new ProfileOwnershipRepository($this->db))->activePlaythrough($installation)===$playthrough)throw new RuntimeException('playthrough_in_use');
            $q=$this->db->prepare("SELECT 1 WHERE EXISTS(SELECT 1 FROM character_playthrough_bindings WHERE installation_id=:installation AND playthrough_id=:world) OR EXISTS(SELECT 1 FROM sessions WHERE installation_id=:installation AND playthrough_id=:world AND state='active' AND NOT archived) OR EXISTS(SELECT 1 FROM playthrough_associations WHERE installation_id=:installation AND state='pending' AND (from_playthrough_id=:world OR to_playthrough_id=:world))");$q->execute(['installation'=>$installation,'world'=>$playthrough]);if($q->fetchColumn())throw new RuntimeException('playthrough_in_use');
            $this->db->prepare('UPDATE playthroughs SET deleted_at=clock_timestamp() WHERE playthrough_id=:world')->execute(['world'=>$playthrough]);
        });
    }

    private function editable(string $installation,string $playthrough,int $revision):array
    {
        if(!Uuid::isValid($playthrough)||$revision<1)throw new RuntimeException('scope_mismatch');
        $q=$this->db->prepare('SELECT * FROM playthroughs WHERE installation_id=:installation AND playthrough_id=:world AND deleted_at IS NULL FOR UPDATE');$q->execute(['installation'=>$installation,'world'=>$playthrough]);$world=$q->fetch();
        if(!$world)throw new RuntimeException('not_found');if((int)$world['current_revision']!==$revision)throw new RuntimeException('revision_conflict');return$world;
    }

    private function name(string $name):string
    {
        $name=trim($name);if($name===''||!mb_check_encoding($name,'UTF-8')||mb_strlen($name)>256||str_contains($name,"\0"))throw new RuntimeException('invalid_playthrough_name');return$name;
    }

    /** Serialize web changes with game admission; rejected edits never retire a running session. */
    private function locked(string $installation,callable $operation):mixed
    {
        if(!Uuid::isValid($installation))throw new RuntimeException('scope_mismatch');$owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{$q=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:installation AND revoked_at IS NULL FOR UPDATE');$q->execute(['installation'=>$installation]);if(!$q->fetchColumn())throw new RuntimeException('not_found');$result=$operation();if($owns)$this->db->commit();return$result;}
        catch(\Throwable$error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    /** Check under the installation lock, before creating owners or cancelling outgoing work. */
    public function assertSessionBinding(array $message):?string
    {
        if(!$this->db->inTransaction())throw new RuntimeException('character_binding_transaction_required');
        $installation=$message['installation_id'];
        $character=$message['character_id']??null;$mode=$message['character_binding']??null;
        if($character===null&&$mode===null){
            $query=$this->db->prepare('SELECT 1 FROM character_playthrough_bindings WHERE installation_id=:installation LIMIT 1');
            $query->execute(['installation'=>$installation]);
            if($query->fetchColumn())throw new DomainException('character_binding_required');
            return null; // Legacy installations remain unchanged until explicit adoption.
        }
        if(!is_string($character)||!Uuid::isValid($character)||!in_array($mode,['new','existing'],true))
            throw new DomainException('character_binding_required');
        $query=$this->db->prepare('SELECT character_id,playthrough_id FROM character_playthrough_bindings
            WHERE installation_id=:installation AND (character_id=:character OR playthrough_id=:playthrough)');
        $query->execute(['installation'=>$installation,'character'=>$character,'playthrough'=>$message['playthrough_id']]);$bindings=$query->fetchAll();
        if($bindings!==[]){
            if(count($bindings)!==1||$bindings[0]['playthrough_id']!==$message['playthrough_id']
                ||($bindings[0]['character_id']!==$character&&$mode!=='existing'))
                throw new DomainException('character_binding_conflict');
            // Explicit recovery of an older untagged save reuses the existing world identity.
            // A requested character already bound elsewhere produced a different row above and fails closed.
            $character=$bindings[0]['character_id'];
        }
        $query=$this->db->prepare('SELECT installation_id,profile_id,deleted_at FROM playthroughs WHERE playthrough_id=:playthrough');
        $query->execute(['playthrough'=>$message['playthrough_id']]);$owner=$query->fetch();
        if($owner&&($owner['installation_id']!==$installation||$owner['deleted_at']!==null))
            throw new DomainException('character_binding_conflict');
        if($bindings!==[])return $character;
        if($mode==='existing'){
            if(!$owner){
                // A gameplay reset keeps pairing but removes every world. Rebuild the first saved
                // character's server records; never adopt a missing world beside existing history.
                $query=$this->db->prepare('SELECT 1 WHERE EXISTS(SELECT 1 FROM playthroughs WHERE installation_id=:installation) OR EXISTS(SELECT 1 FROM character_playthrough_bindings WHERE installation_id=:installation)');
                $query->execute(['installation'=>$installation]);
                if($query->fetchColumn())throw new DomainException('character_binding_conflict');
            }
            return $character;
        }
        if($owner)throw new DomainException('character_binding_conflict');
        return $character;
    }

    /** Save only after the requested playthrough owners passed their normal scope checks. */
    public function bind(array $message,?string $canonicalCharacter=null):void
    {
        if(!isset($message['character_id']))return;
        if(!$this->db->inTransaction())throw new RuntimeException('character_binding_transaction_required');
        $this->db->prepare('INSERT INTO character_playthrough_bindings(installation_id,character_id,playthrough_id,binding_mode)
            VALUES(:installation,:character,:playthrough,:mode) ON CONFLICT(installation_id,character_id) DO NOTHING')
            ->execute(['installation'=>$message['installation_id'],'character'=>$canonicalCharacter??$message['character_id'],
                'playthrough'=>$message['playthrough_id'],'mode'=>$message['character_binding']]);
    }

    /** Read-only management state; the selected save remains the authority for switching. */
    public function state(string $installation):array
    {
        if(!Uuid::isValid($installation))throw new \InvalidArgumentException('invalid_installation_id');
        $query=$this->db->prepare('SELECT b.character_id,b.playthrough_id,b.binding_mode,b.created_at,p.name
            FROM character_playthrough_bindings b JOIN playthroughs p USING(playthrough_id)
            WHERE b.installation_id=:installation ORDER BY b.created_at,b.character_id LIMIT 100');
        $query->execute(['installation'=>$installation]);$bindings=$query->fetchAll();
        $query=$this->db->prepare('SELECT session_id,profile_id,playthrough_id,character_id,generation,state FROM sessions
            WHERE installation_id=:installation AND NOT archived ORDER BY generation DESC LIMIT 1');
        $query->execute(['installation'=>$installation]);$current=$query->fetch()?:null;
        $query=$this->db->prepare("SELECT * FROM playthrough_associations WHERE installation_id=:installation AND state='pending' ORDER BY created_at LIMIT 100");$query->execute(['installation'=>$installation]);$pending=$query->fetchAll();
        $query=$this->db->prepare('SELECT playthrough_id,profile_id,name,current_revision,created_at FROM playthroughs WHERE installation_id=:installation AND deleted_at IS NULL ORDER BY created_at,playthrough_id LIMIT 500');$query->execute(['installation'=>$installation]);$worlds=$query->fetchAll();
        return ['bindings'=>$bindings,'current'=>$current,'playthroughs'=>$worlds,'pending_associations'=>$pending,'switch_available'=>true,'blocked_reason'=>null];
    }
}
