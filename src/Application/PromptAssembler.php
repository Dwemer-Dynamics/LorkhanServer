<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use InvalidArgumentException;
use JsonException;

/**
 * Builds transient provider input and metadata-only trace data.
 *
 * Persistence and source selection deliberately remain outside this class: callers must select
 * revisioned, scope-authorized rows and may persist only the returned trace, never provider_input.
 */
final class PromptAssembler
{
    private const ALGORITHM = 'chim-roleplay-prompt-v1';

    /** @var array<string,array{limit:int,bytes:int}> */
    private const SECTIONS = [
        'profile' => ['limit' => 1, 'bytes' => 12_288],
        'core_profile' => ['limit' => 1, 'bytes' => 65_536],
        'prompt' => ['limit' => 1, 'bytes' => 24_576],
        'history' => ['limit' => 40, 'bytes' => 32_768],
        'memory' => ['limit' => 10, 'bytes' => 16_384],
        'relationship' => ['limit' => 10, 'bytes' => 8_192],
        'knowledge' => ['limit' => 10, 'bytes' => 24_576],
        'narrative' => ['limit' => 10, 'bytes' => 16_384],
        'action_result' => ['limit' => 16, 'bytes' => 12_288],
        'turn' => ['limit' => 1, 'bytes' => 16_384],
    ];

    public function __construct(
        private readonly int $maxInputBytes = 131_072,
        private readonly int $maxSourceBytes = 16_384,
    ) {
        if ($maxInputBytes < 256 || $maxInputBytes > 131_072
            || $maxSourceBytes < 128 || $maxSourceBytes > 32_768) {
            throw new InvalidArgumentException('invalid_prompt_limits');
        }
    }

