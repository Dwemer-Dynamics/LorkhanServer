#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo 'Run this local setup helper as root.' >&2
    exit 2
fi

for command in php psql runuser service; do
    command -v "${command}" >/dev/null || { echo "Missing required command: ${command}" >&2; exit 1; }
done

config_path=/etc/lorkhanserver/server.php
worker_env=/etc/lorkhanserver/worker.env
apache_env=/etc/lorkhanserver/apache-env.conf
for path in "${config_path}" "${worker_env}" "${apache_env}"; do
    [[ -f ${path} ]] || { echo "Required LORKHAN configuration is missing: ${path}" >&2; exit 1; }
done

# Reuse only the user's active Herika LLM endpoint, model, and credential. Speech, STT,
# ITT, and background-life configuration remain outside this focused local setup.
IFS=$'\t' read -r endpoint model api_key reasoning_model < <(
    runuser -u postgres -- psql -d dwemer -At -F $'\t' -c \
        "SELECT c.url,c.model,b.api_key,c.reasoning_model
         FROM core_profiles p
         JOIN core_llm_connector c ON c.id=p.llm_primary_id
         JOIN core_api_badge b ON b.id=c.api_badge_id
         WHERE p.slot=1
         ORDER BY p.id
         LIMIT 1"
)

[[ ${endpoint} == https://* ]] || { echo 'The active Herika LLM endpoint must use HTTPS.' >&2; exit 1; }
[[ -n ${model} && ${#model} -le 200 ]] || { echo 'The active Herika LLM model is missing or invalid.' >&2; exit 1; }
[[ -n ${api_key} ]] || { echo 'The active Herika LLM credential is missing.' >&2; exit 1; }
[[ ${reasoning_model} == 0 || ${reasoning_model} == 1 ]] || { echo 'The active Herika reasoning setting is invalid.' >&2; exit 1; }
host=$(php -r '$host=parse_url($argv[1],PHP_URL_HOST);if(!is_string($host)||$host==="")exit(1);echo strtolower($host);' "${endpoint}")
[[ ${host} =~ ^[a-z0-9.-]+$ ]] || { echo 'The active Herika LLM host is invalid.' >&2; exit 1; }

export LORKHAN_IMPORTED_LLM_ENDPOINT=${endpoint}
export LORKHAN_IMPORTED_LLM_MODEL=${model}
export LORKHAN_IMPORTED_LLM_HOST=${host}
export LORKHAN_IMPORTED_LLM_API_KEY=${api_key}
export LORKHAN_IMPORTED_LLM_DISABLE_REASONING=${reasoning_model}

php <<'PHP'
<?php
declare(strict_types=1);

$configPath = '/etc/lorkhanserver/server.php';
$workerPath = '/etc/lorkhanserver/worker.env';
$apachePath = '/etc/lorkhanserver/apache-env.conf';
$endpoint = (string) getenv('LORKHAN_IMPORTED_LLM_ENDPOINT');
$model = (string) getenv('LORKHAN_IMPORTED_LLM_MODEL');
$host = (string) getenv('LORKHAN_IMPORTED_LLM_HOST');
$apiKey = (string) getenv('LORKHAN_IMPORTED_LLM_API_KEY');
$disableReasoning = getenv('LORKHAN_IMPORTED_LLM_DISABLE_REASONING') === '1';
if ($endpoint === '' || $model === '' || $host === '' || $apiKey === ''
    || preg_match('/^[^\s\x00-\x1f]+$/D', $apiKey) !== 1) {
    throw new RuntimeException('Imported LLM configuration is invalid.');
}

$backupRoot = '/etc/lorkhanserver/backups';
if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true) && !is_dir($backupRoot)) {
    throw new RuntimeException('Could not create the private configuration backup directory.');
}
$stamp = gmdate('Ymd-His');
foreach ([$configPath, $workerPath, $apachePath] as $path) {
    if (!copy($path, $backupRoot . '/' . basename($path) . '.' . $stamp)) {
        throw new RuntimeException('Could not back up ' . $path);
    }
}

$config = require $configPath;
if (!is_array($config)) throw new RuntimeException('LorkhanServer configuration is invalid.');
$config['provider'] = [
    'driver' => 'openai-compatible',
    'endpoint' => $endpoint,
    'allowed_hosts' => [$host],
    'model' => $model,
    'api_key_env' => 'LORKHAN_LLM_API_KEY',
    'timeout_ms' => 120_000,
    'disable_reasoning' => $disableReasoning,
];
$configText = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";

$upsert = static function (string $path, string $pattern, string $line): string {
    $content = file_get_contents($path);
    if (!is_string($content)) throw new RuntimeException('Could not read ' . $path);
    if (preg_match($pattern, $content) === 1) {
        return (string) preg_replace($pattern, $line, $content, 1);
    }
    return rtrim($content) . "\n" . $line . "\n";
};
$workerText = $upsert($workerPath, '/^LORKHAN_LLM_API_KEY=.*$/m', 'LORKHAN_LLM_API_KEY=' . $apiKey);
$apacheText = $upsert($apachePath, '/^SetEnv\s+LORKHAN_LLM_API_KEY\s+.*$/m', 'SetEnv LORKHAN_LLM_API_KEY ' . $apiKey);

foreach ([
    [$configPath, $configText, 0640, 'www-data'],
    [$workerPath, $workerText, 0640, 'lorkhan'],
    [$apachePath, $apacheText, 0640, 'www-data'],
] as [$path, $content, $mode, $group]) {
    $temporary = $path . '.new';
    if (file_put_contents($temporary, $content, LOCK_EX) === false
        || !chmod($temporary, $mode)
        || !chown($temporary, 'root')
        || !chgrp($temporary, $group)
        || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not update ' . $path);
    }
}
PHP

unset LORKHAN_IMPORTED_LLM_API_KEY api_key
php -l "${config_path}" >/dev/null
service apache2 restart >/dev/null
service lorkhanserver-worker restart >/dev/null

driver=$(LORKHAN_CONFIG="${config_path}" php -r '$c=require getenv("LORKHAN_CONFIG");echo $c["provider"]["driver"]??"";')
[[ ${driver} == openai-compatible ]] || { echo 'LORKHAN did not load the live LLM provider.' >&2; exit 1; }
echo "Configured LORKHAN dialogue with the active Herika provider (${host}, ${model})."
echo "Private backups: /etc/lorkhanserver/backups"
