<?php /* Narrator's compact prompt table shares the Prompts Manager documents and editor. */ ?>
<div class="narrator-inline-prompts-table-wrap">
    <table class="narrator-inline-prompts-table">
        <thead><tr><th>Prompt Key</th><th>Description</th><th>Status</th><th>Preview</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($narratorPromptRows as $promptRow):
            $custom = (string)($promptRow['content']['custom_prompt'] ?? '');
            $isCustom = trim($custom) !== '';
            $preview = mb_strimwidth($isCustom ? $custom : $promptRow['content']['default_prompt'], 0, 150, '...');
            $promptId = $promptRow['configuration_id'];
        ?>
            <tr data-prompt-inline-row="<?= lorkhan_ui_h($promptRow['prompt_key']) ?>">
                <td class="narrator-inline-prompt-key-cell"><code><?= lorkhan_ui_h($promptRow['prompt_key']) ?></code></td>
                <td class="narrator-inline-prompt-description-cell"><?= lorkhan_ui_h($promptRow['content']['description']) ?></td>
                <td><span data-inline-prompt-status class="narrator-inline-status-badge <?= $isCustom?'custom':'default' ?>"><?= $isCustom?'Custom':'Default' ?></span></td>
                <td class="narrator-inline-prompt-content-cell"><div data-inline-prompt-preview class="narrator-inline-prompt-preview <?= $isCustom?'custom':'' ?>"><?= lorkhan_ui_h($preview) ?></div></td>
                <td class="narrator-inline-prompts-actions-cell">
                    <button type="button" class="narrator-inline-prompts-btn narrator-inline-prompts-btn-edit" data-prompt-edit="<?= lorkhan_ui_h($promptId) ?>">Edit</button>
                    <button type="button" class="narrator-inline-prompts-btn narrator-inline-prompts-btn-clear" data-prompt-clear="<?= lorkhan_ui_h($promptId) ?>"<?= $isCustom?'':' hidden' ?>>Clear</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="narrator-hint" data-inline-prompt-notice role="status">Changes save immediately and are shared with Prompts Manager. Narration settings above save separately.</p>
