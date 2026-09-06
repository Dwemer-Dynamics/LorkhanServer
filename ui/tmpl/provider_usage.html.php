<?php
declare(strict_types=1);
$costLabels=[];$costValues=[];
foreach($chartRows as$row)if($row['cost_usd']!==null){$costLabels[]=(string)$row['operation'];$costValues[]=(float)$row['cost_usd'];}
$hasChart=array_sum($costValues)>0;
$displayCost=(int)$totals['attempts']===0?'$0.00':($totals['cost_usd']===null?'Unknown':'$'.number_format((float)$totals['cost_usd'],2));
?>
<main class="cost-breakdown">
    <header class="page-header">
        <h1>💰 Cost Distribution by Request Type</h1>
        <h3><?= lorkhan_ui_h($periodLabel) ?></h3>
        <h3>Total Cost: <?= lorkhan_ui_h($displayCost) ?></h3>
        <?php if((int)$totals['priced']<(int)$totals['attempts']): ?><p class="cost-coverage">Missing pricing is shown as unknown. This is a partial total: <?= (int)$totals['priced'] ?> of <?= (int)$totals['attempts'] ?> attempts include cost.</p><?php endif; ?>
    </header>
    <section class="filters" aria-label="Cost date filters">
        <form method="get" id="cost-filter-form">
            <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($state['installation']) ?>">
            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <label><input type="radio" name="filter" value="today"<?= $filter==='today'?' checked':'' ?>> Today</label>
            <div class="cost-filter-field"><label for="cost-date">Filter by Date:</label><input type="date" name="date" id="cost-date" value="<?= lorkhan_ui_h($selectedDate) ?>"><button type="submit" name="filter" value="date">Apply Date</button></div>
            <div class="cost-filter-field"><label for="cost-week">Filter by Week:</label><input type="week" name="week" id="cost-week" value="<?= lorkhan_ui_h($selectedWeek) ?>"><button type="submit" name="filter" value="week">Apply Week</button></div>
            <noscript><button type="submit">Apply filters</button></noscript>
        </form>
    </section>
    <section class="chart-section" aria-label="Cost distribution chart">
        <?php if($hasChart): ?>
        <div class="chart-container"><canvas id="costChart" role="img" aria-label="Recorded cost by request type. Exact values are available in the cost table below."></canvas></div>
        <script type="application/json" id="cost-chart-data"><?= json_encode(['labels'=>$costLabels,'values'=>$costValues],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
        <?php else: ?><p class="cost-empty"><?php if((int)$totals['attempts']===0): ?>No provider attempts match this date range and installation.<?php elseif($totals['cost_usd']===null): ?>No explicit USD cost was recorded in this period. Token counts and attempts remain available below.<?php else: ?>The recorded costs total $0.00. There are no positive costs to chart.<?php endif; ?></p><?php endif; ?>
        <details class="cost-chart-table"><summary>Cost values by request type</summary><table><thead><tr><th>Request Type</th><th>Recorded Cost (USD)</th></tr></thead><tbody><?php foreach($chartRows as$row): ?><tr><td><?= lorkhan_ui_h($row['operation']) ?></td><td><?= $row['cost_usd']===null?'Unknown':'$'.number_format((float)$row['cost_usd'],6) ?></td></tr><?php endforeach; ?><?php if($chartRows===[]): ?><tr><td colspan="2">No recorded requests.</td></tr><?php endif; ?></tbody></table></details>
    </section>
    <details class="cost-options"><summary>More filters and export</summary>
        <p>Dates and ISO weeks use UTC. Scope: <?= lorkhan_ui_h($state['installations'][$state['installation']]??'All Installations') ?>.</p>
        <form method="get">
            <input type="hidden" name="filter" value="<?= lorkhan_ui_h($filter) ?>"><input type="hidden" name="date" value="<?= lorkhan_ui_h($selectedDate) ?>"><input type="hidden" name="week" value="<?= lorkhan_ui_h($selectedWeek) ?>">
            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <label>Installation<select name="installation_id"><option value="">All Installations</option><?php foreach($state['installations'] as$id=>$name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id===$state['installation']?' selected':'' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <button type="submit">Apply Installation</button>
            <label>Period<select name="period"><?php foreach($state['periods'] as$id=>$name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id===$state['period']?' selected':'' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <button type="submit" name="filter" value="period">Apply Period</button>
            <a href="<?= lorkhan_ui_h($costUrl(['export'=>'1'])) ?>">Export Breakdown</a>
        </form>
    </details>
    <details class="cost-details"><summary>Recorded usage details</summary>
        <p><?= number_format((int)$totals['attempts']) ?> attempts · <?= number_format((int)$totals['succeeded']) ?> succeeded · <?= number_format((int)$totals['failed']) ?> failed/cancelled. Recorded tokens: <?= $totals['total_tokens']===null?'Unknown':number_format((int)$totals['total_tokens']) ?>. Average provider duration: <?= $totals['duration_ms']===null?'Unknown':number_format((float)$totals['duration_ms']).' ms' ?>.</p>
        <p>Top 100 provider/model/request groups. The total and request-type chart include every matching attempt.</p>
<div class="control-reader-table-wrap" role="region" aria-label="Recorded provider usage" tabindex="0"><table><thead><tr><th>Request Type</th><th>Provider / Model</th><th>Attempts</th><th>Input / Output Tokens</th><th>Total Tokens</th><th>Recorded Cost</th></tr></thead><tbody>
<?php foreach($groups as$group): ?><tr><td><?= lorkhan_ui_h($group['operation']) ?><small class="muted"><?= lorkhan_ui_h(strtoupper($group['provider_kind'])) ?></small></td><td><?= lorkhan_ui_h($group['provider_name']) ?><small><?= lorkhan_ui_h($group['model']) ?></small></td><td><?= number_format((int)$group['attempts']) ?><small class="muted"><?= (int)$group['failed'] ?> failed / cancelled</small></td><td><?= $group['prompt_tokens']===null?'Unknown':number_format((int)$group['prompt_tokens']) ?> / <?= $group['completion_tokens']===null?'Unknown':number_format((int)$group['completion_tokens']) ?></td><td><?= $group['total_tokens']===null?'Unknown':number_format((int)$group['total_tokens']) ?><small class="muted"><?= (int)$group['token_coverage'] ?> measured attempts</small></td><td><?= $group['cost_usd']===null?'Unknown':'$'.number_format((float)$group['cost_usd'],6) ?><small class="muted"><?= (int)$group['priced'] ?> of <?= (int)$group['attempts'] ?> priced</small></td></tr><?php endforeach; ?>
<?php if($groups===[]): ?><tr><td colspan="6" class="control-reader-empty">No provider attempts match this period and installation.</td></tr><?php endif; ?>
</tbody></table></div>
    </details>
</main>
<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/lib/ui/chartjs/chart.umd.min.js?v=4.5.1" defer></script>
<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/cost-breakdown.js?v=<?= (int)filemtime(__DIR__.'/../js/cost-breakdown.js') ?>" defer></script>
