<?php

declare(strict_types=1);

$routing = is_array($content['routing'] ?? null) ? $content['routing'] : [];
$overrides = is_array($content['settings_overrides'] ?? null) ? $content['settings_overrides'] : [];
$profileMeta = is_array($profileMeta ?? null) ? $profileMeta : [];
$creatingProfile = ($coreProfileMode ?? 'edit') === 'create';
$profileIsDefault = filter_var($profileMeta['default_npc'] ?? false, FILTER_VALIDATE_BOOL);

$routeSelect = static function (string $name, string $label, string $icon, string $description, array $rows) use ($routing): void {
    ?>
    <div class="connector-option-card">
        <div class="setting-key"><span class="setting-icon"><?php echo $icon; ?></span><span><?php echo almsivi_ui_h($label); ?></span></div>
        <div class="setting-desc"><?php echo almsivi_ui_h($description); ?></div>
        <div class="setting-control"><select name="<?php echo almsivi_ui_h($name); ?>">
            <option value="">None / inherit</option>
            <?php foreach ($rows as $row): $id = (string) $row['configuration_id']; ?>
                <option value="<?php echo almsivi_ui_h($id); ?>"<?php echo ($routing[$name] ?? null) === $id ? ' selected' : ''; ?>><?php echo almsivi_ui_h($row['name']); ?></option>
            <?php endforeach; ?>
        </select></div>
    </div>
    <?php
};

$inheritSelect = static function (string $name, mixed $value): void {
    ?>
    <select class="profile-inherit-select" name="<?php echo almsivi_ui_h($name); ?>">
        <option value="inherit"<?php echo $value === null ? ' selected' : ''; ?>>Inherit</option>
        <option value="1"<?php echo $value === true ? ' selected' : ''; ?>>On</option>
        <option value="0"<?php echo $value === false ? ' selected' : ''; ?>>Off</option>
    </select>
    <?php
};

$toggleCard = static function (string $name, string $icon, string $title, string $description, mixed $value) use ($inheritSelect): void {
    ?>
    <label class="profile-toggle-card">
        <span class="profile-toggle-heading"><span><?php echo $icon; ?> <?php echo almsivi_ui_h($title); ?></span><span class="profile-toggle-control"><?php $inheritSelect($name, $value); ?></span></span>
        <span class="profile-toggle-description"><?php echo almsivi_ui_h($description); ?></span>
    </label>
    <?php
};

$placeholderCard = static function (string $icon, string $title, string $description, string $featureId): void {
    ?>
    <div class="profile-toggle-card feature-placeholder-card" title="<?php echo almsivi_ui_h(almsivi_ui_feature($featureId)['description']); ?>">
        <span class="profile-toggle-heading"><span><?php echo $icon; ?> <?php echo almsivi_ui_h($title); ?></span><?php echo almsivi_ui_feature_badge($featureId, true); ?></span>
        <span class="profile-toggle-description"><?php echo almsivi_ui_h($description); ?></span>
    </div>
    <?php
};

$numberField = static function (string $section, string $field, string $label, string $description, int $min, int $max) use ($overrides): void {
    $value = $overrides[$section][$field] ?? '';
    ?>
    <div class="setting-row">
        <div><div class="setting-key"><?php echo almsivi_ui_h($label); ?></div><div class="setting-desc"><?php echo almsivi_ui_h($description); ?></div></div>
        <div class="setting-control"><input type="number" min="<?php echo $min; ?>" max="<?php echo $max; ?>" name="setting_<?php echo almsivi_ui_h($section . '_' . $field); ?>" value="<?php echo almsivi_ui_h($value); ?>" placeholder="Inherit"></div>
    </div>
    <?php
};

$selectSetting = static function (string $name, string $label, string $description, mixed $value) use ($inheritSelect): void {
    ?>
    <div class="setting-row">
        <div><div class="setting-key"><?php echo almsivi_ui_h($label); ?></div><div class="setting-desc"><?php echo almsivi_ui_h($description); ?></div></div>
        <div class="setting-control"><?php $inheritSelect($name, $value); ?></div>
    </div>
    <?php
};

