#!/usr/bin/env bash
set -euo pipefail

# Assemble a code-only runtime in an empty staging directory; never touch persistent data.
source_root=${1:-}
stage_root=${2:-}
if [[ $# != 2 || ! -f ${source_root}/deploy/runtime-files.txt || ! -d ${stage_root} ]] \
    || [[ -n $(find "${stage_root}" -mindepth 1 -print -quit) ]]; then
    echo "Usage: stage-runtime.sh <source-root> <empty-existing-stage-directory>" >&2
    exit 2
fi
catalog_base=data/oghma/morrowind-official
catalog=$(tr -d '\r\n' < "${source_root}/${catalog_base}/active-catalog-version.txt")
if [[ ! ${catalog} =~ ^[a-zA-Z0-9][a-zA-Z0-9._-]*$ ]]; then
    echo "Invalid active Oghma catalog version." >&2
    exit 1
fi
for file in articles.json manifest.json catalog-version.txt; do
    [[ -f ${source_root}/${catalog_base}/catalogs/${catalog}/${file} ]] || {
        echo "Active Oghma catalog is incomplete: ${file}" >&2; exit 1;
    }
done
rsync -ar --files-from="${source_root}/deploy/runtime-files.txt" \
    --exclude-from="${source_root}/deploy/runtime-excludes.txt" "${source_root}/" "${stage_root}/"
# Retain only the selected catalog; historical catalogs remain available in source for rollback.
# Git archives expand version.txt; source-checkout deployments stamp the same revision here.
if [[ -e ${source_root}/.git ]]; then
    git -C "${source_root}" rev-parse --short HEAD > "${stage_root}/version.txt"
fi
rsync -arR "${source_root}/./${catalog_base}/catalogs/${catalog}/" "${stage_root}/"

# Exercise bundled data loaders before deployment can stop the worker or replace live code.
php /dev/stdin "${stage_root}" <<'PHP'
<?php
require $argv[1].'/lib/Autoload.php';
\LorkhanServer\Infrastructure\PlaythroughTablePolicy::tables();
\LorkhanServer\Infrastructure\FactoryDatabaseArchive::catalogFingerprint();
\LorkhanServer\Application\MorrowindVoiceCatalog::bundled();
\LorkhanServer\Application\MorrowindGeographyCatalog::bundled();
PHP
