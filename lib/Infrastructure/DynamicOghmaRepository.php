<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use InvalidArgumentException;
use LorkhanServer\Application\DeterministicRetrieval;
use PDO;
use Throwable;

/** Own quest-stage rules and their source-linked, playthrough-scoped knowledge patches. */
final class DynamicOghmaRepository
{
    public const CSV_FIELDS=['id_quest','stage','topic','topic_desc','knowledge_class','topic_desc_basic','knowledge_class_basic','tags','category'];

    public function __construct(private readonly PDO $db) {}

    /** Build a read-only first-turn preview; accepted source events persist precisely this frozen patch plan. */
    public function plan(array $message,bool $gameData=false):array
    {
        $entries=$gameData?($message['payload']['entries']??[]):($message['payload']['context']['journal']['items']??[]);
        if(!is_array($entries)||$entries===[])return[];
        $installation=(string)$message['installation_id'];$playthrough=(string)$message['playthrough_id'];
        $source=(string)$message[$gameData?'request_id':'message_id'];$observations=[];
        foreach(array_slice($entries,0,128)as$entry){
            if(!is_array($entry))continue;
            $quest=$entry[$gameData?'journal_id':'quest_id']??null;$stage=$entry['stage']??null;
            if(!is_string($quest)||$quest===''||strlen($quest)>256||(!is_int($stage)&&!is_string($stage))||preg_match('/^[0-9]{1,10}$/D',(string)$stage)!==1||(int)$stage>2147483647)continue;
            $quest=mb_strtolower(trim($quest),'UTF-8');$observations[$quest."\0".(int)$stage]=['quest'=>$quest,'stage'=>(int)$stage];
        }
        if($observations===[])return[];
        $query=$this->db->prepare("SELECT r.* FROM jsonb_array_elements(CAST(:observations AS jsonb)) WITH ORDINALITY observation(value,position) JOIN oghma_dynamic r ON r.id_quest=observation.value->>'quest' AND r.stage=(observation.value->>'stage')::integer WHERE r.installation_id=:installation AND r.deleted_at IS NULL AND NOT EXISTS(SELECT 1 FROM oghma_dynamic_applications a WHERE a.installation_id=r.installation_id AND a.playthrough_id=:playthrough AND a.rule_id=r.id AND a.revision=r.revision) ORDER BY observation.position,r.topic,r.id");
        $query->execute(['installation'=>$installation,'playthrough'=>$playthrough,'observations'=>json_encode(array_values($observations),JSON_THROW_ON_ERROR)]);
        $baseQuery=$this->db->prepare("SELECT document_id AS id,title,content,aliases,topic_desc_basic,knowledge_class,knowledge_class_basic,tags,category FROM knowledge_documents WHERE installation_id=:installation AND profile_id IS NULL AND (playthrough_id IS NULL OR playthrough_id=:playthrough) AND lower(topic)=:topic AND deleted_at IS NULL ORDER BY (provenance->>'source' IS DISTINCT FROM 'factory-oghma') DESC,(playthrough_id IS NOT NULL) DESC,created_at DESC,document_id DESC LIMIT 1");
        $plan=[];$topics=[];
        foreach($query->fetchAll()as$rule){
                $topic=$rule['topic'];
                if(!isset($topics[$topic])){
                    $baseQuery->execute(['installation'=>$installation,'playthrough'=>$playthrough,'topic'=>$topic]);
                    $topics[$topic]=$baseQuery->fetch()?:['title'=>str_replace('_',' ',$topic),'content'=>'','aliases'=>'','topic_desc_basic'=>'','knowledge_class'=>'','knowledge_class_basic'=>'','tags'=>'','category'=>''];
                }
                $document=$topics[$topic];$patch=[];
                foreach(['topic_desc'=>'content','knowledge_class'=>'knowledge_class','topic_desc_basic'=>'topic_desc_basic','knowledge_class_basic'=>'knowledge_class_basic','tags'=>'tags','category'=>'category']as$field=>$target){
                    $patch[$field]=$rule[$field];
                    if($rule[$field]!=='')$document[$target]=$rule[$field]==='clearall'?'':$rule[$field];
                }
                // The source is part of the identity so a rule retired with an abandoned save branch can apply again.
                $document['id']=Uuid::deterministicV4('dynamic-oghma|'.$installation.'|'.$playthrough.'|'.$rule['id'].'|'.$rule['revision'].'|'.$source);
                $document['topic']=$topic;$document['content_sha256']=hash('sha256',$document['content']);
                $document['lexical_terms']=DeterministicRetrieval::terms(implode(' ',[$topic,$document['title'],$document['aliases'],$document['content'],$document['topic_desc_basic'],$document['tags']]));
                $document['provenance']=['source'=>'dynamic-oghma','rule_id'=>$rule['id'],'revision'=>(int)$rule['revision'],'quest_id'=>$rule['id_quest'],'stage'=>(int)$rule['stage'],'source_event_id'=>$source];
                $plan[]=['rule_id'=>$rule['id'],'revision'=>(int)$rule['revision'],'patch'=>$patch,'document'=>$document];$topics[$topic]=$document;
        }
        return$plan;
    }

