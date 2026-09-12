#!/bin/bash
set -euo pipefail

# Build a source-only factory privately, then publish immutable files outside the web root.
if [[ ${EUID} -ne 0 || $# != 1 || $1 != /* || ! -f $1/scripts/build-factory-database.sh ]]; then
    echo 'Usage: run as root: deploy-factory-database.sh <absolute-source-root>' >&2; exit 2
fi
source_root=$(realpath -- "$1")
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
runuser -u postgres -- env PATH=/usr/lib/postgresql/15/bin:/usr/bin:/bin:/usr/local/bin \
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
