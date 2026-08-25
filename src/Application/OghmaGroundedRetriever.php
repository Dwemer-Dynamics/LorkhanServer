<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

/** Deterministically grounds conversational mentions in canonical Oghma catalog entities. */
final class OghmaGroundedRetriever
{
    public const VERSION = 'oghma-parity-v1';

    private ?array $preparedIndex = null;

    /** @param list<array<string,mixed>> $catalog */
    public function __construct(array $catalog = [])
    {
        if ($catalog !== []) $this->preparedIndex = $this->buildIndex($catalog);
    }

    /** Keep Oghma on the same player-text, speech, and playback-driven rechat request families as CHIM. */
    public static function isEligibleTurn(array $turn): bool
    {
        $payload = $turn['payload'] ?? null;
        if (!is_array($payload) || array_is_list($payload) || isset($payload['action_request'])) return false;
        $input = $payload['input'] ?? null;
        if (!is_array($input) || array_is_list($input) || !in_array($input['kind'] ?? null, ['text', 'stt'], true)) return false;
        return in_array(mb_strtolower(trim((string)($payload['ui_source'] ?? '')), 'UTF-8'), [
            'text', 'chat', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'rechat', 'continue',
            'instruction', 'suggestion', 'almsivi_text', 'almsivi_voice', 'almsivi_open_mic', 'almsivi_rechat',
        ], true);
    }

