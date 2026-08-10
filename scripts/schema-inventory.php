<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--check', '--write'], true)) {
    fwrite(STDERR, "Usage: php scripts/schema-inventory.php [--check|--write]\n");
    exit(2);
}

$dsn = getenv('ALMSIVI_SCHEMA_DSN') ?: (getenv('ALMSIVI_TEST_DSN') ?: 'pgsql:dbname=almsivi');
$db = new PDO($dsn, getenv('ALMSIVI_TEST_DB_USER') ?: null, getenv('ALMSIVI_TEST_DB_PASSWORD') ?: null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec("SET client_encoding TO 'UTF8'");
$serverEncoding = (string) $db->query('SHOW server_encoding')->fetchColumn();
$clientEncoding = (string) $db->query('SHOW client_encoding')->fetchColumn();
if ($serverEncoding !== 'UTF8' || $clientEncoding !== 'UTF8') {
    throw new RuntimeException("PostgreSQL UTF-8 contract failed: server={$serverEncoding}; client={$clientEncoding}");
}

$relations = $db->query(
    "SELECT n.nspname AS schema_name,c.relname AS relation_name,"
    . "CASE c.relkind WHEN 'r' THEN 'table' WHEN 'p' THEN 'partitioned_table' WHEN 'v' THEN 'view' END AS relation_kind,"
    . "pg_get_userbyid(c.relowner) AS owner_name "
    . "FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace "
    . "WHERE n.nspname IN ('almsivi_internal','public') AND c.relkind IN ('r','p','v') "
    . "ORDER BY n.nspname,c.relname"
)->fetchAll();
$relationKeys = array_fill_keys(array_map(
    static fn(array $row): string => $row['schema_name'] . '.' . $row['relation_name'],
    $relations,
), true);

// Scan maintained runtime PHP only; migrations/tests are evidence, not production readers or writers.
function runtimeReferences(string $root, array $relations, array $relationKeys): array
{
    $files = [];
    foreach (['src', 'public', 'workers'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] =
                    (string) file_get_contents($file->getPathname());
            }
        }
    }
    ksort($files, SORT_STRING);

    $references = [];
    foreach ($relations as $relation) {
        $schema = (string) $relation['schema_name'];
        $name = (string) $relation['relation_name'];
        $key = $schema . '.' . $name;
        $hasInternalTwin = isset($relationKeys['almsivi_internal.' . $name]);
        $target = $schema === 'almsivi_internal' || !$hasInternalTwin
            ? '(?:(?:' . preg_quote($schema, '~') . ')\s*\.\s*)?' . preg_quote($name, '~')
            : preg_quote($schema, '~') . '\s*\.\s*' . preg_quote($name, '~');
        $writer = '~\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?|MERGE\s+INTO)\s+' . $target . '\b~i';
        $reader = '~\b(?:FROM|JOIN)\s+' . $target . '\b~i';
        $references[$key] = ['writers' => [], 'readers' => []];
        foreach ($files as $path => $content) {
            if (preg_match($writer, $content) === 1) $references[$key]['writers'][] = $path;
            if (preg_match($reader, $content) === 1) $references[$key]['readers'][] = $path;
        }
    }
    return $references;
}

$references = runtimeReferences($root, $relations, $relationKeys);
$excludedPublic = [];
$morrowindAdapted = array_fill_keys([
    'books','currentmission','descriptions','descriptions_custom','combined_descriptions','eventlog',
    'eventlog_view','faction_vanilla','factions','game_plugins','locations','locations_v','market_cache',
    'named_cell','questlog','quests','speech_view',
], true);

function disposition(string $schema, string $name, array $excludedPublic, array $morrowindAdapted): string
{
    if ($schema === 'almsivi_internal') {
        if (str_ends_with($name, '_metadata')) return 'typed_companion_metadata';
        return 'typed_authority';
    }
    if (isset($excludedPublic[$name])) return 'excluded_read_only_compatibility';
    if (isset($morrowindAdapted[$name])) return 'morrowind_adapted_herika_contract';
    return 'exact_herika_compatibility_contract';
}

function retention(string $schema, string $name, string $disposition): string
{
    if (str_starts_with($disposition, 'excluded_')) return 'No new rows; retain legacy compatibility state until an approved removal migration.';
    if ($schema === 'public') return 'Projection lifetime follows its typed ALMSIVI source and audit policy.';
    if ($name === 'media_objects') return 'Expires by media ownership/expiry policy, then bounded cleanup.';
    if (in_array($name, ['durable_jobs','durable_job_attempts','durable_job_dead_letters','provider_attempts','operational_audit'], true)) {
        return 'Bounded operational retention and explicit maintenance.';
    }
    if (preg_match('/(?:profiles|revisions|configuration|prompts|memory|relationship|knowledge|narrative)/', $name) === 1) {
        return 'Revisioned installation/playthrough scope until explicit delete or retention action.';
    }
    return 'Installation/session scoped according to the typed repository retention policy.';
}

