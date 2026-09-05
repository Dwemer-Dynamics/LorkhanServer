# Morrowind biography generator

`scripts/build-morrowind-biographies.py` creates reviewable character biographies using the
established CHIM `bio_templates` field order and writing style. It is an offline authoring tool and
does not run in Apache, a worker, OpenMW, or the in-game conversation path.

## Sources and identity

The installed content files are read in the load order supplied through repeatable `--content-file`
arguments, defaulting to `Morrowind.esm`, `Tribunal.esm`, and `Bloodmoon.esm`. Their winning `NPC_`
records supply record ID, display name, content file, race, class,
faction, gender, and flags. The script reads those files but never copies them into an output.

Remote wiki acquisition is disabled. The generator uses locally installed content evidence; any
separately approved wiki export must be reviewed and imported through a future offline source path.

Display names are not keys. Generated `npc_name` values derive from the exact TES3 record ID, and the
review manifest retains the authoritative content file separately.

## Five-character review run

The tool requires Python 3, `requests`, and `beautifulsoup4`. Provider credentials are read only from
the named environment variable and are never written to an output or cache.

Set `OPENROUTER_API_KEY` in the current process and run:

```powershell
python scripts/build-morrowind-biographies.py `
  --npc fargoth `
  --npc "Caius Cosades" `
  --npc Jiub `
  --npc Ajira `
  --npc "Divayth Fyr" `
  --limit 5
```

The default limit is five. `--dry-run` extracts local content evidence without contacting OpenRouter.
Existing outputs are never replaced unless `--force` is supplied. Each completed NPC is checkpointed;
`--resume` reuses rows present in both output files after an interruption only when they still pass
the generator's current formatting and content rules. Add
`--refresh-npc "Display Name"` to regenerate one selected row while retaining the other checkpoints.

The main output contains identity, source revision, generation status, and template data. The
`*-chim.json` output contains only flat rows in this exact order:

```text
npc_name, oghma_knowledge_tags, core, npc_static_bio, appearance, personality,
relationships, occupation, skills, speechstyle, goals, voiceid, gender, race, refid
```

Skills and goals use CHIM-style `* ` bullet lines. `relationships` is a compact JSON object using the
same target-keyed `aff`, canonical `type`, `relation`, `note`, `best`, and `worst` seed contract
accepted by CHIM and Dialectic NPC biography imports. The separate relationships output mirrors the
existing relationship metadata builder's `npc_name`, status, count, and relationship-map results.

Generated prose is bounded to the current CHIM default cadence: a one-sentence core, a 2-3 sentence
biography, 2-3 sentence appearance and personality sections, a 1-2 sentence occupation, and a
1-2 sentence speech style. Skills and goals must contain short descriptive bullets rather than bare
game skill names. Rows outside the field-specific word, sentence, bullet, evidence, or relationship
constraints receive up to three focused GLM repair passes and are rejected if they still do not conform.

The five-character run is review data only and is not imported into PostgreSQL.
`oghma_knowledge_tags` remains present for CHIM field compatibility but is deliberately exported as
an empty string until LORKHAN's tagging policy is set.

## Fifty-character production preflight

`scripts/run-morrowind-biography-preflight.py` builds a deterministic, stratified 50-NPC sample
before an all-catalog run. It includes the approved five, Tribunal and Bloodmoon records, duplicate
display names, essential and respawning NPCs, sparse ESM identities, and a deterministic diversity
fill. Selection and processing always use exact record IDs.

Collect and inspect source evidence first:

```powershell
python scripts/run-morrowind-biography-preflight.py `
  --run-dir C:\path\to\morrowind-biography-preflight-50 `
  --size 50 `
  --evidence-only `
  --resume
```

Then set `OPENROUTER_API_KEY` in the current process and generate the same locked selection:

```powershell
python scripts/run-morrowind-biography-preflight.py `
  --run-dir C:\path\to\morrowind-biography-preflight-50 `
  --size 50 `
  --max-cost 30 `
  --resume
```

Each NPC runs in its own bounded child process and directory. Successful records are skipped on
resume; malformed, timed-out, or nonconforming results are quarantined without discarding earlier
work. `selection.json` pins the official ESM hashes and sample, `manifest.json` records per-NPC
status and aggregate provider telemetry, append-only `attempts.json` files preserve retry usage, and
`combined/` contains only currently validated rows. An OS-level run lock prevents multiple writers
from processing the same run directory concurrently. Rejected candidates and their telemetry are
retained in `rejected.json` for diagnosis.
After the full review gate, package `combined/preflight-chim.json` as `biographies.json` beside the
run `manifest.json` and a stable `catalog-version.txt` under
`data/biographies/morrowind-official/`. Validate it without changing PostgreSQL:

```powershell
php scripts/import-morrowind-biographies.php dry-run `
  --biographies=data/biographies/morrowind-official/biographies.json `
  --manifest=data/biographies/morrowind-official/manifest.json `
  --catalog-version=morrowind-official-2026-08-biographies-v1
```

The versioned importer projects factory rows into CHIM's exact `bio_templates` contract while
retaining canonical `content_file + record_id` identity in LORKHAN's internal catalog. Custom and
encountered NPC profiles stay in the revisioned typed repository. On first encounter, exact OpenMW
identity copies the matching factory biography into the NPC profile used by prompts. Routine deploys
provision a bundled catalog idempotently and do not override an explicit catalog rollback.

For a full official-catalog run, use a new run directory, set `--size 3041`, and retain
`--max-cost 30`. The runner records cumulative attempt cost and holds the configured budget reserve
before launching another bounded child. Per-record files remain immediately resumable while aggregate
outputs are rebuilt every 25 processed records by default. Collect the full evidence-only stage first, review its exact,
not-found, mismatch, and error counts, and then resume the same locked selection for generation.

## Additional content

Tamriel Rebuilt biographies remain in the same combined catalog. Supply the exact OpenMW order,
including `Tamriel_Data.esm` and `TR_Mainland.esm`, to both the preflight and child generator. The
manifest records every input hash, and each biography keeps its winning `content_file`; the runtime
already matches factory profiles by `content_file + record_id`.