$disabledSelectSetting = static function (string $name, string $label, string $description, mixed $value, string $featureId): void {
    $display = $value === true ? 'On' : ($value === false ? 'Off' : 'Inherit');
    $stored = $value === true ? '1' : ($value === false ? '0' : 'inherit');
    ?>
    <div class="setting-row feature-placeholder-card" title="<?php echo almsivi_ui_h(almsivi_ui_feature($featureId)['description']); ?>">
        <div><div class="setting-key"><?php echo almsivi_ui_h($label); ?> <?php echo almsivi_ui_feature_badge($featureId, true); ?></div><div class="setting-desc"><?php echo almsivi_ui_h($description); ?></div></div>
        <div class="setting-control"><input type="hidden" name="<?php echo almsivi_ui_h($name); ?>" value="<?php echo almsivi_ui_h($stored); ?>"><select disabled aria-disabled="true"><option><?php echo almsivi_ui_h($display); ?></option></select></div>
    </div>
    <?php
};

$disabledNumberField = static function (string $section, string $field, string $label, string $description, string $featureId) use ($overrides): void {
    $value = $overrides[$section][$field] ?? '';
    ?>
    <div class="setting-row feature-placeholder-card" title="<?php echo almsivi_ui_h(almsivi_ui_feature($featureId)['description']); ?>">
        <div><div class="setting-key"><?php echo almsivi_ui_h($label); ?> <?php echo almsivi_ui_feature_badge($featureId, true); ?></div><div class="setting-desc"><?php echo almsivi_ui_h($description); ?></div></div>
        <div class="setting-control"><input type="hidden" name="setting_<?php echo almsivi_ui_h($section . '_' . $field); ?>" value="<?php echo almsivi_ui_h($value); ?>"><input type="text" value="<?php echo almsivi_ui_h($value === '' ? 'Inherit' : $value); ?>" disabled aria-disabled="true"></div>
    </div>
    <?php
};
?>

