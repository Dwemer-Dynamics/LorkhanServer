<?php

declare(strict_types=1);

use ALMSIVIserver\Application\ConnectorCatalog;
use ALMSIVIserver\Application\FirstPartyJobHandlerFactory;

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Server Plugins';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page server-plugins-shell' . ($embedded ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';
$jobTypes = FirstPartyJobHandlerFactory::jobTypes();
$actions = $uiRepository->rows('actions');
$ttsModules = ConnectorCatalog::all('tts_provider');
$sttModules = ConnectorCatalog::all('stt_provider');
$additionalStylesheets = ['herika-server-plugins.css?v=' . (string) filemtime(__DIR__ . '/css/herika-server-plugins.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="plugins-page">
    <header class="page-header"><h1>Server Plugins</h1><p>Manage and install plugins to extend ALMSIVI functionality</p></header>
    <section class="sync-section"><div><h2>Automatic Game Plugin Sync</h2><p>ALMSIVI will transfer bundled server plugins automatically when the OpenMW client negotiates a supported package. No manual upload is required.</p></div><span class="status-control"><button type="button" class="plugin-button" disabled>Refresh Status</button><?php echo almsivi_ui_feature_badge('config.plugins.sync', true); ?></span></section>
    <div class="sync-status" role="status"><strong>Installed from game:</strong><span>ALMSIVI Core</span><span>Source controlled</span><?php echo almsivi_ui_feature_badge('config.plugins.lifecycle', true); ?></div>
    <div class="table-toolbar"><span class="status-control"><button type="button" class="plugin-button" disabled>Refresh Plugins</button><?php echo almsivi_ui_feature_badge('config.plugins.sync', true); ?></span><span><?php echo count($jobTypes); ?> worker modules &middot; <?php echo count($actions); ?> OpenMW actions &middot; <?php echo count($ttsModules); ?> TTS adapters</span></div>
    <section class="plugin-table"><table><thead><tr><th>Plugin</th><th>Description</th><th>Current Version</th><th>Channel</th><th>Latest Channel Version</th><th>Plugin Menu</th><th>Delete Plugin</th></tr></thead><tbody><?php foreach ($jobTypes as $type): $excluded = $type === 'stt.process'; ?><tr><td><strong><?php echo almsivi_ui_h($type); ?></strong></td><td><?php echo $excluded ? 'Speech-to-text worker retained in the factory catalogue but excluded from the browser workflow.' : 'Bounded first-party durable job handler registered by ALMSIVIserver.'; ?></td><td>Bundled</td><td><?php echo $excluded ? almsivi_ui_feature_badge('config.stt', true) : '<span class="channel-live">Live</span>'; ?></td><td>Source controlled</td><td><span class="status-control"><button type="button" class="plugin-button" disabled>Plugin Page</button><?php echo almsivi_ui_feature_badge('config.plugins.lifecycle', true); ?></span><span class="status-control"><button type="button" class="plugin-button" disabled>Update Live</button><?php echo almsivi_ui_feature_badge('config.plugins.lifecycle', true); ?></span><span class="status-control"><button type="button" class="plugin-button" disabled>Switch to Dev</button><?php echo almsivi_ui_feature_badge('config.plugins.lifecycle', true); ?></span><span class="status-control"><button type="button" class="plugin-button" disabled>Download OpenMW Modfile</button><?php echo almsivi_ui_feature_badge('config.plugins.sync', true); ?></span></td><td><span class="status-control"><button type="button" class="plugin-button danger" disabled>Delete Plugin</button><?php echo almsivi_ui_feature_badge('config.plugins.lifecycle', true); ?></span></td></tr><?php endforeach; ?></tbody></table></section>

    <header class="repository-header"><h1>ALMSIVI Plugins Repository</h1><p>Download extensions that add extra AI features to ALMSIVI</p></header>
    <section class="plugin-table repository-table"><table><thead><tr><th>Plugin</th><th>Description</th><th>Plugin Menu</th></tr></thead><tbody>
        <?php foreach ([['ALMSIVI-Twitch-Bot','Allows viewers to interact with AI NPCs through a future bounded chat integration.','config.plugins.marketplace'],['ALMSIVI-MindMap','Visualization of typed memories and relationship context.','config.plugins.marketplace'],['ALMSIVI-Custom','Optional prompt-aware integrations for third-party OpenMW mods.','config.plugins.marketplace'],['SHARMAT','Skyrim-specific framework from the Herika catalogue.','config.plugins.skyrim']] as [$name,$description,$feature]): ?><tr><td><strong><?php echo almsivi_ui_h($name); ?></strong></td><td><?php echo almsivi_ui_h($description); ?></td><td><span class="status-control"><button type="button" class="plugin-button" disabled><?php echo $name === 'SHARMAT' ? 'Install SHARMAT' : 'Install Plugin'; ?></button><?php echo almsivi_ui_feature_badge($feature, true); ?></span><span class="status-control"><button type="button" class="plugin-button" disabled>GitHub</button><?php echo almsivi_ui_feature_badge($feature, true); ?></span><?php if ($name === 'ALMSIVI-Custom' || $name === 'SHARMAT'): ?><span class="status-control"><button type="button" class="plugin-button" disabled>Mod Download</button><?php echo almsivi_ui_feature_badge($feature, true); ?></span><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></section>

    <details class="module-details"><summary>ALMSIVI first-party module inventory</summary><div class="module-grid"><article><h2>OpenMW Actions</h2><?php almsivi_ui_table($actions); ?></article><article><h2>Speech Adapters</h2><ul><?php foreach ($ttsModules as $module): ?><li><strong><?php echo almsivi_ui_h($module['label']); ?></strong> &mdash; <?php echo $module['local'] ? 'Local' : 'Cloud'; ?> <code><?php echo almsivi_ui_h($module['driver']); ?></code></li><?php endforeach; ?><?php foreach ($sttModules as $module): ?><li><strong><?php echo almsivi_ui_h($module['label']); ?></strong> <?php echo almsivi_ui_feature_badge('config.stt', true); ?></li><?php endforeach; ?></ul></article></div></details>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
