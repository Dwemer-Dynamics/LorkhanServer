#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run this deployment script as root." >&2
    exit 2
fi

source_root=${1:-}
if [[ -z ${source_root} || ! -f ${source_root}/public/index.php || ! -f ${source_root}/composer.json ]]; then
    echo "Usage: scripts/deploy-wsl.sh <absolute-ALMSIVIserver-source-path>" >&2
    exit 2
fi

for command in apache2ctl openssl php psql rsync runuser sha256sum; do
    command -v "${command}" >/dev/null || { echo "Missing required command: ${command}" >&2; exit 1; }
done

if ss -ltn | awk '{print $4}' | grep -Eq '(^|:)8089$'; then
    if [[ ! -e /etc/apache2/sites-enabled/almsiviserver.conf ]]; then
        echo "Port 8089 is already owned by another service." >&2
        exit 1
    fi
fi

install -d -m 0755 /var/www/ALMSIVIserver/releases /etc/almsiviserver
getent group almsivi >/dev/null || groupadd --system almsivi
if ! id -u almsivi >/dev/null 2>&1; then
    useradd --system --gid almsivi --groups www-data --home-dir /nonexistent --shell /usr/sbin/nologin almsivi
else
    usermod --append --groups www-data almsivi
fi
install -d -o almsivi -g www-data -m 2750 /var/lib/almsiviserver/media
find /var/lib/almsiviserver/media -xdev -type f -name '*.media' -exec chown almsivi:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/almsiviserver/voices
find /var/lib/almsiviserver/voices -xdev -type f -name '*.wav' -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/almsiviserver/profile-portraits
find /var/lib/almsiviserver/profile-portraits -xdev -type f \( -name '*.png' -o -name '*.jpg' -o -name '*.webp' \) -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/almsiviserver/backups
find /var/lib/almsiviserver/backups -xdev -type f -name '*.json' -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/almsiviserver/credentials
find /var/lib/almsiviserver/credentials -xdev -type f -name 'provider-keys.json' -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o almsivi -g www-data -m 0750 /var/log/almsiviserver

release_id="$(date -u +%Y%m%dT%H%M%SZ)-$(git -C "${source_root}" rev-parse --short=12 HEAD 2>/dev/null || echo local)"
release_dir="/var/www/ALMSIVIserver/releases/${release_id}"
if [[ -e ${release_dir} ]]; then
    echo "Release already exists: ${release_dir}" >&2
    exit 1
