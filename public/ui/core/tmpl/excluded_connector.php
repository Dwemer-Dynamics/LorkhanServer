<?php

declare(strict_types=1);

/** @var string $featureId */
/** @var string $connectorKind */
/** @var string $heading */
/** @var string $subtitle */
/** @var string $summary */
/** @var string $activeDriver */
/** @var array<string,array<int,array<int,string>>> $providers */
/** @var array<int,array<int,mixed>> $fields */
/** @var array<int,array<int,mixed>> $settingsFields */
?>
<main class="excluded-connector-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="page-shell">
        <header class="page-header">
            <h1 class="api-title"><?php echo almsivi_ui_h($heading); ?></h1>
            <p class="page-subtitle"><?php echo almsivi_ui_h($subtitle); ?></p>
            <span id="<?php echo almsivi_ui_h($connectorKind); ?>-excluded-note" class="feature-status-corner" title="<?php echo almsivi_ui_h(almsivi_ui_feature($featureId)['description']); ?>"><?php echo almsivi_ui_feature_badge($featureId); ?></span>
        </header>

        <div class="layout">
            <aside class="left-col">
                <div class="summary-note"><?php echo almsivi_ui_h($summary); ?></div>
                <div class="list-wrap" id="<?php echo almsivi_ui_h($connectorKind); ?>_driver_list">
                    <?php foreach ($providers as $groupLabel => $groupProviders): ?>
                    <div class="group-title"><?php echo almsivi_ui_h($groupLabel); ?></div>
                    <?php foreach ($groupProviders as [$driver, $name, $providerKey, $providerTitle]): ?>
                    <div class="conn-card<?php echo $driver === $activeDriver ? ' active' : ''; ?>" role="button" aria-disabled="true" title="<?php echo almsivi_ui_h(almsivi_ui_feature($featureId)['description']); ?>">
                        <span class="conn-head">
                            <span class="conn-name"><?php echo almsivi_ui_h($name); ?></span>
                            <span class="conn-badge"><?php echo almsivi_ui_h($providerKey); ?></span>
                        </span>
                        <span class="conn-sub"><?php echo almsivi_ui_h($providerTitle); ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </aside>

            <section class="right-col">
                <div class="btn-row">
                    <?php foreach ($toolbar as $index => $label): ?>
                    <span class="excluded-control">
                        <button type="button" class="<?php echo $index === 0 ? 'btn-save' : ($index === 1 ? 'btn-primary' : 'btn-secondary'); ?>" disabled aria-disabled="true"><?php echo almsivi_ui_h($label); ?></button>
                        <?php echo almsivi_ui_feature_badge($featureId, true); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <div class="orm-note"><?php echo almsivi_ui_h($testingNote); ?></div>
                <fieldset disabled aria-describedby="<?php echo almsivi_ui_h($connectorKind); ?>-excluded-note">
                    <legend class="visually-hidden"><?php echo almsivi_ui_h($heading); ?> settings</legend>
                    <div class="editor-grid">
                        <?php foreach ($fields as $field): [$type, $label, $value, $help] = $field; $options = $field[4] ?? []; ?>
                        <div class="field-block">
                            <label><?php echo almsivi_ui_h($label); ?></label>
                            <?php if ($type === 'select'): ?>
                            <select aria-label="<?php echo almsivi_ui_h($label); ?>">
                                <?php foreach ($options as $option): ?><option<?php echo $option === $value ? ' selected' : ''; ?>><?php echo almsivi_ui_h($option); ?></option><?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input type="<?php echo almsivi_ui_h($type); ?>" aria-label="<?php echo almsivi_ui_h($label); ?>" value="<?php echo almsivi_ui_h($value); ?>">
                            <?php endif; ?>
                            <?php if ($label === 'API Badge'): ?><div class="api-key-notice <?php echo $connectorKind === 'stt' ? 'warn' : 'ok'; ?>"><?php echo $connectorKind === 'stt' ? 'Selected API badge does not have a configured key yet.' : 'Selected API badge is configured.'; ?></div><?php endif; ?>
                            <div class="field-help"><?php echo almsivi_ui_h($help); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="meta-group active">
                        <h3><?php echo almsivi_ui_h($settingsTitle); ?></h3>
                        <div class="inline-two">
                            <?php foreach ($settingsFields as $field): [$type, $label, $value, $help] = $field; $options = $field[4] ?? []; ?>
                            <div class="field-block">
                                <label><?php echo almsivi_ui_h($label); ?></label>
                                <?php if ($type === 'select'): ?>
                                <select aria-label="<?php echo almsivi_ui_h($label); ?>">
                                    <?php foreach ($options as $option): ?><option<?php echo $option === $value ? ' selected' : ''; ?>><?php echo almsivi_ui_h($option); ?></option><?php endforeach; ?>
                                </select>
                                <?php elseif ($type === 'textarea'): ?>
                                <textarea aria-label="<?php echo almsivi_ui_h($label); ?>"><?php echo almsivi_ui_h($value); ?></textarea>
                                <?php else: ?>
                                <input type="<?php echo almsivi_ui_h($type); ?>" aria-label="<?php echo almsivi_ui_h($label); ?>" value="<?php echo almsivi_ui_h($value); ?>">
                                <?php endif; ?>
                                <div class="field-help"><?php echo almsivi_ui_h($help); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </fieldset>
            </section>
        </div>
    </div>
</main>
