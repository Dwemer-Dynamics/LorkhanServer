<?php

declare(strict_types=1);

$pageTitle = 'Configuration';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page';
require dirname(__DIR__) . '/ui_bootstrap.php';

// Grouping and order follow the current HerikaServer Configuration hub.
$configSections = [
    'settings' => [
        'label' => 'Settings',
        'tabs' => [
            'globals' => ['Global Settings', '&#x1F310;', 'config.globals'],
            'profiles' => ['Profiles', '&#x1F5C3;&#xFE0F;', 'config.profiles'],
            'npc' => ['LORKHAN NPCs', '&#x1F31F;', 'config.npc'],
            'player' => ['Player', '&#x1F464;', 'config.player'],
            'narrator' => ['Narration', '&#x1F5E3;&#xFE0F;', 'config.narrator'],
        ],
    ],
    'ai-voice' => [
        'label' => 'AI & Voice',
        'tabs' => [
            'llm' => ['LLM', '&#x1F9E0;', 'config.llm'],
            'ttscfg' => ['TTS', '&#x1F50A;', 'config.tts'],
            'xtts' => ['TTS Studio', '&#x1F4E2;', 'config.tts-studio'],
            'sttcfg' => ['STT', '&#x1F3A4;', 'config.stt'],
            'keys' => ['API Keys', '&#x1F511;', 'config.keys'],
        ],
    ],
    'world-behavior' => [
        'label' => 'World & Behavior',
        'tabs' => [
            'npcbio' => ['NPC Biographies', '&#x1F6AA;', 'config.biographies'],
            'oghma' => ['Oghma Infinium', '&#x1F4DC;', 'config.oghma'],
            'items' => ['Descriptions', '&#x1F4D6;', 'config.descriptions'],
            'actions' => ['Action Editor', '&#x2694;&#xFE0F;', 'config.actions'],
            'prompts' => ['Prompts Manager', '&#x1F4AC;', 'config.prompts'],
        ],
    ],
];

// Player and Narration name two regions of one shared shell rather than two
// panels. Their buttons only swap which region is visible, so neither embedded
// form is recreated and unsaved values survive a switch between them. Every
// other tab owns its panel outright.
$sharedShell = 'player_narration';
$tabFrames = [
    'globals' => ['panel' => 'globals', 'src' => $webRoot . '/ui/global_settings.php?embed=1'],
    'profiles' => ['panel' => 'profiles', 'src' => $webRoot . '/ui/core/core_profiles.php?embed=1'],
    'npc' => ['panel' => 'npc', 'src' => $webRoot . '/ui/core/npc_master.php?embed=1'],
    'player' => ['panel' => $sharedShell, 'region' => 'player_narration_player_panel', 'src' => $webRoot . '/ui/core/player_management.php?embed=1'],
    'narrator' => ['panel' => $sharedShell, 'region' => 'player_narration_narration_panel', 'src' => $webRoot . '/ui/narrator_management.php?embed=1'],
    'llm' => ['panel' => 'llm', 'src' => $webRoot . '/ui/core/llm_connectors.php?embed=1'],
    'ttscfg' => ['panel' => 'ttscfg', 'src' => $webRoot . '/ui/core/tts_connectors.php?embed=1'],
    'xtts' => ['panel' => 'xtts', 'src' => $webRoot . '/ui/core/voice_library.php?embed=1'],
    'sttcfg' => ['panel' => 'sttcfg', 'src' => $webRoot . '/ui/core/stt_connectors.php?embed=1'],
    'keys' => ['panel' => 'keys', 'src' => $webRoot . '/ui/core/api_keys.php?embed=1'],
    'npcbio' => ['panel' => 'npcbio', 'src' => $webRoot . '/ui/core/npc_biographies.php?embed=1'],
    'oghma' => ['panel' => 'oghma', 'src' => $webRoot . '/ui/worldknowledge_upload.php?embed=1'],
    'items' => ['panel' => 'items', 'src' => $webRoot . '/ui/description_manager.php?embed=1'],
    'actions' => ['panel' => 'actions', 'src' => $webRoot . '/ui/function_editor.php?embed=1'],
    'prompts' => ['panel' => 'prompts', 'src' => $webRoot . '/ui/prompts_manager.php?embed=1'],
];

$aliases = [
    'npc-page' => 'npc', 'profiles-page' => 'profiles', 'player-page' => 'player', 'narration-page' => 'narrator', 'npcbio-page' => 'npcbio',
    'llm-page' => 'llm', 'tts-page' => 'ttscfg', 'studio-page' => 'xtts', 'stt-page' => 'sttcfg', 'keys-page' => 'keys',
    'globals-page' => 'globals', 'knowledge-page' => 'oghma', 'items-page' => 'items', 'actions-page' => 'actions', 'prompts-page' => 'prompts',
    'narration' => 'narrator',
];
$requested = (string) ($_GET['tab'] ?? 'npc');
// Herika addresses the shared shell as ?tab=player_narration&section=..., LORKHAN
// as ?tab=player or ?tab=narrator. Accept both and keep the LORKHAN ids canonical.
if ($requested === 'player_narration') $requested = ((string) ($_GET['section'] ?? '')) === 'narration' ? 'narrator' : 'player';
$requested = $aliases[$requested] ?? $requested;
$allTabs = [];
foreach ($configSections as $section) foreach ($section['tabs'] as $id => $tab) $allTabs[$id] = $tab;
$active = array_key_exists($requested, $allTabs) ? $requested : 'npc';
$activePanel = $tabFrames[$active]['panel'];

