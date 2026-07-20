<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Infrastructure\ProductRepository;
use InvalidArgumentException;

final class ProductService
{
    public function __construct(private readonly ProductRepository $repository, private readonly DeterministicClock $clock) {}

    /** @param array<string,mixed> $input */
    public function createRevisioned(string $kind, array $input): array
    {
        $allowed = ['profile', 'playthrough', 'prompt', 'provider', 'action_policy'];
        if (!in_array($kind, $allowed, true)) throw new InvalidArgumentException('invalid_resource_kind');
        $this->requireUuid($input, 'installation_id');
        $this->boundedString($input, 'name', 1, 256);
        if (!isset($input['content']) || !is_array($input['content']) || array_is_list($input['content'])) throw new InvalidArgumentException('invalid_content');
        $this->assertNoSecrets($input['content']);
        if ($kind === 'provider') {
            $provider = $input['content'];
            if (($provider['driver'] ?? null) !== 'mock' || isset($provider['api_key']) || isset($provider['credential'])) {
                throw new InvalidArgumentException('only_mock_provider_supported');
            }
            $provider += ['driver' => 'mock', 'model' => 'deterministic-mock-v1', 'timeout_ms' => 1000];
            $input['content'] = $provider;
        }
        return $this->repository->createRevisioned($kind, $input, $this->clock->iso());
    }

    /** @param array<string,mixed> $content */
    public function revise(string $kind, string $id, array $content, string $reason): array
    {
        $this->uuid($id);
        if(!in_array($kind,['profile','playthrough','prompt','provider','action_policy'],true)||$this->repository->resourceKind($id)!==$kind)throw new InvalidArgumentException('resource_kind_mismatch');
        if ($reason === '' || strlen($reason) > 512) throw new InvalidArgumentException('invalid_reason');
        $this->assertNoSecrets($content);$content=$this->validateProvider($kind,$content);
        return $this->repository->revise($kind, $id, $content, $reason, $this->clock->iso());
    }

    public function rollback(string $kind, string $id, int $revision, string $reason): array
    {
        if ($revision < 1) throw new InvalidArgumentException('invalid_revision');
        $this->uuid($id);if($this->repository->resourceKind($id)!==$kind)throw new InvalidArgumentException('resource_kind_mismatch');
        $content=$this->repository->revisionContent($kind,$id,$revision);$this->assertNoSecrets($content);$this->validateProvider($kind,$content);
        return $this->repository->rollback($kind, $id, $revision, $reason, $this->clock->iso());
    }

    /** @param array<string,mixed> $input */
    public function createMemory(array $input): array
    {
        $this->scope($input);
        if (!in_array($input['tier'] ?? null, ['recent', 'mid', 'long'], true)) throw new InvalidArgumentException('invalid_memory_tier');
        $this->boundedString($input, 'content', 1, 16384);
        $input['provenance'] = $this->provenance($input);
        return $this->repository->createMemory($input, DeterministicRetrieval::terms($input['content']),
            DeterministicRetrieval::fakeVector($input['content']), $this->clock->iso());
    }

    /** @param array<string,mixed> $scope */
    public function searchMemory(array $scope, string $query, int $limit = 10): array
    {
        $this->scope($scope);
        $this->boundedQuery($query, $limit);
        $rows = $this->repository->memoryCandidates($scope, $this->clock->iso());
        foreach ($rows as &$row) {
            $row['score'] = DeterministicRetrieval::score($query, $row['lexical_terms'], $row['fake_vector']);
        }
        return $this->rankAndTrace('memory', $scope, $query, $rows, $limit);
    }

