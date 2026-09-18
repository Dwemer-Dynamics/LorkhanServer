<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;
use LorkhanServer\Application\MorrowindCalendar;

/** Local gameplay saves use the scoped archive graph, never a full database restore. */
final class PlaythroughSaveRepository
{
    public function __construct(private readonly PDO $db) {}

    public function capture(string $installation, string $playthrough, string $name, string $notes = '', string $kind = 'manual', ?string $key = null): string
    {
        $metadata=ManagementRepository::snapshotMetadata(['name'=>$name,'notes'=>$notes]);
        if (!in_array($kind,['manual','default','dragon_break','before_copy'],true)) throw new RuntimeException('invalid_snapshot');
        $owns=!$this->db->inTransaction();
        if ($owns) $this->db->exec('BEGIN ISOLATION LEVEL REPEATABLE READ');
        try {
            $lock=$this->db->prepare('SELECT pg_try_advisory_xact_lock(hashtext(:scope))');
            $lock->execute(['scope'=>'playthrough-save:'.$installation]);
            if (!filter_var($lock->fetchColumn(),FILTER_VALIDATE_BOOL)) throw new RuntimeException('maintenance_busy');
            if ($key!==null) {
                $q=$this->db->prepare('SELECT save_id FROM playthrough_saves WHERE installation_id=:installation AND capture_key=:key');
                $q->execute(['installation'=>$installation,'key'=>$key]);
                if ($id=$q->fetchColumn()) { if ($owns) $this->db->commit(); return $id; }
            }
            $document=(new PlaythroughArchive($this->db,true))->export($installation,$playthrough);
            $json=json_encode($document,JSON_THROW_ON_ERROR);
            $id=Uuid::v4();
            $q=$this->db->prepare('INSERT INTO playthrough_saves(save_id,installation_id,playthrough_id,name,notes,kind,capture_key,document,metadata) VALUES(:id,:installation,:world,:name,:notes,:kind,:key,:document,CAST(:metadata AS jsonb))');
            $game=['events'=>count($document['tables']['public.eventlog']),'knowledge'=>count($document['tables']['lorkhan_internal.knowledge_documents'])];
            $latest=null;
            foreach ($document['tables']['lorkhan_internal.turns'] as $turn) {
                $context=(array)$turn['context']; $calendar=(array)($context['world']??[]);
                $calendar=MorrowindCalendar::parse(isset($calendar['calendar'])?(array)$calendar['calendar']:null);
                if ($calendar!==null && ($latest===null || $calendar['minute']>$latest['minute'])) $latest=$calendar;
            }
            $game['calendar']=$latest;
            $q->execute(['id'=>$id,'installation'=>$installation,'world'=>$playthrough,'name'=>$metadata['name'],'notes'=>$metadata['notes'],'kind'=>$kind,'key'=>$key,'document'=>$json,'metadata'=>json_encode($game,JSON_THROW_ON_ERROR)]);
            if ($owns) $this->db->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /** Capture current progress, prepare an inert copy, and apply it on the character's next load. */
    public function restore(string $installation, string $playthrough, string $id): array
    {
        $this->db->exec('BEGIN ISOLATION LEVEL REPEATABLE READ');
        try {
            $q=$this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:id FOR UPDATE');
            $q->execute(['id'=>$installation]);
            if (!$q->fetchColumn()) throw new RuntimeException('not_found');
            $q=$this->db->prepare('SELECT * FROM playthrough_saves WHERE installation_id=:installation AND save_id=:id');
            $q->execute(['installation'=>$installation,'id'=>$id]); $save=$q->fetch();
            if (!$save) throw new RuntimeException('not_found');
            $q=$this->db->prepare('SELECT character_id FROM character_playthrough_bindings WHERE installation_id=:installation AND playthrough_id=:world');
            $q->execute(['installation'=>$installation,'world'=>$playthrough]); $character=$q->fetchColumn();
            if (!$character) throw new RuntimeException('character_binding_conflict');
            $q=$this->db->prepare("SELECT 1 FROM playthrough_associations WHERE installation_id=:installation AND character_id=:character AND state='pending'");
            $q->execute(['installation'=>$installation,'character'=>$character]);
            if ($q->fetchColumn()) throw new RuntimeException('association_conflict');
            $this->capture($installation,$playthrough,'Before copy · '.gmdate('Y-m-d H:i:s'),'Before loading '.$save['name'],'before_copy');
            $copy=(new PlaythroughArchive($this->db,true))->importCopy($installation,$save['document']);
            $association=(new CharacterPlaythroughRepository($this->db))->queueAssociation($installation,$character,$playthrough,$copy['playthrough_id']);
            $this->db->commit();
            return $copy+['association'=>$association];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function delete(string $installation,string $id): void
    {
        $q=$this->db->prepare("DELETE FROM playthrough_saves WHERE installation_id=:installation AND save_id=:id AND kind<>'default'");
        $q->execute(['installation'=>$installation,'id'=>$id]);
        if ($q->rowCount()!==1) throw new RuntimeException('snapshot_protected_or_missing');
    }
}
