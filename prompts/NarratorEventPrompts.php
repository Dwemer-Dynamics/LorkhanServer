<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/** Shared editable narrator event instructions; untouched defaults preserve existing behavior. */
final class NarratorEventPrompts
{
    public const SOURCES = [
        'lorkhan_narrator_welcome' => 'narrator_welcome_prompt',
        'lorkhan_narrator_random' => 'random_narration_prompt',
        'lorkhan_narrator_boredom' => 'narrator_bored_prompt',
        'lorkhan_narrator_quest' => 'quest_comment_prompt',
        'lorkhan_narrator_book' => 'narrator_book_prompt',
    ];

    public static function definitions(): array
    {
        return [
            'narrator_welcome_prompt' => ['description' => 'Narrator welcome after loading a game. {PLAYER_NAME} is replaced with the current player name.',
                'default_prompt' => 'Welcome {PLAYER_NAME} after loading the game. Give a concise recap grounded only in the supplied history, journal, and current scene. Do not invent events or speak as another character.'],
            'random_narration_prompt' => ['description' => 'Narrator description after an eligible conversation round.',
                'default_prompt' => 'Add a concise visual description of the current scene using only supplied context. Focus on visible people, environment, lighting, and atmosphere. Do not advance the plot or invent actions.'],
            'narrator_bored_prompt' => ['description' => 'Narrator observation after a quiet period.',
                'default_prompt' => 'Make one concise narrator observation about the current scene after a quiet period. Use only supplied context; do not invent events or dialogue for world actors.'],
            'quest_comment_prompt' => ['description' => 'Narrator comment on the newest observed Morrowind journal update.',
                'default_prompt' => 'Comment concisely on the newest supplied journal update. Preserve uncertainty and do not invent quest outcomes, objectives, or events.'],
            'narrator_book_prompt' => ['description' => 'Narrator summary of the newest supplied opened book.',
                'default_prompt' => 'Summarize or react concisely to the newest supplied opened book. Use only its supplied title and text; do not invent contents.'],
        ];
    }

    /** Add read-only factory baselines without creating configuration records on a page GET. */
    public static function rows(string $installationId, array $savedRows): array
    {
        if ($installationId === '') return [];
        $saved = [];
        foreach ($savedRows as $row) {
            if (($row['installation_id'] ?? '') === $installationId) $saved[$row['prompt_key']] = $row;
        }
        $rows = [];
        foreach (self::definitions() as $key => $definition) {
            $row = $saved[$key] ?? [
                'installation_id' => $installationId, 'configuration_id' => 'factory-'.$key,
                'prompt_key' => $key, 'name' => $key, 'current_revision' => 0,
                'profile_usage' => 0, 'revisions' => [], 'content' => [],
            ];
            $row['narrator_event_prompt'] = true;
            $row['content'] = array_replace($row['content'], $definition);
            $rows[] = $row;
        }
        return $rows;
    }
}
