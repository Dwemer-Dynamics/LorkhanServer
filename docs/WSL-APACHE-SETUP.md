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
apache2
libapache2-mod-php (or reviewed php-fpm setup)
php php-cli php-curl php-gd php-mbstring php-pgsql php-xml php-zip
composer
postgresql-16 postgresql-client-16 postgresql-16-pgvector
ffmpeg curl ca-certificates unzip
```

Enable only required Apache modules, normally `rewrite`, `headers` and the chosen PHP handler. Pin
Composer/dependency locks. Run `apache2ctl configtest` before every reload.

## Files and identities

```text
/var/www/LorkhanServer/releases/<version>   immutable source/vendor/built UI
/var/www/LorkhanServer/current              atomic symlink
/etc/lorkhanserver/                         restrictive config/secrets
/var/lib/lorkhanserver/                     media/job/runtime state
/var/log/lorkhanserver/                     application logs
/var/backups/lorkhanserver/                 encrypted/policy-controlled backups
```

Use a dedicated `lorkhanserver` worker/service identity and deliberate Apache read/write groups.
Only media/runtime/log paths are writable. Config/secrets must reject group/world write and never be
under the document root. Git checkout is not a writable production release.

For local Dwemer development, `scripts/deploy-local-wsl.sh` intentionally mirrors the active source
to `/var/www/html/LorkhanServer`, matching the HerikaServer/DialecticServer workstation layout. It
keeps the same `/etc`, `/var/lib`, and `/var/log` persistence boundaries and leaves the immutable
release tree intact. The sibling `LORKHAN/scripts/deploy/full-local.ps1` is the normal two-stage local
entrypoint. `scripts/deploy-wsl.sh` remains the immutable release/rollback workflow described here.

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

## Apache loopback vhost

Ship a checked-in template equivalent to:

```apache
Listen 127.0.0.1:8090

<VirtualHost 127.0.0.1:8090>
    ServerName lorkhanserver.local
    DocumentRoot /var/www/html
    Alias /LorkhanServer /var/www/LorkhanServer/current/public

    <Directory /var/www/LorkhanServer/current/public>
        Options -Indexes -ExecCGI
        AllowOverride None
        Require local
        DirectoryIndex ui/home.php index.php
    </Directory>

    LimitRequestBody 33554432
    ErrorLog ${APACHE_LOG_DIR}/lorkhanserver-error.log
    CustomLog ${APACHE_LOG_DIR}/lorkhanserver-access.log combined
</VirtualHost>
```

Application endpoints enforce tighter individual caps. Deny source, config, dotfiles, vendor
metadata, storage, logs, backups and directory listing. Verify Apache listens only on loopback inside
WSL and PostgreSQL is not externally reachable. If Windows localhost forwarding is unavailable on the
recorded WSL version, use Microsoft's documented networking configuration; never bind to LAN as a
shortcut.

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

The independently authored durable worker uses `workers/worker.php` with source-controlled handlers,
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

Create a database/config/runtime metadata backup, build/audit a new immutable release, install locked
dependencies, enter maintenance if schema requires, migrate, atomically switch `current`, reload and
run smoke tests. Roll back code only when schema is compatible; otherwise restore backup into a new
database, verify and switch explicitly. Never overwrite the sole backup or the previous release.

## Primary references

- <https://learn.microsoft.com/windows/wsl/install>
- <https://learn.microsoft.com/windows/wsl/systemd>
- <https://learn.microsoft.com/windows/wsl/networking>
- <https://ubuntu.com/server/docs/how-to/web-services/install-php/>
- <https://www.postgresql.org/download/linux/ubuntu/>
- <https://github.com/pgvector/pgvector>
