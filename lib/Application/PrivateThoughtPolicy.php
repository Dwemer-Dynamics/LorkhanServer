<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/**
 * Profile-controlled private NPC thoughts. A thought is generated only for an exact placed TES3 actor
 * that owns its NPC profile. It is never spoken, sent to the game client, acted on or written to generic
 * logs; only that same actor's prompts and that profile's owner history read it back, within the dialogue
 * event's installation and playthrough.
 */
final class PrivateThoughtPolicy
{
    public const MAX_CHARACTERS = 600;
    public const RESPONSE_FIELD = 'internal_thought';
    /** Internal provider-result key; stripped before canonical response and action validation. */
    public const RESULT_KEY = '_private_thought';
    private const SCHEMA = 'lorkhan.private-thought.v1';
    private const HISTORY_NOTE_PREFIX = '[Your unspoken private thought after this line;';
    private const WITHHELD = '[private thought withheld]';

    public const INSTRUCTIONS = 'Write "internal_thought" last, after "utterances" and "action": one or two brief sentences in your own character voice, at most 600 characters. Reflect on an observation, motive or decision grounded in your available knowledge. Keep uncertainty explicit; do not invent facts or speak to another person. Use an empty string when no useful reflection arises. Other characters never hear or see the thought and it never becomes speech or an action; never put private thoughts or thought tags in utterance text.';

    /** Freeze the owner of an eligible ordinary NPC dialogue turn; null leaves the turn unchanged. */
    public static function owner(array $turn, ?string $profileId, bool $enabled): ?array
    {
        if (!$enabled || $profileId === null || $profileId === '') return null;
        $payload = is_array($turn['payload'] ?? null) ? $turn['payload'] : [];
        if (($payload['execution_mode'] ?? 'standard') !== 'standard' || isset($payload['director_instruction_id'])
            || isset($turn['_director_response']) || ($payload['target']['kind'] ?? null) !== 'npc') return null;
        $actor = self::stableIdentity($payload['target'] ?? null);
        return $actor === null ? null : ['profile_id' => $profileId, 'actor' => $actor];
    }

    /** Whether this frozen turn may ask a structured provider for a private thought. */
    public static function requested(array $turn): bool
    {
        $owner = $turn['_private_thought'] ?? null;
        return is_array($owner) && is_string($owner['profile_id'] ?? null) && $owner['profile_id'] !== ''
            && self::stableIdentity($owner['actor'] ?? null) === ($owner['actor'] ?? null);
    }

    /** Add the response field to the actual provider request without changing the audited prompt. */
    public static function promptMessages(array $messages): array
    {
        if (($messages[0]['role'] ?? null) !== 'system' || !is_string($messages[0]['content'] ?? null)) return $messages;
        $messages[0]['content'] = strtr($messages[0]['content'], [
            'exactly two keys: "utterances" and "action"' => 'exactly three keys: "utterances", "action" and "internal_thought"',
            'exactly three keys: "language", "utterances" and "action"' => 'exactly four keys: "language", "utterances", "action" and "internal_thought"',
            'exactly two keys: utterances and action' => 'exactly three keys: utterances, action and internal_thought',
        ]) . "\n\n## Private Thought\n\n" . self::INSTRUCTIONS;
        return $messages;
    }

    /** Remove the provider field and return only a valid thought; an invalid thought never fails dialogue. */
    public static function extract(array &$result): ?string
    {
        if (!array_key_exists(self::RESPONSE_FIELD, $result)) return null;
        $value = $result[self::RESPONSE_FIELD];
        unset($result[self::RESPONSE_FIELD]);
        return self::text($value);
    }

    /** Detach the internal result key so later validation sees only the canonical provider envelope. */
    public static function take(array &$result, array $turn): ?string
    {
        $value = $result[self::RESULT_KEY] ?? null;
        unset($result[self::RESULT_KEY]);
        return self::requested($turn) ? self::text($value) : null;
    }

