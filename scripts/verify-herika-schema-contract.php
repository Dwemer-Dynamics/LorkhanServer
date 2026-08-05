<?php

declare(strict_types=1);

$referenceDsn = getenv('HERIKA_REFERENCE_DSN') ?: 'pgsql:dbname=dwemer';
$targetDsn = getenv('ALMSIVI_SCHEMA_DSN') ?: 'pgsql:dbname=almsivi';
$referenceSchema = getenv('HERIKA_REFERENCE_SCHEMA') ?: 'public';
$targetSchema = getenv('ALMSIVI_HERIKA_SCHEMA') ?: 'public';
$user = getenv('ALMSIVI_SCHEMA_DB_USER') ?: null;
$password = getenv('ALMSIVI_SCHEMA_DB_PASSWORD') ?: null;

$tables = [
    'bio_templates', 'bio_templates_custom', 'core_api_badge', 'core_itt_connector',
    'core_llm_connector', 'core_narrator', 'core_npc_master', 'core_npc_master_history',
    'core_player', 'core_profiles', 'core_stt_connector', 'core_tts_connector',
    'core_tts_fallback', 'general_settings', 'prompts', 'responselog', 'speech',
    'actions_issued', 'audit_memory', 'audit_request', 'conf_opts', 'database_versioning',
    'log', 'moods_issued', 'rolemaster',
    'diarylog', 'memory', 'memory_summary', 'oghma', 'oghma_dynamic', 'physical_npc_diaries',
    'relationship_eval_queue', 'relationship_init_queue', 'rumors',
    'books', 'currentmission', 'descriptions', 'descriptions_custom', 'factions', 'game_plugins',
    'locations', 'named_cell', 'questlog', 'quests',
    'animations', 'animations_custom', 'core_action', 'core_action_custom', 'dynamic_bio',
    'import_rules', 'json_personalities', 'translations',
    'bgl_history', 'core_faction_politics_development', 'core_faction_politics_relation',
    'core_faction_politics_state', 'faction_vanilla', 'market_cache', 'master_packages',
    'npc_commitments', 'npc_profile_backup', 'oghma_context_rule', 'visual_context',
    'quest_asset_group_members', 'quest_asset_groups', 'quest_asset_imports', 'quest_asset_packs',
    'quest_assets', 'quest_item_types', 'quest_npc_own_templates', 'quest_npc_templates',
    'quest_outfits', 'quest_weapons', 'skyrim_quest_action_outbox', 'skyrim_quest_beat_state',
    'skyrim_quest_definitions', 'skyrim_quest_events', 'skyrim_quest_instances',
    'sneq_quests', 'sneq_quests_saved',
];
$views = ['combined_animations', 'combined_bio_templates', 'combined_core_action', 'combined_descriptions', 'memory_v'];
$sequences = [
    'actions_issued_rowid_seq','api_badge_id_seq','audit_request_rowid_seq','books_rowid_seq',
    'core_npc_master_history_history_id_seq','core_tts_fallback_id_seq','currentmission_rowid_seq',
    'diarylog_rowid_seq','itt_connector_id_seq','llm_connector_id_seq','log_rowid_seq',
    'memory_rowid_seq','memory_summary_rowid_seq','memory_uid_seq','npc_master_id_seq',
    'oghma_dynamic_id_seq','profiles_id_seq','questlog_rowid_seq','quests_rowid_seq',
    'relationship_eval_queue_id_seq','relationship_init_queue_id_seq','responselog_rowid_seq',
    'rolemaster_rowid_seq','rumors_id_seq','speech_rowid_seq','stt_connector_id_seq','tts_connector_id_seq',
    'core_action_id_seq','core_action_custom_id_seq','dynamic_bio_id_seq','import_rules_id_seq','translations_id_seq',
    'bgl_history_rowid_seq','core_faction_politics_development_id_seq','npc_commitments_id_seq',
    'oghma_context_rule_id_seq','visual_context_id_seq',
    'quest_asset_imports_id_seq','skyrim_quest_action_outbox_id_seq','skyrim_quest_events_id_seq',
    'sneq_quests_saved_history_id_seq',
];