    /** Replace matching topic rows in a prompt preview without writing any game-derived state. */
    public static function overlay(array $rows,array $plan):array
    {
        if($plan===[])return$rows;
        $byTopic=[];foreach($rows as$row)$byTopic[mb_strtolower($row['topic'],'UTF-8')]=$row;
        foreach($plan as$entry){
            $topic=$entry['document']['topic'];
            // The SQL resolver gives an NPC's story override precedence over shared story knowledge.
            if(!empty($byTopic[$topic]['story_profile_override']))continue;
            $byTopic[$topic]=$entry['document'];
        }
        return array_values($byTopic);
    }

    /** Persist only after the owning source event exists; one rule revision applies once per playthrough. */
    public function apply(string $installation,string $playthrough,string $source,array $plan):void
    {
        if($plan===[])return;
        $this->transaction(function()use($installation,$playthrough,$source,$plan):void{
            $this->lockInstallation($installation);
            $owner=$this->db->prepare('SELECT 1 FROM source_events e JOIN sessions s ON s.session_id=e.session_id WHERE e.source_event_id=:source AND e.installation_id=:installation AND s.playthrough_id=:playthrough');
            $owner->execute(['source'=>$source,'installation'=>$installation,'playthrough'=>$playthrough]);
            if(!$owner->fetchColumn())throw new InvalidArgumentException('invalid_dynamic_oghma_source');
            foreach($plan as$entry){
                $already=$this->db->prepare('SELECT 1 FROM oghma_dynamic_applications WHERE installation_id=:installation AND playthrough_id=:playthrough AND rule_id=:rule AND revision=:revision');
                $scope=['installation'=>$installation,'playthrough'=>$playthrough,'rule'=>$entry['rule_id'],'revision'=>$entry['revision']];$already->execute($scope);
                if($already->fetchColumn())continue;
                $ruleOwner=$this->db->prepare('SELECT 1 FROM oghma_dynamic WHERE installation_id=:installation AND id=:rule');$ruleOwner->execute(['installation'=>$installation,'rule'=>$entry['rule_id']]);
                if(!$ruleOwner->fetchColumn())throw new InvalidArgumentException('invalid_dynamic_oghma_rule');
                $d=$entry['document'];
                $superseded=$this->db->prepare('UPDATE knowledge_documents SET deleted_at=clock_timestamp() WHERE installation_id=:installation AND playthrough_id=:playthrough AND profile_id IS NULL AND lower(topic)=:topic AND deleted_at IS NULL RETURNING document_id');
                $superseded->execute(['installation'=>$installation,'playthrough'=>$playthrough,'topic'=>$d['topic']]);$supersededIds=$superseded->fetchAll(PDO::FETCH_COLUMN);
                if(count($supersededIds)>1)throw new InvalidArgumentException('ambiguous_dynamic_oghma_topic');
                // Rollback provenance: the exact document this patch replaced and the content it wrote.
                $d['provenance']+=['supersedes_document_id'=>$supersededIds[0]??null,'document_sha256'=>self::fingerprint($d)];
                $terms='{'.implode(',',array_map(static fn(string $term):string=>'"'.str_replace(['\\','"'],['\\\\','\\"'],$term).'"',$d['lexical_terms'])).'}';
                $this->db->prepare('INSERT INTO knowledge_documents(document_id,installation_id,playthrough_id,profile_id,title,content,content_sha256,lexical_terms,provenance,created_at,topic,aliases,topic_desc_basic,knowledge_class,knowledge_class_basic,tags,category) VALUES(:id,:installation,:playthrough,NULL,:title,:content,:sha,CAST(:terms AS text[]),CAST(:provenance AS jsonb),clock_timestamp(),:topic,:aliases,:basic,:advanced_class,:basic_class,:tags,:category)')->execute([
                    'id'=>$d['id'],'installation'=>$installation,'playthrough'=>$playthrough,'title'=>$d['title'],'content'=>$d['content'],'sha'=>$d['content_sha256'],'terms'=>$terms,
                    'provenance'=>json_encode($d['provenance'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'topic'=>$d['topic'],'aliases'=>$d['aliases'],'basic'=>$d['topic_desc_basic'],
                    'advanced_class'=>$d['knowledge_class'],'basic_class'=>$d['knowledge_class_basic'],'tags'=>$d['tags'],'category'=>$d['category']]);
                $this->db->prepare('INSERT INTO oghma_dynamic_applications(installation_id,playthrough_id,rule_id,revision,source_event_id,document_id,patch) VALUES(:installation,:playthrough,:rule,:revision,:source,:document,CAST(:patch AS jsonb))')->execute($scope+[
                    'source'=>$source,'document'=>$d['id'],'patch'=>json_encode($entry['patch'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    /**
     * Undo patches whose source left the live save branch, newest first. Only an unmodified active patch document is
     * retired; the document it replaced is restored and the rule may apply again. Edited, deleted, later-superseded and
     * pre-provenance patches are left untouched and counted as skipped. The caller holds the loaded-save transaction.
     */
    public function rollback(string $installation,string $playthrough):array
    {
        $counts=['dynamic_oghma'=>0,'dynamic_oghma_skipped'=>0];
        $this->lockInstallation($installation);$scope=['installation'=>$installation,'playthrough'=>$playthrough];
        $applications=$this->db->prepare('SELECT a.rule_id,a.revision,a.source_event_id,a.document_id FROM oghma_dynamic_applications a
            JOIN source_events e ON e.source_event_id=a.source_event_id
            WHERE a.installation_id=:installation AND a.playthrough_id=:playthrough
            AND (EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=e.source_event_id)
                OR EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=e.turn_id))
            ORDER BY a.applied_at DESC,a.rule_id DESC,a.revision DESC FOR UPDATE OF a');
        $applications->execute($scope);
        $document=$this->db->prepare('SELECT * FROM knowledge_documents WHERE document_id=:id AND installation_id=:installation AND playthrough_id=:playthrough AND profile_id IS NULL FOR UPDATE');
        $retire=$this->db->prepare('UPDATE knowledge_documents SET deleted_at=clock_timestamp() WHERE document_id=:id AND deleted_at IS NULL');
        $restore=$this->db->prepare('UPDATE knowledge_documents SET deleted_at=NULL WHERE document_id=:id AND deleted_at IS NOT NULL');
        $forget=$this->db->prepare('DELETE FROM oghma_dynamic_applications WHERE installation_id=:installation AND playthrough_id=:playthrough AND rule_id=:rule AND revision=:revision');
        foreach($applications->fetchAll()as$application){
            $document->execute($scope+['id'=>$application['document_id']]);$current=$document->fetch();
            $provenance=$current?json_decode((string)$current['provenance'],true,32,JSON_THROW_ON_ERROR):[];
            $previous=$provenance['supersedes_document_id']??null;
            if(!$current||$current['deleted_at']!==null||($provenance['source']??null)!=='dynamic-oghma'
                ||($provenance['rule_id']??null)!==$application['rule_id']||($provenance['revision']??null)!==(int)$application['revision']
                ||strtolower((string)($provenance['source_event_id']??''))!==strtolower((string)$application['source_event_id'])||!array_key_exists('supersedes_document_id',$provenance)
                ||!is_string($provenance['document_sha256']??null)||!hash_equals($provenance['document_sha256'],self::fingerprint($current))
                ||($previous!==null&&(!is_string($previous)||!Uuid::isValid($previous)))){$counts['dynamic_oghma_skipped']++;continue;}
            if($previous!==null){
                $document->execute($scope+['id'=>$previous]);$replaced=$document->fetch();
                if(!$replaced||$replaced['deleted_at']===null||mb_strtolower($replaced['topic'],'UTF-8')!==mb_strtolower($current['topic'],'UTF-8')){$counts['dynamic_oghma_skipped']++;continue;}
            }
            $retire->execute(['id'=>$current['document_id']]);
            if($previous!==null)$restore->execute(['id'=>$previous]);
            $forget->execute($scope+['rule'=>$application['rule_id'],'revision'=>$application['revision']]);
            $counts['dynamic_oghma']++;
        }
        return$counts;
    }

    /** Hash every prompt-visible field so rollback can prove a patch document has not been edited since it was written. */
    private static function fingerprint(array $document):string
    {
        $fields=[];foreach(['topic','title','content','aliases','topic_desc_basic','knowledge_class','knowledge_class_basic','tags','category']as$field)$fields[]=(string)$document[$field];
        return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }

    public function catalog(string $installation,array $filters=[]):array
    {
        if($installation==='')return['rows'=>[],'total'=>0,'pages'=>1,'page'=>1,'categories'=>[]];
        $page=max(1,(int)($filters['page']??1));$size=100;$params=['installation'=>$installation];
        $where='installation_id=:installation AND deleted_at IS NULL';
        $category=trim((string)($filters['category']??''));
        if($category!==''){$where.=' AND category=:category';$params['category']=$category;}
        $count=$this->db->prepare('SELECT count(*) FROM oghma_dynamic WHERE '.$where);$count->execute($params);$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);
        $rows=$this->db->prepare('SELECT * FROM oghma_dynamic WHERE '.$where.' ORDER BY id_quest,stage,topic,id LIMIT '.$size.' OFFSET '.(($page-1)*$size));$rows->execute($params);
        $categories=$this->db->prepare("SELECT DISTINCT category FROM oghma_dynamic WHERE installation_id=:installation AND deleted_at IS NULL AND category<>'' ORDER BY category");$categories->execute(['installation'=>$installation]);
        return['rows'=>$rows->fetchAll(),'total'=>$total,'pages'=>$pages,'page'=>$page,'categories'=>$categories->fetchAll(PDO::FETCH_COLUMN)];
    }

    /** Validate the complete batch before its transaction; CSV upserts share the editor's strict field contract. */
    public function save(string $installation,array $rows,bool $csvImport=false):int
    {
        if($rows===[]||count($rows)>1000)throw new InvalidArgumentException('invalid_dynamic_oghma_batch');
        $validated=[];$seen=[];
        foreach($rows as$row){
            if(!is_array($row))throw new InvalidArgumentException('invalid_dynamic_oghma_row');
            $values=[];
            foreach(['id_quest'=>256,'topic'=>256,'topic_desc'=>131072,'knowledge_class'=>4096,'topic_desc_basic'=>131072,'knowledge_class_basic'=>4096,'tags'=>4096,'category'=>128]as$field=>$max){
                $value=$row[$field]??'';
                if(!is_string($value)||strlen($value)>$max||str_contains($value,"\0")||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException('invalid_dynamic_oghma_'.$field);
                $value=trim($value);
                if(in_array($field,['id_quest','topic'],true)){
                    $value=mb_strtolower($value,'UTF-8');
                    if($value===''||strlen($value)>$max)throw new InvalidArgumentException('invalid_dynamic_oghma_'.$field);
                }
                $values[$field]=$value;
            }
            $stage=$row['stage']??null;
            if((!is_int($stage)&&(!is_string($stage)||preg_match('/^(0|[1-9][0-9]{0,9})$/D',$stage)!==1))||(int)$stage<0||(int)$stage>2147483647)throw new InvalidArgumentException('invalid_dynamic_oghma_stage');
            $values['stage']=(int)$stage;
            $key=$values['id_quest']."\0".$values['stage']."\0".$values['topic'];
            if(isset($seen[$key]))throw new InvalidArgumentException('duplicate_dynamic_oghma_key');$seen[$key]=true;
            $id=(string)($row['id']??'');$revision=(string)($row['revision']??'');
            if($id!==''&&(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$id)!==1||preg_match('/^[1-9][0-9]{0,8}$/D',$revision)!==1))throw new InvalidArgumentException('invalid_dynamic_oghma_revision');
            if($csvImport&&$id!=='')throw new InvalidArgumentException('invalid_dynamic_oghma_import');
            $validated[]=['id'=>$id,'revision'=>(int)$revision,'values'=>$values];
        }
        return$this->transaction(function()use($installation,$validated,$csvImport):int{
            $this->lockInstallation($installation);
            foreach($validated as$row){
                $values=$row['values'];$params=['installation'=>$installation]+$values;
                $duplicate=$this->db->prepare('SELECT id FROM oghma_dynamic WHERE installation_id=:installation AND id_quest=:quest AND stage=:stage AND topic=:topic AND deleted_at IS NULL');
                $duplicate->execute(['installation'=>$installation,'quest'=>$values['id_quest'],'stage'=>$values['stage'],'topic'=>$values['topic']]);$duplicateId=$duplicate->fetchColumn();
                if(!$csvImport&&$duplicateId!==false&&$duplicateId!==$row['id'])throw new InvalidArgumentException('duplicate_dynamic_oghma_key');
                $id=$row['id']!==''?$row['id']:($csvImport&&$duplicateId!==false?(string)$duplicateId:'');
                if($id!==''){
                    $sql='UPDATE oghma_dynamic SET '.implode(',',array_map(static fn(string $field):string=>$field.'=:'.$field,self::CSV_FIELDS)).',revision=revision+1,updated_at=clock_timestamp() WHERE installation_id=:installation AND id=:id AND deleted_at IS NULL';
                    $params['id']=$id;
                    if(!$csvImport){$sql.=' AND revision=:revision';$params['revision']=$row['revision'];}
                    $update=$this->db->prepare($sql);$update->execute($params);
                    if($update->rowCount()!==1)throw new InvalidArgumentException('dynamic_oghma_revision_conflict');
                }else{
                    $params['id']=Uuid::v4();
                    $this->db->prepare('INSERT INTO oghma_dynamic(id,installation_id,'.implode(',',self::CSV_FIELDS).') VALUES(:id,:installation,'.implode(',',array_map(static fn(string $field):string=>':'.$field,self::CSV_FIELDS)).')')->execute($params);
                }
            }
            return count($validated);
        });
    }

    /** Deleting rules stops future applications; existing story knowledge is retained. */
    public function delete(string $installation,?string $id=null,?int $revision=null):int
    {
        return$this->transaction(function()use($installation,$id,$revision):int{
            $this->lockInstallation($installation);$params=['installation'=>$installation];
            $sql='UPDATE oghma_dynamic SET deleted_at=clock_timestamp(),revision=revision+1 WHERE installation_id=:installation AND deleted_at IS NULL';
            if($id!==null){$sql.=' AND id=:id AND revision=:revision';$params+=['id'=>$id,'revision'=>$revision];}
            $delete=$this->db->prepare($sql);$delete->execute($params);$count=$delete->rowCount();
            if($id!==null&&$count!==1)throw new InvalidArgumentException('dynamic_oghma_revision_conflict');
            return$count;
        });
    }

    private function lockInstallation(string $installation):void
    {
        $exists=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:installation');$exists->execute(['installation'=>$installation]);
        if(!$exists->fetchColumn())throw new InvalidArgumentException('invalid_installation_id');
        $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')->execute(['key'=>'dynamic-oghma:'.$installation]);
    }

    private function transaction(callable $work):mixed
    {
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{$result=$work();if($owns)$this->db->commit();return$result;}
        catch(Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw$error;}
    }
}
