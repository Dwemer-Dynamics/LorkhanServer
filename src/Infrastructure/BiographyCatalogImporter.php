<?php

declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class BiographyCatalogImporter
{
    private const FORMAT = 'almsivi.morrowind-biography-preflight.v1';
    private const MAX_ROWS = 5000;
    private const MAX_BIOGRAPHIES_BYTES = 16_777_216;
    private const OFFICIAL_PLUGINS = ['Morrowind.esm', 'Tribunal.esm', 'Bloodmoon.esm'];
    private const LOCK_ID = 4_701_950_050;
    private const CHIM_FIELDS = [
        'npc_name', 'oghma_knowledge_tags', 'core', 'npc_static_bio', 'appearance', 'personality',
        'relationships', 'occupation', 'skills', 'speechstyle', 'goals', 'voiceid', 'gender', 'race', 'refid',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** Validate a generated CHIM-format biography package without changing PostgreSQL. */
    public function plan(string $biographiesPath, string $manifestPath, string $catalogVersion): array
    {
        $package = $this->loadPackage($biographiesPath, $manifestPath, $catalogVersion);
        return [
            'schema' => 'almsivi.biography-catalog-plan.v1',
            'catalog_version' => $catalogVersion,
            'valid' => $package['errors'] === [],
            'row_count' => count($package['rows']),
            'duplicate_count' => $package['duplicate_count'],
            'invalid_count' => count($package['errors']),
            'errors' => $package['errors'],
            'biographies_sha256' => $package['biographies_sha256'],
            'manifest_sha256' => $package['manifest_sha256'],
        ];
    }

    /** Activate one reviewed factory catalog and replace only CHIM factory biography rows. */
    public function apply(string $biographiesPath, string $manifestPath, string $catalogVersion): array
    {
        $package = $this->loadPackage($biographiesPath, $manifestPath, $catalogVersion);
        if ($package['errors'] !== []) {
            throw new InvalidArgumentException('invalid_biography_catalog_package: ' . implode('; ', $package['errors']));
        }
        $plan = $this->planResult($package, $catalogVersion);
        return $this->transaction(function () use ($package, $catalogVersion, $plan): array {
            $this->db->exec('SELECT pg_advisory_xact_lock(' . self::LOCK_ID . ')');
            $existing = $this->catalogByVersion($catalogVersion);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['biographies_sha256'], $package['biographies_sha256'])
                    || !hash_equals((string) $existing['manifest_sha256'], $package['manifest_sha256'])) {
                    throw new RuntimeException('biography_catalog_version_conflict');
                }
                if ($existing['state'] === 'active') {
                    return $plan + ['applied' => false, 'idempotent' => true, 'catalog_id' => $existing['catalog_id']];
                }
                $active = $this->activeCatalog() ?? throw new RuntimeException('no_active_biography_catalog');
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $this->db->prepare("UPDATE biography_catalogs SET state='superseded',superseded_at=:now WHERE catalog_id=:id")
                    ->execute(['now' => $now, 'id' => $active['catalog_id']]);
                $this->db->prepare("UPDATE biography_catalogs SET state='active',previous_catalog_id=:previous,activated_at=:now,superseded_at=NULL WHERE catalog_id=:id")
                    ->execute(['previous' => $active['catalog_id'], 'now' => $now, 'id' => $existing['catalog_id']]);
                $this->projectCatalog((string) $existing['catalog_id']);
                return $plan + ['applied' => true, 'idempotent' => false, 'reactivated' => true,
                    'catalog_id' => $existing['catalog_id']];
            }

            $active = $this->activeCatalog();
            if ($active === null) $active = $this->snapshotCurrentFactoryCatalog();
            $catalogId = Uuid::v4();
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $insertCatalog = $this->db->prepare(<<<'SQL'
INSERT INTO biography_catalogs(catalog_id,catalog_version,source_kind,format_version,model,
    biographies_sha256,manifest_sha256,generator_sha256,official_content_sha256,row_count,state,
    previous_catalog_id,imported_at,activated_at)
VALUES(:id,:version,'imported',:format,:model,:biographies,:manifest,:generator,
    CAST(:content_hashes AS jsonb),:rows,'superseded',:previous,:now,:now)
SQL);
            $insertCatalog->execute([
                'id' => $catalogId, 'version' => $catalogVersion, 'format' => $package['format_version'],
                'model' => $package['model'], 'biographies' => $package['biographies_sha256'],
                'manifest' => $package['manifest_sha256'], 'generator' => $package['generator_sha256'],
                'content_hashes' => json_encode($package['official_content_sha256'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'rows' => count($package['rows']), 'previous' => $active['catalog_id'], 'now' => $now,
            ]);
            $insertEntry = $this->entryStatement();
            foreach ($package['rows'] as $row) $insertEntry->execute(['catalog' => $catalogId] + $row);
            $this->db->prepare("UPDATE biography_catalogs SET state='superseded',superseded_at=:now WHERE catalog_id=:id")
                ->execute(['now' => $now, 'id' => $active['catalog_id']]);
            $this->db->prepare("UPDATE biography_catalogs SET state='active',activated_at=:now,superseded_at=NULL WHERE catalog_id=:id")
                ->execute(['now' => $now, 'id' => $catalogId]);
            $this->projectCatalog($catalogId);
            return $plan + ['applied' => true, 'idempotent' => false, 'catalog_id' => $catalogId];
        });
    }

    /** Install a bundled biography catalog once without overriding a later explicit rollback. */
    public function provision(string $biographiesPath, string $manifestPath, string $catalogVersion): array
    {
        $package = $this->loadPackage($biographiesPath, $manifestPath, $catalogVersion);
        if ($package['errors'] !== []) {
            throw new InvalidArgumentException('invalid_biography_catalog_package: ' . implode('; ', $package['errors']));
        }
        $existing = $this->catalogByVersion($catalogVersion);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['biographies_sha256'], $package['biographies_sha256'])
                || !hash_equals((string) $existing['manifest_sha256'], $package['manifest_sha256'])) {
                throw new RuntimeException('biography_catalog_version_conflict');
            }
            return ['schema' => 'almsivi.biography-catalog-provision.v1', 'applied' => false,
                'idempotent' => true, 'catalog_id' => $existing['catalog_id'],
                'catalog_version' => $existing['catalog_version'], 'state' => $existing['state']];
        }
        return $this->apply($biographiesPath, $manifestPath, $catalogVersion);
    }

    /** Restore an explicit biography catalog, or the active catalog's immediate predecessor. */
    public function rollback(?string $catalogVersion = null): array
    {
        return $this->transaction(function () use ($catalogVersion): array {
            $this->db->exec('SELECT pg_advisory_xact_lock(' . self::LOCK_ID . ')');
            $active = $this->activeCatalog() ?? throw new RuntimeException('no_active_biography_catalog');
            $target = $catalogVersion !== null
                ? $this->catalogByVersion($this->validateVersion($catalogVersion))
                : ($active['previous_catalog_id'] === null ? null : $this->catalogById((string) $active['previous_catalog_id']));
            if ($target === null) throw new RuntimeException('biography_catalog_rollback_target_missing');
            if ($target['catalog_id'] === $active['catalog_id']) {
                return ['schema' => 'almsivi.biography-catalog-rollback.v1', 'rolled_back' => false,
                    'catalog_id' => $target['catalog_id'], 'catalog_version' => $target['catalog_version']];
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $this->db->prepare("UPDATE biography_catalogs SET state='superseded',superseded_at=:now WHERE catalog_id=:id")
                ->execute(['now' => $now, 'id' => $active['catalog_id']]);
            $this->db->prepare("UPDATE biography_catalogs SET state='active',activated_at=:now,superseded_at=NULL WHERE catalog_id=:id")
                ->execute(['now' => $now, 'id' => $target['catalog_id']]);
            $this->projectCatalog((string) $target['catalog_id']);
            return ['schema' => 'almsivi.biography-catalog-rollback.v1', 'rolled_back' => true,
                'catalog_id' => $target['catalog_id'], 'catalog_version' => $target['catalog_version'],
                'row_count' => (int) $target['row_count']];
        });
    }

    /** Return catalog metadata without exposing generated biography prose. */
    public function status(): array
    {
        $rows = $this->db->query('SELECT catalog_id,catalog_version,source_kind,format_version,model,biographies_sha256,manifest_sha256,generator_sha256,official_content_sha256,row_count,state,previous_catalog_id,imported_at,activated_at,superseded_at FROM biography_catalogs ORDER BY activated_at DESC,catalog_id')->fetchAll();
        foreach ($rows as &$row) {
            $row['row_count'] = (int) $row['row_count'];
            $row['official_content_sha256'] = json_decode((string) $row['official_content_sha256'], true, 16, JSON_THROW_ON_ERROR);
        }
        unset($row);
        return ['schema' => 'almsivi.biography-catalog-status.v1', 'catalogs' => $rows];
    }

    /** Parse and cross-check exact identity and CHIM fields while collecting bounded diagnostics. */
    private function loadPackage(string $biographiesPath, string $manifestPath, string $catalogVersion): array
    {
        $this->validateVersion($catalogVersion);
        $errors = [];
        $biographiesRaw = $this->readUtf8File($biographiesPath, self::MAX_BIOGRAPHIES_BYTES, 'biographies');
        $manifestRaw = $this->readUtf8File($manifestPath, 8_388_608, 'manifest');
        try {
            $biographies = json_decode($biographiesRaw, true, 256, JSON_THROW_ON_ERROR);
            $manifest = json_decode($manifestRaw, true, 256, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new InvalidArgumentException('invalid_biography_catalog_json: ' . $error->getMessage(), 0, $error);
        }
        if (!is_array($biographies) || !array_is_list($biographies)) throw new InvalidArgumentException('invalid_biographies_array');
        if (!is_array($manifest) || array_is_list($manifest)) throw new InvalidArgumentException('invalid_biography_manifest_object');
        if (count($biographies) < 1 || count($biographies) > self::MAX_ROWS) $errors[] = 'biographies row count is invalid';
        $format = trim((string) ($manifest['format'] ?? ''));
        $model = trim((string) ($manifest['model'] ?? ''));
        $generatorSha = strtolower(trim((string) ($manifest['builder_sha256'] ?? '')));
        if ($format !== self::FORMAT) $errors[] = 'manifest format is not ' . self::FORMAT;
        if ($model === '' || strlen($model) > 256) $errors[] = 'manifest model is invalid';
        if ($generatorSha !== '' && preg_match('/^[0-9a-f]{64}$/D', $generatorSha) !== 1) $errors[] = 'manifest builder_sha256 is invalid';
        $contentHashes = $manifest['official_content_sha256'] ?? null;
        if (!is_array($contentHashes) || array_is_list($contentHashes)
            || array_diff(array_keys($contentHashes), self::OFFICIAL_PLUGINS) !== []
            || array_diff(self::OFFICIAL_PLUGINS, array_keys($contentHashes)) !== []) {
            $errors[] = 'manifest official_content_sha256 keys are invalid';
            $contentHashes = [];
        } else {
            foreach ($contentHashes as $plugin => $hash) {
                if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) $errors[] = "manifest hash for {$plugin} is invalid";
            }
            $contentHashes = array_replace(array_fill_keys(self::OFFICIAL_PLUGINS, ''), $contentHashes);
        }
        $selected = $manifest['selected_count'] ?? null;
        $completed = $manifest['completed_count'] ?? null;
        $failed = $manifest['failed_count'] ?? null;
        $items = $manifest['items'] ?? null;
        if (!is_int($selected) || !is_int($completed) || $selected !== count($biographies)
            || $completed !== count($biographies) || $failed !== 0) {
            $errors[] = 'manifest selected/completed/failed counts do not match biographies';
        }
        if (!is_array($items) || !array_is_list($items) || count($items) !== count($biographies)) {
            $errors[] = 'manifest items do not match biographies row count';
            $items = [];
        }
        $identities = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || array_is_list($item) || ($item['generation_status'] ?? null) !== 'complete') {
                $errors[] = 'manifest contains a non-complete item';
                continue;
            }
            $recordId = trim((string) ($item['record_id'] ?? ''));
            $contentFile = trim((string) ($item['content_file'] ?? ''));
            $displayName = trim((string) ($item['display_name'] ?? ''));
            $key = $this->key($contentFile, $recordId);
            if ($recordId === '' || $displayName === '' || !in_array($contentFile, self::OFFICIAL_PLUGINS, true)) {
                $errors[] = 'manifest item ' . ($index + 1) . ' has invalid identity';
            } elseif (isset($identities[$key])) {
                $errors[] = 'manifest contains duplicate canonical identity';
            } else {
                $identities[$key] = compact('contentFile', 'recordId', 'displayName');
            }
        }
        $rows = [];
        $seenNames = [];
        $seenIdentities = [];
        $duplicates = 0;
        foreach ($biographies as $index => $biography) {
            $line = $index + 1;
            if (!is_array($biography) || array_is_list($biography) || array_keys($biography) !== self::CHIM_FIELDS) {
                $errors[] = "biography row {$line} does not use the exact CHIM field order";
                continue;
            }
            $npcName = trim((string) $biography['npc_name']);
            $recordId = trim((string) $biography['refid']);
            $matchingIdentities = array_values(array_filter($identities,
                static fn(array $identity): bool => strcasecmp($identity['recordId'], $recordId) === 0));
            if (count($matchingIdentities) !== 1) {
                $errors[] = "biography row {$line} does not resolve one manifest identity";
                continue;
            }
            $identity = $matchingIdentities[0];
            $nameKey = mb_strtolower($npcName, 'UTF-8');
            $identityKey = $this->key($identity['contentFile'], $recordId);
            if (isset($seenNames[$nameKey]) || isset($seenIdentities[$identityKey])) {
                ++$duplicates;
                $errors[] = "biography row {$line} duplicates a canonical key";
                continue;
            }
            $seenNames[$nameKey] = true;
            $seenIdentities[$identityKey] = true;
            $rowErrors = [];
            if ($npcName === '' || strlen($npcName) > 128) $rowErrors[] = 'npc_name is invalid';
            if ($recordId === '' || strlen($recordId) > 256) $rowErrors[] = 'refid is invalid';
            foreach (['core','npc_static_bio','appearance','personality','occupation','skills','speechstyle','goals'] as $field) {
                $value = trim((string) $biography[$field]);
                if ($value === '' || strlen($value) > 16384 || str_contains($value, "\0")) $rowErrors[] = "{$field} is invalid";
            }
            $relationships = trim((string) $biography['relationships']);
            try {
                $decodedRelationships = json_decode($relationships, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($decodedRelationships)
                    || ($decodedRelationships !== [] && array_is_list($decodedRelationships))
                    || count($decodedRelationships) > 16) {
                    $rowErrors[] = 'relationships must be a bounded object';
                }
            } catch (Throwable) {
                $rowErrors[] = 'relationships is not valid JSON';
            }
            if ($rowErrors !== []) {
                $errors[] = "biography row {$line}: " . implode(', ', $rowErrors);
                continue;
            }
            $rows[] = [
                'content_file' => $identity['contentFile'], 'record_id' => $recordId,
                'display_name' => $identity['displayName'], 'npc_name' => $npcName,
                'oghma_knowledge_tags' => trim((string) $biography['oghma_knowledge_tags']),
                'core' => trim((string) $biography['core']), 'npc_static_bio' => trim((string) $biography['npc_static_bio']),
                'appearance' => trim((string) $biography['appearance']), 'personality' => trim((string) $biography['personality']),
                'relationships' => $relationships, 'occupation' => trim((string) $biography['occupation']),
                'skills' => trim((string) $biography['skills']), 'speechstyle' => trim((string) $biography['speechstyle']),
                'goals' => trim((string) $biography['goals']), 'voiceid' => $this->nullable($biography['voiceid']),
                'gender' => $this->nullable($biography['gender']), 'race' => $this->nullable($biography['race']),
            ];
        }
        if (array_diff_key($identities, $seenIdentities) !== [] || array_diff_key($seenIdentities, $identities) !== []) {
            $errors[] = 'manifest and biography identity sets differ';
        }
        return [
            'rows' => $rows, 'errors' => array_slice(array_values(array_unique($errors)), 0, 100),
            'duplicate_count' => $duplicates, 'format_version' => $format, 'model' => $model,
            'biographies_sha256' => hash('sha256', $biographiesRaw), 'manifest_sha256' => hash('sha256', $manifestRaw),
            'generator_sha256' => $generatorSha === '' ? null : $generatorSha,
            'official_content_sha256' => $contentHashes,
        ];
    }

    private function projectCatalog(string $catalogId): void
    {
        $this->db->exec('DELETE FROM public.bio_templates');
        $statement = $this->db->prepare(<<<'SQL'
INSERT INTO public.bio_templates(npc_name,oghma_knowledge_tags,core,npc_static_bio,appearance,personality,
    relationships,occupation,skills,speechstyle,goals,voiceid,gender,race,refid)
SELECT npc_name,oghma_knowledge_tags,core,npc_static_bio,appearance,personality,relationships,occupation,
    skills,speechstyle,goals,voiceid,gender,race,record_id
FROM biography_catalog_entries WHERE catalog_id=:catalog ORDER BY npc_name
SQL);
        $statement->execute(['catalog' => $catalogId]);
    }

    private function snapshotCurrentFactoryCatalog(): array
    {
        $current = $this->db->query('SELECT npc_name,oghma_knowledge_tags,core,npc_static_bio,appearance,personality,relationships,occupation,skills,speechstyle,goals,voiceid,gender,race,refid FROM public.bio_templates ORDER BY lower(npc_name)')->fetchAll();
        $sha = hash('sha256', json_encode($current, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $catalogId = Uuid::v4();
        $version = 'pre-catalog-' . substr($sha, 0, 16);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->db->prepare("INSERT INTO biography_catalogs(catalog_id,catalog_version,source_kind,biographies_sha256,row_count,state,imported_at,activated_at) VALUES(:id,:version,'legacy_snapshot',:sha,:rows,'active',:now,:now)")
            ->execute(['id' => $catalogId, 'version' => $version, 'sha' => $sha, 'rows' => count($current), 'now' => $now]);
        $insert = $this->entryStatement();
        foreach ($current as $row) {
            $insert->execute([
                'catalog' => $catalogId, 'content_file' => null, 'record_id' => (string) ($row['refid'] ?? $row['npc_name']),
                'display_name' => (string) $row['npc_name'], 'npc_name' => (string) $row['npc_name'],
                'oghma_knowledge_tags' => $row['oghma_knowledge_tags'], 'core' => $row['core'],
                'npc_static_bio' => $row['npc_static_bio'], 'appearance' => $row['appearance'],
                'personality' => $row['personality'], 'relationships' => $row['relationships'] ?: '{}',
                'occupation' => $row['occupation'], 'skills' => $row['skills'], 'speechstyle' => $row['speechstyle'],
                'goals' => $row['goals'], 'voiceid' => $row['voiceid'], 'gender' => $row['gender'], 'race' => $row['race'],
            ]);
        }
        return $this->catalogById($catalogId) ?? throw new RuntimeException('biography_factory_snapshot_failed');
    }

    private function entryStatement(): \PDOStatement
    {
        return $this->db->prepare(<<<'SQL'
INSERT INTO biography_catalog_entries(catalog_id,content_file,record_id,display_name,npc_name,
    oghma_knowledge_tags,core,npc_static_bio,appearance,personality,relationships,occupation,skills,
    speechstyle,goals,voiceid,gender,race)
VALUES(:catalog,:content_file,:record_id,:display_name,:npc_name,:oghma_knowledge_tags,:core,
    :npc_static_bio,:appearance,:personality,:relationships,:occupation,:skills,:speechstyle,:goals,
    :voiceid,:gender,:race)
SQL);
    }

    private function planResult(array $package, string $catalogVersion): array
    {
        return ['schema' => 'almsivi.biography-catalog-plan.v1', 'catalog_version' => $catalogVersion,
            'valid' => true, 'row_count' => count($package['rows']), 'duplicate_count' => 0, 'invalid_count' => 0,
            'errors' => [], 'biographies_sha256' => $package['biographies_sha256'],
            'manifest_sha256' => $package['manifest_sha256']];
    }

    private function activeCatalog(): ?array
    {
        $row = $this->db->query("SELECT * FROM biography_catalogs WHERE state='active'")->fetch();
        return $row === false ? null : $row;
    }

    private function catalogByVersion(string $version): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM biography_catalogs WHERE catalog_version=:version');
        $statement->execute(['version' => $version]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    private function catalogById(string $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM biography_catalogs WHERE catalog_id=:id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    private function readUtf8File(string $path, int $maxBytes, string $label): string
    {
        if (!is_file($path) || !is_readable($path)) throw new InvalidArgumentException("{$label} file is unavailable");
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > $maxBytes) throw new InvalidArgumentException("{$label} file size is invalid");
        $contents = file_get_contents($path);
        if ($contents === false || !mb_check_encoding($contents, 'UTF-8')) throw new InvalidArgumentException("{$label} must be valid UTF-8");
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }

    private function validateVersion(string $version): string
    {
        $version = trim($version);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $version) !== 1) throw new InvalidArgumentException('invalid_biography_catalog_version');
        return $version;
    }

    private function key(string $contentFile, string $recordId): string
    {
        return mb_strtolower(trim($contentFile), 'UTF-8') . "\0" . mb_strtolower(trim($recordId), 'UTF-8');
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function transaction(callable $callback): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $callback();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
