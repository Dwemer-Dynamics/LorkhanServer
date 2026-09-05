<?php
declare(strict_types=1);

/** Keep CHIM's scope/people/summary table while preserving Lorkhan's revisioned edit forms. */
function lorkhan_roleplay_memory_table(array $rows, array $profiles, array $playthroughs, string $base, string $csrf): void
{
    ?>
    <div class="memory-log-scroll" tabindex="0" role="region" aria-label="Memory summaries">
        <table class="memory-log-table"><thead><tr><th scope="col">ID</th><th scope="col">Scope</th><th scope="col">People</th><th scope="col">Time (UTC)</th><th scope="col">Summary</th></tr></thead><tbody>
        <?php foreach($rows as $row): $id=(string)$row['memory_id'];$history=is_array($row['revisions']??null)?$row['revisions']:[]; ?>
        <tr><td><abbr title="<?= lorkhan_ui_h($id) ?>"><?= lorkhan_ui_h(substr($id,0,8)) ?></abbr></td>
            <td><?= lorkhan_ui_h($row['tier']??'') ?><br><small><?= lorkhan_ui_h($playthroughs[$row['playthrough_id']]??'') ?></small></td>
            <td><?= lorkhan_ui_h($profiles[$row['profile_id']]??'—') ?></td>
            <td><?= empty($row['occurred_at'])?'—':lorkhan_ui_h(gmdate('d-m-Y H:i:s',strtotime($row['occurred_at']))) ?></td>
            <td><div class="memory-summary-text"><?= lorkhan_ui_h($row['content']??'') ?></div>
                <details class="memory-details"><summary>Details</summary><p>Revision <?= (int)($row['current_revision']??1) ?> · <?= lorkhan_ui_h($row['eligibility']??'authored') ?></p>
                    <details><summary>Revision history (<?= count($history) ?>)</summary><ol><?php foreach($history as $revision): ?><li>r<?= (int)($revision['revision']??0) ?> <?= lorkhan_ui_h($revision['reason']??'revised') ?> <small><?= lorkhan_ui_h($revision['created_at']??'') ?></small></li><?php endforeach; ?></ol></details>
                    <?= lorkhan_roleplay_memory_summary_control($row,$base,$csrf) ?>
                </details>
                <details class="memory-details"><summary>Edit memory</summary><form class="management-form" method="post" action="<?= lorkhan_ui_h($base.'/forms/memory-revise') ?>">
                    <label for="memory-edit-<?= lorkhan_ui_h($id) ?>">Memory content</label><textarea id="memory-edit-<?= lorkhan_ui_h($id) ?>" name="content" required rows="8"><?= lorkhan_ui_h($row['content']??'') ?></textarea>
                    <input type="hidden" name="memory_id" value="<?= lorkhan_ui_h($id) ?>"><input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><button class="btn-base btn-primary" type="submit">Save memory</button>
                </form></details>
                <form class="danger-form" method="post" action="<?= lorkhan_ui_h($base.'/forms/memory-delete') ?>"><input type="hidden" name="memory_id" value="<?= lorkhan_ui_h($id) ?>"><input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><button class="btn-base btn-danger" type="submit">Delete memory</button></form>
            </td></tr>
        <?php endforeach; ?><?php if($rows===[]): ?><tr><td colspan="5" class="log-empty">No memories are available yet.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
    <?php
}
