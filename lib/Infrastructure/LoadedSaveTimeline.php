<?php

declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\MorrowindCalendar;
use PDO;
use RuntimeException;

/** Retire the abandoned branch without deleting immutable transport or turn evidence. */
final class LoadedSaveTimeline
{
    public function __construct(private readonly PDO $db) {}

    /** Avoid starting provider work from a frozen context that has already been retired. */
    public function sourcesActive(array $turnIds): bool
    {
        if ($turnIds===[]) return true;
        $query=$this->db->prepare("SELECT 1 FROM timeline_invalidated_turns i
            WHERE CAST(:sources AS jsonb) @> jsonb_build_array(i.turn_id::text) LIMIT 1");
        $query->execute(['sources'=>json_encode(array_values($turnIds),JSON_THROW_ON_ERROR)]);
        return $query->fetchColumn()===false;
    }

    /** Provenance is usable only when every unique source belongs to this installation and playthrough. */
    public function sourcesBelongTo(array $turnIds,string $installation,string $playthrough): bool
    {
        if(!array_is_list($turnIds)||$turnIds===[]||count($turnIds)>400)return false;
        foreach($turnIds as$id)if(!is_string($id)||!Uuid::isValid($id))return false;
        if(count(array_unique($turnIds))!==count($turnIds))return false;
        $query=$this->db->prepare('SELECT count(*) FROM turns t JOIN sessions s ON s.session_id=t.session_id
            WHERE s.installation_id=:installation AND s.playthrough_id=:playthrough
            AND CAST(:sources AS jsonb) @> jsonb_build_array(t.turn_id::text)');
        $query->execute(['installation'=>$installation,'playthrough'=>$playthrough,'sources'=>json_encode($turnIds,JSON_THROW_ON_ERROR)]);
        return(int)$query->fetchColumn()===count($turnIds);
    }

    /** The caller must hold the accepted-session transaction and persist its session.init source first. */
    public function invalidate(array $message): array
    {
        if (!$this->db->inTransaction()) throw new RuntimeException('timeline_transaction_required');
        $cutoff=MorrowindCalendar::parse($message['loaded_save']??null);
        $cutoffSecond=self::calendarSecond($message['loaded_save']??null);
        $scope=['installation'=>$message['installation_id'],'playthrough'=>$message['playthrough_id']];
        $lock=$this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:installation FOR UPDATE');
        $lock->execute(['installation'=>$scope['installation']]);
        $load=$this->db->prepare("SELECT e.session_id FROM source_events e JOIN sessions s ON s.session_id=e.session_id
            WHERE e.source_event_id=:load AND e.event_kind='session.init' AND e.installation_id=:installation AND s.playthrough_id=:playthrough");
        $load->execute($scope+['load'=>$message['message_id']]);
        $loadSession=$load->fetchColumn();if (!$loadSession) throw new RuntimeException('timeline_load_source_required');
        $parameters=['load'=>$message['message_id'],'minute'=>$cutoff['minute']??0];
        $turns=$this->db->prepare("SELECT t.turn_id,t.context#>'{world,calendar}' AS calendar FROM turns t
            JOIN sessions s ON s.session_id=t.session_id WHERE s.installation_id=:installation AND s.playthrough_id=:playthrough
            AND NOT EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=t.turn_id)");
        $turns->execute($scope);
        $markTurn=$this->db->prepare('INSERT INTO timeline_invalidated_turns(turn_id,loaded_save_id,cutoff_minute) VALUES(:id,:load,:minute) ON CONFLICT DO NOTHING');
        $counts=['turns'=>0,'sources'=>0,'events'=>0,'memories'=>0,'narratives'=>0];
        while ($row=$turns->fetch()) {
            $second=self::calendarSecond($row['calendar']);
            if ($cutoffSecond===null || $second===null || $second<$cutoffSecond) continue;
            $markTurn->execute($parameters+['id'=>$row['turn_id']]);$counts['turns']+=$markTurn->rowCount();
        }
        $sources=$this->db->prepare("SELECT e.source_event_id,e.session_id,e.event_kind,
            COALESCE(e.payload#>'{context,world,calendar}',e.payload#>'{payload,context,world,calendar}',e.payload->'calendar',CASE WHEN e.event_kind IN ('gamedata.spell_cast','gamedata.item_pickup','gamedata.actor_resurrected') THEN e.payload#>'{payload,calendar}' END) AS calendar,
            EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=e.turn_id) AS invalid_turn
            FROM source_events e JOIN sessions s ON s.session_id=e.session_id
            WHERE e.installation_id=:installation AND s.playthrough_id=:playthrough AND e.source_event_id<>:load
            AND NOT EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=e.source_event_id)");
        $sources->execute($scope+['load'=>$message['message_id']]);
        $markSource=$this->db->prepare('INSERT INTO timeline_invalidated_sources(source_event_id,loaded_save_id,cutoff_minute) VALUES(:id,:load,:minute) ON CONFLICT DO NOTHING');
        while ($row=$sources->fetch()) {
            $second=self::calendarSecond($row['calendar']);
            // Undated observations cannot be placed on a loaded branch. With no load date, no older observation is safe.
            $unanchoredObservation=in_array($row['event_kind'],['gamedata.spell_cast','gamedata.item_pickup','gamedata.actor_resurrected'],true)
                &&$row['session_id']!==$loadSession&&($second===null||$cutoffSecond===null);
            if (!$row['invalid_turn']&&!$unanchoredObservation&&($cutoffSecond===null||$second===null||$second<$cutoffSecond)) continue;
            $markSource->execute($parameters+['id'=>$row['source_event_id']]);$counts['sources']+=$markSource->rowCount();
        }
        $events=$this->db->prepare("UPDATE eventlog_metadata m SET suppressed_at=clock_timestamp(),suppression_reason='loaded_save_rollback'
            WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL
            AND (EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=m.source_event_id)
                 OR EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=m.turn_id))");
        $events->execute($scope);$counts['events']=$events->rowCount();
        $memories=$this->db->prepare("UPDATE memory_records m SET deleted_at=clock_timestamp(),updated_at=clock_timestamp()
            WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough AND m.deleted_at IS NULL AND (
                EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=m.source_event_id)
                OR EXISTS(SELECT 1 FROM timeline_invalidated_sources i
                    WHERE COALESCE(m.provenance->'source_event_ids','[]'::jsonb) @> jsonb_build_array(i.source_event_id::text)))");
        $memories->execute($scope);$counts['memories']=$memories->rowCount();
        $narratives=$this->db->prepare("UPDATE narrative_records n SET deleted_at=clock_timestamp(),updated_at=clock_timestamp()
            WHERE n.installation_id=:installation AND n.playthrough_id=:playthrough AND n.deleted_at IS NULL
            AND EXISTS(SELECT 1 FROM timeline_invalidated_turns i
                WHERE COALESCE(n.provenance->'source_turn_ids','[]'::jsonb) @> jsonb_build_array(i.turn_id::text))");
        $narratives->execute($scope);$counts['narratives']=$narratives->rowCount();

        foreach (['speech'=>['speech_metadata','turn_id'],'books'=>['book_metadata','source_turn_id'],
            'currentmission'=>['currentmission_metadata','source_turn_id'],'questlog'=>['questlog_metadata','source_turn_id'],
            'quests'=>['quest_metadata','source_turn_id']] as $table=>[$metadata,$turnColumn]) {
            $projection=$this->db->prepare("DELETE FROM public.$table p USING $metadata m,timeline_invalidated_turns i
                WHERE p.rowid=m.rowid AND m.$turnColumn=i.turn_id AND m.installation_id=:installation AND m.playthrough_id=:playthrough");
            $projection->execute($scope);$counts[$table]=$projection->rowCount();
            $orphan=$this->db->prepare("DELETE FROM $metadata m USING timeline_invalidated_turns i WHERE m.$turnColumn=i.turn_id
                AND m.installation_id=:installation AND m.playthrough_id=:playthrough");$orphan->execute($scope);
        }
        $counts+=$this->restoreGeneratedProfiles($scope,$message['message_id']);
        $counts+=(new RelationshipTimelineRepository($this->db))->restore($scope,$message['message_id']);
        return $counts;
    }

    /** Append a safe baseline revision; manual, unknown and other-playthrough ancestry are boundaries. */
    private function restoreGeneratedProfiles(array $scope,string $loadId): array
    {
        $counts=['profiles'=>0,'profile_restore_skipped'=>0];
        $profiles=$this->db->prepare("SELECT p.profile_id,p.current_revision,r.content,r.provenance FROM profiles p
            JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision
            WHERE p.installation_id=:installation AND p.deleted_at IS NULL
            AND p.actor_identity->>'kind' IN ('npc','creature')
            AND COALESCE(r.content#>>'{management,locked}','false')<>'true'
            AND r.provenance->>'playthrough_id'=:playthrough
            AND r.provenance->>'kind' IN ('automatic_profile','loaded_save_restore') FOR UPDATE OF p");
        $profiles->execute($scope);
        $revision=$this->db->prepare('SELECT content,provenance FROM profile_revisions WHERE profile_id=:profile AND revision=:revision');
        $insert=$this->db->prepare("INSERT INTO profile_revisions(profile_id,revision,content,change_reason,provenance)
            VALUES(:profile,:revision,CAST(:content AS jsonb),'loaded save automatic profile restore',CAST(:provenance AS jsonb))");
        $update=$this->db->prepare('UPDATE profiles SET current_revision=:revision WHERE profile_id=:profile');
        foreach($profiles->fetchAll()as$profile){
            $current=(int)$profile['current_revision'];$cursor=$current;$node=$profile;$restore=null;$complete=false;
            for($depth=0;$depth<256;$depth++){
                $provenance=json_decode($node['provenance'],true,32,JSON_THROW_ON_ERROR);
                if(($provenance['playthrough_id']??null)!==$scope['playthrough']){$complete=true;break;}
                $kind=$provenance['kind']??null;
                if($kind==='automatic_profile'){
                    $sources=$provenance['source_turn_ids']??null;
                    if(!in_array($provenance['mode']??null,['npc_profile_backfill','profile_evolution'],true)
                        ||!is_array($sources)||!$this->sourcesBelongTo($sources,$scope['installation'],$scope['playthrough'])){$complete=true;break;}
                    $next=$provenance['base_revision']??null;
                    if(!is_int($next)||$next<1||$next>=$cursor)break;
                    if(!$this->sourcesActive($sources))$restore=$next;
                }elseif($kind==='loaded_save_restore'){
                    $next=$provenance['restored_revision']??null;
                    if(!is_int($next)||$next<1||$next>=$cursor)break;
                }else{$complete=true;break;}
                $revision->execute(['profile'=>$profile['profile_id'],'revision'=>$next]);$node=$revision->fetch();
                if(!$node)break;$cursor=$next;
            }
            if(!$complete){$counts['profile_restore_skipped']++;continue;}
            if($restore===null)continue;
            $revision->execute(['profile'=>$profile['profile_id'],'revision'=>$restore]);$baseline=$revision->fetch();
            if(!$baseline){$counts['profile_restore_skipped']++;continue;}
            $identity=['profile'=>$profile['profile_id'],'revision'=>$current+1];
            $insert->execute($identity+['content'=>$baseline['content'],'provenance'=>json_encode([
                'kind'=>'loaded_save_restore','playthrough_id'=>$scope['playthrough'],
                'restored_revision'=>$restore,'loaded_save_id'=>$loadId],JSON_THROW_ON_ERROR)]);
            $update->execute($identity);$counts['profiles']++;
        }
        return$counts;
    }

    /** Preserve fractional GameHour precision; UI minute formatting must not retire earlier seconds. */
    private static function calendarSecond(mixed $calendar): ?float
    {
        $calendar=is_string($calendar)?json_decode($calendar,true,16):$calendar;
        $date=MorrowindCalendar::parse($calendar);
        if ($date===null || $date['time']==='') return null;
        if (isset($calendar['hour']) && is_numeric($calendar['hour']) && $calendar['hour']>=0 && $calendar['hour']<24)
            return floor($date['minute']/1440)*86400+(float)$calendar['hour']*3600;
        return (float)$date['minute']*60;
    }

}
