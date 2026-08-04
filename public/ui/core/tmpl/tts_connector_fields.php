<?php

declare(strict_types=1);

/** Render labelled TTS fields and only the typed options supported by the selected driver. */
function almsivi_ui_tts_fields(array $content, array $drivers, array $optionCatalog, array $connectorDefaults, string $formId, string $defaultDriver = 'omnivoice'): void
{
    $options = is_array($content['options'] ?? null) ? $content['options'] : [];
    $driver = (string) ($content['driver'] ?? $defaultDriver);
    $defaults = $connectorDefaults[$driver] ?? [];
    ?>
    <input type="hidden" name="option_fields_present" value="1">
    <div class="almsivi-form-grid">
        <label>Driver<select id="<?php echo almsivi_ui_h($formId . '-driver'); ?>" name="driver"><?php foreach ($drivers as $driverId => $label): ?><option value="<?php echo almsivi_ui_h($driverId); ?>"<?php echo $driver === $driverId ? ' selected' : ''; ?>><?php echo almsivi_ui_h($label); ?></option><?php endforeach; ?></select></label>
        <label>Endpoint<input name="endpoint" required value="<?php echo almsivi_ui_h($content['endpoint'] ?? $defaults['endpoint'] ?? ''); ?>"></label>
        <label>Model<input name="model" value="<?php echo almsivi_ui_h($content['model'] ?? $defaults['model'] ?? 'default'); ?>"></label>
        <label>Default voice<input name="voice" value="<?php echo almsivi_ui_h($content['voice'] ?? $defaults['voice'] ?? 'default'); ?>"></label>
        <label>Language<input name="language" value="<?php echo almsivi_ui_h($content['language'] ?? $defaults['language'] ?? 'en'); ?>"></label>
        <label>Timeout (ms)<input type="number" min="1000" max="120000" name="timeout_ms" value="<?php echo (int) ($content['timeout_ms'] ?? 30000); ?>"></label>
        <label>Male fallback voice ID<input name="fallback_male" value="<?php echo almsivi_ui_h($options['fallback_male'] ?? ''); ?>"></label>
        <label>Female fallback voice ID<input name="fallback_female" value="<?php echo almsivi_ui_h($options['fallback_female'] ?? ''); ?>"></label>
        <div class="wide connector-option-editor" data-connector-options data-driver-control="<?php echo almsivi_ui_h($formId . '-driver'); ?>" data-connector-defaults="<?php echo almsivi_ui_h(json_encode($connectorDefaults, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); ?>">
            <strong>Provider-specific options</strong>
            <?php foreach ($optionCatalog as $catalogDriver => $optionFields): $active = $catalogDriver === $driver; ?>
                <div data-connector-driver="<?php echo almsivi_ui_h($catalogDriver); ?>"<?php echo $active ? '' : ' hidden'; ?>>
                    <?php foreach ($optionFields as $field): $name = (string) $field['name']; $type = (string) $field['type']; $value = $options[$name] ?? ''; ?>
                        <label>
                            <?php if ($type === 'boolean'): ?>
                                <span><input name="option__<?php echo almsivi_ui_h($name); ?>" type="checkbox" value="1"<?php echo $value === true ? ' checked' : ''; ?><?php echo $active ? '' : ' disabled'; ?>> <?php echo almsivi_ui_h($field['label']); ?></span>
                            <?php else: ?>
                                <?php echo almsivi_ui_h($field['label']); ?>
                                <?php if ($type === 'select'): ?>
                                    <select name="option__<?php echo almsivi_ui_h($name); ?>"<?php echo $active ? '' : ' disabled'; ?>><option value="">Connector default</option><?php foreach ($field['values'] as $choice): ?><option value="<?php echo almsivi_ui_h($choice); ?>"<?php echo (string) $value === (string) $choice ? ' selected' : ''; ?>><?php echo almsivi_ui_h($choice); ?></option><?php endforeach; ?></select>
                                <?php elseif (in_array($type, ['number', 'integer'], true)): ?>
                                    <input name="option__<?php echo almsivi_ui_h($name); ?>" type="number" step="<?php echo $type === 'integer' ? '1' : 'any'; ?>" min="<?php echo almsivi_ui_h($field['minimum']); ?>" max="<?php echo almsivi_ui_h($field['maximum']); ?>" value="<?php echo almsivi_ui_h($value); ?>"<?php echo $active ? '' : ' disabled'; ?>>
                                <?php else: ?>
                                    <input name="option__<?php echo almsivi_ui_h($name); ?>" maxlength="512" value="<?php echo almsivi_ui_h($value); ?>"<?php echo $active ? '' : ' disabled'; ?>>
                                <?php endif; ?>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <p class="almsivi-muted" data-connector-options-empty<?php echo ($optionCatalog[$driver] ?? []) === [] ? '' : ' hidden'; ?>>This connector has no additional labelled options.</p>
        </div>
        <label class="wide">Advanced connector options (JSON)<textarea name="options_json"><?php echo almsivi_ui_h(json_encode($options === [] ? (object) [] : $options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea></label>
    </div>
    <?php
}
