# Final Synthserver migration and source audit

## Intake rule

The migration source is the final completed `RANGROO/Synthserver` main commit recorded after its
documented stop condition. Do not pin today's planning SHA or start from DialecticServer directly.
DialecticServer/HerikaServer remain lineage references for provenance and missed feature checks.

## Pre-import inventory

Record exact commits, remotes, license files, dependency lock hashes, clean status, test commands/
results and package manifests for SYNTH/Synthserver. Inventory source layout, migrations/schema,
routes/endpoints, protocol fixtures, provider connectors, event/prompt/media/action pipeline, profiles,
memories/relationships/world knowledge/playthroughs, service/backups, management UI and tests.

Create `docs/evidence/import-ledger.csv` with target path, source URL/SHA/path, license, disposition
(`copied`, `modified`, `concept`, `original`), transformation and reviewer. No unknown row may ship.

## Semantic translation map

| Synthserver/Fallout concept | LorkhanServer concept |
| --- | --- |
| SYNTH/Synthserver | LORKHAN/LorkhanServer |
| Fallout 4 / FO4 | Morrowind/TES3 on OpenMW |
| Sole Survivor | Player/Nerevarine only when lore role is actually known |
| Commonwealth/worldspace | Tamriel, region, interior/exterior cell |
| F4SE/F4SEVR/runtime variant | OpenMW exact version/commit + Lua API revision |
| flat/VR lanes | one OpenMW runtime lane; platform is separate metadata |
| FormID/plugin identity | RecordId + RefNum/FormId + source content/order + cell |
| ESP/ESM plugin list | ordered OpenMW content files and content fingerprint |
| SPECIAL/perks/rads/Power Armor | TES3 attributes/skills/birthsign/reputation/bounty/effects |
| Pip-Boy/VATS/workshop | no direct equivalent; exclude rather than rename falsely |
| F4 native action catalog | OpenMW API-129 typed action catalog |

Use `player` when the game has not proven the Nerevarine narrative state. Do not hardcode Vvardenfell
because Tamriel Rebuilt/Project Tamriel profiles add land, cells, factions and quests.

## Preserve/adapt/replace/exclude audit

- **Preserve architecture:** migrations, typed services, event source, provider abstraction, jobs,
  management product, tests, security controls, backups and observability when applicable.
- **Adapt domain:** schemas/fixtures/identity/context/action catalog/prompts/UI copy/seed data and
  game/client health to TES3/OpenMW.
- **Replace runtime assumptions:** F4SE/VR discovery, INI/DLL/package/ESP, NDJSON if final SYNTH uses it
  incompatibly with LORKHAN long polling, and Fallout media/identity details.
- **Exclude:** VR/HMD/FRIK, Fallout-only entities/actions/content, generic legacy aliases, game assets,
  production data and provider secrets.

## Migration sequence

1. Import provenance commit with baseline tests unchanged.
2. Add LORKHAN names/config alongside existing names just long enough for data migration tests.
3. Add new constrained columns/tables and deterministic backfill for test/dev data.
4. Switch application reads/writes and v1 endpoints atomically by migration version.
5. Remove misleading Fallout/Skyrim names/routes/columns/fixtures. Retain only the explicitly
   approved Herika-compatible UTF-8 public table/view and browser presentation contracts over typed
   LORKHAN sources.
6. Run forbidden-term/source/provenance/schema/UI audit and clean-install plus upgrade fixtures.

Never silently reinterpret persisted production-like rows. This is a new private project; prefer a
clean database unless the user explicitly supplies LORKHAN data to migrate.

## Forbidden-term audit

Search code, schema, routes, UI, seeds, fixtures and generated artifacts for `fallout`, `fo4`,
`commonwealth`, `sole survivor`, `f4se`, `frik`, `pip-boy`, `vats`, `power armor`, Skyrim/Papyrus and
old product names. Allow only cited migration/reference documentation, provenance metadata and
explicit negative tests. Every allowlist entry is path/line-purpose specific.

## Acceptance

- final source pin and every file's provenance/license are recorded;
- imported baseline and migrated suite are green;
- fresh database and supported upgrade fixture reach the same schema/data invariants;
- protocol/identity/action/context fixtures are TES3/OpenMW-native;
- no misleading Fallout/Skyrim route/conf/table/UI alias remains; approved Herika-compatible
  table/view and presentation contracts are documented and backed by typed LORKHAN sources;
- secret/user/game-data scan is clean;
- sibling fake E2E and schema manifest parity pass.
