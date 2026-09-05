<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

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
        $lexical = self::lexicalScore($query,$documentTerms);
        $queryVector = self::fakeVector($query, count($documentVector));
        $dot=0.0;foreach($queryVector as$index=>$value)$dot+=$value*($documentVector[$index]??0.0);
        return round(($lexical * 0.75) + (((max(-1.0, min(1.0, $dot)) + 1.0) / 2.0) * 0.25), 8);
    }

    /** @param list<string> $documentTerms */
    public static function lexicalScore(string $query,array $documentTerms):float
    {
        $queryTerms=self::terms($query);$intersection=count(array_intersect($queryTerms,$documentTerms));
        return$queryTerms===[]?0.0:$intersection/count($queryTerms);
    }

    /** @param list<float|int> $left @param list<float|int> $right */
    public static function cosine(array $left,array $right):?float
    {
        if(count($left)<1||count($left)!==count($right))return null;
        $dot=0.0;$leftLength=0.0;$rightLength=0.0;
        foreach($left as$index=>$leftValue){
            $rightValue=$right[$index]??null;
            if((!is_int($leftValue)&&!is_float($leftValue))||(!is_int($rightValue)&&!is_float($rightValue)))
                return null;
            $a=(float)$leftValue;$b=(float)$rightValue;
            if(!is_finite($a)||!is_finite($b))return null;
            $dot+=$a*$b;$leftLength+=$a*$a;$rightLength+=$b*$b;
        }
        if($leftLength<=0.0||$rightLength<=0.0)return null;
        return max(-1.0,min(1.0,$dot/(sqrt($leftLength)*sqrt($rightLength))));
    }

    /** Use a real semantic vector when both sides match; otherwise retain the exact v1 score. */
    public static function promptScore(string $query,array $documentTerms,array $fakeVector,
        ?array $queryEmbedding,?array $documentEmbedding):array
    {
        $lexical=self::lexicalScore($query,$documentTerms);
        $semantic=$queryEmbedding===null||$documentEmbedding===null?null:self::cosine($queryEmbedding,$documentEmbedding);
        if($semantic===null)return['score'=>self::score($query,$documentTerms,$fakeVector),
            'lexical_score'=>round($lexical,8),'semantic_score'=>null,'source'=>'deterministic-fallback'];
        $semantic=($semantic+1.0)/2.0;
        return['score'=>round($lexical*0.75+$semantic*0.25,8),'lexical_score'=>round($lexical,8),
            'semantic_score'=>round($semantic,8),'source'=>'minime'];
    }
}
