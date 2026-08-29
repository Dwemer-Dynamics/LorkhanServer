# Morrowind Oghma generator

`scripts/run-morrowind-oghma-preflight.py` creates a review-only Morrowind knowledge and lore catalog in CHIM's Oghma Infinium field format. It does not import PostgreSQL rows, change prompt retrieval, deploy LORKHANserver, or implement Dynamic Oghma.

## Scope

The locked inventory is intentionally curated. It covers stable lore, history, religion, factions, races, cultures, major figures, regions, settlements, significant locations, distinctive creatures, diseases, magic traditions, alchemy, and artifacts. Ordinary NPCs, generic equipment, routine spells, consumables, minor quest objects, player-caused outcomes, and quest-stage knowledge are excluded. Prose is anchored to 3E 427 at the start of Morrowind; Fourth Era history, the Red Year, post-Tribunal institutions, and other future knowledge are rejected.

Important figures and artifacts may carry an exact winning official ESM record link. The generator validates those links against `Morrowind.esm`, `Tribunal.esm`, and `Bloodmoon.esm`; display names are never treated as record identity.

## Expansion coverage audit

Before enlarging the curated inventory, audit all regular dialogue topics in the three official ESMs:

```powershell
python scripts/audit-morrowind-oghma-expansion.py `
  --output-dir build/oghma-expansion-audit
```

The audit does not call a provider or change the active catalog. It compares topic names and aliases against the current seeds, counts official dialogue responses as a review signal, cross-references official actor, item, spell, faction, cell, and region names, and separates likely lore candidates from obvious conversation or mechanics noise. Its JSON, CSV, and Markdown files are review inputs rather than generator-ready seeds; major figures and significant artifacts must still be selected deliberately.

Curate the audit into a resumable selection before generating prose:

```powershell
python scripts/curate-morrowind-oghma-expansion.py `
  --run-dir build/oghma-v2-curation `
  --resume `
  --target-total 550 `
  --minimum-total 500 `
  --maximum-total 700 `
  --max-cost 3
```

The curation runner records every provider attempt, enforces a hard cost gate, and produces combined v1+v2 seeds plus Markdown review. GLM produced 418 additions for editorial review. The final v2 catalog contains 522 topics: all 112 v1 topics and 410 accepted additions. Eight generated additions are rejected in `editorial-decisions.json` as ordinary NPC, generic equipment or resource, minor book, or minor quest-location material; one retained Dwemer machine receives a reviewed classification and temporally stable prose override.

The checkpointed known provider cost is $0.882807 for curation plus $1.353481 for article generation, or $2.236287 total against the $20 goal budget. Review artifacts retain the attempt-level accounting and the conservative uncheckpointed reserve separately.

## Evidence-only inventory run

```powershell
python scripts/run-morrowind-oghma-preflight.py `
  --run-dir C:\path\to\morrowind-oghma-50 `
  --size 50 `
  --evidence-only `
  --resume
```

This locks `selection.json`, hashes the supplied ESMs, ontology, and topic inventory, validates aliases and access classes, and resolves official record links.

Official dialogue responses from the supplied content files are retained as first-party evidence for every expansion topic. Seeds may also name exact UESP pages and revision IDs. The generator then acquires only those locked revisions, records them with the run evidence, and fails review if the returned revision differs.

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
knowledge_class_basic, tags, category, mod_source
```

Advanced and basic articles are separately authored. Knowledge classes are restricted to `resources/oghma/morrowind-official/ontology.json`; aliases must remain collision-free; category and topic identity are locked before generation.

`mod_source` is omitted for the existing base catalog. A mod-only article uses the exact content
filename that makes it available, such as `TR_Mainland.esm`. The server keeps one combined factory
catalog and filters mod-sourced rows against the current turn's OpenMW content list. Tamriel Rebuilt's
article count is determined by the reviewed topic inventory rather than a storage allocation.
Ordinary NPCs, walkthroughs, quest stages, and player-dependent outcomes remain excluded.

Tamriel Rebuilt subject expansions remain review-scoped by content type. The location expansion is
exhaustive for released, unique places documented by UESP's recursive Tamriel Rebuilt place categories
and public location indexes. It is not a raw one-article-per-`CELL` export: duplicate interiors,
redirects, deprecated or testing pages, indexes, and unreleased subjects are excluded with recorded
reasons. Other record types, including books, creatures, and items, require their own inventories.

For major settlements, regions, and other publicly recognizable places, `topic_desc_basic` contains a
short factual description available to `common`. For an obscure place whose whereabouts are not public
knowledge, the basic article is exactly `You do not know where <title> is.` Advanced and basic
access-class lists must not overlap. Mod articles use in-world prose only; coordinates, implementation
details, and references to Tamriel Rebuilt as a mod are rejected.

Build the locked location inventory, generate it resumably, assemble the candidate, and audit the exact
merged output:

