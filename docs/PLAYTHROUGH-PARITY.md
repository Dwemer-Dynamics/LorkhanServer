# Playthrough parity implementation

Reference: `Dwemer-Dynamics/HerikaServer` unstable
`1d9a3d8ad1157e3fd429f85e3b992efc234dcfea`, especially
`lib/playthrough_policy.php`, `playthrough_switching.php`, and `playthrough_home.php`.
Client reference: `Dwemer-Dynamics/CHIM` unstable
`76194ad1d0be2c971ebf02a0ca3c4d6266479acd`, `Plugin/PlaythroughSession.cpp`.
These are behavioral references, not a database schema to copy into Lorkhan.

## Data boundary

| Ownership | Lorkhan data | Rule |
| --- | --- | --- |
| Shared | Core Profiles and revisions; connectors and credentials; global settings; prompts; action definitions; pronunciation and voice configuration; biography templates; factory world knowledge | A character switch must not overwrite these. |
| Shared | Narrator configuration/profile | Herika deliberately keeps Narrator shared. Do not split it merely because NPCs are isolated. Narrator dialogue, diary and memory records still use their existing playthrough scopes. |
| Per playthrough | Player and discovered NPC profiles, their revisions and assignments | Must have explicit ownership before automatic switching is enabled. Shared templates may seed a new NPC; another playthrough's evolved profile may not. |
| Per playthrough | Actor bindings, turns, source events, dialogue, memories, relationships, diary/narrative records, acquired knowledge, inventory and world observations | Existing scope columns must remain part of every read, mutation and derived job. |
| Operational | Pairing identity, browser sessions, nonces, jobs, leases, live sessions, response queues, backup catalogue | Preserve authentication and backup metadata. Fence or cancel outgoing work before admitting a different character. Never import live sessions or queued actions from a portable package. |

Full SQL snapshots remain disaster-recovery tools. They are **not** normal character switching:
the current restore replaces settings and profiles as well as gameplay history.
Existing partial profile JSON exports are also not complete portable playthrough packages.

## Implementation stages

1. **Save identity foundation.** Persist a native-generated character UUID in the OpenMW save;
   select it before session initialization. Fence pending choices and callbacks by generation.
   Bind it durably to a playthrough using an explicit `new` or `existing` decision.
   Unknown older saves must ask before adopting existing data. Never match by display name.
2. **NPC and Player ownership.** Add explicit playthrough ownership to mutable profiles.
   Update discovery, Player lookup, explicit binding, prompt routing, evolution writeback and
   management editing together. Preserve shared Narrator and Core Profiles.
   Legacy adoption must detect profiles already bound to another playthrough; ambiguous shared
   history needs an explicit copy/remap decision, not a blanket reassignment.
3. **New/switch/adopt workflow.** Admit the selected character atomically, preserve outgoing
   committed progress, and reject stale gameplay/workers. Do not expose a usable switch control
   until stage 2 proves profile isolation. Duplicate handshakes must not create duplicate scopes.
4. **Management UI.** Match the reference current-playthrough panel and new/switch picker, with
   loaded-character association, concise status and Morrowind dates. Keep full database recovery
   in a separate advanced section. Preserve Lorkhan colours.
5. **Recovery and transfer.** Make the rollback threshold configurable; show bounded recovery
   outcomes. Add portable packages with inspection, compatibility checks and shared-profile
   mapping. Import as an inactive copy; do not replace global settings or credentials.
6. **Optional retention.** Keep automatic deletion off. Provide a dependency-aware preview and
   explicit confirmation before any cleanup. Do not revive excluded game features.

## Development rollout gate

Stages 1-3 now have source/build/database coverage, including isolated NPC/Player ownership
and canonical session-owner handoff. The management UI follows the loaded save; it does not
pretend that selecting a web row loads a different game character. Full SQL recovery remains
separate. Keep the deployed client/server unchanged until a paired deployment is authorized.
Browser screenshots and two-save in-game acceptance remain outstanding.

