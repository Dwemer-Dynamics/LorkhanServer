# Dynamic profile parity

Reference: Dwemer-Dynamics/HerikaServer unstable 68dd8544, especially scheduler commits 72de76f17 and 294bfae06. CHIM unstable 002e224 was also inspected. Reference repositories were not changed.

## Adaptation

- Use game days, per-NPC delivered events and real-minute cooldown together. Combat barks remain excluded from the event threshold.
- In-game targeted, nearby and narrator requests use selected-field evolution, not whole-profile regeneration.
- Retain manual requests until a matching successful commit. Explicit requests bypass scheduling thresholds; failed requests retry after cooldown, at most three attempts within one hour. A newer token survives an older completion.
- Compare meaningful identity, profile assignment, enabled/locked state, field values, field selection, schedule policy and history limit. Merge generated fields into the current revision so unrelated gameplay metadata survives.
- Keep LORKHAN typed jobs, lease fencing, source provenance and loaded-save epochs. Automatic retries are scheduler-owned rather than immediate durable-job retries.
- Match CHIM schedule labels, copy and responsive layout inside Dynamic Profile Fields; Narration uses the same helper. Preserve narrator schedule inheritance.
- Include pending NPC editor requests: editable name/ref ID, remove content-file and voice-language controls, inherit NPC voice language from its connector, and resolve Visit/Teleport/Return through verified game bindings.

## Verification

- 1,539 server checks and 110 client Lua tests passed.
- Isolated PostgreSQL feature integration passed, including manual cooldown/retry/token isolation, selected-field/policy conflict rejection, unrelated metadata preservation, narrator updates and NPC action binding safety.
- Fresh schema reached all 127 migrations; schema inventory regenerated. The broader migrations/jobs suite later stopped at its unrelated manual-diary fixture (missing narrative result, line 1520).
- Local server deployed with existing configuration, database and media preserved. Eleven changed runtime file hashes match; health endpoint succeeds and migration columns exist.
- Profiles schedule screenshot reviewed; Profiles and Narration controls inspected in the local browser without saving live settings.
- Broader factory-enabled integration passed reset/rollback checks but later stopped at the existing active-session fixture dependency. The feature-only integration run passed; do not interpret this as a full factory-suite pass.
- No live LLM calls or in-game behavior were exercised. No game launch/restart was performed.
- Claude UI delegation was unavailable due to authentication status failure; UI adaptation and review completed directly.
