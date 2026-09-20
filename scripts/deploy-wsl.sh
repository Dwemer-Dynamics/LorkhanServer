#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run this deployment script as root." >&2
    exit 2
fi

source_root=${1:-}
http_port=${LORKHAN_HTTP_PORT:-8090}
if [[ -z ${source_root} || ! -f ${source_root}/index.php || ! -f ${source_root}/composer.json ]]; then
    echo "Usage: scripts/deploy-wsl.sh <absolute-LorkhanServer-source-path>" >&2
    exit 2
fi

for command in apache2ctl openssl php psql rsync runuser sha256sum ss; do
    command -v "${command}" >/dev/null || { echo "Missing required command: ${command}" >&2; exit 1; }
done

if [[ ! ${http_port} =~ ^[0-9]+$ ]] || (( http_port < 1024 || http_port > 65535 )); then
    echo "LORKHAN_HTTP_PORT must be an integer from 1024 through 65535." >&2
    exit 2
fi
case " ${http_port} " in
    ' 8020 '|' 8021 '|' 8022 '|' 8023 '|' 8024 '|' 8082 '|' 8085 '|' 8086 '|' 8089 '|' 12346 ')
  echo "Port ${http_port} is reserved by another Dwemer service. LORKHAN uses dedicated port 8090 by default." >&2
        exit 2
        ;;
esac
if ss -ltn | awk '{print $4}' | grep -Eq "(^|:)${http_port}$"; then
    if [[ ! -e /etc/apache2/sites-enabled/lorkhanserver.conf ]] \
        || ! grep -Eq "<VirtualHost[[:space:]]+\*:${http_port}>" /etc/apache2/sites-enabled/lorkhanserver.conf; then
        echo "Port ${http_port} is already owned by another service." >&2
        exit 1
    fi
fi

install -d -m 0755 /etc/lorkhanserver
getent group lorkhan >/dev/null || groupadd --system lorkhan
if ! id -u lorkhan >/dev/null 2>&1; then
    useradd --system --gid lorkhan --groups www-data --home-dir /nonexistent --shell /usr/sbin/nologin lorkhan
else
    usermod --append --groups www-data lorkhan
fi
install -d -o lorkhan -g www-data -m 2770 /var/lib/lorkhanserver/media
find /var/lib/lorkhanserver/media -xdev -type f -name '*.media' -exec chown lorkhan:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/lorkhanserver/voices
find /var/lib/lorkhanserver/voices -xdev -type f -name '*.wav' -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/lorkhanserver/profile-portraits
find /var/lib/lorkhanserver/profile-portraits -xdev -type f \( -name '*.png' -o -name '*.jpg' -o -name '*.webp' \) -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/lorkhanserver/backups
install -d -o lorkhan -g www-data -m 2770 /var/lib/lorkhanserver/backups/sql
find /var/lib/lorkhanserver/backups/sql -xdev -type f -name 'sql-*.sql*' -exec chown lorkhan:www-data -- {} + -exec chmod 0640 -- {} +
find /var/lib/lorkhanserver/backups -xdev -type f -name '*.json' -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/lorkhanserver/credentials
find /var/lib/lorkhanserver/credentials -xdev -type f -name 'provider-keys.json' -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
bash "${source_root}/deploy/provision-logs.sh"

if [[ ! -f /etc/lorkhanserver/database-password ]]; then
    openssl rand -hex 32 > /etc/lorkhanserver/database-password
fi
if [[ ! -f /etc/lorkhanserver/client-pairing-key ]]; then
    openssl rand -base64 32 | tr -d '=\n' | tr '+/' '-_' > /etc/lorkhanserver/client-pairing-key
fi
if [[ ! -f /etc/lorkhanserver/management-secret ]]; then
    openssl rand -base64 24 | tr -d '=\n' | tr '+/' '-_' > /etc/lorkhanserver/management-secret
fi
chown root:www-data /etc/lorkhanserver/database-password
chmod 0640 /etc/lorkhanserver/database-password
local_group=dweme
getent group "${local_group}" >/dev/null || local_group=root
chown root:"${local_group}" /etc/lorkhanserver/client-pairing-key /etc/lorkhanserver/management-secret
chmod 0640 /etc/lorkhanserver/client-pairing-key /etc/lorkhanserver/management-secret

database_password=$(< /etc/lorkhanserver/database-password)
pairing_key=$(< /etc/lorkhanserver/client-pairing-key)
management_secret=$(< /etc/lorkhanserver/management-secret)
pairing_hash=$(printf '%s' "${pairing_key}" | sha256sum | awk '{print $1}')
management_hash=$(printf '%s' "${management_secret}" | sha256sum | awk '{print $1}')

if ! runuser -u postgres -- psql -Atqc "SELECT 1 FROM pg_roles WHERE rolname='lorkhan_runtime'" | grep -qx 1; then
    runuser -u postgres -- psql -v ON_ERROR_STOP=1 -c "CREATE ROLE lorkhan_runtime LOGIN PASSWORD '${database_password}'"
