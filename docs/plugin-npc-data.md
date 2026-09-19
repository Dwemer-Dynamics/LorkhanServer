# Per-NPC plugin data

`public.core_npc_master.plugin_extended_data` is a non-null JSONB object, initially `{}`.
LORKHAN stores its authoritative copy on `lorkhan_internal.profiles` so projection rebuilds,
playthrough exports and imports preserve it. Migration 129 adds live and history columns.

Use the existing PDO connection and the owning installation UUID:

```php
use LorkhanServer\Infrastructure\NpcPluginDataRepository;

$plugins = new NpcPluginDataRepository($db, $installationId);
$plugins->setPluginData($npcId, 'custom_plugin', ['enabled' => true]);
$data = $plugins->getPluginData($npcId, 'custom_plugin');
$plugins->deletePluginData($npcId, 'custom_plugin');
```

- `$npcId` is the positive integer ID from `core_npc_master`, not an NPC name or profile UUID.
- Only NPCs owned by the installation and visible in its current playthrough can be accessed.
- Plugin IDs match `[a-z][a-z0-9_-]{0,63}`. A plugin owns one top-level namespace.
- Set replaces that namespace atomically, preserving other plugins. Concurrent writes to the same
  namespace use the last update, without a deep merge. Values must be string-keyed PHP arrays;
  an empty array stores `{}`. Invalid input throws; PDO errors retain the driver's exceptions.
- Get returns a top-level associative array or `null` for a missing/out-of-scope NPC or namespace.
  Nested objects remain `stdClass` objects and arrays remain arrays for lossless round trips.
- Set/delete return `false` for an inaccessible NPC. Deleting an absent namespace succeeds.
- Plugin-only changes do **not** create profile revisions or NPC history. Ordinary profile revisions
  capture the current plugin data, and revision rollback restores that snapshot. Existing old revisions
  default to `{}`. Normal profile saves preserve plugin data independently of editable content.
- Portable playthrough archives carry live and revision plugin data. Archives from the exact schema
  before migration 129 are accepted with empty plugin data; other schema mismatches remain rejected.
- This is a trusted server-side API. It does not expose an HTTP endpoint, add prompt content, or
  provide a sandbox between installed PHP plugins. Use this API instead of writing the derived column.

Behavioral parity: HerikaServer PR #96, StobeServer PR #165, DialecticServer PR #151.
LORKHAN uses its own typed repositories, migrations, profile ownership and projection triggers.
