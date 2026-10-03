<?php
declare(strict_types=1);

// Source-only example of a trusted LORKHAN server hook entrypoint. It must return closures only; LorkhanServer
// loads it from the verified package tree after the operator installs and enables ashlander.camp_tasks.
// This PHP is not sandboxed: never ship credentials, network calls, file writes or shell commands in hooks.
return [
    // Prompt hook: read-only NPC data; only the registered "scene_notes" slot is used, truncated to its max_chars.
    'prompt' => static function (array $context, \LorkhanServer\Infrastructure\PluginNpcData $npcData): array {
        if (($context['target']['kind'] ?? null) !== 'npc') return [];
        $camp = $npcData->get($context['target']);
        if (!is_array($camp) || !is_int($camp['meals_shared'] ?? null)) return [];
        return ['scene_notes' => sprintf('This camp has shared %d meal(s) with guests recently.', min(99, $camp['meals_shared']))];
    },
    // Event hook: may run again if the worker retries, so it must be idempotent per message_id.
    'event' => static function (array $event, \LorkhanServer\Infrastructure\PluginNpcData $npcData): void {
        if ($event['event'] !== 'meal_shared') return;
        $camp = $npcData->get($event['fields']['host']) ?? [];
        if (($camp['last_message_id'] ?? null) === $event['message_id']) return;
        $npcData->set($event['fields']['host'], ['meals_shared' => min(99, (int) ($camp['meals_shared'] ?? 0) + 1),
            'last_message_id' => $event['message_id']]);
    },
];
