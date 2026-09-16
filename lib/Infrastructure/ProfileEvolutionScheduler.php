<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\MorrowindCalendar;
use PDO;

/** Durable game-calendar scheduling; provider calls remain ordinary leased profile jobs. */
final class ProfileEvolutionScheduler
{
    public function __construct(private readonly PDO $db) {}

    /** Called inside accepted-source transactions, never from client wall-clock timestamps. */
    public function observe(string $installation, ?string $session, string $kind, array $payload): void
    {
        if ($session===null) return;
        $calendar=$kind==='session.init'?($payload['loaded_save']??null):
            ($payload['context']['world']['calendar']??$payload['payload']['context']['world']['calendar']??$payload['calendar']??$payload['payload']['calendar']??null);
        $date=MorrowindCalendar::parse($calendar);if($date===null)return;
        $query=$this->db->prepare("SELECT playthrough_id FROM sessions WHERE session_id=:session AND installation_id=:installation AND state='active'");
        $query->execute(['session'=>$session,'installation'=>$installation]);$playthrough=$query->fetchColumn();if(!$playthrough)return;
        $scope=['installation'=>$installation,'playthrough'=>$playthrough];
        $this->db->prepare('INSERT INTO lorkhan_internal.profile_evolution_clocks(installation_id,playthrough_id,epoch,game_minute,started_minute)
            VALUES(:installation,:playthrough,:epoch,:minute,:start) ON CONFLICT DO NOTHING')->execute($scope+['epoch'=>Uuid::v4(),'minute'=>$date['minute'],'start'=>$date['minute']]);
        $query=$this->db->prepare('SELECT * FROM lorkhan_internal.profile_evolution_clocks WHERE installation_id=:installation AND playthrough_id=:playthrough FOR UPDATE');
        $query->execute($scope);$clock=$query->fetch();
        if($kind==='session.init'&&$date['minute']<(int)$clock['game_minute']){
            $this->db->prepare('UPDATE lorkhan_internal.profile_evolution_clocks SET epoch=:epoch,game_minute=:minute,started_minute=:start,started_at=clock_timestamp()
                WHERE installation_id=:installation AND playthrough_id=:playthrough')->execute($scope+['epoch'=>Uuid::v4(),'minute'=>$date['minute'],'start'=>$date['minute']]);
        }else $this->db->prepare('UPDATE lorkhan_internal.profile_evolution_clocks SET game_minute=GREATEST(game_minute,:minute)
            WHERE installation_id=:installation AND playthrough_id=:playthrough')->execute($scope+['minute'=>$date['minute']]);
    }

    /** Account a bounded batch once; late delivery acknowledgements remain eligible on later passes. */
    public function prepare(string $installation,string $profile,string $playthrough,array $identity,array $policy,bool $manual=false):array
    {
        $query=$this->db->prepare('SELECT * FROM lorkhan_internal.profile_evolution_clocks WHERE installation_id=:installation AND playthrough_id=:playthrough FOR SHARE');
        $query->execute(['installation'=>$installation,'playthrough'=>$playthrough]);$clock=$query->fetch();
        if(!$clock)return ['due'=>false,'reason'=>'game_clock_unavailable','observed'=>0];
        $scope=['profile'=>$profile,'playthrough'=>$playthrough];
        $this->db->prepare('INSERT INTO lorkhan_internal.profile_evolution_progress(profile_id,playthrough_id,epoch,last_game_minute)
            VALUES(:profile,:playthrough,:epoch,:minute) ON CONFLICT DO NOTHING')->execute($scope+['epoch'=>$clock['epoch'],'minute'=>$clock['started_minute']]);
        $query=$this->db->prepare('SELECT * FROM lorkhan_internal.profile_evolution_progress WHERE profile_id=:profile AND playthrough_id=:playthrough FOR UPDATE');
        $query->execute($scope);$progress=$query->fetch();
        if($progress['epoch']!==$clock['epoch']){
            $this->db->prepare('DELETE FROM lorkhan_internal.profile_evolution_events WHERE profile_id=:profile AND playthrough_id=:playthrough')->execute($scope);
            $this->db->prepare('UPDATE lorkhan_internal.profile_evolution_progress SET epoch=:epoch,last_game_minute=:minute,consumed_events=0,manual_requested=false,attempted_at=NULL
                WHERE profile_id=:profile AND playthrough_id=:playthrough')->execute($scope+['epoch'=>$clock['epoch'],'minute'=>$clock['started_minute']]);
            $progress['consumed_events']=0;$progress['last_game_minute']=$clock['started_minute'];$progress['attempted_at']=null;$progress['manual_requested']=false;
        }
        $stable=array_intersect_key($identity,array_fill_keys(['kind','record_id','content_file','refnum'],true));
        $query=$this->db->prepare("INSERT INTO lorkhan_internal.profile_evolution_events(profile_id,playthrough_id,epoch,rowid)
            SELECT :profile,:playthrough,:epoch,e.rowid FROM eventlog e JOIN eventlog_metadata m USING(rowid)
            WHERE m.installation_id=:installation AND m.playthrough_id=:scope_playthrough AND m.suppressed_at IS NULL AND m.created_at>=:started
            AND (e.utterance_id IS NULL OR e.delivery_state IN ('spoken','played'))
            AND e.type IN ('inputtext','chat','chat_background','location','weather','death','infoaction','narration','quest','book','spellcast','npcspellcast','itemfound')
            AND NOT EXISTS (SELECT 1 FROM source_events se WHERE se.turn_id=m.turn_id AND se.event_kind='turn.requested'
                AND se.payload#>>'{payload,ui_source}'='lorkhan_auto_combat_bark')
            AND (:narrator=1 OR m.speaker @> CAST(:identity AS jsonb) OR m.target @> CAST(:target AS jsonb)
                OR EXISTS(SELECT 1 FROM jsonb_array_elements(m.audience) a WHERE a @> CAST(:audience AS jsonb)))
            AND NOT EXISTS(SELECT 1 FROM lorkhan_internal.profile_evolution_events counted WHERE counted.profile_id=:count_profile
                AND counted.playthrough_id=:count_playthrough AND counted.epoch=:count_epoch AND counted.rowid=e.rowid)
            ORDER BY e.rowid LIMIT 200 ON CONFLICT DO NOTHING");
        $encoded=json_encode($stable,JSON_THROW_ON_ERROR);
        $query->execute($scope+['epoch'=>$clock['epoch'],'installation'=>$installation,'scope_playthrough'=>$playthrough,'started'=>$clock['started_at'],
            'narrator'=>($identity['kind']??null)==='narrator'?1:0,'identity'=>$encoded,'target'=>$encoded,'audience'=>$encoded,
            'count_profile'=>$profile,'count_playthrough'=>$playthrough,'count_epoch'=>$clock['epoch']]);
        $query=$this->db->prepare('SELECT count(*) FROM lorkhan_internal.profile_evolution_events WHERE profile_id=:profile AND playthrough_id=:playthrough AND epoch=:epoch');
        $query->execute($scope+['epoch'=>$clock['epoch']]);$total=(int)$query->fetchColumn();$observed=max(0,$total-(int)$progress['consumed_events']);
        $this->db->prepare('UPDATE lorkhan_internal.profile_evolution_progress SET checked_at=clock_timestamp() WHERE profile_id=:profile AND playthrough_id=:playthrough')->execute($scope);
        $manual=$manual||$progress['manual_requested']===true||$progress['manual_requested']==='t';
        $due=$manual||($clock['game_minute']-$progress['last_game_minute']>=($policy['interval_days']??1)*1440
            &&$observed>=($policy['min_events']??30)&&($progress['attempted_at']===null||time()-strtotime($progress['attempted_at'])>=($policy['cooldown_minutes']??5)*60));
        return ['due'=>$due,'reason'=>'interval','observed'=>$observed,'epoch'=>$clock['epoch'],'game_minute'=>(int)$clock['game_minute'],'total'=>$total,'manual'=>$manual];
    }

    public function attempted(string $profile,string $playthrough):void
    {
        $this->db->prepare('UPDATE lorkhan_internal.profile_evolution_progress SET attempted_at=clock_timestamp(),manual_requested=false WHERE profile_id=:profile AND playthrough_id=:playthrough')
            ->execute(['profile'=>$profile,'playthrough'=>$playthrough]);
    }

    public function active(array $payload):bool
    {
        $profile=(new ProductRepository($this->db))->getRevisioned('profile',$payload['profile_id']);
        (new Repository($this->db))->assertAiEnabled((string)$profile['installation_id']);
        if(isset($payload['playthrough_id'])){
            $owner=$this->db->prepare('SELECT 1 FROM profiles p WHERE p.profile_id=:profile AND '
                .ProfileScopeSql::matches('p',':playthrough',true)
                ." AND (NOT EXISTS(SELECT 1 FROM character_playthrough_bindings b WHERE b.installation_id=p.installation_id) "
                ."OR EXISTS(SELECT 1 FROM sessions s WHERE s.installation_id=p.installation_id "
                ."AND s.playthrough_id=:playthrough AND s.state='active'))");
            $owner->execute(['profile'=>$payload['profile_id'],'playthrough'=>$payload['playthrough_id']]);
            if(!$owner->fetchColumn())return false;
        }
        if(!isset($payload['evolution_schedule']))return true;
        $query=$this->db->prepare('SELECT 1 FROM lorkhan_internal.profile_evolution_progress p JOIN profiles profile USING(profile_id)
            JOIN lorkhan_internal.profile_evolution_clocks c ON c.installation_id=profile.installation_id AND c.playthrough_id=p.playthrough_id
            WHERE p.profile_id=:profile AND p.playthrough_id=:playthrough AND c.epoch=:epoch AND p.epoch=c.epoch');
        $query->execute(['profile'=>$payload['profile_id'],'playthrough'=>$payload['playthrough_id'],'epoch'=>$payload['evolution_schedule']['epoch']]);
        return(bool)$query->fetchColumn();
    }

    /** Consume only the frozen successful batch; events received while generating stay pending. */
    public function complete(array $payload):void
    {
        if(!isset($payload['evolution_schedule']))return;
        $schedule=$payload['evolution_schedule'];
        $this->db->prepare('UPDATE lorkhan_internal.profile_evolution_progress SET consumed_events=:total,last_game_minute=:minute
            WHERE profile_id=:profile AND playthrough_id=:playthrough AND epoch=:epoch')->execute(['total'=>$schedule['total'],'minute'=>$schedule['game_minute'],
                'profile'=>$payload['profile_id'],'playthrough'=>$payload['playthrough_id'],'epoch'=>$schedule['epoch']]);
    }

    /** Rotate over every known eligible actor, including actors no longer nearby; enqueue at most one. */
    public function run():void
    {
        $query=$this->db->query("SELECT p.profile_id,s.playthrough_id,s.session_id,c.epoch,c.started_minute FROM profiles p
            JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision
            JOIN sessions s ON s.installation_id=p.installation_id AND s.state='active'
            JOIN lorkhan_internal.profile_evolution_clocks c ON c.installation_id=p.installation_id AND c.playthrough_id=s.playthrough_id
            LEFT JOIN lorkhan_internal.profile_evolution_progress progress ON progress.profile_id=p.profile_id AND progress.playthrough_id=s.playthrough_id
            WHERE p.deleted_at IS NULL AND ".ProfileScopeSql::matches('p','s.playthrough_id',true)." AND r.content->>'dynamic_profile'='true' AND COALESCE(r.content#>>'{management,locked}','false')<>'true'
            AND (p.actor_identity->>'kind'='narrator' OR EXISTS(SELECT 1 FROM actor_profile_bindings b WHERE b.profile_id=p.profile_id AND b.playthrough_id=s.playthrough_id))
            ORDER BY progress.manual_requested DESC NULLS LAST,progress.checked_at ASC NULLS FIRST,p.profile_id LIMIT 32");
        $repository=new ProductRepository($this->db);
        foreach($query->fetchAll()as$row){
            $this->db->prepare('INSERT INTO lorkhan_internal.profile_evolution_progress(profile_id,playthrough_id,epoch,last_game_minute,checked_at)
                VALUES(:profile,:playthrough,:epoch,:minute,clock_timestamp()) ON CONFLICT(profile_id,playthrough_id) DO UPDATE SET checked_at=clock_timestamp()')
                ->execute(['profile'=>$row['profile_id'],'playthrough'=>$row['playthrough_id'],'epoch'=>$row['epoch'],'minute'=>$row['started_minute']]);
            if(($repository->maybeEnqueueDynamicProfileEvolution($row['profile_id'],$row['playthrough_id'],$row['session_id'])['queued']??false)===true)break;
        }
    }
}
