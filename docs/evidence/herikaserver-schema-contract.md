# HerikaServer schema contract

## Baseline

- Repository: `https://github.com/abeiro/HerikaServer.git`
- Ref: `origin/unstable`
- Commit: `508d7335d26f8027b3d576137bf161b331ed8ad9`
- Operational comparison database: local `dwemer` PostgreSQL schema inspected read-only on 2026-08-05
- ALMSIVI migration strategy: exact Herika product tables plus separate OpenMW ownership/correlation metadata

Migration 026 creates the first exact DDL family in `herika_compat`. This staging schema is deliberate:
the current `public.core_profiles`, `public.prompts`, `public.speech`, and `public.responselog` names are
still used by ALMSIVI's typed repositories. The staged tables are backfilled before repository dual
writes and the final public-schema cutover, so no user data is overwritten or silently reinterpreted.
Migration 037 performs that reversible cutover: all 81 active Herika core tables and eight active
views live in `public`; all ALMSIVI-only transport and companion tables live in `almsivi_internal`;
and `herika_compat` is removed.

## Disposition rules

| Disposition | Rule |
| --- | --- |
| Copy Exact | Preserve Herika name, columns, order, type, default, nullability, sequence, constraints, indexes, comments and view behavior. |
| Morrowind Adaptation | Preserve the table contract while replacing Skyrim-only producers or derived display fields with TES3/OpenMW semantics. |
| Internal Companion | Store installation, playthrough, session, source-event, turn and exact TES3 identity without modifying copied Herika columns. |
| Functional Exclusion | Schema/UI compatibility may remain, but no STT, ITT, Background Life or autonomy runtime is provisioned. |
| Proven Deprecated | Omit only when Herika identifies the object as legacy and production-reference review confirms no required writer/reader. |

## First staged family

Copied exact: `core_api_badge`, `core_llm_connector`, `core_tts_connector`, `core_stt_connector`,
`core_itt_connector`, `core_tts_fallback`, `core_profiles`, `core_npc_master`,
`core_npc_master_history`, `core_player`, `core_narrator`, `general_settings`, `prompts`,
`bio_templates`, `bio_templates_custom`, `combined_bio_templates`, `speech`, and `responselog`.

Morrowind adaptation: `speech_view` keeps the Herika speech row projection but replaces Skyrim date
functions with neutral UTC-derived OpenMW event timestamps. Full TES3 game-time formatting will be
added when the client supplies the calendar fields required to derive it without guessing.

Internal companions: connector, profile, NPC, prompt, speech and response metadata tables map the
Herika integer/row IDs back to ALMSIVI installation UUIDs, immutable turns, exact actor identities,
delivery state and source revisions.

Herika's `speech.rowid` and `responselog.rowid` are sequence-backed but are not unique constraints.
Their companion metadata therefore owns the row ID as its primary key without adding a foreign key
that would require changing the copied Herika tables. Repository transactions and parity checks must
enforce the one-to-one relationship.

`core_stt_connector` and `core_itt_connector` exist only because active Herika profile foreign keys
reference them. They remain empty and have no functional ALMSIVI runtime or enabled UI.

## Proven deprecated objects

HerikaServer's Database Manager explicitly labels `npc_templates`, `npc_templates_custom`,
`npc_templates_trl`, and `npc_templates_v2` as legacy. They are not canonical ALMSIVI tables;
`bio_templates`, `bio_templates_custom`, and `combined_bio_templates` replace them.

No other table or column is deprecated merely because it is empty. `conf_opts`, for example, remains
actively read by Herika production paths and cannot be removed until its consumers are migrated.
Physical PostgreSQL column slots already removed from Herika are treated as proven deprecated catalog
tombstones: visible column order is preserved, but ALMSIVI does not recreate invisible `attisdropped`
entries solely to reproduce gaps in `ordinal_position`.

## Second staged family

Migration 027 copies `audit_request`, `audit_memory`, `log`, `actions_issued`, `moods_issued`,
`rolemaster`, `conf_opts`, and `database_versioning` exactly. Existing frozen prompt/provider records
backfill the Herika request/log structures; typed action intents backfill `actions_issued`; companion
metadata preserves immutable turn and action correlation. `core_api_badge` remains empty and audit
rows never copy provider keys or authorization headers.