    /** @param array<string,mixed> $input */
    public function ingestKnowledge(array $input): array
    {
        $this->requireUuid($input, 'installation_id');
        $this->boundedString($input, 'title', 1, 256);
        $this->boundedString($input, 'content', 1, 131072);
        foreach (['profile_id', 'playthrough_id'] as $field) if (isset($input[$field]) && $input[$field] !== null) $this->uuid((string) $input[$field]);
        $input['provenance'] = $this->provenance($input);
        return $this->repository->createKnowledge($input, DeterministicRetrieval::terms($input['content']), $this->clock->iso());
    }

    /** @param array<string,mixed> $scope */
    public function searchKnowledge(array $scope, string $query, int $limit = 10): array
    {
        $this->requireUuid($scope, 'installation_id');
        foreach (['profile_id', 'playthrough_id'] as $field) if (isset($scope[$field]) && $scope[$field] !== null) $this->uuid((string) $scope[$field]);
        $this->boundedQuery($query, $limit);
        $rows = $this->repository->knowledgeCandidates($scope);
        foreach ($rows as &$row) {
            $row['score'] = DeterministicRetrieval::score($query, $row['lexical_terms'], DeterministicRetrieval::fakeVector($row['content']));
        }
        return $this->rankAndTrace('knowledge', $scope, $query, $rows, $limit);
    }

    /** @param array<string,mixed> $input */
    public function setRelationship(array $input): array
    {
        $this->scope($input);
        if (!isset($input['actor_identity']) || !is_array($input['actor_identity']) || array_is_list($input['actor_identity'])) throw new InvalidArgumentException('invalid_actor_identity');
        foreach (['disposition', 'affinity'] as $field) if (!isset($input[$field]) || !is_int($input[$field]) || $input[$field] < -100 || $input[$field] > 100) throw new InvalidArgumentException('invalid_relationship_value');
        if (!in_array($input['source_mode'] ?? null, ['derived', 'manual'], true)) throw new InvalidArgumentException('invalid_source_mode');
        return $this->repository->setRelationship($input, $this->clock->iso());
    }

    /** @param array<string,mixed> $input */
    public function createNarrative(array $input): array
    {
        $this->scope($input);
        if (!in_array($input['kind'] ?? null, ['narrator', 'diary', 'summary'], true)) throw new InvalidArgumentException('invalid_narrative_kind');
        $this->boundedString($input, 'title', 1, 256);
        $this->boundedString($input, 'content', 1, 65536);
        $input['provenance'] = $this->provenance($input);
        return $this->repository->createNarrative($input, $this->clock->iso());
    }

    /** @param array<string,mixed> $input */
    public function scheduleAutonomy(array $input): array
    {
        $this->scope($input);
        if (!in_array($input['kind'] ?? null, ['rechat', 'boredom', 'greeting'], true)) throw new InvalidArgumentException('invalid_schedule_kind');
        foreach (['interval_seconds', 'cooldown_seconds'] as $field) if (!isset($input[$field]) || !is_int($input[$field]) || $input[$field] < 30 || $input[$field] > 86400) throw new InvalidArgumentException('invalid_schedule');
        if (($input['enabled'] ?? false) === true && (!isset($input['current_session_id']) || !isset($input['confirmed_at']))) throw new InvalidArgumentException('session_confirmation_required');
        if (isset($input['current_session_id'])) $this->uuid((string) $input['current_session_id']);
        return $this->repository->scheduleAutonomy($input, $this->clock->iso());
    }

    /** @param array<string,mixed> $scope */
    public function dueAutonomy(array $scope): array
    {
        $this->scope($scope);
        return $this->repository->dueAutonomy($scope, $this->clock->iso());
    }

    /** @param array<string,mixed> $scope */
    public function exportPlaythrough(array $scope): array
    {
        $this->scope($scope);
        return ['schema' => 'almsivi.playthrough-export.v1', 'exported_at' => $this->clock->iso(),
            'scope' => $scope, 'data' => $this->repository->exportScope($scope)];
    }

