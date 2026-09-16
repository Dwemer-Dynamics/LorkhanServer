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
        $query=$this->db->prepare('SELECT playthrough_id FROM sessions WHERE installation_id=:installation ORDER BY generation DESC LIMIT 1');
        $query->execute(['installation'=>$installation]);$previous=$query->fetchColumn();
        // NPC and Player profiles are not yet scoped. Reject switches rather than mixing their revisions.
        if($previous!==false&&$previous!==$message['playthrough_id'])throw new DomainException('playthrough_isolation_required');
        $query=$this->db->prepare('SELECT installation_id,profile_id,deleted_at FROM playthroughs WHERE playthrough_id=:playthrough');
        $query->execute(['playthrough'=>$message['playthrough_id']]);$owner=$query->fetch();
        if($owner&&($owner['installation_id']!==$installation||$owner['profile_id']!==$message['profile_id']||$owner['deleted_at']!==null))
            throw new DomainException('character_binding_conflict');
        if($bindings!==[])return $character;
        if($mode==='existing'){
            if(!$owner)throw new DomainException('character_binding_conflict');
            return $character;
        }
        if($owner)throw new DomainException('character_binding_conflict');
        $query=$this->db->prepare('SELECT 1 FROM playthroughs WHERE installation_id=:installation LIMIT 1');
        $query->execute(['installation'=>$installation]);
        if($query->fetchColumn())throw new DomainException('playthrough_isolation_required');
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

    /** Read-only management state: no switch control is offered until mutable profiles are scoped. */
    public function state(string $installation):array
    {
        if(!Uuid::isValid($installation))throw new \InvalidArgumentException('invalid_installation_id');
        $query=$this->db->prepare('SELECT b.character_id,b.playthrough_id,b.binding_mode,b.created_at,p.name
            FROM character_playthrough_bindings b JOIN playthroughs p USING(playthrough_id)
            WHERE b.installation_id=:installation ORDER BY b.created_at,b.character_id LIMIT 100');
        $query->execute(['installation'=>$installation]);$bindings=$query->fetchAll();
        $query=$this->db->prepare('SELECT session_id,playthrough_id,character_id,generation,state FROM sessions
            WHERE installation_id=:installation ORDER BY generation DESC LIMIT 1');
        $query->execute(['installation'=>$installation]);$current=$query->fetch()?:null;
        return ['bindings'=>$bindings,'current'=>$current,'switch_available'=>false,'blocked_reason'=>'playthrough_isolation_required'];
    }
}
