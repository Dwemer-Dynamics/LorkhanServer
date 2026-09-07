#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/lorkhan-management-http.XXXXXX")
PG_PORT=${LORKHAN_MANAGEMENT_PG_PORT:-55463}
HTTP_PORT=${LORKHAN_MANAGEMENT_HTTP_PORT:-58463}
PHP_PID=
cleanup(){ [ -z "$PHP_PID" ] || kill "$PHP_PID" >/dev/null 2>&1 || true; pg_ctl -D "$TMP/data" -m immediate stop >/dev/null 2>&1 || true; rm -rf "$TMP"; }
trap cleanup EXIT HUP INT TERM
initdb -D "$TMP/data" -A trust --no-locale -E UTF8 >/dev/null
pg_ctl -D "$TMP/data" -o "-h 127.0.0.1 -k $TMP -p $PG_PORT" -l "$TMP/postgres.log" start >/dev/null
createdb -h 127.0.0.1 -p "$PG_PORT" lorkhan_management_http
mkdir "$TMP/control" "$TMP/state"
mkdir "$TMP/control/credentials"
DSN="pgsql:host=127.0.0.1;port=$PG_PORT;dbname=lorkhan_management_http"
CONFIG="$ROOT/conf/server.test.php"
TOKEN_HASH=0f007385b6f9d4b7eeb2748605afe1a984a0a3bfa3f014d09e2a784ce9e5cd1a
MANAGE_HASH=$(printf manage-secret | shasum -a 256 | cut -d' ' -f1)
LORKHAN_CONFIG="$CONFIG" LORKHAN_TEST_DSN="$DSN" LORKHAN_TEST_PROVIDER_CONTROL="$TMP/control" php "$ROOT/scripts/migrate.php" up >/dev/null
psql -h 127.0.0.1 -p "$PG_PORT" -d lorkhan_management_http -v ON_ERROR_STOP=1 -c "INSERT INTO lorkhan_internal.installations(installation_id,token_fingerprint) VALUES ('00000000-0000-4000-8000-000000000001','$TOKEN_HASH')" >/dev/null
# Historical, unassigned attempts exercise cost date bounds and chart totals past the 100-group detail limit.
psql -h 127.0.0.1 -p "$PG_PORT" -d lorkhan_management_http -v ON_ERROR_STOP=1 >/dev/null <<'SQL'
-- Catalog filtering must reach beyond the former 5,000-row UI cutoff.
INSERT INTO public.bio_templates(npc_name,core)
SELECT 'ZZZ Pagination Fixture '||lpad(n::text,5,'0'),'Pagination summary'
FROM generate_series(1,5005) n;
INSERT INTO public.bio_templates(npc_name,core) VALUES ('ZZZ Literal %_ Name','Literal wildcard summary');
INSERT INTO lorkhan_internal.knowledge_documents
    (document_id,installation_id,title,topic,content,content_sha256,lexical_terms,provenance,knowledge_class,topic_desc_basic,knowledge_class_basic,category)
SELECT md5('biography-knowledge-'||n)::uuid,'00000000-0000-4000-8000-000000000001',
    'Biography Knowledge Fixture '||n,'Biography Knowledge Fixture '||n,'AdvancedOnlyHiddenText',
    encode(sha256(convert_to('AdvancedOnlyHiddenText','UTF8')),'hex'),'{}','{}','scholar','BiographyNeedle basic text '||n,'Balmora','Lore'
FROM generate_series(1,53) n;
INSERT INTO lorkhan_internal.knowledge_documents
    (document_id,installation_id,title,topic,content,content_sha256,lexical_terms,provenance,knowledge_class,topic_desc_basic,knowledge_class_basic,category)
VALUES
    (md5('biography-public')::uuid,'00000000-0000-4000-8000-000000000001','Public Fixture','Public Fixture','<b>Visible</b> &amp; readable',encode(sha256(convert_to('<b>Visible</b> &amp; readable','UTF8')),'hex'),'{}','{}','','Visible basic summary','', 'Public'),
    (md5('biography-denied')::uuid,'00000000-0000-4000-8000-000000000001','Denied Fixture','Denied Fixture','DeniedAdvancedText',encode(sha256(convert_to('DeniedAdvancedText','UTF8')),'hex'),'{}','{}','secret','DeniedBasicText','secret','Hidden');
INSERT INTO lorkhan_internal.provider_attempts
 (provider_attempt_id,provider_kind,provider_name,operation,model,attempt_number,state,started_at,finished_at,metadata)
SELECT gen_random_uuid(),'llm','fixture','dialogue','model-'||n,1,'succeeded','2020-12-31 00:00:00+00','2020-12-31 00:00:01+00','{"usage":{"cost_usd":1}}'::jsonb
FROM generate_series(1,101) n;
INSERT INTO lorkhan_internal.provider_attempts
 (provider_attempt_id,provider_kind,provider_name,operation,model,attempt_number,state,started_at,finished_at,metadata)
SELECT gen_random_uuid(),'llm','fixture',operation,'boundary',1,'succeeded',started::timestamptz,started::timestamptz,metadata::jsonb
FROM (VALUES
 ('outside','2020-12-27 23:59:59+00','{"usage":{"cost_usd":100}}'),
 ('diary','2020-12-28 00:00:00+00','{"usage":{"cost_usd":2}}'),
 ('dialogue','2020-12-31 23:59:59+00','{"usage":{"cost_usd":2}}'),
 ('diary','2021-01-01 00:00:00+00','{"usage":{"cost_usd":3}}'),
 ('diary','2021-01-03 23:59:59+00','{"usage":{"cost_usd":4}}'),
 ('outside','2021-01-04 00:00:00+00','{"usage":{"cost_usd":200}}'),
 ('unknown','2020-12-31 12:00:00+00','{}'),
 ('unknown','2020-12-31 12:00:00+00','{"usage":{"cost_usd":-1}}'),
 ('unknown','2020-12-31 12:00:00+00','{"usage":{"cost_usd":"2"}}')
) fixture(operation,started,metadata);
INSERT INTO lorkhan_internal.operational_audit(audit_id,category,action,scope,detail,created_at)
VALUES (md5('health-reader-fixture')::uuid,'health-reader-fixture','safe_scope',
    '{"installation_id":"00000000-0000-4000-8000-000000000001","profile_id":"00000000-0000-4000-8000-000000000099","api_key":"hidden-scope-fixture"}',
    '{"raw_error":"hidden-audit-detail-fixture"}','2020-12-31 12:00:00+00');
SQL
PHP_CLI_SERVER_WORKERS=2 LORKHAN_CONFIG="$CONFIG" LORKHAN_TEST_DSN="$DSN" LORKHAN_TEST_PROVIDER_CONTROL="$TMP/control" \
 LORKHAN_PAIRING_TOKEN_HASH="$TOKEN_HASH" LORKHAN_MANAGEMENT_SECRET_HASH="$MANAGE_HASH" \
 php -S "127.0.0.1:$HTTP_PORT" -t "$ROOT" "$ROOT/index.php" >"$TMP/php.log" 2>&1 &
PHP_PID=$!
i=0; until curl -fsS "http://127.0.0.1:$HTTP_PORT/LorkhanServer/api/v1/health" >/dev/null 2>&1; do i=$((i+1)); if [ "$i" -ge 100 ]; then python3 -c 'import sys;print(open(sys.argv[1]).read())' "$TMP/php.log" >&2; exit 1; fi; sleep .05; done
if ! python3 "$ROOT/scripts/test/management_http.py" "http://127.0.0.1:$HTTP_PORT"; then
  cat "$TMP/php.log" >&2
  exit 1
fi
