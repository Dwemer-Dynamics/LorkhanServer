# Working with LorkhanServer

LorkhanServer is the PHP/PostgreSQL backend for the
[LORKHAN OpenMW/Morrowind client](https://github.com/Dwemer-Dynamics/LORKHAN).
The [server source](https://github.com/Dwemer-Dynamics/LorkhanServer) owns prompts, providers,
profiles, memory and workers. It cannot directly mutate OpenMW actors. Read the
[root instructions](../AGENTS.md) and [building.md](building.md) before changing source.

## Installed server or source checkout?

The source checkout contains tests and development tooling. The installed web root is
assembled by deploy/runtime-files.txt and contains selected operational documentation.
Use the installed revision or deployment record to find matching source before rebuilding.
Links to upstream lorkhan describe current source, which may differ from the installed version.

Both source repositories are public under Dwemer-Dynamics. Use the matching source revision for an installed build. The bundled operational guides remain readable offline.

The usual web root is /var/www/html/LorkhanServer. Persistent configuration and secrets
are under /etc/lorkhanserver; media and credentials under /var/lib/lorkhanserver; logs
under /var/log/lorkhanserver. PostgreSQL owns the database. Existing web-root storage and
vendor directories are preserved by updates. Never replace these with checkout defaults.
See [RUNTIME-LAYOUT.md](RUNTIME-LAYOUT.md) for deployment boundaries.

## Request flow and ownership

The native client sends authenticated lorkhan.*.v1 requests through index.php. Validation
checks pairing, schemas, limits, session/generation, identity and idempotency. Source events
persist before prompt/provider work; provider calls stay outside database transactions.
Ordered dialogue and speech events return to the client. Game actions require negotiated
capabilities, enabled policy and a terminal client result. HTTP acceptance is not game success.
Derived memory/profile/relationship work runs through bounded durable jobs.

| Task | Source path |
| --- | --- |
| Game and management routing | index.php, lib/Http/ |
| Strict contract validation | lib/Protocol/, protocol/ |
| Turn processing and prompts | processor/, prompts/ |
| Custom LLM, text-to-speech or speech-to-text integration | connector/, tts/, stt/ |
| Worker execution and handlers | service/, service/worker-runner.php |
| Database access and migrations | lib/Infrastructure/, data/migrations/ |
| Runtime defaults and loading | conf/server.example.php, lib/Config/ |
| Browser management | ui/ |
| Runtime package contents | deploy/runtime-files.txt, scripts/stage-runtime.sh |

lib/Autoload.php is the runtime loader. Composer classmaps do not replace its explicit
feature-class map. For playthrough ownership and scoped tables, consult
[PLAYTHROUGH-PARITY.md](PLAYTHROUGH-PARITY.md) and data/playthrough-table-policy.json.
Do not treat display names as stable TES3 actor keys or collapse playthrough data.

## Troubleshooting

1. Record client/server revisions, failure time, selected playthrough and symptom.
2. Match request/turn/session/generation IDs between redacted client and server evidence.
3. Inspect /var/log/lorkhanserver, Apache lorkhanserver-error.log and the supervised
   lorkhanserver-worker service/timer. A working health endpoint does not prove worker,
   provider or game delivery success.
4. Check the configured route: direct WSL Apache normally uses port 8090; the Windows
   DwemerDistro launcher proxy uses 7514. Retain local access restrictions and pairing.
5. Preserve configuration, API keys, database, memories and media. Diagnose with mocks
   before making paid provider calls. Never publish raw logs or pairing snippets.

The documentation is readable from disk. It is deliberately not a new public HTTP route.

The Dashboard debugger uses CHIM's log cards and parsers: `lorkhan.log`,
`context_sent_to_llm.log`, `context_sent_to_llm_fast.log`, `output_from_llm.log`,
`output_to_plugin.log` and `stt.log`, plus Apache errors. Fast means internal generation
(CHIM's fast-request path), not the selected conversation model slot; its output is
also retained in `output_from_llm_fast.log`. Plugin output is the actual OpenMW event
response, not proof of playback. Empty polls are not logged. Worker stdout and Apache
access files remain operational logs, outside these cards.

These files contain private conversation text after credential redaction; do not publish
them. Deployment provisions shared worker/Apache permissions outside the web root.
Writes use CHIM's 25 MiB truncation threshold and never replace the shared inode.
Unavailable or busy diagnostic storage does not block gameplay. Tests can redirect
logging with `LORKHAN_LOG_DIR` to an existing directory with precreated log files;
runtime logging never creates a directory or repairs ownership.
Read [WSL-APACHE-SETUP.md](WSL-APACHE-SETUP.md) before installation or update commands;
those commands modify a running system and are not build checks.

## Custom providers, actions and plugins

This server does not provide the HerikaServer ext drop-in plugin contract. Do not copy
an ext directory or assume another product's plugin hooks exist here.

For a custom provider, inspect the maintained
[connector implementations](https://github.com/Dwemer-Dynamics/LorkhanServer/tree/lorkhan/connector),
[speech adapters](https://github.com/Dwemer-Dynamics/LorkhanServer/tree/lorkhan/tts) and
[STT adapters](https://github.com/Dwemer-Dynamics/LorkhanServer/tree/lorkhan/stt).
Follow their interfaces, configuration validation, credential storage and runtime autoload
mapping. Keep URLs and secrets server-owned. Use deterministic mocks in existing tests.
These are source integration points, not a promised external SDK.

For a custom game action, coordinate with the client's
[Lua scripts](https://github.com/Dwemer-Dynamics/LORKHAN/tree/lorkhan/lorkhan/files/scripts/LORKHAN)
and [engine integration boundary](https://github.com/Dwemer-Dynamics/LORKHAN/blob/lorkhan/docs/ENGINE-INTEGRATION-PLAN.md).
Inspect [PROTOCOL.md](PROTOCOL.md), negotiated capabilities and action policy on both sides.
Keep schemas/fixtures byte-identical, reject stale sessions and persist terminal results.
Never emit model-selected code, shell commands, filesystem paths or arbitrary URLs.
Use existing unit, protocol, disposable-database and client tests before any copied-save
game test. No in-game claim follows from server mocks alone.
