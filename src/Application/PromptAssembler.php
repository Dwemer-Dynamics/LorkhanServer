<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use InvalidArgumentException;
use JsonException;

/** Builds CHIM-style XML with compact system-owned chat history for one turn. */
final class PromptAssembler
{
    private const ALGORITHM = 'chim-compact-roleplay-prompt-v2';
    private const OGHMA_CONTRACT = 'oghma-parity-v1';

    /** @var array<string,int> */
    private const SECTION_ORDER = [
        'output_contract' => 1,
        'npc_context' => 2,
        'player_narrator_context' => 3,
        'morrowind_context' => 4,
        'oghma_context' => 5,
        'relationships_factions' => 6,
        'memory_context' => 7,
        'conversation_context' => 8,
        'audience_speaker_rules' => 9,
        'negotiated_actions' => 10,
        'current_turn' => 11,
    ];

    /** @var array<string,array{limit:int,bytes:int}> */
    private const SECTIONS = [
        'profile' => ['limit' => 1, 'bytes' => 12_288],
        'core_profile' => ['limit' => 1, 'bytes' => 65_536],
        'prompt' => ['limit' => 1, 'bytes' => 24_576],
        'history' => ['limit' => 500, 'bytes' => 32_768],
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

    /** Expose the frozen Oghma fragment as a narrow cross-server conformance test seam. */
    public function oghmaKnowledgeFragment(array $rows, string $status = 'grounded'): string
    {
        $items = $this->knowledgeItemsXml($rows);
        if ($items === '') return '';
        return '<oghma contract="' . self::OGHMA_CONTRACT . '" status="' . $this->oghmaXmlValue($status) . '">' . "\n"
            . $items . "\n</oghma>";
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
        $memory = array_slice($this->selectedList($selection, array_key_exists('memory_candidates', $selection) ? 'memory_candidates' : 'memory'), 0, 500);
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
        $knowledgeStatus = (string)($selection['knowledge_retrieval']['status'] ?? 'grounded');
        $systemBudget = max(192, $this->maxInputBytes - strlen($final) - 256);
        $built = $this->systemPrompt(
            $turn,
            $profile,
            $coreProfile,
            $prompt,
            $historyMessages,
            $memory,
            $relationships,
            $knowledge,
            $knowledgeStatus,
            $narrative,
            $actions,
            $actorName,
            $playerName,
            $systemBudget,
        );

        $system = $built['system'];
        $memoryState = $built['memory'];
        $messages = [['role' => 'system', 'content' => $system]];
        $messages[] = ['role' => 'user', 'content' => $final];
        $messages = $this->fitMessages($messages, $turn, $actorName, $playerName);
        $system = $messages[0]['content'];

        // Audit the first ten ranked candidates plus actual survivors, without writing 500 trace rows per turn.
        $memoryTraceRows = [];
        $memoryScores = [];
        $memoryModels = [];
        foreach ($memory as $index => $row) {
            $id = $this->sourceId('memory', $row);
            if ($index < 10 || isset($memoryState['texts'][$id])) $memoryTraceRows[] = $row;
            if (isset($memoryState['texts'][$id])) $memoryScores[$id] = (float) ($row['_prompt_score'] ?? 0);
            if (isset($row['_model_summary'])) $memoryModels[$id] = $row['_model_summary'];
        }
        $memory = $memoryTraceRows;

        $includedHistory = [];
        if (preg_match('#<conversation_context>(.+)</conversation_context>#s', $system) === 1) {
            foreach ($historyMessages as $message) $includedHistory[$message['_source_id']] = true;
        }

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
        $sources = $this->traceSources($rows, $turn, $includedHistory, $system, $memoryState);
        $sections = $this->traceSections($system, $rows, $turn);
        $memoryIncluded = str_contains($system, '<memory_context><item>');
        $memoryRetrieval = $selection['memory_retrieval'] ?? null;
        if (is_array($memoryRetrieval)) {
            $memoryRetrieval['result_ids'] = $memoryIncluded ? array_keys($memoryState['texts']) : [];
            $memoryRetrieval['scores'] = $memoryIncluded ? $memoryScores : [];
            $memoryRetrieval['reasons'] = [];
            foreach ($memoryRetrieval['result_ids'] as $rank => $id) $memoryRetrieval['reasons'][$id] = [
                'rank' => $rank + 1, 'reason' => $memoryState['reasons'][$id]]
                + (isset($memoryModels[$id]) ? ['model_summary' => $memoryModels[$id]] : []);
            $memoryRetrieval['selection'] = 'exact-rendered-coverage-v1';
            $memoryRetrieval['coverage'] = $memoryState['counts'];
            $memoryRetrieval['coverage']['selected'] = count($memoryRetrieval['result_ids']);
            if (!$memoryIncluded) $memoryRetrieval['coverage']['covered_by_memory'] = 0;
            if ($includedHistory === []) $memoryRetrieval['coverage']['covered_by_history'] = 0;
            // Retrieval traces persist the reasons object, not arbitrary top-level metadata.
            $memoryRetrieval['reasons']['_context'] = ['selection' => $memoryRetrieval['selection'],
                'coverage' => $memoryRetrieval['coverage']];
        }
        $memorySources = array_values(array_filter($sources, static fn(array $source): bool => $source['source_kind'] === 'memory'));
        if ($memorySources !== [] && count(array_filter($memorySources, static fn(array $source): bool => $source['reason'] === 'covered_by_history')) === count($memorySources)) {
            foreach ($sections as &$section) if ($section['section_key'] === 'memory_context') $section['inclusion_reason'] = 'covered_by_history';
            unset($section);
        }
        $truncated = false;
        foreach (['history', 'memory', 'relationship', 'knowledge', 'narrative'] as $kind) {
            $truncated = $truncated || count($rows[$kind]) < count($this->selectedList($selection, $kind));
        }
        foreach ($sources as $source) $truncated = $truncated || !in_array($source['reason'], ['included', 'covered_by_history', 'covered_by_memory'], true);

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
            'memory_retrieval' => $memoryRetrieval,
            'knowledge_retrieval' => $selection['knowledge_retrieval'] ?? null,
            'sources' => $sources,
            'sections' => $sections,
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
     * @param list<array{role:string,content:string,_source_id:string}> $historyMessages
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
        array $historyMessages,
        array $memory,
        array $relationships,
        array $knowledge,
        string $knowledgeStatus,
        array $narrative,
        array $actions,
        string $actorName,
        string $playerName,
        int $budget,
    ): array {
        $outputContract = 'Return one JSON object with exactly two keys: "utterances" and "action". '
            . '"utterances" must be a JSON array of one to four objects. Each utterance object must have exactly one key named "text", '
            . 'and "text" must be a non-empty string. Never return utterances as strings. "action" is null or a supported name and parameters object. '
            . 'Do not add prose outside JSON.';
        $roleplay = 'You are ' . $actorName . ', a character in the universe of Morrowind. '
            . 'This world is your reality. Remain ' . $actorName . ' and never speak, decide, or narrate dialogue for ' . $playerName . '.';
        $general = "Write {$actorName}'s next dialogue line. Address {$playerName} or the most recent speaker, review the conversation, and avoid repeating prior dialogue.";

        $npc = $this->xmlTag('roleplay_instructions', $roleplay)
            . $this->characterXml($turn, $profile, $actorName);
        $core = $coreProfile === null ? '' : $this->fieldText($coreProfile['content'] ?? [], ['prompt']);
        if ($core !== '') $npc .= $this->xmlTag('core_profile_instructions', $core);
        $instruction = $this->fieldText($prompt['content'] ?? [], ['instruction', 'prompt', 'default_prompt', 'custom_prompt']);
        if ($instruction !== '') $npc .= $this->xmlTag('roleplay_prompt', $instruction);
        $npc .= $this->xmlTag('general_instructions', $general);

        $context = $turn['payload']['context'] ?? [];
        $morrowind = '';
        $world = $this->worldXml($context);
        if ($world !== '') $morrowind .= '<world>' . $world . '</world>';
        $people = $this->peoplePresentXml($turn, $context);
        if ($people !== '') $morrowind .= '<people_present>' . $people . '</people_present>';
        $nearbyActors = $this->nearbyActorsXml($turn, $context);
        if ($nearbyActors !== '') $morrowind .= '<nearby_actors>' . $nearbyActors . '</nearby_actors>';
        $nearbyItems = $this->nearbyObjectsXml($context, ['items'], 'item');
        if ($nearbyItems !== '') $morrowind .= '<nearby_items>' . $nearbyItems . '</nearby_items>';
        $pointsOfInterest = $this->nearbyObjectsXml($context, ['doors', 'containers', 'activators'], 'point');
        if ($pointsOfInterest !== '') $morrowind .= '<points_of_interest>' . $pointsOfInterest . '</points_of_interest>';

        $playerNarrator = '';
        $player = $this->playerXml($turn, $playerName);
        if ($player !== '') $playerNarrator .= '<player_character>' . $player . '</player_character>';
        $narrator = $this->narratorXml($turn);
        if ($narrator !== '') $playerNarrator .= '<narrator>' . $narrator . '</narrator>';
        $descriptions = $this->recordDescriptionsXml($turn['_item_descriptions'] ?? []);
        if ($descriptions !== '') $morrowind .= '<record_descriptions>' . $descriptions . '</record_descriptions>';
        $oghma = $this->oghmaKnowledgeFragment($knowledge, $knowledgeStatus);
        $narrativeXml = $this->sourceItemsXml($narrative, 'narrative');
        if ($narrativeXml !== '') $morrowind .= '<narrative_context>' . $narrativeXml . '</narrative_context>';

        $conversation = '';
        $historyText = [];
        foreach ($historyMessages as $message) {
            $line = $message['role'] === 'assistant'
                ? $actorName . ': ' . $message['content']
                : $message['content'];
            $conversation .= $this->xmlTag('message', $line);
            if ($message['_complete']) $historyText[] = $line;
        }
        $relationshipXml = $this->sourceItemsXml($relationships, 'relationship');
        $memoryCandidates = array_map(fn(array $row): array => ['id' => $this->sourceId('memory', $row),
            'text' => $this->canonical($this->sourceContent('memory', $row))], $memory);
        $memoryState = MemoryPromptSelection::select($memoryCandidates, implode("\n", $historyText), $this->maxSourceBytes);
        $memoryXml = $memoryState['xml'];
        $actionResults = $this->sourceItemsXml($actions, 'action_result');
        $capabilities = $turn['_negotiated_capabilities'] ?? [];
        $capabilityXml = '';
        if (is_array($capabilities)) {
            foreach ($capabilities as $capability) if (is_string($capability)) $capabilityXml .= $this->xmlTag('capability', $capability);
        }
        $negotiatedActions = $capabilityXml;
        if ($actionResults !== '') $negotiatedActions .= '<recent_action_results>' . $actionResults . '</recent_action_results>';
        $negotiatedActions .= $this->xmlTag('action_contract',
            'action must be null or an object with exactly name and parameters; the server adds actor, target, and tier. '
            . 'Allowed actions are null; inspect.report or inventory.inspect with empty parameters; ai.follow with distance 192; ai.stop, ai.approach, or ai.face with empty parameters; ai.wait with duration_seconds in whole-hour multiples from 3600..86400; ai.wander with integer distance 0..2048 and duration_seconds in whole-hour multiples from 3600..86400; ai.travel or ai.escort with destination_x, destination_y, destination_z, and destination_cell; combat.start or combat.stop with empty parameters; animation.play with group idle2 through idle9; item.use with inventory content_file and record_id; item.equip with inventory content_file, record_id, and equipment slot; or item.unequip with an equipment slot.');

        $sections = [
            'output_contract' => $this->xmlTag('response_contract', $outputContract),
            'npc_context' => $npc,
            'player_narrator_context' => $playerNarrator,
            'morrowind_context' => $morrowind,
            'oghma_context' => $oghma,
            'relationships_factions' => $relationshipXml,
            'memory_context' => $memoryXml,
            'conversation_context' => $conversation,
            'audience_speaker_rules' => $this->xmlTag('rules', "Only speak as {$actorName}. Address the most recent speaker and never invent dialogue for {$playerName} or another actor."),
            'negotiated_actions' => $negotiatedActions,
            'current_turn' => $this->xmlTag('request', $this->currentTurnMessage($turn, $actorName, $playerName)),
        ];
        $render = static function(array $bodies):string {
            $xml = '<roleplay_context>';
            foreach (self::SECTION_ORDER as $key => $_) $xml .= '<' . $key . '>' . $bodies[$key] . '</' . $key . '>';
            return $xml . '</roleplay_context>';
        };
        $system = $render($sections);
        foreach (['conversation_context','morrowind_context','memory_context','relationships_factions',
            'player_narrator_context','npc_context','audience_speaker_rules'] as $optional) {
            if (strlen($system) <= $budget) break;
            $sections[$optional] = '';
            if ($optional === 'conversation_context') {
                $memoryState = MemoryPromptSelection::select($memoryCandidates, '', $this->maxSourceBytes);
                $sections['memory_context'] = $memoryState['xml'];
            }
            $system = $render($sections);
        }
        return ['system' => strlen($system) <= $budget ? $system : $this->minimalSystemPrompt($actorName, $playerName),
            'memory' => $memoryState];
    }

    private function minimalSystemPrompt(string $actorName, string $playerName): string
    {
        return '<roleplay_context>'
            . $this->xmlTag('roleplay_instructions', "You are {$actorName} in Morrowind. Never speak as {$playerName}.")
            . '<character>' . $this->xmlTag('name', $actorName) . '</character>'
            . $this->xmlTag('general_instructions', "Write {$actorName}'s next dialogue line and return the required JSON object.")
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
            'relationships' => ['relationships'],
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
        $stateXml = $this->actorStateXml($state, ['activity', 'disposition', 'health', 'health_percent']);
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
        $stateXml = $this->actorStateXml($state, ['race', 'class', 'level', 'health', 'health_percent']);
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

    /** Render actor state as semantic XML rather than embedding OpenMW state JSON. */
    private function actorStateXml(array $state, array $scalarKeys): string
    {
        $xml = $this->knownFieldsXml($state, $scalarKeys);
        $identity = $state['identity'] ?? null;
        if (is_array($identity) && !array_is_list($identity)) {
            $xml .= $this->knownFieldsXml($identity, ['race', 'class', 'gender', 'primary_faction']);
        }
        $stats = $state['stats'] ?? null;
        if (is_array($stats) && !array_is_list($stats)) {
            if (isset($stats['level']) && is_numeric($stats['level'])) $xml .= $this->xmlTag('level', (string)$stats['level']);
            foreach (['health', 'magicka', 'fatigue'] as $name) {
                $value = $stats[$name] ?? null;
                if (!is_array($value) || array_is_list($value)) continue;
                $summary = [];
                foreach (['current', 'base'] as $field) if (isset($value[$field]) && is_numeric($value[$field])) $summary[] = $field . '=' . $value[$field];
                if ($summary !== []) $xml .= $this->xmlTag($name, implode(', ', $summary));
            }
        }
        foreach (['equipment'=>'equipment','inventory'=>'inventory'] as $field=>$tag) {
            $items = $this->contextItems($state[$field] ?? []);
            $itemsXml = '';
            foreach (array_slice($items, 0, 48) as $item) {
                if (!is_array($item) || array_is_list($item)) continue;
                $name = trim((string)($item['display_name'] ?? $item['record_id'] ?? ''));
                if ($name === '') continue;
                $suffix = isset($item['slot']) ? ' [' . $item['slot'] . ']' : '';
                if (isset($item['count']) && (int)$item['count'] > 1) $suffix .= ' x' . (int)$item['count'];
                $itemsXml .= $this->xmlTag('item', $name . $suffix);
            }
            if ($itemsXml !== '') $xml .= '<' . $tag . '>' . $itemsXml . '</' . $tag . '>';
        }
        return $xml;
    }

    private function worldXml(mixed $context): string
    {
        if (!is_array($context) || array_is_list($context)) return '';
        $world = is_array($context['world'] ?? null) && !array_is_list($context['world']) ? $context['world'] : $context;
        $xml = '';
        foreach (['cell'=>'location','region'=>'region'] as $field=>$tag) {
            if (is_scalar($world[$field] ?? null) && trim((string)$world[$field]) !== '') {
                $xml .= $this->xmlTag($tag, trim((string)$world[$field]));
            }
        }
        $cell = $world['cell_identity'] ?? null;
        if (is_array($cell) && !array_is_list($cell)) {
            $xml .= $this->xmlTag('location_type', (string)($cell['kind'] ?? 'unknown'));
            if (($cell['kind'] ?? null) === 'exterior' && isset($cell['grid_x'], $cell['grid_y'])) {
                $xml .= $this->xmlTag('coordinates', (string)$cell['grid_x'] . ', ' . (string)$cell['grid_y']);
            }
        }
        $weather = $world['weather'] ?? null;
        if (is_array($weather) && !array_is_list($weather)) {
            $name = trim((string)($weather['name'] ?? $weather['record_id'] ?? ''));
            if ($name !== '') $xml .= $this->xmlTag('weather', $name);
            if (($weather['is_storm'] ?? false) === true) $xml .= $this->xmlTag('weather_condition', 'storm');
        } elseif (is_scalar($weather) && trim((string)$weather) !== '') {
            $xml .= $this->xmlTag('weather', trim((string)$weather));
        }
        $calendar = $world['calendar'] ?? null;
        if (is_array($calendar) && !array_is_list($calendar)) {
            $parts = [];
            if (isset($calendar['day'])) $parts[] = (string)$calendar['day'];
            if (is_string($calendar['month_name'] ?? null) && $calendar['month_name'] !== '') $parts[] = $calendar['month_name'];
            if (isset($calendar['year'])) $parts[] = '3E ' . (string)$calendar['year'];
            if ($parts !== []) $xml .= $this->xmlTag('date', implode(' ', $parts));
            if (is_string($calendar['time'] ?? null) && $calendar['time'] !== '') $xml .= $this->xmlTag('time', $calendar['time']);
        }
        return $xml;
    }

    private function peoplePresentXml(array $turn, mixed $context): string
    {
        if (!is_array($context) || array_is_list($context)) return '';
        $identities = [$turn['payload']['speaker'] ?? null, $turn['payload']['target'] ?? null];
        foreach ($this->contextItems($context['nearbyActors'] ?? []) as $actor) $identities[] = $actor;
        $names = [];
        foreach ($identities as $identity) {
            $name = $this->identityName($identity, '');
            if ($name !== '' && $name !== 'Unknown') $names[mb_strtolower($name, 'UTF-8')] = $name;
        }
        $xml = '';
        foreach (array_values($names) as $name) $xml .= $this->xmlTag('person', $name);
        return $xml;
    }

    private function nearbyActorsXml(array $turn, mixed $context): string
    {
        if (!is_array($context) || array_is_list($context)) return '';
        $activities = [];
        foreach ($this->contextItems($context['actorActivities'] ?? []) as $status) {
            if (!is_array($status) || array_is_list($status)) continue;
            $key = $this->actorSemanticKey($status['actor'] ?? null);
            if ($key !== '') $activities[$key] = trim((string)($status['activity'] ?? ''));
        }
        $xml = '';
        foreach ($this->contextItems($context['nearbyActors'] ?? []) as $actor) {
            if (!is_array($actor) || array_is_list($actor)
                || $this->sameActor($actor, $turn['payload']['speaker'] ?? [])
                || $this->sameActor($actor, $turn['payload']['target'] ?? [])) continue;
            $entry = $this->xmlTag('name', $this->identityName($actor, 'Unknown'));
            $profile = $this->nearbyProfile($turn, $actor);
            if ($profile !== null) {
                $content = is_array($profile['content'] ?? null) && !array_is_list($profile['content']) ? $profile['content'] : [];
                foreach (['basic_summary'=>['biography','background','basic_summary','persona'],
                    'personality'=>['personality'],'appearance'=>['appearance'],'occupation'=>['occupation','class']] as $tag=>$keys) {
                    $value=$this->fieldText($content,$keys);if($value!=='')$entry.=$this->xmlTag($tag,$value);
                }
            }
            if (isset($actor['distance']) && is_numeric($actor['distance'])) $entry .= $this->xmlTag('distance', (string)$actor['distance']);
            $activity = $activities[$this->actorSemanticKey($actor)] ?? '';
            if ($activity !== '') $entry .= $this->xmlTag('current_activity', $activity);
            $equipment = $this->contextItems($actor['equipment'] ?? []);
            if ($equipment !== []) {
                $equipmentXml = '';
                foreach ($equipment as $item) {
                    if (!is_array($item) || array_is_list($item)) continue;
                    $label = trim((string)($item['display_name'] ?? $item['record_id'] ?? ''));
                    if ($label !== '') $equipmentXml .= $this->xmlTag('item', $label . (isset($item['slot']) ? ' [' . $item['slot'] . ']' : ''));
                }
                if ($equipmentXml !== '') $entry .= '<equipment>' . $equipmentXml . '</equipment>';
            }
            $xml .= '<actor>' . $entry . '</actor>';
        }
        return $xml;
    }

    /** @param list<string> $kinds */
    private function nearbyObjectsXml(mixed $context, array $kinds, string $tag): string
    {
        if (!is_array($context) || array_is_list($context)) return '';
        $groups = [];
        foreach ($this->contextItems($context['nearbyObjects'] ?? []) as $object) {
            if (!is_array($object) || array_is_list($object) || !in_array($object['kind'] ?? null, $kinds, true)) continue;
            $name = trim((string)($object['display_name'] ?? $object['record_id'] ?? ''));
            if ($name === '') continue;
            $key = mb_strtolower((string)($object['kind'] ?? '') . '|' . $name, 'UTF-8');
            if (!isset($groups[$key])) $groups[$key] = ['name'=>$name,'kind'=>(string)$object['kind'],'count'=>0,'distance'=>$object['distance'] ?? null,'lock'=>$object['lock'] ?? null];
            $groups[$key]['count'] += max(1, (int)($object['count'] ?? 1));
            if (is_numeric($object['distance'] ?? null) && (!is_numeric($groups[$key]['distance']) || $object['distance'] < $groups[$key]['distance'])) $groups[$key]['distance'] = $object['distance'];
        }
        $xml = '';
        foreach ($groups as $object) {
            $entry = $this->xmlTag('name', $object['name']) . $this->xmlTag('kind', $object['kind']);
            if ($object['count'] > 1) $entry .= $this->xmlTag('count', (string)$object['count']);
            if (is_numeric($object['distance'])) $entry .= $this->xmlTag('distance', (string)$object['distance']);
            if (is_array($object['lock']) && !array_is_list($object['lock'])) {
                $entry .= $this->xmlTag('locked', ($object['lock']['locked'] ?? false) ? 'true' : 'false');
                if (isset($object['lock']['level'])) $entry .= $this->xmlTag('lock_level', (string)$object['lock']['level']);
            }
            $xml .= '<' . $tag . '>' . $entry . '</' . $tag . '>';
        }
        return $xml;
    }

    /** Accept both raw client arrays and context.snapshot bounded-array envelopes. */
    private function contextItems(mixed $value): array
    {
        if (!is_array($value)) return [];
        if (array_is_list($value)) return $value;
        return is_array($value['items'] ?? null) && array_is_list($value['items']) ? $value['items'] : [];
    }

    private function actorSemanticKey(mixed $identity): string
    {
        if (!is_array($identity) || array_is_list($identity)) return '';
        return mb_strtolower(trim((string)($identity['content_file'] ?? '')) . '|' . trim((string)($identity['record_id'] ?? '')), 'UTF-8');
    }

    private function nearbyProfile(array $turn, array $actor): ?array
    {
        $profiles=$turn['_nearby_actor_profiles']??[];
        if(!is_array($profiles)||!array_is_list($profiles))return null;
        foreach($profiles as$profile){
            if(is_array($profile)&&!array_is_list($profile)&&$this->sameActor($profile['actor_identity']??[],$actor))return$profile;
        }
        return null;
    }

    /** @param list<array<string,mixed>> $rows @return list<array{role:string,content:string,_source_id:string,_complete:bool}> */
    private function historyMessages(array $rows, array $turn, string $actorName, string $playerName): array
    {
        if (($turn['payload']['ui_source'] ?? null) === 'almsivi_rechat') {
            $latestPlayerInput = null;
            foreach ($rows as $index => $row) {
                $content = $row['content'] ?? null;
                if (is_array($content) && ($content['kind'] ?? null) === 'event'
                    && in_array($content['type'] ?? null, ['inputtext', 'turn.requested'], true)) {
                    $latestPlayerInput = $index;
                }
            }
            if ($latestPlayerInput !== null) $rows = array_slice($rows, $latestPlayerInput);
        }
        $messages = [];
        $semanticEvents = [];
        $messageIndexes = [];
        foreach ($rows as $row) {
            $id = $this->sourceId('history', $row);
            $content = $row['content'] ?? null;
            $message = $this->historyMessage($content, $turn, $actorName, $playerName);
            if ($message === null) continue;
            $message['_complete'] = strlen($message['content']) <= $this->maxSourceBytes;
            $message['content'] = $this->truncateUtf8($message['content'], $this->maxSourceBytes);
            if (str_starts_with($message['content'], '[Journal] ')) {
                $semanticKey = mb_strtolower(preg_replace('/\s+/u', ' ', trim($message['content'])) ?? $message['content'], 'UTF-8');
                if (isset($semanticEvents[$semanticKey])) continue;
                $semanticEvents[$semanticKey] = true;
            }
            $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($message['content'])) ?? $message['content'], 'UTF-8');
            $messageKey = $message['role'] . '|' . $normalized;
            if (isset($messageIndexes[$messageKey])) unset($messages[$messageIndexes[$messageKey]]);
            $messages[] = $message + ['_source_id' => $id];
            $messageIndexes[$messageKey] = array_key_last($messages);
        }
        // The repository already applies the profile's turn limit. Retain its newest messages
        // within the existing byte budget instead of silently applying another fixed row cap.
        $bounded=[];$bytes=0;
        foreach(array_reverse(array_values($messages))as$message){
            $line=$message['role']==='assistant'?$actorName.': '.$message['content']:$message['content'];
            $size=strlen($this->xmlTag('message',$line));
            if($bytes+$size>self::SECTIONS['history']['bytes'])break;
            $bounded[]=$message;$bytes+=$size;
        }
        return array_reverse($bounded);
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
            if (in_array($type, ['inputtext', 'turn.requested'], true)) {
                $text = trim((string) ($content['input']['text'] ?? ''));
                if ($this->ignoredHistoryText($text)) return null;
                $speaker = $this->identityName($content['speaker'] ?? null, $playerName);
                return ['role' => 'user', 'content' => $speaker . ': ' . $text];
            }
            $details = $content['details'] ?? null;
            $event = $this->semanticHistoryEvent($type, $details, $content);
            return $event === null ? null : ['role' => 'user', 'content' => $event];
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

    private function semanticHistoryEvent(string $type, mixed $details, array $content): ?string
    {
        $details = is_array($details) && !array_is_list($details) ? $details : [];
        $location = trim((string)($details['location'] ?? $content['location'] ?? ''));
        return match ($type) {
            'location' => $location === '' ? null : '[Location] The player entered ' . $location . '.',
            'weather' => ($weather = trim((string)($details['weather'] ?? $details['name'] ?? ''))) === ''
                ? null : '[Weather] The weather changed to ' . $weather . '.',
            'quest' => ($text = trim((string)($details['text'] ?? $details['journal_entry'] ?? ''))) === ''
                ? null : '[Journal] ' . $text,
            'book' => ($title = trim((string)($details['title'] ?? $details['record_id'] ?? ''))) === ''
                ? null : '[Book read] ' . $title . (($text = trim((string)($details['text'] ?? ''))) === '' ? '' : ': ' . $text),
            'death' => '[World event] ' . (trim((string)($details['text'] ?? 'An actor died.')) ?: 'An actor died.'),
            'infoaction' => '[Action result] ' . (trim((string)($details['text'] ?? $details['data'] ?? 'An action completed.')) ?: 'An action completed.'),
            'narration' => ($text = trim((string)($details['text'] ?? ''))) === '' ? null : '[Narration] ' . $text,
            default => null,
        };
    }

    private function currentTurnMessage(array $turn, string $actorName, string $playerName): string
    {
        $rechat = $turn['payload']['context']['rechat'] ?? null;
        if (is_array($rechat) && !array_is_list($rechat)) {
            $previous = $this->identityName($rechat['speaker'] ?? null, $playerName);
            $strict = ($rechat['strict_targeting'] ?? false) === true;
            $seed = (string) ($rechat['chain_id'] ?? '') . ':' . (string) ($rechat['rechat_depth'] ?? 1);
            $cues = [
                "Dialogue turn for {$actorName}. Respond naturally to whoever just spoke. Address the previous speaker directly.",
                "Dialogue turn for {$actorName}. Continue the conversation naturally. Address whoever you're actually responding to.",
                "Dialogue turn for {$actorName}. Focus on one actor - respond to whoever just spoke.",
            ];
            $cue = $strict
                ? "Dialogue turn for {$actorName}. The previous speaker was {$previous}. You must respond directly to {$previous}."
                : $cues[(int) (hexdec(substr(hash('sha256', $seed), 0, 7)) % count($cues))];
            $listener = $strict
                ? "Specify who {$actorName} is talking to. The listener must be exactly {$previous}. Address the person who just spoke."
                : "Specify who {$actorName} is talking to. Address whoever just spoke - can be any person in the conversation.";
            $closing = ($rechat['is_final_round'] ?? false) === true
                ? "\n\n[This is your final response in this exchange. Conclude your current thought naturally — you are not leaving, just finishing what you were saying for now.]"
                : '';
            return $cue . "\n\n" . $listener . $closing;
        }
        $text = trim((string) ($turn['payload']['input']['text'] ?? ''));
        if ($text === '') throw new InvalidArgumentException('invalid_turn_input');
        $speaker = $playerName;
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

    /** Render the shared Oghma parity fragment with explicit authorized articles and denied topics. */
    private function knowledgeItemsXml(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) continue;
            $topic = trim((string)($row['topic'] ?? $row['title'] ?? ''));
            if ($topic === '') continue;
            $source = trim((string)($row['source'] ?? 'conversation')) ?: 'conversation';
            $access = trim((string)($row['access_level'] ?? $row['access'] ?? 'denied')) ?: 'denied';
            $lines[] = '  <article topic="' . $this->oghmaXmlValue($topic) . '" source="'
                . $this->oghmaXmlValue($source) . '" access="' . $this->oghmaXmlValue($access) . '">';
            if ($access === 'denied') {
                $reason = trim((string)($row['reason'] ?? $row['access_reason'] ?? 'knowledge_classes_not_authorized'));
                $lines[] = '    <denial reason="' . $this->oghmaXmlValue($reason) . '" />';
                $lines[] = '  </article>';
                continue;
            }
            $lines[] = '    <content>' . $this->oghmaXmlValue((string)($row['content'] ?? '')) . '</content>';
            $lines[] = '  </article>';
        }
        return implode("\n", $lines);
    }

