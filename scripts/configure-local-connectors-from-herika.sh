#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo 'Run this local setup helper as root.' >&2
    exit 2
fi

source_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "${source_root}"
game_data=${1:-'/mnt/c/Program Files (x86)/Steam/steamapps/common/Morrowind/Data Files'}
config_path=/etc/almsiviserver/server.php
worker_env=/etc/almsiviserver/worker.env
for path in "${config_path}" "${worker_env}" "${source_root}/scripts/configure-local-llm-from-herika.sh"; do
    [[ -f ${path} ]] || { echo "Required ALMSIVI configuration is missing: ${path}" >&2; exit 1; }
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

export ALMSIVI_IMPORTED_TTS_ENDPOINT=${tts_endpoint%/}
set -a
source "${worker_env}"
set +a
export ALMSIVI_CONFIG=${config_path}

php <<'PHP'
<?php
declare(strict_types=1);

use ALMSIVIserver\Application\DeterministicClock;
use ALMSIVIserver\Application\ProductService;
use ALMSIVIserver\Infrastructure\Connection;
use ALMSIVIserver\Infrastructure\ProductRepository;

require __DIR__ . '/src/Autoload.php';

$config = require (string) getenv('ALMSIVI_CONFIG');
if (!is_array($config)) throw new RuntimeException('ALMSIVI server configuration is invalid.');
$config['database_password'] = (string) (getenv('ALMSIVI_DATABASE_PASSWORD') ?: ($config['database_password'] ?? ''));
$db = Connection::open($config);
$installations = $db->query("SELECT installation_id FROM installations WHERE revoked_at IS NULL ORDER BY created_at")->fetchAll();
if (count($installations) !== 1) throw new RuntimeException('Local connector setup requires exactly one active installation.');
$installationId = (string) $installations[0]['installation_id'];
$repository = new ProductRepository($db);
$service = new ProductService($repository, new DeterministicClock());

/** Create or revise one named connector without removing the user's other saved connectors. */
$upsert = static function (string $kind, string $name, array $content) use ($db, $installationId, $service): string {
    $find = $db->prepare('SELECT c.configuration_id,r.content FROM configuration_sets c '
        . 'JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision '
        . 'WHERE c.installation_id=:installation AND c.kind=:kind AND c.name=:name AND c.deleted_at IS NULL '
        . 'ORDER BY c.created_at LIMIT 1');
    $find->execute(['installation'=>$installationId,'kind'=>$kind,'name'=>$name]);
    $existing = $find->fetch();
    if (!$existing) {
        $created = $service->createRevisioned($kind, [
            'installation_id'=>$installationId,'name'=>$name,'content'=>$content,
        ]);
        return (string) $created['configuration_id'];
    }
    $id = (string) $existing['configuration_id'];
    $stored = json_decode((string) $existing['content'], true, 32, JSON_THROW_ON_ERROR);
    if ($stored != $content) $service->revise($kind, $id, $content, 'Synchronize active CHIM default');
    return $id;
};

$model = trim((string) ($config['provider']['model'] ?? ''));
if ($model === '') throw new RuntimeException('The imported CHIM LLM model is unavailable.');
$llmId = $upsert('provider', 'CHIM Default', ['driver'=>'configured','model'=>$model]);
$ttsEndpoint = (string) getenv('ALMSIVI_IMPORTED_TTS_ENDPOINT');
$ttsContent = [
    'driver'=>'pockettts','endpoint'=>$ttsEndpoint,'model'=>'pocket-tts','voice'=>'alba',
    'language'=>'en','timeout_ms'=>30000,
    'options'=>['fallback_male'=>'mw_dark_elf_male','fallback_female'=>'mw_dark_elf_female'],
];
$ttsId = $upsert('tts_provider', 'CHIM PocketTTS', $ttsContent);
$service->selectConnector(['installation_id'=>$installationId,'kind'=>'tts_provider','configuration_id'=>$ttsId]);

$default = $db->prepare('SELECT c.core_profile_id,c.label,c.default_npc,c.slot,c.current_revision,r.content '
    . 'FROM core_profiles c JOIN core_profile_revisions r ON r.core_profile_id=c.core_profile_id AND r.revision=c.current_revision '
    . 'WHERE c.installation_id=:installation AND c.deleted_at IS NULL ORDER BY c.default_npc DESC,c.created_at LIMIT 1');
$default->execute(['installation'=>$installationId]);
$profile = $default->fetch();
if (!$profile) throw new RuntimeException('The default Core Profile is unavailable.');
$content = json_decode((string) $profile['content'], true, 64, JSON_THROW_ON_ERROR);
$storedContent = $content;
$routing = is_array($content['routing'] ?? null) && !array_is_list($content['routing']) ? $content['routing'] : [];
$routing['llm_configuration_id'] = $llmId;
$routing['tts_configuration_id'] = $ttsId;
$content['routing'] = $routing;
if ($storedContent != $content) {
    $service->reviseCoreProfile(
        (string) $profile['core_profile_id'], (string) $profile['label'],
        filter_var($profile['default_npc'], FILTER_VALIDATE_BOOL),
        $profile['slot'] === null ? null : (int) $profile['slot'], $content,
        'Assign active CHIM connector defaults'
    );
}

echo json_encode([
    'schema'=>'almsivi.local-connectors.v1','installation_id'=>$installationId,
    'llm'=>['configuration_id'=>$llmId,'model'=>$model],
    'tts'=>['configuration_id'=>$ttsId,'driver'=>'pockettts','endpoint'=>$ttsEndpoint],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
PHP

php "${source_root}/scripts/import-morrowind-voices.php" "${game_data}"

php <<'PHP'
<?php
declare(strict_types=1);

use ALMSIVIserver\Application\NeverCancelledToken;
use ALMSIVIserver\Application\ProviderFactory;
use ALMSIVIserver\Infrastructure\Connection;
use ALMSIVIserver\Infrastructure\ProductRepository;

require __DIR__ . '/src/Autoload.php';
$config = require (string) getenv('ALMSIVI_CONFIG');
if (!is_array($config)) throw new RuntimeException('ALMSIVI server configuration is invalid.');
$config['database_password'] = (string) (getenv('ALMSIVI_DATABASE_PASSWORD') ?: ($config['database_password'] ?? ''));
$db = Connection::open($config);
$installationId = (string) $db->query("SELECT installation_id FROM installations WHERE revoked_at IS NULL ORDER BY created_at LIMIT 1")->fetchColumn();
$preset = (new ProductRepository($db))->connectorForInstallation($installationId, 'tts_provider');
if ($preset === null) throw new RuntimeException('The active ALMSIVI TTS connector is unavailable.');
$speech = ProviderFactory::speechForPreset($config, $preset)->synthesize(
    'ALMSIVI PocketTTS connector test.', new NeverCancelledToken(), ['voice'=>'mw_dark_elf_male','language'=>'en']
);
echo json_encode([
    'schema'=>'almsivi.local-tts-probe.v1','codec'=>$speech['codec'],
    'bytes'=>strlen($speech['bytes']),'duration_ms'=>$speech['duration_ms'],'voice'=>'mw_dark_elf_male',
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
PHP

service almsiviserver-worker restart >/dev/null
echo 'Configured ALMSIVI with the active CHIM LLM and PocketTTS defaults.'
