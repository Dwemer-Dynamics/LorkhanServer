<?php

declare(strict_types=1);

$pageTitle = 'Configuration';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page';
require dirname(__DIR__) . '/ui_bootstrap.php';

$configSections = [
    'characters' => [
        'label' => 'Characters',
        'tabs' => [
            'npc' => ['ALMSIVI NPCs', '&#x1F31F;', 'config.npc', $webRoot . '/ui/core/npc_master.php?embed=1'],
            'profiles' => ['Profiles', '&#x1F5C3;&#xFE0F;', 'config.profiles', $webRoot . '/ui/core/core_profiles.php?embed=1'],
            'player' => ['Player', '&#x1F464;', 'config.player', $webRoot . '/ui/core/player_management.php?embed=1'],
            'narrator' => ['Narration', '&#x1F5E3;&#xFE0F;', 'config.narrator', $webRoot . '/ui/narrator_management.php?embed=1'],
            'npcbio' => ['NPC Biographies', '&#x1F6AA;', 'config.biographies', $webRoot . '/ui/core/npc_biographies.php?embed=1'],
        ],
    ],
    'ai-voice' => [
        'label' => 'AI & Voice',
        'tabs' => [
            'llm' => ['LLM', '&#x1F9E0;', 'config.llm', $webRoot . '/ui/core/llm_connectors.php?embed=1'],
            'ttscfg' => ['TTS', '&#x1F50A;', 'config.tts', $webRoot . '/ui/core/tts_connectors.php?embed=1'],
            'xtts' => ['TTS Studio', '&#x1F4E2;', 'config.tts-studio', $webRoot . '/ui/core/voice_library.php?embed=1'],
            'sttcfg' => ['STT', '&#x1F3A4;', 'config.stt', $webRoot . '/ui/core/stt_connectors.php?embed=1'],
            'keys' => ['API Keys', '&#x1F511;', 'config.keys', $webRoot . '/ui/core/api_keys.php?embed=1'],
        ],
    ],
    'world-behavior' => [
        'label' => 'World & Behavior',
        'tabs' => [
            'globals' => ['Global Settings', '&#x1F310;', 'config.globals', $webRoot . '/ui/global_settings.php?embed=1'],
            'oghma' => ['Oghma Infinium', '&#x1F4DC;', 'config.oghma', $webRoot . '/ui/worldknowledge_upload.php?embed=1'],
            'items' => ['Descriptions', '&#x1F4D6;', 'config.descriptions', $webRoot . '/ui/description_manager.php?embed=1'],
            'actions' => ['Action Editor', '&#x2694;&#xFE0F;', 'config.actions', $webRoot . '/ui/function_editor.php?embed=1'],
            'prompts' => ['Prompts Manager', '&#x1F4AC;', 'config.prompts', $webRoot . '/ui/prompts_manager.php?embed=1'],
            'serverplugins' => ['Server Plugins', '&#x1F9E9;', 'config.plugins', $webRoot . '/ui/server_plugins.php?embed=1'],
        ],
    ],
];

$aliases = [
    'npc-page' => 'npc', 'profiles-page' => 'profiles', 'player-page' => 'player', 'narration-page' => 'narrator', 'npcbio-page' => 'npcbio',
    'llm-page' => 'llm', 'tts-page' => 'ttscfg', 'studio-page' => 'xtts', 'stt-page' => 'sttcfg', 'keys-page' => 'keys',
    'globals-page' => 'globals', 'knowledge-page' => 'oghma', 'items-page' => 'items', 'actions-page' => 'actions', 'prompts-page' => 'prompts', 'plugins-page' => 'serverplugins',
];
$requested = (string) ($_GET['tab'] ?? 'npc');
$requested = $aliases[$requested] ?? $requested;
$allTabs = [];
foreach ($configSections as $section) foreach ($section['tabs'] as $id => $tab) $allTabs[$id] = $tab;
$active = array_key_exists($requested, $allTabs) ? $requested : 'npc';

$additionalStylesheets = ['herika-hubs.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-hubs.css')];
$includeManagementStyles = false;
include dirname(__DIR__) . '/tmpl/head.html';
include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<link rel="stylesheet" href="<?php echo almsivi_ui_h($webRoot); ?>/ui/css/main.css">
<link rel="stylesheet" href="<?php echo almsivi_ui_h($webRoot); ?>/ui/css/hub-navigation.css?v=<?php echo almsivi_ui_h((string) filemtime(dirname(__DIR__) . '/css/hub-navigation.css')); ?>">
<main class="d-flex flex-column herika-hub-page" data-config-hub data-active-tab="<?php echo almsivi_ui_h($active); ?>">
    <div id="toast" class="toast-notification" role="status" aria-live="polite"><span class="message"></span></div>
    <div class="top-area">
        <div class="config-navigation" aria-label="Configuration sections">
            <div class="tab-groups">
                <?php foreach ($configSections as $sectionId => $section): $sectionActive = array_key_exists($active, $section['tabs']); ?>
                <section class="tab-group<?php echo $sectionActive ? ' active' : ''; ?>" data-category="<?php echo almsivi_ui_h($sectionId); ?>">
                    <div class="tab-group-label"><?php echo almsivi_ui_h($section['label']); ?></div>
                    <div class="tab-buttons" role="tablist" aria-label="<?php echo almsivi_ui_h($section['label']); ?> configuration pages">
                        <?php foreach ($section['tabs'] as $tabId => [$label, $icon, $featureId]): $feature = almsivi_ui_feature($featureId); ?>
                        <button class="tab-button<?php echo $tabId === $active ? ' active' : ''; ?>" type="button" data-tab="<?php echo almsivi_ui_h($tabId); ?>" data-category="<?php echo almsivi_ui_h($sectionId); ?>" aria-selected="<?php echo $tabId === $active ? 'true' : 'false'; ?>">
                            <span class="tab-icon" aria-hidden="true"><?php echo $icon; ?></span><?php if ($feature['state'] === 'live'): ?><span class="tab-label"><?php echo almsivi_ui_h($label); ?></span><?php else: ?><span class="tab-label-stack"><span class="tab-label"><?php echo almsivi_ui_h($label); ?></span><?php echo almsivi_ui_feature_badge($featureId, true); ?></span><?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="content-area flex-grow-1 d-flex overflow-hidden">
        <?php foreach ($allTabs as $tabId => [$label, $icon, $featureId, $src]): ?>
        <div id="<?php echo almsivi_ui_h($tabId); ?>" class="tab-content<?php echo $tabId === $active ? ' active' : ''; ?>" data-tab-panel>
            <div class="embed-wrap"><iframe class="embed" title="<?php echo almsivi_ui_h($label); ?>" loading="<?php echo $tabId === $active ? 'eager' : 'lazy'; ?>" src="<?php echo $tabId === $active ? almsivi_ui_h($src) : 'about:blank'; ?>" data-src="<?php echo almsivi_ui_h($src); ?>"></iframe></div>
        </div>
        <?php endforeach; ?>
    </div>
</main>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
