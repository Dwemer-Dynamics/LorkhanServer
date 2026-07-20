<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Infrastructure\Repository;

final class DialogueExpiryJobHandler implements JobHandler
{
    public const TYPE='dialogue.expire';
    public function __construct(private readonly Repository $repository){}
    public function supports(string $jobType,int $schemaVersion):bool{return$jobType===self::TYPE&&$schemaVersion===1;}
    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        $id=$payload['dialogue_message_id']??null;
        if(!is_string($id)||preg_match('/^[0-9a-f-]{36}$/D',$id)!==1)throw new \InvalidArgumentException('invalid_dialogue_message_id');
        if(!$heartbeat())throw new \RuntimeException('lease_lost');
        $this->repository->expireDialogue($id);
    }
}
