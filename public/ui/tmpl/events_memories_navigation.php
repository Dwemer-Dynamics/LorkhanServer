<?php

$roleplayGroups = [
    'Activity & Logs' => ['eventlog-tab' => '📝 Events', 'responses-tab' => '💬 AI Responses'],
    'Memories & Records' => ['memories-tab' => '🧠 Memories', 'relationships-tab' => '🔗 Relationships', 'narratives-tab' => '📔 Narratives'],
    'World' => ['knowledge-tab' => '📜 Knowledge Records'],
    'Morrowind' => ['journal-tab' => 'Morrowind Journal', 'books-tab' => 'Read Books'],
];
?>
<div class="events-memories-navigation config-navigation" aria-label="Roleplay sections">
    <div class="tab-groups">
        <?php foreach ($roleplayGroups as $label => $tabs): ?>
            <?php $groupActive = array_key_exists($activeTab, $tabs); ?>
            <section class="tab-group<?php echo $groupActive ? ' active' : ''; ?>">
                <div class="tab-group-label"><?php echo almsivi_ui_h($label); ?></div>
                <div class="tab-buttons" role="tablist" aria-label="<?php echo almsivi_ui_h($label); ?> pages">
                    <?php foreach ($tabs as $tabId => $tabLabel): ?>
                        <button class="tab-button<?php echo $activeTab === $tabId ? ' active' : ''; ?>" type="button"
                            data-tab="<?php echo almsivi_ui_h($tabId); ?>" aria-selected="<?php echo $activeTab === $tabId ? 'true' : 'false'; ?>">
                            <?php echo almsivi_ui_h($tabLabel); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</div>
