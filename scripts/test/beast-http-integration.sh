#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
CLIENT_ROOT=${ALMSIVI_CLIENT_ROOT:?set ALMSIVI_CLIENT_ROOT to the active ALMSIVI worktree}
TMP=$(mktemp -d "${TMPDIR:-/tmp}/almsivi-beast-http.XXXXXX")
PG_PORT=${ALMSIVI_BEAST_PG_PORT:-55451}
HTTP_PORT=${ALMSIVI_BEAST_HTTP_PORT:-58451}
BUILD_DIR=${ALMSIVI_BEAST_BUILD_DIR:-$CLIENT_ROOT/build-beast}
PHP_PID=
WORKER_PID=

cleanup() {
    if [ "${ALMSIVI_KEEP_TEST_TMP:-0}" = 1 ]; then printf 'test artifacts retained at %s\n' "$TMP" >&2; return; fi
    if [ -n "$WORKER_PID" ]; then kill "$WORKER_PID" >/dev/null 2>&1 || true; wait "$WORKER_PID" >/dev/null 2>&1 || true; fi
    if [ -n "$PHP_PID" ]; then
        pkill -TERM -P "$PHP_PID" >/dev/null 2>&1 || true
        kill "$PHP_PID" >/dev/null 2>&1 || true
        wait "$PHP_PID" >/dev/null 2>&1 || true
    fi
    pg_ctl -D "$TMP/data" -m immediate stop >/dev/null 2>&1 || true
    rm -rf "$TMP"
}
trap cleanup EXIT HUP INT TERM

initdb -D "$TMP/data" -A trust --no-locale -E UTF8 >/dev/null
pg_ctl -D "$TMP/data" -o "-h 127.0.0.1 -k $TMP -p $PG_PORT" -l "$TMP/postgres.log" start >/dev/null
createdb -h 127.0.0.1 -p "$PG_PORT" almsivi_beast_http
mkdir "$TMP/control" "$TMP/server-state"

DSN="pgsql:host=127.0.0.1;port=$PG_PORT;dbname=almsivi_beast_http"
CONFIG="$ROOT/config/server.test.php"
TOKEN_HASH=0f007385b6f9d4b7eeb2748605afe1a984a0a3bfa3f014d09e2a784ce9e5cd1a
MAC_KEY=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
ALMSIVI_CONFIG="$CONFIG" ALMSIVI_TEST_DSN="$DSN" ALMSIVI_TEST_PROVIDER_CONTROL="$TMP/control" \
ALMSIVI_TEST_SERVER_STATE="$TMP/server-state" php "$ROOT/scripts/migrate.php" up >/dev/null

start_http() {
    PHP_CLI_SERVER_WORKERS=4 ALMSIVI_CONFIG="$CONFIG" ALMSIVI_TEST_DSN="$DSN" \
    ALMSIVI_TEST_PROVIDER_CONTROL="$TMP/control" ALMSIVI_TEST_SERVER_STATE="$TMP/server-state" \
    ALMSIVI_PAIRING_TOKEN_HASH="$TOKEN_HASH" ALMSIVI_PAIRING_MAC_KEY="$MAC_KEY" php -S "127.0.0.1:$HTTP_PORT" -t "$ROOT/public" "$ROOT/public/index.php" \
        >>"$TMP/php.log" 2>&1 &
    PHP_PID=$!
    attempts=0
    until curl -fsS "http://127.0.0.1:$HTTP_PORT/ALMSIVIserver/api/v1/health" >/dev/null 2>&1; do
        if ! kill -0 "$PHP_PID" >/dev/null 2>&1; then
            printf 'PHP test server exited before readiness\n' >&2
            return 1
        fi
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 100 ]; then
            printf 'PHP test server readiness timed out\n' >&2
            return 1
        fi
        sleep 0.05
    done
}

start_http
CLIENT_BIN=${ALMSIVI_BEAST_CLIENT:-}
CONTROL_DIR_ARG="$TMP/control"
if [ -z "$CLIENT_BIN" ]; then
    cmake -S "$CLIENT_ROOT" -B "$BUILD_DIR" -DALMSIVI_WITH_BOOST_BEAST=ON >/dev/null
    cmake --build "$BUILD_DIR" --target almsivi_beast_transport_tests --parallel >/dev/null
    CLIENT_BIN="$BUILD_DIR/components/almsivi/almsivi_beast_transport_tests"
elif [ ! -f "$CLIENT_BIN" ]; then
    printf 'prebuilt Beast client does not exist: %s\n' "$CLIENT_BIN" >&2
    exit 1
elif [ "${CLIENT_BIN##*.}" = exe ]; then
    CONTROL_DIR_ARG=$(wslpath -w "$TMP/control")
fi
ALMSIVI_CONFIG="$CONFIG" ALMSIVI_TEST_DSN="$DSN" ALMSIVI_TEST_PROVIDER_CONTROL="$TMP/control" \
 ALMSIVI_TEST_SERVER_STATE="$TMP/server-state" php "$ROOT/workers/worker.php" >>"$TMP/worker.log" 2>&1 &
WORKER_PID=$!
"$CLIENT_BIN" --live-url "http://127.0.0.1:$HTTP_PORT/ALMSIVIserver/api/v1" --control-dir "$CONTROL_DIR_ARG" &
CLIENT_PID=$!
attempts=0
until [ -f "$TMP/control/server-restart.ready" ]; do
    if ! kill -0 "$CLIENT_PID" >/dev/null 2>&1; then
        wait "$CLIENT_PID"
        exit $?
    fi
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 200 ]; then
        printf 'client did not request HTTP server restart\n' >&2
        exit 1
    fi
    sleep 0.05
done
FIRST_PID=$PHP_PID
pkill -TERM -P "$PHP_PID" >/dev/null 2>&1 || true
kill "${PHP_PID:?}" >/dev/null
wait "$PHP_PID" >/dev/null 2>&1 || true
PHP_PID=
start_http
[ "$PHP_PID" != "$FIRST_PID" ] || { printf 'PHP server PID did not change across restart\n' >&2; exit 1; }
printf 'release\n' >"$TMP/control/server-restart.release"
wait "$CLIENT_PID"
printf 'Beast-to-server disposable PostgreSQL HTTP integration passed\n'
