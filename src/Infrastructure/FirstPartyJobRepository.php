<?php

declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use ALMSIVIserver\Application\DeterministicRetrieval;
use PDO;
use RuntimeException;

/** Database operations used exclusively by the deterministic first-party jobs. */
final class FirstPartyJobRepository
{
    public function __construct(private readonly PDO $db) {}

    /** @param array<string,mixed> $memory */
    public function upsertMemory(string $memoryId, array $memory, string $now): void
    {
        $content = (string) $memory['content'];
        $statement = $this->db->prepare('INSERT INTO memory_records '
            . '(memory_id,installation_id,profile_id,playthrough_id,tier,content,lexical_terms,fake_vector,source_event_id,provenance,occurred_at,expires_at,created_at,updated_at) '
            . 'VALUES (:id,:installation,:profile,:playthrough,:tier,:content,CAST(:terms AS text[]),CAST(:vector AS jsonb),:source,CAST(:provenance AS jsonb),:occurred,:expires,:now,:now) '
            . 'ON CONFLICT (memory_id) DO UPDATE SET tier=EXCLUDED.tier,content=EXCLUDED.content,lexical_terms=EXCLUDED.lexical_terms,'
            . 'fake_vector=EXCLUDED.fake_vector,source_event_id=EXCLUDED.source_event_id,provenance=EXCLUDED.provenance,'
            . 'occurred_at=EXCLUDED.occurred_at,expires_at=EXCLUDED.expires_at,updated_at=EXCLUDED.updated_at,deleted_at=NULL '
            . 'WHERE memory_records.installation_id=EXCLUDED.installation_id AND memory_records.profile_id=EXCLUDED.profile_id '
            . 'AND memory_records.playthrough_id=EXCLUDED.playthrough_id');
        $statement->execute([
            'id' => $memoryId,
            'installation' => $memory['installation_id'],
            'profile' => $memory['profile_id'],
            'playthrough' => $memory['playthrough_id'],
            'tier' => $memory['tier'],
            'content' => $content,
            'terms' => $this->pgArray(DeterministicRetrieval::terms($content)),
            'vector' => $this->encode(DeterministicRetrieval::fakeVector($content)),
            'source' => $memory['source_event_id'] ?? null,
            'provenance' => $this->encode($memory['provenance']),
            'occurred' => $memory['occurred_at'] ?? $now,
            'expires' => $memory['expires_at'] ?? null,
            'now' => $now,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('memory_identity_conflict');
        }
    }

    /** @param array<string,string> $scope */
    public function rebuildMemories(array $scope, ?string $afterId, int $limit, string $now): int
    {
        $select = $this->db->prepare('SELECT memory_id,content FROM memory_records '
            . 'WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough '
            . 'AND deleted_at IS NULL AND (:after IS NULL OR memory_id>:after) ORDER BY memory_id LIMIT :limit');
        foreach ($this->scope($scope) + ['after' => $afterId] as $key => $value) {
            $select->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $select->bindValue(':limit', $limit, PDO::PARAM_INT);
        $select->execute();
        $update = $this->db->prepare('UPDATE memory_records SET lexical_terms=CAST(:terms AS text[]),fake_vector=CAST(:vector AS jsonb),updated_at=:now '
            . 'WHERE memory_id=:id AND deleted_at IS NULL');
        $count = 0;
        foreach ($select->fetchAll() as $row) {
            $content = (string) $row['content'];
            $update->execute(['terms' => $this->pgArray(DeterministicRetrieval::terms($content)),
                'vector' => $this->encode(DeterministicRetrieval::fakeVector($content)), 'now' => $now, 'id' => $row['memory_id']]);
            $count += $update->rowCount();
        }
        return $count;
    }

    /** @param array<string,mixed> $narrative */
    public function upsertNarrative(string $narrativeId, array $narrative, string $now): void
    {
        $statement = $this->db->prepare('INSERT INTO narrative_records '
            . '(narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,created_at,updated_at) '
            . 'VALUES (:id,:installation,:profile,:playthrough,:kind,:title,:content,CAST(:provenance AS jsonb),:now,:now) '
            . 'ON CONFLICT (narrative_id) DO UPDATE SET kind=EXCLUDED.kind,title=EXCLUDED.title,content=EXCLUDED.content,'
            . 'provenance=EXCLUDED.provenance,updated_at=EXCLUDED.updated_at,deleted_at=NULL '
            . 'WHERE narrative_records.installation_id=EXCLUDED.installation_id AND narrative_records.profile_id=EXCLUDED.profile_id '
            . 'AND narrative_records.playthrough_id=EXCLUDED.playthrough_id');
        $statement->execute($this->scope($narrative) + ['id' => $narrativeId, 'kind' => $narrative['kind'],
            'title' => $narrative['title'], 'content' => $narrative['content'],
            'provenance' => $this->encode($narrative['provenance']), 'now' => $now]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('narrative_identity_conflict');
        }
    }

    /** @return list<string> */
    public function cleanupMediaCandidates(?string $installationId, string $expiredBefore, int $limit): array
    {
        $sql = 'SELECT m.media_id FROM media_objects m JOIN sessions s ON s.session_id=m.session_id '
            . 'WHERE m.deleted_at IS NULL AND (m.expires_at<=:before OR s.state<>\'active\')';
        $parameters = ['before' => $expiredBefore];
        if ($installationId !== null) {
            $sql .= ' AND m.installation_id=:installation';
            $parameters['installation'] = $installationId;
        }
        $statement = $this->db->prepare($sql . ' ORDER BY m.expires_at,m.media_id LIMIT :limit');
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $media=array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN));
        if(count($media)<$limit){$stt=$this->db->prepare("SELECT storage_media_id FROM stt_requests WHERE storage_media_id IS NOT NULL AND cleanup_pending AND state IN('transcribed','failed') ORDER BY completed_at,message_id LIMIT :limit");$stt->bindValue(':limit',$limit-count($media),PDO::PARAM_INT);$stt->execute();$media=array_merge($media,array_map('strval',$stt->fetchAll(PDO::FETCH_COLUMN)));}
        return $media;
    }

