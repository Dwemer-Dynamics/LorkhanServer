#!/bin/bash
set -euo pipefail

# Build from source in a private Unix-socket cluster; no deployed credentials or database are used.
if [[ $# != 1 || $(id -u) == 0 ]]; then
    echo 'Usage: run as an unprivileged PostgreSQL-capable user: build-factory-database.sh <empty-output-directory>' >&2
    exit 2
fi
source_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
output=$(realpath -- "$1")
if [[ ! -d $output || -e $output/factory.dump || -e $output/factory.json || -L $output/factory.dump || -L $output/factory.json ]]; then
    echo 'An existing output directory without factory.dump/factory.json is required.' >&2; exit 2
fi
for command in initdb pg_ctl createdb pg_dump pg_restore php python3; do command -v "$command" >/dev/null; done
scratch=$(mktemp -d "${TMPDIR:-/tmp}/lorkhan-factory.XXXXXX")
cleanup(){
    pg_ctl -D "$scratch/data" -m immediate stop >/dev/null 2>&1 || true
    case "$scratch" in */lorkhan-factory.??????) rm -rf -- "$scratch";; esac
}
trap cleanup EXIT HUP INT TERM
umask 0077
initdb -D "$scratch/data" -A trust --no-locale -E UTF8 >/dev/null
pg_ctl -D "$scratch/data" -o "-h '' -k $scratch -p 5432" -l "$scratch/postgres.log" start >/dev/null
createdb -h "$scratch" -p 5432 lorkhan_factory
php "$source_root/scripts/factory-database.php" build "$scratch" > "$scratch/build.json"
pg_dump -h "$scratch" -p 5432 -d lorkhan_factory --format=custom --no-owner --no-privileges --file="$scratch/factory.dump"
createdb -h "$scratch" -p 5432 lorkhan_factory_verify
if ! pg_restore -h "$scratch" -p 5432 -d lorkhan_factory_verify --clean --if-exists --no-owner --no-privileges --exit-on-error "$scratch/factory.dump" > "$scratch/restore.log" 2>&1; then
    tail -n 20 "$scratch/restore.log" >&2; exit 1
fi
php "$source_root/scripts/factory-database.php" verify "$scratch" > "$scratch/verify.json"
cmp "$scratch/build.json" "$scratch/verify.json"
python3 - "$scratch" <<'PY'
import hashlib,json,pathlib,sys
root=pathlib.Path(sys.argv[1]); manifest=json.loads((root/'build.json').read_text())
archive=root/'factory.dump'
digest=hashlib.sha256()
with archive.open('rb') as stream:
    for block in iter(lambda:stream.read(1048576),b''): digest.update(block)
manifest.update(archive_sha256=digest.hexdigest(),archive_bytes=archive.stat().st_size)
(root/'factory.json').write_text(json.dumps(manifest,indent=2)+'\n')
PY
install -m 0640 "$scratch/factory.dump" "$output/factory.dump"
install -m 0640 "$scratch/factory.json" "$output/factory.json"
cat "$scratch/factory.json"
