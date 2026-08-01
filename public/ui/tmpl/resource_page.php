<?php

declare(strict_types=1);

if (!isset($uiRootDir, $view, $pageTitle, $topNavSection)) throw new RuntimeException('Incomplete management page definition.');
require $uiRootDir . '/ui_bootstrap.php';
$rows = $uiRepository->rows($view);

$descriptions = [
    'characters' => 'Create and inspect TES3/OpenMW character identities.',
    'profiles' => 'Manage versioned roleplay profiles used by ALMSIVI sessions.',
    'llm' => 'Configure server-side dialogue provider presets. Stored secrets remain redacted.',
    'tts' => 'Configure server-side speech provider presets. Stored secrets remain redacted.',
    'stt' => 'Configure server-side speech-to-text provider presets. Stored secrets remain redacted.',
    'global_settings' => 'Inspect registered ALMSIVI installations and current runtime activity.',
    'worldknowledge' => 'Create and inspect scoped Morrowind world knowledge.',
    'actions' => 'Inspect the negotiated OpenMW action catalog and create action-policy revisions.',
    'prompts' => 'Create and inspect versioned dialogue prompt configurations.',
    'autonomy' => 'Configure narrator records and bounded autonomous schedules.',
    'playthroughs' => 'Create and inspect playthrough state bound to a profile.',
    'request_logs' => 'Inspect bounded source-event and request traces.',
    'relationship_logs' => 'Inspect current relationship state and its latest updates.',
    'jobs' => 'Inspect durable worker jobs, retries, and dead-letter state.',
    'provider_attempts' => 'Inspect redacted LLM, TTS, and STT provider attempts.',
    'backup_health' => 'Inspect backup records and run bounded retention maintenance.',
    'diagnostics' => 'Inspect redacted operational audit records.',
];

$forms = match ($view) {
    'characters' => [[
        'route' => 'profiles', 'legend' => 'Create TES3 character',
        'fields' => [['installation_id', 'Installation ID'], ['name', 'Character name'], ['content_json', 'Character profile JSON', 'textarea', '{}']],
    ]],
    'profiles' => [[
        'route' => 'profiles', 'legend' => 'Create profile',
        'fields' => [['installation_id', 'Installation ID'], ['name', 'Profile name'], ['content_json', 'Profile JSON', 'textarea', '{}']],
    ]],
    'llm', 'tts', 'stt' => [[
        'route' => 'providers', 'legend' => 'Create ' . strtoupper($view) . ' connector',
        'fields' => [['installation_id', 'Installation ID'], ['name', 'Connector name'], ['content_json', 'Connector JSON', 'textarea', '{"kind":"' . $view . '","driver":"mock"}']],
    ]],
    'worldknowledge' => [[
        'route' => 'knowledge', 'legend' => 'Add world knowledge',
        'fields' => [['installation_id', 'Installation ID'], ['profile_id', 'Profile ID'], ['playthrough_id', 'Playthrough ID'], ['title', 'Title'], ['content', 'Knowledge', 'textarea'], ['provenance', 'Provenance source', 'text', 'management']],
    ]],
    'actions' => [[
        'route' => 'action-policies', 'legend' => 'Create action policy',
        'fields' => [['installation_id', 'Installation ID'], ['name', 'Policy name'], ['content_json', 'Policy JSON', 'textarea', '{}']],
    ]],
    'prompts' => [[
        'route' => 'prompts', 'legend' => 'Create prompt',
        'fields' => [['installation_id', 'Installation ID'], ['name', 'Prompt name'], ['content_json', 'Prompt JSON', 'textarea', '{}']],
    ]],
    'autonomy' => [
        [
            'route' => 'narratives', 'legend' => 'Create narrative',
            'fields' => [['installation_id', 'Installation ID'], ['profile_id', 'Profile ID'], ['playthrough_id', 'Playthrough ID'], ['kind', 'Narrative kind', 'select', 'narrator', ['narrator', 'diary', 'summary']], ['title', 'Title'], ['content', 'Narrative', 'textarea'], ['provenance', 'Provenance source', 'text', 'management']],
        ],
        [
            'route' => 'autonomy', 'legend' => 'Save autonomy schedule',
            'fields' => [['installation_id', 'Installation ID'], ['profile_id', 'Profile ID'], ['playthrough_id', 'Playthrough ID'], ['kind', 'Schedule kind', 'select', 'rechat', ['rechat', 'boredom', 'greeting']], ['interval_seconds', 'Interval seconds', 'number', '30'], ['cooldown_seconds', 'Cooldown seconds', 'number', '30'], ['current_session_id', 'Current session ID'], ['enabled', 'Enabled after current-session confirmation', 'checkbox', '1']],
        ],
    ],
    'playthroughs' => [[
        'route' => 'playthroughs', 'legend' => 'Create playthrough',
        'fields' => [['installation_id', 'Installation ID'], ['profile_id', 'Profile ID'], ['name', 'Playthrough name'], ['content_json', 'Playthrough JSON', 'textarea', '{}']],
    ]],
    'backup_health' => [[
        'route' => 'retention', 'legend' => 'Run bounded retention',
        'fields' => [['days', 'Retention days', 'number', '30']],
    ]],
    default => [],
};

