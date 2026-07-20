<?php

declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use PDO;
use RuntimeException;
use Throwable;

final class ProductRepository
{
    public function __construct(private readonly PDO $db) {}

    /** @param array<string,mixed> $input */
    public function createRevisioned(string $kind, array $input, string $now): array
    {
        return $this->transaction(function () use ($kind, $input, $now): array {
            $id = Uuid::v4();
            $reason = (string) ($input['change_reason'] ?? 'created');
            if ($kind === 'profile') {
                $this->db->prepare('INSERT INTO profiles (profile_id, installation_id, name, actor_identity, created_at) VALUES (:id,:installation,:name,CAST(:identity AS jsonb),:now)')
                    ->execute(['id'=>$id,'installation'=>$input['installation_id'],'name'=>$input['name'],'identity'=>$this->encode($input['actor_identity'] ?? []),'now'=>$now]);
                $this->revision('profile_revisions', 'profile_id', $id, 1, $input['content'], $reason, $now);
            } elseif ($kind === 'playthrough') {
                $this->db->prepare('INSERT INTO playthroughs (playthrough_id, installation_id, profile_id, name, content_fingerprint, created_at) VALUES (:id,:installation,:profile,:name,:fingerprint,:now)')
                    ->execute(['id'=>$id,'installation'=>$input['installation_id'],'profile'=>$input['profile_id'],'name'=>$input['name'],'fingerprint'=>$input['content_fingerprint'] ?? null,'now'=>$now]);
                $this->revision('playthrough_revisions', 'playthrough_id', $id, 1, $input['content'], $reason, $now);
            } else {
                $configKind = $kind === 'prompt' ? 'prompt' : ($kind === 'provider' ? 'provider' : 'action_policy');
                $this->db->prepare('INSERT INTO configuration_sets (configuration_id,installation_id,profile_id,kind,name,created_at) VALUES (:id,:installation,:profile,:kind,:name,:now)')
                    ->execute(['id'=>$id,'installation'=>$input['installation_id'],'profile'=>$input['profile_id'] ?? null,'kind'=>$configKind,'name'=>$input['name'],'now'=>$now]);
                $this->revision('configuration_revisions', 'configuration_id', $id, 1, $input['content'], $reason, $now);
            }
            return $this->getRevisioned($kind, $id);
        });
    }

    public function resourceKind(string $id):string
    {
        foreach([['profiles','profile_id','profile'],['playthroughs','playthrough_id','playthrough']] as[$table,$key,$kind]){$s=$this->db->prepare("SELECT 1 FROM {$table} WHERE {$key}=:id");$s->execute(['id'=>$id]);if($s->fetchColumn())return$kind;}
        $s=$this->db->prepare('SELECT kind FROM configuration_sets WHERE configuration_id=:id');$s->execute(['id'=>$id]);$kind=$s->fetchColumn();if($kind===false)throw new RuntimeException('not_found');return$kind==='action_policy'?'action_policy':(string)$kind;
    }
    public function revisionContent(string $kind,string $id,int $revision):array{[, $key,$table]=$this->revisionMeta($kind);$s=$this->db->prepare("SELECT content FROM {$table} WHERE {$key}=:id AND revision=:revision");$s->execute(['id'=>$id,'revision'=>$revision]);$v=$s->fetchColumn();if($v===false)throw new RuntimeException('revision_not_found');return$this->json($v);}

    public function revise(string $kind, string $id, array $content, string $reason, string $now): array
    {
        return $this->transaction(function () use ($kind,$id,$content,$reason,$now): array {
            [$table,$key,$revisions] = $this->revisionMeta($kind);
            $stmt = $this->db->prepare("SELECT current_revision FROM {$table} WHERE {$key}=:id AND deleted_at IS NULL FOR UPDATE");
            $stmt->execute(['id'=>$id]);
            $current = $stmt->fetchColumn();
            if ($current === false) throw new RuntimeException('not_found');
            $next = (int)$current + 1;
            $this->revision($revisions, $key, $id, $next, $content, $reason, $now);
            $this->db->prepare("UPDATE {$table} SET current_revision=:revision WHERE {$key}=:id")->execute(['revision'=>$next,'id'=>$id]);
            return $this->getRevisioned($kind,$id);
        });
    }

