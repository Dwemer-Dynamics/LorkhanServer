#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/lorkhan-jobs-pg.XXXXXX")
PORT=${LORKHAN_JOBS_TEST_PORT:-55440}

cleanup() {
    pg_ctl -D "$TMP/data" -m immediate stop >/dev/null 2>&1 || true
    rm -rf "$TMP"
}
trap cleanup EXIT HUP INT TERM

initdb -D "$TMP/data" -A trust --no-locale -E UTF8 >/dev/null
pg_ctl -D "$TMP/data" -o "-h 127.0.0.1 -k $TMP -p $PORT" -l "$TMP/postgres.log" start >/dev/null
createdb -h 127.0.0.1 -p "$PORT" lorkhan_jobs_test
LORKHAN_TEST_DSN="pgsql:host=127.0.0.1;port=$PORT;dbname=lorkhan_jobs_test" \
    php "$ROOT/tests/migrations_jobs.php"