/** Render one CSRF-protected management form with explicit labels for every control. */
function almsivi_ui_management_form(array $form, string $managementBasePath, string $csrf): void
{
    echo '<form class="management-form" method="post" action="' . almsivi_ui_h($managementBasePath . '/forms/' . $form['route']) . '"><fieldset><legend>' . almsivi_ui_h($form['legend']) . '</legend>';
    foreach ($form['fields'] as $field) {
        [$name, $label] = $field;
        $type = $field[2] ?? 'text';
        $value = $field[3] ?? '';
        $id = 'field-' . $form['route'] . '-' . $name;
        if ($type === 'checkbox') {
            echo '<label><input name="' . almsivi_ui_h($name) . '" type="checkbox" value="' . almsivi_ui_h($value) . '"> ' . almsivi_ui_h($label) . '</label>';
            continue;
        }
        echo '<label for="' . almsivi_ui_h($id) . '">' . almsivi_ui_h($label) . '</label>';
        if ($type === 'textarea') {
            echo '<textarea id="' . almsivi_ui_h($id) . '" name="' . almsivi_ui_h($name) . '" required>' . almsivi_ui_h($value) . '</textarea>';
        } elseif ($type === 'select') {
            echo '<select id="' . almsivi_ui_h($id) . '" name="' . almsivi_ui_h($name) . '">';
            foreach (($field[4] ?? []) as $option) echo '<option value="' . almsivi_ui_h($option) . '">' . almsivi_ui_h(ucwords($option)) . '</option>';
            echo '</select>';
        } else {
            echo '<input id="' . almsivi_ui_h($id) . '" name="' . almsivi_ui_h($name) . '" type="' . almsivi_ui_h($type) . '" value="' . almsivi_ui_h($value) . '" required>';
        }
    }
    echo '</fieldset><input type="hidden" name="_csrf" value="' . almsivi_ui_h($csrf) . '"><button class="btn-base btn-primary" type="submit">' . almsivi_ui_h($form['legend']) . '</button></form>';
}

include $uiRootDir . '/tmpl/head.html';
if (!$embedded) include $uiRootDir . '/tmpl/navbar.php';
?>
<main class="management-page">
    <h1><?php echo almsivi_ui_h($pageTitle); ?></h1>
    <p><?php echo almsivi_ui_h($descriptions[$view] ?? 'ALMSIVIserver management page.'); ?></p>
    <?php if (isset($_GET['status']) && $_GET['status'] === 'saved'): ?><p class="page-status" role="status">Changes saved.</p><?php endif; ?>
    <?php if (isset($_GET['error'])): ?><p class="page-error" role="alert"><?php echo almsivi_ui_h($_GET['error']); ?></p><?php endif; ?>
    <?php foreach ($forms as $form) almsivi_ui_management_form($form, $managementBasePath, $csrf); ?>
    <section class="widget widget-wide">
        <div class="widget-header"><h3>Current Records</h3></div>
        <div class="widget-content"><?php almsivi_ui_table($rows); ?></div>
    </section>
</main>
<?php include $uiRootDir . '/tmpl/footer.html'; ?>
