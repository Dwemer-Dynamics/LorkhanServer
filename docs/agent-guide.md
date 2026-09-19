# Working with LorkhanServer

LorkhanServer is the PHP/PostgreSQL backend for the
[LORKHAN OpenMW/Morrowind client](https://github.com/RANGROO/LORKHAN).
The [server source](https://github.com/RANGROO/LorkhanServer) owns prompts, providers,
profiles, memory and workers. It cannot directly mutate OpenMW actors. Read the
[root instructions](../AGENTS.md) and [building.md](building.md) before changing source.

## Installed server or source checkout?

The source checkout contains tests and development tooling. The installed web root is
assembled by deploy/runtime-files.txt and contains selected operational documentation.
Use the installed revision or deployment record to find matching source before rebuilding.
Links to upstream main describe current source, which may differ from the installed version.

Both linked source repositories are currently private. GitHub source and custom-plugin
example links require authorized access. Without it, ask the maintainer for access or a
complete source archive matching the installed revision. No public source download is
provided here; the bundled operational guides remain readable offline.

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
Read [WSL-APACHE-SETUP.md](WSL-APACHE-SETUP.md) before installation or update commands;
those commands modify a running system and are not build checks.

## Custom providers, actions and plugins

This server does not provide the HerikaServer ext drop-in plugin contract. Do not copy
an ext directory or assume another product's plugin hooks exist here.

For a custom provider, inspect the maintained
[connector implementations](https://github.com/RANGROO/LorkhanServer/tree/main/connector),
[speech adapters](https://github.com/RANGROO/LorkhanServer/tree/main/tts) and
[STT adapters](https://github.com/RANGROO/LorkhanServer/tree/main/stt).
Follow their interfaces, configuration validation, credential storage and runtime autoload
mapping. Keep URLs and secrets server-owned. Use deterministic mocks in existing tests.
These are source integration points, not a promised external SDK.

For a custom game action, coordinate with the client's
[Lua scripts](https://github.com/RANGROO/LORKHAN/tree/main/lorkhan/files/scripts/LORKHAN)
and [engine integration boundary](https://github.com/RANGROO/LORKHAN/blob/main/docs/ENGINE-INTEGRATION-PLAN.md).
Inspect [PROTOCOL.md](PROTOCOL.md), negotiated capabilities and action policy on both sides.
Keep schemas/fixtures byte-identical, reject stale sessions and persist terminal results.
Never emit model-selected code, shell commands, filesystem paths or arbitrary URLs.
Use existing unit, protocol, disposable-database and client tests before any copied-save
game test. No in-game claim follows from server mocks alone.
