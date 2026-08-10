# Morrowind biography generator

`scripts/build-morrowind-biographies.py` creates reviewable character biographies using the
established CHIM `bio_templates` field order and writing style. It is an offline authoring tool and
does not run in Apache, a worker, OpenMW, or the in-game conversation path.

## Sources and identity

The installed `Morrowind.esm`, `Tribunal.esm`, and `Bloodmoon.esm` files are read in official load
order. Their winning `NPC_` records supply record ID, display name, content file, race, class,
faction, gender, and flags. The script reads those files but never copies them into an output.

UESP is optional supporting evidence. A page is accepted only when the record ID printed by its NPC
infobox exactly matches the official ESM record ID. The output stores the accepted source URL, page
ID, revision ID, and license label. Raw page caches stay under the user's cache directory.

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

The default limit is five. `--dry-run` performs ESM and UESP matching without contacting OpenRouter.
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
an empty string until ALMSIVI's tagging policy is set.

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
These files remain review artifacts and are not imported or deployed automatically.

For a full official-catalog run, use a new run directory, set `--size 3041`, and retain
`--max-cost 30`. The runner records cumulative attempt cost and holds the configured budget reserve
before launching another bounded child. Collect the full evidence-only stage first, review its exact,
not-found, mismatch, and error counts, and then resume the same locked selection for generation.
