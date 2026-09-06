<main class="relationship-log-page" data-relationship-log data-installation="<?= lorkhan_ui_h($selected) ?>" data-csrf="<?= lorkhan_ui_h($csrf) ?>" data-clear-endpoint="<?= lorkhan_ui_h($managementBasePath.'/api/v1/relationship-logs/clear') ?>">
    <div class="rel-log-header"><h1>🔗 Relationship LLM Logs</h1><div class="rel-stats">
        <div class="rel-stat"><span class="rel-stat-label">Total Evaluations</span><div class="rel-stat-value"><?= number_format($logs['all_total']) ?></div></div>
        <div class="rel-stat"><span class="rel-stat-label">Last Hour</span><div class="rel-stat-value"><?= number_format($logs['recent']) ?></div></div>
    </div></div>
    <form class="rel-filters" method="get">
        <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($selected) ?>"><input type="hidden" name="embed" value="<?= $embedded?'1':'0' ?>">
        <input type="hidden" name="limit" value="<?= $logs['limit'] ?>"><input type="hidden" name="page" value="1">
        <label for="relationship-log-type">Type:</label><select id="relationship-log-type" name="type">
        <?php foreach([''=>'All Types','eval_'=>'Evaluations','analyze_'=>'Analyze (Build with AI)','npc2npc_'=>'NPC-to-NPC'] as $value=>$label): ?><option value="<?= $value ?>"<?= $logs['type']===$value?' selected':'' ?>><?= lorkhan_ui_h($label) ?></option><?php endforeach; ?>
        </select><button class="btn-base btn-primary" type="button" data-refresh-log>🔄 Refresh</button>
    </form>
    <?php if($logs['all_total']>0): ?><div class="cleanup-section">
        <div class="cleanup-age"><label for="relationship-log-age">Delete logs older than</label><select id="relationship-log-age"><?php foreach(['1 hour','6 hours','1 day','3 days','1 week','2 weeks','1 month'] as $age): ?><option<?= $age==='1 week'?' selected':'' ?>><?= $age ?></option><?php endforeach; ?></select><button class="btn-base btn-warning" type="button" data-delete-old>🗑️ Delete Old</button></div>
        <button class="btn-base btn-danger" type="button" data-delete-all>🗑️ Delete All</button>
    </div><?php endif; ?>
    <?php if($logs['rows']===[]): ?><div class="no-data"><p>No relationship evaluations found.</p><p class="rel-source-note">Choose a Relationship LLM in your NPC or Core Profile settings.</p></div><?php else: ?>
    <?php for($pager=0;$pager<2;$pager++): ?>
    <div class="pagination-buttons">
        <?php if($logs['page']>1): ?><a class="btn-base btn-primary" href="<?= lorkhan_ui_h($logUrl($logs['page']-1)) ?>">← Previous</a><?php endif; ?>
        <span style="padding:6px">Page <?= $logs['page'] ?></span>
        <?php if($logs['page']<$logs['pages']): ?><a class="btn-base btn-primary" href="<?= lorkhan_ui_h($logUrl($logs['page']+1)) ?>">Next →</a><?php endif; ?>
    </div>
    <?php if($pager===1)break; ?>
    <div class="rel-table-wrap" role="region" aria-label="Relationship evaluations" tabindex="0"><table class="rel-log-table"><thead><tr><th scope="col" style="width:100px">Time</th><th scope="col" style="width:80px">Type</th><th scope="col" style="width:130px">NPC</th><th scope="col">Changes &amp; Context</th></tr></thead><tbody>
    <?php foreach($logs['rows'] as $row): $time=new DateTimeImmutable($row['started_at']);$kind=rtrim($row['type'],'_');$id='rel-context-'.$row['provider_attempt_id']; ?>
        <tr><td><div class="rel-time"><?= $time->format('H:i:s') ?></div><div class="rel-date"><?= $time->format('M j') ?></div></td>
        <td><span class="rel-type rel-type-<?= lorkhan_ui_h($kind) ?>"><?= lorkhan_ui_h($kind) ?></span></td>
        <td><?= lorkhan_ui_h($row['npc'].($kind==='npc2npc'?' → '.$row['target']:'')) ?></td><td class="rel-changes">
            <?php if($row['affinity_delta']!==null): $delta=(int)$row['affinity_delta']; ?>
                <div class="rel-change-item"><strong><?= lorkhan_ui_h($row['target']) ?></strong>: <span class="rel-delta rel-delta-<?= $delta>0?'pos':($delta<0?'neg':'zero') ?>" title="Applied affinity change"><?= $delta>0?'+':'' ?><?= $delta ?></span>
                    <?php if((int)$row['disposition_delta']!==0): ?><span class="rel-source-note">Disposition <?= (int)$row['disposition_delta']>0?'+':'' ?><?= (int)$row['disposition_delta'] ?></span><?php endif; ?>
                    <div class="rel-reason"><?= lorkhan_ui_h($row['reason']??'') ?></div>
                </div>
            <?php elseif($row['changed_count']!==null): ?><span class="rel-source-note"><?= (int)$row['changed_count'] ?> relationships updated from <?= (int)$row['source_count'] ?> conversations. Individual changes were not recorded with this build.</span>
            <?php elseif($row['state']==='succeeded'): ?><span class="rel-source-note">Applied result not retained.</span>
            <?php else: ?><span class="rel-source-note"><?= lorkhan_ui_h(['started'=>'Pending evaluation','failed'=>'Evaluation failed','cancelled'=>'Cancelled before saving'][$row['state']]??$row['state']) ?></span><?php endif; ?>
            <?php if($row['context']!==''): ?><div class="rel-context-wrapper"><button class="rel-context-toggle" type="button" aria-expanded="false" aria-controls="<?= $id ?>">📋 Show Context</button><div class="rel-context-content" id="<?= $id ?>" hidden><span class="rel-source-note">Retained source conversation; the exact provider prompt was not recorded.</span><?= "\n\n".lorkhan_ui_h($row['context']) ?></div></div><?php endif; ?>
        </td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php endfor; endif; ?>
    <details class="relationship-tools-disclosure"<?= $notice!==''||$buildProfile!==''?' open':'' ?>><summary>Manage relationships &amp; change history</summary><?php include __DIR__.'/relationship_tools.html.php'; ?></details>
    <dialog id="clear-relationship-log" aria-labelledby="clear-relationship-title"><h2 id="clear-relationship-title">Delete relationship logs?</h2><p data-clear-description></p><p>Removes completed entries from Relationship Logs and Request Logs. Saved relationships, change history and provider accounting are retained. Pending work is protected.</p><p data-clear-status role="status" hidden></p><button class="btn-base" type="button" data-close-clear>Cancel</button> <button class="btn-base btn-danger" type="button" data-confirm-clear>Delete logs</button></dialog>
</main>
