<?php

declare(strict_types=1);

use ALMSIVIserver\Application\EffectiveSettingsResolver;

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Global Settings';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page global-settings-page-shell' . ($embedded ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$requested = trim((string) ($_GET['installation_id'] ?? ''));
$installationId = '';
foreach ($installations as $row) if ($requested !== '' && hash_equals((string) $row['installation_id'], $requested)) $installationId = $requested;
if ($installationId === '' && isset($installations[0])) $installationId = (string) $installations[0]['installation_id'];
$stored = $installationId === '' ? null : $productRepository->globalSettingsForInstallation($installationId);
$settings = is_array($stored['content'] ?? null) ? $stored['content'] : EffectiveSettingsResolver::defaults();

$sections = [
    'prompt-rechat' => [
        'Prompt & Rechat' => [
            ['auto_greeting', 'Automatic Greeting', '&#x1F44B;', 'boolean', $settings['behavior']['auto_greeting'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['rechat', 'Rechat', '&#x1F501;', 'boolean', $settings['behavior']['rechat'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['rechat_delay_seconds', 'Rechat Delay', '&#x23F1;&#xFE0F;', 'integer', $settings['behavior']['rechat_delay_seconds'], 'Automatic rechat scheduling is excluded from this build.', ['min' => 30, 'max' => 3600, 'feature' => 'autonomy']],
            ['rechat_max_depth', 'Maximum Rechat Depth', '&#x1F4AC;', 'integer', $settings['behavior']['rechat_max_depth'], 'Automatic rechat scheduling is excluded from this build.', ['min' => 1, 'max' => 20, 'feature' => 'autonomy']],
            ['boredom', 'Boredom Events', '&#x1F4AD;', 'boolean', $settings['behavior']['boredom'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['boredom_delay_seconds', 'Boredom Delay', '&#x23F3;', 'integer', $settings['behavior']['boredom_delay_seconds'], 'Automatic boredom scheduling is excluded from this build.', ['min' => 30, 'max' => 86400, 'feature' => 'autonomy']],
            ['combat_barks', 'Combat Barks', '&#x2694;&#xFE0F;', 'boolean', $settings['behavior']['combat_barks'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['combat_bark_period_seconds', 'Combat Bark Period', '&#x1F6E1;&#xFE0F;', 'integer', $settings['behavior']['combat_bark_period_seconds'], 'Automatic combat-bark scheduling is excluded from this build.', ['min' => 5, 'max' => 300, 'feature' => 'autonomy']],
        ],
    ],
    'ai-memory' => [
        'Memory & Others' => [
            ['recent_turn_limit', 'Recent Turns', '&#x1F9E0;', 'integer', $settings['memory']['recent_turn_limit'], 'Recent dialogue turns included in bounded context.', ['min' => 1, 'max' => 100]],
            ['knowledge_limit', 'Knowledge Results', '&#x1F4DA;', 'integer', $settings['memory']['knowledge_limit'], 'Maximum scoped Oghma results included in one request.', ['min' => 0, 'max' => 20]],
            ['show_status_hud', 'Show Status HUD', '&#x1F5A5;&#xFE0F;', 'boolean', $settings['presentation']['show_status_hud'], 'Managed locally by the OpenMW client.', ['feature' => 'presentation.local']],
            ['transcript_rows', 'Transcript Rows', '&#x1F4DC;', 'integer', $settings['presentation']['transcript_rows'], 'Managed locally by the OpenMW client.', ['min' => 2, 'max' => 20, 'feature' => 'presentation.local']],
            ['tts_volume_boost', 'ALMSIVI TTS Volume Boost', '&#x1F50A;', 'integer', $settings['presentation']['tts_volume_boost'], 'Managed locally by the OpenMW client.', ['min' => 1, 'max' => 4, 'feature' => 'presentation.local']],
        ],
    ],
    'context-knowledge' => [
        'Context & Knowledge' => [
            ['narrator_enabled', 'Enable Narrator', '&#x1F5E3;&#xFE0F;', 'boolean', $settings['narrator']['enabled'], 'Allows the typed narrator profile to participate in eligible events.'],
            ['narrator_name', 'Narrator Name', '&#x1F3F7;&#xFE0F;', 'text', $settings['narrator']['name'], 'Display name used for narration, prompts, subtitles, and TTS.'],
            ['narrator_inline_mode', 'Inline Mode', '&#x1F4DD;', 'select', $settings['narrator']['inline_mode'], 'Controls how inline narration is delivered.', ['values' => ['Disabled', 'Narrator', 'NPC', 'Text Only']]],
            ['narrator_context_visibility', 'Include Narrator Context', '&#x1F441;&#xFE0F;', 'boolean', $settings['narrator']['context_visibility'], 'Includes narrator context in eligible roleplay requests.'],
            ['narrator_welcome_events', 'Welcome Events', '&#x1F44B;', 'boolean', $settings['narrator']['welcome_events'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['narrator_random_events', 'Random Events', '&#x1F3B2;', 'boolean', $settings['narrator']['random_events'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['narrator_quest_events', 'Quest Events', '&#x1F5FA;&#xFE0F;', 'boolean', $settings['narrator']['quest_events'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['narrator_book_events', 'Book Events', '&#x1F4D6;', 'boolean', $settings['narrator']['book_events'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
        ],
    ],
    'global-connectors' => [
        'Safety & OpenMW Actions' => [
            ['actions_enabled', 'Enable Negotiated Actions', '&#x2694;&#xFE0F;', 'boolean', $settings['safety']['actions_enabled'], 'Allows only actions advertised by the connected OpenMW client.'],
            ['allow_hostile', 'Allow Hostile NPC Targets', '&#x1F6E1;&#xFE0F;', 'boolean', $settings['safety']['allow_hostile'], 'Allows hostile actors to be selected when the client also permits them.'],
            ['allow_creatures', 'Allow Creature Targets', '&#x1F43E;', 'boolean', $settings['safety']['allow_creatures'], 'Allows creatures to be selected when the client also permits them.'],
        ],
    ],
];

$additionalStylesheets = ['herika-global-settings.css?v=' . (string) filemtime(__DIR__ . '/css/herika-global-settings.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="global-settings-page">
    <header class="page-header">
        <div class="page-header-row">
            <h1 class="gs-title">Global Settings</h1>
            <div class="page-header-actions">
                <span class="status-control"><button type="button" class="btn-action-blue" disabled aria-disabled="true">Test Global Connectors</button><?php echo almsivi_ui_feature_badge('config.globals.connector-test', true); ?></span>
                <button type="submit" class="btn-save-green" name="save_all" value="1" form="gs_form">Save All</button>
            </div>
        </div>
    </header>

    <?php if (isset($_GET['status'])): ?><div class="result-ok">Global settings saved to the database.</div><?php endif; ?>
    <?php if (count($installations) > 1): ?><div class="installation-row"><label>Installation <select data-installation-select><?php foreach ($installations as $row): ?><option value="<?php echo almsivi_ui_h($row['installation_id']); ?>"<?php echo $row['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo almsivi_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label></div><?php endif; ?>

    <nav class="settings-tabs" role="tablist" aria-label="Global settings categories">
        <?php foreach (['prompt-rechat' => '&#x1F4AC; Prompt & Rechat', 'ai-memory' => '&#x1F9E0; Memory & Others', 'context-knowledge' => '&#x1F4DA; Context & Knowledge', 'global-connectors' => '&#x1F50C; Global Connectors'] as $tabId => $tabLabel): ?>
        <button type="button" class="settings-tab<?php echo $tabId === 'prompt-rechat' ? ' is-active' : ''; ?>" id="settings-tab-<?php echo almsivi_ui_h($tabId); ?>" role="tab" aria-selected="<?php echo $tabId === 'prompt-rechat' ? 'true' : 'false'; ?>" data-settings-tab="<?php echo almsivi_ui_h($tabId); ?>"><?php echo $tabLabel; ?></button>
        <?php endforeach; ?>
    </nav>

    <?php if ($installations === []): ?><section class="content-section">Connect OpenMW once before configuring installation settings.</section><?php else: ?>
    <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/global-settings-save" id="gs_form">
        <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
        <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>">
        <input type="hidden" name="change_reason" value="Management global settings">
        <div class="content-grid">
            <?php foreach ($sections as $tabId => $tabSections): foreach ($tabSections as $sectionTitle => $fields): ?>
            <section class="content-section<?php echo $tabId === 'global-connectors' ? ' connector-section' : ''; ?>" role="tabpanel" aria-labelledby="settings-tab-<?php echo almsivi_ui_h($tabId); ?>" data-settings-panel="<?php echo almsivi_ui_h($tabId); ?>"<?php echo $tabId === 'prompt-rechat' ? '' : ' hidden'; ?>>
                <h2><?php echo almsivi_ui_h($sectionTitle); ?></h2>
                <?php if ($tabId === 'global-connectors'): ?>
                <div class="provider-grid connector-placeholder-grid">
                    <?php foreach ([['Global LLM Connector', '&#x1F9E0;', 'Inherited through Core Profile routing'], ['Global TTS Connector', '&#x1F50A;', 'Inherited through Core Profile routing']] as [$label, $icon, $help]): ?>
                    <div class="provider-card" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.globals.connectors')['description']); ?>"><div class="provider-head"><div class="provider-title"><span class="provider-icon"><?php echo $icon; ?></span><span><?php echo almsivi_ui_h($label); ?></span><?php echo almsivi_ui_feature_badge('config.globals.connectors', true); ?></div></div><div class="provider-body"><select disabled aria-disabled="true"><option><?php echo almsivi_ui_h($help); ?></option></select></div><div class="provider-help">Connector routing is selected by the Global default Core Profile and then inherited by NPCs.</div></div>
                    <?php endforeach; ?>
                </div>
                <h2 class="subsection-heading">Safety &amp; OpenMW Actions</h2>
                <?php endif; ?>
                <div class="provider-grid">
                    <?php if ($tabId === 'prompt-rechat'): ?>
                    <?php foreach ([
                        ['Prompt Head', '&#x1F51D;', 'textarea', 'System Prompt. Defines the rules of the roleplay.', 'config.globals.prompt-head', []],
                        ['Emote Moods', '&#x1F3AD;', 'textarea', 'Default list of moods passed to the LLM. Core Profiles and NPCs can provide explicit profile text.', 'config.globals.emote-moods', []],
                        ['Rechat Mode', '&#x1F501;', 'select', 'Controls which participant is preferred for the next rechat turn.', 'config.globals.rechat-mode', ['Tight', 'Conversational', 'Group', 'Random (Recommended)']],
                        ['Strict Rechat Targeting', '&#x1F3AF;', 'boolean', 'Requires each rechat responder to address the previous speaker directly.', 'config.globals.strict-rechat', []],
                    ] as [$label, $icon, $placeholderType, $help, $placeholderFeature, $placeholderOptions]): ?>
                    <div class="provider-card" title="<?php echo almsivi_ui_h(almsivi_ui_feature($placeholderFeature)['description']); ?>">
                        <div class="provider-head"><div class="provider-title"><span class="provider-icon"><?php echo $icon; ?></span><span><?php echo almsivi_ui_h($label); ?></span><?php echo almsivi_ui_feature_badge($placeholderFeature, true); ?><?php if ($placeholderType === 'boolean'): ?><span class="provider-toggle"><input type="checkbox" disabled aria-disabled="true" aria-label="<?php echo almsivi_ui_h($label); ?>"></span><?php endif; ?></div></div>
                        <div class="provider-body"><?php if ($placeholderType === 'textarea'): ?><textarea rows="4" disabled aria-disabled="true"></textarea><?php elseif ($placeholderType === 'select'): ?><select disabled aria-disabled="true"><?php foreach ($placeholderOptions as $option): ?><option<?php echo str_starts_with($option, 'Random') ? ' selected' : ''; ?>><?php echo almsivi_ui_h($option); ?></option><?php endforeach; ?></select><?php endif; ?></div>
                        <div class="provider-help"><?php echo almsivi_ui_h($help); ?></div>
                    </div>
                    <?php endforeach; ?>
                    <div class="provider-subsection-title">ALMSIVI Conversation Timing</div>
                    <?php endif; ?>
                    <?php foreach ($fields as $field): [$name, $label, $icon, $type, $value, $help] = $field; $options = $field[6] ?? []; $placeholderFeature = (string) ($options['feature'] ?? ''); $disabled = $placeholderFeature !== ''; ?>
                    <div class="provider-card"<?php if ($disabled): ?> title="<?php echo almsivi_ui_h(almsivi_ui_feature($placeholderFeature)['description']); ?>"<?php endif; ?>>
                        <?php if ($disabled && ($type !== 'boolean' || $value)): ?><input type="hidden" name="<?php echo almsivi_ui_h($name); ?>" value="<?php echo $type === 'boolean' ? '1' : almsivi_ui_h($value); ?>"><?php endif; ?>
                        <div class="provider-head"><div class="provider-title"><span class="provider-icon"><?php echo $icon; ?></span><span><?php echo almsivi_ui_h($label); ?></span><?php if ($disabled) echo almsivi_ui_feature_badge($placeholderFeature, true); ?><?php if ($type === 'boolean'): ?><span class="provider-toggle"><input type="checkbox" name="<?php echo almsivi_ui_h($name); ?>" value="1"<?php if ($disabled): ?> disabled aria-disabled="true"<?php endif; ?><?php echo $value ? ' checked' : ''; ?> aria-label="<?php echo almsivi_ui_h($label); ?>"></span><?php endif; ?></div></div>
                        <div class="provider-body">
                            <?php if ($type === 'integer'): ?><input type="number" name="<?php echo almsivi_ui_h($name); ?>"<?php if ($disabled): ?> disabled aria-disabled="true"<?php endif; ?> value="<?php echo almsivi_ui_h($value); ?>" min="<?php echo almsivi_ui_h($options['min']); ?>" max="<?php echo almsivi_ui_h($options['max']); ?>" step="1" aria-label="<?php echo almsivi_ui_h($label); ?>">
                            <?php elseif ($type === 'select'): ?><select name="<?php echo almsivi_ui_h($name); ?>"<?php if ($disabled): ?> disabled aria-disabled="true"<?php endif; ?> aria-label="<?php echo almsivi_ui_h($label); ?>"><?php foreach ($options['values'] as $option): ?><option<?php echo $option === $value ? ' selected' : ''; ?>><?php echo almsivi_ui_h($option); ?></option><?php endforeach; ?></select>
                            <?php elseif ($type === 'text'): ?><input type="text" name="<?php echo almsivi_ui_h($name); ?>"<?php if ($disabled): ?> disabled aria-disabled="true"<?php endif; ?> value="<?php echo almsivi_ui_h($value); ?>" maxlength="128" aria-label="<?php echo almsivi_ui_h($label); ?>">
                            <?php endif; ?>
                        </div>
                        <div class="provider-help"><?php echo almsivi_ui_h($help); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; endforeach; ?>
        </div>
    </form>
    <?php endif; ?>
</main>
<script defer src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/global-settings.js?v=<?php echo almsivi_ui_h((string) filemtime(__DIR__ . '/js/global-settings.js')); ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