    public function markMediaDeleted(string $mediaId, string $now): void
    {
        $this->db->prepare('UPDATE media_objects SET deleted_at=COALESCE(deleted_at,:now) WHERE media_id=:id')
            ->execute(['now' => $now, 'id' => $mediaId]);
        $this->db->prepare('UPDATE stt_requests SET storage_media_id=NULL,cleanup_pending=false WHERE storage_media_id=:id')->execute(['id'=>$mediaId]);
    }

    /** @param array<string,string> $scope @param array<string,int> $days */
    public function retainMemories(array $scope, array $days, string $now, int $limit): int
    {
        $total = 0;
        foreach (['recent', 'mid', 'long'] as $tier) {
            $retention = $days[$tier] ?? 0;
            if ($retention < 1 || $total >= $limit) {
                continue;
            }
            $statement = $this->db->prepare('UPDATE memory_records SET deleted_at=:now WHERE memory_id IN ('
                . 'SELECT memory_id FROM memory_records WHERE installation_id=:installation AND profile_id=:profile '
                . 'AND playthrough_id=:playthrough AND tier=:tier AND deleted_at IS NULL '
                . "AND occurred_at<CAST(:now AS timestamptz)-(:days||' days')::interval ORDER BY occurred_at,memory_id LIMIT :limit)");
            foreach ($this->scope($scope) + ['tier' => $tier, 'now' => $now, 'days' => (string) $retention] as $key => $value) {
                $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
            }
            $statement->bindValue(':limit', $limit - $total, PDO::PARAM_INT);
            $statement->execute();
            $total += $statement->rowCount();
        }
        return $total;
    }

    public function retainOperational(int $days, string $now, int $limit): int
    {
        $total = 0;
        $queries = [
            "DELETE FROM rate_limit_buckets WHERE bucket_key IN (SELECT bucket_key FROM rate_limit_buckets WHERE window_started_at<CAST(:now AS timestamptz)-interval '1 day' ORDER BY window_started_at LIMIT :limit)",
            "DELETE FROM idempotency_requests WHERE (installation_id,idempotency_key,route) IN (SELECT installation_id,idempotency_key,route FROM idempotency_requests WHERE created_at<CAST(:now AS timestamptz)-(:days||' days')::interval ORDER BY created_at LIMIT :limit)",
            'DELETE FROM browser_sessions WHERE session_hash IN (SELECT session_hash FROM browser_sessions WHERE expires_at<:now OR revoked_at IS NOT NULL ORDER BY expires_at LIMIT :limit)',
        ];
        foreach ($queries as $sql) {
            if ($total >= $limit) {
                break;
            }
            $statement = $this->db->prepare($sql);
            $statement->bindValue(':now', $now, PDO::PARAM_STR);
            if (str_contains($sql, ':days')) {
                $statement->bindValue(':days', (string) $days, PDO::PARAM_STR);
            }
            $statement->bindValue(':limit', $limit - $total, PDO::PARAM_INT);
            $statement->execute();
            $total += $statement->rowCount();
        }
        return $total;
    }

    public function reconcileProviderAttempt(string $attemptId, string $state, ?int $outputBytes, ?string $errorCode): void
    {
        $statement = $this->db->prepare('UPDATE provider_attempts SET state=:state,finished_at=clock_timestamp(),'
            . 'duration_ms=GREATEST(0,floor(extract(epoch FROM (clock_timestamp()-started_at))*1000)::integer),'
            . 'output_bytes=:output,error_code=:code,error_detail=NULL WHERE provider_attempt_id=:id AND state=\'started\'');
        $statement->execute(['state' => $state, 'output' => $outputBytes, 'code' => $errorCode, 'id' => $attemptId]);
    }

    public function reconcileStaleProviderAttempts(string $startedBefore, int $limit): int
    {
        $statement = $this->db->prepare("UPDATE provider_attempts SET state='cancelled',finished_at=clock_timestamp(),"
            . "duration_ms=GREATEST(0,floor(extract(epoch FROM (clock_timestamp()-started_at))*1000)::integer),error_code='provider_attempt_abandoned',error_detail=NULL "
            . 'WHERE provider_attempt_id IN (SELECT provider_attempt_id FROM provider_attempts WHERE state=\'started\' AND started_at<=:before ORDER BY started_at,provider_attempt_id LIMIT :limit)');
        $statement->bindValue(':before', $startedBefore, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->rowCount();
    }

    /** @param array<string,mixed> $value @return array{installation:string,profile:string,playthrough:string} */
    private function scope(array $value): array
    {
        return ['installation' => (string) $value['installation_id'], 'profile' => (string) $value['profile_id'],
            'playthrough' => (string) $value['playthrough_id']];
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function pgArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn(mixed $value): string => '"' . addcslashes((string) $value, '"\\') . '"', $values)) . '}';
    }
}
