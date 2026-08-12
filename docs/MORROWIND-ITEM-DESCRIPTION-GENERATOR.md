# Morrowind item-description generator

`scripts/run-morrowind-item-description-preflight.py` builds a review-only sample of descriptions
for official Morrowind items. It does not import rows into PostgreSQL, deploy the server, or alter
the installed game.

## Catalog and identity

The script reads winning portable-item records from `Morrowind.esm`, `Tribunal.esm`, and
`Bloodmoon.esm` in official load order. Named records from ALCH, APPA, ARMO, BOOK, CLOT, INGR,
LIGH, LOCK, MISC, PROB, REPA, and WEAP are eligible. The stable key is the exact
`(content_file, record_id)` pair; display names are never deduplicated.

The deterministic 50-item preflight includes every supported record type, all three official
content files, duplicate display names, scripted items, and a stable diversity fill. Its locked
`selection.json` records the official ESM hashes so a resumed run cannot silently switch inputs.

UESP evidence is optional and accepted only when retrieved page text contains the exact record ID.
Page and revision identifiers are retained for review, while raw response caches stay under the
user cache directory.

## Output style

Descriptions follow the current CHIM catalog cadence:

- exactly one sentence;
- 13 to 22 words;
- concrete physical appearance and construction;
- no mechanics, values, statistics, metadata, paths, quest instructions, or unsupported history.

The generator writes UTF-8 JSON, a UTF-8 BOM CSV with the CHIM header
`plugin,baseid,name,description`, a Markdown review table, and a standalone HTML review table.
Per-record checkpoints and provider telemetry make interrupted runs resumable. Outputs are review
artifacts and are not committed or imported automatically.

## Run the preflight

Collect and lock evidence without a provider call:

```powershell
python scripts/run-morrowind-item-description-preflight.py `
  --run-dir C:\path\to\morrowind-item-descriptions-50 `
  --size 50 `
  --evidence-only
```

Set `OPENROUTER_API_KEY` in the current process, then generate the same selection with a hard cost
cap:

```powershell
python scripts/run-morrowind-item-description-preflight.py `
  --run-dir C:\path\to\morrowind-item-descriptions-50 `
  --size 50 `
  --max-cost 5 `
  --resume
```

The command skips already validated records. Provider-empty or malformed results are quarantined,
and later runs retry only incomplete records. Increasing the completion ceiling does not relax the
13-to-22-word output validator or the total cost cap.
