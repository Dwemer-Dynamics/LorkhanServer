# LORKHAN integration runtime reference

This guide covers LorkhanServer's current `unstable` source. Read the [agent guide](agent-guide.md), [protocol](PROTOCOL.md), [building guide](building.md) and [NPC plugin-data contract](plugin-npc-data.md). Use matching OpenMW client/server revisions. The examples are source-integration exercises, not an external plugin SDK.

## Integration points and timing

LORKHAN does not load CHIM-style `ext/*` hooks or `.dwpkg` packages. Creating `prerequest.php` or copying a plugin folder does not register an integration. Use the existing typed providers, repositories and handlers; a new integration needs a reviewed source change, explicit runtime loading and any required protocol coordination.

| Stage | Source boundary | Contract |
|---|---|---|
| Gameplay ingress | [index.php](../index.php), [lib/Http](../lib/Http/), [lib/Protocol](../lib/Protocol/) | Authenticate and validate typed envelopes, IDs, session/generation and idempotency before work. |
| Input/response generation | [processor](../processor/), [prompts](../prompts/) | Persist source input before derived model work; generated output is not playback proof. |
| Scoped NPC state | [NpcPluginDataRepository](../lib/Infrastructure/NpcPluginDataRepository.php) | Uses the existing PDO connection, owning installation UUID and a public positive NPC ID. |
| Job registration | [FirstPartyJobHandlerFactory](../service/FirstPartyJobHandlerFactory.php), [lib/Autoload.php](../lib/Autoload.php) | Explicit first-party composition and runtime class mapping; no arbitrary configuration-loaded PHP. |
| Background execution | [service/Worker.php](../service/Worker.php), [JobRepository](../lib/Infrastructure/JobRepository.php) | Claim jobs with leases, dispatch a matching type/schema handler and record success/retry/dead outcomes. |

## Required identities and optional state

The [v1 schemas](../protocol/schemas/v1/) are authoritative. Unlike CHIM's optional acknowledgement ID, LORKHAN's canonical response-line schema requires `utterance_id`; the typed playback payload in the event schema requires `utterance_id`, `state` and `reason_code`. Do not remove required fields or add unknown fields to make a transplanted plugin fit. Use [valid/invalid fixtures](../protocol/fixtures/v1/) when testing.

Installation, profile, playthrough, session and generation values are separate identities. Do not invent profile/playthrough `0`, substitute display names, or let an unrelated active profile stand in for missing ownership. NPC identity is TES3 record/content/runtime identity, not a Skyrim FormID assumption. Request acceptance, model response, playback receipt and a terminal game-action result are separate milestones.

The repository scopes NPC access to the owning installation's current playthrough through [ProfileScopeSql](../lib/Infrastructure/ProfileScopeSql.php). Missing or out-of-scope NPCs return the documented null/false results; invalid IDs throw. Observe-only diagnostics may tolerate unavailable optional context, but that is not permission to bypass required wire validation or scope a write elsewhere.

`NpcPluginDataRepository` stores authoritative state in `lorkhan_internal.profiles.plugin_extended_data`. Do not directly update the derived `public.core_npc_master.plugin_extended_data` column. A namespace write does not create a new profile revision/history entry; later normal revisions capture the current plugin state. See [playthrough ownership](PLAYTHROUGH-PARITY.md) and [the table policy](../data/playthrough-table-policy.json) before introducing new persistence. A separate Morrowind save alone does not isolate server data.

## Atomic writes

One accepted effect must commit its state, duplicate-prevention record and history together. Make provider calls outside the transaction, then revalidate installation/playthrough/session/generation and current locks before writing.

