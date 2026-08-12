#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run this deployment script as root." >&2
    exit 2
fi

source_root=${1:-}
target_root=/var/www/html/ALMSIVIserver
http_port=${ALMSIVI_HTTP_PORT:-8089}
if [[ -z ${source_root} || ${source_root} != /* || ! -f ${source_root}/public/index.php || ! -f ${source_root}/composer.json ]]; then
    echo "Usage: scripts/deploy-local-wsl.sh <absolute-ALMSIVIserver-source-path>" >&2
    exit 2
fi

for command in apache2ctl curl php psql rsync runuser sed ss; do
    command -v "${command}" >/dev/null || { echo "Missing required command: ${command}" >&2; exit 1; }
done

if [[ ! ${http_port} =~ ^[0-9]+$ ]] || (( http_port < 1024 || http_port > 65535 )); then
    echo "ALMSIVI_HTTP_PORT must be an integer from 1024 through 65535." >&2
    exit 2
fi
case " ${http_port} " in
    ' 8020 '|' 8021 '|' 8022 '|' 8023 '|' 8024 '|' 8082 '|' 8085 '|' 8086 '|' 12346 ')
        echo "Port ${http_port} is reserved by another Dwemer service. ALMSIVI uses dedicated port 8089 by default." >&2
        exit 2
        ;;
esac
if ss -ltn | awk '{print $4}' | grep -Eq "(^|:)${http_port}$"; then
    if [[ ! -e /etc/apache2/sites-enabled/almsiviserver.conf ]] \
        || ! grep -Eq "<VirtualHost[[:space:]]+\*:${http_port}>" /etc/apache2/sites-enabled/almsiviserver.conf; then
        echo "Port ${http_port} is already owned by another service." >&2
        exit 1
    fi
fi

# Bootstrap the persistent database, identities, and secrets once through the production-safe
# installer. Later local deploys only mirror code into the stable Herika/Dialectic-style path.
needs_bootstrap=false
for required_file in \
    /etc/almsiviserver/server.php \
    /etc/almsiviserver/client-pairing-key \
    /etc/almsiviserver/management-secret \
    /etc/almsiviserver/worker.env; do
    [[ -f ${required_file} ]] || needs_bootstrap=true
done
if ! runuser -u postgres -- psql -Atqc "SELECT 1 FROM pg_database WHERE datname='almsivi'" | grep -qx 1; then
    needs_bootstrap=true
fi
if [[ ${needs_bootstrap} == true ]]; then
    ALMSIVI_HTTP_PORT=${http_port} bash "${source_root}/scripts/deploy-wsl.sh" "${source_root}"
fi

getent group almsivi >/dev/null || groupadd --system almsivi
if ! id -u almsivi >/dev/null 2>&1; then
    useradd --system --gid almsivi --groups www-data --home-dir /nonexistent --shell /usr/sbin/nologin almsivi
else
    usermod --append --groups www-data almsivi
fi
install -d -m 0755 /var/www/html
install -d -o almsivi -g www-data -m 2770 /var/lib/almsiviserver/media
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

stage_root=$(mktemp -d /var/www/html/.ALMSIVIserver-stage.XXXXXX)
cleanup_stage() {
    if [[ -n ${stage_root:-} && ${stage_root} == /var/www/html/.ALMSIVIserver-stage.* ]]; then
        rm -rf -- "${stage_root}"
    fi
}
trap cleanup_stage EXIT

rsync -a \
    --exclude=.git --exclude=.github --exclude=.work --exclude=build --exclude=coverage \
    --exclude=storage --exclude=vendor "${source_root}/" "${stage_root}/"

# Fail before changing the live tree when a staged PHP file is syntactically invalid.
while IFS= read -r -d '' php_file; do
    php -l "${php_file}" >/dev/null
done < <(find "${stage_root}" -type f -name '*.php' -print0)

if command -v service >/dev/null && [[ -e /etc/init.d/almsiviserver-worker ]]; then
    service almsiviserver-worker stop >/dev/null 2>&1 || true
fi
install -d -m 0755 "${target_root}"
rsync -a --delete \
    --exclude=storage --exclude=vendor "${stage_root}/" "${target_root}/"
find "${target_root}" -type d -exec chmod 0755 {} +
find "${target_root}" -type f -exec chmod 0644 {} +
find "${target_root}/scripts" "${target_root}/deploy" -type f \
    \( -name '*.sh' -o -path '*/sysv/*' \) -exec sed -i 's/\r$//' {} +

ALMSIVI_CONFIG=/etc/almsiviserver/server.php php "${target_root}/scripts/migrate.php" up
ALMSIVI_CONFIG=/etc/almsiviserver/server.php php "${target_root}/scripts/provision-default-connectors.php"
ALMSIVI_CONFIG=/etc/almsiviserver/server.php php "${target_root}/scripts/provision-default-descriptions.php"
ALMSIVI_CONFIG=/etc/almsiviserver/server.php php "${target_root}/scripts/provision-default-biographies.php"
ALMSIVI_CONFIG=/etc/almsiviserver/server.php php "${target_root}/scripts/provision-default-oghma.php"

gateway=$(ip route show default | awk '/^default via / {print $3; exit}')
if [[ ! ${gateway} =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Could not determine the Windows-to-WSL gateway address." >&2
    exit 1
fi
sed \
    -e "s/@WSL_GATEWAY@/${gateway}/g" \
    -e "s/@ALMSIVI_HTTP_PORT@/${http_port}/g" \
    -e 's#/var/www/ALMSIVIserver/current#/var/www/html/ALMSIVIserver#g' \
    "${target_root}/deploy/apache/almsiviserver.conf" \
    > /etc/apache2/sites-available/almsiviserver.conf
chmod 0644 /etc/apache2/sites-available/almsiviserver.conf
sed -i -E "/^Listen[[:space:]]+(127\\.0\\.0\\.1|0\\.0\\.0\\.0):${http_port}$/d" /etc/apache2/ports.conf
printf '\nListen 0.0.0.0:%s\n' "${http_port}" >> /etc/apache2/ports.conf
a2enmod rewrite >/dev/null
a2ensite almsiviserver.conf >/dev/null
apache2ctl configtest
service apache2 restart

if [[ $(ps -p 1 -o comm=) == systemd ]]; then
    command -v systemctl >/dev/null || { echo "Missing required command: systemctl" >&2; exit 1; }
    sed 's#/var/www/ALMSIVIserver/current#/var/www/html/ALMSIVIserver#g' \
        "${target_root}/deploy/systemd/almsiviserver-worker.service" \
        > /etc/systemd/system/almsiviserver-worker.service
    install -m 0644 "${target_root}/deploy/systemd/almsiviserver-worker.timer" \
        /etc/systemd/system/almsiviserver-worker.time
    systemctl daemon-reload
    systemctl enable --now almsiviserver-worker.timer >/dev/null
    systemctl reset-failed almsiviserver-worker.service >/dev/null 2>&1 || true
    systemctl start --no-block almsiviserver-worker.service
    [[ $(systemctl is-enabled almsiviserver-worker.timer) == enabled ]]
    [[ $(systemctl is-active almsiviserver-worker.timer) == active ]]
else
    for command in service start-stop-daemon update-rc.d; do
        command -v "${command}" >/dev/null || { echo "Missing required command: ${command}" >&2; exit 1; }
    done
    install -d -m 0755 /usr/local/libexec
    sed 's#/var/www/ALMSIVIserver/current#/var/www/html/ALMSIVIserver#g' \
        "${target_root}/deploy/sysv/almsiviserver-worker-loop" \
        > /usr/local/libexec/almsiviserver-worker-loop
    chmod 0755 /usr/local/libexec/almsiviserver-worker-loop
    sed 's#/var/www/ALMSIVIserver/current#/var/www/html/ALMSIVIserver#g' \
        "${target_root}/deploy/sysv/almsiviserver-worker" \
        > /etc/init.d/almsiviserver-worker
    chmod 0755 /etc/init.d/almsiviserver-worker
    update-rc.d almsiviserver-worker defaults >/dev/null
    service almsiviserver-worker restart
    service almsiviserver-worker status >/dev/null
fi

health=$(curl --fail --silent --show-error "http://127.0.0.1:${http_port}/ALMSIVIserver/api/v1/health")
if [[ ${health} != '{"schema":"almsivi.health.v1"}' ]]; then
    echo "Unexpected health response." >&2
    exit 1
fi

echo "Deployed ${target_root}"
echo "Health: http://127.0.0.1:${http_port}/ALMSIVIserver/api/v1/health"
echo "Management: http://127.0.0.1:${http_port}/ALMSIVIserver/manage"
echo "Persistent database, media, logs, and secrets were preserved."
