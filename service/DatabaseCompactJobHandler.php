<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\JobRepository;
use LorkhanServer\Infrastructure\ManagementRepository;
use LorkhanServer\Infrastructure\Uuid;
use PDO;

/** Run explicitly requested compaction outside the web request, with a lease beyond the SQL deadline. */
final class DatabaseCompactJobHandler implements JobHandler
{
    public const TYPE = 'database.compact';
    public function __construct(private readonly PDO $db) {}
    public function supports(string $jobType, int $schemaVersion): bool { return $jobType === self::TYPE && $schemaVersion === 1; }
    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        $job=$payload['_job']??[]; unset($payload['_job']);
        if ($payload!==['operation'=>'compact'] || !Uuid::isValid($job['job_id']??'') || !Uuid::isValid($job['lease_token']??''))
            throw new \InvalidArgumentException('invalid_database_maintenance_job');
        if (!$heartbeat() || !(new JobRepository($this->db))->heartbeat($job['job_id'],$job['lease_token'],3600))
            throw new OperationCancelled('lease_lost');
        (new ManagementRepository($this->db))->compactDatabase(true);
    }
}
