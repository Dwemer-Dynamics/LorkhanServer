# Factory description catalog

The reviewed official catalog is bundled at `morrowind-official/` only after its full generation,
review, and approval gate. A complete package contains `descriptions.csv`, `manifest.json`, and
`catalog-version.txt`. Deployment validates and applies that package idempotently after migrations.

Generated review artifacts and official game data are not stored in this directory during the
preflight or importer phases.
