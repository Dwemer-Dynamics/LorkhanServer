# NPC reference identity

NPC profiles use the placed reference: lower-case content filename plus local reference index.
Display name, cell and numeric load order do not identify an NPC. Two guards with the same
base record and name remain separate when their placed references differ.

The stored NPC profile key is:

`ref:<installation>:<playthrough>:<content filename>|<decimal local index>`

Installation/playthrough IDs still scope records. Core Profiles, sessions, player/narrator
owners and biography templates retain their existing identifiers; those are not NPC reference keys.
Ordinary NPC creation never generates a random profile UUID. Rediscovery of a deleted NPC revives
its existing reference-key profile. A changed base record/type at the same physical reference is
rejected rather than silently adopting unrelated history.

In `public.core_npc_master`, `refid` stores the decimal placed reference index and `base` stores
its in-game base record ID (`actor_identity.record_id`). Source mod names are stored separately
in `metadata.mods`, an array matching CHIM's metadata format. NPC cards and editors use **Base**
and **Ref ID**; the editor displays the source file separately as **Mod**. Migrations 130 and 131
correct existing rows/history and future projection writes. Biography catalog identifiers remain
unchanged. OpenMW's content filename remains in raw actor identity for exact game routing.

## Reference Groups

The Lorkhan NPC page contains an editable Reference Groups section. Each group has a stable key,
display name, canonical reference, alternate references and enabled flag. A physical reference can
belong to only one group. Unlisted NPCs are never grouped by name or base record.

Defaults cover the alternate placed references of Dagoth Ur, Almalexia and Thormoor Gray-Wave.
The list contains reference metadata only, not game assets. New paired installations receive the
same editable defaults.

Grouping changes the profile lookup only. Commands, speech and observed actor bindings retain the
actual physical actor. Web NPC actions use the most recently observed binding and reject tied,
ambiguous observations. Group edits invalidate only affected bindings. Existing histories are not
merged or deleted when a group changes; the next observation applies the new mapping.

## Storage and reset

Migration 128 converts NPC profile references to text, preserves foreign keys and projection
functions, and permits duplicate display names. Migration 129 adds Reference Groups. Portable
playthrough imports remap the scope inside reference keys without generating NPC UUIDs.

The prototype deployment uses an explicit gameplay-only reset after a verified full database backup.
Do not use Factory Reset for this: it would also replace configuration. Preserve credentials,
connectors, globals, Core Profiles, biography catalogs and voice assets. Pause workers and take the
exclusive runtime gate before clearing gameplay. Verify protected row hashes before committing.

## Verification (2026-09-18)

- 1,547 server checks; 111 Lua tests.
- Windows Release OpenMW build, native tests and Beast loopback tests passed.
- Matching client/server protocol manifests; 42 schemas and 94 fixtures validated.
- Fresh database: all 129 migrations; no unclassified backup-policy tables.
- 16 transaction-rolled-back identity/group checks: same-name independence, load-order/cell
  continuity, alias sharing, physical target preservation, CRUD, overlap rejection, disable/delete,
  unrelated binding preservation and deleted-profile rediscovery.
- Live reset preserved protected row counts/hashes; 12,674 biography rows and 11 connector/settings
  configuration sets retained. New client hash and deployed Lua matched build/source.
- Live NPC Reference Groups rendered and Save Group completed through the browser.
- In-game acceptance remains for the user; no game was launched or save loaded during deployment.