<div class="connector-card profile-core-card">
    <div class="connector-title">Profile Core</div>
    <div class="connector-subtitle">&#x24D8; Core identity and runtime options for this profile.</div>

    <div class="profile-core-grid">
        <div class="profile-core-compact-field">
            <label for="profile-label">Name</label>
            <input id="profile-label" name="label" required maxlength="256" value="<?php echo almsivi_ui_h($profileMeta['label'] ?? ''); ?>">
            <small class="hint">Name shown when assigning this profile.</small>
        </div>
        <div class="profile-core-compact-field">
            <label for="profile-slot">Slot <span class="profile-info" title="Can be assigned in game through ALMSIVI profile controls">&#x24D8;</span></label>
            <select id="profile-slot" name="slot">
                <option value="">&mdash;</option>
                <?php foreach (range(1, 4) as $slot): $slotOwner = (string) ($usedProfileSlots[$slot] ?? ''); $slotUnavailable = $slotOwner !== '' && $slotOwner !== (string) ($profileMeta['core_profile_id'] ?? ''); ?><option value="<?php echo $slot; ?>"<?php echo (int) ($profileMeta['slot'] ?? 0) === $slot ? ' selected' : ''; ?><?php echo $slotUnavailable ? ' disabled' : ''; ?>><?php echo $slot; ?></option><?php endforeach; ?>
            </select>
            <small class="hint">In-game profile shortcut.</small>
        </div>
        <label class="profile-default-card<?php echo !$creatingProfile && $profileIsDefault ? ' is-readonly' : ''; ?>">
            <?php if (!$creatingProfile && $profileIsDefault): ?><input type="hidden" name="default_npc" value="1"><?php endif; ?>
            <span class="profile-toggle-heading"><span>&#x1F464; Default NPC</span><span class="profile-toggle-control"><input type="checkbox"<?php echo !$creatingProfile && $profileIsDefault ? ' disabled aria-disabled="true"' : ' name="default_npc" value="1"'; ?><?php echo $profileIsDefault ? ' checked' : ''; ?>><span class="toggle-text"><?php echo $profileIsDefault ? 'On' : 'Off'; ?></span></span></span>
            <span class="profile-toggle-description">Use for newly discovered NPCs.</span>
        </label>
    </div>

    <div class="profile-prompt-field">
        <label for="profile-prompt">Profile Prompt</label>
        <textarea id="profile-prompt" name="prompt" maxlength="65536"><?php echo almsivi_ui_h($content['prompt'] ?? ''); ?></textarea>
        <small class="hint">Optional profile-specific system instructions appended to requests.</small>
    </div>

    <div class="profile-toggle-groups">
        <section class="profile-toggle-group">
            <h3 class="profile-toggle-group-title">Profiles &amp; Memories</h3>
            <div class="profile-toggle-grid">
                <?php $placeholderCard('&#x267B;&#xFE0F;', 'Dynamic Profile', 'Allow gameplay events to evolve NPC profiles.', 'config.profiles.dynamic-profile'); ?>
                <?php $placeholderCard('&#x1F4C3;', 'Middle Term Memory', 'Include periodic middle-term memory summaries.', 'config.profiles.middle-term-memory'); ?>
            </div>
        </section>
        <section class="profile-toggle-group">
            <h3 class="profile-toggle-group-title">Diary</h3>
            <div class="profile-toggle-grid">
                <?php $placeholderCard('&#x1F4D9;', 'Auto Diary', 'Generate nearby NPC diaries during sleep or wait.', 'config.profiles.auto-diary'); ?>
                <?php $placeholderCard('&#x23F3;', 'Auto Diary Wait', 'Include wait events when Auto Diary is enabled.', 'config.profiles.auto-diary'); ?>
                <?php $placeholderCard('&#x1F4D5;', 'Physical Diary', 'Create a physical in-game diary that can be read.', 'config.profiles.physical-diary'); ?>
                <?php $placeholderCard('&#x1F4D6;', 'Include Latest Diary Entry', 'Include the NPC latest diary entry in response context.', 'config.profiles.latest-diary'); ?>
            </div>
        </section>
        <section class="profile-toggle-group">
            <h3 class="profile-toggle-group-title">LLM</h3>
            <div class="profile-toggle-grid">
                <?php $toggleCard('llm_randomizer_enabled', '&#x1F3B2;', 'LLM Randomizer', 'Rotate among the four response connectors.', $routing['llm_randomizer_enabled'] ?? null); ?>
                <?php $toggleCard('llm_fallback_enabled', '&#x1F504;', 'LLM Fallback', 'Retry failed requests with the fallback connector.', $routing['llm_fallback_enabled'] ?? null); ?>
            </div>
        </section>
    </div>
</div>

