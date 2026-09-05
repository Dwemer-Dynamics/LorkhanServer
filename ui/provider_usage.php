<?php
declare(strict_types=1);
$pageTitle='Cost Breakdown';$topNavSection='control';$BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';require __DIR__.'/tmpl/control_reader.php';
$state=lorkhan_control_state($uiRepository->rows('installations'));
$conditions=[];$params=[];
if($state['installation']!==''){$conditions[]="COALESCE(session.installation_id::text,j.payload->>'installation_id')=:installation";$params['installation']=$state['installation'];}
if($state['since']!==null){$conditions[]='a.started_at>=CAST(:since AS timestamptz)';$params['since']=$state['since'];}
$from='FROM provider_attempts a LEFT JOIN turns t ON t.turn_id=a.turn_id LEFT JOIN sessions session ON session.session_id=t.session_id LEFT JOIN durable_jobs j ON j.job_id=a.job_id';
$where=$conditions===[]?'':' WHERE '.implode(' AND ',$conditions);
// Only measured, numeric usage fields are aggregated. Missing values remain unknown.
$usage=[];
foreach(['prompt_tokens','completion_tokens','total_tokens','cost_usd']as$key)
    $usage[$key]="CASE WHEN jsonb_typeof(a.metadata#>'{usage,".$key."}')='number' AND (a.metadata#>>'{usage,".$key."}')::numeric>=0 THEN (a.metadata#>>'{usage,".$key."}')::numeric ELSE NULL END";
$summary="count(*)::int AS attempts,count(*) FILTER(WHERE a.state='succeeded')::int AS succeeded,"
    ."count(*) FILTER(WHERE a.state IN ('failed','cancelled'))::int AS failed,"
    .'count('.$usage['cost_usd'].')::int AS priced,sum('.$usage['cost_usd'].') AS cost_usd,'
    .'sum('.$usage['prompt_tokens'].') AS prompt_tokens,sum('.$usage['completion_tokens'].') AS completion_tokens,'
    .'sum('.$usage['total_tokens'].') AS total_tokens,count('.$usage['total_tokens'].')::int AS token_coverage,'
    .'avg(a.duration_ms) AS duration_ms';
$statement=$database->prepare('SELECT '.$summary.' '.$from.$where);$statement->execute($params);$totals=$statement->fetch(PDO::FETCH_ASSOC);
$statement=$database->prepare("SELECT a.provider_kind,a.provider_name,COALESCE(a.model,a.metadata->>'model','Not recorded') AS model,a.operation,".$summary.' '.$from.$where
    ." GROUP BY a.provider_kind,a.provider_name,COALESCE(a.model,a.metadata->>'model','Not recorded'),a.operation ORDER BY cost_usd DESC NULLS LAST,attempts DESC,a.provider_name LIMIT 100");
