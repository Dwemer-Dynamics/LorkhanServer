<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;
use PDO;

/** Game-owned disposition snapshots and bounded, acknowledged AI proposals. */
final class GameDispositionRepository
{
    public function __construct(private readonly PDO $db) {}

    public function snapshot(array $scope,array $actor,array $player):?array
    {
        $q=$this->db->prepare('SELECT disposition,base_disposition,session_id,generation,observed_at FROM game_dispositions WHERE installation_id=:installation AND playthrough_id=:playthrough AND actor_key=:actor AND player_key=:player');
        $q->execute($this->keys($scope,$actor,$player));$row=$q->fetch();
        if(!$row)return null;
        $row['disposition']=(int)$row['disposition'];$row['base_disposition']=(int)$row['base_disposition'];return $row;
    }

    /** Called after immutable source persistence, within the session-locked ingress transaction. */
    public function observe(array $message):void
    {
        $p=$message['payload'];$keys=$this->keys($message,$p['actor'],$p['player']);
        if(isset($p['adjustment_id'])){
            $q=$this->db->prepare("UPDATE disposition_adjustments SET status=:status,confirmed_source_id=:source WHERE adjustment_id=:id AND installation_id=:installation AND playthrough_id=:playthrough AND session_id=:session AND generation=:generation AND actor_key=:actor AND player_key=:player AND status='pending' RETURNING adjustment_id");
            $q->execute($keys+['status'=>$p['status'],'source'=>$message['request_id'],'id'=>$p['adjustment_id'],'session'=>$message['session_id'],'generation'=>$message['generation']]);
            if(!$q->fetchColumn())return;
        }
        $q=$this->db->prepare('INSERT INTO game_dispositions(installation_id,playthrough_id,actor_key,player_key,actor,player,base_disposition,disposition,session_id,generation,observed_at,source_event_id) VALUES(:installation,:playthrough,:actor,:player,CAST(:actor_json AS jsonb),CAST(:player_json AS jsonb),:base,:disposition,:session,:generation,:observed,:source) ON CONFLICT(installation_id,playthrough_id,actor_key,player_key) DO UPDATE SET actor=EXCLUDED.actor,player=EXCLUDED.player,base_disposition=EXCLUDED.base_disposition,disposition=EXCLUDED.disposition,session_id=EXCLUDED.session_id,generation=EXCLUDED.generation,observed_at=EXCLUDED.observed_at,source_event_id=EXCLUDED.source_event_id WHERE game_dispositions.session_id<>EXCLUDED.session_id OR game_dispositions.observed_at<=EXCLUDED.observed_at');
        $q->execute($keys+['actor_json'=>json_encode($p['actor'],JSON_THROW_ON_ERROR),'player_json'=>json_encode($p['player'],JSON_THROW_ON_ERROR),'base'=>$p['base_disposition'],'disposition'=>$p['disposition'],'session'=>$message['session_id'],'generation'=>$message['generation'],'observed'=>$message['observed_at'],'source'=>$message['request_id']]);
        // Only the bound NPC's relationship to this exact player is a game mirror.
        // A changed score invalidates queued evaluations; repeated identical snapshots do not.
        if($q->rowCount()>0){
            $sync=$this->db->prepare('UPDATE relationship_records r SET disposition=:disposition,updated_at=clock_timestamp() FROM actor_profile_bindings b WHERE b.installation_id=:installation AND b.playthrough_id=:playthrough AND b.actor_key=:actor AND r.installation_id=b.installation_id AND r.playthrough_id=b.playthrough_id AND r.profile_id=b.profile_id AND r.deleted_at IS NULL AND relationship_identity_key(r.actor_identity)=relationship_identity_key(CAST(:player_json AS jsonb)) AND r.disposition<>:effective');
            $sync->execute(['installation'=>$message['installation_id'],'playthrough'=>$message['playthrough_id'],'actor'=>$keys['actor'],'player_json'=>json_encode($p['player'],JSON_THROW_ON_ERROR),'disposition'=>$p['disposition'],'effective'=>$p['disposition']]);
        }
    }

    /** Proposals never change either mirror; only the game acknowledgement does that. */
    public function propose(array $source,array $job,int $delta):void
    {
        $delta=max(-3,min(3,$delta));if($delta===0||($source['owner_identity']['kind']??'')!=='npc'||($source['target_identity']['kind']??'')!=='player')return;
        $q=$this->db->prepare("SELECT 1 FROM sessions WHERE session_id=:session AND generation=:generation AND state='active' AND 'relationship.disposition'=ANY(capabilities) FOR UPDATE");
        $q->execute(['session'=>$source['session_id'],'generation'=>$source['generation']]);if(!$q->fetchColumn())return;
        $snapshot=$this->snapshot($source,$source['owner_identity'],$source['target_identity']);
        if($snapshot===null||$snapshot['session_id']!==$source['session_id']||(int)$snapshot['generation']!==(int)$source['generation'])return;
        $id=Uuid::v4();$expires=gmdate('Y-m-d\TH:i:s\Z',time()+90);
        $q=$this->db->prepare('INSERT INTO disposition_adjustments(adjustment_id,job_id,installation_id,playthrough_id,session_id,generation,turn_id,actor_key,player_key,actor,player,delta,expires_at) VALUES(:id,:job,:installation,:playthrough,:session,:generation,:turn,:actor,:player,CAST(:actor_json AS jsonb),CAST(:player_json AS jsonb),:delta,:expires) ON CONFLICT(job_id) DO NOTHING RETURNING adjustment_id');
        $q->execute($this->keys($source,$source['owner_identity'],$source['target_identity'])+['id'=>$id,'job'=>$job['job_id'],'session'=>$source['session_id'],'generation'=>$source['generation'],'turn'=>$source['turn_id'],'actor_json'=>json_encode($source['owner_identity'],JSON_THROW_ON_ERROR),'player_json'=>json_encode($source['target_identity'],JSON_THROW_ON_ERROR),'delta'=>$delta,'expires'=>$expires]);
        if(!$q->fetchColumn())return;
        $q=$this->db->prepare('UPDATE sessions SET event_sequence=event_sequence+1 WHERE session_id=:session RETURNING event_sequence');$q->execute(['session'=>$source['session_id']]);$sequence=$q->fetchColumn();
        $event=['adjustment_id'=>$id,'actor'=>$source['owner_identity'],'player'=>$source['target_identity'],'delta'=>$delta,'expires_at'=>$expires];
        $q=$this->db->prepare("INSERT INTO response_events(session_id,sequence,message_id,request_id,generation,turn_id,event_type,payload,created_at) SELECT :session,:sequence,:message,request_id,:generation,turn_id,'relationship.adjust',CAST(:payload AS jsonb),clock_timestamp() FROM turns WHERE turn_id=:turn");
        $q->execute(['session'=>$source['session_id'],'sequence'=>$sequence,'message'=>Uuid::v4(),'generation'=>$source['generation'],'turn'=>$source['turn_id'],'payload'=>json_encode($event,JSON_THROW_ON_ERROR)]);
    }

    private function keys(array $scope,array $actor,array $player):array
    {
        $products=new ProductRepository($this->db);
        return ['installation'=>$scope['installation_id'],'playthrough'=>$scope['playthrough_id'],'actor'=>$products->actorKey($actor),'player'=>$products->actorKey($player)];
    }
}
