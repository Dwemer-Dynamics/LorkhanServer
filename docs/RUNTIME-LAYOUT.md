# Runtime layout

The source checkout is not the deployable web root. Both WSL entry points use
`scripts/stage-runtime.sh`, `deploy/runtime-files.txt`, and `deploy/runtime-excludes.txt`.
The staging directory must be empty; staging fails before changing the live tree if the
selected Oghma catalog is missing or invalid. Only the active catalog ships. Older catalogs,
biography generation reviews, tests, and development tools remain in source.

Runtime feature directories remain `connector`, `tts`, `stt`, `prompts`, `processor`,
`service`, `lib`, and `ui`. Workers and the authenticated debug command API remain available;
the Jobs, Provider Attempts, and Game Debug web pages have been removed. Server Logs redirects
to Dwemer Dashboard. Voice review is optional tooling; see VOICE-DESIGN-REVIEW.md.

## Persistent state

Deploys preserve `/etc/lorkhanserver`, the PostgreSQL database, `/var/lib/lorkhanserver`,
`/var/log/lorkhanserver`, and existing web-root `storage` and `vendor`. Voice samples and
review approvals are outside the web root. Previous code is backed up under
`/var/backups/lorkhanserver-code.*`. Do not copy runtime data or credentials into source.

## Remaining release gates

Client license/notices and corresponding-source artifacts must be settled and verified
before redistribution. Do not invent a license to satisfy the packaging audit. GitHub's
server workflow remains disabled until explicitly re-enabled by the owner.

## Canonical roleplay pages

- `ui/events-memories.php`: Events, Memories, Books, Journal.
- `ui/ai-response.php`: AI Responses.
- `ui/adventurelog.php`: Adventure Log.
- `ui/diarylog.php`: Diaries.

The three readers use shared rendering/query helpers without executing the Event Log or
memory controller. Old roleplay tab URLs redirect with their query filters intact.
`ui/core/npc_master.php` and `ui/global_settings.php` are the sole NPC/Global Settings entry
points. The old `core/character_manager.php` and `core/global_settings.php` wrappers are removed.
