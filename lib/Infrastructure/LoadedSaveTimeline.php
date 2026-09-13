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

    /** The caller must hold the accepted-session transaction and persist its session.init source first. */
    public function invalidate(array $message): array
    {
        if (!$this->db->inTransaction()) throw new RuntimeException('timeline_transaction_required');
        $cutoff=MorrowindCalendar::parse($message['loaded_save']??null);
        $cutoffSecond=self::calendarSecond($message['loaded_save']??null);
        if ($cutoff===null || $cutoffSecond===null) return [];
        $scope=['installation'=>$message['installation_id'],'playthrough'=>$message['playthrough_id']];
        $lock=$this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:installation FOR UPDATE');
        $lock->execute(['installation'=>$scope['installation']]);
        $load=$this->db->prepare("SELECT 1 FROM source_events e JOIN sessions s ON s.session_id=e.session_id
            WHERE e.source_event_id=:load AND e.event_kind='session.init' AND e.installation_id=:installation AND s.playthrough_id=:playthrough");
        $load->execute($scope+['load'=>$message['message_id']]);
        if (!$load->fetchColumn()) throw new RuntimeException('timeline_load_source_required');
        $parameters=['load'=>$message['message_id'],'minute'=>$cutoff['minute']];
        $turns=$this->db->prepare("SELECT t.turn_id,t.context#>'{world,calendar}' AS calendar FROM turns t
            JOIN sessions s ON s.session_id=t.session_id WHERE s.installation_id=:installation AND s.playthrough_id=:playthrough
            AND NOT EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=t.turn_id)");
        $turns->execute($scope);
        $markTurn=$this->db->prepare('INSERT INTO timeline_invalidated_turns(turn_id,loaded_save_id,cutoff_minute) VALUES(:id,:load,:minute) ON CONFLICT DO NOTHING');
        $counts=['turns'=>0,'sources'=>0,'events'=>0,'memories'=>0,'narratives'=>0];
        while ($row=$turns->fetch()) {
            $second=self::calendarSecond($row['calendar']);
            if ($second===null || $second<$cutoffSecond) continue;
            $markTurn->execute($parameters+['id'=>$row['turn_id']]);$counts['turns']+=$markTurn->rowCount();
        }
        $sources=$this->db->prepare("SELECT e.source_event_id,
            COALESCE(e.payload#>'{context,world,calendar}',e.payload#>'{payload,context,world,calendar}',e.payload->'calendar') AS calendar,
            EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=e.turn_id) AS invalid_turn
            FROM source_events e JOIN sessions s ON s.session_id=e.session_id
            WHERE e.installation_id=:installation AND s.playthrough_id=:playthrough AND e.source_event_id<>:load
            AND NOT EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=e.source_event_id)");
        $sources->execute($scope+['load'=>$message['message_id']]);
        $markSource=$this->db->prepare('INSERT INTO timeline_invalidated_sources(source_event_id,loaded_save_id,cutoff_minute) VALUES(:id,:load,:minute) ON CONFLICT DO NOTHING');
        while ($row=$sources->fetch()) {
            $second=self::calendarSecond($row['calendar']);
            if (!$row['invalid_turn'] && ($second===null || $second<$cutoffSecond)) continue;
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
        return $counts;
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
