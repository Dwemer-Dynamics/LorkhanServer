<?php

declare(strict_types=1);

$pageTitle = 'Control Panel';
$topNavSection = 'control';
$BODY_CLASS = 'hub-page';
require __DIR__ . '/ui_bootstrap.php';

$controlSections = [
    'diagnostics' => ['label' => 'Diagnostics', 'tabs' => [
        'srvlogs' => ['Server Logs', '&#x1F332;', 'control.logs', $webRoot . '/ui/server_logs.php?embed=1'],
        'requests' => ['Request Logs', '&#x1F50D;', 'control.requests', $webRoot . '/ui/request_logs.php?embed=1'],
        'oghmaaudit' => ['Oghma Audit', '&#x1F4D6;', 'control.oghma-audit', $webRoot . '/ui/oghma_audit.php?embed=1'],
        'rellogs' => ['Relationship Logs', '&#x1F517;', 'control.relationships', $webRoot . '/ui/relationship_logs.php?embed=1'],
    ]],
    'monitoring' => ['label' => 'Monitoring', 'tabs' => [
        'audit' => ['Cost Breakdown', '&#x1F4CA;', 'control.usage', $webRoot . '/ui/provider_usage.php?embed=1'],
        'responses' => ['Response Queue', '&#x1F4AC;', 'control.responses', $webRoot . '/ui/response_queue.php?embed=1'],
        'providers' => ['Provider Attempts', '&#x1F4CA;', 'control.providers', $webRoot . '/ui/provider_attempts.php?embed=1'],
        'jobs' => ['Workers & Jobs', '&#x1F4E8;', 'control.jobs', $webRoot . '/ui/jobs.php?embed=1'],
    ]],
    'data-tools' => ['label' => 'Data & Tools', 'tabs' => [
        'game-debug' => ['Game Debug', '&#x1F6E0;&#xFE0F;', 'control.game-debug', $webRoot . '/ui/game_debug.php?embed=1'],
        'cache' => ['Audio Cache', '&#x1F3BC;', 'control.cache', $webRoot . '/ui/cache_browser.php?embed=1'],
        'playthrough' => ['Playthrough Manager', '&#x1F3AE;', 'control.playthroughs', $webRoot . '/ui/playthrough_manager.php?embed=1'],
        'dbmgr' => ['Database Manager', '&#x1F5C4;&#xFE0F;', 'control.database', $webRoot . '/ui/database_manager.php?embed=1'],
    ]],
];
$aliases = ['server-logs-page'=>'srvlogs','requests-page'=>'requests','oghma-audit-page'=>'oghmaaudit','relationships-page'=>'rellogs','usage-page'=>'audit','queue-page'=>'responses','providers-page'=>'providers','jobs-page'=>'jobs','cache-page'=>'cache','playthrough-page'=>'playthrough','database-page'=>'dbmgr','health-page'=>'srvlogs','game-debug-page'=>'game-debug'];
$requested = (string)($_GET['tab'] ?? 'srvlogs');
$requested = $aliases[$requested] ?? $requested;
$allTabs=[];foreach($controlSections as$section)foreach($section['tabs']as$id=>$tab)$allTabs[$id]=$tab;
$active=array_key_exists($requested,$allTabs)?$requested:'srvlogs';
$additionalStylesheets=['herika-hubs.css?v='.(string)filemtime(__DIR__.'/css/herika-hubs.css')];
$includeManagementStyles=false;
include __DIR__ . '/tmpl/head.html';
if(!$embedded)include __DIR__ . '/tmpl/navbar.php';
?>
<link rel="stylesheet" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/css/main.css">
<link rel="stylesheet" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/css/hub-navigation.css?v=<?php echo lorkhan_ui_h((string)filemtime(__DIR__.'/css/hub-navigation.css')); ?>">
<main class="d-flex flex-column herika-hub-page" data-config-hub data-active-tab="<?php echo lorkhan_ui_h($active); ?>">
    <div class="top-area"><div class="config-navigation" aria-label="Control Panel sections"><div class="tab-groups">
        <?php foreach($controlSections as$sectionId=>$section):$sectionActive=array_key_exists($active,$section['tabs']);$rovingTab=$sectionActive?$active:(string)array_key_first($section['tabs']); ?>
        <section class="tab-group<?php echo $sectionActive?' active':''; ?>" data-category="<?php echo lorkhan_ui_h($sectionId); ?>"><div class="tab-group-label"><?php echo lorkhan_ui_h($section['label']); ?></div><div class="tab-buttons" role="tablist" aria-label="<?php echo lorkhan_ui_h($section['label']); ?> pages">
            <?php foreach($section['tabs']as$tabId=>[$label,$icon,$featureId]):$feature=lorkhan_ui_feature($featureId); ?><button class="tab-button<?php echo $tabId===$active?' active':''; ?>" type="button" id="control-tab-<?php echo lorkhan_ui_h($tabId); ?>" role="tab" data-tab="<?php echo lorkhan_ui_h($tabId); ?>" data-category="<?php echo lorkhan_ui_h($sectionId); ?>" aria-controls="<?php echo lorkhan_ui_h($tabId); ?>" aria-selected="<?php echo $tabId===$active?'true':'false'; ?>" tabindex="<?php echo $tabId===$rovingTab?'0':'-1'; ?>" title="<?php echo lorkhan_ui_h($label); ?>"><span class="tab-icon" aria-hidden="true"><?php echo $icon; ?></span><?php if($feature['state']==='live'): ?><span class="tab-label"><?php echo lorkhan_ui_h($label); ?></span><?php else: ?><span class="tab-label-stack"><span class="tab-label"><?php echo lorkhan_ui_h($label); ?></span><?php echo lorkhan_ui_feature_badge($featureId,true); ?></span><?php endif; ?></button><?php endforeach; ?>
        </div></section><?php endforeach; ?>
    </div></div></div>
    <div class="content-area flex-grow-1 d-flex overflow-hidden">
        <?php foreach($allTabs as$tabId=>[$label,$icon,$featureId,$src]): ?><div id="<?php echo lorkhan_ui_h($tabId); ?>" class="tab-content<?php echo $tabId===$active?' active':''; ?>" data-tab-panel role="tabpanel" aria-labelledby="control-tab-<?php echo lorkhan_ui_h($tabId); ?>"><div class="embed-wrap"><iframe class="embed" title="<?php echo lorkhan_ui_h($label); ?>" loading="<?php echo $tabId===$active?'eager':'lazy'; ?>" src="<?php echo $tabId===$active?lorkhan_ui_h($src):'about:blank'; ?>" data-src="<?php echo lorkhan_ui_h($src); ?>"></iframe></div></div><?php endforeach; ?>
    </div>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