    /** Limit history carry-over to short, explicitly referential follow-up lines. */
    public static function shouldUsePreviousExchange(string $text): bool
    {
        $normalized = self::normalizeText($text);
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 240) return false;
        if (preg_match('/^(?:ok(?:ay)?|thanks?|thank you|sure|right|fine|good|got it|i see|never mind|nevermind|forget it|lets go|let us go)$/u', $normalized) === 1) return false;
        if (preg_match('/\b(?:tell me more|go on|what else|anything else|what happened next|why is that|how so)\b/u', $normalized) === 1) return true;
        $reference = preg_match('/\b(?:it|its|they|them|their|theirs|he|him|his|she|her|hers|this|that|these|those|there|former|latter)\b/u', $normalized) === 1;
        $cue = preg_match('/\b(?:who|what|where|when|why|how|which|leader|leaders|founder|founders|origin|origins|history|story|purpose|member|members|enemy|enemies|ally|allies|located|happened|mean|means|more|else|dangerous|safe|powerful|important)\b/u', $normalized) === 1;
        return $reference && $cue;
    }

    /** Resolve the shared advanced, basic, or denied Oghma access decision. */
    public static function accessDecision(array $row, array $knowledgeTags): array
    {
        $tags = array_values(array_diff(self::knowledgeValues($knowledgeTags), ['common', 'esoteric']));
        if (in_array('knowall', $tags, true) && trim((string)($row['topic_desc'] ?? '')) !== '') {
            return ['level'=>'advanced', 'reason'=>'knowall', 'matched'=>['knowall']];
        }
        $advanced = self::classDecision($row['knowledge_class'] ?? '', $tags);
        if ($advanced['allowed'] && trim((string)($row['topic_desc'] ?? '')) !== '') {
            return ['level'=>'advanced', 'reason'=>$advanced['reason'], 'matched'=>$advanced['matched']];
        }
        $basic = self::classDecision($row['knowledge_class_basic'] ?? '', $tags, true);
        if ($basic['allowed'] && trim((string)($row['topic_desc_basic'] ?? '')) !== '') {
            return ['level'=>'basic', 'reason'=>$basic['reason'], 'matched'=>$basic['matched']];
        }
        return [
            'level'=>'denied',
            'reason'=>$advanced['reason']==='negative_class'||$basic['reason']==='negative_class'
                ?'negative_class':'knowledge_classes_not_authorized',
            'matched'=>array_values(array_unique(array_merge($advanced['matched'],$basic['matched']))),
        ];
    }

    private static function classDecision(mixed $classes, array $knowledgeTags, bool $allowCommon = false): array
    {
        $values = self::knowledgeValues($classes);
        if ($values === []) return ['allowed'=>true, 'reason'=>'unrestricted', 'matched'=>[]];
        $denied = array_map(static fn(string $value): string => substr($value, 1),
            array_filter($values, static fn(string $value): bool => str_starts_with($value, '!')));
        $negative = array_values(array_intersect($denied, $knowledgeTags));
        if ($negative !== []) return ['allowed'=>false, 'reason'=>'negative_class', 'matched'=>$negative];
        $allowed = array_values(array_filter($values, static fn(string $value): bool => !str_starts_with($value, '!')));
        if ($allowCommon && in_array('common', $allowed, true)) {
            return ['allowed'=>true, 'reason'=>'common', 'matched'=>['common']];
        }
        $positive = array_values(array_intersect($allowed, $knowledgeTags));
        return ['allowed'=>$positive !== [], 'reason'=>$positive !== []?'positive_class':'missing_class', 'matched'=>$positive];
    }

    private static function knowledgeValues(mixed $values): array
    {
        $items = is_array($values) ? $values : preg_split('/\s*[,|;]\s*/u', (string)$values);
        $result = [];
        foreach ($items ?: [] as $item) {
            $item = mb_strtolower(trim((string)$item), 'UTF-8');
            if ($item === '') continue;
            $negative = str_starts_with($item, '!');
            $raw = $negative ? substr($item, 1) : $item;
            $aliases = [
                'smith'=>['blacksmith'], 'darkelf'=>['dunmer'], 'dark_elf'=>['dunmer'],
                'highelf'=>['altmer'], 'high_elf'=>['altmer'], 'woodelf'=>['bosmer'],
                'wood_elf'=>['bosmer'], 'thievesguild'=>['thieves_guild'],
                'legion'=>['imperial_legion'], 'darkbrotherhood'=>['dark_brotherhood'],
                'eastempirecompany'=>['east_empire_company'], 'moragtong'=>['morag_tong'],
                'househlaalu'=>['house_hlaalu'], 'houseredoran'=>['house_redoran'],
                'housetelvanni'=>['house_telvanni'], 'collegeofwinterhold'=>['college_of_winterhold'],
                'collegeofwinterold'=>['college_of_winterhold'], 'magesguild'=>['mages_guild'],
                'fightersguild'=>['fighters_guild'], 'temple'=>['tribunal_temple'],
                'telvanni'=>['house_telvanni'], 'redoran'=>['house_redoran'],
                'hlaalu'=>['house_hlaalu'], 'sixth_house'=>['house_dagoth'],
                'hands_of_almalexia'=>['tribunal_temple'], 'miraakcult'=>['miraak_cult'],
                'psijic'=>['psijic_order'], 'stormcloak'=>['stormcloaks'],
                'snowelf'=>['snow_elf'], 'skall'=>['skaal'], 'daedric'=>['daedra'],
                'skyrimall'=>['common'], 'legion. skyrimall'=>['imperial_legion', 'common'],
                'stands-in-shallows'=>['stands_in_shallows'], 'talen-jei'=>['talen_jei'],
            ];
            $key = array_key_exists($raw, $aliases)
                ? $raw
                : trim((string)preg_replace('/[^a-z0-9]+/u', '_', $raw), '_');
            foreach ($aliases[$key] ?? [$key] as $canonical) {
                $canonical = $negative ? '!' . $canonical : $canonical;
                if ($canonical !== '' && !in_array($canonical, $result, true)) $result[] = $canonical;
            }
        }
        return $result;
    }

    /** Resolve provider suggestions only when each names one catalog entity unambiguously. */
    public function resolveSuggestions(array $suggestions, array $catalog, int $limit = 1): array
    {
        $index = $this->indexFor($catalog);
        $resolved = [];
        foreach ($suggestions as $suggestion) {
            if (!is_string($suggestion) || trim($suggestion) === '') continue;
            $phrase = $this->normalize($suggestion);
            $owners = array_values(array_unique($index['phrase_owners'][$phrase] ?? []));
            if (count($owners) === 1) {
                $topic = $owners[0];
            } elseif ($owners !== []) {
                continue;
            } else {
                $entries = $index['by_compact'][$this->compact($suggestion)] ?? [];
                $topics = array_values(array_unique(array_column($entries, 'topic')));
                if (count($topics) !== 1) continue;
                $topic = $topics[0];
            }
            if (!in_array($topic, $resolved, true)) $resolved[] = $topic;
            if (count($resolved) >= max(1, min(3, $limit))) break;
        }
        return $resolved;
    }

    /**
     * @param list<array<string,mixed>> $catalog
     * @return array{topics:list<string>,matches:list<array<string,mixed>>,rejected:list<array<string,mixed>>,tag_decisions:list<array<string,mixed>>,fallback_eligible:bool}
     */
    public function extract(string $text, array $catalog, int $limit = 1): array
    {
        $limit = max(1, min(3, $limit));
        $text = trim($text);
        if ($text === '' || ($catalog === [] && $this->preparedIndex === null)) {
            return ['topics'=>[], 'matches'=>[], 'rejected'=>[], 'tag_decisions'=>[],
                'fallback_eligible'=>$this->isExplicitKnowledgeRequest($text)];
        }

        $index = $this->indexFor($catalog);
        $normalized = $this->normalize($text);
        $speakerLabel = $this->speakerLabel($text, $index);
        $speakerLabelEnd = $speakerLabel === '' ? 0 : mb_strlen($speakerLabel, 'UTF-8');
        $requestScore = $this->isExplicitKnowledgeRequest($text) ? 1.0 : 0.0;
        $windows = $this->tokenWindows($text);
        $candidates = [];
        $rejected = [];

        preg_match_all('/[\p{L}\p{N}]+/u', $normalized, $tokenMatches, PREG_OFFSET_CAPTURE);
        $tokens = $tokenMatches[0] ?? [];
        foreach ($tokens as $tokenIndex => $token) {
            $node = $index['exact_trie'] ?? [];
            for ($endIndex = $tokenIndex, $tokenCount = count($tokens); $endIndex < $tokenCount; $endIndex++) {
                $word = (string)$tokens[$endIndex][0];
                if (!isset($node[$word])) break;
                $node = $node[$word];
                foreach ($node[''] ?? [] as $entry) {
                    $start = $this->characterOffset($normalized, (int)$token[1]);
                    $endByte = (int)$tokens[$endIndex][1] + strlen((string)$tokens[$endIndex][0]);
                    $end = $this->characterOffset($normalized, $endByte);
                    if ($speakerLabelEnd > 0 && $start < $speakerLabelEnd) {
                        $rejected[] = $this->rejection($entry, 'speaker_label', $start);
                        continue;
                    }
                    $candidate = $this->candidate(
                        $entry,
                        $entry['canonical'] ? 'exact canonical' : 'exact alias',
                        $start,
                        $end, 0, $entry['canonical'] ? 0.90 : 0.86,
                        mb_substr($normalized, $start, $end - $start, 'UTF-8')
                    );
                    $candidate['score'] = $this->candidateScore($text, $candidate, $requestScore);
                    $candidates[] = $candidate;
                }
            }
        }

        foreach ($windows as $window) {
            $owners = $index['by_compact'][$window['compact']] ?? [];
            $topics = array_values(array_unique(array_column($owners, 'topic')));
            if (count($topics) !== 1 || $owners === []) {
                if (count($topics) > 1) {
                    $rejected[] = ['phrase'=>$window['phrase'], 'reason'=>'ambiguous_compact', 'topics'=>$topics, 'start'=>$window['start']];
                }
                continue;
            }
            usort($owners, static fn(array $a, array $b): int => ((int)$b['canonical']) <=> ((int)$a['canonical']));
            $entry = $owners[0];
            if ($speakerLabelEnd > 0 && $window['start'] < $speakerLabelEnd) {
                continue;
            }
            $candidate = $this->candidate($entry, $entry['canonical'] ? 'compact canonical' : 'compact alias',
                $window['start'], $window['end'], 0, $entry['canonical'] ? 0.88 : 0.84, $window['phrase']);
            $candidate['score'] = $this->candidateScore($text, $candidate, $requestScore);
            $candidates[] = $candidate;
        }

        $candidates = $this->collapseOverlaps($candidates);
        foreach ($this->fuzzyCandidates($text, $windows, $index, $requestScore) as $candidate) {
            if ($speakerLabelEnd > 0 && $candidate['start'] < $speakerLabelEnd) {
                $rejected[] = $this->rejection($candidate, 'speaker_label', $candidate['start']);
                continue;
            }
            if (!$this->hasTranscriptCue($text) && $requestScore < 0.5
                && $this->cueStrength($text, $candidate, $requestScore) < 0.8) {
                $rejected[] = $this->rejection($candidate, 'unguarded_fuzzy_match', $candidate['start']);
                continue;
            }
            $overlap = $this->overlapIndex($candidates, $candidate);
            if ($overlap !== null) {
                $existing = $candidates[$overlap];
                $candidateSpan = $candidate['end'] - $candidate['start'];
                $existingSpan = $existing['end'] - $existing['start'];
                if ($candidateSpan > $existingSpan && $candidate['literal_distance'] <= 3
                    && (($this->hasTranscriptCue($text) && $candidateSpan >= $existingSpan + 2
                            && $candidate['score'] >= $existing['score'] - 0.02)
                        || (strlen($candidate['compact_entity']) >= strlen($existing['compact_entity']) + 3
                            && ($candidate['score'] >= $existing['score'] - 0.08
                                || str_contains($candidate['compact_entity'], $existing['compact_entity']))))) {
                    $candidates[$overlap] = $candidate;
                }
                continue;
            }
            $candidates[] = $candidate;
        }

        $byTopic = [];
        foreach ($candidates as $candidate) {
            $topicKey = $this->normalize($candidate['topic']);
            $candidate['mention_count'] = $this->mentionCount($normalized, $candidate);
            $candidate['context_score'] = $candidate['score'] + min(0.60, max(0, $candidate['mention_count'] - 1) * 0.32)
                - $this->backgroundPenalty($normalized, $candidate);
            if ($this->wrongSense($normalized, $candidate)) {
                $rejected[] = $this->rejection($candidate, 'wrong_sense', $candidate['start']);
                continue;
            }
            if ($this->isRiskySingleWord($candidate) && $candidate['mention_count'] <= 1 && $requestScore < 0.5
                && $this->cueStrength($text, $candidate, $requestScore) < 0.8) {
                $candidate['context_score'] -= 0.52;
            }
            if ($candidate['context_score'] < 0.72) {
                $rejected[] = $this->rejection($candidate, 'below_threshold', $candidate['start'], $candidate['context_score']);
                continue;
            }
            if (!isset($byTopic[$topicKey]) || $candidate['context_score'] > $byTopic[$topicKey]['context_score']) {
                $byTopic[$topicKey] = $candidate;
            }
        }

        $selected = $this->applyRelationalTagSupport($text, $index, array_values($byTopic));
        usort($selected, static function (array $a, array $b): int {
            $mentions = $b['mention_count'] <=> $a['mention_count'];
            if ($mentions !== 0) return $mentions;
            $scoreDifference = $b['context_score'] - $a['context_score'];
            if (abs($scoreDifference) >= 0.15) return $scoreDifference < 0 ? -1 : 1;
            return $a['start'] <=> $b['start'];
        });
        $selected = array_slice($selected, 0, $limit);

        return [
            'topics'=>array_values(array_column($selected, 'topic')),
            'matches'=>$selected,
            'rejected'=>$rejected,
            'tag_decisions'=>[],
            'fallback_eligible'=>$selected === [] && $this->isExplicitKnowledgeRequest($text),
        ];
    }

    /** @param list<array<string,mixed>> $catalog */
    private function buildIndex(array $catalog): array
    {
        $phrases = [];
        $tagPhrases = [];
        foreach ($catalog as $row) {
            $topic = trim((string)($row['topic'] ?? $row['title'] ?? ''));
            if ($topic === '') continue;
            $category = mb_strtolower(trim((string)($row['category'] ?? '')), 'UTF-8');
            $values = [[$topic, true]];
            foreach ($this->values((string)($row['aliases'] ?? '')) as $alias) $values[] = [$alias, false];
            foreach ($values as [$value, $canonical]) {
                $phrase = $this->normalize((string)$value);
                if ($phrase === '') continue;
                $phrases[$phrase]['owners'][$topic] = true;
                if ($canonical) $phrases[$phrase]['canonical_owners'][$topic] = true;
                $phrases[$phrase]['category'][$topic] = $category;
            }
            foreach ($this->values((string)($row['tags'] ?? '')) as $tag) {
                $phrase = $this->normalize($tag);
                if ($phrase !== '') $tagPhrases[$phrase]['owners'][$topic] = true;
            }
        }

        $entries = [];
        $byCompact = [];
        $exactTrie = [];
        $fuzzyBuckets = [];
        $phraseOwners = [];
        foreach ($phrases as $phrase => $owners) {
            $phrase = (string)$phrase;
            $ownerTopics = array_keys($owners['owners'] ?? []);
            $phraseOwners[$phrase] = $ownerTopics;
            $canonicalOwners = array_keys($owners['canonical_owners'] ?? []);
            if (count($canonicalOwners) === 1) $topic = $canonicalOwners[0];
            elseif (count($ownerTopics) === 1) $topic = $ownerTopics[0];
            else continue;
            preg_match_all('/\b\d+\b/u', $phrase, $numberMatches);
            $entry = [
                'topic'=>$topic,
                'phrase'=>$phrase,
                'compact'=>$this->compact($phrase),
                'numbers'=>array_values(array_unique($numberMatches[0] ?? [])),
                'canonical'=>in_array($topic, $canonicalOwners, true),
                'category'=>(string)($owners['category'][$topic] ?? ''),
            ];
            $entries[] = $entry;
            $byCompact[$entry['compact']][] = $entry;
            $node =& $exactTrie;
            foreach (preg_split('/\s+/u', $entry['phrase']) ?: [] as $word) {
                if (!isset($node[$word])) $node[$word] = [];
                $node =& $node[$word];
            }
            $node[''][] = $entry;
            unset($node);
            $entry['phonetic'] = $this->phonetic($entry['compact']);
            $fuzzyBuckets[strlen($entry['compact'])][] = $entry;
        }
        $relationalTagEntries = [];
        foreach ($tagPhrases as $phrase => $owners) {
            if (count(preg_split('/\s+/u', (string)$phrase) ?: []) < 2 || isset($phrases[$phrase])) continue;
            $relationalTagEntries[$phrase] = [
                'phrase'=>(string)$phrase,
                'owners'=>array_keys($owners['owners'] ?? []),
            ];
        }
        return ['entries'=>$entries, 'by_compact'=>$byCompact, 'exact_trie'=>$exactTrie,
            'fuzzy_buckets'=>$fuzzyBuckets,
            'phrase_owners'=>$phraseOwners,
            'relational_tag_entries'=>$relationalTagEntries];
    }

    /** Let descriptive tags strengthen an identified topic without selecting one. */
    private function applyRelationalTagSupport(string $text, array $index, array $entities): array
    {
        if ($entities === [] || ($index['relational_tag_entries'] ?? []) === []) return $entities;
        $normalized = ' ' . $this->normalize($text) . ' ';
        foreach ($entities as &$entity) {
            $topic = (string)($entity['topic'] ?? '');
            $matched = [];
            foreach ($index['relational_tag_entries'] as $phrase => $entry) {
                if (str_contains($normalized, ' ' . $phrase . ' ')
                    && in_array($topic, $entry['owners'] ?? [], true)) $matched[] = $phrase;
            }
            if ($matched !== []) {
                $entity['relational_tag_phrases'] = array_values(array_unique($matched));
                $bonus = min(0.08, count($entity['relational_tag_phrases']) * 0.04);
                $entity['score'] = (float)($entity['score'] ?? 0.0) + $bonus;
                $entity['context_score'] = (float)($entity['context_score'] ?? 0.0) + $bonus;
            }
        }
        unset($entity);
        return $entities;
    }

    private function indexFor(array $catalog): array
    {
        if ($catalog !== []) return $this->buildIndex($catalog);
        if ($this->preparedIndex === null) throw new \InvalidArgumentException('oghma_catalog_required');
        return $this->preparedIndex;
    }

    /** Produce bounded word windows used for exact compact and guarded fuzzy matching. */
    private function tokenWindows(string $text, int $maximumTokens = 7): array
    {
        $normalized = $this->normalize($text);
        preg_match_all('/[\p{L}\p{N}]+/u', $normalized, $matches, PREG_OFFSET_CAPTURE);
        $tokens = $matches[0] ?? [];
        $windows = [];
        for ($start = 0, $count = count($tokens); $start < $count; $start++) {
            for ($length = 1; $length <= $maximumTokens && $start + $length <= $count; $length++) {
                $parts = [];
                for ($offset = 0; $offset < $length; $offset++) $parts[] = (string)$tokens[$start + $offset][0];
                $phrase = implode(' ', $parts);
                $startByte = (int)$tokens[$start][1];
                $last = $tokens[$start + $length - 1];
                $endByte = (int)$last[1] + strlen((string)$last[0]);
                $windows[] = [
                    'phrase'=>$phrase,
                    'compact'=>$this->compact($phrase),
                    'start'=>$this->characterOffset($normalized, $startByte),
                    'end'=>$this->characterOffset($normalized, $endByte),
                    'token_count'=>$length,
                ];
            }
        }
        return $windows;
    }

    /** @return list<array<string,mixed>> */
    private function fuzzyCandidates(string $text, array $windows, array $index, float $requestScore): array
    {
        $buckets = $index['fuzzy_buckets'] ?? [];
        $candidates = [];
        foreach ($windows as $window) {
            $windowCompact = $window['compact'];
            $windowLength = strlen($windowCompact);
            if ($windowLength < 5) continue;
            $windowPhonetic = $this->phonetic($windowCompact);
            preg_match_all('/\b\d+\b/u', $this->normalize($window['phrase']), $windowNumberMatches);
            $windowNumbers = array_values(array_unique($windowNumberMatches[0] ?? []));
            $local = [];
            for ($length = max(5, $windowLength - 3); $length <= $windowLength + 3; $length++) {
                foreach ($buckets[$length] ?? [] as $entry) {
                    $entryNumbers = $entry['numbers'] ?? [];
                    $literal = $this->distance($windowCompact, $entry['compact']);
                    $phonetic = $this->distance($windowPhonetic, $entry['phonetic']);
                    $distance = min($literal, $phonetic);
                    $maximum = max($windowLength, strlen($entry['compact'])) <= 8 ? 2 : 3;
                    if ($literal === 0 || $distance > $maximum) continue;
                    $similarity = max(
                        1.0 - ($literal / max($windowLength, strlen($entry['compact']), 1)),
                        1.0 - ($phonetic / max(strlen($windowPhonetic), strlen($entry['phonetic']), 1)) - 0.02
                    );
                    if ($similarity < 0.78) continue;
                    $candidate = $this->candidate($entry, 'guarded phonetic entity', $window['start'], $window['end'],
                        $distance, $similarity, $window['phrase']);
                    $candidate['literal_distance'] = $literal;
                    $candidate['score'] = $this->candidateScore($text, $candidate, $requestScore);
                    if ($windowNumbers !== [] && $entryNumbers !== []) {
                        $candidate['score'] += array_intersect($windowNumbers, $entryNumbers) === [] ? -0.02 : 0.04;
                    }
                    if ($this->cueStrength($text, $candidate, $requestScore) < 0.5 && $similarity < 0.84) continue;
                    $local[] = $candidate;
                }
            }
            usort($local, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            if ($local === []) continue;
            $best = $local[0];
            $runnerUp = 0.0;
            foreach (array_slice($local, 1) as $other) {
                if ($other['topic'] !== $best['topic']) {
                    $runnerUp = $other['score'];
                    break;
                }
            }
            if ($best['score'] - $runnerUp < 0.04) continue;
            $key = $this->normalize($best['topic']);
            if (!isset($candidates[$key]) || $best['score'] > $candidates[$key]['score']) $candidates[$key] = $best;
        }
        return array_values($candidates);
    }

    private function candidate(array $entry, string $source, int $start, int $end, int $distance,
        float $entityScore, ?string $observed = null): array
    {
        return [
            'topic'=>$entry['topic'], 'phrase'=>$observed ?? $entry['phrase'], 'entity_phrase'=>$entry['phrase'],
            'compact_entity'=>$entry['compact'], 'category'=>$entry['category'], 'source'=>$source,
            'start'=>$start, 'end'=>$end, 'distance'=>$distance, 'entity_score'=>$entityScore,
        ];
    }

    private function candidateScore(string $text, array $candidate, float $requestScore): float
    {
        $score = (float)$candidate['entity_score'];
        if (str_contains($candidate['entity_phrase'], ' ')) $score += 0.08;
        $score += $requestScore * 0.15;
        if (str_contains($candidate['source'], 'exact canonical')) $score += 0.12;
        elseif (str_contains($candidate['source'], 'compact canonical')) $score += 0.09;
        elseif (str_contains($candidate['source'], 'exact alias')) $score += 0.06;
        elseif (str_contains($candidate['source'], 'compact alias')) $score += 0.03;
        $cue = $this->cueStrength($text, $candidate, $requestScore);
        if ($cue >= 0.8) $score += 0.12;
        elseif ($cue >= 0.5) $score += 0.05;
        if ($candidate['start'] <= 5) $score += 0.06;
        return round($score, 8);
    }

    private function cueStrength(string $text, array $candidate, float $requestScore): float
    {
        if ($requestScore >= 0.5) return 1.0;
        $normalized = $this->normalize($text);
        $prefix = trim(mb_substr($normalized, 0, max(0, $candidate['start']), 'UTF-8'));
        $suffix = trim(mb_substr($normalized, max(0, $candidate['end']), null, 'UTF-8'));
        if (preg_match('/\b(?:about|of|near|toward|towards|at|into|from|through|visited|saw|passed|reached|entered|left|named|called|mentioned|discussed|heard|read)\s*$/u', $prefix)) return 1.0;
        if (preg_match('/\b(?:speech|voice)\s+(?:recognition|transcript)|\b(?:transcribed|sounded|heard)\s+(?:as|like)\b/u', $normalized)) return 0.8;
        if ($candidate['start'] <= 1 && preg_match('/^(?:appeared|stood|waited|looked|was|were)\b/u', $suffix)) return 0.8;
        return 0.0;
    }

    private function collapseOverlaps(array $candidates): array
    {
        usort($candidates, static fn(array $a, array $b): int => ($a['start'] <=> $b['start'])
            ?: (($b['end'] - $b['start']) <=> ($a['end'] - $a['start'])) ?: ($b['score'] <=> $a['score']));
        $result = [];
        foreach ($candidates as $candidate) {
            $overlap = $this->overlapIndex($result, $candidate);
            if ($overlap === null) {
                $result[] = $candidate;
                continue;
            }
            $existing = $result[$overlap];
            if (($candidate['end'] - $candidate['start']) >= ($existing['end'] - $existing['start'])
                && $candidate['score'] > $existing['score'] + 0.04) $result[$overlap] = $candidate;
        }
        return $result;
    }

    private function overlapIndex(array $candidates, array $candidate): ?int
    {
        foreach ($candidates as $index => $existing) {
            if ($candidate['start'] < $existing['end'] && $existing['start'] < $candidate['end']) return $index;
        }
        return null;
    }

    private function speakerLabel(string $text, array $index): string
    {
        if (!preg_match('/^\s*([^:\r\n]{1,80}):\s+/u', $text, $match)) return '';
        if (preg_match('/[.!?;]/u', (string)$match[1])) return '';
        $label = $this->normalize((string)$match[1]);
        if ($label === '' || count(preg_split('/\s+/u', $label) ?: []) > 12) return '';
        if (isset($index['phrase_owners'][$label])) return $label;
        if (preg_match('/\b(?:era|volume|book|chapter|part|act)\b/u', $label)) return '';
        return '';
    }

    private function isExplicitKnowledgeRequest(string $text): bool
    {
        $normalized = $this->normalize($text);
        if (preg_match('/\b(?:do not|dont|never mind|forget)\b.{0,30}\b(?:explain|describe|discuss|tell|teach)\b/u', $normalized)) return false;
        return preg_match('/\b(?:tell|teach|explain|describe|discuss)\s+(?:me\s+|us\s+)?(?:about|of)|\bcompare\b|\bwhat\s+(?:do\s+you\s+know|have\s+you\s+heard)\s+about|\b(?:do|did)\s+you\s+know\s+(?:anything\s+)?about|\b(?:history|lore|background|story|details|information)\s+(?:about|on|of)\b/u', $normalized) === 1;
    }

    private function mentionCount(string $normalized, array $candidate): int
    {
        $best = 0;
        foreach (array_unique([$candidate['entity_phrase'], $candidate['phrase']]) as $phrase) {
            if ($phrase === '') continue;
            $count = preg_match_all('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/u', $normalized);
            $best = max($best, (int)$count);
        }
        return $best;
    }

    private function backgroundPenalty(string $normalized, array $candidate): float
    {
        $phrase = preg_quote($candidate['entity_phrase'], '/');
        if (preg_match('/\b(?:forget|ignore|leave)\s+' . $phrase . '\s+(?:for\s+now|aside|behind)\b/u', $normalized)) return 0.45;
        if (preg_match('/\b(?:only|merely|just)\s+(?:passed|crossed|saw|mentioned)\s+(?:the\s+)?' . $phrase . '\b/u', $normalized)) return 0.35;
        if (preg_match('/\b(?:noticed|mentioned|saw)\s+(?:the\s+)?' . $phrase . '\s+but\b/u', $normalized)) return 0.45;
        return 0.0;
    }

    private function wrongSense(string $normalized, array $candidate): bool
    {
        $phrase = $candidate['entity_phrase'];
        $prefix = trim(mb_substr($normalized, 0, max(0, $candidate['start']), 'UTF-8'));
        $suffix = trim(mb_substr($normalized, max(0, $candidate['end']), null, 'UTF-8'));
        $patterns = [
            'ash'=>'/^(?:from|in)\s+(?:the\s+)?(?:fire|hearth|urn)\b/u',
            'blight'=>'/^(?:on|upon)\s+(?:the\s+)?(?:crops|plants|field)\b/u',
            'pale'=>'/^(?:light|skin|face|color|colour|blue|white)\b/u',
            'the pale'=>'/^(?:light|skin|face|color|colour|blue|white)\b/u',
            'tribunal'=>'/^(?:hearing|court|judges|case)\b/u',
        ];
        if (isset($patterns[$phrase]) && preg_match($patterns[$phrase], $suffix) === 1) return true;
        return $phrase === 'prophecy' && preg_match('/\b(?:prediction|weather|guess)\b/u', $prefix) === 1;
    }

    private function hasTranscriptCue(string $text): bool
    {
        $normalized = $this->normalize($text);
        return preg_match('/\b(?:speech|voice)\s+(?:recognition|transcript)|\b(?:transcript|transcription|transcribed|recording|audio|static)\b.{0,36}\b(?:returned|produced|rendered|heard|sounded|captured|as|like)\b/u', $normalized) === 1;
    }

    private function isRiskySingleWord(array $candidate): bool
    {
        if (str_contains($candidate['entity_phrase'], ' ')) return false;
        return in_array($candidate['entity_phrase'], [
            'ash','blight','blood','bone','code','dream','ghost','glass','heart','house','imperial','magic','moon',
            'nerevarine','poison','prophecy','saint','sixth','storm','temple','tribunal','vampire','warrior',
        ], true);
    }

    private function rejection(array $candidate, string $reason, int $start, ?float $score = null): array
    {
        return array_filter([
            'topic'=>$candidate['topic'] ?? null, 'phrase'=>$candidate['phrase'] ?? $candidate['entity_phrase'] ?? null,
            'reason'=>$reason, 'start'=>$start, 'score'=>$score,
        ], static fn(mixed $value): bool => $value !== null);
    }

    private static function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim(str_replace('_', ' ', $value)), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function normalize(string $value): string
    {
        return self::normalizeText($value);
    }

    private function compact(string $value): string
    {
        $normalized = $this->normalize($value);
        static $numbers = [
            'one'=>'1','i'=>'1','two'=>'2','ii'=>'2','three'=>'3','iii'=>'3','four'=>'4','iv'=>'4',
            'five'=>'5','v'=>'5','six'=>'6','vi'=>'6','seven'=>'7','vii'=>'7','eight'=>'8','viii'=>'8',
            'nine'=>'9','ix'=>'9','ten'=>'10','x'=>'10','eleven'=>'11','xi'=>'11','twelve'=>'12','xii'=>'12',
            'thirteen'=>'13','fourteen'=>'14','fifteen'=>'15','sixteen'=>'16','seventeen'=>'17','eighteen'=>'18',
            'nineteen'=>'19','twenty'=>'20','twenty one'=>'21','twenty two'=>'22','twenty three'=>'23',
            'twenty four'=>'24','twenty five'=>'25','twenty six'=>'26','twenty seven'=>'27','twenty eight'=>'28',
            'twenty nine'=>'29','thirty'=>'30','thirty one'=>'31','thirty two'=>'32','thirty three'=>'33',
            'thirty four'=>'34','thirty five'=>'35','thirty six'=>'36',
        ];
        static $numberPattern = null;
        static $compactNumbers = null;
        static $compactNumberPattern = null;
        if ($numberPattern === null) {
            $keys = array_keys($numbers);
            usort($keys, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
            $numberPattern = implode('|', array_map(static fn(string $key): string => preg_quote($key, '/'), $keys));
            $compactNumbers = [];
            foreach ($numbers as $key => $numberValue) $compactNumbers[str_replace(' ', '', $key)] = $numberValue;
            $compactKeys = array_keys($compactNumbers);
            usort($compactKeys, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
            $compactNumberPattern = implode('|', array_map(static fn(string $key): string => preg_quote($key, '/'), $compactKeys));
        }
        $cue = 'book|volume|chapter|part|sermon|v';
        $normalized = preg_replace_callback(
            '/\b(' . $cue . ')\s+(' . $numberPattern . ')\b/u',
            static fn(array $match): string => $match[1] . ' ' . ($numbers[$match[2]] ?? $match[2]),
            $normalized
        ) ?? $normalized;
        $normalized = preg_replace_callback(
            '/\b(' . $numberPattern . ')\b$/u',
            static fn(array $match): string => $numbers[$match[1]] ?? $match[1],
            $normalized
        ) ?? $normalized;
        $normalized = preg_replace_callback(
            '/(' . $cue . ')(' . $compactNumberPattern . ')$/u',
            static fn(array $match): string => $match[1] . ($compactNumbers[$match[2]] ?? $match[2]),
            $normalized
        ) ?? $normalized;
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($ascii === false ? $normalized : $ascii, 'UTF-8')) ?? '';
    }

    private function phonetic(string $value): string
    {
        $value = strtr($value, ['ph'=>'f','ck'=>'k','qu'=>'kw','th'=>'t','ee'=>'i','ea'=>'i','y'=>'i']);
        return preg_replace('/(.)\1+/', '$1', $value) ?? $value;
    }

    private function distance(string $left, string $right): int
    {
        $distance = levenshtein($left, $right);
        if ($distance !== 2 || strlen($left) !== strlen($right)) return $distance;
        $mismatches = [];
        for ($index = 0; $index < strlen($left); $index++) {
            if ($left[$index] !== $right[$index]) $mismatches[] = $index;
            if (count($mismatches) > 2) return $distance;
        }
        return count($mismatches) === 2 && $mismatches[1] === $mismatches[0] + 1
            && $left[$mismatches[0]] === $right[$mismatches[1]] && $left[$mismatches[1]] === $right[$mismatches[0]] ? 1 : $distance;
    }

    private function characterOffset(string $value, int $byteOffset): int
    {
        return mb_strlen(substr($value, 0, max(0, $byteOffset)), 'UTF-8');
    }

    /** @return list<string> */
    private function values(string $value): array
    {
        $result = [];
        foreach (preg_split('/\s*,\s*/u', $value) ?: [] as $item) {
            $item = trim($item);
            if ($item !== '' && !in_array($item, $result, true)) $result[] = $item;
        }
        return $result;
    }
}