else
    runuser -u postgres -- psql -v ON_ERROR_STOP=1 -c "ALTER ROLE lorkhan_runtime PASSWORD '${database_password}'"
fi
if ! runuser -u postgres -- psql -Atqc "SELECT 1 FROM pg_database WHERE datname='lorkhan'" | grep -qx 1; then
    runuser -u postgres -- createdb --template=template0 --owner=lorkhan_runtime --encoding=UTF8 lorkhan
fi

# pgvector is not a trusted PostgreSQL extension, so the restricted runtime role cannot install it
# during a genuinely fresh migration. Keep extension ownership with PostgreSQL administration.
runuser -u postgres -- psql --dbname=lorkhan --set=ON_ERROR_STOP=1 \
    --command='CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS vector;' >/dev/null

if [[ ! -f /etc/lorkhanserver/server.php ]]; then
cat > /etc/lorkhanserver/server.php <<'PHP'
<?php
declare(strict_types=1);

return [
    'environment' => 'production',
    'base_path' => '/LorkhanServer/api/v1',
    'database_dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=lorkhan',
    'database_user' => 'lorkhan_runtime',
    'database_password' => trim((string) file_get_contents('/etc/lorkhanserver/database-password')),
    'pairing_token_hash' => trim((string) file_get_contents('/etc/lorkhanserver/pairing-token-hash')),
    'management_base_path' => '/LorkhanServer/manage',
    'management_secret_hash' => trim((string) file_get_contents('/etc/lorkhanserver/management-secret-hash')),
    'browser_session_ttl_seconds' => 3600,
    'max_json_bytes' => 2 * 1024 * 1024,
    'max_context_bytes' => 128 * 1024,
    'events_page_size' => 100,
    'event_replay_limit' => 256,
    'events_max_wait_seconds' => 15,
    'rate_limit_requests' => 120,
    'rate_limit_window_seconds' => 60,
    'provider' => [
        'driver' => 'openai-compatible',
        'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
        'allowed_hosts' => ['openrouter.ai'],
        'model' => 'deepseek/deepseek-v4-flash',
        'api_key_env' => 'LORKHAN_LLM_API_KEY',
        'timeout_ms' => 120000,
        'disable_reasoning' => true,
    ],
    'speech_provider' => [
        'driver' => 'mock',
        'timeout_ms' => 30000,
    ],
    'stt_provider' => [
        'driver' => 'mock',
        'timeout_ms' => 30000,
    ],
    'media_storage_path' => '/var/lib/lorkhanserver/media',
    'voice_storage_path' => '/var/lib/lorkhanserver/voices',
    'portrait_storage_path' => '/var/lib/lorkhanserver/profile-portraits',
    'backup_storage_path' => '/var/lib/lorkhanserver/backups',
    'credential_storage_path' => '/var/lib/lorkhanserver/credentials/provider-keys.json',
    'media_max_bytes' => 32 * 1024 * 1024,
    'media_quota_bytes' => 256 * 1024 * 1024,
    'worker' => [
        'lease_seconds' => 30,
        'batch_size' => 1,
        'max_jobs' => 100,
        'idle_exit_seconds' => 30,
        'max_runtime_seconds' => 300,
        'types' => null,
    ],
];
PHP
fi
printf '%s\n' "${pairing_hash}" > /etc/lorkhanserver/pairing-token-hash
printf '%s\n' "${management_hash}" > /etc/lorkhanserver/management-secret-hash
if [[ ! -f /etc/lorkhanserver/apache-env.conf ]]; then
cat > /etc/lorkhanserver/apache-env.conf <<EOF
SetEnv LORKHAN_CONFIG /etc/lorkhanserver/server.php
SetEnv LORKHAN_PAIRING_MAC_KEY ${pairing_key}
EOF
fi
chown root:www-data /etc/lorkhanserver/server.php /etc/lorkhanserver/pairing-token-hash \
    /etc/lorkhanserver/management-secret-hash /etc/lorkhanserver/apache-env.conf
chmod 0640 /etc/lorkhanserver/server.php /etc/lorkhanserver/pairing-token-hash \
    /etc/lorkhanserver/management-secret-hash /etc/lorkhanserver/apache-env.conf
if [[ ! -f /etc/lorkhanserver/worker.env ]]; then
cat > /etc/lorkhanserver/worker.env <<'EOF'
LORKHAN_CONFIG=/etc/lorkhanserver/server.php
EOF
fi
chown root:lorkhan /etc/lorkhanserver/worker.env
chmod 0640 /etc/lorkhanserver/worker.env

# Both first install and subsequent updates use the same runtime manifest and stable web root.
if [[ ${LORKHAN_BOOTSTRAP_ONLY:-0} != 1 ]]; then
    exec bash "${source_root}/scripts/deploy-local-wsl.sh" "${source_root}"
fi
