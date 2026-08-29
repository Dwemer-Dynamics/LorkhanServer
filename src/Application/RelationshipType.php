<?php
declare(strict_types=1);

namespace LORKHANserver\Application;

use InvalidArgumentException;

/** Canonical relationship labels shared by manual edits and bounded model output. */
final class RelationshipType
{
    /** @var list<string> */
    public const BUILT_INS = [
        'romantic', 'platonic', 'familial', 'professional', 'rival', 'enemy', 'neutral',
        'nemesis', 'estranged', 'transactional', 'protective', 'indebted', 'fanatical',
        'mentor', 'student', 'servant', 'client', 'patron', 'crush', 'ex', 'betrayed',
        'suspicious', 'admirer', 'jealous', 'fearful', 'obsessed', 'awed', 'contempt',
        'pitying', 'grateful', 'curious', 'dismissive',
    ];

    /** @var array<string,string> */
    private const ALIASES = [
        'romance' => 'romantic', 'marriage' => 'romantic', 'married' => 'romantic',
        'lover' => 'romantic', 'lovers' => 'romantic', 'betrayal' => 'betrayed',
        'enemies' => 'enemy',
    ];

    public static function manual(mixed $value): string
    {
        if (!is_string($value)) throw new InvalidArgumentException('invalid_relationship_type');
        $type = strtolower(trim($value));
        $type = self::ALIASES[$type] ?? $type;
        if (preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $type) !== 1) {
            throw new InvalidArgumentException('invalid_relationship_type');
        }
        return $type;
    }

    /** @param list<string> $available */
    public static function model(mixed $value, array $available, int $affinity, string $reason,
        string $currentType = 'neutral'): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            $type = self::manual($value);
        } catch (InvalidArgumentException) {
            return null;
        }
        if (!in_array($type, $available, true)) return null;
        $romanticTypes = ['romantic', 'crush', 'admirer', 'obsessed', 'infatuated'];
        $currentType = self::manual($currentType);
        if (in_array($type, $romanticTypes, true) && !in_array($currentType, $romanticTypes, true)
            && ($affinity < 56 || trim($reason) === '')) return null;
        return $type;
    }

    /** @param list<string> $savedCustomTypes @return list<string> */
    public static function available(array $savedCustomTypes): array
    {
        $types = self::BUILT_INS;
        foreach ($savedCustomTypes as $type) {
            try {
                $type = self::manual($type);
            } catch (InvalidArgumentException) {
                continue;
            }
            if (!in_array($type, $types, true)) $types[] = $type;
        }
        sort($types, SORT_STRING);
        return $types;
    }
}
