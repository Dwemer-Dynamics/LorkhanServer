#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run this deployment script as root." >&2
    exit 2
fi

source_root=${1:-}
http_port=${LORKHAN_HTTP_PORT:-8090}
if [[ -z ${source_root} || ! -f ${source_root}/public/index.php || ! -f ${source_root}/composer.json ]]; then
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

install -d -m 0755 /var/www/LorkhanServer/releases /etc/lorkhanserver
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
find /var/lib/lorkhanserver/backups -xdev -type f -name '*.json' -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/lorkhanserver/credentials
find /var/lib/lorkhanserver/credentials -xdev -type f -name 'provider-keys.json' -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o lorkhan -g www-data -m 0750 /var/log/lorkhanserver

release_id="$(date -u +%Y%m%dT%H%M%SZ)-$(git -C "${source_root}" rev-parse --short=12 HEAD 2>/dev/null || echo local)"
release_dir="/var/www/LorkhanServer/releases/${release_id}"
if [[ -e ${release_dir} ]]; then
    echo "Release already exists: ${release_dir}" >&2
    exit 1
fi
install -d -m 0755 "${release_dir}"
release_committed=false
cleanup_failed_release() {
    if [[ ${release_committed} != true && -n ${release_dir:-} && ${release_dir} == /var/www/LorkhanServer/releases/* ]]; then
        rm -rf -- "${release_dir}"
    fi
}
trap cleanup_failed_release EXIT
rsync -a --exclude=.git --exclude=.github --exclude=.work --exclude=build --exclude=coverage \
    --exclude=storage --exclude=vendor "${source_root}/" "${release_dir}/"
find "${release_dir}" -type d -exec chmod 0755 {} +
find "${release_dir}" -type f -exec chmod 0644 {} +

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
        'model' => 'z-ai/glm-4.7',
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
printf '%s\n' "${pairing_hash}" > /etc/lorkhanserver/pairing-token-hash
printf '%s\n' "${management_hash}" > /etc/lorkhanserver/management-secret-hash
cat > /etc/lorkhanserver/apache-env.conf <<EOF
SetEnv LORKHAN_CONFIG /etc/lorkhanserver/server.php
SetEnv LORKHAN_PAIRING_MAC_KEY ${pairing_key}
EOF
chown root:www-data /etc/lorkhanserver/server.php /etc/lorkhanserver/pairing-token-hash \
    /etc/lorkhanserver/management-secret-hash /etc/lorkhanserver/apache-env.conf
chmod 0640 /etc/lorkhanserver/server.php /etc/lorkhanserver/pairing-token-hash \
    /etc/lorkhanserver/management-secret-hash /etc/lorkhanserver/apache-env.conf
cat > /etc/lorkhanserver/worker.env <<'EOF'
LORKHAN_CONFIG=/etc/lorkhanserver/server.php
EOF
chown root:lorkhan /etc/lorkhanserver/worker.env
chmod 0640 /etc/lorkhanserver/worker.env

LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${release_dir}/scripts/migrate.php" up
LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${release_dir}/scripts/provision-default-connectors.php"
LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${release_dir}/scripts/provision-default-descriptions.php"
LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${release_dir}/scripts/provision-default-biographies.php"
LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${release_dir}/scripts/backfill-morrowind-localities.php"
LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${release_dir}/scripts/provision-default-oghma.php"

ln -sfn "${release_dir}" /var/www/LorkhanServer/current.next
mv -Tf /var/www/LorkhanServer/current.next /var/www/LorkhanServer/current
release_committed=true
gateway=$(ip route show default | awk '/^default via / {print $3; exit}')
if [[ ! ${gateway} =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Could not determine the Windows-to-WSL gateway address." >&2
    exit 1
fi
sed -e "s/@WSL_GATEWAY@/${gateway}/g" -e "s/@LORKHAN_HTTP_PORT@/${http_port}/g" \
    "${release_dir}/deploy/apache/lorkhanserver.conf" \
    > /etc/apache2/sites-available/lorkhanserver.conf
chmod 0644 /etc/apache2/sites-available/lorkhanserver.conf
sed -i -E "/^Listen[[:space:]]+(127\\.0\\.0\\.1|0\\.0\\.0\\.0):${http_port}$/d" /etc/apache2/ports.conf
printf '\nListen 0.0.0.0:%s\n' "${http_port}" >> /etc/apache2/ports.conf
a2enmod rewrite >/dev/null
a2ensite lorkhanserver.conf >/dev/null
apache2ctl configtest
service apache2 restart

if [[ $(ps -p 1 -o comm=) == systemd ]]; then
    command -v systemctl >/dev/null || { echo "Missing required command: systemctl" >&2; exit 1; }
    install -m 0644 "${release_dir}/deploy/systemd/lorkhanserver-worker.service" \
        /etc/systemd/system/lorkhanserver-worker.service
    install -m 0644 "${release_dir}/deploy/systemd/lorkhanserver-worker.timer" \
        /etc/systemd/system/lorkhanserver-worker.time
    systemctl daemon-reload
    systemctl enable --now lorkhanserver-worker.timer >/dev/null
    systemctl reset-failed lorkhanserver-worker.service >/dev/null 2>&1 || true
    systemctl start --no-block lorkhanserver-worker.service
    if [[ $(systemctl is-enabled lorkhanserver-worker.timer) != enabled \
        || $(systemctl is-active lorkhanserver-worker.timer) != active ]]; then
        echo "LorkhanServer worker timer did not become active." >&2
        exit 1
    fi
else
    for command in service start-stop-daemon update-rc.d; do
        command -v "${command}" >/dev/null || { echo "Missing required command: ${command}" >&2; exit 1; }
    done
    install -d -m 0755 /usr/local/libexec
    install -m 0755 "${release_dir}/deploy/sysv/lorkhanserver-worker-loop" \
        /usr/local/libexec/lorkhanserver-worker-loop
    install -m 0755 "${release_dir}/deploy/sysv/lorkhanserver-worker" \
        /etc/init.d/lorkhanserver-worker
    update-rc.d lorkhanserver-worker defaults >/dev/null
    service lorkhanserver-worker restart
    service lorkhanserver-worker status >/dev/null
fi

health=$(curl --fail --silent --show-error "http://127.0.0.1:${http_port}/LorkhanServer/api/v1/health")
if [[ ${health} != '{"schema":"lorkhan.health.v1"}' ]]; then
    echo "Unexpected health response." >&2
    exit 1
fi

echo "Deployed ${release_dir}"
echo "Health: http://127.0.0.1:${http_port}/LorkhanServer/api/v1/health"
echo "Management: http://127.0.0.1:${http_port}/LorkhanServer/manage"
echo "Local secrets remain in /etc/lorkhanserver and were not printed."
