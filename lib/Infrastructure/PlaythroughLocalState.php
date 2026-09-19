<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/** CHIM-style active gameplay tables, staged separately from inert imported history. */
final class PlaythroughLocalState
{
    // Fixed order follows dependencies. Catalogues retain their stable IDs, not copied-world IDs.
    private const TABLES = [
        'lorkhan_internal.oghma_catalogs', 'lorkhan_internal.oghma_catalog_entries',
        'lorkhan_internal.knowledge_documents', 'lorkhan_internal.oghma_factory_documents',
        'lorkhan_internal.oghma_catalog_deletions', 'lorkhan_internal.oghma_dynamic',
        'public.oghma_context_rule', 'public.conf_opts', 'public.general_settings',
        'public.core_player', 'public.factions', 'public.locations', 'public.game_plugins',
        'public.named_cell', 'public.market_cache', 'public.rumors', 'public.npc_profile_backup',
        'public.physical_npc_diaries', 'public.audit_memory', 'public.moods_issued', 'public.dynamic_bio',
        'lorkhan_internal.faction_metadata', 'lorkhan_internal.location_metadata',
        'lorkhan_internal.game_plugin_metadata',
    ];
    private ?array $globalSettingIds=null;

    public function __construct(private readonly PDO $db) {}

    public static function tableNames(): array { return self::TABLES; }

    /** Use saved inactive state, never accidentally snapshot the currently loaded other character. */
    public function capture(string $installation, string $world): array
    {
        $active=(new ProfileOwnershipRepository($this->db))->activePlaythrough($installation);
        if ($active!==null && $active!==$world) {
            $q=$this->db->prepare('SELECT state FROM playthrough_local_state WHERE installation_id=:i AND playthrough_id=:w');
            $q->execute(['i'=>$installation,'w'=>$world]);$state=$q->fetchColumn();
            if ($state===false) throw new RuntimeException('archive_local_state_unavailable');
            return self::decode($state);
        }
        $tables=[];$columns=[];$bytes=0;$count=0;
        foreach (self::TABLES as $table) {
            $columns[$table]=$this->columns($table);$tables[$table]=[];
            $q=$this->db->prepare('SELECT to_jsonb(t) FROM '.$table.' t WHERE '.$this->scope($table,$installation).' LIMIT '.(PlaythroughArchive::MAX_ROWS+1));
            $q->execute();
            while (($json=$q->fetchColumn())!==false) {
                $row=(array)json_decode($json,false,64,JSON_THROW_ON_ERROR);
                if (in_array($table,['public.conf_opts','public.general_settings'],true) && !$this->isGameplaySetting($table,(string)$row['id'])) continue;
                $bytes+=strlen($json);++$count;
                if ($bytes>PlaythroughArchive::MAX_LOCAL_BYTES || $count>PlaythroughArchive::MAX_ROWS) throw new RuntimeException('archive_too_large');
                $tables[$table][]=$row;
            }
        }
        return ['version'=>1,'columns'=>$columns,'tables'=>$tables];
    }

    /** Only local saves carry installation-wide state; uploaded portable archives cannot supply it. */
    public function validate(array $state,string $installation): void
    {
        $names=array_keys($state['tables']??[]);$expected=self::TABLES;sort($names);sort($expected);
        if (($state['version']??null)!==1 || $names!==$expected) throw new RuntimeException('archive_local_state_invalid');
        $count=0;
        foreach (self::TABLES as $table) {
            $columns=$this->columns($table);
            if (($state['columns'][$table]??null)!==$columns) throw new RuntimeException('archive_schema_mismatch');
            if (!is_array($state['tables'][$table]) || !array_is_list($state['tables'][$table])) throw new RuntimeException('archive_local_state_invalid');
            foreach ($state['tables'][$table] as $row) {
                if (++$count>PlaythroughArchive::MAX_ROWS) throw new RuntimeException('archive_too_large');
                $keys=array_keys($row);sort($keys);$sorted=$columns;sort($sorted);
                if ($keys!==$sorted || (isset($row['installation_id']) && $row['installation_id']!==$installation)) throw new RuntimeException('archive_scope_mismatch');
                if ($table==='lorkhan_internal.knowledge_documents' && ($row['playthrough_id']!==null || $row['profile_id']!==null)) throw new RuntimeException('archive_scope_mismatch');
                if (in_array($table,['public.conf_opts','public.general_settings'],true) && !self::gameplaySetting($table,(string)$row['id'])) throw new RuntimeException('archive_global_setting');
            }
        }
    }

