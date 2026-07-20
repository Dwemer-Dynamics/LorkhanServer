#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CLIENT=${1:-${ALMSIVI_CLIENT_ROOT:-}}
if [ -z "$CLIENT" ]; then
    printf 'error: pass the active ALMSIVI root or set ALMSIVI_CLIENT_ROOT\n' >&2
    exit 2
fi
CLIENT_PROTOCOL=${ALMSIVI_CLIENT_PROTOCOL:-"$CLIENT/almsivi"}

if [ ! -d "$CLIENT_PROTOCOL/schemas" ] || [ ! -d "$CLIENT_PROTOCOL/fixtures" ]; then
    printf 'error: client protocol tree not available at %s\n' "$CLIENT_PROTOCOL" >&2
    exit 2
fi

for path in schemas fixtures MANIFEST.json SHA256SUMS; do
    if ! diff -qr "$CLIENT_PROTOCOL/$path" "$ROOT/protocol/$path"; then
        printf 'error: protocol parity failed for %s\n' "$path" >&2
        exit 1
    fi
done

printf 'protocol schemas, fixtures, and manifests are byte-identical\n'
