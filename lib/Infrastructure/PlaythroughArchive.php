<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/** A data-only, bounded playthrough package; input never supplies executable SQL or identifiers. */
final class PlaythroughArchive
{
    public const MAX_BYTES=16777216;
    public const MAX_LOCAL_BYTES=134217728;
    public const MAX_ROWS=50000;
    private const ADDED_TABLES=['memory_model_summaries','profile_evolution_clocks','profile_evolution_progress',
        'profile_evolution_events','audit_request_metadata','log_metadata','retrieval_traces'];
    private const TABLES=[
        'playthroughs','playthrough_revisions','profiles','profile_revisions','actor_profile_bindings','sessions',
        'source_events','turns','action_intents','action_results','dialogue_utterances','dialogue_delivery_results','interruptions',
        'memory_records','memory_record_revisions','relationship_records','relationship_revisions','relationship_audit',
        'narrative_records','knowledge_documents','oghma_dynamic_applications','timeline_invalidated_sources','timeline_invalidated_turns',
        'eventlog_metadata','speech','speech_metadata','responselog','responselog_metadata','book_metadata','quest_metadata',
        'questlog_metadata','currentmission_metadata','action_issued_metadata','npc_memory_digests','diarylog_metadata',
    ];
    private const PUBLIC_TABLES=['eventlog','speech','responselog','books','quests','questlog','currentmission','actions_issued','diarylog'];
    private const SUSPENDED_TRIGGERS=['herika_project_speech','herika_project_responselog','herika_project_memory',
        'herika_project_knowledge','herika_project_narrative','herika_project_relationship','herika_project_action',
        'herika_project_turn_snapshot','herika_project_dialogue_audit','herika_project_turn_world',
        'herika_project_profile','herika_project_profile_revision','profile_plugin_data_capture','memory_record_initial_revision_capture',
        'memory_record_revision_capture','relationship_revision','turns_openmw_record_identity'];
    private ?array $metadata=null;
    private int $collectedBytes=0;
    private int $deadline=0;

    public function __construct(private readonly PDO $db, private readonly bool $localSave = false) {}

    public static function tableNames(bool $localSave=false):array
    {
        return array_merge(array_map(static fn($name)=>'lorkhan_internal.'.$name,array_merge(self::TABLES,$localSave?self::ADDED_TABLES:[])),array_map(static fn($name)=>'public.'.$name,array_merge(self::PUBLIC_TABLES,$localSave?['audit_request','log']:[])));
    }

