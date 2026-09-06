<?php
// Native operational readers share the pinned Herika Request Logs presentation and safe page exports.
if (($_GET['export']??'')==='csv') lorkhan_control_export($state['rows'],$columns,$exportFilename);
$additionalStylesheets=['control-reader.css?v='.(string)filemtime(__DIR__.'/../css/control-reader.css'),
    'request-logs.css?v='.(string)filemtime(__DIR__.'/../css/request-logs.css'),
    'operational-log.css?v='.(string)filemtime(__DIR__.'/../css/operational-log.css')];
include __DIR__.'/head.html'; if (!$embedded) include __DIR__.'/navbar.php';
?>
<main class="request-log-page operational-log-page"><div class="tab-content">
    <h1 id="page-title" class="page-title"><?= lorkhan_ui_h($pageTitle) ?></h1>
    <div class="btn-row">
        <?php if ($state['page']>1): ?><a class="btn-base btn-primary" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>$state['page']-1])) ?>">Previous</a><?php endif; ?>
        <?php if ($state['page']<$state['pages']): ?><a class="btn-base btn-primary" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>$state['page']+1])) ?>">Next</a><?php endif; ?>
        <?php foreach ([50,100,200] as $limit): ?><a class="btn-base btn-secondary" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>1,'limit'=>$limit])) ?>"<?= $limit===$state['limit']?' aria-current="true"':'' ?>>Limit <?= $limit ?></a><?php endforeach; ?>
        <a class="btn-base btn-secondary" href="<?= lorkhan_ui_h(lorkhan_control_url($state)) ?>">Refresh</a>
        <a class="btn-base btn-secondary" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['export'=>'csv'])) ?>">Export CSV</a>
    </div>
    <div class="request-log-meta"><p class="meta-line">Showing <?= count($state['rows']) ?> of <?= $state['total'] ?> records. Page <?= $state['page'] ?> / <?= $state['pages'] ?>.</p>
    <details class="request-log-filters"><summary>Filters and recorded data</summary>
        <form method="get" class="control-reader-filters">
            <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <input type="hidden" name="limit" value="<?= $state['limit'] ?>">
            <label>Installation<select name="installation_id"><option value="">All Installations</option><?php foreach ($state['installations'] as $id=>$name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id===$state['installation']?' selected':'' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label>Period<select name="period"><?php foreach ($state['periods'] as $id=>$name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id===$state['period']?' selected':'' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label>Status<select name="state"><option value="">All States</option><?php foreach ($states as $id=>$name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id===$state['state']?' selected':'' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label class="control-reader-search">Search<input type="search" name="q" maxlength="200" value="<?= lorkhan_ui_h($state['query']) ?>" placeholder="<?= lorkhan_ui_h($searchPlaceholder) ?>"></label>
            <button type="submit" class="control-reader-button">Apply</button>
        </form>
        <p><?= lorkhan_ui_h($recordNote) ?></p>
    </details></div>
    <div class="table-container" tabindex="0" role="region" aria-label="<?= lorkhan_ui_h($pageTitle) ?> records">
    <?php if ($state['rows']===[]): ?><div class="empty-state"><?= lorkhan_ui_h($emptyMessage) ?></div>
    <?php else: ?><table><thead><tr><?php foreach ($columns as $key=>$label): ?><th scope="col" class="col-<?= lorkhan_ui_h($key) ?>"><?= lorkhan_ui_h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($state['rows'] as $row): ?><tr><?php foreach ($columns as $key=>$_label): ?>
            <td class="col-<?= lorkhan_ui_h($key) ?>">
            <?php if ($key==='state'): $statusClass=match($row[$key]){'succeeded'=>'status-success','failed','dead'=>'status-error',default=>'status-unknown'}; ?>
                <span class="status-pill <?= $statusClass ?>"><?= lorkhan_ui_h($states[$row[$key]]??'Unknown') ?></span>
            <?php else: ?><?= lorkhan_ui_h($row[$key]??'—') ?><?php endif; ?>
            </td>
        <?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
    </div>
</div></main>
<?php include __DIR__.'/footer.html'; ?>
