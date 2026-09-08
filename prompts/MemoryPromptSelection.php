<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/** Select memory using coverage present in the rendered prompt, never provenance alone. */
final class MemoryPromptSelection
{
    /** Select witnessed scene buckets; the caller must establish the retained digest boundary and apply prompt-budget coverage separately. */
    public static function sceneWindow(array $candidates, float $digestedThrough, ?float $historyFloor, int $limit = 10): array
    {
        if ($limit < 1 || $limit > 50 || !is_finite($digestedThrough) || $digestedThrough < 0
            || ($historyFloor !== null && (!is_finite($historyFloor) || $historyFloor < 0))) {
            throw new \InvalidArgumentException('invalid_scene_memory_window');
        }
        $scenes = [];
        $straddler = null;
        foreach (array_slice($candidates, 0, 500) as $candidate) {
            if (!is_array($candidate)) continue;
            $provenance = $candidate['provenance'] ?? [];
            if (!is_array($provenance)) continue;
            $range = $provenance['source_game_time_range'] ?? null;
            $id = $candidate['id'] ?? $candidate['memory_id'] ?? null;
            if (($candidate['tier'] ?? null) !== 'mid' || ($provenance['source'] ?? null) !== 'memory.consolidate'
                || ($provenance['source_tier'] ?? null) !== 'recent' || !is_string($id) || $id === ''
                || !is_string($candidate['content'] ?? null) || trim($candidate['content']) === ''
                || !is_array($range) || !is_numeric($range['from'] ?? null) || !is_numeric($range['to'] ?? null)) continue;
            $from = (float)$range['from']; $to = (float)$range['to'];
            if (!is_finite($from) || !is_finite($to) || $from < 0 || $from > $to || $to > 9_007_199_254_740_991) continue;
            // Include the oldest bucket reaching the live window, as in Herika; never infer text coverage from that timestamp.
            if ($historyFloor !== null && $to >= $historyFloor && ($straddler === null || $to < $straddler)) $straddler = $to;
            $scenes[] = ['id'=>$id, 'end'=>$to, 'row'=>$candidate];
        }
        $scenes = array_values(array_filter($scenes, static fn(array $scene): bool =>
            $scene['end'] > $digestedThrough && ($straddler === null || $scene['end'] <= $straddler)));
        usort($scenes, static fn(array $left, array $right): int =>
            ($right['end'] <=> $left['end']) ?: strcmp($right['id'], $left['id']));
        return array_column(array_reverse(array_slice($scenes, 0, $limit)), 'row');
    }

    /** Apply the scene window after witness filtering; retain unknown-time memories on their existing retrieval path. */
    public static function selectSceneContext(array $candidates, string $history, ?float $historyFloor, int $sourceBytes, int $sceneLimit = 10, int $budget = 16384): array
    {
        $candidates = array_slice($candidates, 0, 500);
        $scenes = self::sceneWindow($candidates, 0, $historyFloor, $sceneLimit);
        $sceneIds = array_fill_keys(array_column($scenes, 'id'), true);
        $generic = [];
        $outside = [];
        foreach ($candidates as $candidate) {
            if (self::sceneWindow([$candidate], 0, null, 1) !== []) {
                if (!isset($sceneIds[$candidate['id']])) $outside[$candidate['id']] = 'outside_scene_window';
            } else $generic[] = $candidate;
        }
        foreach ($scenes as &$scene) $scene['_group'] = 'scene';
        unset($scene);
        // Exact rendered coverage, not a digest timestamp, removes scenes already present in retained memory.
        // A digest can have holes or be truncated; advancing a blanket high-water mark would lose those scenes.
        $state = self::select([...$generic, ...$scenes], $history, $sourceBytes, $budget, $sceneLimit);
        $state['reasons'] += $outside;
        $state['counts']['candidates'] = count($candidates);
        $state['counts']['outside_scene_window'] = count($outside);
        return $state;
    }

    /** Match whole text at line boundaries; partial summaries and changed facts are not duplicates. */
    public static function covers(string $retained, string $source): bool
    {
        $source = trim($source);
        $retained = trim($retained);
        return $source !== '' && strlen($source) <= strlen($retained)
            && str_contains("\n" . $retained . "\n", "\n" . $source . "\n");
    }