// One entry per rendered panel, in tab order, holding the regions it contains.
$panelRegions = [];
foreach ($tabFrames as $tabId => $frame) $panelRegions[$frame['panel']][$tabId] = $frame;

$additionalStylesheets = ['herika-hubs.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-hubs.css')];
$includeManagementStyles = false;
include dirname(__DIR__) . '/tmpl/head.html';
include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<link rel="stylesheet" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/css/main.css">
<link rel="stylesheet" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/css/hub-navigation.css?v=<?php echo lorkhan_ui_h((string) filemtime(dirname(__DIR__) . '/css/hub-navigation.css')); ?>">
<main class="d-flex flex-column herika-hub-page" data-config-hub data-active-tab="<?php echo lorkhan_ui_h($active); ?>">
    <div id="toast" class="toast-notification" role="status" aria-live="polite"><span class="message"></span></div>
    <div class="top-area">
        <div class="config-navigation" aria-label="Configuration sections">
            <div class="tab-groups">
                <?php foreach ($configSections as $sectionId => $section): $sectionActive = array_key_exists($active, $section['tabs']); $rovingTab = $sectionActive ? $active : (string) array_key_first($section['tabs']); ?>
                <section class="tab-group<?php echo $sectionActive ? ' active' : ''; ?>" data-category="<?php echo lorkhan_ui_h($sectionId); ?>">
                    <div class="tab-group-label"><?php echo lorkhan_ui_h($section['label']); ?></div>
                    <div class="tab-buttons" role="tablist" aria-label="<?php echo lorkhan_ui_h($section['label']); ?> configuration pages">
                        <?php foreach ($section['tabs'] as $tabId => [$label, $icon, $featureId]): $feature = lorkhan_ui_feature($featureId); $frame = $tabFrames[$tabId]; ?>
                        <button class="tab-button<?php echo $tabId === $active ? ' active' : ''; ?>" type="button" id="config-tab-<?php echo lorkhan_ui_h($tabId); ?>" role="tab" data-tab="<?php echo lorkhan_ui_h($tabId); ?>" data-category="<?php echo lorkhan_ui_h($sectionId); ?>" aria-controls="<?php echo lorkhan_ui_h($frame['region'] ?? $frame['panel']); ?>" aria-selected="<?php echo $tabId === $active ? 'true' : 'false'; ?>" tabindex="<?php echo $tabId === $rovingTab ? '0' : '-1'; ?>" title="<?php echo lorkhan_ui_h($label); ?>">
                            <span class="tab-icon" aria-hidden="true"><?php echo $icon; ?></span><?php if ($feature['state'] === 'live'): ?><span class="tab-label"><?php echo lorkhan_ui_h($label); ?></span><?php else: ?><span class="tab-label-stack"><span class="tab-label"><?php echo lorkhan_ui_h($label); ?></span><?php echo lorkhan_ui_feature_badge($featureId, true); ?></span><?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="content-area flex-grow-1 d-flex overflow-hidden">
        <?php foreach ($panelRegions as $panelId => $regions): $panelActive = $panelId === $activePanel; ?>
        <?php if (count($regions) > 1): ?>
        <div id="<?php echo lorkhan_ui_h($panelId); ?>" class="tab-content<?php echo $panelActive ? ' active' : ''; ?>" data-tab-panel>
            <div class="player-narration-shell">
                <?php foreach ($regions as $tabId => $frame): $regionActive = $tabId === $active; ?>
                <div id="<?php echo lorkhan_ui_h($frame['region']); ?>" class="embed-wrap player-narration-panel" data-tab-region role="tabpanel" aria-labelledby="config-tab-<?php echo lorkhan_ui_h($tabId); ?>"<?php echo $regionActive ? '' : ' hidden'; ?>>
                    <iframe class="embed" title="<?php echo lorkhan_ui_h($allTabs[$tabId][0]); ?>" loading="<?php echo $regionActive ? 'eager' : 'lazy'; ?>" src="<?php echo $regionActive ? lorkhan_ui_h($frame['src']) : 'about:blank'; ?>" data-src="<?php echo lorkhan_ui_h($frame['src']); ?>"></iframe>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: $tabId = (string) array_key_first($regions); $frame = $regions[$tabId]; ?>
        <div id="<?php echo lorkhan_ui_h($panelId); ?>" class="tab-content<?php echo $panelActive ? ' active' : ''; ?>" data-tab-panel role="tabpanel" aria-labelledby="config-tab-<?php echo lorkhan_ui_h($tabId); ?>">
            <div class="embed-wrap"><iframe class="embed" title="<?php echo lorkhan_ui_h($allTabs[$tabId][0]); ?>" loading="<?php echo $panelActive ? 'eager' : 'lazy'; ?>" src="<?php echo $panelActive ? lorkhan_ui_h($frame['src']) : 'about:blank'; ?>" data-src="<?php echo lorkhan_ui_h($frame['src']); ?>"></iframe></div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</main>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