$columnStatement = $db->prepare(
    "SELECT cols.ordinal_position,cols.column_name,format_type(attr.atttypid,attr.atttypmod) AS data_type,"
    . "cols.is_nullable,cols.column_default,cols.collation_name "
    . "FROM information_schema.columns cols "
    . "JOIN pg_namespace ns ON ns.nspname=cols.table_schema "
    . "JOIN pg_class rel ON rel.relnamespace=ns.oid AND rel.relname=cols.table_name "
    . "JOIN pg_attribute attr ON attr.attrelid=rel.oid AND attr.attnum=cols.ordinal_position "
    . "WHERE cols.table_schema=:schema AND cols.table_name=:relation ORDER BY cols.ordinal_position"
);
$constraintStatement = $db->prepare(
    "SELECT c.conname AS name,CASE c.contype WHEN 'p' THEN 'primary_key' WHEN 'u' THEN 'unique' "
    . "WHEN 'f' THEN 'foreign_key' WHEN 'c' THEN 'check' ELSE c.contype::text END AS kind,"
    . "pg_get_constraintdef(c.oid,true) AS definition "
    . "FROM pg_constraint c JOIN pg_class r ON r.oid=c.conrelid JOIN pg_namespace n ON n.oid=r.relnamespace "
    . "WHERE n.nspname=:schema AND r.relname=:relation ORDER BY kind,name"
);
$indexStatement = $db->prepare(
    "SELECT idx.relname AS name,pg_get_indexdef(i.indexrelid) AS definition "
    . "FROM pg_index i JOIN pg_class r ON r.oid=i.indrelid JOIN pg_namespace n ON n.oid=r.relnamespace "
    . "JOIN pg_class idx ON idx.oid=i.indexrelid WHERE n.nspname=:schema AND r.relname=:relation "
    . "ORDER BY idx.relname"
);
$databaseOwner = (string) $db->query(
    'SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = current_database()'
)->fetchColumn();
$objects = [];
foreach ($relations as $relation) {
    $schema = (string) $relation['schema_name'];
    $name = (string) $relation['relation_name'];
    $key = $schema . '.' . $name;
    $columnStatement->execute(['schema' => $schema, 'relation' => $name]);
    $columns = array_map(static function (array $column): array {
        $type = (string) $column['data_type'];
        $textual = preg_match('/(?:text|char|json|name|xml)/i', $type) === 1;
        return [
            'ordinal' => (int) $column['ordinal_position'],
            'name' => (string) $column['column_name'],
            'type' => $type,
            'nullable' => $column['is_nullable'] === 'YES',
            'default' => $column['column_default'],
            'collation' => $column['collation_name'] ?: 'database_default',
            'encoding' => $textual ? 'UTF8' : 'not_textual',
        ];
    }, $columnStatement->fetchAll());
    $constraintStatement->execute(['schema' => $schema, 'relation' => $name]);
    $indexStatement->execute(['schema' => $schema, 'relation' => $name]);
    $objectDisposition = disposition($schema, $name, $excludedPublic, $morrowindAdapted);
    if (str_starts_with($objectDisposition, 'excluded_') && $references[$key]['writers'] !== []) {
        throw new RuntimeException($key . ' has excluded runtime writers: ' . implode(', ', $references[$key]['writers']));
    }
    $objects[] = [
        'schema' => $schema,
        'name' => $name,
        'kind' => (string) $relation['relation_kind'],
        'owner' => $relation['owner_name'] === $databaseOwner ? '<database-owner>' : (string) $relation['owner_name'],
        'disposition' => $objectDisposition,
        'retention' => retention($schema, $name, $objectDisposition),
        'writers' => $references[$key]['writers'],
        'readers' => $references[$key]['readers'],
        'columns' => $columns,
        'constraints' => $constraintStatement->fetchAll(),
        'indexes' => $indexStatement->fetchAll(),
    ];
}

$inventory = [
    'format' => 'almsivi.schema-inventory.v1',
    'reference_commits' => [
        'herikaserver' => 'c973f5c8fde2d01cb8211be3d5f96d1783663da4',
        'dialecticserver' => '4f3d8fed834b283fd53ff0655ddd091d849dea1d',
    ],
    'server_encoding' => $serverEncoding,
    'client_encoding' => $clientEncoding,
    'relations' => $objects,
];
$canonical = json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$encoded = json_encode($inventory, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$kinds = array_count_values(array_column($objects, 'kind'));
$dispositions = array_count_values(array_column($objects, 'disposition'));
ksort($kinds, SORT_STRING);
ksort($dispositions, SORT_STRING);
$summary = [
    'format' => 'almsivi.schema-inventory-summary.v1',
    'generated_inventory' => 'build/schema-inventory.json',
    'reference_commits' => $inventory['reference_commits'],
    'server_encoding' => $serverEncoding,
    'client_encoding' => $clientEncoding,
    'inventory_sha256' => hash('sha256', $canonical),
    'relation_count' => count($objects),
    'column_count' => array_sum(array_map(static fn(array $object): int => count($object['columns']), $objects)),
    'relation_kinds' => $kinds,
    'dispositions' => $dispositions,
];
$summaryEncoded = json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$output = $root . '/build/schema-inventory.json';
$summaryOutput = $root . '/docs/evidence/schema-inventory-summary.json';
if ($mode === '--write') {
    if (!is_dir(dirname($output))) mkdir(dirname($output), 0770, true);
    file_put_contents($output, $encoded, LOCK_EX);
    file_put_contents($summaryOutput, $summaryEncoded, LOCK_EX);
    fwrite(STDOUT, 'wrote schema inventory: ' . count($objects) . " relations; summary hash " . $summary['inventory_sha256'] . "\n");
    exit(0);
}
if (!is_file($summaryOutput) || !hash_equals(hash('sha256', (string) file_get_contents($summaryOutput)), hash('sha256', $summaryEncoded))) {
    fwrite(STDERR, "Schema inventory is stale; run php scripts/schema-inventory.php --write against a fresh migrated database.\n");
    exit(1);
}
fwrite(STDOUT, 'schema inventory matches: ' . count($objects) . " relations; summary hash " . $summary['inventory_sha256'] . "\n");
