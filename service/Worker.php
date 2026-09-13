<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\JobRepository;
use Throwable;

final class Worker
{
    /** @param callable(int):void|null $sleep */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobHandlerRegistry $handlers,
        private readonly string $workerId,
        private readonly int $leaseSeconds = 30,
        private readonly int $batchSize = 1,
        private readonly int $maxJobs = 100,
        private readonly int $idleExitSeconds = 30,
        private readonly int $maxRuntimeSeconds = 300,
        private readonly ?array $types = null,
        private readonly mixed $sleep = null,
    ) {}

    /** @return array{claimed:int,succeeded:int,retried:int,dead:int} */
    public function run(): array
    {
        $stats = ['claimed' => 0, 'succeeded' => 0, 'retried' => 0, 'dead' => 0];
        $started = hrtime(true);
        $lastWork = $started;
        while ($stats['claimed'] < $this->maxJobs && $this->secondsSince($started) < $this->maxRuntimeSeconds) {
            if(!$this->jobs->enterRuntime()){
                // Avoid rapidly restarting the daemon while another worker restores the database.
                if($this->secondsSince($lastWork)>=$this->idleExitSeconds)break;
                ($this->sleep ?? static fn(int $microseconds): mixed => usleep($microseconds))(200_000);
                continue;
            }
            $runtimeGate=true;
            try{
                // Claim just in time so queued work cannot expire while an earlier batch member runs.
                $claimed = $this->jobs->claim($this->workerId, 1, $this->leaseSeconds, $this->types);
                if ($claimed === []) {
                    $this->jobs->leaveRuntime();$runtimeGate=false;
                    if ($this->secondsSince($lastWork) >= $this->idleExitSeconds) {
                        break;
                    }
                    ($this->sleep ?? static fn(int $microseconds): mixed => usleep($microseconds))(200_000);
                    continue;
                }
                $lastWork = hrtime(true);
                foreach ($claimed as $job) {
                    if(in_array($job['job_type'],['database.restore','database.replay','database.factory_reset','database.import'],true)){
                        $this->jobs->leaveRuntime();$runtimeGate=false;
                    }
                    ++$stats['claimed'];
                    $heartbeat = fn(): bool => $this->jobs->heartbeat($job['job_id'], $job['lease_token'], $this->leaseSeconds);
                    try {
                        $handler = $this->handlers->for($job['job_type'], $job['schema_version']);
                        $payload=$job['payload']+['_job'=>['job_id'=>$job['job_id'],'lease_token'=>$job['lease_token'],'attempt'=>$job['attempt_count']]];
                        $handler->handle($payload, $job['idempotency_key'], $heartbeat);
                        $this->jobs->succeed($job['job_id'], $job['lease_token']);
                        ++$stats['succeeded'];
                        // Reload handler/profile state in a fresh worker after replacing the database.
                        if(in_array($job['job_type'],['database.restore','database.replay','database.factory_reset','database.import'],true))return $stats;
                    } catch (Throwable $error) {
                        $code = $error->getMessage() === 'unsupported_job_type' ? 'unsupported_job_type' : 'handler_failed';
                        $delay = min(3600, 2 ** min(10, max(0, $job['attempt_count'] - 1)));
                        try {
                            // Exception text may contain provider payloads or credentials; persist only stable codes.
                            $outcome = $this->jobs->fail($job['job_id'], $job['lease_token'], $code, null, $delay);
                            ++$stats[$outcome === 'dead' ? 'dead' : 'retried'];
                        } catch (Throwable $leaseError) {
                            if ($leaseError->getMessage() !== 'lease_lost') {
                                throw $leaseError;
                            }
                        }
                    }
                }
            }finally{if($runtimeGate)$this->jobs->leaveRuntime();}
        }
        return $stats;
    }

    private function secondsSince(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000_000;
    }
}