    /** Obtain a repeatable database snapshot, rejecting overflow rather than producing a truncated success. */
    public function export(string $installation,string $playthrough):array
    {
        $this->deadline=hrtime(true)+120_000_000_000;
        if(!Uuid::isValid($installation)||!Uuid::isValid($playthrough))throw new RuntimeException('invalid_archive_scope');
        $owns = !$this->db->inTransaction();
        if (!$owns && !$this->localSave) throw new RuntimeException('archive_transaction_active');
        if ($owns) $this->db->beginTransaction();
        try{
            if ($owns) $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $this->db->exec("SET LOCAL statement_timeout='30s'; SET LOCAL lock_timeout='3s'");
            $meta=$this->metadata();$tables=array_fill_keys(self::tableNames($this->localSave),[]);$this->collectedBytes=0;
            foreach($meta as$table=>$definition){
                if(!isset($definition['columns']['playthrough_id']))continue;
                $where='t.playthrough_id=:world';$parameters=['world'=>$playthrough];
                if(isset($definition['columns']['installation_id'])){$where.=' AND t.installation_id=:installation';$parameters['installation']=$installation;}
                $q=$this->db->prepare('SELECT to_jsonb(t) FROM '.$table.' t WHERE '.$where.' LIMIT '.(self::MAX_ROWS+1));
                $q->execute($parameters);$this->collect($tables[$table],$q,$definition);
            }
            if(count($tables['lorkhan_internal.playthroughs'])!==1)throw new RuntimeException('archive_playthrough_not_found');
            // Follow fixed schema relationships in both directions, but never cross a declared world/installation boundary.
            for($pass=0;$pass<count($meta);++$pass){
                $before=array_sum(array_map('count',$tables));
                foreach($meta as$table=>$definition)foreach($definition['foreign']as$fk){
                    if(!isset($meta[$fk['table']]))continue;
                    foreach([false,true]as$reverse){
                        $from=$reverse?$table:$fk['table'];$to=$reverse?$fk['table']:$table;
                        $fromColumns=$reverse?$fk['columns']:$fk['references'];$toColumns=$reverse?$fk['references']:$fk['columns'];
                        if($tables[$from]===[])continue;
                        $keys=[];foreach($tables[$from]as$row){$key=[];foreach($fromColumns as$column)$key[]=$row[$column]??null;if(!in_array(null,$key,true))$keys[]=$key;}
                        foreach(array_chunk($keys,200)as$chunk){$this->checkBudget();
                            $parameters=[];$groups=[];foreach($chunk as$key){$parts=[];foreach($toColumns as$i=>$column){$parts[]='t.'.$column.'=?';$parameters[]=$key[$i];}$groups[]='('.implode(' AND ',$parts).')';}
                            if($groups===[])continue;
                            $where='('.implode(' OR ',$groups).')';
                            if(isset($meta[$to]['columns']['installation_id'])){$where.=' AND t.installation_id=?';$parameters[]=$installation;}
                            if(isset($meta[$to]['columns']['playthrough_id'])){$where.=' AND t.playthrough_id=?';$parameters[]=$playthrough;}
                            $q=$this->db->prepare('SELECT to_jsonb(t) FROM '.$to.' t WHERE '.$where.' LIMIT '.(self::MAX_ROWS+1));$q->execute($parameters);$this->collect($tables[$to],$q,$meta[$to]);
                        }
                    }
                }
                $after=array_sum(array_map('count',$tables));if($after>self::MAX_ROWS)throw new RuntimeException('archive_too_large');if($after===$before)break;
            }
            foreach($tables as$table=>&$rows){ksort($rows);$rows=array_values($rows);foreach($rows as&$row){foreach($meta[$table]['columns']as$column=>$type)if(in_array($type['type'],['json','jsonb'],true)&&(is_array($row[$column])||is_object($row[$column])))$row[$column]=$this->portableProfile($row[$column]);if(isset($row['audios']))$row['audios']='';}unset($row);}unset($rows);
            $owned=array_column($tables['lorkhan_internal.profiles'],'profile_id');$shared=[];
            foreach($tables as$rows)foreach($rows as$row)if(isset($row['profile_id'])&&!in_array($row['profile_id'],$owned,true))$shared[$row['profile_id']]=true;
            foreach($shared as$id=>$_){$q=$this->db->prepare("SELECT actor_identity->>'kind' FROM profiles WHERE profile_id=:id AND installation_id=:installation");$q->execute(['id'=>$id,'installation'=>$installation]);if($q->fetchColumn()!=='narrator')throw new RuntimeException('archive_unowned_profile');$shared[$id]='narrator';}
            $document=['format'=>$this->localSave?'lorkhan.playthrough-save':'lorkhan.playthrough-archive','version'=>1,'schema_sha256'=>$this->schemaHash($meta),
                'source'=>['installation_id'=>$installation,'playthrough_id'=>$playthrough],'created_at'=>gmdate('c'),
                'limitations'=>$this->limitations(),'shared_profiles'=>$shared,'tables'=>$tables];
            if ($this->localSave) $document['local_state']=(new PlaythroughLocalState($this->db))->capture($installation,$playthrough);
            $document['sha256']=hash('sha256',$this->canonical($document));
            if(strlen($this->canonical($document))>($this->localSave?self::MAX_LOCAL_BYTES:self::MAX_BYTES))throw new RuntimeException('archive_too_large');
            $this->validate($document);if ($owns) $this->db->commit();return$document;
        }catch(\Throwable$error){if($owns && $this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function inspect(string $json):array
    {
        $this->deadline=hrtime(true)+120_000_000_000;
        $document=$this->decode($json);$this->validate($document);
        return ['format'=>$document['format'],'version'=>1,'source'=>$document['source'],'created_at'=>$document['created_at'],
            'sha256'=>$document['sha256'],'row_counts'=>array_map('count',$document['tables']),'limitations'=>$this->limitations()];
    }

    /** Restore only new IDs into an inactive world; no session or delivery can become runnable. */
    public function importCopy(string $installation,string $json):array
    {
        $this->deadline=hrtime(true)+120_000_000_000;
        if(!Uuid::isValid($installation))throw new RuntimeException('invalid_archive_scope');
        $document=$this->decode($json);$this->validate($document);$meta=$this->metadata();
        // Validate the original document before filling sections omitted by older archives.
        foreach (self::tableNames($this->localSave) as $table) $document['tables'][$table]??=[];
        foreach(['lorkhan_internal.profiles','lorkhan_internal.profile_revisions']as$table){
            foreach($document['tables'][$table]as&$row){
                if(!array_key_exists('plugin_extended_data',$row))$row['plugin_extended_data']=new \stdClass();
            }
            unset($row);
        }
        usort($document['tables']['lorkhan_internal.relationship_audit'],static fn($a,$b)=>$a['audit_sequence']<=>$b['audit_sequence']);
        $owns = !$this->db->inTransaction();
        if (!$owns && !$this->localSave) throw new RuntimeException('archive_transaction_active');
        if ($this->localSave && $document['source']['installation_id'] !== $installation) throw new RuntimeException('archive_scope_mismatch');
        if ($owns) $this->db->beginTransaction();
        try{
            $this->db->exec("SET LOCAL lock_timeout='3s'; SET LOCAL statement_timeout='30s'");
            $this->db->exec('SET CONSTRAINTS ALL DEFERRED');
            $q=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:id AND revoked_at IS NULL FOR UPDATE');$q->execute(['id'=>$installation]);if(!$q->fetchColumn())throw new RuntimeException('not_found');
            $products=new ProductRepository($this->db);$core=$products->defaultCoreProfileForInstallation($installation);
            if($core===null)throw new RuntimeException('archive_default_core_missing');
            $uuid=[$document['source']['installation_id']=>$installation,$document['source']['playthrough_id']=>Uuid::v4()];$serial=[];
            foreach($document['shared_profiles']as$id=>$kind){$narrator=$products->narratorProfileForInstallation($installation);if($narrator===null)throw new RuntimeException('archive_shared_narrator_missing');$uuid[$id]=$narrator['profile_id'];}
            // Reference keys are stable within one world, but a copied world owns new scoped keys.
            foreach($document['tables']['lorkhan_internal.profiles']as$profile){
                $id=$profile['profile_id'];
                $uuid[$id]=str_starts_with($id,'ref:')
                    ?'ref:'.$installation.':'.$uuid[$document['source']['playthrough_id']].':'.explode(':',$id,4)[3]
                    :Uuid::v4();
            }
            // Local saves keep references to existing shared settings; they never copy those settings.
            if ($this->localSave) {
                foreach (['core_profiles'=>'core_profile_id', 'configuration_sets'=>'configuration_id'] as $table=>$column) {
                    $q=$this->db->prepare('SELECT '.$column.' FROM '.$table.' WHERE installation_id=:installation');
                    $q->execute(['installation'=>$installation]);
                    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $uuid[$id]=$id;
                }
            }
            foreach($document['tables']as$table=>$rows)foreach($rows as$row)foreach($meta[$table]['columns']as$column=>$type){
                $this->checkBudget();
                if($row[$column]===null)continue;
                if($type['type']==='uuid'&&!isset($uuid[$row[$column]]))$uuid[$row[$column]]=Uuid::v4();
                if($type['identity']||(in_array($column,$meta[$table]['primary'],true)&&str_contains((string)$type['default'],'nextval('))){
                    $sequence=$this->db->prepare('SELECT pg_get_serial_sequence(:table,:column)');$sequence->execute(['table'=>$table,'column'=>$column]);$sequenceName=$sequence->fetchColumn();
                    if(!$sequenceName){
                        $sequence=$this->db->prepare("SELECT s.oid::regclass::text FROM pg_attribute a JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum JOIN pg_depend dep ON dep.classid='pg_attrdef'::regclass AND dep.objid=d.oid AND dep.refclassid='pg_class'::regclass JOIN pg_class s ON s.oid=dep.refobjid AND s.relkind='S' WHERE a.attrelid=CAST(:table AS regclass) AND a.attname=:column");
                        $sequence->execute(['table'=>$table,'column'=>$column]);$sequenceName=$sequence->fetchColumn();
                    }
                    if(!$sequenceName)throw new RuntimeException('archive_sequence_unavailable');$next=$this->db->prepare('SELECT nextval(CAST(:sequence AS regclass))');$next->execute(['sequence'=>$sequenceName]);$serial[$table][$column][(string)$row[$column]]=(int)$next->fetchColumn();
                }
            }
            $collectIds=function(mixed $value)use(&$collectIds,&$uuid):void{
                if(is_string($value)&&Uuid::isValid($value)&&!isset($uuid[$value]))$uuid[$value]=Uuid::v4();
                elseif(is_array($value)||is_object($value))foreach($value as$key=>$child){if(is_string($key)&&Uuid::isValid($key)&&!isset($uuid[$key]))$uuid[$key]=Uuid::v4();$collectIds($child);}
            };
            $collectIds($document['tables']);
            $disabled=[];
            foreach($meta as$table=>$definition){
                $q=$this->db->prepare('SELECT tgname,tgenabled FROM pg_trigger WHERE tgrelid=CAST(:table AS regclass) AND NOT tgisinternal');$q->execute(['table'=>$table]);
                foreach($q->fetchAll()as$trigger)if(in_array($trigger['tgname'],self::SUSPENDED_TRIGGERS,true)&&$trigger['tgenabled']==='O'){
                    $this->db->exec('ALTER TABLE '.$table.' DISABLE TRIGGER '.$trigger['tgname']);$disabled[]=[$table,$trigger['tgname']];
                }
            }
            $rows=[];$external=[];
            foreach($document['tables']as$table=>$sourceRows)foreach($sourceRows as$source){
                $row=$this->remapJson($source,$uuid);
                foreach($meta[$table]['columns']as$column=>$type)if(in_array($type['type'],['json','jsonb'],true)&&(is_array($row[$column])||is_object($row[$column])))$row[$column]=$this->portableProfile($row[$column]);
                if(isset($row['audios']))$row['audios']='';
                foreach($serial[$table]??[]as$column=>$values)if(isset($values[(string)$source[$column]]))$row[$column]=$values[(string)$source[$column]];
                foreach($meta[$table]['foreign']as$fk){
                    foreach($fk['columns']as$i=>$column){$old=$source[$column];if($old!==null&&isset($serial[$fk['table']][$fk['references'][$i]][(string)$old]))$row[$column]=$serial[$fk['table']][$fk['references'][$i]][(string)$old];}
                    if(isset($meta[$fk['table']])||$fk['table']==='lorkhan_internal.installations')continue;
                    if($fk['table']==='lorkhan_internal.core_profiles'){foreach($fk['columns']as$column)if($column==='core_profile_id')$row[$column]=$this->localSave ? $source[$column] : $core['core_profile_id'];continue;}
                    if($fk['table']==='lorkhan_internal.character_playthrough_bindings'){if($table==='lorkhan_internal.sessions')$row['character_id']=null;continue;}
                    if ($this->localSave && in_array($fk['table'],['lorkhan_internal.configuration_sets','lorkhan_internal.configuration_revisions','lorkhan_internal.core_profile_revisions'],true)) {
                        if (in_array(null,array_map(static fn($column)=>$source[$column],$fk['columns']),true)) continue;
                        $conditions=[];$values=[];
                        foreach ($fk['columns'] as $i=>$column) {$row[$column]=$source[$column];$conditions[]=$fk['references'][$i].'=?';$values[]=$source[$column];}
                        $check=$this->db->prepare('SELECT 1 FROM '.$fk['table'].' WHERE '.implode(' AND ',$conditions));
                        $check->execute($values);
                        if (!$check->fetchColumn()) throw new RuntimeException('archive_shared_dependency_missing');
                        continue;
                    }
                    $nullable=array_values(array_filter($fk['columns'],fn($column)=>$meta[$table]['columns'][$column]['nullable']));
                    if($nullable!==[]){foreach($nullable as$column)$row[$column]=null;continue;}
                    // Required shared catalog references must exist at the destination, never be silently manufactured.
                    $conditions=[];$values=[];foreach($fk['columns']as$i=>$column){$row[$column]=$source[$column];$conditions[]=$fk['references'][$i].'=?';$values[]=$source[$column];}
                    if($fk['table']!=='lorkhan_internal.action_catalog'){
                        $columns=$this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=split_part(:table,'.',1) AND table_name=split_part(:table,'.',2) AND column_name='installation_id'");$columns->execute(['table'=>$fk['table']]);
                        if(!$columns->fetchColumn())throw new RuntimeException('archive_shared_dependency_missing');$conditions[]='installation_id=?';$values[]=$installation;
                    }
                    $check=$this->db->prepare('SELECT 1 FROM '.$fk['table'].' WHERE '.implode(' AND ',$conditions));$check->execute($values);if(!$check->fetchColumn())throw new RuntimeException('archive_shared_dependency_missing');
                }
                if($table==='lorkhan_internal.sessions'){$row['archived']=true;$row['state']='ended';$row['capabilities']=[];$row['enabled_actions']=[];$row['character_id']=null;$row['event_sequence']=0;$row['ended_at']??=gmdate('c');}
                if($table==='lorkhan_internal.turns'){$row['state']=in_array($row['state'],['complete','failed','cancelled'],true)?$row['state']:'cancelled';foreach(['processing_job_id','processing_lease_token','processing_job_attempt']as$key)$row[$key]=null;$row['completed_at']??=gmdate('c');}
                if($table==='lorkhan_internal.profile_evolution_progress'){$row['manual_requested']=false;$row['manual_request_id']=null;$row['manual_requested_at']=null;$row['manual_attempts']=0;}
                if($table==='lorkhan_internal.profile_evolution_events'){
                    // Retained progress can outlive pruned event rows. Negative IDs cannot collide with future events.
                    $row['rowid']=$serial['public.eventlog']['rowid'][(string)$source['rowid']]??-abs((int)$source['rowid']);
                }
                if($table==='lorkhan_internal.action_intents'){$row['state']='terminal';$row['expires_at']=gmdate('c');$row['followup_enabled']=false;$row['followup_actions_allowed']=false;}
                if($table==='lorkhan_internal.dialogue_utterances'&&$row['delivery_state']==='pending'){$row['delivery_state']='expired';$row['delivery_deadline_at']=gmdate('c');}
                if($table==='lorkhan_internal.profiles' && !$this->localSave)$row['core_profile_id']=$core['core_profile_id'];
                if($table==='lorkhan_internal.profile_revisions'){$row['content']=$this->portableProfile($row['content']);}
                if($table==='lorkhan_internal.playthroughs')$row['name']=mb_substr($row['name'],0,180).' (copy '.$uuid[$document['source']['playthrough_id']].')';
                foreach(['derivation_key','projection_key']as$key)if(isset($row[$key]))$row[$key]='archive:'.$uuid[$document['source']['playthrough_id']].':'.hash('sha256',$row[$key]);
                $rows[]=[$table,$row];
            }
            // Nondeferrable dependencies are inserted in bounded passes; cycles or missing parents fail atomically.
            $pending=$rows;
            while($pending!==[]){$next=[];$progress=0;$dependencyError=null;foreach($pending as[$table,$row]){$this->checkBudget();
                $this->db->exec('SAVEPOINT archive_row');
                try{$columns=implode(',',array_keys($meta[$table]['columns']));$q=$this->db->prepare('INSERT INTO '.$table.'('.$columns.') OVERRIDING SYSTEM VALUE SELECT '.$columns.' FROM jsonb_populate_record(NULL::'.$table.',CAST(:row AS jsonb))');$q->execute(['row'=>$this->canonical($row)]);$this->db->exec('RELEASE SAVEPOINT archive_row');++$progress;}
                catch(\PDOException$error){$this->db->exec('ROLLBACK TO SAVEPOINT archive_row');$this->db->exec('RELEASE SAVEPOINT archive_row');if($error->getCode()!=='23503')throw$error;$dependencyError=$error;$next[]=[$table,$row];}
            }if($progress===0)throw new RuntimeException('archive_dependency_incomplete',0,$dependencyError);$pending=$next;}
            $this->db->exec('SET CONSTRAINTS ALL IMMEDIATE');
            // Rebuild scoped read models while revision/delivery triggers remain disabled.
            foreach (['memory_records'=>'herika_project_memory','knowledge_documents'=>'herika_project_knowledge'] as $table=>$trigger) {
                $this->db->exec('ALTER TABLE lorkhan_internal.'.$table.' ENABLE TRIGGER '.$trigger);
                $q=$this->db->prepare('UPDATE lorkhan_internal.'.$table.' SET content=content WHERE playthrough_id=:world');
                $q->execute(['world'=>$uuid[$document['source']['playthrough_id']]]);
            }
            // Project each saved NPC revision, leaving the source's current revision selected.
            foreach($document['tables']['lorkhan_internal.profiles'] as $profile){
                if((((array)$profile['actor_identity'])['kind']??'')==='player')continue;
                $id=$uuid[$profile['profile_id']];
                $revisions=$this->db->prepare('SELECT revision FROM profile_revisions WHERE profile_id=:p ORDER BY revision');$revisions->execute(['p'=>$id]);
                $select=$this->db->prepare('UPDATE profiles SET current_revision=:r WHERE profile_id=:p');
                $project=$this->db->prepare('SELECT lorkhan_internal.sync_profile_projection(CAST(:profile AS text))');
                foreach($revisions->fetchAll(PDO::FETCH_COLUMN) as $revision){$select->execute(['r'=>$revision,'p'=>$id]);$project->execute(['profile'=>$id]);}
                $select->execute(['r'=>$profile['current_revision'],'p'=>$id]);$project->execute(['profile'=>$id]);
            }
            foreach($disabled as[$table,$trigger])$this->db->exec('ALTER TABLE '.$table.' ENABLE TRIGGER '.$trigger);
            if ($this->localSave && isset($document['local_state'])) {
                // Remap owned graph IDs only. Shared Oghma/catalogue IDs may also appear in prompt JSON.
                $ownedIds=[$document['source']['installation_id']=>$installation,$document['source']['playthrough_id']=>$uuid[$document['source']['playthrough_id']]];
                foreach($document['tables'] as $table=>$sourceRows) foreach($sourceRows as $source) foreach($meta[$table]['primary'] as $column){
                    $id=$source[$column];if(is_string($id)&&isset($uuid[$id]))$ownedIds[$id]=$uuid[$id];
                }
                $state=$this->remapJson($document['local_state'],$ownedIds);
                foreach($state['tables']['public.moods_issued'] as &$mood)$mood['rowid']=$serial['public.speech']['rowid'][(string)$mood['rowid']]??$mood['rowid'];
                unset($mood);
                (new PlaythroughLocalState($this->db))->stage($installation,$uuid[$document['source']['playthrough_id']],$state);
            }
            if ($owns) $this->db->commit();return['playthrough_id'=>$uuid[$document['source']['playthrough_id']],
                'profile_id'=>$uuid[$document['tables']['lorkhan_internal.playthroughs'][0]['profile_id']],
                'active'=>false,'row_counts'=>array_map('count',$document['tables']),'limitations'=>$this->limitations()];
        }catch(\Throwable$error){if($owns && $this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    private function remapJson(mixed $value,array $ids):mixed
    {
        if(is_string($value))return$ids[$value]??$value;
        if(is_object($value))return(object)$this->remapJson((array)$value,$ids);
        if(is_array($value)){$mapped=[];foreach($value as$key=>$item)$mapped[$ids[$key]??$key]=$this->remapJson($item,$ids);return$mapped;}return$value;
    }

    private function portableProfile(array|object $content):array|object
    {
        if ($this->localSave) return $content;
        if(is_object($content))return(object)$this->portableProfile((array)$content);
        unset($content['routing'],$content['portrait']);
        foreach($content as$key=>$value){if(preg_match('/(?:api.?key|secret|password|authorization|token|configuration_id)$/i',(string)$key)||in_array($key,['audio','media','media_id','audio_url','audio_path','storage_path','file_path'],true))unset($content[$key]);elseif(is_array($value)||is_object($value))$content[$key]=$this->portableProfile($value);}
        return$content;
    }

    private function checkBudget():void
    {
        if(hrtime(true)>$this->deadline)throw new RuntimeException('archive_timeout');
    }

    private function decode(string $json):array
    {
        if(strlen($json)>($this->localSave?self::MAX_LOCAL_BYTES:self::MAX_BYTES))throw new RuntimeException('archive_too_large');
        try{$object=json_decode($json,false,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('invalid_archive');}
        if(!is_object($object))throw new RuntimeException('invalid_archive');$document=(array)$object;
        foreach(['source','shared_profiles','tables']as$key)if(isset($document[$key])&&is_object($document[$key]))$document[$key]=(array)$document[$key];
        if(isset($document['local_state']))$document['local_state']=PlaythroughLocalState::decode(json_encode($document['local_state'],JSON_THROW_ON_ERROR));
        if(is_array($document['tables']??null))foreach($document['tables']as&$rows)if(is_array($rows))$rows=array_map(static fn($row)=>is_object($row)?(array)$row:$row,$rows);unset($rows);
        return$document;
    }

    private function validate(array $document):void
    {
        $keys=array_keys($document);sort($keys);$expected=['created_at','format','limitations','schema_sha256','sha256','shared_profiles','source','tables','version'];sort($expected);
        if ($this->localSave && isset($document['local_state'])) {$expected[]='local_state';sort($expected);}
        if($keys!==$expected||$document['format']!==($this->localSave?'lorkhan.playthrough-save':'lorkhan.playthrough-archive')||$document['version']!==1)throw new RuntimeException('invalid_archive');
        $copy=$document;unset($copy['sha256']);
        if(!is_string($document['sha256'])||!hash_equals(hash('sha256',$this->canonical($copy)),$document['sha256']))throw new RuntimeException('archive_checksum_mismatch');
        $meta=$this->metadata();
        if($document['schema_sha256']!==$this->schemaHash($meta)){
            // Both branches shipped archives: accept only their exact prior table/column shapes.
            $preExpanded=array_diff_key($meta,array_fill_keys(array_merge(array_map(static fn($t)=>'lorkhan_internal.'.$t,self::ADDED_TABLES),['public.audit_request','public.log']),true));
            $prePlugin=$meta;
            unset($prePlugin['lorkhan_internal.profiles']['columns']['plugin_extended_data'],
                $prePlugin['lorkhan_internal.profile_revisions']['columns']['plugin_extended_data']);
            $preBoth=array_diff_key($prePlugin,array_diff_key($meta,$preExpanded));
            $legacy=[];
            foreach([$preExpanded,$prePlugin,$preBoth]as$candidate){
                if($document['schema_sha256']===$this->schemaHash($candidate)){$legacy=$candidate;break;}
            }
            if($document['schema_sha256']!==$this->schemaHash($legacy))throw new RuntimeException('archive_schema_mismatch');
            $meta=$legacy;
        }
        if(!is_array($document['source'])||count($document['source'])!==2||!Uuid::isValid($document['source']['installation_id']??'')||!Uuid::isValid($document['source']['playthrough_id']??''))throw new RuntimeException('invalid_archive_scope');
        if(!is_array($document['tables']))throw new RuntimeException('invalid_archive');
        if(!is_array($document['shared_profiles']))throw new RuntimeException('invalid_archive');foreach($document['shared_profiles']as$id=>$kind)if(!\LorkhanServer\Domain\ProfileId::isValid($id)||$kind!=='narrator')throw new RuntimeException('archive_shared_profile');
        $names=array_keys($document['tables']);sort($names);$allowed=array_keys($meta);sort($allowed);if($names!==$allowed)throw new RuntimeException('archive_table_mismatch');
        if(isset($document['local_state']))(new PlaythroughLocalState($this->db))->validate($document['local_state'],$document['source']['installation_id']);
        $count=0;$profileIds=[];
        foreach($document['tables']as$table=>$rows){
            if(!is_array($rows)||!array_is_list($rows))throw new RuntimeException('invalid_archive_rows');
            $count+=count($rows);if($count>self::MAX_ROWS)throw new RuntimeException('archive_too_large');
            $columns=array_keys($meta[$table]['columns']);sort($columns);$seen=[];
            foreach($rows as$row){
                if(!is_array($row)||array_is_list($row))throw new RuntimeException('invalid_archive_row');
                $actual=array_keys($row);sort($actual);if($actual!==$columns)throw new RuntimeException('archive_column_mismatch');
                foreach(['installation_id','playthrough_id']as$scope)if(isset($row[$scope])&&$row[$scope]!==$document['source'][$scope])throw new RuntimeException('archive_scope_mismatch');
                $key=$this->rowKey($row,$meta[$table]);if(isset($seen[$key]))throw new RuntimeException('archive_duplicate_row');$seen[$key]=true;
                if($table==='lorkhan_internal.profiles'){
                    if(!\LorkhanServer\Domain\ProfileId::isValid($row['profile_id']))throw new RuntimeException('archive_profile_key');
                    if(str_starts_with($row['profile_id'],'ref:')&&!str_starts_with($row['profile_id'],'ref:'.$document['source']['installation_id'].':'.$document['source']['playthrough_id'].':'))throw new RuntimeException('archive_scope_mismatch');
                    if($row['playthrough_id']!==$document['source']['playthrough_id']||in_array(((array)$row['actor_identity'])['kind']??'', ['narrator','template'],true))throw new RuntimeException('archive_shared_profile');
                    $profileIds[$row['profile_id']]=true;
                }
            }
        }
        if(count($document['tables']['lorkhan_internal.playthroughs'])!==1)throw new RuntimeException('archive_scope_mismatch');
        $owner=$document['tables']['lorkhan_internal.playthroughs'][0]['profile_id'];if(!isset($profileIds[$owner]))throw new RuntimeException('archive_owner_missing');
        foreach($document['shared_profiles']as$id=>$kind)if(isset($profileIds[$id])||in_array($id,$document['source'],true))throw new RuntimeException('archive_shared_profile');
        foreach(['profile_revisions','actor_profile_bindings','sessions']as$name)foreach($document['tables']['lorkhan_internal.'.$name]as$row)if(!isset($profileIds[$row['profile_id']]))throw new RuntimeException('archive_owner_missing');
        $references=[];
        foreach($meta as$definition)foreach($definition['foreign']as$fk)if(isset($meta[$fk['table']])){
            $index=$fk['table'].':'.implode(',',$fk['references']);if(isset($references[$index]))continue;$references[$index]=[];
            foreach($document['tables'][$fk['table']]as$parent)$references[$index][$this->canonical(array_map(static fn($column)=>$parent[$column],$fk['references']))]=true;
        }
        foreach($document['tables']as$table=>$rows)foreach($rows as$row){$this->checkBudget();
            foreach($meta[$table]['columns']as$column=>$definition){
                $value=$row[$column];if($value===null){if(!$definition['nullable'])throw new RuntimeException('archive_invalid_type');continue;}
                $type=$definition['type'];
                if(($type==='uuid'&&(!is_string($value)||!Uuid::isValid($value)))||($type==='bool'&&!is_bool($value))
                    ||(in_array($type,['int2','int4','int8'],true)&&!is_int($value))
                    ||(str_starts_with($type,'_')&&(!is_array($value)||!array_is_list($value))))throw new RuntimeException('archive_invalid_type');
            }
            if(array_key_exists('playthrough_id',$row)&&$row['playthrough_id']!==$document['source']['playthrough_id'])throw new RuntimeException('archive_scope_mismatch');
            foreach($meta[$table]['foreign']as$fk){
                $values=array_map(static fn($column)=>$row[$column],$fk['columns']);if(in_array(null,$values,true))continue;
                if($fk['table']==='lorkhan_internal.profiles'&&isset($document['shared_profiles'][$row[$fk['columns'][0]]??'']))continue;
                if(!isset($meta[$fk['table']]))continue;
                if(!isset($references[$fk['table'].':'.implode(',',$fk['references'])][$this->canonical($values)]))throw new RuntimeException('archive_dependency_incomplete');
            }
        }
    }

    private function collect(array &$rows,\PDOStatement $query,array $definition):void
    {
        while(($json=$query->fetchColumn())!==false){$row=(array)json_decode($json,false,64,JSON_THROW_ON_ERROR);$key=$this->rowKey($row,$definition);if(isset($rows[$key])&&$this->canonical($rows[$key])!==$this->canonical($row))throw new RuntimeException('archive_duplicate_row');if(!isset($rows[$key]))$this->collectedBytes+=strlen($json);$rows[$key]=$row;if(count($rows)>self::MAX_ROWS||$this->collectedBytes>($this->localSave?self::MAX_LOCAL_BYTES:self::MAX_BYTES))throw new RuntimeException('archive_too_large');}
    }

    private function rowKey(array $row,array $definition):string
    {
        return $this->canonical(array_map(static fn($column)=>$row[$column]??null,$definition['primary']));
    }

    /** The fixed allowlist supplies identifiers; PostgreSQL supplies exact types, keys and dependencies. */
    private function metadata():array
    {
        if($this->metadata!==null)return$this->metadata;$result=[];
        foreach(self::tableNames($this->localSave)as$table){
            [$schema,$name]=explode('.',$table);
            $q=$this->db->prepare('SELECT column_name,udt_name,is_nullable,column_default,is_identity FROM information_schema.columns WHERE table_schema=:schema AND table_name=:table ORDER BY ordinal_position');
            $q->execute(['schema'=>$schema,'table'=>$name]);$columns=[];foreach($q->fetchAll()as$row)$columns[$row['column_name']]=['type'=>$row['udt_name'],'nullable'=>$row['is_nullable']==='YES','default'=>$row['column_default'],'identity'=>$row['is_identity']==='YES'];
            if($columns===[])throw new RuntimeException('archive_schema_unavailable');
            $q=$this->db->prepare("SELECT c.contype, c.confrelid::regclass::text AS target,
                (SELECT json_agg(a.attname ORDER BY k.ordinality) FROM unnest(c.conkey) WITH ORDINALITY k(num,ordinality) JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.num) AS columns,
                (SELECT json_agg(a.attname ORDER BY k.ordinality) FROM unnest(c.confkey) WITH ORDINALITY k(num,ordinality) JOIN pg_attribute a ON a.attrelid=c.confrelid AND a.attnum=k.num) AS references
                FROM pg_constraint c WHERE c.conrelid=CAST(:table AS regclass) AND c.contype IN ('p','f') ORDER BY c.conname");
            $q->execute(['table'=>$table]);$primary=[];$foreign=[];foreach($q->fetchAll()as$row){$keys=json_decode($row['columns'],true);if($row['contype']==='p')$primary=$keys;else{$target=$row['target'];if(!str_contains($target,'.')){$lookup=$this->db->prepare('SELECT n.nspname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=CAST(:name AS regclass)');$lookup->execute(['name'=>$target]);$target=$lookup->fetchColumn().'.'.$target;}$foreign[]=['table'=>$target,'columns'=>$keys,'references'=>json_decode($row['references'],true)];}}
            if($primary===[]){$q=$this->db->prepare("SELECT json_agg(a.attname ORDER BY key.ordinality) FROM pg_index i CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY key(num,ordinality) JOIN pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=key.num WHERE i.indrelid=CAST(:table AS regclass) AND i.indisunique AND i.indpred IS NULL GROUP BY i.indexrelid ORDER BY i.indexrelid LIMIT 1");$q->execute(['table'=>$table]);$keys=$q->fetchColumn();if($keys!==false)$primary=json_decode($keys,true);}
            if(in_array($table,['public.speech','public.responselog','lorkhan_internal.speech_metadata','lorkhan_internal.responselog_metadata'],true)){
                $primary=['rowid'];$base=str_contains($table,'responselog')?'responselog':'speech';
                $foreign[]=['table'=>'lorkhan_internal.'.$base,'columns'=>['rowid'],'references'=>['rowid']];
            }
            if(str_starts_with($table,'public.')&&in_array(substr($table,7),array_merge(self::PUBLIC_TABLES,['audit_request','log']),true))$primary=['rowid'];
            $projection=['book_metadata'=>'books','quest_metadata'=>'quests','questlog_metadata'=>'questlog','currentmission_metadata'=>'currentmission','eventlog_metadata'=>'eventlog','action_issued_metadata'=>'actions_issued','diarylog_metadata'=>'diarylog','audit_request_metadata'=>'audit_request','log_metadata'=>'log'];
            if(isset($projection[$name])&&!array_filter($foreign,static fn($fk)=>$fk['columns']===['rowid']))$foreign[]=['table'=>'public.'.$projection[$name],'columns'=>['rowid'],'references'=>['rowid']];
            if($primary===[])throw new RuntimeException('archive_table_without_key:'.$table);$result[$table]=['columns'=>$columns,'primary'=>$primary,'foreign'=>$foreign];
        }
        return$this->metadata=$result;
    }

    private function schemaHash(array $metadata):string{return hash('sha256',$this->canonical($metadata));}

    private function canonical(mixed $value):string
    {
        $sort=function(mixed $item)use(&$sort):mixed{if(is_object($item))return(object)$sort((array)$item);if(!is_array($item))return$item;if(!array_is_list($item))ksort($item);foreach($item as$key=>$child)$item[$key]=$sort($child);return$item;};
        return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    private function limitations():array
    {
        if ($this->localSave) return ['Gameplay only; shared settings and connectors remain unchanged.',
            'No game saves, media, active sessions, queued jobs or executable deliveries are restored.',
            'Local Core Profile assignments, summaries and evolution progress are preserved. Embeddings regenerate.',
            'Oghma, gameplay settings and active compatibility views are applied on the next character load.'];
        return ['No game saves, media, credentials, connectors or global settings.','No active sessions, queued jobs, pending delivery or executable actions are restored.',
            'Core and Narrator references use the destination installation; connector routing is not transferred.','Derived summaries, embeddings and runtime scheduling are not imported; normal workflows must regenerate them.'];
    }
}
