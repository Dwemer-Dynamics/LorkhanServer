# Developing and validating LorkhanServer

Start from https://github.com/Dwemer-Dynamics/LorkhanServer at the required revision. An installed
runtime lacks the full test/development tree. Read root AGENTS.md and the
[agent guide](agent-guide.md). There is no native server DLL to compile.

## Requirements

composer.json requires PHP 8.2+ with json, curl, ctype, mbstring, PDO and pdo_pgsql.
The application uses PostgreSQL/pgvector. The isolated integration suite specifically
requires PostgreSQL 15 or 17 tools with pg_trgm and pgvector, Python 3, Bubblewrap and enabled
unprivileged user namespaces. Read scripts/test/integration.sh before choosing an isolated test host.
Use Bash/Linux or WSL for shell suites. Composer is optional; lib/Autoload.php loads runtime
classes. Production dependency and service setup is separate from source validation.

Put exactly one major on PATH, for example `PATH=/usr/lib/postgresql/17/bin:$PATH`, before running
database suites or scripts/build-factory-database.sh. Never mix binaries from different majors.

- The SQL-import sandbox uses a complete PostgreSQL 17 toolset (server binaries, pg_trgm and
  pgvector) and falls back to a complete 15 toolset only when 17 is incomplete. It refuses
  custom-format archives, unrecognized `-- Dumped ... version` headers and dumps from a newer
  major than the selected tools, so a PostgreSQL 17 dump is never replayed with 15 tools.
- scripts/deploy-factory-database.sh reads `SHOW server_version_num` through the deployed
  configuration and builds with the matching /usr/lib/postgresql/15 or 17 tools. Other
  majors, missing tools or a mismatch with the caller's expected major stop before building.
- scripts/deploy-local-wsl.sh performs the PostgreSQL version and factory-tool preflight
  before bootstrap or any file, account, service or database change. It never installs
  packages or upgrades, downgrades or migrates a cluster.
- Both deploy scripts pin PostgreSQL administration to the local cluster at socket
  /var/run/postgresql port 5432 and ignore inherited PG* service/host variables. Preflight
  requires that cluster to listen on 127.0.0.1:5432, the bootstrap DSN target. It also
  requires an existing /etc/lorkhanserver/server.php to name 127.0.0.1 (or localhost), port 5432,
  dbname lorkhan. Any other target is refused unchanged, never redirected.

## Run from the source root

```bash
php tests/run.php
php scripts/protocol/verify-local.php
bash scripts/verify-protocol-parity.sh /absolute/path/to/LORKHAN
```

Lint changed PHP files with php -l. For persistence, workers or protocol changes, run the
relevant existing suites as an unprivileged account with disposable databases:

```bash
bash scripts/test/integration.sh
bash scripts/test/migrations-jobs.sh
LORKHAN_CLIENT_ROOT=/absolute/path/to/LORKHAN bash scripts/test/cross-repo-integration.sh
```

Inspect each script's required environment and prerequisites first. Never point tests at
an active player database. Use mock providers; live provider tests require separate scope
and credentials. A passing unit suite is not WSL, browser or game acceptance.

## Verify installed documentation without deployment

Use an empty temporary directory as the second argument to scripts/stage-runtime.sh:

```bash
stage=$(mktemp -d)
bash scripts/stage-runtime.sh "$PWD" "$stage"
test -f "$stage/AGENTS.md"
test -f "$stage/docs/agent-guide.md"
test -f "$stage/docs/building.md"
```

Staging needs rsync, PHP and the valid bundled active Oghma catalog. It assembles code
and selected documentation, validates bundled loaders and does not install services.
The explicit deploy/runtime-files.txt manifest is shared by bootstrap and updates;
add any newly required runtime file there. Keep source-only tests and historical docs out.

Actual installation/update uses scripts/deploy-wsl.sh or scripts/deploy-local-wsl.sh;
the companion client's scripts/deploy/full-local.ps1 coordinates both products. These
change runtime files/services and can migrate data, so inspect current state and backups
before a separately requested deployment. Preserve credentials, profiles, playthroughs,
voice samples and media. Both first run the read-only scripts/check-php-runtime-security.sh
and stop before any change unless the PHP CLI and Apache PHP runtime are patched
([PHP security preflight](WSL-APACHE-SETUP.md#php-security-preflight)). Follow [WSL-APACHE-SETUP.md](WSL-APACHE-SETUP.md),
[RUNTIME-LAYOUT.md](RUNTIME-LAYOUT.md) and data/migrations/README.md.