```powershell
python scripts/build-tamriel-rebuilt-location-seeds.py `
  --data-dir C:\path\to\morrowind-masters `
  --output-dir build\tamriel-rebuilt-locations

python scripts/run-morrowind-oghma-preflight.py `
  --run-dir build\tamriel-rebuilt-location-generation `
  --data-dir C:\path\to\morrowind-masters `
  --content-file Morrowind.esm `
  --content-file Tribunal.esm `
  --content-file Bloodmoon.esm `
  --content-file TR_Mainland.esm `
  --seeds build\tamriel-rebuilt-locations\location-seeds.json `
  --workers 8 `
  --max-output-tokens 8192 `
  --resume

python scripts/assemble-tamriel-rebuilt-oghma.py `
  --reviewed build\tamriel-rebuilt-location-generation `
  --seeds build\tamriel-rebuilt-locations\location-seeds.json `
  --coverage build\tamriel-rebuilt-locations\coverage.json `
  --curation resources\oghma\morrowind-official\reviews\morrowind-official-3e427-v5.16\location-curation.json `
  --output build\morrowind-official-3e427-v5.16 `
  --catalog-version morrowind-official-3e427-v5.16

python scripts/audit-tamriel-rebuilt-location-catalog.py `
  --base resources\oghma\morrowind-official\catalogs\morrowind-official-3e427-v5.15 `
  --catalog build\morrowind-official-3e427-v5.16 `
  --reviewed build\tamriel-rebuilt-location-generation `
  --seeds build\tamriel-rebuilt-locations\location-seeds.json `
  --coverage build\tamriel-rebuilt-locations\coverage.json `
  --curation resources\oghma\morrowind-official\reviews\morrowind-official-3e427-v5.16\location-curation.json `
  --ontology resources\oghma\morrowind-official\ontology.json `
  --output-dir build\morrowind-official-3e427-v5.16-review
```

## Current factory dataset

The reviewed factory dataset lives under `resources/oghma/morrowind-official/catalogs/<catalog-version>/`. `active-catalog-version.txt` identifies the one checked-in dataset used by the server. Synchronization validates it first, then transactionally replaces only factory rows and current integrity metadata. Custom articles are preserved. Git revert plus redeploy is the rollback path.

Preview or operate the bundled catalog against the configured PostgreSQL database:

```powershell
php scripts/provision-default-oghma.php --plan
php scripts/provision-default-oghma.php
```

The final command synchronizes the dataset named by `active-catalog-version.txt` and is safe to rerun.

Build and audit a candidate before copying it into the versioned resource tree:

```powershell
python scripts/build-morrowind-oghma-catalog.py `
  --reviewed resources/oghma/morrowind-official/catalogs/morrowind-official-3e427-v1 `
  --reviewed build/oghma-v2-generation-part-1 `
  --reviewed build/oghma-v2-generation-part-2 `
  --reviewed build/oghma-v2-generation-part-3 `
  --reviewed build/oghma-v2-generation-part-4 `
  --output build/oghma-v2-catalog-review `
  --catalog-version morrowind-official-3e427-v2 `
  --seeds resources/oghma/morrowind-official/topic-seeds.json

python scripts/audit-morrowind-oghma-catalog.py `
  --catalog build/oghma-v2-catalog-review `
  --seeds resources/oghma/morrowind-official/topic-seeds.json `
  --ontology resources/oghma/morrowind-official/ontology.json `
  --reviewed build/oghma-v2-generation-part-1 `
  --reviewed build/oghma-v2-generation-part-2 `
  --reviewed build/oghma-v2-generation-part-3 `
  --reviewed build/oghma-v2-generation-part-4 `
  --output-dir build/oghma-v2-review
```

Catalog assembly reserves canonical topic keys first and removes ambiguous generated aliases deterministically. Every dropped alias is recorded in the catalog manifest. Review JSON, Markdown, and HTML are bundled under `resources/oghma/morrowind-official/reviews/morrowind-official-3e427-v2/` before activation.

## V4 official-book expansion

The active `morrowind-official-3e427-v4` catalog adds 84 reviewed book-backed lore topics to the v3
catalog. `scripts/audit-morrowind-oghma-books.py` audits winning official BOOK records without
copying their text into repository artifacts. `scripts/build-morrowind-oghma-v4-seeds.py` builds the
curated v4 inventory with exact official BOOK evidence links. The preflight generator reads the
official book text directly from the locally installed ESMs and may supplement it with
revision-addressed UESP evidence.

V4 contains 700 accepted articles and preserves v3's three editorial exclusions. It remains checked in
as a superseded rollback target; the currently selected factory catalog is documented below.

## V5.17 Tamriel Rebuilt full catalog

The active `morrowind-official-3e427-v5.17` catalog contains 3,743 articles, including 2,443 scoped to
`TR_Mainland.esm`. This pass adds 1,177 reviewed Tamriel Rebuilt subjects: 530 books, 491 ingredients,
69 artifacts, 23 creatures, 19 diseases, 15 factions, 12 cultures, 11 races, three magic subjects,
two history subjects, one lore subject, and one religion subject. It also fills the 106 basic-knowledge
gaps left in the v5.16 Tamriel Rebuilt rows.

Every catalog article now has a non-empty basic description and a non-empty basic class. New and
backfilled entries use `common` only for basic access, with no class repeated between advanced and
basic knowledge. Book basics identify the work and its core subject in a short, self-contained form;
creatures and all other accepted categories also carry common knowledge. The run preserves in-world
prose, excludes coordinates and mod or quest language, resolves new record links against the locked
five-master source set, and records $6.918051891 in fully accounted GLM 5.1 provider cost, including
the approved pilot and rejected attempts.

## V5.16 Tamriel Rebuilt location expansion

The superseded `morrowind-official-3e427-v5.16` catalog contains 2,566 articles. Its reviewed location run
covers 1,160 released, unique Tamriel Rebuilt places: 1,053 locations, 71 settlements, and 36 regions.
It emits 1,157 location rows, adds 1,130 topics, and safely replaces 27 earlier `TR_Mainland.esm`
location rows, bringing the catalog to 1,266 mod-sourced rows in total. Necrom, the Padomaic Ocean,
and the Sea of Ghosts reuse their existing canonical base articles instead of creating unreachable
duplicates. All 1,160 source subjects retain revision-locked UESP evidence; 131 generated articles have
factual common basics and 1,029 use the exact ignorance fallback.

The generation manifest records $3.404739 of provider cost. Cost accounting is explicitly incomplete
because 344 earlier provider responses returned usage before a JSON parse failure, while the old runner
discarded that usage. The recorded amount must not be presented as the exact total cost.
