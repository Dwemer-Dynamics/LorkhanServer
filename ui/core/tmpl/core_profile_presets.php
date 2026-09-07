<?php declare(strict_types=1); ?>
<div class="profile-preset-row" data-core-presets data-endpoint="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/core-profile-preset" data-installation="<?php echo lorkhan_ui_h($installationId); ?>" data-revision="<?php echo (int)$selected['current_revision']; ?>">
    <label class="profile-preset-label" for="profile-preset-select">Profile Preset</label>
    <select class="profile-preset-select" id="profile-preset-select" aria-describedby="profile-preset-status"><option value="">Choose preset…</option>
        <?php foreach($managementRepository->coreProfilePresets($installationId) as $preset): ?><option value="<?php echo lorkhan_ui_h($preset['preset_id']); ?>" data-revision="<?php echo (int)$preset['revision']; ?>"><?php echo lorkhan_ui_h($preset['name']); ?></option><?php endforeach; ?>
    </select>
    <button type="button" class="btn-save profile-preset-apply" data-core-preset-action="apply" disabled>Apply</button>
    <div class="profile-preset-actions">
        <button type="button" class="btn-base profile-preset-btn" data-core-preset-action="save_new">Save as new…</button>
        <button type="button" class="btn-base profile-preset-btn" data-core-preset-action="overwrite" disabled>Overwrite…</button>
        <button type="button" class="btn-base profile-preset-btn" data-core-preset-action="export" disabled>Export</button>
        <button type="button" class="btn-base profile-preset-btn" data-core-preset-action="import">Import</button>
    </div>
    <input type="file" id="profile-preset-file" accept="application/json,.json" hidden>
    <span class="profile-preset-status" id="profile-preset-status" role="status" aria-live="polite"></span>
</div>
<dialog class="profile-preset-dialog" id="profile-preset-dialog" aria-labelledby="profile-preset-title" aria-describedby="profile-preset-description">
    <h2 id="profile-preset-title"></h2><p id="profile-preset-description"></p>
    <label id="profile-preset-name-field" for="profile-preset-name" hidden>Preset name<input id="profile-preset-name" maxlength="128" autocomplete="off"></label>
    <p id="profile-preset-error" role="alert" hidden></p>
    <div class="profile-preset-dialog-actions"><button type="button" class="btn-base" id="profile-preset-cancel">Cancel</button><button type="button" class="btn-save" id="profile-preset-confirm">Apply Preset</button></div>
</dialog>
