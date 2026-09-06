<main class="container-fluid response-queue-page" data-response-queue data-csrf="<?= lorkhan_ui_h($csrf) ?>" data-remove-endpoint="<?= lorkhan_ui_h($managementBasePath.'/api/v1/response-queue/remove') ?>">
    <div class="queue-heading"><h1 class="my-2">Response Queue</h1><details class="queue-tools"><summary>Filters and tools</summary><div class="queue-tools-body">
        <?php lorkhan_control_filters($state,$states); lorkhan_control_pagination($state); ?>
        <a class="control-reader-button" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['export'=>'1'])) ?>">Export This Page</a>
        <p>Queued response messages, including dialogue, actions and lifecycle events. Sent means published by the server, not played in game. Playback details show actual playback state. Row removal clears only this log projection; native delivery and history are retained. Pending work cannot be removed.</p>
        <details class="queue-playback"><summary>Playback details</summary><table class="table table-sm"><thead><tr><th>rowid</th><th>State</th><th>Delivered</th><th>Deadline</th></tr></thead><tbody><?php foreach ($state['rows'] as $row): if ($row['delivery_state']===null) continue; ?><tr><td><?= (int)$row['rowid'] ?></td><td><?= lorkhan_ui_h($row['delivery_state']) ?></td><td><?= lorkhan_ui_h($row['delivered_at']??'Not recorded') ?></td><td><?= lorkhan_ui_h($row['delivery_deadline_at']??'Not recorded') ?></td></tr><?php endforeach; ?></tbody></table></details>
    </div></details></div>
    <div class="datatable" tabindex="0" role="region" aria-label="Response Queue">
        <?php if ($state['rows']===[]): ?><p class="queue-empty">No queued responses match these filters.</p>
        <?php else: ?><table class="table table-striped table-bordered table-sm"><tbody><tr class="primary"><?php foreach (['localts','sent','actor','text','action','tag','rowid'] as $column): ?><th scope="col"><?= $column ?></th><?php endforeach; ?></tr>
        <?php foreach ($state['rows'] as $row): ?><tr>
            <td title="<?= lorkhan_ui_h(gmdate('d-m-Y H:i:s',(int)$row['localts'])) ?> UTC"><?= (int)$row['localts'] ?></td>
            <td title="<?= (int)$row['sent']===1?'Published by the server; not a playback receipt':'Queued' ?>"><?= (int)$row['sent'] ?></td>
            <td><?= lorkhan_ui_h($row['actor']??'') ?></td>
            <td class="queue-text"><?= lorkhan_ui_h($row['text']??'') ?></td>
            <td><?= lorkhan_ui_h($row['action']??'') ?></td><td><?= lorkhan_ui_h($row['tag']??'') ?></td>
            <td><button type="button" class="queue-row-remove" data-remove-row="<?= (int)$row['rowid'] ?>" data-installation="<?= lorkhan_ui_h($row['installation_id']) ?>" aria-label="Remove queue log entry <?= (int)$row['rowid'] ?>" title="<?= filter_var($row['can_remove'],FILTER_VALIDATE_BOOL)?'Remove this completed log entry; delivery and history are retained':'Pending work cannot be removed' ?>"<?= filter_var($row['can_remove'],FILTER_VALIDATE_BOOL)?'':' disabled' ?>><?= (int)$row['rowid'] ?> <span aria-hidden="true">&#x1F5D1;&#xFE0F;</span></button></td>
        </tr><?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
    <?php if ($state['pages']>1): ?><?php lorkhan_control_pagination($state); ?><?php endif; ?>
    <dialog class="queue-remove-dialog" aria-labelledby="queue-remove-title"><h2 id="queue-remove-title">Remove Queue Log Entry</h2>
        <p>Remove entry <strong data-remove-id></strong> from this log? Native response events, delivery and conversation history will be retained.</p>
        <div class="queue-dialog-actions"><button type="button" class="control-reader-button" data-remove-cancel>Cancel</button><button type="button" class="control-reader-button queue-confirm" data-remove-confirm>Remove Entry</button></div><p data-remove-status role="status" hidden></p>
    </dialog>
</main>
