# Morrowind Oghma generator

`scripts/run-morrowind-oghma-preflight.py` creates a review-only Morrowind knowledge and lore catalog in CHIM's Oghma Infinium field format. It does not import PostgreSQL rows, change prompt retrieval, deploy ALMSIVIserver, or implement Dynamic Oghma.

## Scope

The locked inventory is intentionally curated. It covers stable lore, history, religion, factions, races, cultures, major figures, regions, settlements, significant locations, distinctive creatures, diseases, magic traditions, alchemy, and artifacts. Ordinary NPCs, generic equipment, routine spells, consumables, minor quest objects, player-caused outcomes, and quest-stage knowledge are excluded. Prose is anchored to 3E 427 at the start of Morrowind; Fourth Era history, the Red Year, post-Tribunal institutions, and other future knowledge are rejected.

Important figures and artifacts may carry an exact winning official ESM record link. The generator validates those links against `Morrowind.esm`, `Tribunal.esm`, and `Bloodmoon.esm`; display names are never treated as record identity.

## Evidence-only inventory run

```powershell
python scripts/run-morrowind-oghma-preflight.py `
  --run-dir C:\path\to\morrowind-oghma-50 `
  --size 50 `
  --evidence-only `
  --resume
```

This locks `selection.json`, hashes the official ESMs, ontology, and topic inventory, validates aliases and access classes, resolves official record links, and stores UESP page/revision evidence when found.

## GLM review generation

Set `OPENROUTER_API_KEY` in the current process, then resume the locked run:

```powershell
python scripts/run-morrowind-oghma-preflight.py `
  --run-dir C:\path\to\morrowind-oghma-50 `
  --size 50 `
  --max-cost 5 `
  --resume
```

The default model is `z-ai/glm-5.1`. Every topic is checkpointed independently, provider attempts and cost are retained, failed articles receive up to three focused repair attempts, and persistent failures are quarantined. The combined folder contains UTF-8 JSON, a UTF-8 BOM CHIM-format CSV, Markdown review, and HTML review.

The output columns are:

```text
topic, aliases, topic_desc, knowledge_class, topic_desc_basic,
knowledge_class_basic, tags, category
```

Advanced and basic articles are separately authored. Knowledge classes are restricted to `resources/oghma/morrowind-official/ontology.json`; aliases must remain collision-free; category and topic identity are locked before generation.