    /**
     * Expected selection keys are profile, prompt, memory, relationship, knowledge, and narrative.
     * Profile and prompt are one selected row (or a one-item list), each with id, revision, and content.
     * Other keys are ordered lists of selected rows. Scoped IDs on any row are checked against the turn.
     * Recent terminal action results are taken only from the validated turn payload.
     *
     * @param array<string,mixed> $turn
     * @param array<string,mixed> $selection
     * @return array{
     *   provider_input:array<string,mixed>,
     *   trace:array{
     *     algorithm:string,input_sha256:string,input_bytes:int,truncated:bool,redaction:string,
     *     profile_id:string,profile_revision:int,prompt_configuration_id:string,prompt_revision:int,
     *     sources:list<array<string,mixed>>
     *   }
     * }
     */
    public function assemble(array $turn, array $selection): array
    {
        $this->assertTurnScope($turn);

        $profile = $this->selectedRevision($selection, 'profile');
        $coreProfile = $this->optionalSelectedRevision($selection, 'core_profile');
        $prompt = $this->selectedRevision($selection, 'prompt');
        $this->assertSourceScope($profile, $turn, 'profile');
        if($coreProfile!==null)$this->assertSourceScope($coreProfile,$turn,'core_profile');
        $this->assertSourceScope($prompt, $turn, 'prompt');

        $rows = [
            'profile' => [$profile],
            'core_profile' => $coreProfile===null?[]:[$coreProfile],
            'prompt' => [$prompt],
            'history' => $this->selectedList($selection, 'history'),
            'memory' => $this->selectedList($selection, 'memory'),
            'relationship' => $this->selectedList($selection, 'relationship'),
            'knowledge' => $this->selectedList($selection, 'knowledge'),
            'narrative' => $this->selectedList($selection, 'narrative'),
            'action_result' => $this->terminalActionResults($selection),
            'turn' => [[
                'id' => $turn['turn_id'],
                'content' => $this->turnContent($turn),
            ]],
        ];

        $parts = [];
        $sources = [];
        $truncated = false;
        $ordinal = 0;

        foreach (self::SECTIONS as $kind => $policy) {
            $items = $rows[$kind];
            if (count($items) > $policy['limit']) {
                $items = $kind === 'history'
                    ? array_slice($items, -$policy['limit'])
                    : array_slice($items, 0, $policy['limit']);
                $truncated = true;
            }

            $sectionItems = [];
            $sectionSources = [];
            $sectionBytes = 0;
            $header = '[' . strtoupper($kind) . "]\n";
            $separatorBytes = $parts === [] ? 0 : 2;
            $totalAvailable = $this->maxInputBytes - strlen(implode("\n\n", $parts)) - $separatorBytes - strlen($header);

            // Reserve history space from newest to oldest, then render the selected rows chronologically.
            $budgetItems = $kind === 'history' ? array_reverse($items) : $items;
            foreach ($budgetItems as $item) {
                if (!is_array($item) || array_is_list($item)) {
                    throw new InvalidArgumentException('invalid_prompt_source');
                }
                $this->assertSourceScope($item, $turn, $kind);
                $id = $this->sourceId($kind, $item);
                $content = $this->canonical($this->sourceContent($kind, $item));
                $sourceBytes = strlen($content);
                $delimiterBytes = $sectionItems === [] ? 0 : 1;
                $available = min(
                    $this->maxSourceBytes,
                    $policy['bytes'] - $sectionBytes - $delimiterBytes,
                    $totalAvailable - $sectionBytes - $delimiterBytes,
                );

                $included = $available > 0;
                $includedContent = $included ? $this->truncateUtf8($content, $available) : '';
                $includedBytes = strlen($includedContent);
                $wasTruncated = !$included || $includedBytes < $sourceBytes;
                if ($included) {
                    $sectionItems[] = $includedContent;
                    $sectionBytes += $delimiterBytes + $includedBytes;
                }
                $truncated = $truncated || $wasTruncated;
                $sectionSources[] = [
                    'source_kind' => $kind,
                    'source_id' => $id,
                    'revision' => $this->sourceRevision($item),
                    'included' => $included,
                    'reason' => $wasTruncated ? 'byte_limit' : 'included',
                    'source_sha256' => hash('sha256', $content),
                    'source_bytes' => $sourceBytes,
                    'included_bytes' => $includedBytes,
                    // Kept for the trace persistence schema; raw source previews are intentionally absent.
                    'redacted_preview' => '',
                ];
            }

            if ($kind === 'history') {
                $sectionItems = array_reverse($sectionItems);
                $sectionSources = array_reverse($sectionSources);
            }
            foreach ($sectionSources as $source) {
                $source['ordinal'] = $ordinal++;
                $sources[] = $source;
            }

            if ($sectionItems !== []) {
                $parts[] = $header . implode("\n", $sectionItems);
            }
        }

        $input = implode("\n\n", $parts);
        if ($input === '' || strlen($input) > $this->maxInputBytes) {
            throw new InvalidArgumentException('invalid_assembled_prompt');
        }

        $providerInput = $this->providerInput($turn, $input);
        $trace = [
            'algorithm' => self::ALGORITHM,
            'input_sha256' => hash('sha256', $input),
            'input_bytes' => strlen($input),
            'truncated' => $truncated,
            'redaction' => 'metadata-only-v1',
            'profile_id' => $this->sourceId('profile', $profile),
            'profile_revision' => $this->requiredRevision($profile),
            'core_profile_id' => $coreProfile===null?null:$this->sourceId('core_profile',$coreProfile),
            'core_profile_revision' => $coreProfile===null?null:$this->requiredRevision($coreProfile),
            'effective_settings_sha256' => $selection['effective_settings']['sha256']??null,
            'settings_sources' => $selection['effective_settings']['sources']??[],
            'prompt_configuration_id' => $this->sourceId('prompt', $prompt),
            'prompt_revision' => $this->requiredRevision($prompt),
            'sources' => $sources,
        ];

        return ['provider_input' => $providerInput, 'trace' => $trace];
    }

    /** @param array<string,mixed> $selection @return array<string,mixed> */
    private function selectedRevision(array $selection, string $kind): array
    {
        $value = $selection[$kind] ?? null;
        if (is_array($value) && array_is_list($value)) {
            if (count($value) !== 1 || !is_array($value[0])) {
                throw new InvalidArgumentException('selected_' . $kind . '_revision_required');
            }
            $value = $value[0];
        }
        if (!is_array($value) || array_is_list($value) || !array_key_exists('content', $value)) {
            throw new InvalidArgumentException('selected_' . $kind . '_revision_required');
        }
        $this->sourceId($kind, $value);
        $this->requiredRevision($value);
        return $value;
    }

    /** @param array<string,mixed> $selection @return array<string,mixed>|null */
    private function optionalSelectedRevision(array $selection,string $kind):?array
    {
        if(!array_key_exists($kind,$selection)||$selection[$kind]===null)return null;
        return$this->selectedRevision($selection,$kind);
    }

