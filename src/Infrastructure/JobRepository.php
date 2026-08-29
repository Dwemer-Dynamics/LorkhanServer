<?php

declare(strict_types=1);

namespace LORKHANserver\Infrastructure;

use PDO;
use RuntimeException;
use Throwable;

final class JobRepository
{
    private const MAX_ERROR_BYTES = 4096;

    public function __construct(private readonly PDO $db) {}

    public function enqueue(
        string $jobId,
        string $type,
        int $schemaVersion,
        string $idempotencyKey,
        array $payload,
        int $maxAttempts = 5,
        ?string $nextRunAt = null,
        int $priority = 0,
    ): array {
        $statement = $this->db->prepare('INSERT INTO durable_jobs '
            . '(job_id, job_type, schema_version, idempotency_key, payload, max_attempts, next_run_at, priority) VALUES '
            . '(:id, :type, :schema, :key, CAST(:payload AS jsonb), :max_attempts, COALESCE(CAST(:next_run AS timestamptz), clock_timestamp()), :priority) '
            . 'ON CONFLICT (job_type, idempotency_key) DO NOTHING RETURNING *');
        $statement->execute([
            'id' => $jobId,
            'type' => $type,
            'schema' => $schemaVersion,
            'key' => $idempotencyKey,
            'payload' => $this->encodeObject($payload),
            'max_attempts' => $maxAttempts,
            'next_run' => $nextRunAt,
            'priority' => $priority,
        ]);
        $row = $statement->fetch();
        if (!$row) {
            $existing = $this->db->prepare('SELECT * FROM durable_jobs WHERE job_type = :type AND idempotency_key = :key');
            $existing->execute(['type' => $type, 'key' => $idempotencyKey]);
            $row = $existing->fetch();
            if (!$row || (int) $row['schema_version'] !== $schemaVersion || $this->json((string) $row['payload']) != $payload) {
                throw new RuntimeException('job_idempotency_conflict');
            }
        }
        return $this->job($row);
    }

