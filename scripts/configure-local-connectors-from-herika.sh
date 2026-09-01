#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo 'Run this local setup helper as root.' >&2
    exit 2
fi

source_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "${source_root}"
game_data=${1:-'/mnt/c/Program Files (x86)/Steam/steamapps/common/Morrowind/Data Files'}
config_path=/etc/lorkhanserver/server.php
worker_env=/etc/lorkhanserver/worker.env
for path in "${config_path}" "${worker_env}" "${source_root}/scripts/configure-local-llm-from-herika.sh"; do
    [[ -f ${path} ]] || { echo "Required LORKHAN configuration is missing: ${path}" >&2; exit 1; }
done
[[ -f ${game_data}/Morrowind.esm ]] || { echo "Morrowind Data Files were not found at ${game_data}." >&2; exit 1; }

# Keep the private runtime endpoint and credential synchronized with CHIM first.
bash "${source_root}/scripts/configure-local-llm-from-herika.sh"

IFS=$'\t' read -r tts_driver tts_endpoint < <(
    runuser -u postgres -- psql -d dwemer -At -F $'\t' -c \
        "SELECT t.driver,t.url
         FROM public.core_profiles p
         JOIN public.core_tts_connector t ON t.id=p.tts_connector_id
         WHERE p.slot=1
         ORDER BY p.id
         LIMIT 1"
)
[[ ${tts_driver} == pockettts ]] || { echo "The active CHIM TTS connector (${tts_driver:-missing}) is not PocketTTS." >&2; exit 1; }
[[ ${tts_endpoint} == http://127.0.0.1:8086* ]] || { echo 'The active CHIM PocketTTS endpoint is not the local audio.cpp service.' >&2; exit 1; }
curl --fail --silent --show-error --max-time 5 "${tts_endpoint%/}/health" >/dev/null

export LORKHAN_IMPORTED_TTS_ENDPOINT=${tts_endpoint%/}
set -a
source "${worker_env}"
set +a
export LORKHAN_CONFIG=${config_path}
export LORKHAN_DEFAULT_TTS_ENDPOINT=${LORKHAN_IMPORTED_TTS_ENDPOINT}
php "${source_root}/scripts/provision-default-connectors.php"

php "${source_root}/scripts/import-morrowind-voices.php" "${game_data}"

php <<'PHP'
<?php
declare(strict_types=1);

use LorkhanServer\Application\NeverCancelledToken;
use LorkhanServer\Application\ProviderFactory;
use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\ProductRepository;

require __DIR__ . '/src/Autoload.php';
$config = require (string) getenv('LORKHAN_CONFIG');
if (!is_array($config)) throw new RuntimeException('LorkhanServer configuration is invalid.');
$config['database_password'] = (string) (getenv('LORKHAN_DATABASE_PASSWORD') ?: ($config['database_password'] ?? ''));
$db = Connection::open($config);
$installationId = (string) $db->query("SELECT installation_id FROM installations WHERE revoked_at IS NULL ORDER BY created_at LIMIT 1")->fetchColumn();
$preset = (new ProductRepository($db))->connectorForInstallation($installationId, 'tts_provider');
if ($preset === null) throw new RuntimeException('The active LORKHAN TTS connector is unavailable.');
$speech = ProviderFactory::speechForPreset($config, $preset)->synthesize(
    'LORKHAN PocketTTS connector test.', new NeverCancelledToken(), ['voice'=>'mw_dark_elf_male','language'=>'en']
);
echo json_encode([
    'schema'=>'lorkhan.local-tts-probe.v1','codec'=>$speech['codec'],
    'bytes'=>strlen($speech['bytes']),'duration_ms'=>$speech['duration_ms'],'voice'=>'mw_dark_elf_male',
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
PHP

service lorkhanserver-worker restart >/dev/null
echo 'Configured LORKHAN with the active CHIM LLM and PocketTTS defaults.'