<div class="connector-card profile-connectors-card">
    <div class="connector-title">Connector Selection</div>
    <div class="connector-subtitle">&#x24D8; Choose connector assignments for each role. Saved with Save All.</div>
    <div class="connector-selection-grid">
        <section class="connector-group-card">
            <h3 class="connector-group-title">Response Connectors</h3>
            <div class="connector-group-subtitle">Connectors used for live NPC dialogue and response modes.</div>
            <div class="connector-group-fields">
                <?php $routeSelect('llm_configuration_id', 'Standard LLM', '&#x1F579;&#xFE0F;', 'General-purpose connector for normal roleplay responses.', $llm); ?>
                <?php $routeSelect('llm_fast_configuration_id', 'Fast LLM', '&#x1F3C3;', 'Lower-latency connector for quick reactions.', $llm); ?>
                <?php $routeSelect('llm_powerful_configuration_id', 'Powerful LLM', '&#x1F4AA;', 'Higher-quality connector for deeper responses.', $llm); ?>
                <?php $routeSelect('llm_experimental_configuration_id', 'Experimental LLM', '&#x1F9EA;', 'Optional connector for experimentation and variety.', $llm); ?>
            </div>
        </section>
        <section class="connector-group-card">
            <h3 class="connector-group-title">Other Connectors</h3>
            <div class="connector-group-subtitle">Voice, prompt, fallback, diary, and formatting services.</div>
            <div class="connector-group-fields">
                <?php $routeSelect('tts_configuration_id', 'TTS Connector', '&#x1F50A;', 'Voice synthesis connector used for spoken output.', $tts); ?>
                <?php $routeSelect('prompt_configuration_id', 'Dialogue Prompt', '&#x1F4AC;', 'Prompt template inherited by NPCs using this profile.', $prompts); ?>
                <?php $routeSelect('llm_fallback_configuration_id', 'Fallback LLM', '&#x1F504;', 'Backup connector used when primary requests fail.', $llm); ?>
                <div class="connector-option-card feature-placeholder-card"><div class="setting-key"><span class="setting-icon">&#x1F4D3;</span><span>Diary LLM</span><?php echo almsivi_ui_feature_badge('config.profiles.diary-llm', true); ?></div><div class="setting-desc">Connector used for diary generation.</div><div class="setting-control"><select disabled aria-disabled="true"><option>Active narrative pipeline</option></select></div></div>
                <div class="connector-option-card feature-placeholder-card"><div class="setting-key"><span class="setting-icon">&#x1F9FE;</span><span>Formatter LLM</span><?php echo almsivi_ui_feature_badge('config.profiles.formatter-llm', true); ?></div><div class="setting-desc">Connector used for structured background tasks.</div><div class="setting-control"><select disabled aria-disabled="true"><option>Not configured</option></select></div></div>
                <div class="connector-option-card feature-placeholder-card"><div class="setting-key"><span class="setting-icon">&#x1F5BC;&#xFE0F;</span><span>ITT Connector</span><?php echo almsivi_ui_feature_badge('config.itt', true); ?></div><div class="setting-desc">Image-to-text connector used for visual context.</div><div class="setting-control"><select disabled aria-disabled="true"><option>Excluded</option></select></div></div>
            </div>
        </section>
    </div>
</div>

