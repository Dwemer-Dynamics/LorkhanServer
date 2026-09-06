<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use PDO;

/** Read actual LLM attempts and committed receipts, never label manual edits as evaluations. */
final class RelationshipLogRepository
{
    public function __construct(private readonly PDO $db) {}

    public function page(string $installation,string $type='',int $page=1,int $limit=50):array
    {
        $type=in_array($type,['eval_','analyze_','npc2npc_'],true)?$type:'';
        $limit=in_array($limit,[25,50,100],true)?$limit:50;
        $from=<<<'SQL'
 FROM provider_attempts a JOIN durable_jobs j ON j.job_id=a.job_id
 LEFT JOIN profiles p ON p.profile_id::text=j.payload->>'profile_id'
 LEFT JOIN dialogue_delivery_results d ON d.source_event_id::text=j.payload->>'source_event_id'
 LEFT JOIN turns t ON t.turn_id=d.turn_id
 LEFT JOIN relationship_evaluation_results e ON e.job_id=j.job_id AND a.state='succeeded'
 LEFT JOIN relationship_build_results b ON b.job_id=j.job_id AND a.state='succeeded'
SQL;
        $kind="CASE WHEN j.job_type='relationship.build' THEN 'analyze_' WHEN t.speaker->>'kind' IN ('npc','creature') THEN 'npc2npc_' ELSE 'eval_' END";
        $where=" WHERE a.provider_kind='llm' AND a.operation IN ('evaluate_relationship','build_relationships')
            AND j.job_type IN ('relationship.evaluate','relationship.build') AND j.payload->>'installation_id'=:installation
            AND NOT EXISTS(SELECT 1 FROM request_log_hidden h WHERE h.provider_attempt_id=a.provider_attempt_id)";
        $params=['installation'=>$installation];
        $stats=$this->db->prepare("SELECT count(*) AS total,count(*) FILTER(WHERE a.started_at>clock_timestamp()-INTERVAL '1 hour') AS recent".$from.$where);
        $stats->execute($params);$totals=$stats->fetch();
        if($type!==''){$where.=' AND ('.$kind.')=:type';$params['type']=$type;}
        $count=$this->db->prepare('SELECT count(*)'.$from.$where);$count->execute($params);$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/$limit));$page=max(1,min($page,$pages));
        $select="SELECT a.provider_attempt_id,a.state,a.error_code,a.started_at,a.finished_at,a.operation,
            {$kind} AS type,COALESCE(t.target->>'display_name',p.name,'Unknown NPC') AS npc,
            COALESCE(t.speaker->>'display_name',t.speaker->>'record_id','Unknown interlocutor') AS target,
            e.disposition_delta,e.affinity_delta,e.reason,b.changed_count,b.source_count,
            j.payload->'source_ids' AS source_ids,j.payload->>'source_event_id' AS source_event_id";
        $stmt=$this->db->prepare($select.$from.$where.' ORDER BY a.started_at DESC,a.provider_attempt_id DESC LIMIT :limit OFFSET :offset');
        foreach($params as $key=>$value)$stmt->bindValue($key,$value);
        $stmt->bindValue('limit',$limit,PDO::PARAM_INT);$stmt->bindValue('offset',($page-1)*$limit,PDO::PARAM_INT);$stmt->execute();
        $rows=$stmt->fetchAll();
        // Read only retained, unsuppressed played conversations. These are source context,
        // not a reconstruction of an unrecorded provider prompt or its proposed changes.
        $context=$this->db->prepare(<<<'SQL'
SELECT t.input_text,t.target->>'display_name' AS npc,t.speaker->>'display_name' AS speaker,
    (SELECT string_agg(u.text,E'\n' ORDER BY u.utterance_index) FROM dialogue_utterances u
      JOIN eventlog_metadata m ON m.projection_key='dialogue:'||u.dialogue_message_id::text
        AND m.projection_kind='dialogue' AND m.suppressed_at IS NULL
      WHERE u.turn_id=t.turn_id AND u.delivery_state='played') AS reply
FROM jsonb_array_elements_text(CAST(:sources AS jsonb)) WITH ORDINALITY ids(id,position)
JOIN dialogue_delivery_results d ON d.source_event_id::text=ids.id JOIN turns t ON t.turn_id=d.turn_id
JOIN sessions s ON s.session_id=t.session_id
JOIN eventlog_metadata m ON m.projection_key='turn:'||t.turn_id::text
    AND m.projection_kind='turn' AND m.suppressed_at IS NULL
WHERE s.installation_id=:installation AND d.status='played' ORDER BY ids.position
SQL);
        foreach($rows as &$row){
            $ids=$row['operation']==='build_relationships'?json_decode((string)$row['source_ids'],true):[$row['source_event_id']];
            $ids=array_values(array_filter(array_slice(is_array($ids)?$ids:[],0,100),static fn(mixed $id):bool=>is_string($id)&&Uuid::isValid($id)));
            $parts=[];$bytes=0;
            $context->execute(['sources'=>json_encode($ids,JSON_THROW_ON_ERROR),'installation'=>$installation]);
            while($exchange=$context->fetch()){
                if($exchange['reply']===null)continue;
                $part=($exchange['speaker']??'Speaker').': '.$exchange['input_text']."\n".($exchange['npc']??'NPC').': '.$exchange['reply'];
                if($bytes+strlen($part)>65536){$parts[]='[Further retained context omitted from this reader.]';break;}
                $parts[]=$part;$bytes+=strlen($part);
            }
            $row['context']=implode("\n\n",$parts);unset($row['source_ids'],$row['source_event_id']);
        }unset($row);
        return ['rows'=>$rows,'total'=>$total,'all_total'=>(int)$totals['total'],'recent'=>(int)$totals['recent'],
            'page'=>$page,'pages'=>$pages,'limit'=>$limit,'type'=>$type];
    }
}