    /** @return list<array<string,mixed>> */
    public function claim(string $workerId, int $limit, int $leaseSeconds, ?array $types = null): array
    {
        if ($workerId === '' || strlen($workerId) > 255 || $limit < 1 || $limit > 100 || $leaseSeconds < 5 || $leaseSeconds > 3600) {
            throw new RuntimeException('Invalid claim bounds.');
        }
        if ($types !== null && ($types === [] || count($types) > 100)) {
            throw new RuntimeException('Job type filter is invalid.');
        }

        return $this->transaction(function () use ($workerId, $limit, $leaseSeconds, $types): array {
            $parameters = ['limit' => $limit];
            $typeSql = '';
            if ($types !== null) {
                $placeholders = [];
                foreach (array_values($types) as $index => $type) {
                    if (!is_string($type) || $type === '') {
                        throw new RuntimeException('Job type filter is invalid.');
                    }
                    $name = 'type_' . $index;
                    $placeholders[] = ':' . $name;
                    $parameters[$name] = $type;
                }
                $typeSql = ' AND job_type IN (' . implode(', ', $placeholders) . ')';
            }
            $select = $this->db->prepare("SELECT job_id FROM durable_jobs WHERE ("
                . "(state = 'queued' AND attempt_count < max_attempts AND next_run_at <= clock_timestamp()) OR "
                . "(state = 'leased' AND lease_expires_at <= clock_timestamp())){$typeSql} "
                . 'ORDER BY priority DESC, next_run_at, created_at, job_id FOR UPDATE SKIP LOCKED LIMIT :limit');
            foreach ($parameters as $name => $value) {
                $select->bindValue(':' . $name, $value, $name === 'limit' ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $select->execute();
            $ids = $select->fetchAll(PDO::FETCH_COLUMN);
            $jobs = [];
            foreach ($ids as $id) {
                $current = $this->db->prepare('SELECT state, lease_token, attempt_count, max_attempts FROM durable_jobs WHERE job_id = :id FOR UPDATE');
                $current->execute(['id' => $id]);
                $before = $current->fetch();
                if ($before['state'] === 'leased') {
                    $final=(int)$before['attempt_count'] >= (int)$before['max_attempts'];
                    $expire = $this->db->prepare("UPDATE durable_job_attempts SET finished_at = clock_timestamp(), outcome = :outcome, "
                        . "error_code = 'lease_expired' WHERE lease_token = :token AND finished_at IS NULL");
                    $expire->execute(['outcome'=>$final?'dead':'lease_expired','token' => $before['lease_token']]);
                    if($final){
                        $this->db->prepare("UPDATE durable_jobs SET state='dead',completed_at=clock_timestamp(),lease_owner=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,heartbeat_at=NULL,last_error_code='lease_expired',last_error_detail=NULL,updated_at=clock_timestamp() WHERE job_id=:id")->execute(['id'=>$id]);
                        $this->db->prepare("INSERT INTO durable_job_dead_letters(job_id,failed_attempt_number,error_code,error_detail) VALUES(:job,:attempt,'lease_expired',NULL) ON CONFLICT(job_id) DO NOTHING")->execute(['job'=>$id,'attempt'=>$before['attempt_count']]);
                        continue;
                    }
                }
                $token = Uuid::v4();
                $attempt = (int) $before['attempt_count'] + 1;
                $update = $this->db->prepare("UPDATE durable_jobs SET state = 'leased', attempt_count = :attempt, lease_owner = :worker, "
                    . 'lease_token = :token, leased_at = clock_timestamp(), heartbeat_at = clock_timestamp(), '
                    . "lease_expires_at = clock_timestamp() + (:seconds * interval '1 second'), updated_at = clock_timestamp() "
                    . 'WHERE job_id = :id RETURNING *');
                $update->execute(['attempt' => $attempt, 'worker' => $workerId, 'token' => $token, 'seconds' => $leaseSeconds, 'id' => $id]);
                $row = $update->fetch();
                $insert = $this->db->prepare('INSERT INTO durable_job_attempts '
                    . '(job_id, attempt_number, lease_token, worker_id, started_at, heartbeat_at) '
                    . 'VALUES (:job, :attempt, :token, :worker, clock_timestamp(), clock_timestamp())');
                $insert->execute(['job' => $id, 'attempt' => $attempt, 'token' => $token, 'worker' => $workerId]);
                $jobs[] = $this->job($row);
            }
            return $jobs;
        });
    }

    public function heartbeat(string $jobId, string $leaseToken, int $leaseSeconds): bool
    {
        if ($leaseSeconds < 5 || $leaseSeconds > 3600) {
            throw new RuntimeException('Invalid lease duration.');
        }
        return $this->transaction(function () use ($jobId, $leaseToken, $leaseSeconds): bool {
            $statement = $this->db->prepare("UPDATE durable_jobs SET heartbeat_at = clock_timestamp(), "
                . "lease_expires_at = clock_timestamp() + (:seconds * interval '1 second'), updated_at = clock_timestamp() "
                . "WHERE job_id = :job AND state = 'leased' AND lease_token = :token AND lease_expires_at > clock_timestamp()");
            $statement->execute(['seconds' => $leaseSeconds, 'job' => $jobId, 'token' => $leaseToken]);
            if ($statement->rowCount() !== 1) {
                return false;
            }
            $attempt = $this->db->prepare('UPDATE durable_job_attempts SET heartbeat_at = clock_timestamp() '
                . 'WHERE job_id = :job AND lease_token = :token AND finished_at IS NULL');
            $attempt->execute(['job' => $jobId, 'token' => $leaseToken]);
            if ($attempt->rowCount() !== 1) {
                throw new RuntimeException('Active job attempt is missing.');
            }
            return true;
        });
    }

    public function succeed(string $jobId, string $leaseToken): bool
    {
        $this->finish($jobId, $leaseToken, 'succeeded', null, null, 0);
        return true;
    }

    public function fail(string $jobId, string $leaseToken, string $errorCode, ?string $errorDetail, int $retryDelaySeconds): string
    {
        if ($errorCode === '' || strlen($errorCode) > 255 || $retryDelaySeconds < 0 || $retryDelaySeconds > 86_400) {
            throw new RuntimeException('Invalid job failure metadata.');
        }
        return $this->finish($jobId, $leaseToken, 'failed', $errorCode, $errorDetail, $retryDelaySeconds) ? 'dead' : 'retry';
    }

    public function replayDeadLetter(string $jobId, string $newJobId, string $newIdempotencyKey): array
    {
        return $this->transaction(function () use ($jobId, $newJobId, $newIdempotencyKey): array {
            $statement = $this->db->prepare('SELECT j.*, d.dead_letter_id, d.replayed_at FROM durable_jobs j '
                . 'JOIN durable_job_dead_letters d ON d.job_id = j.job_id WHERE j.job_id = :id FOR UPDATE OF j, d');
            $statement->execute(['id' => $jobId]);
            $row = $statement->fetch();
            if (!$row || $row['state'] !== 'dead') {
                throw new RuntimeException('dead_letter_not_found');
            }
            if ($row['replayed_at'] !== null) {
                throw new RuntimeException('dead_letter_already_replayed');
            }
            if($newIdempotencyKey===(string)$row['idempotency_key'])throw new RuntimeException('dead_letter_replay_key_collision');
            $collision=$this->db->prepare('SELECT 1 FROM durable_jobs WHERE job_type=:type AND idempotency_key=:key');
            $collision->execute(['type'=>$row['job_type'],'key'=>$newIdempotencyKey]);
            if($collision->fetchColumn())throw new RuntimeException('dead_letter_replay_key_collision');
            $job = $this->enqueue($newJobId, (string) $row['job_type'], (int) $row['schema_version'], $newIdempotencyKey,
                $this->json((string) $row['payload']), (int) $row['max_attempts'], null, (int) $row['priority']);
            $update = $this->db->prepare('UPDATE durable_job_dead_letters SET replayed_at = clock_timestamp(), replay_job_id = :replay '
                . 'WHERE dead_letter_id = :id AND replayed_at IS NULL');
            $update->execute(['replay' => $job['job_id'], 'id' => $row['dead_letter_id']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('dead_letter_already_replayed');
            }
            return $job;
        });
    }

    private function finish(
        string $jobId,
        string $leaseToken,
        string $result,
        ?string $errorCode,
        ?string $errorDetail,
        int $retryDelaySeconds,
    ): bool {
        return $this->transaction(function () use ($jobId, $leaseToken, $result, $errorCode, $errorDetail, $retryDelaySeconds): bool {
            $select = $this->db->prepare('SELECT * FROM durable_jobs WHERE job_id = :job FOR UPDATE');
            $select->execute(['job' => $jobId]);
            $job = $select->fetch();
            if (!$job || $job['state'] !== 'leased' || $job['lease_token'] !== $leaseToken) {
                throw new RuntimeException('lease_lost');
            }
            if (new \DateTimeImmutable((string) $job['lease_expires_at']) <= new \DateTimeImmutable('now')) {
                throw new RuntimeException('lease_lost');
            }
            // Never persist arbitrary exception/provider text; callers may only use stable error codes.
            $detail = null;
            if ($result === 'succeeded') {
                $state = 'succeeded';
                $outcome = 'succeeded';
                $update = $this->db->prepare("UPDATE durable_jobs SET state = 'succeeded', completed_at = clock_timestamp(), "
                    . 'lease_owner = NULL, lease_token = NULL, leased_at = NULL, lease_expires_at = NULL, heartbeat_at = NULL, '
                    . 'last_error_code = NULL, last_error_detail = NULL, updated_at = clock_timestamp() '
                    . 'WHERE job_id = :job AND lease_token = :token');
                $update->execute(['job' => $jobId, 'token' => $leaseToken]);
            } else {
                $dead = (int) $job['attempt_count'] >= (int) $job['max_attempts'];
                $state = $dead ? 'dead' : 'queued';
                $outcome = $dead ? 'dead' : 'retry';
                $update = $this->db->prepare("UPDATE durable_jobs SET state = :state, next_run_at = clock_timestamp() + (:delay * interval '1 second'), "
                    . 'lease_owner = NULL, lease_token = NULL, leased_at = NULL, lease_expires_at = NULL, heartbeat_at = NULL, '
                    . 'last_error_code = :code, last_error_detail = :detail, completed_at = CASE WHEN :state = \'dead\' THEN clock_timestamp() ELSE NULL END, '
                    . 'updated_at = clock_timestamp() WHERE job_id = :job AND lease_token = :token');
                $update->execute(['state' => $state, 'delay' => $retryDelaySeconds, 'code' => $errorCode, 'detail' => $detail,
                    'job' => $jobId, 'token' => $leaseToken]);
                if ($dead) {
                    $letter = $this->db->prepare('INSERT INTO durable_job_dead_letters '
                        . '(job_id, failed_attempt_number, error_code, error_detail) VALUES (:job, :attempt, :code, :detail)');
                    $letter->execute(['job' => $jobId, 'attempt' => $job['attempt_count'], 'code' => $errorCode, 'detail' => $detail]);
                }
            }
            $attempt = $this->db->prepare('UPDATE durable_job_attempts SET finished_at = clock_timestamp(), heartbeat_at = clock_timestamp(), '
                . 'outcome = :outcome, error_code = :code, error_detail = :detail '
                . 'WHERE job_id = :job AND lease_token = :token AND finished_at IS NULL');
            $attempt->execute(['outcome' => $outcome, 'code' => $errorCode, 'detail' => $detail, 'job' => $jobId, 'token' => $leaseToken]);
            if ($attempt->rowCount() !== 1) {
                throw new RuntimeException('Active job attempt is missing.');
            }
            return $state === 'dead';
        });
    }

    private function transaction(callable $callback): mixed
    {
        $owns = !$this->db->inTransaction();
        if ($owns) {
            $this->db->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($owns && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function job(array $row): array
    {
        return [
            'job_id' => (string) $row['job_id'],
            'job_type' => (string) $row['job_type'],
            'schema_version' => (int) $row['schema_version'],
            'idempotency_key' => (string) $row['idempotency_key'],
            'payload' => $this->json((string) $row['payload']),
            'state' => (string) $row['state'],
            'attempt_count' => (int) $row['attempt_count'],
            'max_attempts' => (int) $row['max_attempts'],
            'lease_token' => $row['lease_token'] === null ? null : (string) $row['lease_token'],
            'lease_expires_at' => $row['lease_expires_at'] === null ? null : (string) $row['lease_expires_at'],
        ];
    }

    private function encodeObject(array $value): string
    {
        if (array_is_list($value) && $value !== []) {
            throw new RuntimeException('Job payload must be a JSON object.');
        }
        return json_encode($value === [] ? (object) [] : $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function json(string $value): array
    {
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : throw new RuntimeException('Stored job payload is invalid.');
    }

    private function truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return strlen($value) <= self::MAX_ERROR_BYTES ? $value : substr($value, 0, self::MAX_ERROR_BYTES);
    }
}
