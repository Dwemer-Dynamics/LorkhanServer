<?php

declare(strict_types=1);

use ALMSIVIserver\Application\ConnectorCatalog;
use ALMSIVIserver\Application\FirstPartyJobHandlerFactory;

$pageTitle='Server Plugins';$topNavSection='configuration';$bodyClass='management-page';
require __DIR__.'/ui_bootstrap.php';
$jobTypes=FirstPartyJobHandlerFactory::jobTypes();$actions=$uiRepository->rows('actions');
$speechModules=array_merge(ConnectorCatalog::all('tts_provider'),ConnectorCatalog::all('stt_provider'));
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="management-page">
    <h1>Server Plugins</h1>
    <p>Installed ALMSIVI server modules are source-controlled and registered through fixed factories. Arbitrary PHP upload and execution is disabled.</p>
    <section class="widget widget-wide"><div class="widget-header"><h3>First-party Worker Modules</h3><span class="status-badge connector-active"><?php echo count($jobTypes);?> installed</span></div><div class="widget-content"><div class="connector-grid">
        <?php foreach($jobTypes as$type):?><article class="connector-card active"><header><div><span class="connector-kind">Durable job</span><h3><?php echo almsivi_ui_h($type);?></h3></div><span class="status-badge connector-active">Enabled</span></header><p>Bounded first-party handler registered by ALMSIVIserver.</p></article><?php endforeach;?>
    </div></div></section>
    <section class="widget widget-wide"><div class="widget-header"><h3>OpenMW Action Modules</h3><span class="status-badge connector-active"><?php echo count($actions);?> catalogued</span></div><div class="widget-content"><?php almsivi_ui_table($actions);?></div></section>
    <section class="widget widget-wide"><div class="widget-header"><h3>Speech Adapter Modules</h3><span class="status-badge connector-active"><?php echo count($speechModules);?> installed</span></div><div class="widget-content"><div class="connector-grid">
        <?php foreach($speechModules as$module):?><article class="connector-card"><header><div><span class="connector-kind"><?php echo str_starts_with((string)$module['credential_environment'],'ALMSIVI_TTS_')?'TTS':'STT';?></span><h3><?php echo almsivi_ui_h($module['label']);?></h3></div><span class="status-badge"><?php echo $module['local']?'Local':'Cloud';?></span></header><dl><dt>Driver</dt><dd><code><?php echo almsivi_ui_h($module['driver']);?></code></dd><dt>Credential</dt><dd><code><?php echo almsivi_ui_h($module['credential_environment']);?></code></dd></dl></article><?php endforeach;?>
    </div></div></section>
</main>
<?php include __DIR__.'/tmpl/footer.html';?>