- Reuse the same PDO connection for all participating repositories. `NpcPluginDataRepository` uses the connection supplied by the caller and does not open its own transaction. [Connection::open()](../lib/Infrastructure/Connection.php) enables PDO exceptions; catch failures and roll back a transaction you own. Also check false returns for inaccessible NPCs.
- Define transaction ownership. PDO transactions do not nest; use a savepoint only when the enclosing caller explicitly permits it. Do not commit a transaction you did not start.
- Use a unique scoped operation key and parameterized values. One utterance can cause multiple listener/subject effects, so an utterance ID alone may be too broad.
- Preserve projection triggers, current-scope restrictions, relationship policy and revision semantics. A namespace replacement is not an automatic combined relationship/history write. Follow the owning repositories rather than editing projection tables by hand.
- [JobRepository::enqueue()](../lib/Infrastructure/JobRepository.php) deduplicates by job type plus idempotency key and rejects a conflicting schema/payload. That enqueue rule does not make arbitrary handler side effects exactly-once.
- The worker invokes the handler and then marks the job successful in a separate call. A crash between those steps can redeliver work. Make the effect idempotent; do not claim the worker automatically commits your effect and its completion together.

The exercise below uses temporary tables to demonstrate the state/dedup/history invariant. It is not a migration or a replacement for LORKHAN's owning repositories. Production schema changes use reviewed, versioned migrations, never runtime ad-hoc table creation.

### Runnable transaction exercise

Run these SQL blocks on **one connection to a disposable PostgreSQL database**. They use temporary tables only; do not substitute core table names. This demonstrates atomic state/history/deduplication without changing a real NPC. Production table ownership, migrations and retention must follow the owning LORKHAN subsystem.

```sql
CREATE TEMP TABLE example_effects (
    scope text NOT NULL, event_id text NOT NULL,
    PRIMARY KEY (scope, event_id)
);
CREATE TEMP TABLE example_affinity (
    scope text NOT NULL, listener_id bigint NOT NULL, subject_id bigint NOT NULL,
    affinity integer NOT NULL CHECK (affinity BETWEEN -100 AND 100),
    PRIMARY KEY (scope, listener_id, subject_id)
);
CREATE TEMP TABLE example_history (
    scope text NOT NULL, event_id text NOT NULL,
    listener_id bigint NOT NULL, subject_id bigint NOT NULL, affinity integer NOT NULL,
    PRIMARY KEY (scope, event_id)
);
```

The example operation key represents one effect on one listener/subject pair. A real plugin must include those identities in its deduplication key when one utterance can produce multiple effects. Parameterize the fixture values when adapting this statement.

```sql
BEGIN;
WITH claimed AS (
    INSERT INTO example_effects (scope, event_id)
    VALUES ('test-session', 'effect-001')
    ON CONFLICT DO NOTHING
    RETURNING scope, event_id
), changed AS (
    INSERT INTO example_affinity AS current (scope, listener_id, subject_id, affinity)
    SELECT scope, 1, 2, 5 FROM claimed
    ON CONFLICT (scope, listener_id, subject_id) DO UPDATE
    SET affinity = LEAST(100, GREATEST(-100, current.affinity + EXCLUDED.affinity))
    RETURNING scope, listener_id, subject_id, affinity
)
INSERT INTO example_history (scope, event_id, listener_id, subject_id, affinity)
SELECT changed.scope, claimed.event_id, listener_id, subject_id, affinity
FROM changed JOIN claimed USING (scope);
COMMIT;
```

After one run, affinity is `5` with one effect and one history row. Repeating the operation leaves those values unchanged. To test rollback, repeat with a new event ID and replace `COMMIT` with `ROLLBACK`; none of that operation's three writes should remain. On any statement error, roll back and report failure rather than continuing to commit or marking the job complete.

## Installation and updates

There is no generic plugin catalog, MO2 `.dwpkg` sync or CHIM tarball route here. The server and OpenMW client are separate deployments.

