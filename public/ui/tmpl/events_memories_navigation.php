<?php

$roleplayGroups = [
    ['label' => 'Activity & Logs', 'aria' => 'Activity and logs', 'tabs' => [
        ['key' => 'eventlog', 'label' => 'Events', 'icon' => '&#x1F4DD;', 'feature' => 'roleplay.events'],
        ['key' => 'responselog', 'label' => 'AI Responses', 'icon' => '&#x1F4AC;', 'feature' => 'roleplay.responses'],
        ['key' => 'adventure', 'label' => 'Adventure Log', 'icon' => '&#x1F4C6;', 'feature' => 'roleplay.adventure'],
    ]],
    ['label' => 'Memories & Records', 'aria' => 'Memory and record pages', 'tabs' => [
        ['key' => 'memory', 'label' => 'Memories', 'icon' => '&#x1F9E0;', 'feature' => 'roleplay.memories'],
        ['key' => 'diaries', 'label' => 'LORKHAN Diaries', 'icon' => '&#x1F4D4;', 'feature' => 'roleplay.diaries'],
        ['key' => 'books', 'label' => 'Books', 'icon' => '&#x1F4DA;', 'feature' => 'roleplay.books'],
    ]],
    ['label' => 'World & Quests', 'aria' => 'World and quest pages', 'tabs' => [
        ['key' => 'journal', 'label' => 'Journal', 'icon' => '&#x1F4D6;', 'feature' => 'roleplay.journal'],
        ['key' => 'questgen', 'label' => 'AI Quest Manager', 'icon' => '&#x1F9ED;', 'feature' => 'roleplay.quest-manager'],
        ['key' => 'backgroundlife', 'label' => 'Background Life', 'icon' => '&#x1F5FA;&#xFE0F;', 'feature' => 'roleplay.background-life'],
    ]],
];
?>
<div class="config-navigation events-memories-navigation" aria-label="Roleplay sections">
    <div class="tab-groups">
        <?php foreach ($roleplayGroups as $group): ?>
        <section class="tab-group<?php echo array_filter($group['tabs'], static fn(array $tab): bool => $tab['key'] === $activeTab) ? ' active' : ''; ?>">
            <div class="tab-group-label"><?php echo lorkhan_ui_h($group['label']); ?></div>
            <div class="tab-buttons" role="tablist" aria-label="<?php echo lorkhan_ui_h($group['aria']); ?>">
                <?php foreach ($group['tabs'] as $tab): $isActive = $tab['key'] === $activeTab; $feature = lorkhan_ui_feature($tab['feature']); ?>
                <a class="tab-button<?php echo $isActive ? ' active' : ''; ?>" href="<?php echo lorkhan_ui_h($webRoot . '/ui/events-memories.php?tab=' . rawurlencode($tab['key'])); ?>" data-tab="<?php echo lorkhan_ui_h($tab['key']); ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                    <span class="tab-icon" aria-hidden="true"><?php echo $tab['icon']; ?></span><?php if ($feature['state'] === 'live'): ?><span class="tab-label"><?php echo lorkhan_ui_h($tab['label']); ?></span><?php else: ?><span class="tab-label-stack"><span class="tab-label"><?php echo lorkhan_ui_h($tab['label']); ?></span><?php echo lorkhan_ui_feature_badge($tab['feature'], true); ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
</div>
