<?php /* Herika-style prompt rows/readers over Lorkhan's revisioned prompt documents. */ ?>
<section class="prompt-database">
    <div class="search-heading">
        <label for="prompt-search">Search Prompts</label>
        <p>Filter by prompt key, description, status, or preview text.</p>
        <input id="prompt-search" type="search" class="form-control" placeholder="Search prompts..." autocomplete="off" data-prompt-search>
        <p data-prompt-search-empty hidden>No prompts match your current search.</p>
    </div>
    <?php if($rows===[]): ?>
        <div class="prompt-warning"><h3>⚠️ No Prompts Found</h3><p>Use Prompt documents → Create Prompt to add one.</p></div>
    <?php else: ?>
    <div class="prompt-table-wrap table-container"><table class="prompts-table"><thead><tr><th>Prompt Key</th><th>Description</th><th>Status</th><th>Preview</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $row):
        $content=$row['content'];$custom=(string)($content['custom_prompt']??'');$isCustom=trim($custom)!=='';
        $description=(string)($content['description']??$row['name']);
        $preview=mb_strimwidth($isCustom?$custom:(string)($content['default_prompt']??''),0,150,'...');
        $id=(string)$row['configuration_id'];
    ?>
        <tr data-prompt-row data-search="<?= lorkhan_ui_h(mb_strtolower($row['prompt_key'].' '.$description.' '.($isCustom?'custom':'default').' '.$preview)) ?>">
            <td class="prompt-key-cell"><code><?= lorkhan_ui_h($row['prompt_key']) ?></code></td>
            <td class="prompt-description"><?= lorkhan_ui_h($description) ?></td>
            <td><span class="prompt-status <?= $isCustom?'custom':'default' ?>"><?= $isCustom?'🎨 Custom':'📋 Default' ?></span></td>
            <td class="prompt-content-cell"><div class="prompt-preview <?= $isCustom?'custom':'' ?>"><?= lorkhan_ui_h($preview) ?></div></td>
            <td><div class="row-actions"><button type="button" class="prompt-button prompt-edit-button" data-prompt-edit="<?= lorkhan_ui_h($id) ?>">✏️ Edit</button>
                <?php if($isCustom): ?><button type="button" class="prompt-button prompt-clear-button" data-prompt-clear="<?= lorkhan_ui_h($id) ?>">🔄 Clear</button><?php endif; ?>
            </div></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/prompt_dialogs.php'; ?>
