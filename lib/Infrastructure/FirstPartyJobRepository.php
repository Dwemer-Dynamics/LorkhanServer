<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\DeterministicRetrieval;
use PDO;
use RuntimeException;

/** Database operations used exclusively by the deterministic first-party jobs. */
final class FirstPartyJobRepository
{
    public function __construct(private readonly PDO $db) {}

    /** Reject derived memories whose immutable source is outside scope or did not finish playback. */
    public function assertMemorySourceEligible(array $memory): void
    {
        $sourceId = $memory['source_event_id'] ?? null;
        if (!is_string($sourceId)) throw new RuntimeException('memory_source_required');
        $statement = $this->db->prepare('SELECT se.event_kind,se.installation_id,s.profile_id,s.playthrough_id,d.status '
            . 'FROM source_events se LEFT JOIN sessions s ON s.session_id=se.session_id '
            . 'LEFT JOIN dialogue_delivery_results d ON d.source_event_id=se.source_event_id '
            . 'WHERE se.source_event_id=:source');
        $statement->execute(['source'=>$sourceId]);
        $source = $statement->fetch();
        if (!$source || $source['installation_id'] !== $memory['installation_id']
            || $source['profile_id'] !== $memory['profile_id'] || $source['playthrough_id'] !== $memory['playthrough_id']) {
            throw new RuntimeException('memory_source_scope_mismatch');
        }
        if ($source['event_kind'] === 'dialogue.delivery' && $source['status'] === 'played') return;
        if (in_array($source['event_kind'], ['turn.requested','stt.transcript','action.result','location','death','narration'], true)) return;
        throw new RuntimeException('memory_source_ineligible');
    }

