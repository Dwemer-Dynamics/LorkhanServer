<?php
declare(strict_types=1);

// Server half of the runnable parity.example addon (the client half lives in the LORKHAN repository under
// examples/plugin-parity). LorkhanServer loads this file from the verified package tree only after the operator
// installs and enables parity.example. It is trusted, unsandboxed PHP: return closures only and never add
// credentials, network calls, file writes or shell commands. The manifest declares no prompt slots, so there is
// no prompt hook here.
return [
    // camp_marked: remember the camp mood this actor last reported. The worker may deliver an event more than
    // once. Ignore immediate duplicates and older sequential deliveries; this simple state setter is not a ledger.
    'event' => static function (array $event, \LorkhanServer\Infrastructure\PluginNpcData $npcData): void {
        if ($event['event'] !== 'camp_marked') return;
        $actor = $event['fields']['actor'];
        $camp = $npcData->get($actor) ?? [];
        if (($camp['last_message_id'] ?? null) === $event['message_id']) return;
        if (isset($camp['marked_at']) && strcmp((string)$event['observed_at'], (string)$camp['marked_at']) < 0) return;
        $npcData->set($actor, ['mood' => mb_substr((string) $event['fields']['mood'], 0, 16),
            'marked_at' => (string) $event['observed_at'], 'last_message_id' => $event['message_id']]);
    },
];
