#!/bin/bash
set -euo pipefail

# Internal worker primitive: run uploaded SQL without host networking, secrets or writable host mounts.
# Stdout is UNTRUSTED JSON data, never SQL suitable for execution or a trusted restore archive.
if [[ ${EUID} -eq 0 || $# != 1 || ! -f $1 || -L $1 ]]; then
    echo 'Usage: unprivileged import-sql-sandbox.sh <private-sql-file>' >&2; exit 2
fi
source_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
input=$(realpath -- "$1")
[[ $(stat -c %s "$input") -le 1073741824 ]] || { echo 'Import exceeds 1 GiB.' >&2; exit 2; }
for command in bwrap timeout python3; do command -v "$command" >/dev/null; done
php_binary=$(readlink -f /usr/bin/php)
[[ -x "$php_binary" ]] || { echo 'PHP runtime unavailable.' >&2; exit 2; }
uid=$(id -u); gid=$(id -g)
# A minimal account database supports initdb without exposing host account files.
exec 3<<<"importer:x:$uid:$gid:SQL importer:/scratch:/bin/sh"
exec 4<<<"importer:x:$gid:"
ulimit -f 2097152
ulimit -v 2097152
exec timeout --kill-after=5s 600s bwrap \
    --unshare-all --unshare-user --disable-userns --die-with-parent --new-session --clearenv \
    --ro-bind /usr/bin /usr/bin --ro-bind /usr/lib /usr/lib --ro-bind /usr/lib64 /usr/lib64 \
    --ro-bind /usr/share/postgresql /usr/share/postgresql --ro-bind /usr/share/zoneinfo /usr/share/zoneinfo \
    --symlink usr/bin /bin --symlink usr/bin /sbin --symlink usr/lib /lib --symlink usr/lib64 /lib64 \
    --dir /etc --ro-bind-data 3 /etc/passwd --ro-bind-data 4 /etc/group \
    --proc /proc --dev /dev --size 1073741824 --tmpfs /scratch --symlink scratch /tmp \
    --ro-bind "$input" /input.sql \
    --ro-bind "$php_binary" /php-runtime \
    --ro-bind "$source_root/scripts/sql-import-reader.py" /reader.py \
    --ro-bind "$source_root/scripts/sql-import-upgrade.php" /upgrade.php \
    --ro-bind "$source_root/lib/Infrastructure/MigrationRunner.php" /MigrationRunner.php \
    --ro-bind "$source_root/lib/Infrastructure/PlaythroughTablePolicy.php" /PlaythroughTablePolicy.php \
    --ro-bind "$source_root/data/playthrough-table-policy.json" /data/playthrough-table-policy.json \
    --ro-bind "$source_root/data/migrations" /migrations \
    --setenv PATH /usr/lib/postgresql/15/bin:/usr/bin:/bin --setenv HOME /scratch \
    --setenv LANG C --setenv USER importer --setenv LOGNAME importer \
    --chdir /scratch --remount-ro / -- python3 /reader.py
