# WSL2, Apache, PHP, PostgreSQL setup plan

These commands become reviewed idempotent repository scripts during implementation. Do not execute
them against an existing distribution/database without read-only state checks and a backup decision.

## Target

Use WSL2 Ubuntu LTS x86-64 with systemd, PHP 8.3 where available (8.2 minimum), Apache 2, PostgreSQL
16+ and pgvector. Record Windows build, `wsl --version/status/list --verbose`, `/etc/os-release`,
architecture, PID 1, package versions, disk space and listening sockets in the evidence manifest.

If systemd is not active, preserve existing `/etc/wsl.conf` and add only:

```ini
[boot]
systemd=true
```

Then use `wsl.exe --shutdown`, reopen and verify. WSL1 is unsupported.

## Packages

From Ubuntu/PostgreSQL supported repositories pinned/documented for the selected LTS:

```text
/var/www/html/LorkhanServer/                deployed runtime manifest
/etc/lorkhanserver/                         restrictive configuration/secrets
/var/lib/lorkhanserver/                     media/voices/portraits/backups/credentials
/var/log/lorkhanserver/                     application logs
/var/backups/lorkhanserver-code.*/          previous code and Apache route
```

The dedicated `lorkhan` worker and Apache `www-data` identities retain their existing
permissions. Only persistent runtime paths are writable. Configuration and secrets remain
outside the document root. The source checkout and deployed code are not runtime storage.

Both `scripts/deploy-wsl.sh` (bootstrap) and `scripts/deploy-local-wsl.sh` (updates) install
`deploy/runtime-files.txt` at the same stable path. The old `/var/www/LorkhanServer/releases`
tree is left untouched as historical rollback evidence. The sibling
`LORKHAN/scripts/deploy/full-local.ps1` remains the combined client/server entrypoint.

## PostgreSQL

Create database `lorkhan`, an owner/migration role and a lower-privilege runtime role with locally
generated passwords. Store secrets only in restrictive `/etc/lorkhanserver` files. Bootstrap must:

- verify version/encoding/locale/ownership and unexpected existing state;
- enable `vector` under the migration owner;
- apply source-controlled migrations and safe authored defaults;
- grant runtime only connect/schema usage and required table/sequence/function rights;
- prove runtime cannot create schema objects/read unrelated databases;
- be rerunnable and use a separate disposable test DB in CI.

Provider calls/job publishes never occur inside DB transactions.

## Apache routing and private files

`deploy/apache/lorkhanserver.conf` serves `/LorkhanServer` directly from the stable root on
port 8090; the Windows launcher route remains port 7514. Access is restricted to local
requests and the detected Windows-to-WSL gateway. Only `ui/`, `index.php`, `api/v1/`, and
`manage/` are routed publicly. The API and management handlers retain their authentication.

Install `deploy/apache/lorkhanserver-private.conf` globally as well: other virtual hosts must
not expose the physical tree, and internal directories are denied even on the Lorkhan host.
`conf/`, `lib/`, `data/`, `service/`, feature code, and deployment files are never static downloads.
Run `apache2ctl configtest` before restarting. Check page/assets, API health, authentication,
private directory denial, and worker status after deployment.

## Configuration and pairing

Copy the tracked safe example to `/etc/lorkhanserver`, configure the database, validate
permissions/URLs/limits/schema, migrate, then run a health/self-test. The installer provisions the
CHIM Standard/Fast/Powerful/Experimental model slots and PocketTTS without overwriting existing
connector choices. Generate a 256-bit
pairing token through the setup command; output one restrictive native config snippet, store only its
server hash/fingerprint and redact all later display. Test token rotation and old-session revocation.

Save `LORKHAN_LLM_API_KEY` through the browser API Keys page or the restrictive service environment;
the browser masks it and never returns the stored value. Mock providers remain available for test
flows. The installer provisions one Deepgram STT connector without overwriting an existing selection. ITT, Background Life, and timer-driven autonomy are not provisioned.

## Worker supervision

The independently authored durable worker uses `service/worker-runner.php` with source-controlled handlers,
leases, heartbeats, bounded retries and dead letters. Install the tracked hardened oneshot service and
timer from `deploy/systemd/` only after configuring `/etc/lorkhanserver/worker.env`; the service is not a
placeholder and deliberately exits after bounded work/runtime so systemd can supervise restart.

Adapt narrowly for actual connector/media needs. Worker heartbeat/lease state distinguishes a healthy
web process from unavailable derived processing. Game requests never fork unbounded daemons.

## Acceptance

1. From WSL, run health, pair, session init and deterministic text/media/action-result flow.
2. From Windows PowerShell, run sibling fake client against the same localhost URL.
3. Verify the browser displays the provisioned model slots, PocketTTS, and client/server/schema/DB/worker state.
4. Restart Apache, PostgreSQL, worker and WSL separately; prove bounded failure, idempotent recovery
   and no duplicate completed turn/action.
5. Rotate token, test stale generation/cursor replay, auth/rate/body/media failures and redacted logs.
6. Verify sockets/firewall, file/DB permissions, log rotation, media expiry/quota, backup and restore
   to a new database/release.
7. Save sanitized exact versions/config hashes/server/client commits and test artifacts.

## Upgrade and rollback

The updater lints staged PHP before stopping the worker, records a unique previous-code and
Apache-route copy, then installs only the runtime manifest. Persistent database, credentials,
voice samples and generated data stay outside that mirror. Old source-only folders are removed
from the deployed code tree, not from persistent storage.

For a failed structural upgrade, stop the worker, restore the recorded code and Apache route,
and disable the new private-directory Apache include only if restoring the former `public/`
layout. Validate Apache and restart the worker before checking health. This reorganization adds
no database migrations; never restore or overwrite live data for a code-only rollback.

## Primary references

- <https://learn.microsoft.com/windows/wsl/install>
- <https://learn.microsoft.com/windows/wsl/systemd>
- <https://learn.microsoft.com/windows/wsl/networking>
- <https://ubuntu.com/server/docs/how-to/web-services/install-php/>
- <https://www.postgresql.org/download/linux/ubuntu/>
- <https://github.com/pgvector/pgvector>