    /** @param array<string,mixed> $document */
    public function restorePlaythrough(array $document): array
    {
        $keys=array_keys($document);sort($keys);if($keys!==['data','exported_at','schema','scope']||($document['schema']??null)!=='almsivi.playthrough-export.v1'||!is_string($document['exported_at'])
            ||!is_array($document['scope'])||array_is_list($document['scope'])||!is_array($document['data'])||array_is_list($document['data']))throw new InvalidArgumentException('invalid_restore');
        $dataKeys=array_keys($document['data']);sort($dataKeys);if($dataKeys!==['memories','narratives','relationships'])throw new InvalidArgumentException('invalid_restore');
        foreach($document['data'] as$rows)if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('invalid_restore');
        foreach($document['data']['memories'] as$r)if(!is_array($r)||!in_array($r['tier']??null,['recent','mid','long'],true)||!is_string($r['content']??null)||!is_array($r['lexical_terms']??null)||!is_string($r['occurred_at']??null))throw new InvalidArgumentException('invalid_restore');
        foreach($document['data']['relationships'] as$r)if(!is_array($r)||!is_array($r['actor_identity']??null)||!is_numeric($r['disposition']??null)||!is_numeric($r['affinity']??null))throw new InvalidArgumentException('invalid_restore');
        foreach($document['data']['narratives'] as$r)if(!is_array($r)||!is_string($r['kind']??null)||!is_string($r['title']??null)||!is_string($r['content']??null))throw new InvalidArgumentException('invalid_restore');
        $this->scope($document['scope']);
        return $this->repository->restoreScope($document, $this->clock->iso());
    }

    /** @param array<string,mixed> $scope @param list<array<string,mixed>> $rows */
    private function rankAndTrace(string $domain, array $scope, string $query, array $rows, int $limit): array
    {
        usort($rows, static fn(array $a, array $b): int => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);
        $rows = array_slice($rows, 0, $limit);
        return $this->repository->recordRetrieval($domain, $scope, $query, $rows, $this->clock->iso());
    }

    private function provenance(array $input): array
    {
        $value = $input['provenance'] ?? null;
        if (!is_array($value) || array_is_list($value) || !is_string($value['source'] ?? null) || $value['source'] === '') throw new InvalidArgumentException('provenance_required');
        return $value;
    }

    private function scope(array $input): void
    {
        foreach (['installation_id', 'profile_id', 'playthrough_id'] as $field) $this->requireUuid($input, $field);
    }

    private function boundedQuery(string $query, int $limit): void
    {
        if ($query === '' || strlen($query) > 4096 || $limit < 1 || $limit > 50) throw new InvalidArgumentException('invalid_query');
    }

    private function requireUuid(array $input, string $field): void { if (!isset($input[$field]) || !is_string($input[$field])) throw new InvalidArgumentException('invalid_' . $field); $this->uuid($input[$field]); }
    private function uuid(string $value): void { if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) throw new InvalidArgumentException('invalid_uuid'); }
    private function boundedString(array $input, string $field, int $min, int $max): void { if (!isset($input[$field]) || !is_string($input[$field]) || strlen($input[$field]) < $min || strlen($input[$field]) > $max || !mb_check_encoding($input[$field], 'UTF-8')) throw new InvalidArgumentException('invalid_' . $field); }

    private function validateProvider(string $kind,array $content):array
    {
        if($kind!=='provider')return$content;
        if(($content['driver']??null)!=='mock')throw new InvalidArgumentException('only_mock_provider_supported');
        return$content+['model'=>'deterministic-mock-v1','timeout_ms'=>1000];
    }

    private function assertNoSecrets(array $content): void
    {
        $walk = static function (mixed $value) use (&$walk): void {
            if (!is_array($value)) return;
            foreach ($value as $key => $child) {
                if (is_string($key) && preg_match('/(?:secret|password|api[_-]?key|authorization|credential|token)/i', $key)) throw new InvalidArgumentException('secret_not_accepted');
                $walk($child);
            }
        };
        $walk($content);
    }
}