// Build one strict catalog snapshot without reading table contents or secret values.
function catalogSnapshot(PDO $db, string $schema, array $tables, array $views, array $sequences): array
{
    $snapshot = ['tables' => [], 'views' => [], 'sequences' => []];
    $column = $db->prepare(
        "SELECT cols.column_name,cols.ordinal_position,cols.data_type,cols.udt_name,"
        . "format_type(attr.atttypid,attr.atttypmod) AS formatted_type,cols.character_maximum_length,"
        . "cols.numeric_precision,cols.numeric_scale,cols.is_nullable,cols.column_default,"
        . "cols.is_identity,cols.identity_generation FROM information_schema.columns cols "
        . "JOIN pg_namespace ns ON ns.nspname=cols.table_schema JOIN pg_class rel ON rel.relnamespace=ns.oid AND rel.relname=cols.table_name "
        . "JOIN pg_attribute attr ON attr.attrelid=rel.oid AND attr.attnum=cols.ordinal_position "
        . "WHERE cols.table_schema=:schema AND cols.table_name=:table ORDER BY cols.ordinal_position"
    );
    $constraints = $db->prepare(
        "SELECT c.contype,pg_get_constraintdef(c.oid,true) AS definition "
        . "FROM pg_constraint c JOIN pg_class r ON r.oid=c.conrelid JOIN pg_namespace n ON n.oid=r.relnamespace "
        . "WHERE n.nspname=:schema AND r.relname=:table ORDER BY c.contype,definition"
    );
    $indexes = $db->prepare(
        "SELECT pg_get_indexdef(i.indexrelid) AS definition FROM pg_index i "
        . "JOIN pg_class r ON r.oid=i.indrelid JOIN pg_namespace n ON n.oid=r.relnamespace "
        . "WHERE n.nspname=:schema AND r.relname=:table AND NOT i.indisprimary "
        . "ORDER BY definition"
    );
    foreach ($tables as $table) {
        $column->execute(['schema' => $schema, 'table' => $table]);
        $rows = $column->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new RuntimeException("Missing {$schema}.{$table}");
        }
        // Reindex visible columns so PostgreSQL attnum gaps left by already-dropped,
        // deprecated columns do not require recreating invisible catalog tombstones.
        foreach ($rows as $index => &$row) {
            $row['ordinal_position'] = $index + 1;
        }
        unset($row);
        $constraints->execute(['schema' => $schema, 'table' => $table]);
        $indexes->execute(['schema' => $schema, 'table' => $table]);
        $snapshot['tables'][$table] = [
            'columns' => $rows,
            'constraints' => $constraints->fetchAll(PDO::FETCH_COLUMN),
            'indexes' => $indexes->fetchAll(PDO::FETCH_COLUMN),
        ];
    }
    $viewStatement = $db->prepare(
        "SELECT pg_get_viewdef(c.oid,true) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace "
        . "WHERE n.nspname=:schema AND c.relname=:view AND c.relkind='v'"
    );
    foreach ($views as $view) {
        $viewStatement->execute(['schema' => $schema, 'view' => $view]);
        $definition = $viewStatement->fetchColumn();
        if ($definition === false) {
            throw new RuntimeException("Missing {$schema}.{$view}");
        }
        $snapshot['views'][$view] = $definition;
    }
    $sequenceStatement = $db->prepare(
        "SELECT start_value,min_value,max_value,increment_by,cycle,cache_size "
        . "FROM pg_sequences WHERE schemaname=:schema AND sequencename=:sequence"
    );
    foreach ($sequences as $sequence) {
        $sequenceStatement->execute(['schema' => $schema, 'sequence' => $sequence]);
        $row = $sequenceStatement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException("Missing {$schema}.{$sequence}");
        }
        $snapshot['sequences'][$sequence] = $row;
    }
    return $snapshot;
}

