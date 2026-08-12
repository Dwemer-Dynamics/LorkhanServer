<?php

declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class DescriptionCatalogImporter
{
    private const FORMAT = 'almsivi.morrowind-item-description-preflight.v1';
    private const OFFICIAL_PLUGINS = ['Morrowind.esm', 'Tribunal.esm', 'Bloodmoon.esm'];
    private const MAX_CSV_BYTES = 8_388_608;
    private const MAX_ROWS = 5_000;
    private const LOCK_ID = 6_525_393_698_659_163;

    public function __construct(private readonly PDO $db) {}

    /** Inspect a generated package without changing catalog or Herika compatibility tables. */
    public function plan(string $csvPath, string $manifestPath, string $catalogVersion): array
    {
        $package = $this->loadPackage($csvPath, $manifestPath, $catalogVersion);
        $current = $this->officialDescriptions();
        $incoming = [];
        foreach ($package['rows'] as $row) {
            $incoming[$this->key($row['plugin'], $row['baseid'])] = $row;
        }
        $inserted = $changed = $unchanged = 0;
        foreach ($incoming as $key => $row) {
            if (!isset($current[$key])) {
                ++$inserted;
            } elseif ($current[$key]['plugin'] === $row['plugin']
                && $current[$key]['baseid'] === $row['baseid']
                && $current[$key]['name'] === $row['name']
                && $current[$key]['description'] === $row['description']) {
                ++$unchanged;
            } else {
                ++$changed;
            }
        }
        return [
            'schema' => 'almsivi.description-catalog-plan.v1',
            'valid' => $package['errors'] === [],
            'catalog_version' => $catalogVersion,
            'format_version' => $package['format_version'],
            'prompt_sha256' => $package['prompt_sha256'],
            'model' => $package['model'],
            'csv_sha256' => $package['csv_sha256'],
            'manifest_sha256' => $package['manifest_sha256'],
            'official_content_sha256' => $package['official_content_sha256'],
            'row_count' => count($package['rows']),
            'inserted' => $inserted,
            'changed' => $changed,
            'unchanged' => $unchanged,
            'missing_from_import' => count(array_diff_key($current, $incoming)),
            'duplicate_count' => $package['duplicate_count'],
            'invalid_count' => count($package['errors']),
            'errors' => $package['errors'],
        ];
    }

    /** Activate one validated catalog and atomically project it into Herika's factory table. */
    public function apply(string $csvPath, string $manifestPath, string $catalogVersion): array
    {
        $package = $this->loadPackage($csvPath, $manifestPath, $catalogVersion);
        if ($package['errors'] !== []) {
            throw new InvalidArgumentException('invalid_catalog_package: ' . implode('; ', $package['errors']));
        }
        $plan = $this->plan($csvPath, $manifestPath, $catalogVersion);
        return $this->transaction(function () use ($package, $catalogVersion, $plan): array {
            $this->db->exec('SELECT pg_advisory_xact_lock(' . self::LOCK_ID . ')');
            $existing = $this->catalogByVersion($catalogVersion);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['csv_sha256'], $package['csv_sha256'])
                    || !hash_equals((string) $existing['manifest_sha256'], $package['manifest_sha256'])) {
                    throw new RuntimeException('catalog_version_conflict');
                }
                if ($existing['state'] === 'active') {
                    return $plan + ['applied' => false, 'idempotent' => true, 'catalog_id' => $existing['catalog_id']];
                }
                $active = $this->activeCatalog() ?? throw new RuntimeException('no_active_description_catalog');
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $this->db->prepare("UPDATE description_catalogs SET state='superseded',superseded_at=:now WHERE catalog_id=:id")
                    ->execute(['now' => $now, 'id' => $active['catalog_id']]);
                $this->db->prepare("UPDATE description_catalogs SET state='active',previous_catalog_id=:previous,activated_at=:now,superseded_at=NULL WHERE catalog_id=:id")
                    ->execute(['previous' => $active['catalog_id'], 'now' => $now, 'id' => $existing['catalog_id']]);
                $this->projectCatalog((string) $existing['catalog_id']);
                return $plan + ['applied' => true, 'idempotent' => false, 'reactivated' => true,
                    'catalog_id' => $existing['catalog_id']];
            }

            $active = $this->activeCatalog();
            if ($active === null) {
                $active = $this->snapshotCurrentFactoryCatalog();
            }
            $catalogId = Uuid::v4();
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $insertCatalog = $this->db->prepare(<<<'SQL'
INSERT INTO description_catalogs(catalog_id,catalog_version,source_kind,format_version,prompt_sha256,model,
    csv_sha256,manifest_sha256,official_content_sha256,row_count,state,previous_catalog_id,imported_at,activated_at)
VALUES(:id,:version,'imported',:format,:prompt,:model,:csv,:manifest,CAST(:content_hashes AS jsonb),:rows,
    'superseded',:previous,:now,:now)
SQL);
            $insertCatalog->execute([
                'id' => $catalogId, 'version' => $catalogVersion, 'format' => $package['format_version'],
                'prompt' => $package['prompt_sha256'], 'model' => $package['model'], 'csv' => $package['csv_sha256'],
                'manifest' => $package['manifest_sha256'],
                'content_hashes' => json_encode($package['official_content_sha256'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'rows' => count($package['rows']), 'previous' => $active['catalog_id'], 'now' => $now,
            ]);
            $insertEntry = $this->db->prepare('INSERT INTO description_catalog_entries(catalog_id,plugin,baseid,name,description) VALUES(:catalog,:plugin,:baseid,:name,:description)');
            foreach ($package['rows'] as $row) {
                $insertEntry->execute(['catalog' => $catalogId] + $row);
            }
            $this->db->prepare("UPDATE description_catalogs SET state='superseded',superseded_at=:now WHERE catalog_id=:id")
                ->execute(['now' => $now, 'id' => $active['catalog_id']]);
            $this->db->prepare("UPDATE description_catalogs SET state='active',activated_at=:now,superseded_at=NULL WHERE catalog_id=:id")
                ->execute(['now' => $now, 'id' => $catalogId]);
            $this->projectCatalog($catalogId);
            return $plan + ['applied' => true, 'idempotent' => false, 'catalog_id' => $catalogId];
        });
    }

    /** Install a bundled catalog once without overriding a later explicit administrative rollback. */
    public function provision(string $csvPath, string $manifestPath, string $catalogVersion): array
    {
        $package = $this->loadPackage($csvPath, $manifestPath, $catalogVersion);
        if ($package['errors'] !== []) {
            throw new InvalidArgumentException('invalid_catalog_package: ' . implode('; ', $package['errors']));
        }
        $existing = $this->catalogByVersion($catalogVersion);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['csv_sha256'], $package['csv_sha256'])
                || !hash_equals((string) $existing['manifest_sha256'], $package['manifest_sha256'])) {
                throw new RuntimeException('catalog_version_conflict');
            }
            return ['schema' => 'almsivi.description-catalog-provision.v1', 'applied' => false,
                'idempotent' => true, 'catalog_id' => $existing['catalog_id'],
                'catalog_version' => $existing['catalog_version'], 'state' => $existing['state']];
        }
        return $this->apply($csvPath, $manifestPath, $catalogVersion);
    }

    /** Restore an explicit catalog, or the active catalog's immediate predecessor. */
    public function rollback(?string $catalogVersion = null): array
    {
        return $this->transaction(function () use ($catalogVersion): array {
            $this->db->exec('SELECT pg_advisory_xact_lock(' . self::LOCK_ID . ')');
            $active = $this->activeCatalog() ?? throw new RuntimeException('no_active_description_catalog');
            if ($catalogVersion !== null) {
                $target = $this->catalogByVersion($this->validateVersion($catalogVersion));
            } else {
                $target = $active['previous_catalog_id'] === null ? null : $this->catalogById((string) $active['previous_catalog_id']);
            }
            if ($target === null) {
                throw new RuntimeException('description_catalog_rollback_target_missing');
            }
            if ($target['catalog_id'] === $active['catalog_id']) {
                return ['schema' => 'almsivi.description-catalog-rollback.v1', 'rolled_back' => false,
                    'catalog_id' => $target['catalog_id'], 'catalog_version' => $target['catalog_version']];
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $this->db->prepare("UPDATE description_catalogs SET state='superseded',superseded_at=:now WHERE catalog_id=:id")
                ->execute(['now' => $now, 'id' => $active['catalog_id']]);
            $this->db->prepare("UPDATE description_catalogs SET state='active',activated_at=:now,superseded_at=NULL WHERE catalog_id=:id")
                ->execute(['now' => $now, 'id' => $target['catalog_id']]);
            $this->projectCatalog((string) $target['catalog_id']);
            return ['schema' => 'almsivi.description-catalog-rollback.v1', 'rolled_back' => true,
                'catalog_id' => $target['catalog_id'], 'catalog_version' => $target['catalog_version'],
                'row_count' => (int) $target['row_count']];
        });
    }

    /** Return catalog metadata without exposing descriptions or source files. */
    public function status(): array
    {
        $rows = $this->db->query('SELECT catalog_id,catalog_version,source_kind,format_version,prompt_sha256,model,csv_sha256,manifest_sha256,official_content_sha256,row_count,state,previous_catalog_id,imported_at,activated_at,superseded_at FROM description_catalogs ORDER BY activated_at DESC,catalog_id')->fetchAll();
        foreach ($rows as &$row) {
            $row['row_count'] = (int) $row['row_count'];
            $row['official_content_sha256'] = json_decode((string) $row['official_content_sha256'], true, 16, JSON_THROW_ON_ERROR);
        }
        unset($row);
        return ['schema' => 'almsivi.description-catalog-status.v1', 'catalogs' => $rows];
    }

    /** Parse and cross-check CSV and generation manifest while collecting bounded diagnostics. */
    private function loadPackage(string $csvPath, string $manifestPath, string $catalogVersion): array
    {
        $this->validateVersion($catalogVersion);
        $errors = [];
        $csv = $this->readUtf8File($csvPath, self::MAX_CSV_BYTES, 'CSV');
        $manifestRaw = $this->readUtf8File($manifestPath, 2_097_152, 'manifest');
        try {
            $manifest = json_decode($manifestRaw, true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new InvalidArgumentException('invalid_manifest_json: ' . $error->getMessage(), 0, $error);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new InvalidArgumentException('invalid_manifest_object');
        }
        $format = trim((string) ($manifest['format'] ?? ''));
        $promptSha = strtolower(trim((string) ($manifest['prompt_sha256'] ?? '')));
        $model = trim((string) ($manifest['model'] ?? ''));
        if ($format !== self::FORMAT) $errors[] = 'manifest format is not ' . self::FORMAT;
        if (preg_match('/^[0-9a-f]{64}$/D', $promptSha) !== 1) $errors[] = 'manifest prompt_sha256 is invalid';
        if ($model === '' || strlen($model) > 256) $errors[] = 'manifest model is invalid';
        $contentHashes = $manifest['official_content_sha256'] ?? null;
        if (!is_array($contentHashes) || array_is_list($contentHashes)
            || array_diff(array_keys($contentHashes), self::OFFICIAL_PLUGINS) !== []
            || array_diff(self::OFFICIAL_PLUGINS, array_keys($contentHashes)) !== []) {
            $errors[] = 'manifest official_content_sha256 keys are invalid';
            $contentHashes = [];
        } else {
            foreach ($contentHashes as $plugin => $hash) {
                if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
                    $errors[] = "manifest hash for {$plugin} is invalid";
                }
            }
            $contentHashes = array_replace(array_fill_keys(self::OFFICIAL_PLUGINS, ''), $contentHashes);
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('temporary CSV stream unavailable');
        fwrite($stream, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
        rewind($stream);
        $header = fgetcsv($stream, null, ',', '"', '');
        if ($header !== ['plugin', 'baseid', 'name', 'description']) {
            $errors[] = 'CSV header must be exactly plugin,baseid,name,description';
        }
        $rows = [];
        $seen = [];
        $duplicates = 0;
        $line = 1;
        while (($fields = fgetcsv($stream, null, ',', '"', '')) !== false) {
            ++$line;
            if ($fields === [null] || $fields === []) continue;
            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = 'CSV exceeds 5000 rows';
                break;
            }
            if (count($fields) !== 4) {
                $errors[] = "CSV line {$line} does not contain exactly four fields";
                continue;
            }
            [$plugin, $baseid, $name, $description] = array_map(static fn(mixed $value): string => trim((string) $value), $fields);
            $rowErrors = [];
            if (!in_array($plugin, self::OFFICIAL_PLUGINS, true)) $rowErrors[] = 'plugin is not an exact official content file';
            if ($baseid === '' || strlen($baseid) > 128 || preg_match('/[\x00-\x1F\x7F]/u', $baseid) === 1) $rowErrors[] = 'baseid is invalid';
            if ($name === '' || mb_strlen($name, 'UTF-8') > 256 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $name) === 1) $rowErrors[] = 'name is invalid';
            foreach ($this->descriptionErrors($description) as $error) $rowErrors[] = $error;
            $key = $this->key($plugin, $baseid);
            if (isset($seen[$key])) {
                ++$duplicates;
                $rowErrors[] = "duplicates CSV line {$seen[$key]}";
            } else {
                $seen[$key] = $line;
            }
            if ($rowErrors !== []) {
                $errors[] = "CSV line {$line}: " . implode(', ', $rowErrors);
                continue;
            }
            $rows[] = compact('plugin', 'baseid', 'name', 'description');
        }
        fclose($stream);

        $selected = $manifest['selected_count'] ?? null;
        $completed = $manifest['completed_count'] ?? null;
        $pending = $manifest['pending_count'] ?? null;
        if ($rows === []) $errors[] = 'CSV must contain at least one validated description';
        if (!is_int($selected) || !is_int($completed) || !is_int($pending)
            || $selected !== count($rows) || $completed !== count($rows) || $pending !== 0) {
            $errors[] = 'manifest selected/completed/pending counts do not match the validated CSV';
        }
        $manifestItems = $manifest['items'] ?? null;
        if (!is_array($manifestItems) || !array_is_list($manifestItems) || count($manifestItems) !== count($rows)) {
            $errors[] = 'manifest items do not match the validated CSV row count';
        } else {
            $manifestKeys = [];
            foreach ($manifestItems as $item) {
                if (!is_array($item) || array_is_list($item) || ($item['status'] ?? null) !== 'complete') {
                    $errors[] = 'manifest contains a non-complete item';
                    continue;
                }
                $manifestKeys[$this->key((string) ($item['content_file'] ?? ''), (string) ($item['record_id'] ?? ''))] = true;
            }
            if (array_diff_key($seen, $manifestKeys) !== [] || array_diff_key($manifestKeys, $seen) !== []) {
                $errors[] = 'manifest item identities do not exactly match the CSV';
            }
        }
        return [
            'rows' => $rows, 'errors' => array_slice(array_values(array_unique($errors)), 0, 100),
            'duplicate_count' => $duplicates, 'format_version' => $format, 'prompt_sha256' => $promptSha,
            'model' => $model, 'csv_sha256' => hash('sha256', str_replace(["\r\n", "\r"], "\n", $csv)),
            'manifest_sha256' => hash('sha256', $manifestRaw), 'official_content_sha256' => $contentHashes,
        ];
    }

    private function descriptionErrors(string $description): array
    {
        $errors = [];
        $words = preg_match_all("/\b[\p{L}\p{N}_'-]+\b/u", $description);
        if ($words === false || $words < 13 || $words > 22) $errors[] = 'description must contain 13 to 22 words';
        $sentences = preg_match_all('/[.!?](?:["\'\)\]]+)?(?:\s|$)/u', $description);
        if ($sentences !== 1 || preg_match('/[.!?]["\'\)\]]?$/u', $description) !== 1) $errors[] = 'description must be exactly one complete sentence';
        if (preg_match('/^this item\b/iu', $description) === 1) $errors[] = 'description must not begin with This item';
        if (mb_strlen($description, 'UTF-8') > 240 || str_contains($description, "\0")) $errors[] = 'description exceeds the text boundary';
        if (preg_match('/\b(?:game|player|inventory|record|file|quest|level|value|worth|weighs?|damage|armor rating|durability|quality|charges?|uses?|skill|attribute|magnitude|probability|chance|seconds?|points?|UESP|wiki|model|icon)\b/iu', $description) === 1) {
            $errors[] = 'description contains a mechanics or metadata term';
        }
        return $errors;
    }

    private function projectCatalog(string $catalogId): void
    {
        $plugins = "('Morrowind.esm','Tribunal.esm','Bloodmoon.esm')";
        $this->db->exec("DELETE FROM public.descriptions WHERE plugin IN {$plugins}");
        $insert = $this->db->prepare('INSERT INTO public.descriptions(plugin,baseid,name,description) SELECT plugin,baseid,name,description FROM description_catalog_entries WHERE catalog_id=:id ORDER BY plugin,baseid');
        $insert->execute(['id' => $catalogId]);
        $this->db->exec("DELETE FROM public.market_cache cache WHERE cache.plugin IN {$plugins} AND NOT EXISTS (SELECT 1 FROM public.descriptions_custom custom WHERE lower(custom.plugin)=lower(cache.plugin) AND lower(custom.baseid)=lower(cache.baseid))");
        $this->db->exec("INSERT INTO public.market_cache(baseid,name,description,plugin,enchantment,price) SELECT defaults.baseid,defaults.name,defaults.description,defaults.plugin,NULL,NULL FROM public.descriptions defaults WHERE defaults.plugin IN {$plugins} AND NOT EXISTS (SELECT 1 FROM public.descriptions_custom custom WHERE lower(custom.plugin)=lower(defaults.plugin) AND lower(custom.baseid)=lower(defaults.baseid)) ON CONFLICT(baseid,plugin) DO UPDATE SET name=EXCLUDED.name,description=EXCLUDED.description");
    }

    private function snapshotCurrentFactoryCatalog(): array
    {
        $rows = array_values($this->officialDescriptions());
        $encoded = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sha = hash('sha256', $encoded);
        $catalogId = Uuid::v4();
        $version = 'pre-catalog-' . substr($sha, 0, 16);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->db->prepare("INSERT INTO description_catalogs(catalog_id,catalog_version,source_kind,csv_sha256,row_count,state,imported_at,activated_at) VALUES(:id,:version,'legacy_snapshot',:sha,:rows,'active',:now,:now)")
            ->execute(['id' => $catalogId, 'version' => $version, 'sha' => $sha, 'rows' => count($rows), 'now' => $now]);
        $insert = $this->db->prepare('INSERT INTO description_catalog_entries(catalog_id,plugin,baseid,name,description) VALUES(:catalog,:plugin,:baseid,:name,:description)');
        foreach ($rows as $row) $insert->execute(['catalog' => $catalogId] + $row);
        return $this->catalogById($catalogId) ?? throw new RuntimeException('factory_snapshot_failed');
    }

    private function officialDescriptions(): array
    {
        $rows = $this->db->query("SELECT plugin,baseid,name,description FROM public.descriptions WHERE plugin IN ('Morrowind.esm','Tribunal.esm','Bloodmoon.esm') ORDER BY lower(plugin),lower(baseid)")->fetchAll();
        $result = [];
        foreach ($rows as $row) $result[$this->key((string) $row['plugin'], (string) $row['baseid'])] = $row;
        return $result;
    }

    private function activeCatalog(): ?array
    {
        $row = $this->db->query("SELECT * FROM description_catalogs WHERE state='active'")->fetch();
        return $row === false ? null : $row;
    }

    private function catalogByVersion(string $version): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM description_catalogs WHERE catalog_version=:version');
        $statement->execute(['version' => $version]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    private function catalogById(string $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM description_catalogs WHERE catalog_id=:id');
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
        return $contents;
    }

    private function validateVersion(string $version): string
    {
        $version = trim($version);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $version) !== 1) throw new InvalidArgumentException('invalid_catalog_version');
        return $version;
    }

    private function key(string $plugin, string $baseid): string
    {
        return mb_strtolower(trim($plugin), 'UTF-8') . "\0" . mb_strtolower(trim($baseid), 'UTF-8');
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