$statement->execute($params);$groups=$statement->fetchAll(PDO::FETCH_ASSOC);
if(($_GET['export']??'')==='1')lorkhan_control_export($groups,['provider_kind'=>'Type','provider_name'=>'Provider','model'=>'Model','operation'=>'Request Type','attempts'=>'Attempts','priced'=>'Attempts With Recorded Cost','cost_usd'=>'Recorded USD Cost','prompt_tokens'=>'Input Tokens','completion_tokens'=>'Output Tokens','total_tokens'=>'Total Tokens'],'lorkhan-cost-breakdown.csv');
$maxCost=0.0;foreach($groups as$group)if($group['cost_usd']!==null)$maxCost=max($maxCost,(float)$group['cost_usd']);
$additionalStylesheets=['control-reader.css?v='.(string)filemtime(__DIR__.'/css/control-reader.css')];include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="control-reader"><header class="control-reader-heading"><div><h1>Cost Breakdown</h1><p>Cost distribution by request type using recorded provider usage. Missing pricing is shown as unknown.</p></div><a class="control-reader-button" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['export'=>'1'])) ?>">Export Breakdown</a></header>
<?php lorkhan_control_filters($state,[],false,false); ?>
<div class="control-reader-metrics">
<section class="control-reader-metric">Recorded Cost<strong><?= $totals['cost_usd']===null?'Unknown':'$'.number_format((float)$totals['cost_usd'],6) ?></strong><small class="muted"><?= number_format((int)$totals['priced']) ?> of <?= number_format((int)$totals['attempts']) ?> attempts include cost</small></section>
<section class="control-reader-metric">Provider Attempts<strong><?= number_format((int)$totals['attempts']) ?></strong><small class="muted"><?= number_format((int)$totals['succeeded']) ?> succeeded · <?= number_format((int)$totals['failed']) ?> failed / cancelled</small></section>
<section class="control-reader-metric">Recorded Tokens<strong><?= $totals['total_tokens']===null?'Unknown':number_format((int)$totals['total_tokens']) ?></strong><small class="muted"><?= number_format((int)$totals['token_coverage']) ?> attempts include total tokens</small></section>
<section class="control-reader-metric">Average Duration<strong><?= $totals['duration_ms']===null?'Unknown':number_format((float)$totals['duration_ms']).' ms' ?></strong><small class="muted">Measured provider request duration</small></section>
</div>
<?php if((int)$totals['priced']<(int)$totals['attempts']): ?><p class="control-reader-status">The recorded cost is a partial total. <?= number_format((int)$totals['attempts']-(int)$totals['priced']) ?> attempts have unknown cost; they are not treated as free.</p><?php endif; ?>
<section class="control-reader-chart"><h2>Cost Distribution by Request Type</h2><p class="muted">Top 100 provider / model / request groups. Period totals above include every matching attempt.</p>
<?php foreach($groups as$group): if($group['cost_usd']===null)continue; ?><div class="control-reader-chart-row"><span><?= lorkhan_ui_h($group['operation'].' · '.$group['provider_name'].' / '.$group['model']) ?></span><meter min="0" max="<?= max(0.000001,$maxCost) ?>" value="<?= max(0,(float)$group['cost_usd']) ?>" aria-label="<?= lorkhan_ui_h($group['operation'].' recorded cost') ?>"></meter><span>$<?= number_format((float)$group['cost_usd'],6) ?></span></div><?php endforeach; ?>
<?php if($totals['cost_usd']===null): ?><p class="control-reader-empty">No explicit USD cost was recorded in this period. Token counts and attempts are shown below when available.</p><?php endif; ?>
</section>
<div class="control-reader-table-wrap"><table><thead><tr><th>Request Type</th><th>Provider / Model</th><th>Attempts</th><th>Input / Output Tokens</th><th>Total Tokens</th><th>Recorded Cost</th></tr></thead><tbody>
<?php foreach($groups as$group): ?><tr><td><?= lorkhan_ui_h($group['operation']) ?><small class="muted"><?= lorkhan_ui_h(strtoupper($group['provider_kind'])) ?></small></td><td><?= lorkhan_ui_h($group['provider_name']) ?><small><?= lorkhan_ui_h($group['model']) ?></small></td><td><?= number_format((int)$group['attempts']) ?><small class="muted"><?= (int)$group['failed'] ?> failed / cancelled</small></td><td><?= $group['prompt_tokens']===null?'Unknown':number_format((int)$group['prompt_tokens']) ?> / <?= $group['completion_tokens']===null?'Unknown':number_format((int)$group['completion_tokens']) ?></td><td><?= $group['total_tokens']===null?'Unknown':number_format((int)$group['total_tokens']) ?><small class="muted"><?= (int)$group['token_coverage'] ?> measured attempts</small></td><td><?= $group['cost_usd']===null?'Unknown':'$'.number_format((float)$group['cost_usd'],6) ?><small class="muted"><?= (int)$group['priced'] ?> of <?= (int)$group['attempts'] ?> priced</small></td></tr><?php endforeach; ?>
<?php if($groups===[]): ?><tr><td colspan="6" class="control-reader-empty">No provider attempts match this period and installation.</td></tr><?php endif; ?>
</tbody></table></div>
</main><?php include __DIR__.'/tmpl/footer.html'; ?>
