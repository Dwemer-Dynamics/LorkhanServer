#!/bin/bash
set -euo pipefail

# Build a source-only factory privately, then publish immutable files outside the web root.
# An optional expected major cross-checks the caller's probe. LORKHAN_FACTORY_PREFLIGHT_ONLY=1 stops after
# read-only version/tool checks; =bootstrap checks tools for the expected major because no database exists yet.
if [[ ${EUID} -ne 0 || $# -lt 1 || $# -gt 2 || $1 != /* || ! -f $1/scripts/build-factory-database.sh ]]; then
    echo 'Usage: run as root: deploy-factory-database.sh <absolute-source-root> [15|17]' >&2; exit 2
fi
source_root=$(realpath -- "$1")
expected_major=${2:-}
preflight_only=${LORKHAN_FACTORY_PREFLIGHT_ONLY:-0}
if [[ $preflight_only != 0 && $preflight_only != 1 && $preflight_only != bootstrap ]] || [[ $preflight_only == bootstrap && -z $expected_major ]]; then
    echo 'LORKHAN_FACTORY_PREFLIGHT_ONLY must be 0, 1 or bootstrap (bootstrap requires an expected major).' >&2; exit 2
fi
if [[ -n $expected_major && $expected_major != 15 && $expected_major != 17 ]]; then
    echo "Unsupported PostgreSQL major '${expected_major}'; factory builds support PostgreSQL 15 or 17." >&2; exit 2
fi
if [[ $preflight_only == bootstrap ]]; then
    pg_major=$expected_major
else
    # The artifact must match the server the deployed configuration actually uses; this probe is read-only.
    # Only its DSN selects the server: inherited libpq overrides such as PGHOSTADDR/PGSERVICE are dropped.
    pg_major=$(env -u PGSERVICE -u PGSERVICEFILE -u PGSYSCONFDIR -u PGHOSTADDR -u PGOPTIONS -u PGTARGETSESSIONATTRS \
        php /dev/stdin "$source_root" <<'PHP'
<?php
require $argv[1].'/lib/Autoload.php';
try {
    $config=require '/etc/lorkhanserver/server.php';
    $db=\LorkhanServer\Infrastructure\Connection::open($config,false);
    $number=(string)$db->query('SHOW server_version_num')->fetchColumn();
    if(preg_match('/^[1-9][0-9]{5}$/D',$number)!==1)throw new RuntimeException('version');
    echo intdiv((int)$number,10000),"\n";
} catch (Throwable $error) {
    fwrite(STDERR,"Could not read the configured PostgreSQL server version.\n");exit(1);
}
PHP
)
fi
if [[ $pg_major != 15 && $pg_major != 17 ]]; then
    echo "PostgreSQL ${pg_major} is not supported; factory builds support PostgreSQL 15 or 17. No cluster was changed." >&2; exit 1
fi
if [[ -n $expected_major && $expected_major != "$pg_major" ]]; then
    echo "Configured database uses PostgreSQL ${pg_major}, but PostgreSQL ${expected_major} was expected. Nothing was changed." >&2; exit 1
fi
# Never build a newer-format archive for an older server or mix tools from different majors.
pg_bin=/usr/lib/postgresql/$pg_major/bin
for command in initdb pg_ctl createdb pg_dump pg_restore psql postgres; do
    [[ -x $pg_bin/$command ]] || { echo "PostgreSQL ${pg_major} tools are required to build the factory database for this server." >&2; exit 2; }
done
for extension in pg_trgm vector; do
    if [[ ! -f /usr/share/postgresql/$pg_major/extension/$extension.control || ! -f /usr/lib/postgresql/$pg_major/lib/$extension.so ]]; then
        echo "PostgreSQL ${pg_major} ${extension} extension files are required to build the factory database." >&2; exit 2
    fi
done
[[ $preflight_only == 0 ]] || exit 0
factory_root=/var/lib/lorkhanserver/factory
exec 9>/run/lock/lorkhan-factory-deploy.lock
flock -n 9 || { echo 'Factory deployment is already running.' >&2; exit 1; }
[[ ! -L $factory_root ]] || { echo 'Factory root must not be a symlink.' >&2; exit 1; }
install -d -o root -g www-data -m 0750 "$factory_root"
if [[ -e $factory_root/current && ! -L $factory_root/current ]]; then
    echo 'Factory current must be a deployment-owned symlink.' >&2; exit 1
fi
scratch=$(mktemp -d /tmp/lorkhan-factory-deploy.XXXXXX)
release=
cleanup(){
    case "$scratch" in /tmp/lorkhan-factory-deploy.??????) rm -rf -- "$scratch";; esac
    rm -f -- "$factory_root/.current.$$"
}
trap cleanup EXIT HUP INT TERM
chown postgres:postgres "$scratch"
runuser -u postgres -- env PATH="$pg_bin:/usr/bin:/bin:/usr/local/bin" \
    bash "$source_root/scripts/build-factory-database.sh" "$scratch" > "$scratch/build.log"
release=$(mktemp -d "$factory_root/release.XXXXXX")
chown root:www-data "$release"
chmod 0750 "$release"
for name in factory.dump factory.sql factory.json; do
    install -o root -g www-data -m 0640 "$scratch/$name" "$release/$name"
done
# Only read the deployed schema ledger/configuration; never reset or provision the live database.
php /dev/stdin "$source_root" "$release" <<'PHP'
<?php
require $argv[1].'/lib/Autoload.php';
try {
    $config=require '/etc/lorkhanserver/server.php';
    $db=\LorkhanServer\Infrastructure\Connection::open($config);
    $runner=new \LorkhanServer\Infrastructure\MigrationRunner($db,$argv[1].'/data/migrations');
    $fingerprint=hash('sha256',$runner->replayFingerprint(false)."\0".\LorkhanServer\Infrastructure\FactoryDatabaseArchive::catalogFingerprint());
    \LorkhanServer\Infrastructure\FactoryDatabaseArchive::load($db,$argv[2],$fingerprint);
} catch (Throwable $error) {
    fwrite(STDERR,"Factory verification failed; previous artifact remains active.\n");exit(1);
}
PHP
ln -s -- "$(basename "$release")" "$factory_root/.current.$$"
mv -Tf -- "$factory_root/.current.$$" "$factory_root/current"
echo 'Verified factory artifact deployed; live database unchanged.'
