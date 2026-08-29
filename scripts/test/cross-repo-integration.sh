#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
ACTIVE_CLIENT=${LORKHAN_CLIENT_ROOT:?set LORKHAN_CLIENT_ROOT to the active LORKHAN worktree}
PYTHON=${LORKHAN_JSONSCHEMA_PYTHON:-python3}
CAPTURE=$(mktemp "${TMPDIR:-/tmp}/lorkhan-responses.XXXXXX")
cleanup() { rm -f "$CAPTURE"; }
trap cleanup EXIT HUP INT TERM

"$ROOT/scripts/verify-protocol-parity.sh" "$ACTIVE_CLIENT"
"$PYTHON" "$ACTIVE_CLIENT/scripts/protocol/validate.py" --require-jsonschema
LORKHAN_RESPONSE_CAPTURE="$CAPTURE" "$ROOT/scripts/test/integration.sh"
"$PYTHON" "$ROOT/scripts/test/validate-responses.py" "$CAPTURE"
printf 'cross-repository no-game vertical slice passed\n'
