<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\MorrowindCalendar;
use PDO;
use RuntimeException;

/** Capture committed gameplay before a validated loaded-save session replaces it. */
final class DragonBreakSnapshot
{
    public function __construct(private readonly array $config) {}

    public function capture(array $message):bool
    {
        $incoming=MorrowindCalendar::parse($message['loaded_save']??null);
        if($incoming===null)return true;
        $db=null;$locked=false;$id=null;
        try{
            // Keep a valid backup even if the surrounding session transaction subsequently rolls back.
            $db=Connection::open($this->config,false);
            $previous=$db->prepare("WITH observations AS (SELECT t.context#>'{world,calendar}' AS calendar,t.accepted_at AS observed_at,t.turn_id AS id,t.session_id FROM turns t WHERE t.context#>'{world,calendar}' IS NOT NULL UNION ALL SELECT e.payload->'loaded_save',e.received_at,e.source_event_id,e.session_id FROM source_events e WHERE e.event_kind='session.init' AND jsonb_exists(e.payload,'loaded_save')) SELECT o.calendar FROM observations o JOIN sessions s ON s.session_id=o.session_id WHERE s.installation_id=:installation AND s.playthrough_id=:playthrough AND s.profile_id=:profile ORDER BY o.observed_at DESC,o.id DESC LIMIT 1");
            $previous->execute(['installation'=>$message['installation_id'],'playthrough'=>$message['playthrough_id'],'profile'=>$message['profile_id']]);
            $old=MorrowindCalendar::parse($previous->fetchColumn());
            $settings=(new ProductRepository($db))->globalSettingsForInstallation($message['installation_id']);
            $days=$settings['content']['backup']['dragon_break_days']??3;
            if(!is_int($days)||$days<1||$days>3650)$days=3;
            if($old===null||$old['minute']-$incoming['minute']<$days*1440)return true;
            $locked=filter_var($db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL);
            if(!$locked)throw new RuntimeException('maintenance_busy');
            $name='Dragon Break ('.$old['date'].' -> '.$incoming['date'].')';
            // Request identity, not just dates: revisiting the same dates after new progress needs a new save.
            $id=(new PlaythroughSaveRepository($db))->capture($message['installation_id'],$message['playthrough_id'],$name,
                'Automatic save before loading an older game save.','dragon_break','load:'.$message['message_id']);
            $this->audit($db,'dragon_break_saved',$message,['backup_id'=>$id]);
            return true;
        }catch(\Throwable $error){
            $reason=preg_match('/^[a-z_]{1,80}$/D',$error->getMessage())?$error->getMessage():'snapshot_failed';
            error_log('Lorkhan Dragon Break snapshot failed: '.$reason);
            if($db!==null)try{$this->audit($db,'dragon_break_failed',$message,['reason'=>$reason,'backup_id'=>$id]);}catch(\Throwable){}
            return false;
        }finally{if($db!==null&&$locked)$db->query('SELECT pg_advisory_unlock(7514,113)');}
    }

    /** Retain identifier-only capture outcomes without exposing archive paths or connection secrets. */
    private function audit(PDO $db,string $action,array $message,array $detail):void
    {
        $query=$db->prepare("INSERT INTO operational_audit(audit_id,category,action,scope,detail) VALUES(:id,'playthrough',:action,CAST(:scope AS jsonb),CAST(:detail AS jsonb))");
        $query->execute(['id'=>Uuid::v4(),'action'=>$action,'scope'=>json_encode(['installation_id'=>$message['installation_id'],'playthrough_id'=>$message['playthrough_id'],'request_id'=>$message['message_id']],JSON_THROW_ON_ERROR),'detail'=>json_encode($detail,JSON_THROW_ON_ERROR)]);
    }
}
