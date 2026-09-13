<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\{NpcMemoryDigestRepository,Uuid};
use InvalidArgumentException;

/** Page NPC eligibility after scene consolidation; provider generation stays in separate jobs. */
final class MemoryDigestScanJobHandler implements JobHandler
{
    public const TYPE='memory.digest.scan';
    public function __construct(private readonly NpcMemoryDigestRepository $digests){}
    public function supports(string $type,int $version):bool{return $type===self::TYPE&&$version===1;}
    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        unset($idempotencyKey);
        foreach(['installation_id','playthrough_id','memory_id']as$key)if(!is_string($payload[$key]??null)||!Uuid::isValid($payload[$key]))throw new InvalidArgumentException('invalid_digest_scan');
        if(!is_int($payload['memory_revision']??null)||$payload['memory_revision']<1
            ||(($payload['after_profile']??null)!==null&&(!is_string($payload['after_profile'])||!Uuid::isValid($payload['after_profile']))))throw new InvalidArgumentException('invalid_digest_scan');
        if(!$heartbeat())throw new OperationCancelled('lease_lost');$this->digests->scan($payload,$heartbeat);
    }
}