`conf_opts` remains because the pinned Herika runtime still reads it. ALMSIVI writes only the current
player name and a bounded Global Settings compatibility document during this stage.

## Remaining families

Migration 028 stages exact `memory`, `memory_summary`, `memory_v`, `oghma`, `oghma_dynamic`,
`diarylog`, `physical_npc_diaries`, relationship queues and `rumors`. Current ALMSIVI memory,
knowledge, narrative, relationship and retrieval records are backfilled with companion source IDs.
Background Life remains excluded: no scheduler or `backgroundlife_diary` producer is added, and the
copied `memory_v` continues to filter that legacy classifier.

Migration 029 stages exact Herika `books`, `quests`, `questlog`, `currentmission`, `locations`,
`factions`, `game_plugins`, `descriptions`, `descriptions_custom`, `combined_descriptions`, and
`named_cell` tables. OpenMW journal, content-file, cell, faction and item-description snapshots are
backfilled into those contracts with exact source identities in companion metadata. `locations_v`
keeps the Herika columns but removes the Skyrim-specific hard-coded cleared-location exception.
- Relationships: `core_npc_master.extended_data` plus exact relationship queues and history snapshots.
- Final cutover: migration 037 moves all ALMSIVI-only tables, not only conflicting names, into
  `almsivi_internal`; promotes the validated tables, views, sequences and adapted views into `public`;
  retargets projection functions; and leaves no custom ALMSIVI columns on copied Herika tables.
- Browser reads: NPCs, Core Profiles, LLM/TTS connectors, prompts, events, responses, memories,
  relationships, narratives, knowledge, journal, books, descriptions and actions read the public
  Herika objects, joining internal companion metadata only for UUID ownership and protocol state.

The catalog gate also asserts the complete public namespace inventory. Extra product tables, views or
sequences fail verification instead of being silently accepted.

Run the catalog check as the PostgreSQL owner:

```bash
php scripts/verify-herika-schema-contract.php
```

The command compares columns, order, PostgreSQL types, defaults, identity behavior, constraints,
indexes and active views against the local read-only Herika reference database. The Morrowind-adapted
`speech_view`, `locations_v`, and `eventlog_view` retain the Herika column contracts and have separate
adaptation assertions.

## Local cutover validation snapshot

The 2026-08-05 local WSL cutover was preceded by
`/var/backups/almsiviserver/almsivi-pre-herika-cutover-20260805T203820Z.dump` with SHA-256
`ee392828566374921e0f2e14217d5ee46c37db598cb68863598d2a585a840b8b`. All 37 migrations applied,
and the catalog verifier reported `Herika core schema contract matches public -> public`.

Populated public projections and their companion metadata had equal live counts after cutover:

| Projection | Public rows | Companion rows |
| --- | ---: | ---: |
| Event log | 155 | 155 |
| Speech | 80 | 80 |
| Core profiles | 1 | 1 |
| NPC profiles | 2 | 2 |
| LLM connectors | 4 | 4 |
| TTS connectors | 2 | 2 |
| Prompts | 1 | 1 |
| Journal | 1 | 1 |

The existing migration suite now inserts and verifies both sides of provider, prompt, memory,
knowledge/Oghma, NPC relationship, diary and action projections. It also exercises a populated
upgrade, complete down/up cycle, final-cutover rerun and fresh installation. The migration/durable
job, integration vertical-slice and browser-like management HTTP suites pass together.

The live Morrowind projections contain no Skyrim-labelled quest, location or content-file data. The
five active Herika `skyrim_quest_*` tables remain present for exact schema parity but contain no rows
and have no ALMSIVI producer. Functional STT, ITT, Background Life and general autonomy remain
excluded even where exact Herika compatibility tables are retained.

Final acceptance remains intentionally separate from catalog and server tests: after this cutover,
an actual OpenMW conversation must produce a correlated turn, prompt trace, eventlog dialogue,
speech/TTS delivery and any eligible memory, relationship or journal projections. Do not call the
cutover complete until those rows and the client/server logs are inspected together.