// Remove only schema qualification and formatting noise; semantic catalog differences remain visible.
function normalizeSnapshot(array $snapshot, string $schema): array
{
    $replace = static function (mixed $value) use ($schema, &$replace): mixed {
        if (is_array($value)) {
            return array_map($replace, $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        $value = str_replace(
            ["'{$schema}.", " {$schema}.", "\"{$schema}\".", "'public.", ' public.', '"public".'],
            ["'", ' ', '', "'", ' ', ''],
            $value
        );
        return preg_replace('/\s+/', ' ', trim($value));
    };
    return $replace($snapshot);
}

$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$reference = new PDO($referenceDsn, $user ?: null, $password ?: null, $options);
$target = new PDO($targetDsn, $user ?: null, $password ?: null, $options);
$expected = normalizeSnapshot(catalogSnapshot($reference, $referenceSchema, $tables, $views, $sequences), $referenceSchema);
$actual = normalizeSnapshot(catalogSnapshot($target, $targetSchema, $tables, $views, $sequences), $targetSchema);

if ($expected !== $actual) {
    foreach ($tables as $table) {
        if ($expected['tables'][$table] !== $actual['tables'][$table]) {
            fwrite(STDERR, "Schema mismatch: {$table}\n");
        }
    }
    foreach ($views as $view) {
        if ($expected['views'][$view] !== $actual['views'][$view]) {
            fwrite(STDERR, "View mismatch: {$view}\n");
        }
    }
    foreach ($sequences as $sequence) {
        if ($expected['sequences'][$sequence] !== $actual['sequences'][$sequence]) {
            fwrite(STDERR, "Sequence mismatch: {$sequence}\n");
        }
    }
    exit(1);
}

$expectedEventlog = normalizeSnapshot(
    catalogSnapshot($reference,$referenceSchema,['eventlog'],[],['eventlog_rowid_seq']),
    $referenceSchema
);
$actualEventlog = normalizeSnapshot(
    catalogSnapshot($target,'public',['eventlog'],[],['eventlog_rowid_seq']),
    'public'
);
if ($expectedEventlog !== $actualEventlog) {
    fwrite(STDERR,"Schema mismatch: public.eventlog\n");
    exit(1);
}

if ($targetSchema === 'public') {
    $actualTables = $target->query(
        "SELECT table_name FROM information_schema.tables WHERE table_schema='public' "
        . "AND table_type='BASE TABLE' ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $expectedTables = [...$tables, 'eventlog'];
    sort($expectedTables, SORT_STRING);
    if ($actualTables !== $expectedTables) {
        fwrite(STDERR, "Public table inventory is not the exact active Herika core contract; missing="
            . json_encode(array_values(array_diff($expectedTables,$actualTables))) . '; extra='
            . json_encode(array_values(array_diff($actualTables,$expectedTables))) . "\n");
        exit(1);
    }

    $actualViews = $target->query(
        "SELECT table_name FROM information_schema.views WHERE table_schema='public' ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $expectedViews = [...$views, 'eventlog_view', 'locations_v', 'speech_view'];
    sort($expectedViews, SORT_STRING);
    if ($actualViews !== $expectedViews) {
        fwrite(STDERR, "Public view inventory is not the exact active Herika core contract; missing="
            . json_encode(array_values(array_diff($expectedViews,$actualViews))) . '; extra='
            . json_encode(array_values(array_diff($actualViews,$expectedViews))) . "\n");
        exit(1);
    }

    $actualSequences = $target->query(
        "SELECT sequencename FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename"
    )->fetchAll(PDO::FETCH_COLUMN);
    $expectedSequences = [...$sequences, 'eventlog_rowid_seq'];
    sort($expectedSequences, SORT_STRING);
    if ($actualSequences !== $expectedSequences) {
        fwrite(STDERR, "Public sequence inventory is not the exact active Herika core contract; missing="
            . json_encode(array_values(array_diff($expectedSequences,$actualSequences))) . '; extra='
            . json_encode(array_values(array_diff($actualSequences,$expectedSequences))) . "\n");
        exit(1);
    }
}

$speechView = $target->query(
    "SELECT column_name FROM information_schema.columns WHERE table_schema=" . $target->quote($targetSchema)
    . " AND table_name='speech_view' ORDER BY ordinal_position"
)->fetchAll(PDO::FETCH_COLUMN);
$expectedSpeechView = [
    'sess','speaker','speech','location','listener','topic','localts','gamets','ts','rowid',
    'companions','audios','mood','emotion','emotion_intensity','utterance_id','mw_local_datetime','mw_event_datetime',
];
if ($speechView !== $expectedSpeechView) {
    fwrite(STDERR, "Morrowind speech_view adaptation mismatch\n");
    exit(1);
}

$locationView = $target->query(
    "SELECT column_name FROM information_schema.columns WHERE table_schema=" . $target->quote($targetSchema)
    . " AND table_name='locations_v' ORDER BY ordinal_position"
)->fetchAll(PDO::FETCH_COLUMN);
$expectedLocationView = [
    'name','formid','region','hold','tags','factions','is_interior','vanilla_location',
    'coords','refs','cleared','updated_at','world',
];
if ($locationView !== $expectedLocationView) {
    fwrite(STDERR, "Morrowind locations_v adaptation mismatch\n");
    exit(1);
}

$eventlogView = $target->query(
    "SELECT column_name FROM information_schema.columns WHERE table_schema='public' "
    . "AND table_name='eventlog_view' ORDER BY ordinal_position"
)->fetchAll(PDO::FETCH_COLUMN);
$expectedEventlogView = [
    'type','data','sess','gamets','localts','ts','rowid','people','location','party',
    'utterance_id','delivery_state','mw_local_datetime','mw_event_datetime',
];
if ($eventlogView !== $expectedEventlogView) {
    fwrite(STDERR,"Morrowind eventlog_view adaptation mismatch\n");
    exit(1);
}

fwrite(STDOUT, "Herika core schema contract matches {$referenceSchema} -> {$targetSchema}\n");