    public function rollback(string $kind, string $id, int $revision, string $reason, string $now): array
    {
        [$table,$key,$revisions] = $this->revisionMeta($kind);
        $stmt = $this->db->prepare("SELECT content FROM {$revisions} WHERE {$key}=:id AND revision=:revision");
        $stmt->execute(['id'=>$id,'revision'=>$revision]);
        $content = $stmt->fetchColumn();
        if ($content === false) throw new RuntimeException('revision_not_found');
        return $this->revise($kind,$id,$this->json($content),'rollback:'.$revision.' '.$reason,$now);
    }

    public function getRevisioned(string $kind, string $id): array
    {
        [$table,$key,$revisions] = $this->revisionMeta($kind);
        $stmt = $this->db->prepare("SELECT b.*, r.content, r.change_reason, r.created_at AS revision_created_at FROM {$table} b JOIN {$revisions} r ON r.{$key}=b.{$key} AND r.revision=b.current_revision WHERE b.{$key}=:id AND b.deleted_at IS NULL");
        $stmt->execute(['id'=>$id]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('not_found');
        $row['content']=$this->json($row['content']);
        return $row;
    }

    public function listRevisioned(string $kind, string $installationId): array
    {
        [$table,$key,$revisions] = $this->revisionMeta($kind);
        $stmt=$this->db->prepare("SELECT b.{$key} AS id,b.name,b.current_revision,b.created_at,r.content FROM {$table} b JOIN {$revisions} r ON r.{$key}=b.{$key} AND r.revision=b.current_revision WHERE b.installation_id=:installation AND b.deleted_at IS NULL ORDER BY b.name LIMIT 100");
        $stmt->execute(['installation'=>$installationId]);
        return array_map(fn(array $r):array=>$r+['content'=>$this->json($r['content'])],$stmt->fetchAll());
    }

    public function deleteRevisioned(string $kind,string $id,string $now): void
    {
        [$table,$key] = $this->revisionMeta($kind);
        $this->db->prepare("UPDATE {$table} SET deleted_at=:now WHERE {$key}=:id")->execute(['now'=>$now,'id'=>$id]);
    }

    public function createMemory(array $input,array $terms,array $vector,string $now): array
    {
        $id=Uuid::v4();
        $this->db->prepare('INSERT INTO memory_records (memory_id,installation_id,profile_id,playthrough_id,tier,content,lexical_terms,fake_vector,source_event_id,provenance,occurred_at,expires_at,created_at,updated_at) VALUES (:id,:installation,:profile,:playthrough,:tier,:content,CAST(:terms AS text[]),CAST(:vector AS jsonb),:source,CAST(:provenance AS jsonb),:occurred,:expires,:now,:now)')
            ->execute(['id'=>$id,'installation'=>$input['installation_id'],'profile'=>$input['profile_id'],'playthrough'=>$input['playthrough_id'],'tier'=>$input['tier'],'content'=>$input['content'],'terms'=>$this->pgArray($terms),'vector'=>$this->encode($vector),'source'=>$input['source_event_id'] ?? null,'provenance'=>$this->encode($input['provenance']),'occurred'=>$input['occurred_at'] ?? $now,'expires'=>$input['expires_at'] ?? null,'now'=>$now]);
        return $this->memory($id);
    }

    public function memory(string $id): array
    {
        $stmt=$this->db->prepare('SELECT * FROM memory_records WHERE memory_id=:id AND deleted_at IS NULL');$stmt->execute(['id'=>$id]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('not_found');return $this->decodeMemory($row);
    }

    public function updateMemory(string $id,string $content,array $terms,array $vector,string $now): array
    {
        $this->db->prepare('UPDATE memory_records SET content=:content,lexical_terms=CAST(:terms AS text[]),fake_vector=CAST(:vector AS jsonb),updated_at=:now WHERE memory_id=:id AND deleted_at IS NULL')
            ->execute(['content'=>$content,'terms'=>$this->pgArray($terms),'vector'=>$this->encode($vector),'now'=>$now,'id'=>$id]);
        return $this->memory($id);
    }

    public function deleteMemory(string $id,string $now): void {$this->db->prepare('UPDATE memory_records SET deleted_at=:now WHERE memory_id=:id')->execute(['now'=>$now,'id'=>$id]);}

    public function rebuildMemories(array $scope,string $now): int
    {
        $stmt=$this->db->prepare('SELECT memory_id,content FROM memory_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL');$stmt->execute($this->scopeParams($scope));$count=0;
        foreach($stmt->fetchAll() as $row){$terms=\ALMSIVIserver\Application\DeterministicRetrieval::terms($row['content']);$vector=\ALMSIVIserver\Application\DeterministicRetrieval::fakeVector($row['content']);$this->updateMemory($row['memory_id'],$row['content'],$terms,$vector,$now);++$count;}return $count;
    }

    public function enforceMemoryRetention(array $scope,array $days,string $now): int
    {
        $count=0;foreach(['recent','mid','long'] as $tier){$retention=(int)($days[$tier]??0);if($retention<1)continue;$stmt=$this->db->prepare("UPDATE memory_records SET deleted_at=:now WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND tier=:tier AND deleted_at IS NULL AND occurred_at < CAST(:now AS timestamptz) - (:days || ' days')::interval");$stmt->execute($this->scopeParams($scope)+['tier'=>$tier,'now'=>$now,'days'=>(string)$retention]);$count+=$stmt->rowCount();}return $count;
    }

    public function memoryCandidates(array $scope,string $now): array
    {
        $stmt=$this->db->prepare('SELECT memory_id AS id,tier,content,lexical_terms,fake_vector,provenance,source_event_id,occurred_at FROM memory_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL AND (expires_at IS NULL OR expires_at>:now) ORDER BY occurred_at DESC LIMIT 500');$stmt->execute($this->scopeParams($scope)+['now'=>$now]);return array_map(fn($r)=>$this->decodeMemory($r),$stmt->fetchAll());
    }

    public function createKnowledge(array $input,array $terms,string $now): array
    {
        $id=Uuid::v4();$sha=hash('sha256',$input['content']);$this->db->prepare('INSERT INTO knowledge_documents (document_id,installation_id,profile_id,playthrough_id,title,content,content_sha256,lexical_terms,provenance,created_at) VALUES (:id,:installation,:profile,:playthrough,:title,:content,:sha,CAST(:terms AS text[]),CAST(:provenance AS jsonb),:now)')->execute(['id'=>$id,'installation'=>$input['installation_id'],'profile'=>$input['profile_id']??null,'playthrough'=>$input['playthrough_id']??null,'title'=>$input['title'],'content'=>$input['content'],'sha'=>$sha,'terms'=>$this->pgArray($terms),'provenance'=>$this->encode($input['provenance']),'now'=>$now]);return $this->knowledge($id);
    }
    public function knowledge(string $id): array {$s=$this->db->prepare('SELECT * FROM knowledge_documents WHERE document_id=:id AND deleted_at IS NULL');$s->execute(['id'=>$id]);$r=$s->fetch();if(!$r)throw new RuntimeException('not_found');$r['id']=$r['document_id'];$r['lexical_terms']=$this->parsePgArray($r['lexical_terms']);$r['provenance']=$this->json($r['provenance']);return $r;}
    public function deleteKnowledge(string $id,string $now):void{$this->db->prepare('UPDATE knowledge_documents SET deleted_at=:now WHERE document_id=:id')->execute(['now'=>$now,'id'=>$id]);}
    public function knowledgeCandidates(array $scope):array{$sql='SELECT document_id AS id,title,content,content_sha256,lexical_terms,provenance FROM knowledge_documents WHERE installation_id=:installation AND deleted_at IS NULL AND (profile_id IS NULL OR profile_id=:profile) AND (playthrough_id IS NULL OR playthrough_id=:playthrough) ORDER BY created_at DESC LIMIT 500';$s=$this->db->prepare($sql);$s->execute(['installation'=>$scope['installation_id'],'profile'=>$scope['profile_id']??null,'playthrough'=>$scope['playthrough_id']??null]);return array_map(function($r){$r['lexical_terms']=$this->parsePgArray($r['lexical_terms']);$r['provenance']=$this->json($r['provenance']);return $r;},$s->fetchAll());}

    public function recordRetrieval(string $domain,array $scope,string $query,array $rows,string $now):array
    {
        $id=Uuid::v4();$ids=array_column($rows,'id');$scores=[];foreach($rows as $r)$scores[$r['id']]=$r['score'];$this->db->prepare('INSERT INTO retrieval_traces (retrieval_trace_id,installation_id,profile_id,playthrough_id,domain,query,result_ids,scores,algorithm,created_at) VALUES (:id,:installation,:profile,:playthrough,:domain,:query,CAST(:ids AS uuid[]),CAST(:scores AS jsonb),:algorithm,:now)')->execute(['id'=>$id,'installation'=>$scope['installation_id'],'profile'=>$scope['profile_id']??null,'playthrough'=>$scope['playthrough_id']??null,'domain'=>$domain,'query'=>$query,'ids'=>$this->pgArray($ids),'scores'=>$this->encode($scores),'algorithm'=>'lexical-0.75+fake-vector-0.25-v1','now'=>$now]);return ['trace_id'=>$id,'algorithm'=>'lexical-0.75+fake-vector-0.25-v1','results'=>$rows];
    }

    public function setRelationship(array $input,string $now):array
    {
        $identity=$this->encode($input['actor_identity']);return $this->transaction(function()use($input,$now,$identity){$find=$this->db->prepare('SELECT * FROM relationship_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND actor_identity=CAST(:identity AS jsonb) AND deleted_at IS NULL FOR UPDATE');$find->execute($this->scopeParams($input)+['identity'=>$identity]);$before=$find->fetch();$id=$before['relationship_id']??Uuid::v4();if($before){$this->db->prepare('UPDATE relationship_records SET disposition=:disposition,affinity=:affinity,source_mode=:mode,source_event_id=:source,updated_at=:now WHERE relationship_id=:id')->execute(['disposition'=>$input['disposition'],'affinity'=>$input['affinity'],'mode'=>$input['source_mode'],'source'=>$input['source_event_id']??null,'now'=>$now,'id'=>$id]);}else{$this->db->prepare('INSERT INTO relationship_records (relationship_id,installation_id,profile_id,playthrough_id,actor_identity,disposition,affinity,source_mode,source_event_id,updated_at) VALUES (:id,:installation,:profile,:playthrough,CAST(:identity AS jsonb),:disposition,:affinity,:mode,:source,:now)')->execute($this->scopeParams($input)+['id'=>$id,'identity'=>$this->encode($input['actor_identity']),'disposition'=>$input['disposition'],'affinity'=>$input['affinity'],'mode'=>$input['source_mode'],'source'=>$input['source_event_id']??null,'now'=>$now]);}$after=['disposition'=>$input['disposition'],'affinity'=>$input['affinity']];$this->db->prepare('INSERT INTO relationship_audit (audit_id,relationship_id,mode,before_value,after_value,reason,source_event_id,created_at) VALUES (:audit,:id,:mode,CAST(:before AS jsonb),CAST(:after AS jsonb),:reason,:source,:now)')->execute(['audit'=>Uuid::v4(),'id'=>$id,'mode'=>$input['source_mode'],'before'=>$this->encode($before?['disposition'=>(int)$before['disposition'],'affinity'=>(int)$before['affinity']]:[]),'after'=>$this->encode($after),'reason'=>$input['reason']??'updated','source'=>$input['source_event_id']??null,'now'=>$now]);return ['relationship_id'=>$id]+$after+['source_mode'=>$input['source_mode']];});
    }

    public function relationships(array $scope):array{$s=$this->db->prepare('SELECT * FROM relationship_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 100');$s->execute($this->scopeParams($scope));return array_map(function($r){$r['actor_identity']=$this->json($r['actor_identity']);return $r;},$s->fetchAll());}

    public function createNarrative(array $input,string $now):array{$id=Uuid::v4();$this->db->prepare('INSERT INTO narrative_records (narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,created_at,updated_at) VALUES (:id,:installation,:profile,:playthrough,:kind,:title,:content,CAST(:provenance AS jsonb),:now,:now)')->execute($this->scopeParams($input)+['id'=>$id,'kind'=>$input['kind'],'title'=>$input['title'],'content'=>$input['content'],'provenance'=>$this->encode($input['provenance']),'now'=>$now]);return ['narrative_id'=>$id]+$input;}
    public function narratives(array $scope):array{$s=$this->db->prepare('SELECT * FROM narrative_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 100');$s->execute($this->scopeParams($scope));return array_map(function($r){$r['provenance']=$this->json($r['provenance']);return $r;},$s->fetchAll());}

    public function scheduleAutonomy(array $input,string $now):array{$id=$input['schedule_id']??Uuid::v4();$this->db->prepare('INSERT INTO autonomy_schedules (schedule_id,installation_id,profile_id,playthrough_id,kind,enabled,interval_seconds,cooldown_seconds,current_session_id,confirmed_at,updated_at) VALUES (:id,:installation,:profile,:playthrough,:kind,:enabled,:interval,:cooldown,:session,:confirmed,:now) ON CONFLICT (installation_id,profile_id,playthrough_id,kind) DO UPDATE SET enabled=EXCLUDED.enabled,interval_seconds=EXCLUDED.interval_seconds,cooldown_seconds=EXCLUDED.cooldown_seconds,current_session_id=EXCLUDED.current_session_id,confirmed_at=EXCLUDED.confirmed_at,updated_at=EXCLUDED.updated_at RETURNING schedule_id')->execute($this->scopeParams($input)+['id'=>$id,'kind'=>$input['kind'],'enabled'=>$input['enabled']?'true':'false','interval'=>$input['interval_seconds'],'cooldown'=>$input['cooldown_seconds'],'session'=>$input['current_session_id']??null,'confirmed'=>$input['confirmed_at']??null,'now'=>$now]);return ['schedule_id'=>$id,'kind'=>$input['kind'],'enabled'=>$input['enabled']];}
    public function dueAutonomy(array $scope,string $now):array{$s=$this->db->prepare("SELECT a.* FROM autonomy_schedules a JOIN sessions s ON s.session_id=a.current_session_id AND s.state='active' AND s.installation_id=a.installation_id WHERE a.installation_id=:installation AND a.profile_id=:profile AND a.playthrough_id=:playthrough AND a.enabled AND a.confirmed_at IS NOT NULL AND (a.last_triggered_at IS NULL OR a.last_triggered_at + make_interval(secs=>GREATEST(a.interval_seconds,a.cooldown_seconds)) <= :now) ORDER BY a.kind");$s->execute($this->scopeParams($scope)+['now'=>$now]);return $s->fetchAll();}
    public function markAutonomyTriggered(string $id,string $now):void{$this->db->prepare('UPDATE autonomy_schedules SET last_triggered_at=:now,updated_at=:now WHERE schedule_id=:id')->execute(['now'=>$now,'id'=>$id]);}

    public function exportScope(array $scope):array
    {
        $queries=[
            'memories'=>'SELECT memory_id AS id,tier,content,lexical_terms,fake_vector,provenance,source_event_id,occurred_at,expires_at FROM memory_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY occurred_at DESC',
            'relationships'=>'SELECT * FROM relationship_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY updated_at DESC',
            'narratives'=>'SELECT * FROM narrative_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY created_at DESC'];
        $result=[];foreach($queries as$name=>$sql){$s=$this->db->prepare($sql);$s->execute($this->scopeParams($scope));$rows=$s->fetchAll();if($name==='memories')$rows=array_map(fn($r)=>$this->decodeMemory($r),$rows);elseif($name==='relationships')$rows=array_map(function($r){$r['actor_identity']=$this->json($r['actor_identity']);return$r;},$rows);else$rows=array_map(function($r){$r['provenance']=$this->json($r['provenance']);return$r;},$rows);$result[$name]=$rows;}return$result;
    }
    public function restoreScope(array $document,string $now):array
    {
        return$this->transaction(function()use($document,$now):array{$counts=['memories'=>0,'relationships'=>0,'narratives'=>0];$scope=$document['scope'];$key=hash('sha256',$this->encode($document));
            foreach($document['data']['memories'] as$i=>$r){$id=$this->deterministicUuid('restore:memory:'.$key.':'.$i);$s=$this->db->prepare('SELECT 1 FROM memory_records WHERE memory_id=:id');$s->execute(['id'=>$id]);if(!$s->fetchColumn()){$this->db->prepare('INSERT INTO memory_records(memory_id,installation_id,profile_id,playthrough_id,tier,content,lexical_terms,fake_vector,source_event_id,provenance,occurred_at,expires_at,created_at,updated_at) VALUES(:id,:installation,:profile,:playthrough,:tier,:content,CAST(:terms AS text[]),CAST(:vector AS jsonb),:source,CAST(:provenance AS jsonb),:occurred,:expires,:now,:now)')->execute($this->scopeParams($scope)+['id'=>$id,'tier'=>$r['tier'],'content'=>$r['content'],'terms'=>$this->pgArray($r['lexical_terms']),'vector'=>$this->encode(\ALMSIVIserver\Application\DeterministicRetrieval::fakeVector($r['content'])),'source'=>$r['source_event_id']??null,'provenance'=>$this->encode($r['provenance']??['source'=>'restore','key'=>$key]),'occurred'=>$r['occurred_at'],'expires'=>$r['expires_at']??null,'now'=>$now]);}$counts['memories']++;}
            foreach($document['data']['relationships'] as$i=>$r){$id=$this->deterministicUuid('restore:relationship:'.$key.':'.$i);$this->db->prepare('INSERT INTO relationship_records(relationship_id,installation_id,profile_id,playthrough_id,actor_identity,disposition,affinity,source_mode,source_event_id,updated_at) VALUES(:id,:installation,:profile,:playthrough,CAST(:identity AS jsonb),:disposition,:affinity,:mode,:source,:now) ON CONFLICT(relationship_id) DO NOTHING')->execute($this->scopeParams($scope)+['id'=>$id,'identity'=>$this->encode($r['actor_identity']),'disposition'=>(int)$r['disposition'],'affinity'=>(int)$r['affinity'],'mode'=>$r['source_mode']??'manual','source'=>$r['source_event_id']??null,'now'=>$now]);$counts['relationships']++;}
            foreach($document['data']['narratives'] as$i=>$r){$id=$this->deterministicUuid('restore:narrative:'.$key.':'.$i);$s=$this->db->prepare('SELECT 1 FROM narrative_records WHERE narrative_id=:id');$s->execute(['id'=>$id]);if(!$s->fetchColumn())$this->db->prepare('INSERT INTO narrative_records(narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,created_at,updated_at) VALUES(:id,:installation,:profile,:playthrough,:kind,:title,:content,CAST(:provenance AS jsonb),:now,:now)')->execute($this->scopeParams($scope)+['id'=>$id,'kind'=>$r['kind'],'title'=>$r['title'],'content'=>$r['content'],'provenance'=>$this->encode($r['provenance']??['source'=>'restore','key'=>$key]),'now'=>$now]);$counts['narratives']++;}return$counts;});
    }

    public function promptContext(array $turn, string $now): array
    {
        $scope = ['installation_id'=>$turn['installation_id'],'profile_id'=>$turn['profile_id'],'playthrough_id'=>$turn['playthrough_id']];
        $profile = $this->getRevisioned('profile', $turn['profile_id']);
        $promptStmt = $this->db->prepare("SELECT c.configuration_id,c.current_revision AS revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.installation_id=:installation AND c.kind='prompt' AND c.deleted_at IS NULL AND (c.profile_id=:profile OR c.profile_id IS NULL) ORDER BY (c.profile_id IS NOT NULL) DESC,c.name,c.configuration_id LIMIT 1");
        $promptStmt->execute(['installation'=>$turn['installation_id'],'profile'=>$turn['profile_id']]);
        $prompt = $promptStmt->fetch();
        if (!$prompt) {
            $prompt = ['configuration_id'=>$turn['profile_id'],'revision'=>(int)$profile['current_revision'],'content'=>['instruction'=>'Respond in character using only scoped context.']];
        } else {
            $prompt['revision']=(int)$prompt['revision'];$prompt['content']=$this->json($prompt['content']);
        }
        $profile['revision']=(int)$profile['current_revision'];
        $memories=$this->memoryCandidates($scope,$now);usort($memories,fn($a,$b)=>strcmp((string)$a['id'],(string)$b['id']));
        $knowledge=$this->knowledgeCandidates($scope);usort($knowledge,fn($a,$b)=>strcmp((string)$a['id'],(string)$b['id']));
        $relationships=$this->relationships($scope);usort($relationships,fn($a,$b)=>strcmp((string)$a['relationship_id'],(string)$b['relationship_id']));
        $narratives=$this->narratives($scope);usort($narratives,fn($a,$b)=>strcmp((string)$a['narrative_id'],(string)$b['narrative_id']));
        $actions=$this->db->prepare('SELECT r.action_id,r.status,r.reason_code,r.observed,r.completed_at FROM action_results r JOIN action_intents a ON a.action_id=r.action_id WHERE a.session_id=:session ORDER BY r.completed_at DESC,r.action_id LIMIT 16');
        $actions->execute(['session'=>$turn['session_id']]);
        $recent=array_map(function($r){$r['observed']=$this->json($r['observed']);return$r;},$actions->fetchAll());
        return ['profile'=>$profile,'prompt'=>$prompt,'memory'=>array_slice($memories,0,10),'relationship'=>array_slice($relationships,0,10),'knowledge'=>array_slice($knowledge,0,10),'narrative'=>array_slice($narratives,0,10),'recent_action_results'=>$recent];
    }

    public function recordPromptTrace(array $turn, array $trace, string $now): string
    {
        return $this->transaction(function() use($turn,$trace,$now):string{$id=Uuid::v4();$this->db->prepare('INSERT INTO prompt_traces (prompt_trace_id,installation_id,profile_id,playthrough_id,session_id,turn_id,request_id,prompt_configuration_id,prompt_revision,algorithm,input_sha256,input_bytes,truncated,created_at) VALUES (:id,:installation,:profile,:playthrough,:session,:turn,:request,:config,:revision,:algorithm,:sha,:bytes,:truncated,:now) ON CONFLICT (turn_id) DO NOTHING')->execute(['id'=>$id,'installation'=>$turn['installation_id'],'profile'=>$turn['profile_id'],'playthrough'=>$turn['playthrough_id'],'session'=>$turn['session_id'],'turn'=>$turn['turn_id'],'request'=>$turn['request_id'],'config'=>$trace['prompt_configuration_id']===$turn['profile_id']?null:$trace['prompt_configuration_id'],'revision'=>$trace['prompt_revision'],'algorithm'=>$trace['algorithm'],'sha'=>$trace['input_sha256'],'bytes'=>$trace['input_bytes'],'truncated'=>$trace['truncated']?'true':'false','now'=>$now]);$find=$this->db->prepare('SELECT prompt_trace_id FROM prompt_traces WHERE turn_id=:turn');$find->execute(['turn'=>$turn['turn_id']]);$stored=(string)$find->fetchColumn();foreach($trace['sources'] as $source){$this->db->prepare('INSERT INTO prompt_trace_sources (prompt_trace_id,ordinal,source_kind,source_id,included,reason,source_sha256,included_bytes,redacted_preview) VALUES (:trace,:ordinal,:kind,:source,:included,:reason,:sha,:bytes,:preview) ON CONFLICT DO NOTHING')->execute(['trace'=>$stored,'ordinal'=>$source['ordinal'],'kind'=>$source['source_kind'],'source'=>$source['source_id'],'included'=>$source['included']?'true':'false','reason'=>$source['reason'],'sha'=>$source['source_sha256'],'bytes'=>$source['included_bytes'],'preview'=>'']);}return$stored;});
    }

    public function searchTraces(string $installation,string $query,int $limit=50):array{$needle='%'.$query.'%';$stmt=$this->db->prepare('SELECT source_event_id AS id,event_kind AS type,occurred_at AS created_at,request_id,turn_id,payload FROM source_events WHERE installation_id=:installation AND (event_kind ILIKE :query OR payload::text ILIKE :query) ORDER BY received_at DESC LIMIT :limit');$stmt->bindValue(':installation',$installation);$stmt->bindValue(':query',$needle);$stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->execute();return array_map(function($r){$r['payload']=$this->json($r['payload']);return$r;},$stmt->fetchAll());}
    public function traceDetail(string $id):array{$s=$this->db->prepare('SELECT * FROM source_events WHERE source_event_id=:id');$s->execute(['id'=>$id]);$r=$s->fetch();if(!$r)throw new RuntimeException('not_found');$r['payload']=$this->json($r['payload']);return $r;}

    public function diagnostics():array{return ['database'=>['connected'=>true,'version'=>(string)$this->db->query('SHOW server_version')->fetchColumn()],'counts'=>['installations'=>(int)$this->db->query('SELECT count(*) FROM installations WHERE revoked_at IS NULL')->fetchColumn(),'active_sessions'=>(int)$this->db->query("SELECT count(*) FROM sessions WHERE state='active'")->fetchColumn(),'queued_jobs'=>(int)$this->db->query("SELECT count(*) FROM durable_jobs WHERE state='queued'")->fetchColumn(),'dead_jobs'=>(int)$this->db->query("SELECT count(*) FROM durable_jobs WHERE state='dead'")->fetchColumn(),'memory_records'=>(int)$this->db->query('SELECT count(*) FROM memory_records WHERE deleted_at IS NULL')->fetchColumn()]];}

    public function prune(int $days,string $now):array{$result=[];$queries=['rate_limits'=>"DELETE FROM rate_limit_buckets WHERE window_started_at < CAST(:now AS timestamptz) - interval '1 day'",'idempotency'=>"DELETE FROM idempotency_requests WHERE created_at < CAST(:now AS timestamptz) - (:days || ' days')::interval",'browser_sessions'=>'DELETE FROM browser_sessions WHERE expires_at<:now OR revoked_at IS NOT NULL'];foreach($queries as $key=>$sql){$s=$this->db->prepare($sql);$s->execute(['now'=>$now]+(str_contains($sql,':days')?['days'=>(string)$days]:[]));$result[$key]=$s->rowCount();}return $result;}

    private function revision(string $table,string $key,string $id,int $revision,array $content,string $reason,string $now):void{$this->db->prepare("INSERT INTO {$table} ({$key},revision,content,change_reason,created_at) VALUES (:id,:revision,CAST(:content AS jsonb),:reason,:now)")->execute(['id'=>$id,'revision'=>$revision,'content'=>$this->encode($content),'reason'=>$reason,'now'=>$now]);}
    private function revisionMeta(string $kind):array{return match($kind){'profile'=>['profiles','profile_id','profile_revisions'],'playthrough'=>['playthroughs','playthrough_id','playthrough_revisions'],'prompt','provider','action_policy'=>['configuration_sets','configuration_id','configuration_revisions'],default=>throw new RuntimeException('invalid_resource_kind')};}
    private function transaction(callable $callback):mixed{$owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();try{$v=$callback();if($owns)$this->db->commit();return$v;}catch(Throwable $e){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw$e;}}
    private function deterministicUuid(string $value):string{$h=md5($value);return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);}
    private function encode(array $v):string{return json_encode($v===[]?(object)[]:$v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
    private function json(mixed $v):array{return is_array($v)?$v:json_decode((string)$v,true,64,JSON_THROW_ON_ERROR);}
    private function scopeParams(array $v):array{return ['installation'=>$v['installation_id'],'profile'=>$v['profile_id'],'playthrough'=>$v['playthrough_id']];}
    private function pgArray(array $v):string{return '{'.implode(',',array_map(fn($x)=>'"'.addcslashes((string)$x,'"\\').'"',$v)).'}';}
    private function parsePgArray(string $v):array{return $v==='{}'?[]:str_getcsv(trim($v,'{}'),',','"','\\');}
    private function decodeMemory(array $r):array{$r['lexical_terms']=$this->parsePgArray((string)$r['lexical_terms']);$r['fake_vector']=$this->json($r['fake_vector']);$r['provenance']=$this->json($r['provenance']);return$r;}
}
