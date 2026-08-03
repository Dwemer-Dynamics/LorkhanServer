<?php
declare(strict_types=1);
$currentPageName=basename((string)($_SERVER['PHP_SELF']??''));
$topNavSection=$topNavSection??match(true){
    $currentPageName==='home.php'=>'home',
    in_array($currentPageName,['events-memories.php'],true)=>'roleplay',
    in_array($currentPageName,['control_panel.php','database_manager.php','request_logs.php'],true)=>'control',
    default=>'configuration',
};
?>
<div class="chim-navbar-wrapper">
    <nav class="navbar navbar-expand chim-navbar" aria-label="ALMSIVI server navigation">
        <div class="container-fluid justify-content-center">
            <div class="navbar-center dropdown">
                <button class="navbar-brand Title btn btn-link p-0 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Open menu">
                    <img src="<?php echo almsivi_ui_h($webRoot); ?>/ui/images/DwemerDynamics.png" alt="Dwemer Dynamics">
                    <span class="almsivi-brand-name">ALMSIVI</span>
                </button>
                <ul class="dropdown-menu brand-menu">
                    <?php foreach([
                        'home'=>['Home',$webRoot.'/ui/home.php'],
                        'roleplay'=>['Roleplay',$webRoot.'/ui/events-memories.php'],
                        'configuration'=>['Configuration',$webRoot.'/ui/core/config_hub.php'],
                        'control'=>['Control Panel',$webRoot.'/ui/control_panel.php'],
                    ]as$key=>[$label,$href]): ?>
                        <li><a class="dropdown-item<?php echo $topNavSection===$key?' active':''; ?>" href="<?php echo almsivi_ui_h($href); ?>"<?php echo $topNavSection===$key?' aria-current="page"':''; ?>><?php echo almsivi_ui_h($label); ?></a></li>
                    <?php endforeach; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="/Dwemer-Dashboard/index.php">DwemerDistro Home</a></li>
                </ul>
            </div>
        </div>
    </nav>
</div>
<div id="toast-notification" class="toast-notification" role="status" aria-live="polite"><span class="message"></span></div>