<div class="connector-card profile-settings-card">
    <div class="connector-title">Profile Settings</div>
    <div class="profile-feature-grid">
        <div class="provider-card feature-placeholder-card">
            <div class="provider-head"><div class="provider-title"><div class="provider-icon">&#x1F310;</div><div>Language</div></div><?php echo almsivi_ui_feature_badge('config.profiles.language', true); ?></div>
            <div class="provider-body"><div class="setting-row"><div><div class="setting-key">Profile Language</div><div class="setting-desc">Language used for profile-specific dialogue.</div></div><div class="setting-control"><select disabled aria-disabled="true"><option>Installation default</option></select></div></div></div>
        </div>
        <div class="provider-card">
            <div class="provider-head"><div class="provider-title"><div class="provider-icon">&#x1F4AC;</div><div>Conversation</div></div></div>
            <div class="provider-body">
                <?php $disabledSelectSetting('setting_behavior_auto_greeting', 'Automatic Greeting', 'Automatic model-triggering is excluded from this milestone.', $overrides['behavior']['auto_greeting'] ?? null, 'autonomy'); ?>
                <?php $selectSetting('setting_behavior_rechat', 'Rechat', 'Continue a player-started conversation after its speech queue completes.', $overrides['behavior']['rechat'] ?? null); ?>
                <?php $disabledSelectSetting('setting_behavior_boredom', 'Bored Event', 'Automatic model-triggering is excluded from this milestone.', $overrides['behavior']['boredom'] ?? null, 'autonomy'); ?>
                <?php $disabledSelectSetting('setting_behavior_combat_barks', 'Combat Barks', 'Automatic model-triggering is excluded from this milestone.', $overrides['behavior']['combat_barks'] ?? null, 'autonomy'); ?>
            </div>
        </div>
    </div>
    <div class="profile-settings-columns">
        <section class="profile-settings-group"><h3 class="profile-settings-heading">Rechat &amp; Bored Event</h3><div class="provider-card">
            <?php $disabledNumberField('behavior', 'rechat_delay_seconds', 'Rechat Delay', 'Automatic model-triggering is excluded from this milestone.', 'autonomy'); ?>
            <?php $numberField('behavior', 'rechat_max_depth', 'Rechat Depth', 'Maximum inherited continuation replies.', 1, 20); ?>
            <?php $disabledNumberField('behavior', 'boredom_delay_seconds', 'Bored Event Delay', 'Automatic model-triggering is excluded from this milestone.', 'autonomy'); ?>
            <?php $disabledNumberField('behavior', 'combat_bark_period_seconds', 'Combat Bark Period', 'Automatic model-triggering is excluded from this milestone.', 'autonomy'); ?>
        </div></section>
        <section class="profile-settings-group"><h3 class="profile-settings-heading">Context &amp; Presentation</h3><div class="provider-card">
            <?php $numberField('memory', 'recent_turn_limit', 'Recent Turns', 'Maximum recent conversation turns in context.', 1, 100); ?>
            <?php $numberField('memory', 'knowledge_limit', 'Knowledge Results', 'Maximum scoped knowledge documents returned.', 0, 20); ?>
            <?php $disabledSelectSetting('setting_presentation_show_status_hud', 'Show Status HUD', 'Controlled by local OpenMW settings.', $overrides['presentation']['show_status_hud'] ?? null, 'presentation.local'); ?>
            <?php $disabledNumberField('presentation', 'transcript_rows', 'Transcript Rows', 'Controlled by local OpenMW settings.', 'presentation.local'); ?>
            <?php $disabledNumberField('presentation', 'tts_volume_boost', 'TTS Volume Boost', 'Controlled by local OpenMW settings.', 'presentation.local'); ?>
        </div></section>
        <section class="profile-settings-group"><h3 class="profile-settings-heading">Narrator</h3><div class="provider-card">
            <?php $selectSetting('setting_narrator_enabled', 'Enable Narrator', 'Allow inherited narrator events for this profile.', $overrides['narrator']['enabled'] ?? null); ?>
            <?php $selectSetting('setting_narrator_context_visibility', 'Narrator Context', 'Include narrator-visible context for this profile.', $overrides['narrator']['context_visibility'] ?? null); ?>
            <?php foreach (['welcome_events' => 'Welcome Events', 'random_events' => 'Random Events', 'quest_events' => 'Quest Events', 'book_events' => 'Book Events'] as $field => $label) $disabledSelectSetting('setting_narrator_' . $field, $label, 'Automatic narrator triggers are excluded from this milestone.', $overrides['narrator'][$field] ?? null, 'autonomy'); ?>
            <div class="setting-row"><div><div class="setting-key">Narrator Name</div><div class="setting-desc">Optional profile-specific narrator display name.</div></div><div class="setting-control"><input name="setting_narrator_name" maxlength="128" value="<?php echo almsivi_ui_h($overrides['narrator']['name'] ?? ''); ?>" placeholder="Inherit"></div></div>
            <div class="setting-row"><div><div class="setting-key">Inline Mode</div><div class="setting-desc">How narrator text is routed to dialogue output.</div></div><div class="setting-control"><select name="setting_narrator_inline_mode"><option value="">Inherit</option><?php foreach (['Disabled', 'Narrator', 'NPC', 'Text Only'] as $mode): ?><option<?php echo ($overrides['narrator']['inline_mode'] ?? null) === $mode ? ' selected' : ''; ?>><?php echo almsivi_ui_h($mode); ?></option><?php endforeach; ?></select></div></div>
        </div></section>
        <section class="profile-settings-group"><h3 class="profile-settings-heading">Safety</h3><div class="provider-card">
            <?php $selectSetting('setting_safety_actions_enabled', 'Negotiated Actions', 'Allow bounded action negotiation.', $overrides['safety']['actions_enabled'] ?? null); ?>
            <?php $selectSetting('setting_safety_allow_hostile', 'Hostile NPC Targets', 'Allow hostile NPCs to participate.', $overrides['safety']['allow_hostile'] ?? null); ?>
            <?php $selectSetting('setting_safety_allow_creatures', 'Creature Targets', 'Allow eligible creature targets.', $overrides['safety']['allow_creatures'] ?? null); ?>
        </div></section>
    </div>
</div>