    /** @param list<array{id:string,text:string}> $candidates @return array{xml:string,texts:array,reasons:array,counts:array} */
    public static function select(array $candidates, string $history, int $sourceBytes, int $budget = 16384, int $sceneLimit = 10): array
    {
        $candidates = array_slice($candidates, 0, 500);
        $selected = [];
        $reasons = [];
        $bytes = 0;
        foreach ($candidates as $candidate) {
            $id = $candidate['id'];
            $text = $candidate['text'];
            $group = ($candidate['_group'] ?? '') === 'scene' ? 'scene' : 'memory';
            if (!mb_check_encoding($text, 'UTF-8')) throw new \InvalidArgumentException('invalid_prompt_source_encoding');
            if (trim($text) === '') { $reasons[$id] = 'empty'; continue; }
            if (self::covers($history, $text)) { $reasons[$id] = 'covered_by_history'; continue; }
            foreach ($selected as $entry) {
                if (self::covers($entry['coverage'], $text)) { $reasons[$id] = 'covered_by_memory'; continue 2; }
            }
            $rendered = self::cut($text, $sourceBytes);
            $coverage = self::coverage($text, $rendered);
            $replaced = [];
            $reclaimed = 0;
            foreach ($selected as $selectedId => $entry) {
                if (self::covers($coverage, $entry['source'])) {
                    $replaced[] = $selectedId;
                    $reclaimed += strlen($entry['xml']);
                }
            }
            $groupCount = count(array_filter(array_diff_key($selected, array_fill_keys($replaced, true)),
                static fn(array $entry): bool => $entry['group'] === $group));
            if ($groupCount >= ($group === 'scene' ? $sceneLimit : 10)) { $reasons[$id] = 'section_limit'; continue; }
            // An exhausted section cannot admit even one character; skip repeated XML fit searches.
            if ($budget - $bytes + $reclaimed < 14) { $reasons[$id] = 'byte_limit'; continue; }
            $xml = self::xml($rendered);
            if ($bytes - $reclaimed + strlen($xml) > $budget) {
                // Never replace a child with a parent whose covering text will itself be cut away.
                $replaced = [];
                $reclaimed = 0;
                $available = $budget - $bytes;
                $low = 0;
                $high = min(strlen($text), $sourceBytes);
                while ($low < $high) {
                    $mid = intdiv($low + $high + 1, 2);
                    if (strlen(self::xml(self::cut($text, $mid))) <= $available) $low = $mid;
                    else $high = $mid - 1;
                }
                if ($low < 4) { $reasons[$id] = 'byte_limit'; continue; }
                $rendered = self::cut($text, $low);
                $xml = self::xml($rendered);
            }
            $groupCount = count(array_filter(array_diff_key($selected, array_fill_keys($replaced, true)),
                static fn(array $entry): bool => $entry['group'] === $group));
            if ($groupCount >= ($group === 'scene' ? $sceneLimit : 10)) { $reasons[$id] = 'section_limit'; continue; }
            foreach ($replaced as $removed) unset($selected[$removed]);
            $selected[$id] = ['group' => $group, 'source' => $text, 'text' => $rendered, 'coverage' => self::coverage($text, $rendered), 'xml' => $xml];
            $bytes += strlen($xml) - $reclaimed;
        }
        // Recheck final survivors, including children replaced by a later summary.
        $counts = ['candidates' => count($candidates), 'selected' => count($selected), 'covered_by_history' => 0, 'covered_by_memory' => 0];
        foreach ($candidates as $candidate) {
            $id = $candidate['id'];
            if (isset($selected[$id])) {
                $reasons[$id] = $selected[$id]['text'] === $candidate['text'] ? 'included' : 'byte_limit';
                continue;
            }
            if (str_starts_with($reasons[$id] ?? '', 'covered_by_')) $reasons[$id] = 'section_limit';
            if (self::covers($history, $candidate['text'])) $reasons[$id] = 'covered_by_history';
            else foreach ($selected as $entry) {
                if (self::covers($entry['coverage'], $candidate['text'])) { $reasons[$id] = 'covered_by_memory'; break; }
            }
            $reasons[$id] ??= 'section_limit';
            if (isset($counts[$reasons[$id]])) ++$counts[$reasons[$id]];
        }
        return ['xml' => implode('', array_column($selected, 'xml')),
            'texts' => array_map(static fn(array $entry): string => $entry['text'], $selected), 'reasons' => $reasons, 'counts' => $counts];
    }

    private static function cut(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) return $text;
        return $bytes < 4 ? '' : mb_strcut($text, 0, $bytes - 3, 'UTF-8') . "\u{2026}";
    }

    private static function xml(string $text): string
    {
        return '<item>' . htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</item>';
    }

    /** A truncated final line and its synthetic ellipsis cannot prove source coverage. */
    private static function coverage(string $source, string $rendered): string
    {
        if ($source === $rendered) return $rendered;
        $newline = strrpos($rendered, "\n");
        return $newline === false ? '' : substr($rendered, 0, $newline);
    }
}
