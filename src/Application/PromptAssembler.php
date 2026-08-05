<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use InvalidArgumentException;
use JsonException;

/** Builds CHIM-style XML system context and role-separated chat messages for one turn. */
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
     * @param array<string,mixed> $turn
     * @param array<string,mixed> $selection
     * @return array{provider_input:array<string,mixed>,trace:array<string,mixed>}
     */
    public function assemble(array $turn, array $selection): array
    {
        $this->assertTurnScope($turn);
        $profile = $this->selectedRevision($selection, 'profile');
        $coreProfile = $this->optionalSelectedRevision($selection, 'core_profile');
        $prompt = $this->selectedRevision($selection, 'prompt');
        $this->assertSourceScope($profile, $turn, 'profile');
        if ($coreProfile !== null) $this->assertSourceScope($coreProfile, $turn, 'core_profile');
        $this->assertSourceScope($prompt, $turn, 'prompt');

        $history = $this->limitedSelection($selection, 'history');
        $memory = $this->limitedSelection($selection, 'memory');
        $relationships = $this->limitedSelection($selection, 'relationship');
        $knowledge = $this->limitedSelection($selection, 'knowledge');
        $narrative = $this->limitedSelection($selection, 'narrative');
        $actions = $this->terminalActionResults($selection);
        foreach ([
            'history' => $history,
            'memory' => $memory,
            'relationship' => $relationships,
            'knowledge' => $knowledge,
            'narrative' => $narrative,
            'action_result' => $actions,
        ] as $kind => $rows) {
            foreach ($rows as $row) $this->assertSourceScope($row, $turn, $kind);
        }

        $actorName = $this->actorName($turn, $profile);
        $playerName = $this->playerName($turn);
        $final = $this->currentTurnMessage($turn, $actorName, $playerName);
        $historyMessages = $this->historyMessages($history, $turn, $actorName, $playerName);

        $historyReserve = min(self::SECTIONS['history']['bytes'], intdiv($this->maxInputBytes, 3));
        $systemBudget = max(192, $this->maxInputBytes - strlen($final) - $historyReserve - 256);
        $system = $this->systemPrompt(
            $turn,
            $profile,
            $coreProfile,
            $prompt,
            $memory,
            $relationships,
            $knowledge,
            $narrative,
            $actions,
            $actorName,
            $playerName,
            $systemBudget,
        );

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($historyMessages as $message) {
            $messages[] = ['role' => $message['role'], 'content' => $message['content'], '_source_id' => $message['_source_id']];
        }
        $messages[] = ['role' => 'user', 'content' => $final];
        $messages = $this->fitMessages($messages, $turn, $actorName, $playerName);

        $includedHistory = [];
        foreach ($messages as $message) {
            if (is_string($message['_source_id'] ?? null)) $includedHistory[$message['_source_id']] = true;
        }
        foreach ($messages as &$message) unset($message['_source_id']);
        unset($message);

        $assembled = $this->readableMessages($messages);
        if ($assembled === '' || strlen($assembled) > $this->maxInputBytes) {
            throw new InvalidArgumentException('invalid_assembled_prompt');
        }

        $rows = [
            'profile' => [$profile],
            'core_profile' => $coreProfile === null ? [] : [$coreProfile],
            'prompt' => [$prompt],
            'history' => $history,
            'memory' => $memory,
            'relationship' => $relationships,
            'knowledge' => $knowledge,
            'narrative' => $narrative,
            'action_result' => $actions,
            'turn' => [['id' => $turn['turn_id'], 'content' => $this->turnTraceContent($turn)]],
        ];
        $sources = $this->traceSources($rows, $turn, $includedHistory);
        $truncated = false;
        foreach (['history', 'memory', 'relationship', 'knowledge', 'narrative'] as $kind) {
            $truncated = $truncated || count($rows[$kind]) < count($this->selectedList($selection, $kind));
        }
        foreach ($sources as $source) $truncated = $truncated || $source['reason'] !== 'included';

        $providerInput = $this->providerInput($turn, $assembled, $messages);
        $trace = [
            'algorithm' => self::ALGORITHM,
            'input_sha256' => hash('sha256', $assembled),
            'input_bytes' => strlen($assembled),
            'truncated' => $truncated,
            'redaction' => 'metadata-only-v1',
            'profile_id' => $this->sourceId('profile', $profile),
            'profile_revision' => $this->requiredRevision($profile),
            'core_profile_id' => $coreProfile === null ? null : $this->sourceId('core_profile', $coreProfile),
            'core_profile_revision' => $coreProfile === null ? null : $this->requiredRevision($coreProfile),
            'effective_settings_sha256' => $selection['effective_settings']['sha256'] ?? null,
            'settings_sources' => $selection['effective_settings']['sources'] ?? [],
            'prompt_configuration_id' => $this->sourceId('prompt', $prompt),
            'prompt_revision' => $this->requiredRevision($prompt),
            'sources' => $sources,
        ];
        return ['provider_input' => $providerInput, 'trace' => $trace];
    }

    /** @param list<array<string,mixed>> $messages @return list<array<string,mixed>> */
    private function fitMessages(array $messages, array $turn, string $actorName, string $playerName): array
    {
        while (count($messages) > 2 && strlen($this->readableMessages($messages)) > $this->maxInputBytes) {
            array_splice($messages, 1, 1);
        }
        if (strlen($this->readableMessages($messages)) <= $this->maxInputBytes) return $messages;

        $final = (string) $messages[array_key_last($messages)]['content'];
        $overhead = strlen("SYSTEM:\n\n\nUSER:\n") + strlen($final);
        $budget = $this->maxInputBytes - $overhead;
        if ($budget < 96) throw new InvalidArgumentException('invalid_assembled_prompt');
        $minimal = $this->minimalSystemPrompt($actorName, $playerName);
        if (strlen($minimal) > $budget) throw new InvalidArgumentException('invalid_assembled_prompt');
        return [
            ['role' => 'system', 'content' => $minimal],
            ['role' => 'user', 'content' => $final],
        ];
    }

    /**
     * Construct the same broad XML families CHIM uses while keeping ALMSIVI's typed response contract.
     * @param list<array<string,mixed>> $memory
     * @param list<array<string,mixed>> $relationships
     * @param list<array<string,mixed>> $knowledge
     * @param list<array<string,mixed>> $narrative
     * @param list<array<string,mixed>> $actions
     */
    private function systemPrompt(
        array $turn,
        array $profile,
        ?array $coreProfile,
        array $prompt,
        array $memory,
        array $relationships,
        array $knowledge,
        array $narrative,
        array $actions,
        string $actorName,
        string $playerName,
        int $budget,
    ): string {
        $roleplay = 'You are ' . $actorName . ', a character in the universe of Morrowind. '
            . 'This world is your reality. Remain ' . $actorName . ' and never speak, decide, or narrate dialogue for ' . $playerName . '.';
        $character = $this->characterXml($turn, $profile, $actorName);
        $general = "Write {$actorName}'s next dialogue line. Address {$playerName} or the most recent speaker, review the conversation, and avoid repeating prior dialogue.";
        $base = '<roleplay_context>'
            . $this->xmlTag('roleplay_instructions', $roleplay)
            . $character
            . $this->xmlTag('general_instructions', $general)
            . '</roleplay_context>';
        if (strlen($base) > $budget) return $this->minimalSystemPrompt($actorName, $playerName);

        $blocks = [];
        $core = $coreProfile === null ? '' : $this->fieldText($coreProfile['content'] ?? [], ['prompt']);
        if ($core !== '') $blocks[] = $this->xmlTag('core_profile_instructions', $core);
        $instruction = $this->fieldText($prompt['content'] ?? [], ['instruction', 'prompt', 'default_prompt', 'custom_prompt']);
        if ($instruction !== '') $blocks[] = $this->xmlTag('roleplay_prompt', $instruction);

        $world = $this->worldXml($turn['payload']['context'] ?? []);
        if ($world !== '') $blocks[] = '<world>' . $world . '</world>';
        $player = $this->playerXml($turn, $playerName);
        if ($player !== '') $blocks[] = '<player_character>' . $player . '</player_character>';
        $narrator = $this->narratorXml($turn);
        if ($narrator !== '') $blocks[] = '<narrator>' . $narrator . '</narrator>';
        $descriptions = $this->recordDescriptionsXml($turn['_item_descriptions'] ?? []);
        if ($descriptions !== '') $blocks[] = '<record_descriptions>' . $descriptions . '</record_descriptions>';
        foreach ([
            'memories' => ['memory', $memory],
            'relationships' => ['relationship', $relationships],
            'knowledge' => ['knowledge', $knowledge],
            'narrative_context' => ['narrative', $narrative],
            'recent_action_results' => ['action_result', $actions],
        ] as $tag => [$kind, $rows]) {
            $xml = $this->sourceItemsXml($rows, $kind);
            if ($xml !== '') $blocks[] = '<' . $tag . '>' . $xml . '</' . $tag . '>';
        }
        $blocks[] = $this->xmlTag('response_contract',
            'Return one JSON object with exactly two keys: "utterances" and "action". "utterances" must be a JSON array of one to four objects. Each utterance object must have exactly one key named "text", and "text" must be a non-empty string. Never return utterances as strings. "action" is null or a supported name and parameters object. Do not add prose outside JSON.');

        $closing = '</roleplay_context>';
        $system = substr($base, 0, -strlen($closing));
        foreach ($blocks as $block) {
            if (strlen($system) + strlen($block) + strlen($closing) > $budget) continue;
            $system .= $block;
        }
        return $system . $closing;
    }

    private function minimalSystemPrompt(string $actorName, string $playerName): string
    {
        return '<roleplay_context>'
            . $this->xmlTag('roleplay_instructions', "You are {$actorName} in Morrowind. Never speak as {$playerName}.")
            . '<character>' . $this->xmlTag('name', $actorName) . '</character>'
            . $this->xmlTag('general_instructions', "Write {$actorName}'s next dialogue line.")
            . '</roleplay_context>';
    }

    private function characterXml(array $turn, array $profile, string $actorName): string
    {
        $content = is_array($profile['content'] ?? null) && !array_is_list($profile['content']) ? $profile['content'] : [];
        $identity = is_array($profile['actor_identity'] ?? null) && !array_is_list($profile['actor_identity'])
            ? $profile['actor_identity'] : [];
        $target = is_array($turn['payload']['target'] ?? null) && !array_is_list($turn['payload']['target'])
            ? $turn['payload']['target'] : [];
        $xml = '<identity>' . $this->xmlTag('name', $actorName);
        foreach (['record_id', 'content_file'] as $field) {
            $value = $target[$field] ?? $identity[$field] ?? null;
            if (is_scalar($value) && (string) $value !== '') $xml .= $this->xmlTag($field, (string) $value);
        }
        $xml .= '</identity>';
        $fields = [
            'basic_summary' => ['biography', 'background', 'basic_summary', 'persona'],
            'personality' => ['personality'],
            'appearance' => ['appearance'],
            'occupation' => ['occupation', 'class'],
            'skills' => ['skills'],
            'speech_style' => ['speech_style'],
            'goals' => ['goals'],
            'notes' => ['notes'],
            'race' => ['race'],
            'gender' => ['gender'],
        ];
        foreach ($fields as $tag => $keys) {
            $value = $this->fieldText($content, $keys);
            if ($value !== '') $xml .= $this->xmlTag($tag, $value);
        }
        $state = is_array(($turn['payload']['context']['targetState'] ?? null))
            ? $turn['payload']['context']['targetState'] : [];
        $stateXml = $this->knownFieldsXml($state, ['activity', 'disposition', 'health', 'health_percent', 'equipment', 'inventory']);
        if ($stateXml !== '') $xml .= '<current_state>' . $stateXml . '</current_state>';
        return '<character>' . $xml . '</character>';
    }

    private function playerXml(array $turn, string $playerName): string
    {
        $xml = $this->xmlTag('name', $playerName);
        $player = $turn['_player_profile'] ?? null;
        if (is_array($player) && !array_is_list($player)) {
            $content = is_array($player['content'] ?? null) && !array_is_list($player['content']) ? $player['content'] : [];
            foreach ([
                'basic_summary' => ['biography', 'background', 'basic_summary', 'persona'],
                'personality' => ['personality'],
                'appearance' => ['appearance'],
                'speech_style' => ['speech_style'],
                'goals' => ['goals'],
                'notes' => ['notes'],
            ] as $tag => $keys) {
                $value = $this->fieldText($content, $keys);
                if ($value !== '') $xml .= $this->xmlTag($tag, $value);
            }
        }
        $state = is_array(($turn['payload']['context']['playerState'] ?? null))
            ? $turn['payload']['context']['playerState'] : [];
        $stateXml = $this->knownFieldsXml($state, ['race', 'class', 'level', 'health', 'health_percent', 'equipment']);
        if ($stateXml !== '') $xml .= '<current_state>' . $stateXml . '</current_state>';
        return $xml;
    }

    private function narratorXml(array $turn): string
    {
        $narrator = $turn['_narrator_profile'] ?? null;
        if (!is_array($narrator) || array_is_list($narrator)) return '';
        $content = is_array($narrator['content'] ?? null) && !array_is_list($narrator['content'])
            ? $narrator['content'] : [];
        $isNarratorTarget = ($turn['payload']['target']['kind'] ?? null) === 'narrator';
        if (($content['enabled'] ?? false) !== true && !$isNarratorTarget) return '';
        $identity = is_array($narrator['actor_identity'] ?? null) && !array_is_list($narrator['actor_identity'])
            ? $narrator['actor_identity'] : [];
        $xml = $this->xmlTag('name', $this->identityName($identity, 'The Narrator'));
        foreach ([
            'basic_summary' => ['biography', 'background', 'basic_summary', 'persona'],
            'personality' => ['personality'],
            'speech_style' => ['speech_style'],
            'goals' => ['goals'],
            'notes' => ['notes'],
        ] as $tag => $keys) {
            $value = $this->fieldText($content, $keys);
            if ($value !== '') $xml .= $this->xmlTag($tag, $value);
        }
        return $xml;
    }

    private function worldXml(mixed $context): string
    {
        if (!is_array($context) || array_is_list($context)) return '';
        return $this->knownFieldsXml($context, [
            'location', 'cell', 'region', 'weather', 'date', 'time', 'game_time', 'gameTime', 'day', 'month', 'year',
        ]);
    }

    /** @param list<array<string,mixed>> $rows @return list<array{role:string,content:string,_source_id:string}> */
    private function historyMessages(array $rows, array $turn, string $actorName, string $playerName): array
    {
        $messages = [];
        foreach ($rows as $row) {
            $id = $this->sourceId('history', $row);
            $content = $row['content'] ?? null;
            $message = $this->historyMessage($content, $turn, $actorName, $playerName);
            if ($message === null) continue;
            $message['content'] = $this->truncateUtf8($message['content'], $this->maxSourceBytes);
            $previous = $messages[array_key_last($messages)] ?? null;
            if ($previous !== null && $previous['role'] === $message['role'] && $previous['content'] === $message['content']) continue;
            $messages[] = $message + ['_source_id' => $id];
        }
        return array_slice($messages, -32);
    }

    /** @return array{role:string,content:string}|null */
    private function historyMessage(mixed $content, array $turn, string $actorName, string $playerName): ?array
    {
        if (is_string($content)) {
            $text = trim($content);
            return $this->ignoredHistoryText($text) ? null : ['role' => 'user', 'content' => 'Context: ' . $text];
        }
        if (!is_array($content) || array_is_list($content)) return null;
        $kind = (string) ($content['kind'] ?? '');
        if ($kind === 'event') {
            $type = (string) ($content['type'] ?? '');
            if ($type === 'rechat' || (($content['turn_id'] ?? null) === ($turn['turn_id'] ?? null))) return null;
            if (in_array($type, ['turn.requested'], true)) {
                $text = trim((string) ($content['input']['text'] ?? ''));
                if ($this->ignoredHistoryText($text)) return null;
                $speaker = $this->identityName($content['speaker'] ?? null, $playerName);
                return ['role' => 'user', 'content' => $speaker . ': ' . $text];
            }
            $details = $content['details'] ?? null;
            if ($details === null) return null;
            return ['role' => 'user', 'content' => '[World event] ' . $this->canonical($details)];
        }
        if ($kind === 'speech') {
            $text = trim((string) ($content['text'] ?? ''));
            if ($text === '' || $this->ignoredHistoryText($text)) return null;
            $speaker = $this->identityName($content['speaker_identity'] ?? null, (string) ($content['speaker'] ?? 'Unknown'));
            $target = $turn['payload']['target'] ?? [];
            $isActor = $this->sameActor($content['speaker_identity'] ?? null, $target)
                || mb_strtolower($speaker, 'UTF-8') === mb_strtolower($actorName, 'UTF-8');
            return $isActor
                ? ['role' => 'assistant', 'content' => $text]
                : ['role' => 'user', 'content' => $speaker . ': ' . $text];
        }
        return null;
    }

    private function currentTurnMessage(array $turn, string $actorName, string $playerName): string
    {
        $text = trim((string) ($turn['payload']['input']['text'] ?? ''));
        if ($text === '') throw new InvalidArgumentException('invalid_turn_input');
        $speaker = $playerName;
        $rechat = $turn['payload']['context']['rechat'] ?? null;
        if (is_array($rechat) && !array_is_list($rechat)) {
            $speaker = $this->identityName($rechat['previous_speaker'] ?? null, $playerName);
        }
        return $speaker . ': ' . $text . "\n\nRespond as {$actorName}. Write {$actorName}'s next dialogue line; do not write dialogue for {$speaker}.";
    }

    private function ignoredHistoryText(string $text): bool
    {
        if ($text === '') return true;
        $normalized = mb_strtolower(ltrim($text), 'UTF-8');
        return str_starts_with($normalized, 'automated almsivi smoke test')
            || str_starts_with($normalized, '[autonomy:')
            || str_starts_with($normalized, '[fallback]');
    }

    private function actorName(array $turn, array $profile): string
    {
        foreach ([
            $turn['payload']['target']['display_name'] ?? null,
            $profile['actor_identity']['display_name'] ?? null,
            $profile['name'] ?? null,
            $turn['payload']['target']['record_id'] ?? null,
        ] as $value) {
            if (is_string($value) && trim($value) !== '') return $this->truncateUtf8(trim($value), 256);
        }
        return 'the selected Morrowind actor';
    }

    private function playerName(array $turn): string
    {
        foreach ([
            $turn['payload']['speaker']['display_name'] ?? null,
            $turn['_player_profile']['actor_identity']['display_name'] ?? null,
            $turn['_player_profile']['name'] ?? null,
            $turn['payload']['speaker']['record_id'] ?? null,
        ] as $value) {
            if (is_string($value) && trim($value) !== '') return $this->truncateUtf8(trim($value), 256);
        }
        return 'the player';
    }

    private function identityName(mixed $identity, string $fallback): string
    {
        if (is_array($identity) && !array_is_list($identity)) {
            foreach (['display_name', 'name', 'record_id'] as $field) {
                if (is_string($identity[$field] ?? null) && trim($identity[$field]) !== '') return trim($identity[$field]);
            }
        }
        return $fallback !== '' ? $fallback : 'Unknown';
    }

    private function sameActor(mixed $left, mixed $right): bool
    {
        if (!is_array($left) || array_is_list($left) || !is_array($right) || array_is_list($right)) return false;
        foreach (['record_id', 'content_file'] as $field) {
            if (is_string($left[$field] ?? null) && is_string($right[$field] ?? null)
                && mb_strtolower($left[$field], 'UTF-8') !== mb_strtolower($right[$field], 'UTF-8')) return false;
        }
        return isset($left['record_id'], $right['record_id']);
    }

    private function fieldText(mixed $content, array $keys): string
    {
        if (!is_array($content) || array_is_list($content)) return '';
        foreach ($keys as $key) {
            if (!array_key_exists($key, $content) || $content[$key] === null || $content[$key] === '') continue;
            $value = is_string($content[$key]) ? $content[$key] : $this->canonical($content[$key]);
            return $this->truncateUtf8(trim($value), $this->maxSourceBytes);
        }
        return '';
    }

    private function knownFieldsXml(mixed $content, array $keys): string
    {
        if (!is_array($content) || array_is_list($content)) return '';
        $xml = '';
        foreach ($keys as $key) {
            if (!array_key_exists($key, $content) || $content[$key] === null || $content[$key] === '') continue;
            $value = is_string($content[$key]) || is_numeric($content[$key]) || is_bool($content[$key])
                ? (string) $content[$key] : $this->canonical($content[$key]);
            $xml .= $this->xmlTag($this->xmlName($key), $this->truncateUtf8($value, $this->maxSourceBytes));
        }
        return $xml;
    }

    private function itemsXml(mixed $rows, string $itemTag): string
    {
        if (!is_array($rows) || !array_is_list($rows)) return '';
        $xml = '';
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) continue;
            $content = array_key_exists('content', $row) ? $row['content'] : $row;
            $encoded = $this->canonical($content);
            $xml .= $this->xmlTag($itemTag, $this->truncateUtf8($encoded, $this->maxSourceBytes));
        }
        return $xml;
    }

    /** Render only the source content selected for the named prompt family, never repository scope metadata. */
    private function sourceItemsXml(array $rows, string $kind): string
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) continue;
            $items[] = ['content' => $this->sourceContent($kind, $row)];
        }
        return $this->itemsXml($items, 'item');
    }

    private function recordDescriptionsXml(mixed $rows): string
    {
        if (!is_array($rows) || !array_is_list($rows)) return '';
        $safe = [];
        foreach (array_slice($rows, 0, 64) as $row) {
            if (!is_array($row) || array_is_list($row)) continue;
            $record = $this->allow($row, ['record_id', 'content_file', 'name', 'description']);
            if (isset($record['record_id'], $record['description'])) $safe[] = $record;
        }
        return $this->itemsXml($safe, 'record');
    }

    private function xmlTag(string $tag, string $value): string
    {
        return '<' . $tag . '>' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</' . $tag . '>';
    }

    private function xmlName(string $value): string
    {
        $name = preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value;
        return strtolower($name);
    }

    /** @param list<array<string,mixed>> $messages */
    private function readableMessages(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) $parts[] = strtoupper((string) $message['role']) . ":\n" . (string) $message['content'];
        return implode("\n\n", $parts);
    }

    /** @param array<string,list<array<string,mixed>>> $rows @return list<array<string,mixed>> */
    private function traceSources(array $rows, array $turn, array $includedHistory): array
    {
        $sources = [];
        $ordinal = 0;
        foreach ($rows as $kind => $items) {
            foreach ($items as $item) {
                $this->assertSourceScope($item, $turn, $kind);
                $content = $this->canonical($this->sourceContent($kind, $item));
                $id = $this->sourceId($kind, $item);
                $included = $kind !== 'history' || isset($includedHistory[$id]);
                $includedBytes = $included ? min(strlen($content), $this->maxSourceBytes, self::SECTIONS[$kind]['bytes']) : 0;
                $sources[] = [
                    'source_kind' => $kind,
                    'source_id' => $id,
                    'revision' => $this->sourceRevision($item),
                    'included' => $included,
                    'reason' => !$included ? 'section_limit' : ($includedBytes < strlen($content) ? 'byte_limit' : 'included'),
                    'source_sha256' => hash('sha256', $content),
                    'source_bytes' => strlen($content),
                    'included_bytes' => $includedBytes,
                    'redacted_preview' => '',
                    'ordinal' => $ordinal++,
                ];
            }
        }
        return $sources;
    }

    /** @param array<string,mixed> $turn @param list<array<string,string>> $messages */
    private function providerInput(array $turn, string $assembled, array $messages): array
    {
        $provider = $this->allow($turn, [
            'schema', 'request_id', 'turn_id', 'installation_id', 'profile_id', 'playthrough_id',
            'session_id', 'generation', 'content_fingerprint',
        ]);
        $provider['payload'] = $this->allow($turn['payload'], ['input', 'speaker', 'target', 'audience', 'ui_source']);
        if (isset($turn['_negotiated_capabilities']) && is_array($turn['_negotiated_capabilities'])) {
            $provider['_negotiated_capabilities'] = array_values(array_filter(
                $turn['_negotiated_capabilities'], static fn(mixed $value): bool => is_string($value),
            ));
        }
        $provider['_assembled_prompt'] = $assembled;
        $provider['_messages'] = $messages;
        return $provider;
    }

    /** @param array<string,mixed> $selection @return array<string,mixed> */
    private function selectedRevision(array $selection, string $kind): array
    {
        $value = $selection[$kind] ?? null;
        if (is_array($value) && array_is_list($value)) {
            if (count($value) !== 1 || !is_array($value[0])) throw new InvalidArgumentException('selected_' . $kind . '_revision_required');
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
    private function optionalSelectedRevision(array $selection, string $kind): ?array
    {
        if (!array_key_exists($kind, $selection) || $selection[$kind] === null) return null;
        return $this->selectedRevision($selection, $kind);
    }

    /** @param array<string,mixed> $selection @return list<array<string,mixed>> */
    private function selectedList(array $selection, string $kind): array
    {
        $value = $selection[$kind] ?? [];
        if (!is_array($value) || !array_is_list($value)) throw new InvalidArgumentException('invalid_' . $kind . '_selection');
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) throw new InvalidArgumentException('invalid_' . $kind . '_selection');
        }
        return $value;
    }

    /** @param array<string,mixed> $selection @return list<array<string,mixed>> */
    private function limitedSelection(array $selection, string $kind): array
    {
        $rows = $this->selectedList($selection, $kind);
        $limit = self::SECTIONS[$kind]['limit'];
        return count($rows) <= $limit ? $rows : ($kind === 'history' ? array_slice($rows, -$limit) : array_slice($rows, 0, $limit));
    }

    /** @param array<string,mixed> $selection @return list<array<string,mixed>> */
    private function terminalActionResults(array $selection): array
    {
        $results = $selection['recent_action_results'] ?? [];
        if (!is_array($results) || !array_is_list($results) || count($results) > self::SECTIONS['action_result']['limit']) {
            throw new InvalidArgumentException('invalid_recent_action_results');
        }
        foreach ($results as $result) {
            if (!is_array($result) || array_is_list($result) || !is_string($result['action_id'] ?? null)
                || !in_array($result['status'] ?? null, ['cancelled', 'failed', 'rejected', 'succeeded', 'timed_out'], true)) {
                throw new InvalidArgumentException('non_terminal_action_result');
            }
        }
        return $results;
    }

    /** @param array<string,mixed> $turn */
    private function assertTurnScope(array $turn): void
    {
        foreach (['installation_id', 'profile_id', 'playthrough_id', 'turn_id'] as $field) {
            if (!is_string($turn[$field] ?? null) || $turn[$field] === '') throw new InvalidArgumentException('invalid_turn_scope');
        }
        if (!is_array($turn['payload'] ?? null) || array_is_list($turn['payload'])) throw new InvalidArgumentException('invalid_turn_payload');
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $turn */
    private function assertSourceScope(array $source, array $turn, string $kind): void
    {
        foreach (['installation_id', 'profile_id', 'playthrough_id'] as $field) {
            if (!array_key_exists($field, $source) || $source[$field] === null) continue;
            $expected = $turn[$field];
            if ($field === 'profile_id' && in_array($kind, ['profile', 'prompt'], true)
                && is_string($turn['_selected_profile_id'] ?? null)) $expected = $turn['_selected_profile_id'];
            if (!is_string($source[$field]) || !hash_equals((string) $expected, $source[$field])) {
                throw new InvalidArgumentException('prompt_source_scope_mismatch');
            }
        }
    }

    /** @param array<string,mixed> $source */
    private function sourceId(string $kind, array $source): string
    {
        $keys = match ($kind) {
            'profile' => ['profile_id', 'id'], 'core_profile' => ['core_profile_id', 'id'],
            'prompt' => ['configuration_id', 'id'], 'history' => ['history_id', 'id'],
            'memory' => ['memory_id', 'id'], 'relationship' => ['relationship_id', 'id'],
            'knowledge' => ['document_id', 'id'], 'narrative' => ['narrative_id', 'id'],
            'action_result' => ['action_id', 'id'], 'turn' => ['turn_id', 'id'],
            default => throw new InvalidArgumentException('invalid_prompt_source_kind'),
        };
        foreach ($keys as $key) {
            if (is_string($source[$key] ?? null) && $source[$key] !== '' && strlen($source[$key]) <= 256) return $source[$key];
        }
        throw new InvalidArgumentException('prompt_source_id_required');
    }

    /** @param array<string,mixed> $source */
    private function sourceContent(string $kind, array $source): mixed
    {
        if ($kind === 'profile') {
            return $this->allow($source, ['name', 'actor_identity', 'content']);
        }
        if (array_key_exists('content', $source)) return $source['content'];
        return match ($kind) {
            'relationship' => $this->allow($source, ['actor_identity', 'disposition', 'affinity']),
            'action_result' => $this->allow($source, ['action_id', 'status', 'reason_code', 'observed', 'completed_at']),
            default => throw new InvalidArgumentException('prompt_source_content_required'),
        };
    }

    /** @return array<string,mixed> */
    private function turnTraceContent(array $turn): array
    {
        return $this->allow($turn['payload'], ['input', 'speaker', 'target', 'audience', 'context', 'ui_source']);
    }

    /** @param array<string,mixed> $source */
    private function requiredRevision(array $source): int
    {
        $revision = $source['revision'] ?? $source['current_revision'] ?? null;
        if (!is_int($revision) && !(is_string($revision) && preg_match('/^[1-9][0-9]*$/D', $revision) === 1)) {
            throw new InvalidArgumentException('prompt_source_revision_required');
        }
        if ((int) $revision < 1) throw new InvalidArgumentException('prompt_source_revision_required');
        return (int) $revision;
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
        foreach ($keys as $key) if (array_key_exists($key, $value)) $result[$key] = $value[$key];
        return $result;
    }

    /** @throws JsonException */
    private function canonical(mixed $value): string
    {
        $sort = static function (mixed $child) use (&$sort): mixed {
            if (!is_array($child)) return $child;
            if (array_is_list($child)) return array_map($sort, $child);
            ksort($child, SORT_STRING);
            foreach ($child as &$nested) $nested = $sort($nested);
            return $child;
        };
        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('invalid_prompt_source_encoding');
            return $value;
        }
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function truncateUtf8(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) return $value;
        if ($maxBytes <= 3) return mb_strcut($value, 0, $maxBytes, 'UTF-8');
        return mb_strcut($value, 0, $maxBytes - 3, 'UTF-8') . "\u{2026}";
    }
}
