<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

final class DeterministicRetrieval
{
    /** @return list<string> */
    public static function terms(string $text): array
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        preg_match_all('/[\p{L}\p{N}]{2,}/u', $normalized, $matches);
        $terms = array_values(array_unique($matches[0] ?? []));
        sort($terms, SORT_STRING);
        return array_slice($terms, 0, 256);
    }

    /** @return list<float> */
    public static function fakeVector(string $text, int $dimensions = 8): array
    {
        $buckets = array_fill(0, $dimensions, 0.0);
        foreach (self::terms($text) as $term) {
            $digest = hash('sha256', $term, true);
            for ($i = 0; $i < $dimensions; ++$i) {
                $buckets[$i] += (ord($digest[$i]) - 127.5) / 127.5;
            }
        }
        $length = sqrt(array_sum(array_map(static fn(float $v): float => $v * $v, $buckets)));
        if ($length === 0.0) return $buckets;
        return array_map(static fn(float $v): float => round($v / $length, 8), $buckets);
    }

    /** @param list<string> $documentTerms @param list<float> $documentVector */
    public static function score(string $query, array $documentTerms, array $documentVector): float
    {
        $queryTerms = self::terms($query);
        $intersection = count(array_intersect($queryTerms, $documentTerms));
        $lexical = $queryTerms === [] ? 0.0 : $intersection / count($queryTerms);
        $queryVector = self::fakeVector($query, count($documentVector));
        $dot = 0.0;
        foreach ($queryVector as $index => $value) $dot += $value * ($documentVector[$index] ?? 0.0);
        return round(($lexical * 0.75) + (((max(-1.0, min(1.0, $dot)) + 1.0) / 2.0) * 0.25), 8);
    }
}
