<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

final class ProviderAttemptRepository
{
    private const MAX_ERROR_BYTES = 4096;

    public function __construct(private readonly PDO $db) {}

    public function start(
        string $attemptId,
        string $kind,
        string $providerName,
        string $operation,
        int $attemptNumber,
        ?string $requestId = null,
        ?string $turnId = null,
        ?string $jobId = null,
        ?string $model = null,
        ?string $configRevision = null,
        ?int $inputBytes = null,
        array $metadata = [],
    ): void {
        $statement = $this->db->prepare('INSERT INTO provider_attempts '
            . '(provider_attempt_id, provider_kind, provider_name, operation, request_id, turn_id, job_id, attempt_number, state, '
            . 'model, config_revision, input_bytes, metadata) VALUES '
            . '(:id, :kind, :provider, :operation, :request, :turn, :job, :attempt, \'started\', :model, :revision, :input, CAST(:metadata AS jsonb))');
        $statement->execute([
            'id' => $attemptId,
            'kind' => $kind,
            'provider' => $providerName,
            'operation' => $operation,
            'request' => $requestId,
            'turn' => $turnId,
            'job' => $jobId,
            'attempt' => $attemptNumber,
            'model' => $model,
            'revision' => $configRevision,
            'input' => $inputBytes,
            'metadata' => $this->encodeObject($metadata),
        ]);
    }

    public function finish(string $attemptId, string $state, ?int $outputBytes = null, ?string $errorCode = null, ?string $errorDetail = null): bool
    {
        if (!in_array($state, ['succeeded', 'failed', 'cancelled'], true)) {
            throw new RuntimeException('Invalid provider attempt terminal state.');
        }
        if ($state === 'succeeded' && ($errorCode !== null || $errorDetail !== null)) {
            throw new RuntimeException('Successful provider attempt cannot contain an error.');
        }
        // Provider/exception text is untrusted and may contain secrets. Persist stable codes only.
        $detail = null;
        $statement = $this->db->prepare('UPDATE provider_attempts SET state = :state, finished_at = clock_timestamp(), '
            . 'duration_ms = GREATEST(0, floor(extract(epoch FROM (clock_timestamp() - started_at)) * 1000)::integer), '
            . 'output_bytes = :output, error_code = :code, error_detail = :detail '
            . "WHERE provider_attempt_id = :id AND state = 'started'");
        $statement->execute(['state' => $state, 'output' => $outputBytes, 'code' => $errorCode, 'detail' => $detail, 'id' => $attemptId]);
        return $statement->rowCount() === 1;
    }

    private function encodeObject(array $value): string
    {
        if (array_is_list($value) && $value !== []) {
            throw new RuntimeException('Provider metadata must be a JSON object.');
        }
        return json_encode($value === [] ? (object) [] : $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