1. Identify whether existing provider/profile/prompt configuration is enough. For source integrations, specify supported client/server revisions and the explicit interface/registration point.
2. Add runtime classes to the actual loader/composition paths as needed. Composer classmaps do not replace `lib/Autoload.php`.
3. Verify [deploy/runtime-files.txt](../deploy/runtime-files.txt) and stage into an empty temporary directory using [scripts/stage-runtime.sh](../scripts/stage-runtime.sh). Source presence alone does not put a file into an installed runtime.
4. Deploy only through the installation's documented procedure after authorization. Preserve `/etc/lorkhanserver`, `/var/lib/lorkhanserver`, `/var/log/lorkhanserver`, database state and existing mutable web-root storage. See [runtime layout](RUNTIME-LAYOUT.md).
5. Verify the installed revision and a known integration event. Keep one authoritative update route; a stale local sync must not overwrite newer integration code. Removal of client files is not removal of server state.

MO2 warnings for CHIM packages are not a LORKHAN installation contract. Coordinate actual OpenMW actions and schema/fixture changes with the [LORKHAN client](https://github.com/Dwemer-Dynamics/LORKHAN); installing server code alone cannot create a native game capability.

## Background model calls

The existing handler interface is a concrete source integration boundary. This standalone CLI example registers a **dry-run handler only** and dispatches it directly through the actual registry. Run from the source root. It does not enqueue a job, call a provider, write data or install a handler into the production worker:

```php
<?php
require_once getcwd() . '/lib/Autoload.php';
$exampleHandler = new class implements \LorkhanServer\Application\JobHandler {
    public function supports(string $jobType, int $schemaVersion): bool {
        return $jobType === 'example.dry_run' && $schemaVersion === 1;
    }

    // Demonstrate payload and lease validation without persistent side effects.
    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void {
        if ($payload !== ['dry_run' => true] || $idempotencyKey === '') {
            throw new InvalidArgumentException('invalid_example_job');
        }
        if (!$heartbeat()) {
            throw new RuntimeException('lease_lost');
        }
    }
};
$exampleRegistry = new \LorkhanServer\Application\JobHandlerRegistry([$exampleHandler]);
$exampleRegistry->for('example.dry_run', 1)->handle(
    ['dry_run' => true], 'example-operation-001', static fn(): bool => true
);
```

For a real model job, inspect [RelationshipEvaluateJobHandler](../service/RelationshipEvaluateJobHandler.php), its repositories, [FirstPartyJobHandlerFactory](../service/FirstPartyJobHandlerFactory.php), [worker-runner.php](../service/worker-runner.php) and [Worker.php](../service/Worker.php) together. The fixed factory must include the handler before the standard worker can use it; an unknown type/schema fails dispatch.

`JobRepository` claims due work with row locks and leases, checks lease ownership when updating job state, and tracks attempts. The worker claims one job at a time, supplies a heartbeat callback, bounds job count/runtime/idle time, and schedules retries with bounded delay. Handler/provider execution still needs its own timeout and cancellation checks; the outer loop is not a preemptive timeout for a stuck call.

Use stable scoped idempotency keys, bounded payloads and deterministic mocks. Persist immutable source events before enqueueing derived work. Never keep a database transaction open across a provider call. Check heartbeat/lease loss and current generation before committing results, and handle crash-after-commit redelivery. Respect the runtime gate during restore rather than bypassing it from custom code.

The real runner reads `LORKHAN_CONFIG` or `conf/server.php` and can write database/media state and call configured providers. Do not run it as a documentation check. Follow [building.md](building.md) for disposable integration/migration/job suites and use mock providers by default.

## Validation and reports

Lint and run the dry-run handler. In separate scratch checks, call it with an unsupported type/schema, invalid payload and a heartbeat returning false, and verify rejection. Run the SQL exercise in disposable PostgreSQL. Use existing unit/protocol tests and, for persistence changes, the isolated integration and migration/job suites. Stage the runtime separately to verify the guides are shipped.

Record client/server/integration revisions, installation/session/generation correlation, event route and expected/actual outcomes with redacted evidence. Unit, staging and fixture success do not establish OpenMW gameplay, authenticated live ingress or real-provider reliability.
