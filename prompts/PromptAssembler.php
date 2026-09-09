<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;
use JsonException;

/** Builds one compact Markdown prompt with system-owned chat history for each turn. */
final class PromptAssembler
{
    private const ALGORITHM = 'chim-compact-roleplay-prompt-v3-markdown';
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
        'latest_diary' => ['limit' => 1, 'bytes' => 16_384],
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

        $contextPolicy = is_array($selection['effective_settings']['context'] ?? null)
            ? $selection['effective_settings']['context'] : SettingsCatalog::globalDefaults()['context'];
        $contextPolicy['details']=SettingsCatalog::normalizeContextDetails($contextPolicy['details']);
        $enabled = $contextPolicy['sections'];
        $history = $enabled['conversation_history'] ? $this->limitedSelection($selection, 'history') : [];
        $memory = array_slice($this->selectedList($selection, array_key_exists('memory_candidates', $selection) ? 'memory_candidates' : 'memory'), 0, 500);
        if (!$enabled['memories']) $memory = [];
        $memoryFlags = $selection['effective_settings']['settings']['memory']
            ?? $coreProfile['content']['settings_overrides']['memory'] ?? [];
        $responseMaxWords = (int)($selection['effective_settings']['settings']['response']['max_words']
            ?? $coreProfile['content']['settings_overrides']['response']['max_words'] ?? 0);
        $memory = array_values(array_filter($memory, static function (array $row) use ($memoryFlags): bool {
            $tier = ($row['tier'] ?? '') === 'mid' && ($row['provenance']['source'] ?? '') === 'memory.consolidate'
                && ($row['provenance']['source_tier'] ?? '') === 'recent' ? 'recent' : ($row['tier'] ?? '');
            $flag = match ($tier) { 'recent' => 'short_term_enabled', 'mid' => 'mid_term_enabled', 'long' => 'long_term_enabled', default => '' };
            return $flag === '' || ($memoryFlags[$flag] ?? true) === true;
        }));
        $relationships = $enabled['relationships'] ? $this->limitedSelection($selection, 'relationship') : [];
        $knowledge = $enabled['oghma'] ? $this->limitedSelection($selection, 'knowledge') : [];
        $narrative = $enabled['narratives'] ? $this->limitedSelection($selection, 'narrative') : [];
        $latestDiary = $this->limitedSelection($selection, 'latest_diary');
        $actions = $enabled['recent_action_results'] ? $this->terminalActionResults($selection) : [];
        foreach ([
            'history' => $history,
            'memory' => $memory,
            'relationship' => $relationships,
            'knowledge' => $knowledge,
            'narrative' => $narrative,
            'latest_diary' => $latestDiary,
            'action_result' => $actions,
        ] as $kind => $rows) {
            foreach ($rows as $row) $this->assertSourceScope($row, $turn, $kind);
        }