    /** Retries update the same derived record without clearing a user or timeline deletion. */
    public function upsertMemory(string $memoryId, array $memory, string $now): void
    {
        $content = (string) $memory['content'];
        $statement = $this->db->prepare('INSERT INTO memory_records '
            . '(memory_id,installation_id,profile_id,playthrough_id,tier,content,lexical_terms,fake_vector,source_event_id,provenance,occurred_at,expires_at,created_at,updated_at) '
            . 'VALUES (:id,:installation,:profile,:playthrough,:tier,:content,CAST(:terms AS text[]),CAST(:vector AS jsonb),:source,CAST(:provenance AS jsonb),:occurred,:expires,:now,:now) '
            . 'ON CONFLICT (memory_id) DO UPDATE SET tier=EXCLUDED.tier,content=EXCLUDED.content,lexical_terms=EXCLUDED.lexical_terms,'
            . 'fake_vector=EXCLUDED.fake_vector,source_event_id=EXCLUDED.source_event_id,provenance=EXCLUDED.provenance,'
            . 'occurred_at=EXCLUDED.occurred_at,expires_at=EXCLUDED.expires_at,updated_at=EXCLUDED.updated_at '
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
        $this->enqueueMemoryEmbedding($memoryId,(string)$memory['installation_id']);
    }

    /** Chain delivered recent memories into bounded event-driven middle/long consolidation. */
    public function enqueueMemoryConsolidation(string $memoryId, array $memory): void
    {
        $sourceTier = (string) ($memory['tier'] ?? '');
        if($sourceTier==='mid')(new NpcMemoryDigestRepository($this->db))->enqueueScan($memory);
        if (!in_array($sourceTier, ['recent', 'mid'], true)) {
            return;
        }
        $payload = [
            'installation_id' => (string) $memory['installation_id'],
            'profile_id' => (string) $memory['profile_id'],
            'playthrough_id' => (string) $memory['playthrough_id'],
            'source_memory_id' => $memoryId,
            'source_tier' => $sourceTier,
        ];
        $statement = $this->db->prepare("INSERT INTO durable_jobs "
            . "(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) "
            . "VALUES(:job,'memory.consolidate',1,:key,CAST(:payload AS jsonb),3,30) "
            . 'ON CONFLICT(job_type,idempotency_key) DO NOTHING');
        $statement->execute([
            'job' => Uuid::v4(),
            'key' => 'memory:consolidate:' . $sourceTier . ':' . $memoryId,
            'payload' => $this->encode($payload),
        ]);
    }

    /**
     * Consolidate the oldest four unused eligible rows from one tier. The incoming source ID is an authority fence,
     * while deterministic IDs and derivation keys make concurrent or replayed jobs converge on the same record.
     *
     * @param array<string,string> $scope
     * @return array<string,mixed>|null
     */
    public function consolidateMemories(array $scope, string $sourceTier, string $sourceMemoryId, string $now): ?array
    {
        $targetTier = $sourceTier === 'recent' ? 'mid' : 'long';
        $policy = (new MemorySummaryRepository($this->db))->policy($scope['installation_id'])['content'] ?? [];
        $minimum = $sourceTier === 'recent' ? (int)($policy['minimum_events'] ?? 4) : 4;
        $interval = $sourceTier === 'recent' ? (int)($policy['summary_interval'] ?? 0) * 864 : 0;
        $limit = $interval > 0 ? 16 : $minimum;
        $eligible = $sourceTier === 'recent'
            ? "m.source_event_id IS NOT NULL AND se.installation_id=m.installation_id "
                . "AND source_session.profile_id=m.profile_id AND source_session.playthrough_id=m.playthrough_id "
                . "AND ((se.event_kind='dialogue.delivery' AND delivery.status='played') "
                . "OR se.event_kind IN ('turn.requested','stt.transcript','action.result','location','death','narration'))"
            : "m.derivation_key IS NOT NULL AND m.provenance->>'source'='memory.consolidate' "
                . "AND m.provenance->>'provider'='first-party' "
                . "AND m.provenance->>'model'='deterministic-extractive-v1' "
                . "AND m.provenance->>'source_tier'='recent'";
        // A deleted summary still consumed its sources; do not endlessly regenerate its old batch.
        $sql = "WITH eligible AS (SELECT m.memory_id,m.content,m.source_event_id,m.provenance,m.occurred_at,"
            . "CASE WHEN jsonb_typeof(source_turn.context#>'{world,game_time}')='number' THEN (source_turn.context#>>'{world,game_time}')::numeric END AS game_time "
            . "FROM memory_records m LEFT JOIN source_events se ON se.source_event_id=m.source_event_id "
            . "LEFT JOIN sessions source_session ON source_session.session_id=se.session_id "
            . "LEFT JOIN turns source_turn ON source_turn.turn_id=se.turn_id "
            . "LEFT JOIN dialogue_delivery_results delivery ON delivery.source_event_id=se.source_event_id "
            . "WHERE m.installation_id=:installation AND m.profile_id=:profile AND m.playthrough_id=:playthrough "
            . "AND m.tier=:source_tier AND m.deleted_at IS NULL AND {$eligible}), "
            . "unused AS (SELECT e.* FROM eligible e WHERE NOT EXISTS (SELECT 1 FROM memory_records derived "
            . "CROSS JOIN LATERAL jsonb_array_elements_text(COALESCE(derived.provenance->'source_memory_ids','[]'::jsonb)) used(memory_id) "
            . "WHERE derived.installation_id=:installation AND derived.profile_id=:profile "
            . "AND derived.playthrough_id=:playthrough AND derived.tier=:target_tier "
            . "AND used.memory_id=e.memory_id::text) ORDER BY e.occurred_at,e.memory_id LIMIT {$limit}) "
            . "SELECT unused.*,(SELECT max(game_time) FROM eligible) AS latest_game_time FROM unused "
            . "WHERE EXISTS (SELECT 1 FROM eligible WHERE memory_id=:source_memory) "
            . "ORDER BY occurred_at,memory_id";
        $statement = $this->db->prepare($sql);
        $statement->execute($this->scope($scope) + [
            'source_tier' => $sourceTier,
            'target_tier' => $targetTier,
            'source_memory' => $sourceMemoryId,
        ]);
        $rows = $statement->fetchAll();
        if (count($rows) < $minimum) {
            return null;
        }
        if ($interval > 0 && $rows[0]['game_time'] !== null) {
            $end = (float)$rows[0]['game_time'] + $interval;
            // Only authoritative game time closes a bucket; a bounded full bucket can flush early.
            if ((float)$rows[0]['latest_game_time'] < $end && count($rows) < $limit) return null;
            $bucket = array_values(array_filter($rows, static fn(array $row): bool =>
                $row['game_time'] === null || (float)$row['game_time'] < $end));
            $rows = count($bucket) >= $minimum ? $bucket : array_slice($rows, 0, $minimum);
        }

        $sourceMemoryIds = [];
        $sourceEventIds = [];
        $parts = [];
        $sourceGameRanges = [];
        foreach ($rows as $row) {
            $sourceMemoryIds[] = (string) $row['memory_id'];
            // Game-time provenance describes inputs, not proof that a truncated summary covers them.
            $sourceProvenance = json_decode((string)$row['provenance'], true, 64, JSON_THROW_ON_ERROR);
            $range = $sourceTier === 'recent'
                ? ['from'=>$row['game_time'], 'to'=>$row['game_time']]
                : ($sourceProvenance['source_game_time_range'] ?? null);
            if (is_array($range) && is_numeric($range['from'] ?? null) && is_numeric($range['to'] ?? null)) {
                $from = (float)$range['from']; $to = (float)$range['to'];
                if (is_finite($from) && is_finite($to) && $from >= 0 && $from <= $to && $to <= 9_007_199_254_740_991)
                    $sourceGameRanges[] = ['from'=>$from, 'to'=>$to];
            }
            $content = trim((string) $row['content']);
            if ($content !== '' && !in_array($content, $parts, true)) {
                $parts[] = $content;
            }
            if ($sourceTier === 'recent') {
                $sourceEventIds[] = (string) $row['source_event_id'];
                continue;
            }
            $provenance = $sourceProvenance;
            foreach (($provenance['source_event_ids'] ?? []) as $sourceEventId) {
                if (is_string($sourceEventId) && !in_array($sourceEventId, $sourceEventIds, true)) {
                    $sourceEventIds[] = $sourceEventId;
                }
            }
        }
        $content = trim(mb_strcut(implode($targetTier === 'mid' ? "\n" : "\n\n", $parts), 0, 16384, 'UTF-8'));
        if ($content === '') {
            throw new RuntimeException('memory_consolidation_empty');
        }
        // Provenance lists every input; only this separate content proof survives summary truncation.
        $completeSources = [];
        foreach ($rows as $row) {
            if (\LorkhanServer\Application\MemoryPromptSelection::covers($content, (string) $row['content'])) $completeSources[] = (string) $row['memory_id'];
        }
        $utc = new \DateTimeZone('UTC');
        $sourceFrom = (new \DateTimeImmutable((string) $rows[0]['occurred_at']))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $sourceTo = (new \DateTimeImmutable((string) $rows[array_key_last($rows)]['occurred_at']))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $derivationKey = 'memory.consolidate:' . $targetTier . ':' . implode(',', $sourceMemoryIds);
        $memoryId = Uuid::deterministicV4($derivationKey);
        $memory = $scope + [
            'memory_id' => $memoryId,
            'tier' => $targetTier,
            'content' => $content,
            'occurred_at' => $sourceTo,
            'derivation_key' => $derivationKey,
            'provenance' => [
                'source' => 'memory.consolidate',
                'provider' => 'first-party',
                'model' => 'deterministic-extractive-v1',
                'revision' => 1,
                'source_tier' => $sourceTier,
                'source_memory_ids' => $sourceMemoryIds,
                'content_coverage' => ['algorithm' => 'exact-content-v1', 'content_sha256' => hash('sha256', $content),
                    'complete_source_memory_ids' => $completeSources],
                'source_event_ids' => $sourceEventIds,
                'source_range' => ['from' => $sourceFrom, 'to' => $sourceTo],
            ],
        ];
        // Partial or legacy timestamps cannot establish a boundary for the complete source bucket.
        if (count($sourceGameRanges) === count($rows)) {
            $memory['provenance']['source_game_time_range'] = [
                'from'=>min(array_column($sourceGameRanges, 'from')),
                'to'=>max(array_column($sourceGameRanges, 'to')),
            ];
        }
        // Commit the deterministic record and its optional durable job together so a failed enqueue is retryable.
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            $this->upsertConsolidatedMemory($memory, $now);
            (new MemorySummaryRepository($this->db))->enqueue($scope['installation_id'],$memoryId);
            if($owns)$this->db->commit();
        }catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw$error;}
        return $memory;
    }

    /** @param array<string,mixed> $memory */
    private function upsertConsolidatedMemory(array $memory, string $now): void
    {
        $content = (string) $memory['content'];
        $statement = $this->db->prepare('INSERT INTO memory_records '
            . '(memory_id,installation_id,profile_id,playthrough_id,tier,content,lexical_terms,fake_vector,source_event_id,provenance,occurred_at,expires_at,created_at,updated_at,derivation_key) '
            . 'VALUES (:id,:installation,:profile,:playthrough,:tier,:content,CAST(:terms AS text[]),CAST(:vector AS jsonb),NULL,CAST(:provenance AS jsonb),:occurred,NULL,:now,:now,:derivation) '
            . 'ON CONFLICT (memory_id) DO UPDATE SET tier=EXCLUDED.tier,content=EXCLUDED.content,lexical_terms=EXCLUDED.lexical_terms,'
            . 'fake_vector=EXCLUDED.fake_vector,source_event_id=NULL,provenance=EXCLUDED.provenance,occurred_at=EXCLUDED.occurred_at,'
            . 'expires_at=NULL,updated_at=EXCLUDED.updated_at,derivation_key=EXCLUDED.derivation_key '
            . 'WHERE memory_records.installation_id=EXCLUDED.installation_id AND memory_records.profile_id=EXCLUDED.profile_id '
            . 'AND memory_records.playthrough_id=EXCLUDED.playthrough_id AND memory_records.derivation_key=EXCLUDED.derivation_key '
            . 'AND (memory_records.tier, memory_records.content, memory_records.lexical_terms, memory_records.fake_vector, '
            . 'memory_records.provenance, memory_records.occurred_at) IS DISTINCT FROM '
            . '(EXCLUDED.tier, EXCLUDED.content, EXCLUDED.lexical_terms, EXCLUDED.fake_vector, EXCLUDED.provenance, EXCLUDED.occurred_at)');
        $statement->execute([
            'id' => $memory['memory_id'],
            'installation' => $memory['installation_id'],
            'profile' => $memory['profile_id'],
            'playthrough' => $memory['playthrough_id'],
            'tier' => $memory['tier'],
            'content' => $content,
            'terms' => $this->pgArray(DeterministicRetrieval::terms($content)),
            'vector' => $this->encode(DeterministicRetrieval::fakeVector($content)),
            'provenance' => $this->encode($memory['provenance']),
            'occurred' => $memory['occurred_at'],
            'now' => $now,
            'derivation' => $memory['derivation_key'],
        ]);
        if ($statement->rowCount() === 1) {
            $this->enqueueMemoryEmbedding((string)$memory['memory_id'],(string)$memory['installation_id']);
            return;
        }
        $existing = $this->db->prepare('SELECT installation_id,profile_id,playthrough_id,derivation_key FROM memory_records WHERE memory_id=:id');
        $existing->execute(['id' => $memory['memory_id']]);
        $row = $existing->fetch();
        if (!$row || $row['installation_id'] !== $memory['installation_id'] || $row['profile_id'] !== $memory['profile_id']
            || $row['playthrough_id'] !== $memory['playthrough_id'] || $row['derivation_key'] !== $memory['derivation_key']) {
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
            if($update->rowCount()===1)$this->enqueueMemoryEmbedding((string)$row['memory_id'],$scope['installation_id']);
        }
        return $count;
    }

    /** A queued diary with retired source turns must not start another provider request. */
    public function narrativeSourcesActive(array $turnIds): bool
    {
        return (new LoadedSaveTimeline($this->db))->sourcesActive($turnIds);
    }

    /** Job replays preserve soft deletion; they are not restoration requests. */
    public function upsertNarrative(string $narrativeId, array $narrative, string $now): void
    {
        $statement = $this->db->prepare('INSERT INTO narrative_records '
            . '(narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,created_at,updated_at) '
            . 'VALUES (:id,:installation,:profile,:playthrough,:kind,:title,:content,CAST(:provenance AS jsonb),:now,:now) '
            . 'ON CONFLICT (narrative_id) DO UPDATE SET kind=EXCLUDED.kind,title=EXCLUDED.title,content=EXCLUDED.content,'
            . 'provenance=EXCLUDED.provenance,updated_at=EXCLUDED.updated_at '
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

    /** Queue semantic work only after an opted-in policy exists; ordinary memory writes stay local. */
    private function enqueueMemoryEmbedding(string $memoryId,string $installation):void
    {
        $query=$this->db->prepare('SELECT current_revision FROM memory_records WHERE memory_id=:memory AND deleted_at IS NULL');
        $query->execute(['memory'=>$memoryId]);$revision=$query->fetchColumn();
        if($revision!==false)(new MemoryEmbeddingRepository($this->db))->enqueue($installation,$memoryId,(int)$revision);
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
