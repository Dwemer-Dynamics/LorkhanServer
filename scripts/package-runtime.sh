#!/usr/bin/env bash
set -euo pipefail

# Package the maintained runtime allowlist, never an installed server or player database.
source_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
output=${1:-}
if [[ $# != 1 || ! -d "$output" || -n $(find "$output" -mindepth 1 -print -quit) ]]; then
    echo 'Usage: package-runtime.sh <empty-existing-output-directory>' >&2
    exit 2
fi
output=$(cd "$output" && pwd)
case "$output/" in "$source_root/"*) echo 'Output must be outside the source tree.' >&2; exit 2 ;; esac
version=$(tr -d '\r\n' < "$source_root/version.txt")
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Invalid version.' >&2; exit 1; }
revision=$(git -C "$source_root" rev-parse HEAD)
epoch=$(git -C "$source_root" show -s --format=%ct HEAD)
[[ -z $(git -C "$source_root" status --porcelain) ]] || { echo 'Commit all source changes before packaging.' >&2; exit 1; }
stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT
mkdir "$stage/LorkhanServer"
bash "$source_root/scripts/stage-runtime.sh" "$source_root" "$stage/LorkhanServer"
printf '%s\n' "$revision" > "$stage/LorkhanServer/source-revision.txt"
archive="LorkhanServer-${version}-runtime.tar.gz"
tar --sort=name --mtime="@$epoch" --owner=0 --group=0 --numeric-owner \
    --mode='u+rwX,go+rX,go-w' -C "$stage" -cf - LorkhanServer | gzip -n > "$output/$archive"
(cd "$output" && sha256sum "$archive" > SHA256SUMS)
printf '{"product":"LorkhanServer","version":"%s","revision":"%s","artifact":"%s"}\n' \
    "$version" "$revision" "$archive" > "$output/release-manifest.json"
echo "Packaged $archive from $revision. This does not install or publish a release."
