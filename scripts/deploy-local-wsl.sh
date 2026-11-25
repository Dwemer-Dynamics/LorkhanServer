#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run this deployment script as root." >&2
    exit 2
fi

# Pin every PostgreSQL administration call, including bootstrap and factory preflight children, to
# the established local cluster: socket /var/run/postgresql port 5432, which the bootstrap DSN names
# as 127.0.0.1:5432. Inherited PG* connection/service variables never select the target.
unset PGSERVICE PGSERVICEFILE PGSYSCONFDIR PGHOSTADDR PGDATABASE PGUSER PGOPTIONS PGTARGETSESSIONATTRS PGLOADBALANCEHOSTS
export PGHOST=/var/run/postgresql PGPORT=5432

source_root=${1:-}
target_root=/var/www/html/LorkhanServer
http_port=${LORKHAN_HTTP_PORT:-8090}
if [[ -z ${source_root} || ${source_root} != /* || ! -f ${source_root}/index.php || ! -f ${source_root}/composer.json ]]; then
    echo "Usage: scripts/deploy-local-wsl.sh <absolute-LorkhanServer-source-path>" >&2
    exit 2
fi

for command in apache2ctl curl php psql rsync runuser sed ss; do
    command -v "${command}" >/dev/null || { echo "Missing required command: ${command}" >&2; exit 1; }
done
# Refuse an unpatched PHP CLI or Apache PHP runtime before any account, file or database change.
bash "${source_root}/scripts/check-php-runtime-security.sh"

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

# Bootstrap the persistent database, identities, and secrets once through the production-safe
# installer. Later local deploys only mirror code into the stable Herika/Dialectic-style path.
needs_bootstrap=false
for required_file in \
    /etc/lorkhanserver/server.php \
    /etc/lorkhanserver/client-pairing-key \
    /etc/lorkhanserver/management-secret \
    /etc/lorkhanserver/worker.env; do
    [[ -f ${required_file} ]] || needs_bootstrap=true
done
if ! runuser -u postgres -- psql -Atqc "SELECT 1 FROM pg_database WHERE datname='lorkhan'" | grep -qx 1; then
    needs_bootstrap=true
fi
# Read-only PostgreSQL preflight: accept only an existing 15 or 17 server and matching factory tools.
# Never install packages, upgrade, downgrade or migrate a cluster here.
# An existing configuration must already name this same target; it is never silently redirected.
if [[ -f /etc/lorkhanserver/server.php ]] && ! php /dev/stdin <<'PHP'
<?php
$dsn=(string)((require '/etc/lorkhanserver/server.php')['database_dsn']??'');
if(!str_starts_with($dsn,'pgsql:'))exit(1);
$parts=[];
foreach(explode(';',substr($dsn,6)) as $pair){[$key,$value]=array_pad(explode('=',$pair,2),2,'');$parts[trim($key)]=trim($value);}
exit(in_array($parts['host']??'',['127.0.0.1','localhost'],true)&&($parts['port']??'')==='5432'&&($parts['dbname']??'')==='lorkhan'?0:1);
PHP
then
    echo "The existing LorkhanServer database configuration does not target the local PostgreSQL cluster at 127.0.0.1:5432/lorkhan; this deployment does not support it. Nothing was changed." >&2
    exit 1
fi
pg_target=$(runuser -u postgres -- psql -XAtqd postgres \
    -c "SELECT current_setting('server_version_num')||'|'||current_setting('port')||'|'||current_setting('listen_addresses')") \
    || { echo "Could not read the local PostgreSQL server version; nothing was changed." >&2; exit 1; }
IFS='|' read -r pg_version_num pg_port pg_listen <<< "${pg_target}"
if [[ ! ${pg_version_num} =~ ^[1-9][0-9]{5}$ ]]; then
    echo "Unrecognized PostgreSQL server version; nothing was changed." >&2
    exit 1
fi
# The socket probed here must be the same cluster that the 127.0.0.1:5432 runtime DSN reaches.
pg_loopback=false
IFS=',' read -r -a pg_listen_items <<< "${pg_listen}"
for pg_listen_item in "${pg_listen_items[@]}"; do
    case "${pg_listen_item//[[:space:]]/}" in '*'|0.0.0.0|127.0.0.1|localhost) pg_loopback=true ;; esac
done
if [[ ${pg_port} != 5432 || ${pg_loopback} != true ]]; then
    echo "The local PostgreSQL cluster does not listen on 127.0.0.1:5432, the target LorkhanServer configures. Nothing was changed." >&2
    exit 1
fi
pg_major=$((pg_version_num / 10000))
if [[ ${pg_major} != 15 && ${pg_major} != 17 ]]; then
    echo "PostgreSQL ${pg_major} is not supported; LorkhanServer requires an existing PostgreSQL 15 or 17 server. Nothing was changed." >&2
    exit 1
fi
factory_preflight=1
if [[ ${needs_bootstrap} == true ]]; then
    factory_preflight=bootstrap
fi
LORKHAN_FACTORY_PREFLIGHT_ONLY=${factory_preflight} \
    bash "${source_root}/scripts/deploy-factory-database.sh" "${source_root}" "${pg_major}"
if [[ ${needs_bootstrap} == true ]]; then
    LORKHAN_BOOTSTRAP_ONLY=1 LORKHAN_HTTP_PORT=${http_port} bash "${source_root}/scripts/deploy-wsl.sh" "${source_root}"
fi

getent group lorkhan >/dev/null || groupadd --system lorkhan
if ! id -u lorkhan >/dev/null 2>&1; then
    useradd --system --gid lorkhan --groups www-data --home-dir /nonexistent --shell /usr/sbin/nologin lorkhan
else
    usermod --append --groups www-data lorkhan
fi
install -d -m 0755 /var/www/html
install -d -o lorkhan -g www-data -m 2770 /var/lib/lorkhanserver/media
find /var/lib/lorkhanserver/media -xdev -type f -name '*.media' -exec chown lorkhan:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/lorkhanserver/voices
find /var/lib/lorkhanserver/voices -xdev -type f -name '*.wav' -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/lorkhanserver/profile-portraits
find /var/lib/lorkhanserver/profile-portraits -xdev -type f \( -name '*.png' -o -name '*.jpg' -o -name '*.webp' \) -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/lorkhanserver/backups
install -d -o lorkhan -g www-data -m 2770 /var/lib/lorkhanserver/backups/sql
install -d -o lorkhan -g www-data -m 2770 /var/lib/lorkhanserver/backups/imports
install -d -o lorkhan -g www-data -m 2770 /var/lib/lorkhanserver/plugin-packages
find /var/lib/lorkhanserver/backups/sql -xdev -type f -name 'sql-*.sql*' -exec chown lorkhan:www-data -- {} + -exec chmod 0640 -- {} +
find /var/lib/lorkhanserver/backups -xdev -type f -name '*.json' -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
install -d -o www-data -g www-data -m 0750 /var/lib/lorkhanserver/credentials
find /var/lib/lorkhanserver/credentials -xdev -type f -name 'provider-keys.json' -exec chown www-data:www-data -- {} + -exec chmod 0640 -- {} +
bash "${source_root}/deploy/provision-logs.sh"

stage_root=$(mktemp -d /var/tmp/lorkhanserver-stage.XXXXXX)
cleanup_stage() {
    if [[ -n ${stage_root:-} && ${stage_root} == /var/tmp/lorkhanserver-stage.* ]]; then
        rm -rf -- "${stage_root}"
    fi
}
trap cleanup_stage EXIT

# Both fresh installs and updates stage the same runtime payload.
bash "${source_root}/scripts/stage-runtime.sh" "${source_root}" "${stage_root}"

# Fail before changing the live tree when a staged PHP file is syntactically invalid.
while IFS= read -r -d '' php_file; do
    php -l "${php_file}" >/dev/null
done < <(find "${stage_root}" -type f -name '*.php' -print0)

# Keep the previous code and Apache route available for rollback; persistent data is external.
rollback_root=$(mktemp -d /var/backups/lorkhanserver-code.XXXXXX)
if [[ -d ${target_root} ]]; then
    rsync -a "${target_root}/" "${rollback_root}/code/"
fi
if [[ -f /etc/apache2/sites-available/lorkhanserver.conf ]]; then
    cp -p /etc/apache2/sites-available/lorkhanserver.conf "${rollback_root}/apache.conf"
fi
if [[ $(ps -p 1 -o comm=) == systemd ]]; then
    for worker_unit in lorkhanserver-worker.timer lorkhanserver-worker.service \
        lorkhanserver-worker-interactive.timer lorkhanserver-worker-interactive.service; do
        if systemctl cat "${worker_unit}" >/dev/null 2>&1; then
            systemctl stop "${worker_unit}"
        fi
    done
elif command -v service >/dev/null && [[ -e /etc/init.d/lorkhanserver-worker ]]; then
    service lorkhanserver-worker stop >/dev/null 2>&1 || true
fi
install -d -m 0755 "${target_root}"
rsync -a --delete \
    --exclude=storage --exclude=vendor "${stage_root}/" "${target_root}/"
find "${target_root}" -type d -exec chmod 0755 {} +
find "${target_root}" -type f -exec chmod 0644 {} +
find "${target_root}/scripts" "${target_root}/deploy" -type f \
    \( -name '*.sh' -o -path '*/sysv/*' \) -exec sed -i 's/\r$//' {} +

# pgvector is not a trusted PostgreSQL extension, so the restricted runtime role cannot install it
# during a genuinely fresh migration. Keep extension ownership with PostgreSQL administration.
runuser -u postgres -- psql --dbname=lorkhan --set=ON_ERROR_STOP=1 \
    --command='CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS vector;' >/dev/null

LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${target_root}/scripts/migrate.php" up
LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${target_root}/scripts/check-prompt-trace-labels.php"
LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${target_root}/scripts/provision-default-connectors.php"
LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${target_root}/scripts/provision-default-descriptions.php"
LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${target_root}/scripts/provision-default-biographies.php"
LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${target_root}/scripts/backfill-morrowind-localities.php"
LORKHAN_CONFIG=/etc/lorkhanserver/server.php php "${target_root}/scripts/provision-default-oghma.php"

gateway=$(ip route show default | awk '/^default via / {print $3; exit}')
if [[ ! ${gateway} =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Could not determine the Windows-to-WSL gateway address." >&2
    exit 1
fi
sed \
    -e "s/@WSL_GATEWAY@/${gateway}/g" \
    -e "s/@LORKHAN_HTTP_PORT@/${http_port}/g" \
    "${target_root}/deploy/apache/lorkhanserver.conf" \
    > /etc/apache2/sites-available/lorkhanserver.conf
chmod 0644 /etc/apache2/sites-available/lorkhanserver.conf
sed -i -E "/^Listen[[:space:]]+(127\\.0\\.0\\.1|0\\.0\\.0\\.0):${http_port}$/d" /etc/apache2/ports.conf
printf '\nListen 0.0.0.0:%s\n' "${http_port}" >> /etc/apache2/ports.conf
# A global deny also protects the physical tree through other Apache virtual hosts.
install -m 0644 "${target_root}/deploy/apache/lorkhanserver-private.conf" \
    /etc/apache2/conf-available/lorkhanserver-private.conf
a2enconf lorkhanserver-private >/dev/null
a2enmod rewrite >/dev/null
a2ensite lorkhanserver.conf >/dev/null
apache2ctl configtest
service apache2 restart

if [[ $(ps -p 1 -o comm=) == systemd ]]; then
    command -v systemctl >/dev/null || { echo "Missing required command: systemctl" >&2; exit 1; }
    # Background and interactive lanes share hardening but never claim each other's job types.
    for worker_lane in lorkhanserver-worker lorkhanserver-worker-interactive; do
        install -m 0644 "${target_root}/deploy/systemd/${worker_lane}.service" \
            "/etc/systemd/system/${worker_lane}.service"
        install -m 0644 "${target_root}/deploy/systemd/${worker_lane}.timer" \
            "/etc/systemd/system/${worker_lane}.timer"
    done
    systemctl daemon-reload
    for worker_lane in lorkhanserver-worker lorkhanserver-worker-interactive; do
        systemctl enable --now "${worker_lane}.timer" >/dev/null
        systemctl reset-failed "${worker_lane}.service" >/dev/null 2>&1 || true
        systemctl start --no-block "${worker_lane}.service"
        [[ $(systemctl is-enabled "${worker_lane}.timer") == enabled ]]
        [[ $(systemctl is-active "${worker_lane}.timer") == active ]]
    done
else
    for command in service start-stop-daemon update-rc.d; do
        command -v "${command}" >/dev/null || { echo "Missing required command: ${command}" >&2; exit 1; }
    done
    install -d -m 0755 /usr/local/libexec
    install -m 0755 "${target_root}/deploy/sysv/lorkhanserver-worker-loop" \
        /usr/local/libexec/lorkhanserver-worker-loop
    install -m 0755 "${target_root}/deploy/sysv/lorkhanserver-worker" \
        /etc/init.d/lorkhanserver-worker
    update-rc.d lorkhanserver-worker defaults >/dev/null
    # SysV restart returns non-zero when the worker has not been started before.
    service lorkhanserver-worker stop >/dev/null 2>&1 || true
    service lorkhanserver-worker start
    for _ in {1..50}; do
        service lorkhanserver-worker status >/dev/null 2>&1 && break
        sleep 0.1
    done
    service lorkhanserver-worker status >/dev/null
fi

health=$(curl --fail --silent --show-error "http://127.0.0.1:${http_port}/LorkhanServer/api/v1/health")
if [[ ${health} != '{"schema":"lorkhan.health.v1"}' ]]; then
    echo "Unexpected health response." >&2
    exit 1
fi

# Refresh the private source-only reset artifact only after the deployed schema is current.
bash "${source_root}/scripts/deploy-factory-database.sh" "${source_root}" "${pg_major}"

echo "Deployed ${target_root}"
echo "Rollback code and Apache route: ${rollback_root}"
echo "Health: http://127.0.0.1:${http_port}/LorkhanServer/api/v1/health"
echo "Management: http://127.0.0.1:${http_port}/LorkhanServer/manage"
echo "Persistent database, media, logs, and secrets were preserved."
