#!/usr/bin/env python3
"""Isolated SQL reader. Its JSON output remains untrusted and must be validated by the import worker."""
import json
import subprocess
import sys

MAX_OUTPUT = 1024 * 1024 * 1024
MAX_ROW = 8 * 1024 * 1024
written = 0


def emit(record):
    """Bound the normal exporter stream; the parent must separately cap all sandbox stdout."""
    global written
    payload = json.dumps(record, ensure_ascii=True, separators=(",", ":")) + "\n"
    written += len(payload)
    if len(payload) > MAX_ROW or written > MAX_OUTPUT:
        raise RuntimeError("import_output_too_large")
    sys.stdout.write(payload)


def query(sql):
    """Run only reader-owned queries against the private socket; never use host connection defaults."""
    return subprocess.check_output(["psql", "-X", "-h", "/scratch", "-p", "5432", "-d", "imported", "-At", "-v", "ON_ERROR_STOP=1", "-c", sql], stderr=subprocess.DEVNULL, text=True)


try:
    subprocess.run(["initdb", "-D", "/scratch/pg", "-A", "trust", "--no-locale", "-E", "UTF8"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run(["pg_ctl", "-D", "/scratch/pg", "-o", "-h '' -k /scratch -p 5432 -c shared_buffers=16MB -c max_connections=10", "-l", "/scratch/postgres.log", "start"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run(["createdb", "-h", "/scratch", "-p", "5432", "imported"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run(["psql", "-X", "-h", "/scratch", "-p", "5432", "-d", "imported", "-v", "ON_ERROR_STOP=1", "-f", "/input.sql"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    # Upgrade known historical Lorkhan schemas using the same checksum-checked runner as deployment.
    # Uploaded SQL and all resulting data remain inside this untrusted sandbox.
    subprocess.run(["/php-runtime", "-n", "-d", "extension=pdo.so", "-d", "extension=pdo_pgsql.so", "/upgrade.php"],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    tables = query("SELECT jsonb_build_object('schema',n.nspname,'name',c.relname,'columns',(SELECT jsonb_agg(a.attname ORDER BY a.attnum) FROM pg_attribute a WHERE a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped))::text FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('public','lorkhan_internal') AND c.relkind IN ('r','p') AND NOT EXISTS(SELECT 1 FROM pg_depend d WHERE d.classid='pg_class'::regclass AND d.objid=c.oid AND d.deptype='e') ORDER BY n.nspname,c.relname").splitlines()
    if len(tables) > 256:
        raise RuntimeError("import_too_many_tables")
    emit({"kind": "header", "format": "lorkhan.import-data.v2"})
    for line in tables:
        table = json.loads(line)
        identifier = '.'.join('"' + table[key].replace('"', '""') + '"' for key in ("schema", "name"))
        # Export owned counters separately: maximum surviving row IDs omit deleted allocations.
        sequences = {}
        relation_literal = "'" + identifier.replace("'", "''") + "'"
        owned = query("SELECT jsonb_build_object('column',attname,'sequence',pg_get_serial_sequence(" + relation_literal + ",attname))::text FROM pg_attribute WHERE attrelid=" + relation_literal + "::regclass AND attnum>0 AND NOT attisdropped AND pg_get_serial_sequence(" + relation_literal + ",attname) IS NOT NULL ORDER BY attname")
        for owned_line in owned.splitlines():
            owner = json.loads(owned_line)
            sequences[owner['column']] = json.loads(query("SELECT jsonb_build_object('last_value',last_value::text,'is_called',is_called)::text FROM " + owner['sequence']))
        emit({"kind": "table", **table, "sequences": sequences})
        # Text representations preserve numeric precision, PostgreSQL arrays and bytea without JSON coercion.
        cells = ','.join("('" + column.replace("'", "''") + "',t.\"" + column.replace('"', '""') + '\"::text)' for column in table['columns'])
        row_query = "SELECT (SELECT jsonb_object_agg(k,v) FROM (VALUES " + cells + ") AS cells(k,v))::text FROM " + identifier + " t"
        with subprocess.Popen(["psql", "-X", "-h", "/scratch", "-p", "5432", "-d", "imported", "-At", "-v", "ON_ERROR_STOP=1", "-c", row_query], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True) as reader:
            while True:
                row = reader.stdout.readline(MAX_ROW + 1)
                if not row:
                    break
                if len(row) > MAX_ROW:
                    reader.kill()
                    raise RuntimeError("import_row_too_large")
                emit({"kind": "row", "schema": table["schema"], "table": table["name"], "data": json.loads(row)})
            if reader.wait() != 0:
                raise RuntimeError("import_read_failed")
    emit({"kind": "complete"})
except Exception:
    sys.stderr.write("isolated_sql_import_failed\n")
    sys.exit(1)
finally:
    subprocess.run(["pg_ctl", "-D", "/scratch/pg", "-m", "immediate", "stop"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
