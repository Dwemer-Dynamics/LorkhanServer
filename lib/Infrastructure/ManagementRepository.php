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
    public function __construct(private readonly PDO $db) {}

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
