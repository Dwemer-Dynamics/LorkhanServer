# Main consolidation — 2026-09-19

## Included work

- Server: merge the full `codex/playthrough-parity` history through `f07a683` with main through `c1a8167`.
- Client: merge the full `codex/playthrough-parity` history through `8ba2dd9` with main through `2b3fdc7`.
- Retain main's Director, Higgs, scene-classifier, and namespaced NPC plugin-data changes.
- Combine Director cancellation notices with the newer player speech interruption path.
- Accept only the exact historical archive schema combinations from both branches; retain checksum, scope, and column validation.
- Renumber the newly merged prototype migrations to 003/004 after the flattened 001/002 baseline. No migration SQL is discarded.
- Regenerate the client protocol manifest and server schema inventory from the merged sources.

## Validation

- 1,621 server checks passed.
- 119 Lua tests passed.
- Protocol schemas, fixtures, and manifests are byte-identical across the two repos.
- 42 schemas and 95 fixtures validated with the built-in validator.
- Fresh database applies migrations 001–004.
- Baseline replay, rollback, control-state preservation and incremental migration checks passed.
- Focused PostgreSQL archive validation covers both formats and both historical schema changes; unknown schema hashes remain rejected.
- Broader migrations/jobs suite still stops at its older profile fixture with `active_playthrough_required`.
- Native build was attempted: this WSL environment has no clang++; GCC's warning-as-error build stops on variant/misleading-indentation warnings. No new Windows or in-game build proof is claimed.

## Retained historical work

No dirty or archival worktree was deleted or blindly merged.

- Old action-catalog branches predate the shipped replacement `75aa608` and later action/identity changes. They are not the current implementation.
- Historical Oghma branches predate the consolidated runtime `8e175a7`, regional catalogs `69de848`, and subsequent UI/data updates.
- `codex/preserve-biography-wip-20260911` retains the old generator experiment. The dirty pilot generator matches that preserved snapshot; the current generator has subsequent formatting/validation choices.
- `codex/preserve-oghma-wip-20260911` retains the unfinished old-layout Oghma work, including files absent from the dirty worktree. It is not safe to replace the current runtime with this snapshot.
- Old pilot output/tmp directories and regional catalog staging remain untouched. They are not automatically treated as shippable source.

These archival branches remain available for a separate recovery review. Main is the source of truth for the completed playthrough work, not a claim that every historical experiment was shipped.
