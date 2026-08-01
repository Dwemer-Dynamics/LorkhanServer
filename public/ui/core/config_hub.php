<?php

declare(strict_types=1);

$pageTitle = 'ALMSIVI Configuration';
$topNavSection = 'configuration';
$bodyClass = 'hub-page';
require dirname(__DIR__) . '/ui_bootstrap.php';
$tabs = [
    'characters' => ['label' => 'Characters', 'pages' => [
        'characters-page' => ['🌟', 'ALMSIVI Characters', $webRoot . '/ui/core/character_manager.php?embed=1'],
        'profiles-page' => ['🗃️', 'Profiles', $webRoot . '/ui/core/core_profiles.php?embed=1'],
    ]],
    'ai-voice' => ['label' => 'AI & Voice', 'pages' => [
        'llm-page' => ['🧠', 'LLM', $webRoot . '/ui/core/llm_connectors.php?embed=1'],
        'tts-page' => ['🔊', 'TTS', $webRoot . '/ui/core/tts_connectors.php?embed=1'],
        'stt-page' => ['🎤', 'STT', $webRoot . '/ui/core/stt_connectors.php?embed=1'],
    ]],
    'world-behavior' => ['label' => 'World & Behavior', 'pages' => [
        'globals-page' => ['🌐', 'Global Settings', $webRoot . '/ui/core/global_settings.php?embed=1'],
        'knowledge-page' => ['📜', 'World Knowledge', $webRoot . '/ui/worldknowledge_upload.php?embed=1'],
        'actions-page' => ['⚔️', 'Action Editor', $webRoot . '/ui/function_editor.php?embed=1'],
        'prompts-page' => ['💬', 'Prompts Manager', $webRoot . '/ui/prompts_manager.php?embed=1'],
        'autonomy-page' => ['📔', 'Narrator & Autonomy', $webRoot . '/ui/narrator_management.php?embed=1'],
    ]],
];
$allTabIds = [];
foreach ($tabs as $group) $allTabIds = array_merge($allTabIds, array_keys($group['pages']));
$requestedTab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'characters-page';
$activeTab = in_array($requestedTab, $allTabIds, true) ? $requestedTab : 'characters-page';
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="d-flex flex-column">
    <div class="top-area">
        <div class="config-navigation" aria-label="Configuration sections">
            <div class="tab-groups">
                <?php foreach ($tabs as $group): ?>
                    <?php $groupActive = array_key_exists($activeTab, $group['pages']); ?>
                    <section class="tab-group<?php echo $groupActive ? ' active' : ''; ?>">
                        <div class="tab-group-label"><?php echo almsivi_ui_h($group['label']); ?></div>
                        <div class="tab-buttons" role="tablist" aria-label="<?php echo almsivi_ui_h($group['label']); ?> pages">
                            <?php foreach ($group['pages'] as $tabId => [$icon, $label]): ?>
                                <button class="tab-button<?php echo $activeTab === $tabId ? ' active' : ''; ?>" type="button" data-tab="<?php echo almsivi_ui_h($tabId); ?>" aria-selected="<?php echo $activeTab === $tabId ? 'true' : 'false'; ?>">
                                    <span class="tab-icon" aria-hidden="true"><?php echo almsivi_ui_h($icon); ?></span><span><?php echo almsivi_ui_h($label); ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="content-area flex-grow-1 d-flex overflow-hidden">
        <?php foreach ($tabs as $group): foreach ($group['pages'] as $tabId => [, $label, $src]): ?>
            <section id="<?php echo almsivi_ui_h($tabId); ?>" class="tab-content<?php echo $activeTab === $tabId ? ' active' : ''; ?>">
                <div class="embed-wrap"><iframe class="embed" title="<?php echo almsivi_ui_h($label); ?>" loading="<?php echo $activeTab === $tabId ? 'eager' : 'lazy'; ?>" src="<?php echo $activeTab === $tabId ? almsivi_ui_h($src) : 'about:blank'; ?>"<?php echo $activeTab === $tabId ? '' : ' data-src="' . almsivi_ui_h($src) . '"'; ?>></iframe></div>
            </section>
        <?php endforeach; endforeach; ?>
    </div>
</main>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