    /** @param array<string,mixed> $selection @return list<array<string,mixed>> */
    private function selectedList(array $selection, string $kind): array
    {
        $value = $selection[$kind] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('invalid_' . $kind . '_selection');
        }
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('invalid_' . $kind . '_selection');
            }
        }
        return $value;
    }

    /** @param array<string,mixed> $selection @return list<array<string,mixed>> */
    private function terminalActionResults(array $selection): array
    {
        $results = $selection['recent_action_results'] ?? [];
        if (!is_array($results) || !array_is_list($results) || count($results) > self::SECTIONS['action_result']['limit']) {
            throw new InvalidArgumentException('invalid_recent_action_results');
        }
        $terminal = ['cancelled', 'failed', 'rejected', 'succeeded', 'timed_out'];
        foreach ($results as $result) {
            if (!is_array($result) || array_is_list($result)
                || !is_string($result['action_id'] ?? null)
                || !in_array($result['status'] ?? null, $terminal, true)) {
                throw new InvalidArgumentException('non_terminal_action_result');
            }
        }
        return $results;
    }

    /** @param array<string,mixed> $turn */
    private function assertTurnScope(array $turn): void
    {
        foreach (['installation_id', 'profile_id', 'playthrough_id', 'turn_id'] as $field) {
            if (!is_string($turn[$field] ?? null) || $turn[$field] === '') {
                throw new InvalidArgumentException('invalid_turn_scope');
            }
        }
        if (!is_array($turn['payload'] ?? null) || array_is_list($turn['payload'])) {
            throw new InvalidArgumentException('invalid_turn_payload');
        }
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $turn */
    private function assertSourceScope(array $source, array $turn, string $kind): void
    {
        foreach (['installation_id', 'profile_id', 'playthrough_id'] as $field) {
            if (!array_key_exists($field, $source) || $source[$field] === null) continue;
            $expected=$turn[$field];
            if($field==='profile_id'&&in_array($kind,['profile','prompt'],true)
                &&is_string($turn['_selected_profile_id']??null))$expected=$turn['_selected_profile_id'];
            if (!is_string($source[$field]) || !hash_equals((string) $expected, $source[$field])) {
                throw new InvalidArgumentException('prompt_source_scope_mismatch');
            }
        }
    }

    /** @param array<string,mixed> $source */
    private function sourceId(string $kind, array $source): string
    {
        $keys = match ($kind) {
            'profile' => ['profile_id', 'id'],
            'core_profile' => ['core_profile_id', 'id'],
            'prompt' => ['configuration_id', 'id'],
            'history' => ['history_id', 'id'],
            'memory' => ['memory_id', 'id'],
            'relationship' => ['relationship_id', 'id'],
            'knowledge' => ['document_id', 'id'],
            'narrative' => ['narrative_id', 'id'],
            'action_result' => ['action_id', 'id'],
            'turn' => ['turn_id', 'id'],
            default => throw new InvalidArgumentException('invalid_prompt_source_kind'),
        };
        foreach ($keys as $key) {
            if (is_string($source[$key] ?? null) && $source[$key] !== '' && strlen($source[$key]) <= 256) {
                return $source[$key];
            }
        }
        throw new InvalidArgumentException('prompt_source_id_required');
    }

    /** @param array<string,mixed> $source */
    private function sourceContent(string $kind, array $source): mixed
    {
        if (array_key_exists('content', $source)) {
            return $source['content'];
        }
        return match ($kind) {
            'relationship' => $this->allow($source, ['actor_identity', 'disposition', 'affinity']),
            'action_result' => $this->allow($source, ['action_id', 'status', 'reason_code', 'observed', 'completed_at']),
            default => throw new InvalidArgumentException('prompt_source_content_required'),
        };
    }

    /** @param array<string,mixed> $turn @return array<string,mixed> */
    private function turnContent(array $turn): array
    {
        $content=$this->allow($turn['payload'], ['input', 'speaker', 'target', 'audience', 'context', 'ui_source']);
        if (array_key_exists('context', $content)) {
            // Keep input and actor identities ahead of the largest snapshot field under byte truncation.
            $content['world_context']=$content['context'];
            unset($content['context']);
        }
        $player=$turn['_player_profile']??null;
        if(is_array($player)&&!array_is_list($player)){
            $identity=is_array($player['actor_identity']??null)&&!array_is_list($player['actor_identity'])
                ?$this->allow($player['actor_identity'],['kind','display_name']):[];
            $profileContent=is_array($player['content']??null)&&!array_is_list($player['content'])
                ?$this->allow($player['content'],['appearance','biography','personality','speech_style','goals','notes']):[];
            $summary=[];
            foreach(['profile_id','name','revision']as$field)if(isset($player[$field])&&(is_string($player[$field])||is_int($player[$field])))$summary[$field]=$player[$field];
            if($identity!==[])$summary['identity']=$identity;
            if($profileContent!==[])$summary['content']=$profileContent;
            if($summary!==[])$content['player_profile']=$summary;
        }
        $narrator=$turn['_narrator_profile']??null;
        if(is_array($narrator)&&!array_is_list($narrator)){
            $narratorContent=is_array($narrator['content']??null)&&!array_is_list($narrator['content'])
                ?$this->allow($narrator['content'],['enabled','inline_narration_mode','biography','personality','speech_style','goals','notes']):[];
            $identity=is_array($narrator['actor_identity']??null)&&!array_is_list($narrator['actor_identity'])
                ?$this->allow($narrator['actor_identity'],['kind','display_name']):[];
            $content['narrator_profile']=['identity'=>$identity,'content'=>$narratorContent];
        }
        $descriptions=$turn['_item_descriptions']??null;
        if(is_array($descriptions)&&array_is_list($descriptions)){$safe=[];foreach(array_slice($descriptions,0,64)as$item){if(!is_array($item)||array_is_list($item))continue;
            $row=$this->allow($item,['record_id','content_file','name','description']);if(isset($row['record_id'],$row['description']))$safe[]=$row;}
            if($safe!==[])$content['record_descriptions']=$safe;}
        return$content;
    }

    /** @param array<string,mixed> $turn @return array<string,mixed> */
    private function providerInput(array $turn, string $prompt): array
    {
        $provider = $this->allow($turn, [
            'schema', 'request_id', 'turn_id', 'installation_id', 'profile_id', 'playthrough_id',
            'session_id', 'generation', 'content_fingerprint',
        ]);
        $provider['payload'] = $this->allow($turn['payload'], ['input', 'speaker', 'target', 'audience', 'ui_source']);
        if (isset($turn['_negotiated_capabilities']) && is_array($turn['_negotiated_capabilities'])) {
            $provider['_negotiated_capabilities'] = array_values(array_filter(
                $turn['_negotiated_capabilities'],
                static fn(mixed $value): bool => is_string($value),
            ));
        }
        $provider['_assembled_prompt'] = $prompt;
        return $provider;
    }

    /** @param array<string,mixed> $source */
    private function requiredRevision(array $source): int
    {
        $revision = $source['revision'] ?? $source['current_revision'] ?? null;
        if (!is_int($revision) && !(is_string($revision) && preg_match('/^[1-9][0-9]*$/D', $revision) === 1)) {
            throw new InvalidArgumentException('prompt_source_revision_required');
        }
        $revision = (int) $revision;
        if ($revision < 1) {
            throw new InvalidArgumentException('prompt_source_revision_required');
        }
        return $revision;
    }

    /** @param array<string,mixed> $source */
    private function sourceRevision(array $source): ?int
    {
        $revision = $source['revision'] ?? $source['current_revision'] ?? null;
        return is_int($revision) && $revision > 0 ? $revision
            : (is_string($revision) && preg_match('/^[1-9][0-9]*$/D', $revision) === 1 ? (int) $revision : null);
    }

    /** @param array<string,mixed> $value @param list<string> $keys @return array<string,mixed> */
    private function allow(array $value, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                $result[$key] = $value[$key];
            }
        }
        return $result;
    }

    /** @throws JsonException */
    private function canonical(mixed $value): string
    {
        $sort = static function (mixed $child) use (&$sort): mixed {
            if (!is_array($child)) {
                return $child;
            }
            if (array_is_list($child)) {
                return array_map($sort, $child);
            }
            ksort($child, SORT_STRING);
            foreach ($child as &$nested) {
                $nested = $sort($nested);
            }
            return $child;
        };
        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('invalid_prompt_source_encoding');
            }
            return $value;
        }
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function truncateUtf8(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        if ($maxBytes <= 3) {
            return mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }
        return mb_strcut($value, 0, $maxBytes - 3, 'UTF-8') . "\u{2026}";
    }
}
