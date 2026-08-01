<?php

$navItems = [
    'home' => ['Home', $webRoot . '/ui/home.php'],
    'roleplay' => ['Roleplay', $webRoot . '/ui/events-memories.php'],
    'configuration' => ['Configuration', $webRoot . '/ui/core/config_hub.php'],
    'control' => ['Control Panel', $webRoot . '/ui/control_panel.php'],
];
?>
<div class="almsivi-navbar-wrapper">
    <nav class="navbar navbar-expand-lg almsivi-navbar" aria-label="ALMSIVI server">
        <div class="container-fluid mx-1">
            <div class="navbar-content-wrapper">
                <div class="navbar-center dropdown">
                    <button class="navbar-brand Title btn btn-link p-0 dropdown-toggle" type="button"
                        data-bs-toggle="dropdown" data-bs-auto-close="true" data-bs-display="static"
                        aria-expanded="false" title="Open menu">
                        <img src="<?php echo almsivi_ui_h($webRoot); ?>/ui/images/DwemerDynamics.png" alt="Dwemer Dynamics">
                        <img src="<?php echo almsivi_ui_h($webRoot); ?>/ui/images/almsivi-logo.svg" alt="ALMSIVI Server">
                    </button>
                    <ul class="dropdown-menu brand-menu">
                        <?php foreach ($navItems as $section => [$label, $href]): ?>
                            <li><a class="dropdown-item<?php echo $topNavSection === $section ? ' active' : ''; ?>"
                                href="<?php echo almsivi_ui_h($href); ?>"<?php echo $topNavSection === $section ? ' aria-current="page"' : ''; ?>><?php echo almsivi_ui_h($label); ?></a></li>
                        <?php endforeach; ?>
                        <li><a class="dropdown-item" href="/Dwemer-Dashboard/index.php">DwemerDistro Home</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
</div>
<div id="toast-notification" class="toast-notification" aria-live="polite"><span class="message"></span></div>