        $actorName = $this->actorName($turn, $profile);
        $playerName = $this->playerName($turn);
        $promptContent = is_array($prompt['content'] ?? null) ? $prompt['content'] : [];
        $moodTemplates = $promptContent['player_mood_prompts'] ?? null;
        $playerMoodCue = PlayerMoodPolicy::cue($turn['payload']['input']['mood'] ?? null, $moodTemplates, $playerName);
        $final = $this->currentTurnMessage($turn, $actorName, $playerName, $moodTemplates);
        // Inline templates describe the existing leading-asterisk transport; direct Narrator dialogue is separate.
        $narratorContent = $turn['_narrator_profile']['content'] ?? [];
        $inlineMode = ($narratorContent['enabled'] ?? false) === true ? ($narratorContent['inline_narration_mode'] ?? 'Disabled') : 'Disabled';
        if (($turn['payload']['target']['kind'] ?? '') !== 'narrator' && in_array($inlineMode, ['Narrator','NPC','Text Only'], true)) {
            $suffix = $inlineMode === 'Narrator' ? 'narrator' : 'npc';
            $maxWords = $responseMaxWords;
            $replacements = ['{NPC_NAME}'=>$actorName, '{NARRATOR_NAME}'=>(string)($turn['_narrator_profile']['name'] ?? 'The Narrator'),
                '{MAXIMUM_WORDS}'=>$maxWords > 0 ? " Keep the complete response within {$maxWords} words." : ''];
            foreach (['dialogue_line_inline_response_', 'inline_narration_prompt_'] as $prefix) {
                $key = $prefix.$suffix;
                $custom = $turn['_narrator_event_prompts'][$key] ?? null;
                $instruction = is_string($custom) && trim($custom) !== '' ? $custom : NarratorEventPrompts::definitions()[$key]['default_prompt'];
                $final .= "\n".strtr($instruction, $replacements);
            }
        }
        $historyMessages = $this->historyMessages($history, $turn, $actorName, $playerName, $moodTemplates, $contextPolicy['prompt_timestamp'] ?? false);
        $knowledgeStatus = (string)($selection['knowledge_retrieval']['status'] ?? 'grounded');
        $systemBudget = max(192, $this->maxInputBytes - strlen($final) - 256);
        $speechStyle = is_array($selection['speech_style'] ?? null) ? $selection['speech_style'] : [];
        $this->assertSourceScope($speechStyle, $turn, 'speech_style');
        $llmSpeechLanguage=($selection['effective_settings']['settings']['response']['lang_llm_xtts'] ?? $coreProfile['content']['settings_overrides']['response']['lang_llm_xtts'] ?? false)===true;
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
            $latestDiary,
            $actions,
            $actorName,
            $playerName,
            $systemBudget,
            $contextPolicy,
            $selection['effective_settings']['prompt'] ?? SettingsCatalog::globalDefaults()['prompt'],
            ParalinguisticSpeech::prompt($speechStyle),
            $responseMaxWords,
            (string)($selection['effective_settings']['settings']['response']['core_lang'] ?? $coreProfile['content']['settings_overrides']['response']['core_lang'] ?? ''),
            $llmSpeechLanguage,
            (int)($memoryFlags['short_term_max_summaries'] ?? 10),
        );

        $system = $built['system'];
        $traceSystem = $built['trace_system'];
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
        if (preg_match('#<conversation_context>(.+)</conversation_context>#s', $traceSystem) === 1) {
            foreach ($built['history_ids'] as $id) $includedHistory[$id] = true;
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
            'latest_diary' => $latestDiary,
            'action_result' => $actions,
            'turn' => [['id' => $turn['turn_id'], 'content' => $this->turnTraceContent($turn)]],
        ];
        $sources = $this->traceSources($rows, $turn, $includedHistory, $traceSystem, $memoryState);
        $sections = $this->traceSections($traceSystem, $rows, $turn);
        $memoryIncluded = str_contains($traceSystem, '<memory_context><item>');
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
            if (!$memoryIncluded) $memoryRetrieval['coverage']['covered_by_context'] = 0;
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
        foreach (['history', 'memory', 'relationship', 'knowledge', 'narrative', 'latest_diary'] as $kind) {
            $truncated = $truncated || count($rows[$kind]) < count($this->selectedList($selection, $kind));
        }
        foreach ($sources as $source) $truncated = $truncated || !in_array($source['reason'], ['included', 'covered_by_history', 'covered_by_memory', 'covered_by_context', 'outside_scene_window'], true);

        $providerInput = $this->providerInput($turn, $assembled, $messages);
        if ($llmSpeechLanguage) $providerInput['_llm_tts_language'] = true;
        $trace = [
            'algorithm' => self::ALGORITHM,
            'prompt_format' => 'markdown',
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
            'speech_style_configuration_id' => $speechStyle['configuration_id'] ?? null,
            'speech_style_revision' => $speechStyle['revision'] ?? null,
            'memory_retrieval' => $memoryRetrieval,
            'knowledge_retrieval' => $selection['knowledge_retrieval'] ?? null,
            'player_mood_cue' => $playerMoodCue,
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
     * Construct CHIM-equivalent context families while keeping LORKHAN's typed response contract.
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
        array $latestDiary,
        array $actions,
        string $actorName,
        string $playerName,
        int $budget,
        array $contextPolicy,
        array $promptDefaults,
        string $speechStylePrompt,
        int $responseMaxWords,
        string $coreLanguage,
        bool $llmSpeechLanguage,
        int $sceneLimit,
    ): array {
        $outputContract = 'Return one JSON object with exactly two keys: "utterances" and "action". '
            . '"utterances" must be a JSON array of one to four objects. Each utterance object must have exactly one key named "text", '
            . 'and "text" must be a non-empty string. Never return utterances as strings. "action" is null or a supported name and parameters object. '
            . 'Do not add prose outside JSON.';
        if ($llmSpeechLanguage) {
            $outputContract = str_replace('exactly two keys: "utterances" and "action"', 'exactly three keys: "language", "utterances" and "action"', $outputContract);
            $outputContract .= ' Write "language" first, before "utterances": use the spoken dialogue language code from '.implode(', ', SpeechLanguage::CODES).'. Never place the language code inside spoken text.';
        }
        $maxWords = $responseMaxWords;
        if (is_int($maxWords) && $maxWords > 0 && $maxWords <= 10000) {
            $outputContract .= ' Keep the combined spoken dialogue across all utterances within ' . $maxWords . ' words.';
        }
        $roleplay = 'You are ' . $actorName . ', a character in the universe of Morrowind. '
            . 'This world is your reality. Remain ' . $actorName . ' and never speak, decide, or narrate dialogue for ' . $playerName . '.';
        $general = "Write {$actorName}'s next dialogue line. Address {$playerName} or the most recent speaker, review the conversation, and avoid repeating prior dialogue.";

        $localized = CoreProfileLanguage::instructions($coreLanguage, $actorName, $playerName);
        if ($localized !== null) [$roleplay, $general] = $localized;

        $npc = $this->xmlTag('roleplay_instructions', $roleplay);
        // Global roleplay defaults fill absent NPC fields without changing the saved profile.
        $roleplayProfile = $profile;
        foreach (['prompt_head','emote_moods'] as $field) {
            if ($this->fieldText($roleplayProfile['content'] ?? [], [$field]) === '') {
                $roleplayProfile['content'][$field] = (string)($promptDefaults[$field] ?? '');
            }
        }
        $promptHead = $this->fieldText($roleplayProfile['content'] ?? [], ['prompt_head']);
        if ($promptHead !== '') $npc .= $this->xmlTag('npc_prompt_head', $promptHead);
        if ($contextPolicy['inventory_items_descriptions_only'] ?? false) {
            foreach (['playerState', 'targetState'] as $stateKey) {
                $inventory = $turn['payload']['context'][$stateKey]['inventory']
                    ?? ($stateKey === 'playerState' ? ($turn['payload']['context']['inventory'] ?? []) : []);
                $turn['payload']['context'][$stateKey]['inventory'] = array_values(array_filter(
                    $this->contextItems($inventory),
                    fn($item) => is_array($item) && (int)($item['count'] ?? 0) <= 5
                        && $this->itemHasDescription($item, $turn['_item_descriptions'] ?? [])
                ));
            }
        }
        $details = $contextPolicy['details'];
        $itemBlacklist = $this->blacklistSet($contextPolicy['item_blacklist']);
        $magicBlacklist = $this->blacklistSet($contextPolicy['magic_effects_blacklist']);
        $npc .= $this->characterXml($turn, $roleplayProfile, $actorName, $details, $itemBlacklist, $magicBlacklist);
        foreach ($latestDiary as $entry) $npc .= $this->xmlTag('latest_diary_entry', $this->truncateUtf8($this->sourceContent('latest_diary', $entry), min($this->maxSourceBytes, self::SECTIONS['latest_diary']['bytes'])));
        $core = $coreProfile === null ? '' : $this->fieldText($coreProfile['content'] ?? [], ['prompt']);
        if ($core !== '') $npc .= $this->xmlTag('core_profile_instructions', $core);
        $instruction = $this->fieldText($prompt['content'] ?? [], ['instruction', 'prompt', 'default_prompt', 'custom_prompt']);
        if ($instruction !== '') $npc .= $this->xmlTag('roleplay_prompt', $instruction);
        if ($speechStylePrompt !== '') $npc .= $this->xmlTag('speech_style_instructions', $speechStylePrompt);
        $npc .= $this->xmlTag('general_instructions', $general);

        $context = $turn['payload']['context'] ?? [];
        $morrowind = '';
        $world = $contextPolicy['sections']['world'] ? $this->worldXml($context, $this->blacklistSet($contextPolicy['location_blacklist'])) : '';
        if ($world !== '') $morrowind .= '<world>' . $world . '</world>';
        $people = $contextPolicy['sections']['people_present'] ? $this->peoplePresentXml($turn, $context) : '';
        if ($people !== '') $morrowind .= '<people_present>' . $people . '</people_present>';
        $nearbyActors = $contextPolicy['sections']['nearby_actors'] ? $this->nearbyActorsXml($turn, $context, $details, $itemBlacklist) : '';
        if ($nearbyActors !== '') $morrowind .= '<nearby_actors>' . $nearbyActors . '</nearby_actors>';
        $nearbyItems = $contextPolicy['sections']['nearby_items'] ? $this->nearbyObjectsXml($context, ['items'], 'item', $itemBlacklist, $details['group_duplicate_items'], ($contextPolicy['ground_items_descriptions_only'] ?? false) ? ($turn['_item_descriptions'] ?? []) : null) : '';
        if ($nearbyItems !== '') $morrowind .= '<nearby_items>' . $nearbyItems . '</nearby_items>';
        $pointsOfInterest = $contextPolicy['sections']['points_of_interest'] ? $this->nearbyObjectsXml($context, ['doors', 'containers', 'activators'], 'point', [], true) : '';
        if ($pointsOfInterest !== '') $morrowind .= '<points_of_interest>' . $pointsOfInterest . '</points_of_interest>';

        $playerNarrator = '';
        $player = $contextPolicy['sections']['player_narrator'] ? $this->playerXml($turn, $playerName, $details, $itemBlacklist, $magicBlacklist) : '';
        if ($player !== '') $playerNarrator .= '<player_character>' . $player . '</player_character>';
        $narrator = $contextPolicy['sections']['player_narrator'] ? $this->narratorXml($turn) : '';
        if ($narrator !== '') $playerNarrator .= '<narrator>' . $narrator . '</narrator>';
        $descriptions = $contextPolicy['sections']['record_descriptions'] && $details['item_descriptions']
            ? $this->recordDescriptionsXml($turn['_item_descriptions'] ?? [], $itemBlacklist) : '';
        if ($descriptions !== '') $morrowind .= '<record_descriptions>' . $descriptions . '</record_descriptions>';
        $oghma = $contextPolicy['sections']['oghma'] ? $this->oghmaKnowledgeFragment($knowledge, $knowledgeStatus) : '';
        $narrativeXml = $this->sourceItemsXml($narrative, 'narrative');
        if ($narrativeXml !== '') $morrowind .= '<narrative_context>' . $narrativeXml . '</narrative_context>';

        $historyText = [];
        foreach ($historyMessages as $message) {
            $line = $message['role'] === 'assistant'
                ? $actorName . ': ' . $message['content']
                : $message['content'];
            if ($message['_complete']) $historyText[] = $line;
        }
        $relationshipXml = $this->sourceItemsXml($relationships, 'relationship');
        $memoryCandidates = array_map(fn(array $row): array => ['id' => $this->sourceId('memory', $row),
            'text' => $this->canonical($this->sourceContent('memory', $row))] + $row, $memory);
        $historyTimes = array_column($historyMessages, '_game_time');
        $historyFloor = $historyTimes !== [] && count(array_filter($historyTimes, static fn($time): bool =>
            is_numeric($time) && is_finite((float)$time) && $time >= 0)) === count($historyTimes)
            ? (float)min($historyTimes) : null;
        $memoryState = MemoryPromptSelection::selectSceneContext($memoryCandidates, implode("\n", $historyText), $historyFloor, $this->maxSourceBytes, $sceneLimit);
        foreach ($historyMessages as &$message) $message['_line'] = $message['role'] === 'assistant'
            ? $actorName . ': ' . $message['content'] : $message['content'];
        unset($message);
        $pruned = MemoryPromptSelection::pruneHistory($historyMessages, $memoryCandidates, $memoryState);
        $historyMessages = $pruned['history'];
        $memoryState = $pruned['memory'];
        $conversation = '';
        foreach ($historyMessages as $message) $conversation .= $this->xmlTag('message', ($message['_time_heading'] ?? '') . $message['_line']);
        $memoryXml = $memoryState['xml'];
        $actionResults = $this->sourceItemsXml($actions, 'action_result');
        $capabilities = $turn['_negotiated_capabilities'] ?? [];
        $capabilityXml = '';
        if (is_array($capabilities)) {
            foreach ($capabilities as $capability) if (is_string($capability)) $capabilityXml .= $this->xmlTag('capability', $capability);
        }
        $negotiatedActions = $capabilityXml;
        if ($actionResults !== '') $negotiatedActions .= '<recent_action_results>' . $actionResults . '</recent_action_results>';
        $negotiatedActions .= $this->xmlTag('action_contract', (new ActionPolicyValidator())->promptContract($turn));

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
            'current_turn' => $this->xmlTag('request', $this->currentTurnMessage($turn, $actorName, $playerName,
                is_array($prompt['content'] ?? null) ? ($prompt['content']['player_mood_prompts'] ?? null) : null)),
        ];
        $presentationSections = $sections;
        $presentationSections['oghma_context'] = $this->oghmaKnowledgeMarkdown($knowledge, $knowledgeStatus);
        $renderXml = static function(array $bodies):string {
            $xml = '<roleplay_context>';
            foreach (self::SECTION_ORDER as $key => $_) $xml .= '<' . $key . '>' . $bodies[$key] . '</' . $key . '>';
            return $xml . '</roleplay_context>';
        };
        $traceSystem = $renderXml($sections);
        $system = $this->markdownSystemPrompt($presentationSections);
        foreach (['conversation_context','morrowind_context','memory_context','relationships_factions',
            'player_narrator_context','npc_context','audience_speaker_rules'] as $optional) {
            if (strlen($system) <= $budget) break;
            $sections[$optional] = '';
            $presentationSections[$optional] = '';
            if ($optional === 'conversation_context') {
                $memoryState = MemoryPromptSelection::selectSceneContext($memoryCandidates, '', null, $this->maxSourceBytes, $sceneLimit);
                $sections['memory_context'] = $memoryState['xml'];
                $presentationSections['memory_context'] = $memoryState['xml'];
            }
            $traceSystem = $renderXml($sections);
            $system = $this->markdownSystemPrompt($presentationSections);
        }
        if (strlen($system) > $budget) $system = $traceSystem = $this->minimalSystemPrompt($actorName, $playerName);
        return ['system' => $system, 'trace_system' => $traceSystem, 'memory' => $memoryState,
            'history_ids' => array_column($historyMessages, '_source_id')];
    }

    /** Present every model-facing prompt section as compact Markdown. */
    private function markdownSystemPrompt(array $sections): string
    {
        $parts = ['# Roleplay Context'];
        foreach (self::SECTION_ORDER as $key => $_) {
            $body = (string)($sections[$key] ?? '');
            if ($body === '') continue;
            $title = $this->promptLabel($key);
            $parts[] = '## ' . $title . "\n\n" . ($key === 'oghma_context'
                ? $body : $this->markdownXmlBody($body, 3));
        }
        return implode("\n\n", $parts);
    }

    /** Render authorized and denied Oghma articles without exposing XML to the model. */
    private function oghmaKnowledgeMarkdown(array $rows, string $status): string
    {
        $parts = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) continue;
            $topic = trim((string)($row['topic'] ?? $row['title'] ?? ''));
            if ($topic === '') continue;
            $source = trim((string)($row['source'] ?? 'conversation')) ?: 'conversation';
            $access = trim((string)($row['access_level'] ?? $row['access'] ?? 'denied')) ?: 'denied';
            $article = ['### Article: ' . $topic, '- **Source:** ' . $source, '- **Access:** ' . $access];
            if ($access === 'denied') {
                $reason = trim((string)($row['reason'] ?? $row['access_reason'] ?? 'knowledge_classes_not_authorized'));
                $article[] = '- **Denial Reason:** ' . $reason;
            } else {
                $article[] = '- **Content:** ' . trim((string)($row['content'] ?? ''));
            }
            $parts[] = implode("\n", $article);
        }
        if ($parts === []) return '';
        array_unshift($parts, '- **Contract:** ' . self::OGHMA_CONTRACT . "\n- **Status:** " . $status);
        return implode("\n\n", $parts);
    }

    /** Convert server-generated no-attribute XML nodes without rewriting their text. */
    private function markdownXmlBody(string $xml, int $headingLevel): string
    {
        $nodes = $this->promptXmlNodes($xml);
        if ($nodes === null) return $xml;
        $parts = [];
        foreach ($nodes as $node) {
            $children = $this->promptXmlNodes($node['body']);
            if ($children === null) {
                $text = html_entity_decode(trim($node['body']), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if ($text === '') continue;
                $text = preg_replace('/\R/u', "\n  ", $text) ?? $text;
                $parts[] = '- **' . $this->promptLabel($node['tag']) . ':** ' . $text;
                continue;
            }
            $parts[] = str_repeat('#', min(6, $headingLevel)) . ' ' . $this->promptLabel($node['tag'])
                . "\n\n" . $this->markdownXmlBody($node['body'], $headingLevel + 1);
        }
        return implode("\n\n", $parts);
    }

    /** Parse the exact compact XML emitted by this assembler; return null rather than guessing on mixed content. */
    private function promptXmlNodes(string $xml): ?array
    {
        $nodes = [];
        $offset = 0;
        $length = strlen($xml);
        while ($offset < $length) {
            if (preg_match('/\G\s+/', $xml, $space, 0, $offset) === 1) {
                $offset += strlen($space[0]);
                continue;
            }
            if (preg_match('/\G<([A-Za-z][A-Za-z0-9_-]*)>(.*?)<\/\1>/s', $xml, $match, 0, $offset) !== 1) {
                return null;
            }
            $nodes[] = ['tag' => strtolower($match[1]), 'body' => $match[2]];
            $offset += strlen($match[0]);
        }
        return $nodes === [] ? null : $nodes;
    }

    private function promptLabel(string $value): string
    {
        $words = ucwords(str_replace(['_', '-'], ' ', strtolower($value)));
        return str_replace(['Npc', 'Llm', 'Oghma'], ['NPC', 'LLM', 'Oghma'], $words);
    }

    private function minimalSystemPrompt(string $actorName, string $playerName): string
    {
        return "# Roleplay Context\n\n## NPC Context\n\n"
            . "- **Roleplay Instructions:** You are {$actorName} in Morrowind. Never speak as {$playerName}.\n\n"
            . "- **Character:** {$actorName}\n\n"
            . "- **General Instructions:** Write {$actorName}'s next dialogue line and return the required JSON object.";
    }

    private function characterXml(array $turn, array $profile, string $actorName, array $details, array $itemBlacklist, array $magicBlacklist): string
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
            'core_identity' => [['core'], true],
            'basic_summary' => [['biography', 'background', 'basic_summary', 'persona'], $details['npc_summary']],
            'personality' => [['personality'], $details['npc_personality']],
            'appearance' => [['appearance'], $details['npc_appearance']],
            'occupation' => [['occupation', 'class'], $details['npc_occupation']],
            'skills' => [['skills'], $details['npc_skills']],
            'speech_style' => [['speech_style'], $details['npc_speech_style']],
            'allowed_moods_and_emotes' => [['emote_moods'], $details['npc_moods']],
            'goals' => [['goals'], $details['npc_goals']],
            'relationships' => [['relationships'], $details['npc_relationships']],
            'notes' => [['notes'], $details['npc_notes']],
            'race' => [['race'], $details['npc_race_gender']],
            'gender' => [['gender'], $details['npc_race_gender']],
        ];
        foreach ($fields as $tag => [$keys, $include]) {
            if (!$include) continue;
            $value = $this->fieldText($content, $keys);
            if ($value !== '') $xml .= $this->xmlTag($tag, $value);
        }
        $state = is_array(($turn['payload']['context']['targetState'] ?? null))
            ? $turn['payload']['context']['targetState'] : [];
        $stateXml = $details['npc_current_state'] ? $this->actorStateXml($state, ['activity', 'disposition', 'health', 'health_percent'],
            $details['npc_equipment'], $details['npc_inventory'], $details['npc_magic_effects'], $itemBlacklist, $magicBlacklist) : '';
        if ($stateXml !== '') $xml .= '<current_state>' . $stateXml . '</current_state>';
        return '<character>' . $xml . '</character>';
    }

    private function playerXml(array $turn, string $playerName, array $details, array $itemBlacklist, array $magicBlacklist): string
    {
        $xml = $this->xmlTag('name', $playerName);
        $player = $turn['_player_profile'] ?? null;
        if (is_array($player) && !array_is_list($player)) {
            $content = is_array($player['content'] ?? null) && !array_is_list($player['content']) ? $player['content'] : [];
            $biographyVisible = ($content['biography_known_by_all'] ?? true) !== false
                || ($turn['payload']['target']['kind'] ?? null) === 'narrator';
            foreach ([
                'basic_summary' => ['biography', 'background', 'basic_summary', 'persona'],
                'personality' => ['personality'],
                'appearance' => ['appearance'],
                'speech_style' => ['speech_style'],
                'goals' => ['goals'],
                'notes' => ['notes'],
            ] as $tag => $keys) {
                if ($tag === 'basic_summary' && !$biographyVisible) continue;
                $value = $this->fieldText($content, $keys);
                if ($value !== '') $xml .= $this->xmlTag($tag, $value);
            }
        }
        $state = is_array(($turn['payload']['context']['playerState'] ?? null))
            ? $turn['payload']['context']['playerState'] : [];
        // The OpenMW context collector sends player inventory beside playerState.
        if (!array_key_exists('inventory', $state)) {
            $state['inventory'] = $turn['payload']['context']['inventory'] ?? [];
        }
        $stateXml = $this->actorStateXml($state, ['race', 'class', 'level', 'health', 'health_percent'],
            $details['npc_equipment'], $details['npc_inventory'], $details['npc_magic_effects'], $itemBlacklist, $magicBlacklist);
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
    private function actorStateXml(array $state, array $scalarKeys, bool $includeEquipment, bool $includeInventory, bool $includeMagic, array $itemBlacklist, array $magicBlacklist): string
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
            if($field==='equipment'?!$includeEquipment:!$includeInventory)continue;
            $items = $this->contextItems($state[$field] ?? []);
            $itemsXml = '';
            foreach (array_slice($items, 0, 48) as $item) {
                if (!is_array($item) || array_is_list($item)) continue;
                $name = trim((string)($item['display_name'] ?? $item['record_id'] ?? ''));
                if ($name === '' || $this->blocked($itemBlacklist, $name, (string)($item['record_id'] ?? ''))) continue;
                $suffix = isset($item['slot']) ? ' [' . $item['slot'] . ']' : '';
                if (isset($item['count']) && (int)$item['count'] > 1) $suffix .= ' x' . (int)$item['count'];
                $itemsXml .= $this->xmlTag('item', $name . $suffix);
            }
            if ($itemsXml !== '') $xml .= '<' . $tag . '>' . $itemsXml . '</' . $tag . '>';
        }
        if ($includeMagic) {
            $magicXml = '';
            foreach (['spells', 'activeEffects', 'active_effects'] as $field) foreach ($this->contextItems($state[$field] ?? []) as $effect) {
                $name = is_array($effect) ? trim((string)($effect['display_name'] ?? $effect['name'] ?? $effect['record_id'] ?? '')) : trim((string)$effect);
                $recordId = is_array($effect) ? (string)($effect['record_id'] ?? '') : '';
                if ($name === '' || $this->blocked($magicBlacklist, $name, $recordId)) continue;
                $magicXml .= $this->xmlTag('effect', $name);
            }
            if ($magicXml !== '') $xml .= '<magic_and_effects>' . $magicXml . '</magic_and_effects>';
        }
        return $xml;
    }

    private function worldXml(mixed $context, array $locationBlacklist): string
    {
        if (!is_array($context) || array_is_list($context)) return '';
        $world = is_array($context['world'] ?? null) && !array_is_list($context['world']) ? $context['world'] : $context;
        if ($this->blocked($locationBlacklist, (string)($world['cell'] ?? ''), (string)($world['region'] ?? ''))) return '';
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

    private function nearbyActorsXml(array $turn, mixed $context, array $details, array $itemBlacklist): string
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
                foreach (['basic_summary'=>[['biography','background','basic_summary','persona'],$details['nearby_actor_summary']],
                    'personality'=>[['personality'],$details['nearby_actor_personality']],
                    'appearance'=>[['appearance'],$details['nearby_actor_appearance']],
                    'occupation'=>[['occupation','class'],$details['nearby_actor_occupation']]] as $tag=>[$keys,$include]) {
                    if(!$include)continue;
                    $value=$this->fieldText($content,$keys);if($value!=='')$entry.=$this->xmlTag($tag,$value);
                }
            }
            if (isset($actor['distance']) && is_numeric($actor['distance'])) $entry .= $this->xmlTag('distance', (string)$actor['distance']);
            $activity = $activities[$this->actorSemanticKey($actor)] ?? '';
            if ($details['nearby_actor_activity'] && $activity !== '') $entry .= $this->xmlTag('current_activity', $activity);
            $equipment = $this->contextItems($actor['equipment'] ?? []);
            if ($details['nearby_actor_equipment'] && $equipment !== []) {
                $equipmentXml = '';
                foreach ($equipment as $item) {
                    if (!is_array($item) || array_is_list($item)) continue;
                    $label = trim((string)($item['display_name'] ?? $item['record_id'] ?? ''));
                    if ($label !== '' && !$this->blocked($itemBlacklist, $label, (string)($item['record_id'] ?? ''))) $equipmentXml .= $this->xmlTag('item', $label . (isset($item['slot']) ? ' [' . $item['slot'] . ']' : ''));
                }
                if ($equipmentXml !== '') $entry .= '<equipment>' . $equipmentXml . '</equipment>';
            }
            $xml .= '<actor>' . $entry . '</actor>';
        }
        return $xml;
    }

    /** Match resolved description availability without relying on client-provided item prose. */
    private function itemHasDescription(array $item, array $descriptions): bool
    {
        foreach ($descriptions as $record) {
            if (!is_array($record) || !is_string($record['description'] ?? null) || trim($record['description']) === '') continue;
            if (trim((string)($item['content_file'] ?? '')) !== '') {
                if ($this->actorSemanticKey($item) === $this->actorSemanticKey($record)) return true;
            } elseif (mb_strtolower(trim((string)($item['record_id'] ?? '')), 'UTF-8') === mb_strtolower(trim((string)($record['record_id'] ?? '')), 'UTF-8')) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $kinds */
    private function nearbyObjectsXml(mixed $context, array $kinds, string $tag, array $itemBlacklist, bool $groupDuplicates, ?array $describedItems = null): string
    {
        if (!is_array($context) || array_is_list($context)) return '';
        $groups = [];
        foreach ($this->contextItems($context['nearbyObjects'] ?? []) as $index => $object) {
            if (!is_array($object) || array_is_list($object) || !in_array($object['kind'] ?? null, $kinds, true)) continue;
            if ($describedItems !== null && !$this->itemHasDescription($object, $describedItems)) continue;
            $name = trim((string)($object['display_name'] ?? $object['record_id'] ?? ''));
            if ($name === '' || $this->blocked($itemBlacklist, $name, (string)($object['record_id'] ?? ''))) continue;
            $key = mb_strtolower((string)($object['kind'] ?? '') . '|' . $name . ($groupDuplicates ? '' : '|' . $index), 'UTF-8');
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
    private function historyMessages(array $rows, array $turn, string $actorName, string $playerName, mixed $moodTemplates, bool $timestamp): array
    {
        if (($turn['payload']['ui_source'] ?? null) === 'lorkhan_rechat') {
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
            $message = $this->historyMessage($content, $turn, $actorName, $playerName, $moodTemplates);
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
            $messages[] = $message + ['_source_id' => $id,
                '_game_time' => is_array($content) ? ($content['game_time'] ?? null) : null,
                '_time_forward' => is_array($content) && ($content['type'] ?? '') === 'info_timeforward'];
            $messageIndexes[$messageKey] = array_key_last($messages);
        }
        // Herika's temporal categories use game hours. OpenMW supplies elapsed game seconds.
        // Attach dividers to retained messages so budgeting cannot leave an orphan heading.
        $currentTime = $turn['payload']['context']['world']['game_time'] ?? null;
        $lastCategory = null;
        if ($timestamp && is_numeric($currentTime) && $currentTime > 0) {
            foreach ($messages as &$message) {
                $gameTime = $message['_game_time'];
                if ($message['_time_forward'] || !is_numeric($gameTime) || $gameTime <= 0) continue;
                $hoursAgo = max(0, ($currentTime - $gameTime) / 3600);
                $category = match (true) {
                    $hoursAgo < 0.02 => 'Happened Recently',
                    $hoursAgo < 0.1 => 'Moments Ago',
                    $hoursAgo < 0.25 => 'A few minutes ago',
                    $hoursAgo < 0.5 => 'A while ago',
                    $hoursAgo < 1.5 => 'About an hour ago',
                    $hoursAgo < 4 => 'A couple of hours ago',
                    $hoursAgo < 12 => 'Earlier in the day',
                    $hoursAgo < 36 => 'A day ago',
                    default => 'Days ago',
                };
                if ($lastCategory !== null && $lastCategory !== $category) {
                    $message['_time_heading'] = "--- {$category} ---\n";
                }
                $lastCategory = $category;
            }
            unset($message);
        }
        // The repository already applies the profile's turn limit. Retain its newest messages
        // within the existing byte budget instead of silently applying another fixed row cap.
        $bounded=[];$bytes=0;
        foreach(array_reverse(array_values($messages))as$message){
            $line=$message['role']==='assistant'?$actorName.': '.$message['content']:$message['content'];
            $size=strlen($this->xmlTag('message',($message['_time_heading']??'').$line));
            if($bytes+$size>self::SECTIONS['history']['bytes'])break;
            $bounded[]=$message;$bytes+=$size;
        }
        return array_reverse($bounded);
    }

    /** @return array{role:string,content:string}|null */
    private function historyMessage(mixed $content, array $turn, string $actorName, string $playerName, mixed $moodTemplates): ?array
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
                $frozenCue = $content['input']['resolved_mood_cue'] ?? null;
                $text = is_string($frozenCue)
                    ? PlayerMoodPolicy::decorateWithCue($text, $frozenCue)
                    : PlayerMoodPolicy::decorate($text, $content['input']['mood'] ?? null, $moodTemplates, $speaker);
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
            'chat_background' => ($text = trim((string)($details['text'] ?? ''))) === '' ? null
                : '[Background dialogue] ' . $this->identityName($details['speaker'] ?? $content['speaker'] ?? null, 'NPC') . ': ' . $text,
            default => null,
        };
    }

    private function currentTurnMessage(array $turn, string $actorName, string $playerName, mixed $moodTemplates): string
    {
        $narratorKey = NarratorEventPrompts::SOURCES[$turn['payload']['ui_source'] ?? ''] ?? null;
        if ($narratorKey !== null) {
            $custom = $turn['_narrator_event_prompts'][$narratorKey] ?? null;
            $instruction = is_string($custom) && trim($custom) !== '' ? $custom
                : NarratorEventPrompts::definitions()[$narratorKey]['default_prompt'];
            $instruction=strtr($instruction, ['{PLAYER_NAME}' => $playerName]);
            $input=(string)($turn['payload']['input']['text']??'');
            $marker="[Narrator:quest]\n";
            if(($turn['payload']['ui_source']??'')==='lorkhan_narrator_quest'&&str_starts_with($input,$marker)){
                $observation=trim(mb_substr(substr($input,strlen($marker)),0,8192,'UTF-8'));
                if($observation!=='')$instruction.="\n[Observed journal update]\n".$observation
                    ."\nComment on this observed update. It is scene context, not player speech; do not invent additional objectives.";
            }
            return $instruction;
        }
        $automaticCue = match ($turn['payload']['ui_source'] ?? null) {
            'lorkhan_quest_event' => 'Make one brief in-character comment about this observed journal update: '.(string)($turn['payload']['input']['text']??'').'. This is scene context, not spoken player dialogue. Do not invent additional objectives.',
            'lorkhan_rpg_event' => 'Make one brief in-character comment about this observed game event: '.(string)($turn['payload']['input']['text']??'').'. This is scene context, not spoken player dialogue. Do not invent additional events.',
            'lorkhan_auto_greeting' => "Automatic greeting for {$actorName}. Address {$playerName} with one brief, natural greeting that fits your character and the current situation.",
            'lorkhan_auto_boredom' => "Automatic idle remark for {$actorName}. Make one brief, spontaneous in-character observation about the current situation. Address {$playerName} only when it feels natural.",
            'lorkhan_auto_combat_bark' => "Automatic combat bark for {$actorName}. Deliver one short, urgent in-character combat line. Do not narrate actions or produce dialogue for anyone else.",
            default => null,
        };
        if ($automaticCue !== null) return $automaticCue;
        $mode = $turn['payload']['context']['dialogueMode'] ?? null;
        $closeCue = '';
        if ($mode === 'Close') {
            $audience = $turn['payload']['audience'] ?? [];
            $audience = is_array($audience) && array_is_list($audience) ? $audience : [];
            $closeActors = [];
            foreach (array_merge(
                [$turn['payload']['speaker'] ?? null, $turn['payload']['context']['rechat']['speaker'] ?? null],
                $audience,
                [$turn['payload']['target'] ?? null],
            ) as $candidate) {
                if (!is_array($candidate) || array_is_list($candidate)) continue;
                foreach ($closeActors as $existing) if ($this->sameActor($candidate, $existing)) continue 2;
                $closeActors[] = $candidate;
            }
            $names = array_map(fn(array $identity): string => $this->truncateUtf8(
                $this->identityName($identity, 'Unknown'), 128,
            ), $closeActors);
            $closeCue = "\n\nClose mode audience: " . implode(', ', $names)
                . '. Only these actors can hear or take part. Do not involve anyone outside this audience.';
        }
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
            return $cue . "\n\n" . $listener . $closing . $closeCue;
        }
        $text = trim((string) ($turn['payload']['input']['text'] ?? ''));
        if ($text === '') throw new InvalidArgumentException('invalid_turn_input');
        $text = PlayerMoodPolicy::decorate($text, $turn['payload']['input']['mood'] ?? null, $moodTemplates, $playerName);
        $speaker = $playerName;
        return $speaker . ': ' . $text . "\n\nRespond as {$actorName}. Write {$actorName}'s next dialogue line; do not write dialogue for {$speaker}."
            . $closeCue;
    }

    private function ignoredHistoryText(string $text): bool
    {
        if ($text === '') return true;
        $normalized = mb_strtolower(ltrim($text), 'UTF-8');
        return str_starts_with($normalized, 'automated lorkhan smoke test')
            || str_starts_with($normalized, '[autonomy:')
            || str_starts_with($normalized, '[narrator:')
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
            $turn['_player_profile']['actor_identity']['display_name'] ?? null,
            $turn['_player_profile']['name'] ?? null,
            $turn['payload']['speaker']['display_name'] ?? null,
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

    private function recordDescriptionsXml(mixed $rows, array $itemBlacklist): string
    {
        if (!is_array($rows) || !array_is_list($rows)) return '';
        $safe = [];
        foreach (array_slice($rows, 0, 64) as $row) {
            if (!is_array($row) || array_is_list($row)) continue;
            $record = $this->allow($row, ['record_id', 'content_file', 'name', 'description', 'source']);
            if ($this->blocked($itemBlacklist, (string)($record['name'] ?? ''), (string)($record['record_id'] ?? ''))) continue;
            if (isset($record['record_id'], $record['description'])) $safe[] = $record;
        }
        return $this->itemsXml($safe, 'record');
    }

    /** Match normalized exact names or record IDs against a bounded management blacklist. */
    private function blocked(array $blacklist, string ...$values): bool
    {
        if ($blacklist === []) return false;
        foreach ($values as $value) if ($value !== '' && isset($blacklist[mb_strtolower(trim($value), 'UTF-8')])) return true;
        return false;
    }

    private function blacklistSet(array $values): array
    {
        $set = [];
        foreach ($values as $value) if (is_string($value) && trim($value) !== '') $set[mb_strtolower(trim($value), 'UTF-8')] = true;
        return $set;
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
                if ($kind === 'latest_diary') {
                    $included = $includedContent !== '' && str_contains($sectionBodies[$section] ?? '', $this->xmlTag('latest_diary_entry', $includedContent));
                    if (!$included) {$includedBytes=0;$includedContent='';$reason='byte_limit';}
                }
                if ($kind === 'history' && !$included && ($sectionBodies['memory_context'] ?? '') !== ''
                    && isset($memoryState['covered_history'][$id])) $reason = 'covered_by_memory';
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
                    'source_kind' => $kind === 'latest_diary' ? 'narrative' : $kind,
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
            'profile', 'core_profile', 'prompt', 'latest_diary' => 'npc_context',
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
            'narrative', 'latest_diary' => 'narrative_records',
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
            // Narrator diary author eligibility is selected by the repository; world scope stays strict.
            if ($field === 'profile_id' && $kind === 'narrative' && ($source['kind'] ?? null) === 'diary'
                && ($turn['payload']['target']['kind'] ?? null) === 'narrator') continue;
            if ($field === 'profile_id' && $kind === 'memory'
                && is_string($turn['_selected_profile_id'] ?? null)
                && $source[$field] === $turn['_selected_profile_id']) continue;
            if ($field === 'profile_id' && in_array($kind, ['profile', 'prompt', 'knowledge', 'relationship', 'latest_diary'], true)
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
            'knowledge' => ['document_id', 'id'], 'narrative', 'latest_diary' => ['narrative_id', 'id'],
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
        if ($kind === 'latest_diary') {
            $title=trim((string)($source['title']??''));
            return ($title!==''?'Date: '.$title."\n":'').(string)($source['content']??'');
        }
        if ($kind === 'profile') {
            return $this->allow($source, ['name', 'actor_identity', 'content']);
        }
        if($kind==='knowledge')return['topic'=>(string)($source['topic']??$source['title']??''),
            'access_level'=>(string)($source['access_level']??'authorized'),'article'=>(string)($source['content']??'')];
        if (array_key_exists('content', $source)) return $source['content'];
        return match ($kind) {
            'relationship' => $this->allow($source, ['actor_identity', 'disposition', 'affinity', 'relationship_type', 'details']),
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
