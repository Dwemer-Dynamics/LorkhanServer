<?php
declare(strict_types=1);
// Keep bookmarked links working; Dwemer Dashboard owns the service-log viewer.
require __DIR__.'/ui_bootstrap.php';
header('Cache-Control: no-store');
header('Location: /Dwemer-Dashboard/distro_debugger.php?tab=lorkhan'.($embedded?'&embed=1':''), true, 302);
exit;