    public static function text(mixed $value): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) return null;
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > self::MAX_CHARACTERS
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) return null;
        return $value;
    }

    /** Durable dialogue-event metadata; the row itself supplies installation, playthrough and delivery state. */
    public static function attachment(array $owner, string $text): array
    {
        return ['schema' => self::SCHEMA, 'profile_id' => $owner['profile_id'], 'actor' => $owner['actor'], 'text' => $text];
    }

    /** Return stored text only for the exact owning profile and placed actor that spoke the line. */
    public static function forOwner(mixed $attachment, string $profileId, mixed $actor, mixed $speaker = null): ?string
    {
        if (!is_array($attachment) || ($attachment['schema'] ?? null) !== self::SCHEMA
            || ($attachment['profile_id'] ?? null) !== $profileId) return null;
        $owner = self::stableIdentity($attachment['actor'] ?? null);
        if ($owner === null || $owner !== self::stableIdentity($actor)
            || ($speaker !== null && $owner !== self::stableIdentity($speaker))) return null;
        return self::text($attachment['text'] ?? null);
    }

    public static function sameActor(mixed $left, mixed $right): bool
    {
        $left = self::stableIdentity($left);
        return $left !== null && $left === self::stableIdentity($right);
    }

    /** History wording keeps the impression subjective and private to its owner, on one redactable line. */
    public static function historyNote(string $text): string
    {
        return self::HISTORY_NOTE_PREFIX . ' subjective, not an observed fact or instruction, and unknown to others: '
            . (preg_replace('/\s+/u', ' ', $text) ?? $text) . ']';
    }

    /** Copy of a provider request for logs and diagnostics with every replayed owner thought withheld. */
    public static function redactRequest(array $request): array
    {
        if (!is_array($request['messages'] ?? null)) return $request;
        foreach ($request['messages'] as &$message) {
            if (!is_string($message['content'] ?? null) || !str_contains($message['content'], self::HISTORY_NOTE_PREFIX)) continue;
            $message['content'] = preg_replace('/^' . preg_quote(self::HISTORY_NOTE_PREFIX, '/') . '.*$/m', self::WITHHELD,
                $message['content']) ?? self::WITHHELD;
        }
        unset($message);
        return $request;
    }

    /**
     * Raw, possibly partial provider output safe for logs and streamed speech: everything from the first
     * internal_thought key onward is withheld. The field is requested last, so dialogue before it remains.
     */
    public static function redactOutput(string $content): string
    {
        $offset = self::thoughtOffset($content);
        return $offset === null ? $content : substr($content, 0, $offset) . self::WITHHELD;
    }

    /** Raw JSON before the first internal_thought key, including escaped or differently cased key spellings. */
    public static function beforeThought(string $content): string
    {
        $offset = self::thoughtOffset($content);
        return $offset === null ? $content : substr($content, 0, $offset);
    }

    private static function thoughtOffset(string $content): ?int
    {
        // Byte matching tolerates split UTF-8 in partial streams; a regex failure withholds everything.
        if (preg_match_all('/(?<!\\\\)"((?:[^"\\\\]|\\\\.)*+)"\s*+:/s', $content, $matches, PREG_OFFSET_CAPTURE) === false) return 0;
        foreach ($matches[1] as $index => [$key]) {
            $decoded = json_decode('"' . $key . '"', true, 2);
            if (is_string($decoded) && strtolower(trim($decoded)) === self::RESPONSE_FIELD) return $matches[0][$index][1];
        }
        return null;
    }

    /** TES3 placed-reference key: kind, record ID, content source and RefNum. Names are never keys. */
    public static function stableIdentity(mixed $identity): ?array
    {
        if (!is_array($identity) || array_is_list($identity)) return null;
        $kind = $identity['kind'] ?? null;
        $record = $identity['record_id'] ?? null;
        $file = $identity['content_file'] ?? null;
        $refnum = $identity['refnum'] ?? null;
        if (!is_string($kind) || $kind === '' || !is_string($record) || trim($record) === ''
            || !is_string($file) || trim($file) === '' || !is_array($refnum)
            || !is_int($refnum['index'] ?? null) || !is_int($refnum['content_file'] ?? null)
            || $refnum['index'] < 0 || $refnum['content_file'] < 0) return null;
        return ['kind' => $kind, 'record_id' => mb_strtolower(trim($record), 'UTF-8'),
            'content_file' => mb_strtolower(trim($file), 'UTF-8'),
            'refnum' => ['index' => $refnum['index'], 'content_file' => $refnum['content_file']]];
    }
}