Legacy adoption is deliberately conservative: one existing legacy playthrough may be adopted
explicitly without rewriting its history anchor. If an installation contains multiple worlds,
unowned legacy profiles cannot be assigned safely and admission rejects without replacing the
current session. An explicit provenance migration for that case is still future work.

## Acceptance

- Two characters with identical names retain different saved identities.
- An older save can explicitly adopt its existing history without deleting or reassigning it.
- Two playthroughs have separate Player/NPC revisions, dialogue, memories and relationships.
- Core Profiles, Narrator configuration, providers, credentials and global settings survive a switch.
- Reload, duplicate request, interrupted initialization and stale UI selection do not move data.
- Outgoing speech, actions and derived jobs cannot write to or run in the incoming character.
- Fresh install and migration from the deployed schema both pass on a disposable database.
- Client/server schemas and fixtures remain byte-identical.
- Build/test evidence is separate from deployment and in-game acceptance. Do not launch the game
  for testing; the user controls when to load a save.

## Known gaps at the starting baseline

At LorkhanServer `db5d566`, NPC discovery in `ProductRepository::ensureActorProfile` and the
Player singleton select by installation, while mutable profile revisions have no playthrough
owner. `bindActorProfile` validates installation but not profile ownership. These are blockers,
even though history, actor bindings, memory and relationship tables already contain playthrough IDs.
The existing fixed three-day rollback backup, partial JSON export and full SQL restore do not
resolve those ownership gaps.

## Foundation evidence (2026-09-16)

- Migration 121 adds character bindings without assigning existing data automatically.
- 1,512 server checks and the full disposable PostgreSQL integration suite pass. Integration
  covers explicit adoption, canonical identity recovery for an older untagged save, duplicate
  binding prevention, outgoing turn cancellation and rejected admission without side effects.
- A populated schema-120 database retained its legacy profile, revisions, playthrough, source
  history, Core Profiles and configuration through upgrade to 121 and a down/up round trip.
- Client/server protocol schemas, fixtures and manifests match. The paired identity contract
  also passes official Draft 2020-12 checks, including rejection of either missing pair member.
- This is a development checkpoint, not a deployed switching feature. The live installation
  is unchanged; NPC/Player ownership and the remaining stages above are still outstanding.

## Ownership checkpoint (2026-09-16)

- Migration 122 adds installation-checked playthrough ownership and per-world profile names.
  It does not automatically assign legacy data. Rollback refuses to discard populated ownership.
- Fresh characters receive separate Player/NPC profiles; returning to a saved character uses
  its canonical owner. The native client adopts that owner only after session acceptance.
- Discovery, bindings, prompt lookup, management lists and edits, evolution, diary, memory and
  relationship paths preserve the owning scope. Narrator and shared configuration remain global.
- The disposable PostgreSQL suite covers new/switch/return, legacy preservation, ambiguous
  adoption rejection without side effects, cross-scope guards, and six management selectors. Real discovery of the same TES3 NPC in two worlds
  verifies separate persona edits and return-to-save behavior, not just separate session IDs.
- Remaining plan: browser/in-game acceptance, explicit multi-world legacy migration, configurable
  recovery and portable transfer (stage 5), and optional retention (stage 6). No automatic cleanup.

Shared biography templates already present before migration are preserved: older versions did
not record whether each custom template was explicitly edited or projected from a live NPC.
The ownership migration must not guess and delete those records. New scoped NPC edits must not
write into the global template catalogue.

Checkpoint validation: 1,512 server checks, 99 Lua tests, native bridge and Beast transport
suites, OpenMW Release build, protocol parity, and client provenance/source-tree audits pass.
The older structural Python fallback still has its 10 baseline failures; it is not reported as
passing. Browser tooling was unavailable, so template render/lint is not visual acceptance.
