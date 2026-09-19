# Factory biography catalog

The reviewed official Morrowind catalog is bundled at `morrowind-official/` after its full generation
and approval gate. A complete package contains `biographies.json`, `manifest.json`, and
`catalog-version.txt`. Deployment validates canonical OpenMW identity and the exact CHIM biography
field order, then provisions the catalog idempotently after migrations.

## Voice filters

Biographies can preset `tts_filter_preset` using an exposed Voice Filter ID from
`tts/TtsFilterPresets.php`, for example `warm` or `none`. The NPC Biographies add/edit
dialog includes the same preset selector. New NPC profiles inherit the preset;
editing a biography does not overwrite existing NPC voice-filter choices.

CSV exports include `tts_filter_preset` after `race` (before optional `scope`).
Older CSVs without the column remain supported and preserve an existing template's
filter. A blank CSV value explicitly means `none`. Factory JSON packages may append
`tts_filter_preset` after `refid`; omitted values default to `none`. Invalid or internal
presets are rejected before import. Filter presets travel with biography exports.

Factory biographies project into CHIM's `bio_templates` table. Installation-specific edits remain in
typed revisioned profiles and the `bio_templates_custom` compatibility projection.

## Missing relationship seeds

Start with `scripts/collect-biography-relationship-evidence.py --scope SCOPE.json --cache CACHE_DIR
--output NEW_DIR` (repeat `--cache` for official and Tamriel Rebuilt caches). It performs no network
or provider calls. The catalog hash must match the audited scope. NPC pages require an exact infobox
record ID and matching content namespace; redirects to shared location pages are not accepted.
It writes revision-pinned `sources.jsonl`, all-row `coverage.jsonl`, and `summary.json`.
Linked NPC mentions resolve only through other identity-verified pages. Plain-text full names are
also matched when unique across the catalog, at least five characters long, and backed by a verified
NPC page. These are unreviewed source
candidates, not proof of personal relationships and not input for automatic import. Missing sources
remain explicit coverage gaps. Passages may describe conditional quests or later events and need
review before conversion to the directed evidence input below. Excerpts are bounded to 40 passages
of at most 1600 characters per NPC; this is not an exhaustive lore corpus.

`scripts/prepare-biography-relationship-inputs.py --scope SCOPE.json --sources sources.jsonl
--output NEW_DIR` combines these candidates with explicit, unambiguous named mentions in existing
factory bio, personality, occupation and goals fields. It labels generated bio text as provisional,
prioritizes verified sources, and emits bounded `evidence.json` for the backfill script plus a coverage
audit. It does not infer ties from faction, race or location, reverse another NPC's relationship, or
touch custom/live profiles. Rows without named candidates remain empty. Overflow beyond six targets
is recorded for a later pass. This preparation makes no paid calls and does not enforce a runner's
spending cap; the overnight runner still needs its separate budget gate.

`scripts/run-biography-relationship-batch.py --evidence evidence.json --run-dir NEW_RUN_DIR
--budget 30` runs the prepared candidates with GLM 5.2. Use `--dry-run` first. The single-worker
runner holds an OS lock, pins inputs and code hashes, durably reserves costs before requests and
limits provider token prices. It checkpoints terminal results, retries invalid/transient failures
at most once, and quarantines failures. Unknown request outcomes stop for reconciliation rather
than silently resubmitting. Inspect `status.json`, `ledger.jsonl`, and `results.jsonl`; an empty
relationship response is valid. Do not edit pinned scripts while a run is active. This runner does
not import data, change source catalogs, or push git changes. Final output still requires review,
an importer dry-run, and conditional empty-only live updates preserving existing/custom data.

`scripts/backfill-morrowind-biography-relationships.py` reviews only empty factory relationship maps.
It never touches the live database, custom biographies, populated maps, or the input catalog. Dry-run
is the default. Many empty maps are correct: shared race, faction, or location does not establish a
personal relationship. Player relationships are excluded from this baseline backfill.

Supply a reviewed evidence JSON object keyed by the subject's exact `refid`. Each value is a list of
at most six directed links, each containing `target` (the target's exact catalog `npc_name`), `source`
(a provenance reference), and `excerpt` (facts supporting that specific relationship). Display names
and approximate matches are rejected. Source excerpts are data, not generation instructions. An
empty object `{}` is a valid no-work audit input; no evidence means no generation.

Evidence entries may also include reviewed `best` and `worst` past experiences (up to 180 characters).
Generated experience fields must be empty or copy that exact reviewed event. Opinions, future hopes,
and unfinished quests are not past experiences. Labels are limited to 60 characters and notes to 180.
Role labels and emotions still require human review; schema validation cannot prove factual accuracy.

```powershell
# Audit first; no provider request and no writes.
python scripts/backfill-morrowind-biography-relationships.py --evidence C:/work/relationship-evidence.json

# Only after approving provider spending; bounded to five missing subjects, resumable.
python scripts/backfill-morrowind-biography-relationships.py --evidence C:/work/relationship-evidence.json --generate --limit 5 --checkpoint C:/work/relationship-proposals.jsonl

# Review the checkpoint proposals, then stage a NEW package. This does not deploy it.
python scripts/backfill-morrowind-biography-relationships.py --evidence C:/work/relationship-evidence.json --apply --limit 5 --checkpoint C:/work/relationship-proposals.jsonl --output C:/work/biographies-review --catalog-version morrowind-relationship-review-1
```

Generation reads `OPENROUTER_API_KEY` from the environment by default. `--model` overrides the existing
builder's GLM default. The utility reuses its CHIM schema and normalization rules. Checkpoints bind
proposals to the original row, reviewed evidence, model, and script hash; changed inputs or script
require regeneration. Generation disables reasoning for these short structured records and records
provider usage in checkpoints.
Successful empty proposals are also checkpointed. A provider interruption before checkpointing can
require reviewing provider usage before resuming; there are no automatic retries. The model can still
misinterpret evidence, so manual review remains required before staging/importing.

Run the existing `import-morrowind-biographies.php dry-run` against the staged package before any
separately authorized catalog activation. Catalog deployment is not performed by this utility.

### Reviewed September 2026 relationship backfill

Catalog `morrowind-relationships-2026-09-v2` adds relationships to 258 previously empty factory
biographies. GLM 5.2 processed 933 named-connection candidates; three rate-limited requests were
retried successfully. The final raw outcomes were 273 nonempty and 660 empty. Manual consistency
review corrected labels, direction, name collisions and conditional quest claims; 15 additional
subjects were left empty. Other catalog fields and all existing populated relationships are unchanged.

The batch accounting retains conservative reservations for provider HTTP errors; its final bound
was $0.8840034405 against the approved $30 cap. This is not a provider invoice total. Existing
source/bio evidence does not establish a connection for every NPC: unknown relationships remain empty.

`package-biography-relationship-batch.py` validates a completed run, its pinned inputs, budget ledger,
terminal records and normalized output. `--review` accepts corrections keyed by NPC with `reason`
and `raw` fields; `--exclude` accepts documented exclusions. Original generation output stays intact.
The package includes `relationships-backfill.sql` and uses manifest mode `empty_factory_only`.
Activation updates only empty matching factory relationships, skips custom biographies, and preserves
other columns. The SQL is independently guarded and safe to reapply. Fresh installations still
receive the complete factory catalog. Back up the database before activation; a full catalog rollback
is a separate operation and is not a relationships-only undo.