    private function oghmaXmlValue(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', "\u{FFFD}", $value) ?? '';
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }

    private function recordDescriptionsXml(mixed $rows): string
    {
        if (!is_array($rows) || !array_is_list($rows)) return '';
        $safe = [];
        foreach (array_slice($rows, 0, 64) as $row) {
            if (!is_array($row) || array_is_list($row)) continue;
            $record = $this->allow($row, ['record_id', 'content_file', 'name', 'description', 'source']);
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
    private function traceSources(array $rows, array $turn, array $includedHistory, string $system, array $memoryState): array
    {
        $sources = [];
        $ordinal = 0;
        $sectionBodies = [];
        foreach (self::SECTION_ORDER as $key => $_) {
            if (preg_match('#<' . preg_quote($key, '#') . '>(.*?)</' . preg_quote($key, '#') . '>#s', $system, $match) === 1) {
                $sectionBodies[$key] = (string)$match[1];
            }
        }
        foreach ($rows as $kind => $items) {
            foreach ($items as $item) {
                $this->assertSourceScope($item, $turn, $kind);
                $content = $this->canonical($this->sourceContent($kind, $item));
                $id = $this->sourceId($kind, $item);
                $section = $this->sectionForSource($kind);
                $included = $kind === 'history' ? isset($includedHistory[$id])
                    : ($kind === 'turn' || (($sectionBodies[$section] ?? '') !== ''));
                $includedBytes = $included ? min(strlen($content), $this->maxSourceBytes, self::SECTIONS[$kind]['bytes']) : 0;
                $includedContent = $includedBytes > 0 ? $this->truncateUtf8($content, $includedBytes) : '';
                $reason = !$included ? ($kind === 'history' ? 'section_limit' : 'byte_limit')
                    : ($includedBytes < strlen($content) ? 'byte_limit' : 'included');
                if ($kind === 'memory') {
                    $included = ($sectionBodies[$section] ?? '') !== '' && isset($memoryState['texts'][$id]);
                    $includedContent = $included ? $memoryState['texts'][$id] : '';
                    $includedBytes = strlen($includedContent);
                    $reason = $memoryState['reasons'][$id] ?? 'section_limit';
                    if ($reason === 'empty') $reason = 'section_limit';
                    if (!$included && ($sectionBodies[$section] ?? '') === ''
                        && !($reason === 'covered_by_history' && $includedHistory !== [])) $reason = 'byte_limit';
                }
                $sources[] = [
                    'source_kind' => $kind,
                    'source_id' => $id,
                    'section_key' => $section,
                    'section_order' => self::SECTION_ORDER[$section],
                    'source_table' => $kind === 'memory' && isset($item['_model_summary']) ? 'memory_model_summaries' : $this->sourceTable($kind),
                    'source_revision' => $this->sourceRevision($item),
                    'source_occurred_at' => $this->sourceTimestamp($item),
                    'playthrough_id' => $turn['playthrough_id'],
                    'included' => $included,
                    'reason' => $reason,
                    'source_sha256' => hash('sha256', $content),
                    'source_bytes' => strlen($content),
                    'included_bytes' => $includedBytes,
                    'source_characters' => mb_strlen($includedContent, 'UTF-8'),
                    'estimated_tokens' => $includedContent === '' ? 0 : (int) ceil(mb_strlen($includedContent, 'UTF-8') / 4),
                    'redacted_preview' => $this->truncateUtf8($kind . ':' . $id . ' [content redacted]', 256),
                    'ordinal' => $ordinal++,
                ];
            }
        }
        return $sources;
    }

    /** Persist all ten ordered prompt families, including empty or budget-truncated sections. */
    private function traceSections(string $system, array $rows, array $turn): array
    {
        $hasOrderedSections = str_contains($system, '<output_contract>');
        $sections = [];
        foreach (self::SECTION_ORDER as $key => $order) {
            $body = '';
            if ($hasOrderedSections && preg_match('#<' . preg_quote($key, '#') . '>(.*?)</' . preg_quote($key, '#') . '>#s', $system, $match) === 1) {
                $body = (string) $match[1];
            }
            $refs = $this->sectionSourceRefs($key, $rows, $turn);
            $timestamp = null;
            foreach ($refs as $ref) {
                if (is_string($ref['occurred_at'] ?? null) && ($timestamp === null || strcmp($ref['occurred_at'], $timestamp) > 0)) {
                    $timestamp = $ref['occurred_at'];
                }
            }
            $characters = mb_strlen($body, 'UTF-8');
            $sections[] = [
                'section_order' => $order,
                'section_key' => $key,
                'source_refs' => $refs,
                'inclusion_reason' => !$hasOrderedSections ? 'minimal_fallback'
                    : ($body !== '' ? 'included' : ($refs === [] ? 'empty' : 'byte_limit')),
                'source_occurred_at' => $timestamp,
                'playthrough_id' => $turn['playthrough_id'],
                'source_characters' => $characters,
                'estimated_tokens' => $characters === 0 ? 0 : (int) ceil($characters / 4),
                'redacted_preview' => $key . ' [content redacted; ' . $characters . ' characters]',
                'source_sha256' => hash('sha256', $body),
            ];
        }
        return $sections;
    }

    /** @return list<array{table:string,id:string,revision:?int,occurred_at:?string}> */
    private function sectionSourceRefs(string $section, array $rows, array $turn): array
    {
        $refs = [];
        foreach ($rows as $kind => $items) {
            if ($this->sectionForSource($kind) !== $section) continue;
            foreach ($items as $item) {
                $refs[] = [
                    'table' => $this->sourceTable($kind),
                    'id' => $this->sourceId($kind, $item),
                    'revision' => $this->sourceRevision($item),
                    'occurred_at' => $this->sourceTimestamp($item),
                ];
            }
        }
        if (in_array($section, ['output_contract','audience_speaker_rules','current_turn'], true)
            || ($section === 'morrowind_context' && $refs === []) || ($section === 'negotiated_actions' && $refs === [])) {
            $refs[] = ['table'=>'turns','id'=>(string)$turn['turn_id'],'revision'=>null,
                'occurred_at'=>$this->sourceTimestamp($turn)];
        }
        if ($section === 'player_narrator_context') {
            foreach (['_player_profile','_narrator_profile'] as $field) {
                $profile = $turn[$field] ?? null;
                if (!is_array($profile) || array_is_list($profile)) continue;
                try {
                    $refs[] = ['table'=>'profile_revisions','id'=>$this->sourceId('profile',$profile),
                        'revision'=>$this->sourceRevision($profile),'occurred_at'=>$this->sourceTimestamp($profile)];
                } catch (InvalidArgumentException) {
                    // Optional legacy profile context may not carry a durable profile identifier.
                }
            }
        }
        return $refs;
    }

    private function sectionForSource(string $kind): string
    {
        return match ($kind) {
            'profile', 'core_profile', 'prompt' => 'npc_context',
            'knowledge' => 'oghma_context',
            'narrative' => 'morrowind_context',
            'relationship' => 'relationships_factions',
            'memory' => 'memory_context',
            'history' => 'conversation_context',
            'action_result' => 'negotiated_actions',
            'turn' => 'current_turn',
            default => throw new InvalidArgumentException('invalid_prompt_source_kind'),
        };
    }

    private function sourceTable(string $kind): string
    {
        return match ($kind) {
            'profile' => 'profile_revisions',
            'core_profile' => 'core_profile_revisions',
            'prompt' => 'configuration_revisions',
            'history' => 'eventlog',
            'memory' => 'memory_records',
            'relationship' => 'relationship_records',
            'knowledge' => 'knowledge_documents',
            'narrative' => 'narrative_records',
            'action_result' => 'action_results',
            'turn' => 'turns',
            default => throw new InvalidArgumentException('invalid_prompt_source_kind'),
        };
    }

    private function sourceTimestamp(array $source): ?string
    {
        foreach (['occurred_at','completed_at','updated_at','created_at'] as $field) {
            if (is_string($source[$field] ?? null) && $source[$field] !== '') return $source[$field];
        }
        return null;
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
            if ($field === 'profile_id' && $kind === 'memory'
                && is_string($turn['_selected_profile_id'] ?? null)
                && $source[$field] === $turn['_selected_profile_id']) continue;
            if ($field === 'profile_id' && in_array($kind, ['profile', 'prompt', 'knowledge', 'relationship'], true)
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
        if($kind==='knowledge')return['topic'=>(string)($source['topic']??$source['title']??''),
            'access_level'=>(string)($source['access_level']??'authorized'),'article'=>(string)($source['content']??'')];
        if (array_key_exists('content', $source)) return $source['content'];
        return match ($kind) {
            'relationship' => $this->allow($source, ['actor_identity', 'disposition', 'affinity', 'relationship_type']),
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
