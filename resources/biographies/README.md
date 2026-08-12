# Factory biography catalog

The reviewed official Morrowind catalog is bundled at `morrowind-official/` after its full generation
and approval gate. A complete package contains `biographies.json`, `manifest.json`, and
`catalog-version.txt`. Deployment validates canonical OpenMW identity and the exact CHIM biography
field order, then provisions the catalog idempotently after migrations.

Factory biographies project into CHIM's `bio_templates` table. Installation-specific edits remain in
typed revisioned profiles and the `bio_templates_custom` compatibility projection.
