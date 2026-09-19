<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Http\Request;
use LorkhanServer\Security\BrowserSession;
use LorkhanServer\Security\RequestMac;
use PDO;
use RuntimeException;

final class ManagementRepository
{
    // Fixed r/m/t/s aliases shared by the queue reader and its guarded presentation-only deletion.
    public const RESPONSE_QUEUE_REMOVABLE = "r.sent=1 AND (s.state IN ('ended','replaced') OR
        (t.state IN ('complete','failed','cancelled')
        AND NOT EXISTS (SELECT 1 FROM dialogue_utterances pending WHERE pending.turn_id=m.turn_id AND pending.delivery_state='pending')
        AND NOT EXISTS (SELECT 1 FROM action_intents pending WHERE pending.turn_id=m.turn_id AND pending.state<>'terminal')))";

    public function __construct(private readonly PDO $db) {}

    public function playthroughSaves(): PlaythroughSaveRepository
    {return new PlaythroughSaveRepository($this->db);}

    public function playthroughTablePolicy():array
    {return PlaythroughTablePolicy::inventory($this->db);}

    /** Keep archive handlers on the authenticated management connection. */
    public function exportPlaythroughArchive(string $installation,string $playthrough):array
    {return (new PlaythroughArchive($this->db))->export($installation,$playthrough);}
    public function inspectPlaythroughArchive(string $json):array
    {return (new PlaythroughArchive($this->db))->inspect($json);}
    public function importPlaythroughArchive(string $installation,string $json):array
    {return (new PlaythroughArchive($this->db))->importCopy($installation,$json);}
    /** Keep scoped management mutations behind the same guarded character repository. */
    public function managePlaythrough(string $operation,string $installation,?string $playthrough,string $name,int $revision):array
    {
        $characters=new CharacterPlaythroughRepository($this->db);
        if($operation==='create')return $characters->createEmpty($installation,$name);
        if($operation==='rename')return $characters->renamePlaythrough($installation,(string)$playthrough,$name,$revision);
        if($operation==='copy')return $characters->copyPlaythrough($installation,(string)$playthrough);
        if($operation==='delete'){$characters->deletePlaythrough($installation,(string)$playthrough,$revision);return ['deleted'=>true];}
        throw new \InvalidArgumentException('invalid_playthrough_operation');
    }

    /** Queue a saved-character reassignment without changing its running session. */
    public function queuePlaythroughAssociation(string $installation,string $character,string $expected,string $target):array
    {return (new CharacterPlaythroughRepository($this->db))->queueAssociation($installation,$character,$expected,$target);}
    public function cancelPlaythroughAssociation(string $installation,string $association):void
    {(new CharacterPlaythroughRepository($this->db))->cancelAssociation($installation,$association);}

    public function previewBackupFileRetention(int $days,string $cutoff):array
    {return (new BackupFileRetention($this->db))->preview($days,$cutoff);}
    public function confirmBackupFileRetention(int $days,string $cutoff,string $token,array $config):array
    {return (new BackupFileRetention($this->db))->confirm($days,$cutoff,$token,$config);}


    /** Queue one database-wide maintenance request; repeated submissions reuse pending work. */
    public function queueDatabaseMaintenance(): string
    {
        if (!filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(), FILTER_VALIDATE_BOOL))
            throw new RuntimeException('maintenance_busy');
        try {
            $this->assertNoPendingReplay();
            $existing=$this->db->query("SELECT job_id FROM durable_jobs WHERE job_type='database.compact' AND state IN ('queued','leased') ORDER BY created_at LIMIT 1")->fetchColumn();
            if (is_string($existing)) return $existing;
            $id=Uuid::v4();
            (new JobRepository($this->db))->enqueue($id,'database.compact',1,$id,['operation'=>'compact'],1);
            return $id;
        } finally { $this->db->query('SELECT pg_advisory_unlock(7514,113)'); }
    }

    /** Queue one full SQL snapshot; a pending snapshot is shared by repeated clicks. */
    public function queueDatabaseBackup(bool $automatic = false,?array $snapshot = null): ?string
    {
        if($snapshot!==null){$snapshot=self::snapshotMetadata($snapshot);if($automatic)throw new \InvalidArgumentException('invalid_snapshot');}
        if (!filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL)) throw new RuntimeException('maintenance_busy');
        try {
            $this->assertNoPendingReplay();
            if($snapshot!==null){
                $duplicate=$this->db->prepare("SELECT 1 FROM backup_records WHERE scope->>'kind'='database_sql' AND scope#>>'{snapshot,name}'=:name LIMIT 1");
                $duplicate->execute(['name'=>$snapshot['name']]);
                if($duplicate->fetchColumn()!==false)throw new RuntimeException('snapshot_name_exists');
            }
            if($automatic){
                $due=$this->db->query("SELECT enabled AND (last_queued_at IS NULL OR last_queued_at<=clock_timestamp()-interval '10 minutes') FROM lorkhan_internal.database_backup_settings WHERE singleton")->fetchColumn();
                if(!filter_var($due,FILTER_VALIDATE_BOOL))return null;
            }
            $existing=$this->db->query("SELECT job_id,payload FROM durable_jobs WHERE job_type='database.backup' AND state IN ('queued','leased') ORDER BY created_at LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if($existing){
                $pending=json_decode($existing['payload'],true,16,JSON_THROW_ON_ERROR);
                if($snapshot!==null&&($pending['snapshot']??null)!=$snapshot)throw new RuntimeException('maintenance_busy');
                return $existing['job_id'];
            }
            $id=Uuid::v4();
            $this->db->beginTransaction();
            try{
                (new JobRepository($this->db))->enqueue($id,'database.backup',1,$id,['backup_id'=>$id]+($automatic?['automatic'=>true]:[])+($snapshot!==null?['snapshot'=>$snapshot]:[]),1);
                if($automatic)$this->db->exec('UPDATE lorkhan_internal.database_backup_settings SET last_queued_at=clock_timestamp() WHERE singleton');
                $this->db->commit();
            }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
            return $id;
        } finally {$this->db->query('SELECT pg_advisory_unlock(7514,113)');}
    }

    /** Share strict snapshot metadata validation between browser ingress and the durable worker. */
    public static function snapshotMetadata(array $snapshot):array
    {
        if(array_diff(array_keys($snapshot),['name','notes'])!==[]||!is_string($snapshot['name']??null)||!is_string($snapshot['notes']??null))throw new \InvalidArgumentException('invalid_snapshot');
        $name=trim($snapshot['name']);$notes=trim($snapshot['notes']);
        if($name===''||strlen($name)>128||strlen($notes)>1024||!preg_match('//u',$name.$notes))throw new \InvalidArgumentException('invalid_snapshot');
        return ['name'=>$name,'notes'=>$notes];
    }

    public function snapshotSaveStatus():?array
    {
        $row=$this->db->query("SELECT job_id,state,created_at,updated_at FROM durable_jobs WHERE job_type='database.backup' AND jsonb_exists(payload,'snapshot') ORDER BY created_at DESC,job_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    /** Match the reference's first Playthrough Manager visit without running a dump inside the web request. */
    public function ensureInitialPlaythroughSnapshot():?string
    {
        if(!filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL))return null;
        try{
            if($this->db->query("SELECT 1 FROM backup_records WHERE scope->>'kind'='database_sql' AND jsonb_exists(scope,'snapshot') LIMIT 1")->fetchColumn()!==false)return null;
            return $this->queueDatabaseBackup(false,['name'=>'default','notes'=>'Auto-captured initial database snapshot']);
        }catch(RuntimeException $error){if($error->getMessage()==='maintenance_busy')return null;throw $error;}
        finally{$this->db->query('SELECT pg_advisory_unlock(7514,113)');}
    }

    public function databaseBackupSettings(): array
    {
        $row=$this->db->query('SELECT enabled,max_count FROM lorkhan_internal.database_backup_settings WHERE singleton')->fetch(PDO::FETCH_ASSOC);
        return ['enabled'=>filter_var($row['enabled'],FILTER_VALIDATE_BOOL),'max_count'=>(int)$row['max_count']];
    }

    /** Apply retention only after a successful automatic backup, never on a settings save. */
    public function saveDatabaseBackupSettings(?bool $enabled,?int $maxCount): void
    {
        if($maxCount!==null&&($maxCount<1||$maxCount>10))throw new \InvalidArgumentException('invalid_backup_retention');
        $query=$this->db->prepare('UPDATE lorkhan_internal.database_backup_settings SET enabled=COALESCE(CAST(:enabled AS boolean),enabled),max_count=COALESCE(CAST(:max AS integer),max_count) WHERE singleton');
        $query->execute(['enabled'=>$enabled===null?null:($enabled?'true':'false'),'max'=>$maxCount]);
    }

    /** Queue a confirmed upload using private storage and the shared maintenance lock. */
    public function queueDatabaseImport(string $id,string $source,array $config):string
    {
        return (new DatabaseImportStore($config))->enqueue($this->db,$id,$source);
    }

    /** Expose only the latest maintenance lifecycle, never worker payloads or database credentials. */
    public function databaseMaintenanceStatus(string $type = 'database.compact'): ?array
    {
        if(!in_array($type,['database.compact','database.backup','database.restore','database.replay','database.factory_reset','database.import'],true))throw new \InvalidArgumentException('invalid_database_job_type');
        $query=$this->db->prepare('SELECT job_id,state,created_at,updated_at FROM durable_jobs WHERE job_type=:type ORDER BY created_at DESC,job_id DESC LIMIT 1');
        $query->execute(['type'=>$type]);$row=$query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Delete an explicitly selected automatic backup or named snapshot, never a queued restore source. */
    public function deleteStoredDatabaseBackup(string $id,array $config,string $kind='automatic'):void
    {
        if(!in_array($kind,['automatic','snapshot'],true))throw new \InvalidArgumentException('invalid_backup_kind');
        $db=$this->db;$path=(new DatabaseSqlBackup($config))->path($id);
        if(!filter_var($db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL))throw new RuntimeException('maintenance_busy');
        try{
            $record=(new ProductRepository($db))->configurationBackupRecord($id);
            if(($record['scope']['kind']??'')!=='database_sql'||($kind==='automatic'?($record['scope']['automatic']??false)!==true:!isset($record['scope']['snapshot'])))throw new RuntimeException('not_found');
            if(strtolower($record['scope']['snapshot']['name']??'')==='default')throw new RuntimeException('default_snapshot_protected');
            $active=$db->prepare('SELECT 1 FROM lorkhan_internal.database_snapshot_source WHERE singleton AND backup_id=:id');
            $active->execute(['id'=>$id]);if($active->fetchColumn()!==false&&$kind==='snapshot')throw new RuntimeException('active_snapshot_protected');
            $pending=$db->prepare("SELECT 1 FROM durable_jobs WHERE job_type='database.restore' AND state IN ('queued','leased') AND payload->>'backup_id'=:id LIMIT 1");
            $pending->execute(['id'=>$id]);if($pending->fetchColumn()!==false)throw new RuntimeException('backup_restore_pending');
            if(is_link($path)||is_link($path.'.dump'))throw new RuntimeException('backup_integrity_failed');
            foreach([$path,$path.'.dump'] as $file)if(is_file($file)&&!unlink($file))throw new RuntimeException('backup_delete_failed');
            $delete=$db->prepare("DELETE FROM backup_records WHERE backup_id=:id AND scope->>'kind'='database_sql'");
            $delete->execute(['id'=>$id]);
        }finally{$db->query('SELECT pg_advisory_unlock(7514,113)');}
    }

    /** Never accept other maintenance work that a queued migration replay would invalidate. */
    private function assertNoPendingReplay():void
    {
        if($this->db->query("SELECT 1 FROM durable_jobs WHERE job_type IN ('database.replay','database.factory_reset','database.import') AND state IN ('queued','leased') LIMIT 1")->fetchColumn()!==false)throw new RuntimeException('maintenance_busy');
    }

    /** Return only confirmation metadata from a verified private factory artifact. */
    public function databaseFactoryPlan(array $config):array
    {
        $runner=new MigrationRunner($this->db,dirname(__DIR__,2).'/data/migrations');
        $fingerprint=hash('sha256',$runner->replayFingerprint(false)."\0".FactoryDatabaseArchive::catalogFingerprint());
        $factory=FactoryDatabaseArchive::load($this->db,(string)($config['factory_storage_path']??'/var/lib/lorkhanserver/factory/current'),$fingerprint);
        return ['fingerprint'=>$fingerprint,'migration_count'=>$factory['manifest']['migration_count']];
    }

    /** Queue one confirmed factory reset, refusing conflicting database maintenance. */
    public function queueDatabaseFactoryReset(string $fingerprint,array $config):string
    {
        if(preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1)throw new \InvalidArgumentException('invalid_factory_plan');
        if(!filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL))throw new RuntimeException('maintenance_busy');
        try{
            $plan=$this->databaseFactoryPlan($config);
            if(!hash_equals($plan['fingerprint'],$fingerprint))throw new RuntimeException('factory_source_changed');
            $pending=$this->db->query("SELECT job_id,job_type,payload FROM durable_jobs WHERE job_type IN ('database.compact','database.backup','database.restore','database.replay','database.factory_reset','database.import') AND state IN ('queued','leased') ORDER BY created_at LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if($pending){
                $payload=json_decode($pending['payload'],true,16,JSON_THROW_ON_ERROR);
                if($pending['job_type']==='database.factory_reset'&&($payload['fingerprint']??null)===$fingerprint)return $pending['job_id'];
                throw new RuntimeException('maintenance_busy');
            }
            $id=Uuid::v4();
            (new JobRepository($this->db))->enqueue($id,'database.factory_reset',1,$id,['fingerprint'=>$fingerprint,'rollback_id'=>Uuid::v4()],1);
            return $id;
        }finally{$this->db->query('SELECT pg_advisory_unlock(7514,113)');}
    }

    /** Read an existing migration ledger without attempting web-role schema changes. */
    public function databaseReplayPlan():array
    {
        $runner=new MigrationRunner($this->db,dirname(__DIR__,2).'/data/migrations');
        $status=$runner->status(false);
        return ['fingerprint'=>$runner->replayFingerprint(false),'versions'=>array_values(array_map(
            static fn(array $row):array=>['version'=>$row['version'],'name'=>$row['name']],
            array_filter($status,static fn(array $row):bool=>$row['applied'])))];
    }

    /** Queue exactly the confirmed source-owned replay once, with a separate rollback identity. */
    public function queueDatabaseReplay(int $version,string $fingerprint):string
    {
        if($version<1||preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1)throw new \InvalidArgumentException('invalid_replay_plan');
        if(!filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL))throw new RuntimeException('maintenance_busy');
        try{
            $plan=$this->databaseReplayPlan();
            if(!hash_equals($plan['fingerprint'],$fingerprint))throw new RuntimeException('replay_plan_changed');
            if(!in_array($version,array_column($plan['versions'],'version'),true))throw new \InvalidArgumentException('invalid_replay_version');
            $pending=$this->db->query("SELECT job_id,job_type,payload FROM durable_jobs WHERE job_type IN ('database.compact','database.backup','database.restore','database.replay','database.factory_reset','database.import') AND state IN ('queued','leased') ORDER BY created_at LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if($pending){
                $payload=json_decode($pending['payload'],true,16,JSON_THROW_ON_ERROR);
                if($pending['job_type']==='database.replay'&&($payload['version']??null)===$version&&($payload['fingerprint']??null)===$fingerprint)return $pending['job_id'];
                throw new RuntimeException('maintenance_busy');
            }
            $id=Uuid::v4();
            (new JobRepository($this->db))->enqueue($id,'database.replay',1,$id,['version'=>$version,'fingerprint'=>$fingerprint,'rollback_id'=>Uuid::v4()],1);
            return $id;
        }finally{$this->db->query('SELECT pg_advisory_unlock(7514,113)');}
    }

    /** An explicit stored-file restore is single-attempt and gets a separate manual rollback backup. */
    public function queueDatabaseRestore(string $backupId): string
    {
        if(!Uuid::isValid($backupId))throw new \InvalidArgumentException('invalid_backup_id');
        $record=(new ProductRepository($this->db))->configurationBackupRecord($backupId);
        if(($record['scope']['kind']??'')!=='database_sql')throw new RuntimeException('not_found');
        if(!isset($record['scope']['archive_sha256']))throw new RuntimeException('restore_archive_unavailable');
        if(!filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL))throw new RuntimeException('maintenance_busy');
        try{
            $this->assertNoPendingReplay();
            $pending=$this->db->query("SELECT job_id,payload->>'backup_id' AS backup_id FROM durable_jobs WHERE job_type='database.restore' AND state IN ('queued','leased') ORDER BY created_at LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if($pending){if($pending['backup_id']!==$backupId)throw new RuntimeException('maintenance_busy');return $pending['job_id'];}
            $id=Uuid::v4();(new JobRepository($this->db))->enqueue($id,'database.restore',1,$id,['backup_id'=>$backupId,'rollback_id'=>Uuid::v4()],1);
            return $id;
        }finally{$this->db->query('SELECT pg_advisory_unlock(7514,113)');}
    }

    /** Compact only this server's application tables, with a lock and time-bounded explicit request. */
    public function compactDatabase(bool $background = false): void
    {
        $locked=$this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn();
        if(!filter_var($locked,FILTER_VALIDATE_BOOL))throw new RuntimeException('maintenance_busy');
        $statementTimeout=(string)$this->db->query('SHOW statement_timeout')->fetchColumn();
        $lockTimeout=(string)$this->db->query('SHOW lock_timeout')->fetchColumn();
        try {
            $recent=$this->db->query("SELECT count(*) FROM operational_audit WHERE category='database_maintenance'
                AND action='started' AND created_at>now()-interval '1 minute'")->fetchColumn();
            if((int)$recent>0)throw new RuntimeException('maintenance_busy');
            $tables=$this->db->query("SELECT format('%I.%I',n.nspname,c.relname) AS name,
                pg_has_role(current_user,c.relowner,'USAGE') AS owned
                FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname IN ('public','lorkhan_internal') AND c.relkind IN ('r','m')
                ORDER BY n.nspname,c.relname")->fetchAll(PDO::FETCH_ASSOC);
            foreach($tables as$table)if(!filter_var($table['owned'],FILTER_VALIDATE_BOOL))
                throw new RuntimeException('maintenance_permission_required');
            if($tables===[])throw new RuntimeException('maintenance_no_tables');
            $this->audit('database_maintenance','started',[],['tables'=>count($tables)]);
            $this->db->exec($background ? "SET statement_timeout='1800s'; SET lock_timeout='3s'" : "SET statement_timeout='25s'; SET lock_timeout='3s'");
            // Names are quoted by PostgreSQL from its catalogue, never supplied by the browser.
            $this->db->exec('VACUUM (FULL, ANALYZE) '.implode(',',array_column($tables,'name')));
            $this->audit('database_maintenance','completed',[],['tables'=>count($tables)]);
        } catch(\PDOException $error) {
            $state=preg_match('/^[A-Z0-9]{5}$/D',(string)$error->getCode())?(string)$error->getCode():'unknown';
            error_log('Lorkhan database maintenance failed (SQLSTATE '.$state.').');
            $this->audit('database_maintenance','failed',[],['reason'=>'database_operation_failed','sqlstate'=>$state]);
            throw new RuntimeException('maintenance_failed',0,$error);
        } finally {
            try {
                $restore=$this->db->prepare("SELECT set_config('statement_timeout',:statement,false),set_config('lock_timeout',:lock,false)");
                $restore->execute(['statement'=>$statementTimeout,'lock'=>$lockTimeout]);
            } finally {
                $this->db->query('SELECT pg_advisory_unlock(7514,113)');
            }
        }
    }

    /** Remove a completed queue-log projection without cancelling or erasing native response events. */
    public function removeResponseQueueEntry(string $installation, int $rowid): int
    {
        if (!Uuid::isValid($installation) || $rowid<1) throw new \InvalidArgumentException('invalid_response_queue_scope');
        $this->db->beginTransaction();
        try {
            $query=$this->db->prepare('WITH eligible AS (
                SELECT r.rowid FROM public.responselog r JOIN lorkhan_internal.responselog_metadata m ON m.rowid=r.rowid
                    LEFT JOIN sessions s ON s.session_id=m.session_id LEFT JOIN turns t ON t.turn_id=m.turn_id
                WHERE m.installation_id=:installation AND r.rowid=:rowid AND '.self::RESPONSE_QUEUE_REMOVABLE.'
                ), removed AS (
                DELETE FROM lorkhan_internal.responselog_metadata m USING eligible WHERE m.rowid=eligible.rowid RETURNING m.rowid
                ) DELETE FROM public.responselog r USING removed WHERE r.rowid=removed.rowid');
            $query->execute(['installation'=>$installation,'rowid'=>$rowid]);
            $count=$query->rowCount();
            if ($count!==1) throw new RuntimeException('response_queue_entry_unavailable_or_pending');
            $this->audit('control','remove_response_queue_entry',['installation_id'=>$installation],['rowid'=>$rowid]);
            $this->db->commit();
            return $count;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function globalSettingsPresets(string $installation): array
    {
        if (!Uuid::isValid($installation)) throw new \InvalidArgumentException('invalid_installation_id');
        $query = $this->db->prepare('SELECT preset_id,name,revision,CASE WHEN payload->>\'schema\'=\'lorkhan.named-global-preset.v2\' THEN 1 ELSE 0 END AS profiles_included FROM lorkhan_internal.global_settings_presets WHERE installation_id=:installation ORDER BY lower(name),preset_id');
        $query->execute(['installation' => $installation]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function globalSettingsPreset(string $installation, string $id, ?int $revision = null): array
    {
        if (!Uuid::isValid($installation) || !Uuid::isValid($id)) throw new \InvalidArgumentException('invalid_global_settings_preset');
        $query = $this->db->prepare('SELECT payload,revision FROM lorkhan_internal.global_settings_presets WHERE installation_id=:installation AND preset_id=:id');
        $query->execute(['installation' => $installation, 'id' => $id]);
        $record = $query->fetch(PDO::FETCH_ASSOC);
        if ($record === false) throw new RuntimeException('not_found');
        if($revision!==null && (int)$record['revision']!==$revision)throw new RuntimeException('revision_conflict');
        return json_decode($record['payload'], true, 32, JSON_THROW_ON_ERROR);
    }

    /** Serialize catalogue writes per installation and reject stale overwrites without touching runtime settings. */
    public function saveGlobalSettingsPreset(string $installation, string $name, array $payload, ?string $id = null, int $revision = 0): string
    {
        $name = trim($name);
        if (!Uuid::isValid($installation) || ($id !== null && !Uuid::isValid($id))) throw new \InvalidArgumentException('invalid_global_settings_preset');
        if ($name === '' || strlen($name) > 128 || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $name)
            || in_array(mb_strtolower($name), ['default', 'local llm'], true)) throw new \InvalidArgumentException('invalid_preset_name');
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($json) > 200000) throw new \InvalidArgumentException('preset_too_large');
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:installation FOR UPDATE');
            $lock->execute(['installation' => $installation]);
            if (!$lock->fetchColumn()) throw new \InvalidArgumentException('invalid_installation_id');
            $existing = $this->globalSettingsPresets($installation);
            foreach ($existing as $row) if ($row['preset_id'] !== $id && mb_strtolower($row['name']) === mb_strtolower($name))
                throw new \InvalidArgumentException('preset_name_exists');
            if ($id === null) {
                if (count($existing) >= 64) throw new \InvalidArgumentException('preset_limit_reached');
                $id = Uuid::v4();
                $query = $this->db->prepare('INSERT INTO lorkhan_internal.global_settings_presets(preset_id,installation_id,name,payload) VALUES (:id,:installation,:name,CAST(:payload AS jsonb))');
                $query->execute(['id' => $id, 'installation' => $installation, 'name' => $name, 'payload' => $json]);
            } else {
                $query = $this->db->prepare('UPDATE lorkhan_internal.global_settings_presets SET name=:name,payload=CAST(:payload AS jsonb),revision=revision+1,updated_at=clock_timestamp() WHERE preset_id=:id AND installation_id=:installation AND revision=:revision');
                $query->execute(['id' => $id, 'installation' => $installation, 'name' => $name, 'payload' => $json, 'revision' => $revision]);
                if ($query->rowCount() !== 1) throw new RuntimeException('revision_conflict');
            }
            $this->audit('configuration', 'save_global_preset', ['installation_id' => $installation], ['preset_id' => $id]);
            $this->db->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function coreProfilePresets(string $installation): array
    {
        if (!Uuid::isValid($installation)) throw new \InvalidArgumentException('invalid_installation_id');
        $query = $this->db->prepare('SELECT preset_id,name,revision FROM lorkhan_internal.core_profile_presets WHERE installation_id=:installation ORDER BY lower(name),preset_id');
        $query->execute(['installation' => $installation]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function coreProfilePreset(string $installation, string $id): array
    {
        return $this->coreProfilePresetRecord($installation, $id)['payload'];
    }

    /** Read the payload and its revision together so an Apply cannot mix catalogue versions. */
    public function coreProfilePresetRecord(string $installation, string $id): array
    {
        if (!Uuid::isValid($installation) || !Uuid::isValid($id)) throw new \InvalidArgumentException('invalid_core_profile_preset');
        $query = $this->db->prepare('SELECT preset_id,name,revision,payload FROM lorkhan_internal.core_profile_presets WHERE installation_id=:installation AND preset_id=:id');
        $query->execute(['installation' => $installation, 'id' => $id]);
        $record = $query->fetch(PDO::FETCH_ASSOC);
        if ($record === false) throw new RuntimeException('not_found');
        $record['payload'] = \LorkhanServer\Application\CoreProfilePreset::validate(json_decode($record['payload'], true, 32, JSON_THROW_ON_ERROR));
        return $record;
    }

    /** Serialize catalogue writes per installation and reject stale overwrites without touching runtime settings. */
    public function saveCoreProfilePreset(string $installation, string $name, array $payload, ?string $id = null, int $revision = 0): string
    {
        $name = trim($name);
        if (!Uuid::isValid($installation) || ($id !== null && !Uuid::isValid($id))) throw new \InvalidArgumentException('invalid_core_profile_preset');
        if ($name === '' || strlen($name) > 128 || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $name)
            || in_array(mb_strtolower($name), ['default', 'local llm', 'follower', 'passive'], true)) throw new \InvalidArgumentException('invalid_preset_name');
        $payload = \LorkhanServer\Application\CoreProfilePreset::validate($payload);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($json) > 200000) throw new \InvalidArgumentException('preset_too_large');
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:installation FOR UPDATE');
            $lock->execute(['installation' => $installation]);
            if (!$lock->fetchColumn()) throw new \InvalidArgumentException('invalid_installation_id');
            $existing = $this->coreProfilePresets($installation);
            foreach ($existing as $row) if ($row['preset_id'] !== $id && mb_strtolower($row['name']) === mb_strtolower($name))
                throw new \InvalidArgumentException('preset_name_exists');
            if ($id === null) {
                if (count($existing) >= 64) throw new \InvalidArgumentException('preset_limit_reached');
                $id = Uuid::v4();
                $query = $this->db->prepare('INSERT INTO lorkhan_internal.core_profile_presets(preset_id,installation_id,name,payload) VALUES (:id,:installation,:name,CAST(:payload AS jsonb))');
                $query->execute(['id' => $id, 'installation' => $installation, 'name' => $name, 'payload' => $json]);
            } else {
                $query = $this->db->prepare('UPDATE lorkhan_internal.core_profile_presets SET name=:name,payload=CAST(:payload AS jsonb),revision=revision+1,updated_at=clock_timestamp() WHERE preset_id=:id AND installation_id=:installation AND revision=:revision');
                $query->execute(['id' => $id, 'installation' => $installation, 'name' => $name, 'payload' => $json, 'revision' => $revision]);
                if ($query->rowCount() !== 1) throw new RuntimeException('revision_conflict');
            }
            $this->audit('configuration', 'save_core_profile_preset', ['installation_id' => $installation], ['preset_id' => $id]);
            $this->db->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function createSession(int $ttlSeconds): array
    {
        $session=BrowserSession::token();$csrf=BrowserSession::token();
        $expires=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+'.$ttlSeconds.' seconds')->format('Y-m-d\TH:i:s\Z');
        $this->db->prepare('INSERT INTO browser_sessions (session_hash,csrf_hash,expires_at) VALUES (:session,:csrf,:expires)')->execute(['session'=>BrowserSession::hash($session),'csrf'=>BrowserSession::hash($csrf),'expires'=>$expires]);
        return ['session'=>$session,'csrf'=>$csrf,'expires_at'=>$expires];
    }

    public function validate(string $session,?string $csrf=null):bool
    {
        $stmt=$this->db->prepare('SELECT csrf_hash FROM browser_sessions WHERE session_hash=:session AND revoked_at IS NULL AND expires_at>clock_timestamp()');$stmt->execute(['session'=>BrowserSession::hash($session)]);$hash=$stmt->fetchColumn();if($hash===false)return false;if($csrf!==null&&!hash_equals((string)$hash,BrowserSession::hash($csrf)))return false;$this->db->prepare('UPDATE browser_sessions SET last_seen_at=clock_timestamp() WHERE session_hash=:session')->execute(['session'=>BrowserSession::hash($session)]);return true;
    }

    public function revoke(string $session):void{$this->db->prepare('UPDATE browser_sessions SET revoked_at=clock_timestamp() WHERE session_hash=:session')->execute(['session'=>BrowserSession::hash($session)]);}

    /** Clear completed LLM entries from the reader, retaining accounting and unfinished work. */
    public function clearRequestLog(string $installation,?string $relationshipAge=null): int
    {
        if (!Uuid::isValid($installation)) throw new \InvalidArgumentException('invalid_installation_id');
        if($relationshipAge!==null&&!in_array($relationshipAge,['all','1 hour','6 hours','1 day','3 days','1 week','2 weeks','1 month'],true))
            throw new \InvalidArgumentException('invalid_relationship_log_age');
        $filter=$relationshipAge===null?'':" AND a.operation IN ('evaluate_relationship','build_relationships') AND j.job_type IN ('relationship.evaluate','relationship.build')";
        $params=['installation'=>$installation];
        if($relationshipAge!==null&&$relationshipAge!=='all'){$filter.=' AND a.started_at<transaction_timestamp()-CAST(:age AS interval)';$params['age']=$relationshipAge;}
        $this->db->beginTransaction();
        try {
            $scope = $this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:installation FOR SHARE');
            $scope->execute(['installation'=>$installation]);
            if (!$scope->fetchColumn()) throw new \InvalidArgumentException('invalid_installation_id');
            $statement = $this->db->prepare("INSERT INTO lorkhan_internal.request_log_hidden(provider_attempt_id)
                SELECT a.provider_attempt_id FROM provider_attempts a
                LEFT JOIN turns t ON t.turn_id=a.turn_id
                LEFT JOIN sessions s ON s.session_id=t.session_id
                LEFT JOIN durable_jobs j ON j.job_id=a.job_id
                WHERE a.provider_kind='llm' AND a.state IN ('succeeded','failed','cancelled')
                    AND a.finished_at<=transaction_timestamp()
                    AND (t.turn_id IS NULL OR t.state IN ('complete','failed','cancelled'))
                    AND (j.job_id IS NULL OR j.state IN ('succeeded','dead'))
                    AND COALESCE(s.installation_id::text,j.payload->>'installation_id')=:installation".$filter."
                ON CONFLICT DO NOTHING");
            $statement->execute($params);
            $count = $statement->rowCount();
            $this->audit('control', $relationshipAge===null?'clear_request_log':'clear_relationship_log', $params, ['count'=>$count]);
            $this->db->commit();
            return $count;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /** Clear a scoped presentation log without deleting immutable source events or conversation history. */
    public function clearRoleplayLog(string $installation, string $playthrough, string $kind): int
    {
        if (!Uuid::isValid($installation) || !Uuid::isValid($playthrough)
            || !in_array($kind, ['responses', 'diaries', 'memories'], true)) {
            throw new \InvalidArgumentException('invalid_roleplay_log_scope');
        }
        $this->db->beginTransaction();
        try {
            $scope = $this->db->prepare('SELECT playthrough_id FROM playthroughs WHERE installation_id=:installation AND playthrough_id=:playthrough AND deleted_at IS NULL FOR SHARE');
            $scope->execute(['installation'=>$installation, 'playthrough'=>$playthrough]);
            if (!$scope->fetchColumn()) throw new \InvalidArgumentException('invalid_roleplay_log_scope');
            if ($kind === 'responses') {
                $statement = $this->db->prepare("WITH removed AS (
                    DELETE FROM lorkhan_internal.log_metadata m USING public.log l, turns t, sessions s
                    WHERE m.rowid=l.rowid AND m.turn_id=t.turn_id AND t.session_id=s.session_id
                        AND s.installation_id=:installation AND s.playthrough_id=:playthrough
                        AND t.state IN ('complete','failed','cancelled') RETURNING m.rowid
                    ) DELETE FROM public.log l USING removed WHERE l.rowid=removed.rowid");
            } elseif ($kind === 'memories') {
                $statement = $this->db->prepare("UPDATE memory_records SET deleted_at=clock_timestamp(),updated_at=clock_timestamp()
                    WHERE installation_id=:installation AND playthrough_id=:playthrough AND tier IN ('mid','long') AND deleted_at IS NULL");
            } else {
                $statement = $this->db->prepare("UPDATE narrative_records SET deleted_at=clock_timestamp(),updated_at=clock_timestamp()
                    WHERE installation_id=:installation AND playthrough_id=:playthrough AND kind='diary' AND deleted_at IS NULL");
            }
            $statement->execute(['installation'=>$installation, 'playthrough'=>$playthrough]);
            $count = $statement->rowCount();
            $this->audit('roleplay', 'clear_'.$kind, ['installation_id'=>$installation, 'playthrough_id'=>$playthrough], ['count'=>$count]);
            $this->db->commit();
            return $count;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /** Queue a bounded page of missing summaries; existing jobs and source memories are never duplicated. */
    public function syncMemorySummaries(string $installation, string $playthrough): array
    {
        if (!Uuid::isValid($installation) || !Uuid::isValid($playthrough)) throw new \InvalidArgumentException('invalid_memory_scope');
        $this->db->beginTransaction();
        try {
            $scope=$this->db->prepare('SELECT 1 FROM playthroughs WHERE installation_id=:installation AND playthrough_id=:playthrough AND deleted_at IS NULL FOR SHARE');
            $scope->execute(['installation'=>$installation,'playthrough'=>$playthrough]);
            if (!$scope->fetchColumn()) throw new \InvalidArgumentException('invalid_memory_scope');
            $this->db->prepare('SELECT pg_advisory_xact_lock(hashtext(:scope))')->execute(['scope'=>'memory-sync:'.$installation.':'.$playthrough]);
            $summaries=new MemorySummaryRepository($this->db);
            $policy=$summaries->policy($installation);
            if (($policy['content']['enabled']??false)!==true) throw new \InvalidArgumentException('memory_summary_policy_disabled');
            $summaries->assertProvider($installation,$policy['content']);
            $query=$this->db->prepare("SELECT m.memory_id,m.current_revision FROM memory_records m
                WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough AND m.deleted_at IS NULL
                    AND (m.expires_at IS NULL OR m.expires_at>clock_timestamp())
                    AND m.derivation_key IS NOT NULL AND m.tier IN ('mid','long')
                    AND m.provenance->>'source'='memory.consolidate' AND m.provenance->>'provider'='first-party'
                    AND m.provenance->>'model'='deterministic-extractive-v1'
                    AND NOT EXISTS(SELECT 1 FROM memory_model_summaries s WHERE s.memory_id=m.memory_id AND s.memory_revision=m.current_revision)
                    AND NOT EXISTS(SELECT 1 FROM durable_jobs j WHERE j.job_type='memory.summarize'
                        AND j.payload->>'memory_id'=m.memory_id::text AND j.payload->>'memory_revision'=m.current_revision::text)
                ORDER BY m.memory_id LIMIT 101");
            $query->execute(['installation'=>$installation,'playthrough'=>$playthrough]);$rows=$query->fetchAll();$queued=0;
            foreach (array_slice($rows,0,100) as $row) {
                if ($summaries->enqueue($installation,$row['memory_id'],(int)$row['current_revision'])!==null) ++$queued;
            }
            $this->audit('roleplay','sync_memories',['installation_id'=>$installation,'playthrough_id'=>$playthrough],['queued'=>$queued]);
            $this->db->commit();
            return ['queued'=>$queued,'has_more'=>count($rows)>100];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /** Atomically admit at most one bounded number of TTS previews per browser-session window. */
    public function allowTtsPreview(string $session,int $limit=30,int $windowSeconds=60):bool
    {
        $statement=$this->db->prepare("UPDATE browser_sessions SET
                tts_preview_count=CASE WHEN tts_preview_window_started_at IS NULL
                    OR tts_preview_window_started_at<=clock_timestamp()-make_interval(secs=>:window_reset)
                    THEN 1 ELSE tts_preview_count+1 END,
                tts_preview_window_started_at=CASE WHEN tts_preview_window_started_at IS NULL
                    OR tts_preview_window_started_at<=clock_timestamp()-make_interval(secs=>:window_start)
                    THEN clock_timestamp() ELSE tts_preview_window_started_at END
            WHERE session_hash=:session AND revoked_at IS NULL AND expires_at>clock_timestamp()
                AND (tts_preview_window_started_at IS NULL
                    OR tts_preview_window_started_at<=clock_timestamp()-make_interval(secs=>:window_allow)
                    OR tts_preview_count<:request_limit)
            RETURNING tts_preview_count");
        $statement->execute(['window_reset'=>$windowSeconds,'window_start'=>$windowSeconds,'window_allow'=>$windowSeconds,
            'session'=>BrowserSession::hash($session),'request_limit'=>$limit]);
        return $statement->fetchColumn()!==false;
    }

    public function rotatePairingToken(string $installationId,string $_unused,int $overlapSeconds):array
    {
        $key=random_bytes(32);$hash=hash('sha256',$key);
        $this->db->beginTransaction();try{$this->db->prepare("UPDATE pairing_tokens SET state='overlap',valid_until=clock_timestamp()+(:seconds||' seconds')::interval WHERE installation_id=:installation AND state='active'")->execute(['seconds'=>(string)$overlapSeconds,'installation'=>$installationId]);$id=Uuid::v4();$insert=$this->db->prepare("INSERT INTO pairing_tokens (pairing_token_id,installation_id,token_hash,mac_key,state) VALUES (:id,:installation,:hash,:key,'active')");$insert->bindValue(':id',$id);$insert->bindValue(':installation',$installationId);$insert->bindValue(':hash',$hash);$insert->bindValue(':key',$key,\PDO::PARAM_LOB);$insert->execute();$this->db->prepare('UPDATE installations SET token_fingerprint=:hash WHERE installation_id=:installation')->execute(['hash'=>$hash,'installation'=>$installationId]);$this->audit('security','pairing.rotate',['installation_id'=>$installationId],['overlap_seconds'=>$overlapSeconds]);$this->db->commit();return ['pairing_token_id'=>$id,'overlap_seconds'=>$overlapSeconds,'mac_key'=>rtrim(strtr(base64_encode($key),'+/','-_'),'=')];}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    public function revokePairingToken(string $id):void{$stmt=$this->db->prepare("UPDATE pairing_tokens SET state='revoked',revoked_at=clock_timestamp() WHERE pairing_token_id=:id AND state<>'revoked'");$stmt->execute(['id'=>$id]);if($stmt->rowCount()!==1)throw new RuntimeException('not_found');$this->audit('security','pairing.revoke',['pairing_token_id'=>$id],[]);}
    public function verifyPairing(string $tokenHash):bool{$s=$this->db->prepare("SELECT 1 FROM pairing_tokens WHERE token_hash=:hash AND (state='active' OR (state='overlap' AND valid_until>clock_timestamp())) LIMIT 1");$s->execute(['hash'=>$tokenHash]);return(bool)$s->fetchColumn();}
    public function authorizePairing(?string $authorization):?bool{return $this->pairingPrincipal($authorization)!==false;}
    public function pairingPrincipal(?string $authorization):string|false|null{return false;}

    public function requestMacPrincipal(Request $request, int $clockSkewSeconds = 300): string|false|null
    {
        $count=(int)$this->db->query('SELECT count(*) FROM pairing_tokens')->fetchColumn();if($count===0)return null;
        $installation=(string)($request->header('X-LORKHAN-Installation-Id')??'');
        $timestamp=(string)($request->header('X-LORKHAN-Timestamp')??'');
        $nonce=(string)($request->header('X-LORKHAN-Nonce')??'');
        $digest=(string)($request->header('X-LORKHAN-Content-SHA256')??'');
        $signature=(string)($request->header('X-LORKHAN-Signature')??'');
        $algorithm=(string)($request->header('X-LORKHAN-Auth')??'');
        if($algorithm!==RequestMac::ALGORITHM||preg_match('/^[0-9a-f-]{36}$/D',$installation)!==1
            ||preg_match('/^[0-9a-f]{32}$/D',$nonce)!==1||preg_match('/^[0-9a-f]{64}$/D',$digest)!==1
            ||preg_match('/^[0-9a-f]{64}$/D',$signature)!==1)return false;
        try{$instant=new \DateTimeImmutable($timestamp,new \DateTimeZone('UTC'));}catch(\Throwable){return false;}
        if($instant->format('Y-m-d\TH:i:s\Z')!==$timestamp||abs(time()-$instant->getTimestamp())>$clockSkewSeconds)return false;
        if(!hash_equals(RequestMac::bodyDigest($request->body),$digest))return false;
        $s=$this->db->prepare("SELECT pairing_token_id,mac_key FROM pairing_tokens WHERE installation_id=:installation AND (state='active' OR(state='overlap' AND valid_until>clock_timestamp())) ORDER BY state='active' DESC");
        $s->execute(['installation'=>$installation]);
        foreach($s->fetchAll() as $row){$key=is_resource($row['mac_key'])?stream_get_contents($row['mac_key']):(string)$row['mac_key'];$expected=RequestMac::sign($key,$request,$installation,$timestamp,$nonce,(string)($request->header('Content-Type')??''),$digest);if(!hash_equals($expected,$signature))continue;
            try{$insert=$this->db->prepare('INSERT INTO request_mac_nonces(pairing_token_id,nonce,request_timestamp) VALUES (:token,:nonce,:instant)');$insert->execute(['token'=>$row['pairing_token_id'],'nonce'=>$nonce,'instant'=>$timestamp]);}catch(\PDOException $error){if($error->getCode()==='23505')return false;throw$error;}
            $this->db->prepare("DELETE FROM request_mac_nonces WHERE created_at<clock_timestamp()-interval '10 minutes'")->execute();return$installation;}
        return false;
    }

    public function audit(string $category,string $action,array $scope,array $detail):void{$scope=(object)$scope;$detail=(object)$detail;$this->db->prepare('INSERT INTO operational_audit (audit_id,category,action,scope,detail) VALUES (:id,:category,:action,CAST(:scope AS jsonb),CAST(:detail AS jsonb))')->execute(['id'=>Uuid::v4(),'category'=>$category,'action'=>$action,'scope'=>json_encode($scope,JSON_THROW_ON_ERROR),'detail'=>json_encode($detail,JSON_THROW_ON_ERROR)]);}
}