    public function stage(string $installation,string $world,array $state): void
    {
        $this->validate($state,$installation);
        $q=$this->db->prepare('INSERT INTO playthrough_local_state(installation_id,playthrough_id,state) VALUES(:i,:w,CAST(:s AS jsonb)) ON CONFLICT(playthrough_id) DO UPDATE SET state=EXCLUDED.state,updated_at=clock_timestamp()');
        $q->execute(['i'=>$installation,'w'=>$world,'s'=>json_encode($state,JSON_THROW_ON_ERROR)]);
    }

    /** Runs inside the installation-locked handshake; preparing a copy never changes active tables. */
    public function activate(string $installation,string $world): void
    {
        if (!$this->db->inTransaction()) throw new RuntimeException('character_binding_transaction_required');
        $q=$this->db->prepare('SELECT state FROM playthrough_local_state WHERE installation_id=:i AND playthrough_id=:w');
        $q->execute(['i'=>$installation,'w'=>$world]);$json=$q->fetchColumn();
        if ($json===false) return; // Legacy copies have no additional state to apply.
        $active=(new ProfileOwnershipRepository($this->db))->activePlaythrough($installation);
        if ($active===$world) return;
        // Public compatibility tables have one active installation, like CHIM's public schema.
        if ((int)$this->db->query('SELECT count(*) FROM installations WHERE revoked_at IS NULL')->fetchColumn()>1) throw new RuntimeException('archive_local_state_multiple_installations');
        $state=self::decode($json);$this->validate($state,$installation);
        if ($active!==null) $this->stage($installation,$active,$this->capture($installation,$active));
        foreach (array_reverse(self::TABLES) as $table) {
            $scope=$this->scope($table,$installation);
            if (in_array($table,['public.conf_opts','public.general_settings'],true)) {
                $q=$this->db->query('SELECT id FROM '.$table);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) if ($this->isGameplaySetting($table,$id)) {
                    $delete=$this->db->prepare('DELETE FROM '.$table.' WHERE id=:id');$delete->execute(['id'=>$id]);
                }
            } elseif ($table==='lorkhan_internal.knowledge_documents' || $table==='lorkhan_internal.oghma_dynamic') {
                // Historical worlds may still reference these stable IDs. Retire, never cascade-delete them.
                $this->db->exec('UPDATE '.$table.' SET deleted_at=clock_timestamp() WHERE '.$scope);
            } elseif ($table==='lorkhan_internal.oghma_catalogs') {
                $this->db->exec("UPDATE lorkhan_internal.oghma_catalogs SET state='superseded' WHERE state='active'");
            } else {
                $this->db->exec('DELETE FROM '.$table.' WHERE '.$scope);
            }
        }
        foreach (self::TABLES as $table) {
            $columns=$state['columns'][$table];$quoted=array_map(static fn($c)=>'"'.$c.'"',$columns);
            $upsert=match($table) {
                'lorkhan_internal.oghma_catalogs'=>['catalog_id'],
                'lorkhan_internal.oghma_catalog_entries'=>['catalog_id','topic'],
                'lorkhan_internal.knowledge_documents'=>['document_id'],
                'lorkhan_internal.oghma_dynamic'=>['id'],
                default=>[],
            };
            $sql='INSERT INTO '.$table.'('.implode(',',$quoted).') OVERRIDING SYSTEM VALUE SELECT '.implode(',',$quoted).' FROM jsonb_populate_record(NULL::'.$table.',CAST(:row AS jsonb))';
            if ($upsert!==[]) {
                $updates=[];foreach ($columns as $column) if (!in_array($column,$upsert,true)) $updates[]='"'.$column.'"=EXCLUDED."'.$column.'"';
                $sql.=' ON CONFLICT('.implode(',',$upsert).') DO UPDATE SET '.implode(',',$updates);
            }
            $insert=$this->db->prepare($sql);
            foreach ($state['tables'][$table] as $row) {
                if (in_array($table,['public.conf_opts','public.general_settings'],true) && !$this->isGameplaySetting($table,(string)$row['id'])) continue;
                $insert->execute(['row'=>json_encode($row,JSON_THROW_ON_ERROR)]);
            }
            // Existing sequence high-water marks are intentionally retained when restoring old IDs.
        }
    }

    /** Same mixed-table policy as CHIM's chim_meta.is_global_setting (unstable 5a97fe2f). */
    public static function gameplaySetting(string $table,string $id,array $globalIds=[]): bool
    {
        if (preg_match('/^(PLAYER_NAME|PLAYER_BIO|PLAYER_CATS|CurrentParty|PLAYER_SQUADS)$/D',$id)===1
            || preg_match('/^(DIARY_LAST_|AUTO_DIARY_LAST_|NARRATOR_AUTO_DIARY_LAST_|DYNAMIC_PROFILE_MANUAL_|DYNAMIC_PROFILE_CLOCK$|DYNAMIC_PROFILE_STATE_|DYNAMIC_PROFILE_LAST_|DYNAMIC_PROFILE_LOAD_GRACE_|MEMORY_LAST_)/D',$id)===1) return true;
        if ($table==='public.general_settings') return false;
        if ($table!=='public.conf_opts') return true;
        return !in_array($id,['CONTEXT_HISTORY','CONTEXT_HISTORY_DIARY','CONTEXT_HISTORY_DYNAMIC_PROFILE','MAX_WORDS_LIMIT',
            'RECHAT_H','RECHAT_P','RECHAT_ALLOW_ACTIONS','BORED_EVENT','RPG_COMMENTS_CHANCE','COMBAT_BARK_COOLDOWN',
            'QUEST_COMMENT','PLAYER2_FORCE_ALL_LLM','PLAYER2_HEALTH_URL','core_action_legacy_user_pref_imported',
            'dialectic_mode','dialectic_profile_model','plugin_dll_version'],true)
            && preg_match('/^(cartesia_voice_|inworld_voice_|tts_sync_|Voicetype\/|Network\/)/',$id)!==1
            && !in_array($id,$globalIds,true);
    }

    private function isGameplaySetting(string $table,string $id): bool
    {
        $this->globalSettingIds??=$this->db->query('SELECT id FROM public.general_settings')->fetchAll(PDO::FETCH_COLUMN);
        return self::gameplaySetting($table,$id,$this->globalSettingIds);
    }

    /** Preserve JSON objects inside SQL rows, particularly empty rule conditions and provenance. */
    public static function decode(string $json): array
    {
        $state=(array)json_decode($json,false,64,JSON_THROW_ON_ERROR);
        $state['columns']=(array)($state['columns']??[]);
        $state['tables']=(array)($state['tables']??[]);
        foreach ($state['tables'] as &$rows) $rows=array_map(static fn($row)=>(array)$row,$rows);
        return $state;
    }

    private function scope(string $table,string $installation): string
    {
        $columns=$this->columns($table);$scope=in_array('installation_id',$columns,true)?'installation_id='.$this->db->quote($installation):'true';
        if ($table==='lorkhan_internal.knowledge_documents') $scope.=' AND playthrough_id IS NULL AND profile_id IS NULL';
        return $scope;
    }

    private function columns(string $table): array
    {
        [$schema,$name]=explode('.',$table);
        $q=$this->db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=:s AND table_name=:t ORDER BY ordinal_position');
        $q->execute(['s'=>$schema,'t'=>$name]);return $q->fetchAll(PDO::FETCH_COLUMN);
    }
}
