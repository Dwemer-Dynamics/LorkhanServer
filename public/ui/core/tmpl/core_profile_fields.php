<?php

declare(strict_types=1);

$routing = is_array($content['routing'] ?? null) ? $content['routing'] : [];
$overrides = is_array($content['settings_overrides'] ?? null) ? $content['settings_overrides'] : [];
$profileMeta = is_array($profileMeta ?? null) ? $profileMeta : [];
$creatingProfile = ($coreProfileMode ?? 'edit') === 'create';
$profileIsDefault = filter_var($profileMeta['default_npc'] ?? false, FILTER_VALIDATE_BOOL);

$routeSelect = static function (string $name, string $label, string $icon, string $description, array $rows, ?string $blankLabel = null, string $help = '') use ($routing): void {
    $labelId = $name . '-label';
    $helpId = $help === '' ? '' : $name . '-help';
    // Retain an unavailable generation route until the user explicitly chooses its replacement.
    if ($blankLabel !== null && ($routing[$name] ?? '') !== '' && !in_array($routing[$name], array_column($rows, 'configuration_id'), true)) {
        $rows[] = ['configuration_id' => $routing[$name], 'name' => 'Unavailable connector'];
    }
    ?>
    <div class="connector-option-card">
        <div class="setting-key"><span class="setting-icon"><?php echo $icon; ?></span><span<?php echo $labelId === '' ? '' : ' id="' . lorkhan_ui_h($labelId) . '"'; ?>><?php echo lorkhan_ui_h($label); ?></span></div>
        <div class="setting-desc"><?php echo lorkhan_ui_h($description); ?></div>
        <div class="setting-control"><select name="<?php echo lorkhan_ui_h($name); ?>"<?php echo $labelId === '' ? '' : ' aria-labelledby="' . lorkhan_ui_h($labelId) . '"'; ?><?php echo $helpId === '' ? '' : ' aria-describedby="' . lorkhan_ui_h($helpId) . '"'; ?>>
            <option value=""><?php echo lorkhan_ui_h($blankLabel ?? 'None / inherit'); ?></option>
            <?php foreach ($rows as $row): $id = (string) $row['configuration_id']; ?>
                <option value="<?php echo lorkhan_ui_h($id); ?>"<?php echo ($routing[$name] ?? null) === $id ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($row['name']); ?></option>
            <?php endforeach; ?>
        </select><?php if ($helpId !== ''): ?><small class="hint" id="<?php echo lorkhan_ui_h($helpId); ?>"><?php echo lorkhan_ui_h($help); ?></small><?php endif; ?></div>
    </div>
    <?php
};

$inheritSelect = static function (string $name, mixed $value, string $ariaLabel = ''): void {
    ?>
    <select class="profile-inherit-select" name="<?php echo lorkhan_ui_h($name); ?>"<?php echo $ariaLabel === '' ? '' : ' aria-label="' . lorkhan_ui_h($ariaLabel) . '"'; ?>>
        <option value="inherit"<?php echo $value === null ? ' selected' : ''; ?>>Inherit</option>
        <option value="1"<?php echo $value === true ? ' selected' : ''; ?>>On</option>
        <option value="0"<?php echo $value === false ? ' selected' : ''; ?>>Off</option>
    </select>
    <?php
};

$toggleCard = static function (string $name, string $icon, string $title, string $description, mixed $value) use ($inheritSelect): void {
    ?>
    <label class="profile-toggle-card">
        <span class="profile-toggle-heading"><span><?php echo $icon; ?> <?php echo lorkhan_ui_h($title); ?></span><span class="profile-toggle-control"><?php $inheritSelect($name, $value); ?></span></span>
        <span class="profile-toggle-description"><?php echo lorkhan_ui_h($description); ?></span>
    </label>
    <?php
};

$numberField = static function (string $section, string $field, string $label, string $description, int $min, int $max) use ($overrides): void {
    $value = $overrides[$section][$field] ?? '';
    ?>
    <div class="setting-row">
        <div><div class="setting-key"><?php echo lorkhan_ui_h($label); ?></div><div class="setting-desc"><?php echo lorkhan_ui_h($description); ?></div></div>
        <div class="setting-control"><input type="number" min="<?php echo $min; ?>" max="<?php echo $max; ?>" name="setting_<?php echo lorkhan_ui_h($section . '_' . $field); ?>" aria-label="<?php echo lorkhan_ui_h($label); ?>" value="<?php echo lorkhan_ui_h($value); ?>" placeholder="Inherit"></div>
    </div>
    <?php
};

