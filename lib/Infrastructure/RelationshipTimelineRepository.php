<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/** Immutable directed relationship ancestry used only to retire automatic changes on a loaded branch. */
final class RelationshipTimelineRepository
{
    public function __construct(private readonly PDO $db) {}

    /** Capture legacy/manual state as an unknown boundary without guessing from its source mode. */
    public function snapshot(array $row,array $provenance=[]):void
    {
        $content=array_intersect_key($row,array_fill_keys(['disposition','affinity','relationship_type','details','source_mode','source_event_id'],true));
        foreach(['disposition','affinity']as$field)$content[$field]=(int)$content[$field];
        if(is_string($content['details']))$content['details']=json_decode($content['details'],false,32,JSON_THROW_ON_ERROR);
        $query=$this->db->prepare('INSERT INTO relationship_revisions(relationship_id,revision,content,provenance)
            VALUES(:id,:revision,CAST(:content AS jsonb),CAST(:provenance AS jsonb)) ON CONFLICT DO NOTHING');
        $query->execute(['id'=>$row['relationship_id'],'revision'=>$row['revision'],
            'content'=>json_encode($content,JSON_THROW_ON_ERROR),'provenance'=>json_encode((object)$provenance,JSON_THROW_ON_ERROR)]);
    }

    /** Only a matching, currently leased automatic evaluator can produce automatic provenance. */
    public function recordWrite(string $id,int $base,?array $job):void
    {
        $query=$this->db->prepare('SELECT * FROM relationship_records WHERE relationship_id=:id FOR UPDATE');
        $query->execute(['id'=>$id]);$row=$query->fetch();if(!$row)throw new RuntimeException('relationship_snapshot_missing');
        $provenance=[];
        if($job!==null){
            $query=$this->db->prepare("SELECT payload FROM durable_jobs WHERE job_id=:id AND job_type='relationship.evaluate'
                AND state='leased' AND lease_token=:lease AND attempt_count=:attempt AND lease_expires_at>clock_timestamp() FOR SHARE");
            $query->execute(['id'=>$job['job_id'],'lease'=>$job['lease_token'],'attempt'=>$job['attempt']]);
            $payload=$query->fetchColumn();if($payload===false)throw new RuntimeException('relationship_provenance_lease_lost');
            $payload=json_decode($payload,true,32,JSON_THROW_ON_ERROR);
            foreach(['installation_id','profile_id','playthrough_id','source_event_id']as$field)
                if(($payload[$field]??null)!==$row[$field])throw new RuntimeException('relationship_provenance_scope');
            $source=(new RelationshipEvaluationRepository($this->db))->source($row['source_event_id'],false);
            $products=new ProductRepository($this->db);
            if($source===null||($payload['turn_id']??null)!==$source['turn_id']
                ||$products->actorKey($source['target_identity'])!==$products->actorKey(json_decode($row['actor_identity'],true,32,JSON_THROW_ON_ERROR)))
                throw new RuntimeException('relationship_provenance_source');
            $provenance=['kind'=>'automatic_relationship','base_revision'=>$base,'job_id'=>$job['job_id'],
                'playthrough_id'=>$row['playthrough_id'],'source_turn_ids'=>[$source['turn_id']]];
        }
        $this->snapshot($row,$provenance);
    }

    /** Append a monotonic baseline or retire an automatic-only row; manual/deleted/locked rows remain untouched. */
    public function restore(array $scope,string $loadId):array
    {
        if(!$this->db->inTransaction())throw new RuntimeException('timeline_transaction_required');
        $counts=['relationships'=>0,'relationship_restore_skipped'=>0];
        $query=$this->db->prepare("SELECT r.*,v.provenance FROM relationship_records r
            JOIN relationship_revisions v ON v.relationship_id=r.relationship_id AND v.revision=r.revision
            JOIN profiles p ON p.profile_id=r.profile_id AND p.installation_id=r.installation_id
            JOIN profile_revisions pr ON pr.profile_id=p.profile_id AND pr.revision=p.current_revision
            WHERE r.installation_id=:installation AND r.playthrough_id=:playthrough AND r.deleted_at IS NULL
                AND p.deleted_at IS NULL AND p.actor_identity->>'kind' IN ('npc','creature')
                AND COALESCE(pr.content#>>'{management,locked}','false')<>'true'
                AND v.provenance->>'kind' IN ('automatic_relationship','loaded_save_relationship_restore')
            ORDER BY r.relationship_id FOR UPDATE OF r FOR SHARE OF p");
        $query->execute($scope);$rows=$query->fetchAll();
        $history=$this->db->prepare('SELECT content,provenance FROM relationship_revisions WHERE relationship_id=:id AND revision=:revision');
        $timeline=new LoadedSaveTimeline($this->db);
        foreach($rows as$row){
            $policy=(new RelationshipEvaluationRepository($this->db))->policy($row['installation_id'],$row['profile_id']);
            if($policy===null||$policy['locked'])continue;
            $cursor=(int)$row['revision'];$node=$row;$restore=null;$complete=false;
            for($depth=0;$depth<256;$depth++){
                $provenance=json_decode($node['provenance'],true,32,JSON_THROW_ON_ERROR);
                if(($provenance['playthrough_id']??null)!==$scope['playthrough']){$complete=true;break;}
                $kind=$provenance['kind']??null;
                if($kind==='automatic_relationship'){
                    $sources=$provenance['source_turn_ids']??null;
                    if(!is_array($sources)||!$timeline->sourcesBelongTo($sources,$scope['installation'],$scope['playthrough'])){$complete=true;break;}
                    $next=$provenance['base_revision']??null;
                    if(!is_int($next)||$next<0||$next>=$cursor)break;
                    if(!$timeline->sourcesActive($sources))$restore=$next;
                }elseif($kind==='loaded_save_relationship_restore'){
                    $next=$provenance['restored_revision']??null;
                    if(!is_int($next)||$next<0||$next>=$cursor)break;
                }else{$complete=true;break;}
                if($next===0){$complete=true;break;}
                $history->execute(['id'=>$row['relationship_id'],'revision'=>$next]);$node=$history->fetch();
                if(!$node)break;$cursor=$next;
            }
            if(!$complete){$counts['relationship_restore_skipped']++;continue;}
            if($restore===null)continue;
            if($restore===0){
                // No relationship existed before this abandoned automatic branch. Do not manufacture neutrality.
                $update=$this->db->prepare('UPDATE relationship_records SET deleted_at=clock_timestamp(),updated_at=clock_timestamp()
                    WHERE relationship_id=:id AND revision=:revision RETURNING *');
                $params=['id'=>$row['relationship_id'],'revision'=>$row['revision']];
            }else{
                $history->execute(['id'=>$row['relationship_id'],'revision'=>$restore]);$baseline=$history->fetch();
                if(!$baseline){$counts['relationship_restore_skipped']++;continue;}
                $baseline=json_decode($baseline['content'],true,32,JSON_THROW_ON_ERROR);
                $update=$this->db->prepare('UPDATE relationship_records SET disposition=:disposition,affinity=:affinity,
                    relationship_type=:type,details=CAST(:details AS jsonb),source_mode=:mode,source_event_id=:source,updated_at=clock_timestamp()
                    WHERE relationship_id=:id AND revision=:revision RETURNING *');
                $params=['id'=>$row['relationship_id'],'revision'=>$row['revision'],'disposition'=>$baseline['disposition'],
                    'affinity'=>$baseline['affinity'],'type'=>$baseline['relationship_type'],'details'=>json_encode((object)$baseline['details'],JSON_THROW_ON_ERROR),
                    'mode'=>$baseline['source_mode'],'source'=>$baseline['source_event_id']];
            }
            $update->execute($params);$after=$update->fetch();if(!$after)throw new RuntimeException('relationship_revision_conflict');
            $this->snapshot($after,['kind'=>'loaded_save_relationship_restore','playthrough_id'=>$scope['playthrough'],
                'restored_revision'=>$restore,'loaded_save_id'=>$loadId]);
            $audit=$this->db->prepare("INSERT INTO relationship_audit(audit_id,relationship_id,mode,before_value,after_value,reason,created_at)
                VALUES(:audit,:id,'derived',CAST(:before AS jsonb),CAST(:after AS jsonb),'loaded save automatic relationship restore',clock_timestamp())");
            $fields=array_fill_keys(['disposition','affinity','relationship_type','revision','deleted_at'],true);
            $audit->execute(['audit'=>Uuid::v4(),'id'=>$row['relationship_id'],
                'before'=>json_encode(array_intersect_key($row,$fields),JSON_THROW_ON_ERROR),
                'after'=>json_encode(array_intersect_key($after,$fields),JSON_THROW_ON_ERROR)]);
            $counts['relationships']++;
        }
        return$counts;
    }
}
