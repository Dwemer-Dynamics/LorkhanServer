<main class="request-log-page" data-request-log data-installation="<?= lorkhan_ui_h($state['installation']) ?>" data-csrf="<?= lorkhan_ui_h($csrf) ?>" data-clear-endpoint="<?= lorkhan_ui_h($managementBasePath.'/api/v1/request-logs/clear') ?>">
<div class="tab-content">
    <h1 id="page-title" class="page-title">Request to LLM Services Log</h1>
    <div class="btn-row">
        <?php if ($state['page']>1): ?><a class="btn-base btn-primary" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>$state['page']-1])) ?>">Previous</a><?php endif; ?>
        <?php if ($state['page']<$state['pages']): ?><a class="btn-base btn-primary" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>$state['page']+1])) ?>">Next</a><?php endif; ?>
        <?php foreach ([100,200] as $limit): ?><a class="btn-base btn-secondary" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>1,'limit'=>$limit])) ?>">Limit <?= $limit ?></a><?php endforeach; ?>
        <button type="button" class="btn-base btn-danger" data-clear-log<?= $state['installation']===''?' disabled':'' ?> title="Clear completed entries for the selected installation from this view. Usage and history are retained.">Clear Log</button>
    </div>
    <div class="request-log-meta"><p class="meta-line">Showing <?= count($state['rows']) ?> of <?= $state['total'] ?> records. Page <?= $state['page'] ?> / <?= $state['pages'] ?>.</p>
    <details class="request-log-filters"><summary>Filters and recorded data</summary>
        <form method="get" class="control-reader-filters">
            <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <input type="hidden" name="limit" value="<?= $state['limit'] ?>">
            <label>Installation<select name="installation_id"><option value="">All Installations</option><?php foreach ($state['installations'] as $id=>$name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id===$state['installation']?' selected':'' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label>Period<select name="period"><?php foreach ($state['periods'] as $id=>$name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id===$state['period']?' selected':'' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label>Status<select name="state"><option value="">All States</option><?php foreach ($states as $id=>$name): ?><option value="<?= $id ?>"<?= $id===$state['state']?' selected':'' ?>><?= $name ?></option><?php endforeach; ?></select></label>
            <label class="control-reader-search">Search<input type="search" name="q" maxlength="200" value="<?= lorkhan_ui_h($state['query']) ?>" placeholder="Connector, model or ID"></label>
            <button type="submit" class="control-reader-button">Apply</button>
        </form>
        <p>LLM attempts only. Requests show recorded prompt messages; results show accepted dialogue from the successful turn attempt, not raw HTTP payloads. URLs and unrecorded payloads are unavailable. Provider settings and credentials are excluded. Clear Log removes completed entries for the selected installation from this view, regardless of filters; usage, history and pending work are retained.</p>
    </details></div>
    <p data-log-status role="status" hidden></p>
    <div class="table-container" tabindex="0" role="region" aria-label="LLM request log">
    <?php if ($state['rows']===[]): ?><div class="empty-state">No LLM requests match these filters.</div>
    <?php else: ?><table><thead><tr><th>ID</th><th>Time (UTC)</th><th>Connector / Model</th><th>Status</th><th>Tokens</th><th>URL</th><th>Request</th><th>Result</th><th>Error</th></tr></thead><tbody>
    <?php foreach ($state['rows'] as $row): $id=$row['provider_attempt_id']; $statusClass=match($row['state']){'succeeded'=>'status-success','failed'=>'status-error',default=>'status-unknown'}; ?>
        <tr>
            <td class="mono" title="<?= lorkhan_ui_h($id) ?>"><?= lorkhan_ui_h($id) ?></td><td><?= lorkhan_ui_h($row['time_utc']) ?></td>
            <td><div><?= lorkhan_ui_h($row['provider_name']) ?></div><div class="mono"><?= lorkhan_ui_h($row['model']??'') ?></div></td>
            <td><span class="status-pill <?= $statusClass ?>"><?= $row['state']==='succeeded'?'OK':lorkhan_ui_h($states[$row['state']]??'Unknown') ?></span></td>
            <td class="mono" title="Input / Output / Total tokens"><?= lorkhan_ui_h($row['tokens']) ?></td>
            <td class="mono cell-url" title="Endpoint URL was not recorded">—</td>
            <?php foreach (['request'=>'Request','result'=>'Result'] as $key=>$label): ?><td class="cell-preview">
                <div class="mono"><?= $row[$key]===''?'Not recorded':lorkhan_ui_h(mb_substr($row[$key],0,140).(mb_strlen($row[$key])>140?'...':'')) ?></div>
                <?php if ($row[$key]!==''): ?><button type="button" class="btn-linklike" data-open-modal="<?= $key.'-'.$id ?>" aria-label="View <?= $label ?> <?= lorkhan_ui_h($id) ?>">View</button><?php endif; ?>
            </td><?php endforeach; ?><td class="mono"><?= lorkhan_ui_h($row['error_code']??'') ?></td>
        </tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
</div>
<?php foreach ($state['rows'] as $row): foreach (['request'=>'Request','result'=>'Result'] as $key=>$label): if ($row[$key]==='') continue; $modal=$key.'-'.$row['provider_attempt_id']; ?>
    <dialog id="<?= $modal ?>" class="request-log-dialog" aria-labelledby="<?= $modal ?>-title">
        <div class="modal-head"><h3 id="<?= $modal ?>-title" class="modal-title"><?= $label ?> Payload (ID <?= lorkhan_ui_h($row['provider_attempt_id']) ?>)</h3><button type="button" class="btn-base btn-secondary" data-close-modal>Close</button></div>
        <div class="modal-body"><p class="payload-note"><?= $key==='request'?'Recorded prompt messages; provider configuration is excluded.':'Accepted dialogue text; the raw provider response was not retained.' ?></p><pre class="modal-pre"><?= lorkhan_ui_h($row[$key]) ?></pre>
            <?php if ($key==='request' && ($row['sections']??[])!==[]): ?><details class="request-coverage"><summary>Section Coverage</summary><table><thead><tr><th>Section</th><th>Inclusion</th><th>Characters</th><th>Estimated Tokens</th></tr></thead><tbody>
                <?php foreach ($row['sections'] as $section): ?><tr><td><?= (int)$section['section_order'] ?> · <?= lorkhan_ui_h($section['section_key']) ?></td><td><?= lorkhan_ui_h($section['inclusion_reason']) ?></td><td><?= (int)$section['source_characters'] ?></td><td><?= (int)$section['estimated_tokens'] ?></td></tr><?php endforeach; ?>
            </tbody></table></details><?php endif; ?>
        </div>
    </dialog>
<?php endforeach; endforeach; ?>
<dialog id="clear-request-log" class="request-log-dialog clear-log-dialog" aria-labelledby="clear-log-title">
    <div class="modal-head"><h3 id="clear-log-title" class="modal-title">Clear Request Log</h3><button type="button" class="btn-base btn-secondary" data-close-modal>Cancel</button></div>
    <div class="modal-body"><p>Clear all completed LLM entries for <strong><?= lorkhan_ui_h($state['installations'][$state['installation']]??'the selected installation') ?></strong> from this view?</p><p>Usage records, conversation history and pending requests are retained. This applies across all filters.</p><button type="button" class="btn-base btn-danger" data-confirm-clear>Clear Log</button><p data-clear-status role="status" hidden></p></div>
</dialog>
</main>
