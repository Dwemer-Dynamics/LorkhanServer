#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/almsivi-management-http.XXXXXX")
PG_PORT=${ALMSIVI_MANAGEMENT_PG_PORT:-55463}
HTTP_PORT=${ALMSIVI_MANAGEMENT_HTTP_PORT:-58463}
PHP_PID=
cleanup(){ [ -z "$PHP_PID" ] || kill "$PHP_PID" >/dev/null 2>&1 || true; pg_ctl -D "$TMP/data" -m immediate stop >/dev/null 2>&1 || true; rm -rf "$TMP"; }
trap cleanup EXIT HUP INT TERM
initdb -D "$TMP/data" -A trust --no-locale -E UTF8 >/dev/null
pg_ctl -D "$TMP/data" -o "-h 127.0.0.1 -k $TMP -p $PG_PORT" -l "$TMP/postgres.log" start >/dev/null
createdb -h 127.0.0.1 -p "$PG_PORT" almsivi_management_http
mkdir "$TMP/control" "$TMP/state"
DSN="pgsql:host=127.0.0.1;port=$PG_PORT;dbname=almsivi_management_http"
CONFIG="$ROOT/config/server.test.php"
TOKEN_HASH=0f007385b6f9d4b7eeb2748605afe1a984a0a3bfa3f014d09e2a784ce9e5cd1a
MANAGE_HASH=$(printf manage-secret | shasum -a 256 | cut -d' ' -f1)
ALMSIVI_CONFIG="$CONFIG" ALMSIVI_TEST_DSN="$DSN" ALMSIVI_TEST_PROVIDER_CONTROL="$TMP/control" php "$ROOT/scripts/migrate.php" up >/dev/null
psql -h 127.0.0.1 -p "$PG_PORT" -d almsivi_management_http -v ON_ERROR_STOP=1 -c "INSERT INTO almsivi_internal.installations(installation_id,token_fingerprint) VALUES ('00000000-0000-4000-8000-000000000001','$TOKEN_HASH')" >/dev/null
PHP_CLI_SERVER_WORKERS=2 ALMSIVI_CONFIG="$CONFIG" ALMSIVI_TEST_DSN="$DSN" ALMSIVI_TEST_PROVIDER_CONTROL="$TMP/control" \
 ALMSIVI_PAIRING_TOKEN_HASH="$TOKEN_HASH" ALMSIVI_MANAGEMENT_SECRET_HASH="$MANAGE_HASH" \
 php -S "127.0.0.1:$HTTP_PORT" -t "$ROOT/public" "$ROOT/public/index.php" >"$TMP/php.log" 2>&1 &
PHP_PID=$!
i=0; until curl -fsS "http://127.0.0.1:$HTTP_PORT/ALMSIVIserver/api/v1/health" >/dev/null 2>&1; do i=$((i+1)); if [ "$i" -ge 100 ]; then python3 -c 'import sys;print(open(sys.argv[1]).read())' "$TMP/php.log" >&2; exit 1; fi; sleep .05; done
if ! python3 "$ROOT/scripts/test/management_http.py" "http://127.0.0.1:$HTTP_PORT"; then
  cat "$TMP/php.log" >&2
  exit 1
fi
