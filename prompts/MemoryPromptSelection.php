<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/** Select memory using coverage present in the rendered prompt, never provenance alone. */
final class MemoryPromptSelection
{
    /** Match whole text at line boundaries; partial summaries and changed facts are not duplicates. */
    public static function covers(string $retained, string $source): bool
    {
        $source = trim($source);
        $retained = trim($retained);
        return $source !== '' && strlen($source) <= strlen($retained)
            && str_contains("\n" . $retained . "\n", "\n" . $source . "\n");
    }

    /** @param list<array{id:string,text:string}> $candidates @return array{xml:string,texts:array,reasons:array,counts:array} */
    public static function select(array $candidates, string $history, int $sourceBytes, int $budget = 16384): array
    {
        $candidates = array_slice($candidates, 0, 500);
        $selected = [];
        $reasons = [];
        $bytes = 0;
        foreach ($candidates as $candidate) {
            $id = $candidate['id'];
            $text = $candidate['text'];
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
            if (count($selected) - count($replaced) >= 10) { $reasons[$id] = 'section_limit'; continue; }
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
            if (count($selected) - count($replaced) >= 10) { $reasons[$id] = 'section_limit'; continue; }
            foreach ($replaced as $removed) unset($selected[$removed]);
            $selected[$id] = ['source' => $text, 'text' => $rendered, 'coverage' => self::coverage($text, $rendered), 'xml' => $xml];
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
