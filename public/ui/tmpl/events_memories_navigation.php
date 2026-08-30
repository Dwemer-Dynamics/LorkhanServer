<?php

$roleplayGroups = [
    ['key' => 'activity-logs', 'label' => 'Activity & Logs', 'tabs' => [
        ['key' => 'eventlog', 'label' => 'Events', 'icon' => '&#x1F4DD;', 'feature' => 'roleplay.events'],
        ['key' => 'responselog', 'label' => 'AI Responses', 'icon' => '&#x1F4AC;', 'feature' => 'roleplay.responses'],
        ['key' => 'adventure', 'label' => 'Adventure Log', 'icon' => '&#x1F4C6;', 'feature' => 'roleplay.adventure'],
    ]],
    ['key' => 'memories-records', 'label' => 'Memories & Records', 'tabs' => [
        ['key' => 'memory', 'label' => 'Memories', 'icon' => '&#x1F9E0;', 'feature' => 'roleplay.memories'],
        ['key' => 'diaries', 'label' => 'LORKHAN Diaries', 'icon' => '&#x1F4D4;', 'feature' => 'roleplay.diaries'],
        ['key' => 'books', 'label' => 'Books', 'icon' => '&#x1F4DA;', 'feature' => 'roleplay.books'],
    ]],
    ['key' => 'world-quests', 'label' => 'World & Quests', 'tabs' => [
        ['key' => 'journal', 'label' => 'Journal', 'icon' => '&#x1F4D6;', 'feature' => 'roleplay.journal'],
        ['key' => 'questgen', 'label' => 'AI Quest Manager', 'icon' => '&#x1F9ED;', 'feature' => 'roleplay.quest-manager'],
        ['key' => 'backgroundlife', 'label' => 'Background Life', 'icon' => '&#x1F5FA;&#xFE0F;', 'feature' => 'roleplay.background-life'],
    ]],
];
?>
<nav class="config-navigation events-memories-navigation" aria-label="Roleplay sections">
    <div class="tab-groups">
        <?php foreach ($roleplayGroups as $group): $groupActive = (bool) array_filter($group['tabs'], static fn(array $tab): bool => $tab['key'] === $activeTab); ?>
        <section class="tab-group<?php echo $groupActive ? ' active' : ''; ?>" aria-labelledby="roleplay-group-<?php echo lorkhan_ui_h($group['key']); ?>">
            <div class="tab-group-label" id="roleplay-group-<?php echo lorkhan_ui_h($group['key']); ?>"><?php echo lorkhan_ui_h($group['label']); ?></div>
            <?php // Each entry navigates to its own page, so these stay links with aria-current rather than pretending to be tabs. ?>
            <div class="tab-buttons">
                <?php foreach ($group['tabs'] as $tab): $isActive = $tab['key'] === $activeTab; $feature = lorkhan_ui_feature($tab['feature']); $isLive = $feature['state'] === 'live'; ?>
                <?php if ($isLive): ?>
                <a class="tab-button<?php echo $isActive ? ' active' : ''; ?>" href="<?php echo lorkhan_ui_h($webRoot . '/ui/events-memories.php?tab=' . rawurlencode($tab['key'])); ?>" data-tab="<?php echo lorkhan_ui_h($tab['key']); ?>" title="<?php echo lorkhan_ui_h($tab['label']); ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                    <span class="tab-icon" aria-hidden="true"><?php echo $tab['icon']; ?></span><span class="tab-label"><?php echo lorkhan_ui_h($tab['label']); ?></span>
                </a>
                <?php else: // An unavailable page has nothing to open, so it is inert to pointer and keyboard alike and states why. ?>
                <button class="tab-button" type="button" disabled aria-disabled="true" data-tab="<?php echo lorkhan_ui_h($tab['key']); ?>" title="<?php echo lorkhan_ui_h($tab['label'] . ' — ' . $feature['description']); ?>">
                    <span class="tab-icon" aria-hidden="true"><?php echo $tab['icon']; ?></span><span class="tab-label-stack"><span class="tab-label"><?php echo lorkhan_ui_h($tab['label']); ?></span><?php echo lorkhan_ui_feature_badge($tab['feature'], true); ?></span>
                </button>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
</nav>
