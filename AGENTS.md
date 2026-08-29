# LORKHANserver engineering rules

## Scope and lineage

- Read the root/sibling assignments and all server docs before importing code.
- Import only from the final tested Synthserver SHA recorded after its completion gate.
- Preserve source licenses/notices/history where practical and record every imported/derived file.
- Translate Fallout/Skyrim concepts to TES3/OpenMW; do not leave compatibility aliases or misleading
  labels merely to make tests pass.

## Runtime and data safety

- Bind Apache to loopback by default. Require the native pairing token on every `/api/v1` endpoint
  except minimal health and never accept it in query strings.
- Keep provider/database secrets outside Git and browser/client payloads. Redact structured logs.
- Use PostgreSQL migrations and generated/typed data access conventions inherited from the final
  Synthserver; no runtime ad-hoc schema creation.
- Persist immutable source events before derived jobs. Workers are idempotent, bounded and visible.
- Validate strict schemas, size/rate/idempotency/session/generation/action policy at ingress.
- Store media under opaque IDs outside the web root; serve only authenticated allowlisted audio with
  hash/size/type metadata and expiry.
- Never store/upload game data, saves, content archives, credentials or unbounded provider payloads.

## Cross-repository contract

- `lorkhan.*.v1` schemas and fixtures must match sibling LORKHAN by manifest hash.
- TES3 identity is record ID + content source/order + runtime RefNum/FormId + cell where applicable;
  names are not keys.
- An action is incomplete until one terminal client result is persisted. HTTP acceptance is not game
  success.
- Server never emits arbitrary code, paths or URLs and never selects an action absent from negotiated
  client capabilities and enabled policy.

## Working method and proof

- Keep changes focused, migrations reversible where possible, and tests beside behavior.
- Use mock providers by default. Live provider tests require separate credentials/authorization and
  must not record sensitive prompts/audio.
- Run PHP/static/unit/integration/migration/schema/UI build tests plus sibling contract E2E.
- Track completion rows with evidence. Do not claim WSL/browser/game integration from unit tests.
- Do not push, publish, deploy or modify reference repos without separate authorization.
