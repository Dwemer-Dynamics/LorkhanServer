<?php
declare(strict_types=1);

/** Share validated installation, period and paging controls across the operational readers. */
function lorkhan_control_state(array $installations): array
{
    $labels = []; foreach ($installations as $row) $labels[(string) $row['installation_id']] = (string) $row['display_name'];
    $installation = (string) ($_GET['installation_id'] ?? array_key_first($labels) ?? '');
    if ($installation !== '' && !isset($labels[$installation])) $installation = (string) (array_key_first($labels) ?? '');
    $periods = ['today' => 'Today (UTC)', '24h' => 'Last 24 Hours', '7d' => 'Last 7 Days', '30d' => 'Last 30 Days', 'all' => 'All Time'];
    $period = (string) ($_GET['period'] ?? '7d'); if (!isset($periods[$period])) $period = '7d';
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $since = match ($period) { 'today' => $now->setTime(0, 0), '24h' => $now->modify('-24 hours'), '7d' => $now->modify('-7 days'), '30d' => $now->modify('-30 days'), default => null };
    $limit = (int) ($_GET['limit'] ?? 25); if (!in_array($limit, [25, 50, 100], true)) $limit = 25;
    return ['installation' => $installation, 'installations' => $labels, 'period' => $period, 'periods' => $periods,
        'since' => $since?->format(DATE_ATOM), 'limit' => $limit, 'page' => max(1, min(1000000, (int) ($_GET['page'] ?? 1))),
        'query' => trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 200)), 'state' => (string) ($_GET['state'] ?? ''),
        'total' => 0, 'pages' => 1, 'rows' => []];
}

/** Return a bounded page using fixed caller-owned SELECT clauses and bound filter values. */
function lorkhan_control_query(PDO $database, array $state, string $select, string $from, array $conditions, array $params, string $order): array
{
    $where = $conditions === [] ? '' : ' WHERE '.implode(' AND ', $conditions);
    $statement = $database->prepare('SELECT count(*) '.$from.$where); $statement->execute($params);
    $state['total'] = (int) $statement->fetchColumn(); $state['pages'] = max(1, (int) ceil($state['total'] / $state['limit']));
    $state['page'] = min($state['page'], $state['pages']);
    $statement = $database->prepare('SELECT '.$select.' '.$from.$where.' ORDER BY '.$order.' LIMIT :reader_limit OFFSET :reader_offset');
    foreach ($params as $key => $value) $statement->bindValue(':'.$key, $value, PDO::PARAM_STR);
    $statement->bindValue(':reader_limit', $state['limit'], PDO::PARAM_INT);
    $statement->bindValue(':reader_offset', ($state['page'] - 1) * $state['limit'], PDO::PARAM_INT);
    $statement->execute(); $state['rows'] = $statement->fetchAll(PDO::FETCH_ASSOC); return $state;
}

function lorkhan_control_url(array $state, array $changes = []): string
{
    return '?'.http_build_query(array_merge(['installation_id' => $state['installation'], 'period' => $state['period'],
        'limit' => $state['limit'], 'page' => $state['page'], 'q' => $state['query'], 'state' => $state['state'],
        'embed' => (($_GET['embed'] ?? '') === '1' ? '1' : '0')], $changes));
}

function lorkhan_control_filters(array $state, array $states = [], bool $search = true, bool $paging = true): void
{
    ?>
    <form method="get" class="control-reader-filters">
        <?php if (($_GET['embed'] ?? '') === '1'): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label>Installation<select name="installation_id"><option value="">All Installations</option><?php foreach ($state['installations'] as $id => $name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id === $state['installation'] ? ' selected' : '' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
        <label>Period<select name="period"><?php foreach ($state['periods'] as $id => $name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id === $state['period'] ? ' selected' : '' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
        <?php if ($states !== []): ?><label>Status<select name="state"><option value="">All States</option><?php foreach ($states as $id => $label): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id === $state['state'] ? ' selected' : '' ?>><?= lorkhan_ui_h($label) ?></option><?php endforeach; ?></select></label><?php endif; ?>
        <?php if ($search): ?><label class="control-reader-search">Search<input type="search" name="q" maxlength="200" value="<?= lorkhan_ui_h($state['query']) ?>" placeholder="Search records"></label><?php endif; ?>
        <?php if ($paging): ?><label>Rows<select name="limit"><?php foreach ([25, 50, 100] as $limit): ?><option<?= $limit === $state['limit'] ? ' selected' : '' ?>><?= $limit ?></option><?php endforeach; ?></select></label><?php endif; ?>
        <button type="submit" class="control-reader-button">Apply</button><a class="control-reader-button" href="<?= lorkhan_ui_h(lorkhan_control_url($state, ['page' => 1, 'q' => '', 'state' => ''])) ?>">Refresh</a>
    </form>
    <?php
}

function lorkhan_control_pagination(array $state): void
{
    ?><nav class="control-reader-pagination" aria-label="Record pages"><span><?= number_format($state['total']) ?> records · Page <?= $state['page'] ?> of <?= $state['pages'] ?></span><div><?php if ($state['page'] > 1): ?><a class="control-reader-button" href="<?= lorkhan_ui_h(lorkhan_control_url($state, ['page' => 1])) ?>">First</a><a class="control-reader-button" href="<?= lorkhan_ui_h(lorkhan_control_url($state, ['page' => $state['page'] - 1])) ?>">Previous</a><?php endif; ?><?php if ($state['page'] < $state['pages']): ?><a class="control-reader-button" href="<?= lorkhan_ui_h(lorkhan_control_url($state, ['page' => $state['page'] + 1])) ?>">Next</a><a class="control-reader-button" href="<?= lorkhan_ui_h(lorkhan_control_url($state, ['page' => $state['pages']])) ?>">Last</a><?php endif; ?></div></nav><?php
}

/** CSV exports contain the displayed page only, and neutralize spreadsheet formula prefixes. */
function lorkhan_control_export(array $rows, array $columns, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$filename.'"'); header('Cache-Control: private, no-store');
    $output = fopen('php://output', 'wb'); fputcsv($output, array_values($columns), ',', '"', '');
    foreach ($rows as $row) {
        $values = []; foreach ($columns as $key => $_label) { $value = (string) ($row[$key] ?? ''); if (preg_match('/^[\s]*[=+@-]/u', $value)) $value = "'".$value; $values[] = $value; }
        fputcsv($output, $values, ',', '"', '');
    }
    fclose($output); exit;
}

/** Extract only allowlisted role/text pairs from the frozen prompt-message projection. */
function lorkhan_control_prompt_messages(mixed $json): array
{
    $messages = is_string($json) ? json_decode($json, true) : $json;
    if (!is_array($messages) || !array_is_list($messages)) return [];
    $result = []; $remaining = 131072;
    foreach (array_slice($messages, 0, 256) as $message) {
        if (!is_array($message) || !in_array($message['role'] ?? '', ['system', 'developer', 'user', 'assistant', 'tool'], true) || !is_string($message['content'] ?? null)) continue;
        $content = mb_strcut($message['content'], 0, $remaining, 'UTF-8'); $remaining -= strlen($content);
        $result[] = ['role' => $message['role'], 'content' => $content]; if ($remaining <= 0) break;
    }
    return $result;
}
