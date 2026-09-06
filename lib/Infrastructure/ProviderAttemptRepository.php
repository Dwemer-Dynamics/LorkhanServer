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

    /** Store only measured token counts and explicit USD cost supplied by the provider adapter. */
    public function recordUsage(string $attemptId,array $usage):void
    {
        $safe=[];
        foreach(['prompt_tokens','completion_tokens','total_tokens']as$key)
            if(is_int($usage[$key]??null)&&$usage[$key]>=0&&$usage[$key]<=100_000_000)$safe[$key]=$usage[$key];
        if((is_float($usage['cost_usd']??null)||is_int($usage['cost_usd']??null))&&is_finite((float)$usage['cost_usd'])
            &&$usage['cost_usd']>=0&&$usage['cost_usd']<=1_000_000)$safe['cost_usd']=$usage['cost_usd'];
        if($safe===[])return;
        $this->db->prepare("UPDATE provider_attempts SET metadata=jsonb_set(metadata,'{usage}',CAST(:usage AS jsonb)) WHERE provider_attempt_id=:id")
            ->execute(['id'=>$attemptId,'usage'=>$this->encodeObject($safe)]);
    }

    /** Capture only message role/content; typed mock input is explicitly not an exact provider prompt. */
    public function recordRelationshipRequest(string $attempt,array $messages,bool $exact=true):void
    {
        if(count($messages)<1||count($messages)>2)throw new \InvalidArgumentException('invalid_relationship_log_request');
        $safe=[];
        foreach($messages as $message){
            if(!is_array($message)||!in_array($message['role']??null,['system','user'],true)||!is_string($message['content']??null))
                throw new \InvalidArgumentException('invalid_relationship_log_request');
            $safe[]=['role'=>$message['role'],'content'=>$message['content']];
        }
        $this->recordRelationshipEvidence($attempt,'relationship_request',['exact'=>$exact,'messages'=>$safe]);
    }

    /** Retain validated model output separately; it is not proof that any change was saved. */
    public function recordRelationshipProposal(string $attempt,array $output):void
    {
        $output=array_key_exists('relationships',$output)
            ?\LorkhanServer\Application\RelationshipBuildPolicy::output($output)
            :\LorkhanServer\Application\RelationshipEvaluationPolicy::output($output);
        $this->recordRelationshipEvidence($attempt,'relationship_proposal',$output);
    }

    /** Called inside the fenced relationship-save transaction so the receipt cannot outlive rollback. */
    public function recordRelationshipApplied(string $attempt,string $job,array $changes):void
    {
        if(!$this->db->inTransaction())throw new RuntimeException('relationship_evidence_requires_transaction');
        if(count($changes)>20)throw new \InvalidArgumentException('invalid_relationship_log_changes');
        $keys=array_fill_keys(['target','affinity_delta','disposition_delta','old_type','type','reason'],true);
        foreach($changes as &$change){
            if(!is_array($change))throw new \InvalidArgumentException('invalid_relationship_log_changes');
            $change=array_intersect_key($change,$keys);
            foreach(['target','old_type','type','reason'] as $key)if(!is_string($change[$key]??null)||strlen($change[$key])>4096)
                throw new \InvalidArgumentException('invalid_relationship_log_changes');
            foreach(['affinity_delta','disposition_delta'] as $key)if(!is_int($change[$key]??null)||abs($change[$key])>200)
                throw new \InvalidArgumentException('invalid_relationship_log_changes');
        }unset($change);
        $this->recordRelationshipEvidence($attempt,'relationship_applied',$changes,$job);
    }

    private function recordRelationshipEvidence(string $attempt,string $field,array $value,?string $job=null):void
    {
        $json=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(strlen($json)>196608)throw new \InvalidArgumentException('relationship_log_too_large');
        $statement=$this->db->prepare("UPDATE provider_attempts SET metadata=jsonb_set(metadata,ARRAY[CAST(:field AS text)],CAST(:value AS jsonb))
            WHERE provider_attempt_id=:attempt AND state='started' AND provider_kind='llm'
            AND operation IN ('evaluate_relationship','build_relationships')".($job===null?'':' AND job_id=:job'));
        $params=['attempt'=>$attempt,'field'=>$field,'value'=>$json];if($job!==null)$params['job']=$job;
        $statement->execute($params);
        if($statement->rowCount()!==1)throw new \LorkhanServer\Application\OperationCancelled('relationship_attempt_not_current');
    }

    private function encodeObject(array $value): string
    {
        if (array_is_list($value) && $value !== []) {
            throw new RuntimeException('Provider metadata must be a JSON object.');
        }
        return json_encode($value === [] ? (object) [] : $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