fi
install -d -m 0755 "${release_dir}"
release_committed=false
cleanup_failed_release() {
    if [[ ${release_committed} != true && -n ${release_dir:-} && ${release_dir} == /var/www/ALMSIVIserver/releases/* ]]; then
        rm -rf -- "${release_dir}"
    fi
}
trap cleanup_failed_release EXIT
rsync -a --exclude=.git --exclude=.github --exclude=.work --exclude=build --exclude=coverage \
    --exclude=storage --exclude=vendor "${source_root}/" "${release_dir}/"
find "${release_dir}" -type d -exec chmod 0755 {} +
find "${release_dir}" -type f -exec chmod 0644 {} +

if [[ ! -f /etc/almsiviserver/database-password ]]; then
    openssl rand -hex 32 > /etc/almsiviserver/database-password
fi
if [[ ! -f /etc/almsiviserver/client-pairing-key ]]; then
    openssl rand -base64 32 | tr -d '=\n' | tr '+/' '-_' > /etc/almsiviserver/client-pairing-key
fi
if [[ ! -f /etc/almsiviserver/management-secret ]]; then
    openssl rand -base64 24 | tr -d '=\n' | tr '+/' '-_' > /etc/almsiviserver/management-secret
fi
chown root:www-data /etc/almsiviserver/database-password
chmod 0640 /etc/almsiviserver/database-password
local_group=dweme
getent group "${local_group}" >/dev/null || local_group=root
chown root:"${local_group}" /etc/almsiviserver/client-pairing-key /etc/almsiviserver/management-secret
chmod 0640 /etc/almsiviserver/client-pairing-key /etc/almsiviserver/management-secret

database_password=$(< /etc/almsiviserver/database-password)
pairing_key=$(< /etc/almsiviserver/client-pairing-key)
management_secret=$(< /etc/almsiviserver/management-secret)
pairing_hash=$(printf '%s' "${pairing_key}" | sha256sum | awk '{print $1}')
management_hash=$(printf '%s' "${management_secret}" | sha256sum | awk '{print $1}')

if ! runuser -u postgres -- psql -Atqc "SELECT 1 FROM pg_roles WHERE rolname='almsivi_runtime'" | grep -qx 1; then
    runuser -u postgres -- psql -v ON_ERROR_STOP=1 -c "CREATE ROLE almsivi_runtime LOGIN PASSWORD '${database_password}'"
else
    runuser -u postgres -- psql -v ON_ERROR_STOP=1 -c "ALTER ROLE almsivi_runtime PASSWORD '${database_password}'"
fi
if ! runuser -u postgres -- psql -Atqc "SELECT 1 FROM pg_database WHERE datname='almsivi'" | grep -qx 1; then
    runuser -u postgres -- createdb --template=template0 --owner=almsivi_runtime --encoding=UTF8 almsivi
fi

cat > /etc/almsiviserver/server.php <<'PHP'
<?php
declare(strict_types=1);

return [
    'environment' => 'production',
    'base_path' => '/ALMSIVIserver/api/v1',
    'database_dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=almsivi',
    'database_user' => 'almsivi_runtime',
    'database_password' => trim((string) file_get_contents('/etc/almsiviserver/database-password')),
    'pairing_token_hash' => trim((string) file_get_contents('/etc/almsiviserver/pairing-token-hash')),
    'management_base_path' => '/ALMSIVIserver/manage',
    'management_secret_hash' => trim((string) file_get_contents('/etc/almsiviserver/management-secret-hash')),
    'browser_session_ttl_seconds' => 3600,
    'max_json_bytes' => 2 * 1024 * 1024,
    'max_context_bytes' => 128 * 1024,
    'events_page_size' => 100,
    'event_replay_limit' => 256,
    'events_max_wait_seconds' => 15,
    'rate_limit_requests' => 120,
    'rate_limit_window_seconds' => 60,
    'provider' => [
        'driver' => 'mock',
        'model' => 'deterministic-mock-v1',
        'mock_prefix' => 'ALMSIVI: ',
        'timeout_ms' => 1000,
    ],
    'speech_provider' => [
        'driver' => 'mock',
        'timeout_ms' => 30000,
    ],
    'stt_provider' => [
        'driver' => 'mock',
        'timeout_ms' => 30000,
    ],
    'media_storage_path' => '/var/lib/almsiviserver/media',
    'voice_storage_path' => '/var/lib/almsiviserver/voices',
    'portrait_storage_path' => '/var/lib/almsiviserver/profile-portraits',
    'backup_storage_path' => '/var/lib/almsiviserver/backups',
    'credential_storage_path' => '/var/lib/almsiviserver/credentials/provider-keys.json',
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
printf '%s\n' "${pairing_hash}" > /etc/almsiviserver/pairing-token-hash
printf '%s\n' "${management_hash}" > /etc/almsiviserver/management-secret-hash
cat > /etc/almsiviserver/apache-env.conf <<EOF
SetEnv ALMSIVI_CONFIG /etc/almsiviserver/server.php
SetEnv ALMSIVI_PAIRING_MAC_KEY ${pairing_key}
EOF
chown root:www-data /etc/almsiviserver/server.php /etc/almsiviserver/pairing-token-hash \
    /etc/almsiviserver/management-secret-hash /etc/almsiviserver/apache-env.conf
chmod 0640 /etc/almsiviserver/server.php /etc/almsiviserver/pairing-token-hash \
    /etc/almsiviserver/management-secret-hash /etc/almsiviserver/apache-env.conf
cat > /etc/almsiviserver/worker.env <<'EOF'
ALMSIVI_CONFIG=/etc/almsiviserver/server.php
EOF
chown root:almsivi /etc/almsiviserver/worker.env
chmod 0640 /etc/almsiviserver/worker.env

ALMSIVI_CONFIG=/etc/almsiviserver/server.php php "${release_dir}/scripts/migrate.php" up

ln -sfn "${release_dir}" /var/www/ALMSIVIserver/current.next
mv -Tf /var/www/ALMSIVIserver/current.next /var/www/ALMSIVIserver/current
release_committed=true
gateway=$(ip route show default | awk '/^default via / {print $3; exit}')
if [[ ! ${gateway} =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Could not determine the Windows-to-WSL gateway address." >&2
    exit 1
fi
sed "s/@WSL_GATEWAY@/${gateway}/g" "${release_dir}/deploy/apache/almsiviserver.conf" \
    > /etc/apache2/sites-available/almsiviserver.conf
chmod 0644 /etc/apache2/sites-available/almsiviserver.conf
sed -i '/^Listen[[:space:]]\+127\.0\.0\.1:8089$/d;/^Listen[[:space:]]\+0\.0\.0\.0:8089$/d' /etc/apache2/ports.conf
printf '\nListen 0.0.0.0:8089\n' >> /etc/apache2/ports.conf
a2enmod rewrite >/dev/null
a2ensite almsiviserver.conf >/dev/null
apache2ctl configtest
service apache2 restart

if [[ $(ps -p 1 -o comm=) == systemd ]]; then
    command -v systemctl >/dev/null || { echo "Missing required command: systemctl" >&2; exit 1; }
    install -m 0644 "${release_dir}/deploy/systemd/almsiviserver-worker.service" \
        /etc/systemd/system/almsiviserver-worker.service
    install -m 0644 "${release_dir}/deploy/systemd/almsiviserver-worker.timer" \
        /etc/systemd/system/almsiviserver-worker.time
    systemctl daemon-reload
    systemctl enable --now almsiviserver-worker.timer >/dev/null
    systemctl reset-failed almsiviserver-worker.service >/dev/null 2>&1 || true
    systemctl start --no-block almsiviserver-worker.service
    if [[ $(systemctl is-enabled almsiviserver-worker.timer) != enabled \
        || $(systemctl is-active almsiviserver-worker.timer) != active ]]; then
        echo "ALMSIVIserver worker timer did not become active." >&2
        exit 1
    fi
else
    for command in service start-stop-daemon update-rc.d; do
        command -v "${command}" >/dev/null || { echo "Missing required command: ${command}" >&2; exit 1; }
    done
    install -d -m 0755 /usr/local/libexec
    install -m 0755 "${release_dir}/deploy/sysv/almsiviserver-worker-loop" \
        /usr/local/libexec/almsiviserver-worker-loop
    install -m 0755 "${release_dir}/deploy/sysv/almsiviserver-worker" \
        /etc/init.d/almsiviserver-worker
    update-rc.d almsiviserver-worker defaults >/dev/null
    service almsiviserver-worker restart
    service almsiviserver-worker status >/dev/null
fi

health=$(curl --fail --silent --show-error http://127.0.0.1:8089/ALMSIVIserver/api/v1/health)
if [[ ${health} != '{"schema":"almsivi.health.v1"}' ]]; then
    echo "Unexpected health response." >&2
    exit 1
fi

echo "Deployed ${release_dir}"
echo "Health: http://127.0.0.1:8089/ALMSIVIserver/api/v1/health"
echo "Management: http://127.0.0.1:8089/ALMSIVIserver/manage"
echo "Local secrets remain in /etc/almsiviserver and were not printed."
