<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use DomainException;
use PDO;
use RuntimeException;

/** Resolves save-owned mutable profiles without rewriting historical scope anchors. */
final class ProfileOwnershipRepository
{
    public function __construct(private readonly PDO $db) {}

    public function activePlaythrough(string $installation):?string
    {
        $q=$this->db->prepare('SELECT playthrough_id FROM sessions WHERE installation_id=:installation AND character_id IS NOT NULL ORDER BY generation DESC LIMIT 1');
        $q->execute(['installation'=>$installation]);$id=$q->fetchColumn();
        return $id===false?null:(string)$id;
    }

    /** Called under the installation lock; every rejection rolls back the complete handshake. */
    public function prepareSessionOwners(array $message):string
    {
        if(!$this->db->inTransaction())throw new RuntimeException('profile_ownership_transaction_required');
        $installation=$message['installation_id'];$world=$message['playthrough_id'];
        $q=$this->db->prepare('SELECT installation_id FROM profiles WHERE profile_id=:profile');
        $q->execute(['profile'=>$message['profile_id']]);$hint=$q->fetchColumn();
        if($hint!==false&&$hint!==$installation)throw new DomainException('profile_scope_conflict');
        $q=$this->db->prepare('SELECT p.profile_id,p.playthrough_id,p.actor_identity FROM playthroughs w JOIN profiles p ON p.profile_id=w.profile_id WHERE w.playthrough_id=:world AND w.installation_id=:installation FOR UPDATE OF p');
        $q->execute(['world'=>$world,'installation'=>$installation]);$owner=$q->fetch();
        if($owner){
            if($owner['playthrough_id']===$world)return (string)$owner['profile_id'];
            if($owner['playthrough_id']!==null)throw new DomainException('playthrough_isolation_required');
            // Without provenance separating several legacy worlds, adoption must not guess ownership.
            $q=$this->db->prepare('SELECT COUNT(*) FROM playthroughs WHERE installation_id=:installation');
            $q->execute(['installation'=>$installation]);
            if((int)$q->fetchColumn()!==1)throw new DomainException('playthrough_isolation_required');
            $identity=json_decode((string)$owner['actor_identity'],true);
            if(in_array($identity['kind']??'', ['narrator','template'],true))throw new DomainException('playthrough_isolation_required');
            $this->db->prepare("UPDATE profiles SET playthrough_id=:world WHERE installation_id=:installation AND playthrough_id IS NULL AND COALESCE(actor_identity->>'kind','') NOT IN ('narrator','template')")
                ->execute(['world'=>$world,'installation'=>$installation]);
            return (string)$owner['profile_id'];
        }
        $profile=$hint===false?$message['profile_id']:Uuid::v4();
        $this->db->prepare("INSERT INTO profiles(profile_id,installation_id,playthrough_id,name,actor_identity,created_at) VALUES(:profile,:installation,:world,'Player','{\"kind\":\"player\",\"display_name\":\"Player\"}'::jsonb,:created)")
            ->execute(['profile'=>$profile,'installation'=>$installation,'world'=>$world,'created'=>$message['created_at']]);
        $this->db->prepare("INSERT INTO profile_revisions(profile_id,revision,content,change_reason,created_at) VALUES(:profile,1,'{\"biography\":\"\",\"appearance\":\"\",\"personality\":\"\",\"speech_style\":\"\",\"goals\":\"\",\"notes\":\"\"}'::jsonb,'new character',:created)")
            ->execute(['profile'=>$profile,'created'=>$message['created_at']]);
        $this->db->prepare('INSERT INTO playthroughs(playthrough_id,installation_id,profile_id,name,content_fingerprint,created_at) VALUES(:world,:installation,:profile,:name,:fingerprint,:created)')
            ->execute(['world'=>$world,'installation'=>$installation,'profile'=>$profile,'name'=>'Playthrough '.$world,'fingerprint'=>$message['content_fingerprint'],'created'=>$message['created_at']]);
        $this->db->prepare("INSERT INTO playthrough_revisions(playthrough_id,revision,content,change_reason,created_at) VALUES(:world,1,'{\"source\":\"character-binding\"}'::jsonb,'new character',:created)")
            ->execute(['world'=>$world,'created'=>$message['created_at']]);
        return $profile;
    }
}