$selectSetting = static function (string $name, string $label, string $description, mixed $value) use ($inheritSelect): void {
    ?>
    <div class="setting-row">
        <div><div class="setting-key"><?php echo lorkhan_ui_h($label); ?></div><div class="setting-desc"><?php echo lorkhan_ui_h($description); ?></div></div>
        <div class="setting-control"><?php $inheritSelect($name, $value, $label); ?></div>
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
            <input id="profile-label" name="label" required maxlength="256" value="<?php echo lorkhan_ui_h($profileMeta['label'] ?? ''); ?>">
            <small class="hint">Name shown when assigning this profile.</small>
        </div>
        <div class="profile-core-compact-field">
            <label for="profile-slot">Slot <span class="profile-info" title="Can be assigned in game through LORKHAN profile controls">&#x24D8;</span></label>
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
        <textarea id="profile-prompt" name="prompt" maxlength="65536"><?php echo lorkhan_ui_h($content['prompt'] ?? ''); ?></textarea>
        <small class="hint">Optional profile-specific system instructions appended to requests.</small>
    </div>

    <div class="profile-toggle-groups">
        <section class="profile-toggle-group">
            <h3 class="profile-toggle-group-title">Diary</h3>
            <div class="profile-toggle-grid">
                <?php $toggleCard('setting_diary_enabled', '&#x1F4D3;', 'Manual Diary Generation', 'Off by default. On lets Narratives queue one requested diary for NPCs using this profile.', $overrides['diary']['enabled'] ?? null); ?>
                <?php $toggleCard('setting_diary_include_in_context', '&#x1F4D6;', 'Diary In Context', 'On by default. Off hides scoped diary narratives from this profile roleplay context.', $overrides['diary']['include_in_context'] ?? null); ?>
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
            <div class="connector-group-subtitle">Voice, prompt, fallback, generation, and diary services.</div>
            <div class="connector-group-fields">
                <?php $routeSelect('tts_configuration_id', 'TTS Connector', '&#x1F50A;', 'Voice synthesis connector used for spoken output.', $tts); ?>
                <?php $routeSelect('prompt_configuration_id', 'Dialogue Prompt', '&#x1F4AC;', 'Prompt template inherited by NPCs using this profile.', $prompts); ?>
                <?php $routeSelect('llm_fallback_configuration_id', 'Fallback LLM', '&#x1F504;', 'Backup connector used when primary requests fail.', $llm); ?>
                <?php $routeSelect('oghma_configuration_id', 'Oghma Extractor', '&#x1F4DA;', 'Fallback connector used only when local catalog grounding cannot resolve an explicit lore request.', $llm); ?>
                <?php $routeSelect('profile_generation_configuration_id', 'Profile Generation LLM', '&#x1F58B;&#xFE0F;', 'Connector for requested NPC and narrator profile generation and player speech-style analysis.', $llm, 'Use server runtime', 'Applies to newly queued generation jobs; already queued jobs keep their selected connector revision. Saving never calls a provider.'); ?>
                <?php $routeSelect('relationship_configuration_id', 'Relationship LLM', '&#x1F91D;', 'Connector for relationship updates after fully played conversations.', $llm, 'Disabled', 'No connector means no evaluation. Saving never calls a provider.'); ?>
                <?php $routeSelect('diary_generation_configuration_id', 'Diary LLM', '&#x1F4D3;', 'Connector for diary generation that a person explicitly requests from Narratives.', $llm, 'Disabled', 'Disabled refuses manual diary requests. Applies to newly queued diary jobs; already queued jobs keep their selected connector revision. Saving never calls a provider.'); ?>
            </div>
        </section>
    </div>
</div>

<div class="connector-card profile-settings-card">
    <div class="connector-title">Profile Settings</div>
    <div class="profile-settings-columns">
        <section class="profile-settings-group"><h3 class="profile-settings-heading">Rechat</h3><div class="provider-card">
            <?php $selectSetting('setting_behavior_rechat', 'Rechat', 'Continue a player-started conversation after its speech queue completes.', $overrides['behavior']['rechat'] ?? null); ?>
            <?php $numberField('behavior', 'rechat_max_depth', 'Rechat Rounds', 'Maximum inherited NPC-to-NPC continuation rounds.', 1, 20); ?>
            <?php $numberField('behavior', 'rechat_probability_percent', 'Rechat Probability', 'Inherited chance, from 0 to 100, that the conversation continues.', 0, 100); ?>
            <div class="setting-row"><div><label class="setting-key" for="profile-rechat-mode">Rechat Mode</label><div class="setting-desc">Choose how the next speaker is selected. Blank inherits the installation setting.</div></div><div class="setting-control"><select id="profile-rechat-mode" name="setting_behavior_rechat_mode"><option value="">Inherit</option><?php foreach (['tight' => 'Tight', 'conversational' => 'Conversational', 'group' => 'Group', 'random' => 'Random'] as $value => $label): ?><option value="<?php echo $value; ?>"<?php echo ($overrides['behavior']['rechat_mode'] ?? '') === $value ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div></div>
            <?php $selectSetting('setting_behavior_rechat_strict_targeting', 'Strict Targeting', 'Require the selected responder to address the previous speaker.', $overrides['behavior']['rechat_strict_targeting'] ?? null); ?>
            <?php $selectSetting('setting_behavior_open_rechat', 'Open Rechat', 'Allow nearby scene participants to enter the conversation.', $overrides['behavior']['open_rechat'] ?? null); ?>
            <?php $numberField('behavior', 'end_conversation_cooldown_seconds', 'End Cooldown', 'Seconds before an NPC can enter another rechat chain.', 0, 300); ?>
        </div></section>
        <section class="profile-settings-group"><h3 class="profile-settings-heading">Context &amp; Knowledge</h3><div class="provider-card">
            <?php $numberField('memory', 'recent_turn_limit', 'Recent Turns', 'Maximum recent conversation turns in context.', 1, 100); ?>
            <div class="setting-row"><div><div class="setting-key">Oghma Knowledge Tags</div><div class="setting-desc">Comma-separated access classes inherited from Global Settings unless overridden here.</div></div><div class="setting-control"><input name="setting_memory_oghma_knowledge_tags" aria-label="Oghma Knowledge Tags" maxlength="4096" value="<?php echo lorkhan_ui_h($overrides['memory']['oghma_knowledge_tags'] ?? ''); ?>" placeholder="Inherit"></div></div>
            <?php $selectSetting('setting_oghma_enabled', 'Oghma Enabled', 'Override catalog grounding and prompt injection for this profile.', $overrides['oghma']['enabled'] ?? null); ?>
            <?php $numberField('oghma', 'topic_count', 'Oghma Topics', 'Maximum conversational topics extracted.', 1, 3); ?>
            <?php $numberField('oghma', 'result_limit', 'Oghma Results', 'Maximum Oghma articles or denials injected.', 1, 5); ?>
            <?php $selectSetting('setting_oghma_racial_context_enabled', 'Racial Context', 'Override race-topic injection.', $overrides['oghma']['racial_context_enabled'] ?? null); ?>
            <?php $selectSetting('setting_oghma_location_context_enabled', 'Location Context', 'Override location-topic injection.', $overrides['oghma']['location_context_enabled'] ?? null); ?>
            <?php $selectSetting('setting_oghma_extractor_fallback_enabled', 'Extractor Fallback', 'Override the one-call connector fallback.', $overrides['oghma']['extractor_fallback_enabled'] ?? null); ?>
            <?php $numberField('oghma', 'extractor_timeout_ms', 'Extractor Timeout', 'Connector fallback timeout in milliseconds.', 250, 3000); ?>
        </div></section>
        <section class="profile-settings-group profile-diary-settings"><h3 class="profile-settings-heading">Diary</h3><div class="provider-card">
            <?php $numberField('diary', 'context_turn_limit', 'Diary Context Turns', 'Maximum witnessed turns frozen into one manual diary request, from 1 to 100. Blank uses the server default of 20.', 1, 100); ?>
            <div class="setting-row profile-setting-stacked">
                <div><label class="setting-key" for="profile-diary-prompt">Diary Instruction</label><div class="setting-desc">Profile-specific instruction sent with a manual diary request. Blank inherits the server default instruction.</div></div>
                <div class="setting-control"><textarea id="profile-diary-prompt" name="setting_diary_prompt" rows="3" maxlength="8192" placeholder="Inherit" aria-describedby="profile-diary-prompt-help"><?php echo lorkhan_ui_h($overrides['diary']['prompt'] ?? ''); ?></textarea></div>
            </div>
            <p class="setting-desc" id="profile-diary-prompt-help">Turn Manual Diary Generation on and choose a Diary LLM before requesting a diary from Narratives. Saving this page never calls a provider.</p>
        </div></section>
        <section class="profile-settings-group profile-relationship-settings"><h3 class="profile-settings-heading">Relationships</h3><div class="provider-card">
            <?php $numberField('relationship', 'update_chance_percent', 'Relationship Update Chance', 'Default 0: no automatic evaluation. 100: every eligible played response. Saved relationships still appear in prompts.', 0, 100); ?>
            <div class="setting-row"><div><label class="setting-key" for="relationship-lock">Relationship Lock</label><div class="setting-desc">Stop relationship evaluation. Separate from the NPC profile lock.</div></div><div class="setting-control"><select id="relationship-lock" name="setting_relationship_locked"><?php foreach (['inherit'=>'Inherit (unlocked)', '1'=>'Locked', '0'=>'Unlocked'] as $value=>$label): ?><option value="<?php echo $value; ?>"<?php echo ($overrides['relationship']['locked'] ?? null) === ($value === 'inherit' ? null : (string)$value === '1') ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($label); ?></option><?php endforeach; ?></select></div></div>
        </div></section>
    </div>
</div>
