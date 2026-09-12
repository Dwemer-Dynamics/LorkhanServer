#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/lorkhan-pg.XXXXXX")
PORT=${LORKHAN_TEST_PORT:-55439}

cleanup() {
    pg_ctl -D "$TMP/data" -m immediate stop >/dev/null 2>&1 || true
    rm -rf "$TMP"
}
trap cleanup EXIT HUP INT TERM

initdb -D "$TMP/data" -A trust --no-locale -E UTF8 >/dev/null
if ! pg_ctl -D "$TMP/data" -o "-h 127.0.0.1 -k $TMP -p $PORT" -l "$TMP/postgres.log" start >/dev/null; then
    cat "$TMP/postgres.log" >&2
    exit 1
fi
createdb -h 127.0.0.1 -p "$PORT" lorkhan_test
createdb -h 127.0.0.1 -p "$PORT" lorkhan_migrations_test
mkdir "$TMP/factory"
bash "$ROOT/scripts/build-factory-database.sh" "$TMP/factory"
LORKHAN_TEST_FACTORY_DIR="$TMP/factory" \
LORKHAN_TEST_DSN="pgsql:host=127.0.0.1;port=$PORT;dbname=lorkhan_test" \
LORKHAN_RESPONSE_CAPTURE="${LORKHAN_RESPONSE_CAPTURE:-}" php "$ROOT/tests/integration.php"
LORKHAN_SCHEMA_DSN="pgsql:host=127.0.0.1;port=$PORT;dbname=lorkhan_test" \
php "$ROOT/scripts/schema-inventory.php" "${LORKHAN_SCHEMA_MODE:---check}"
pg_dump -h 127.0.0.1 -p "$PORT" -Fc -f "$TMP/lorkhan.backup" lorkhan_test
createdb -h 127.0.0.1 -p "$PORT" lorkhan_restore_test
pg_restore -h 127.0.0.1 -p "$PORT" -d lorkhan_restore_test --exit-on-error "$TMP/lorkhan.backup"
RESTORED_TABLES=$(psql -h 127.0.0.1 -p "$PORT" -d lorkhan_restore_test -Atc \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'lorkhan_internal' AND table_name IN ('sessions','media_objects','durable_jobs','provider_attempts')")
[ "$RESTORED_TABLES" = "4" ] || { printf 'backup restore schema check failed\n' >&2; exit 1; }
LORKHAN_TEST_DSN="pgsql:host=127.0.0.1;port=$PORT;dbname=lorkhan_migrations_test" \
php "$ROOT/tests/migrations_jobs.php"
