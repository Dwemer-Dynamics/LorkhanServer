<?php
declare(strict_types=1);
$pageTitle='Server Logs'; $topNavSection='control'; $BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';
require __DIR__.'/tmpl/server_log_reader.php';
$logSources=[
    ['id'=>'worker','title'=>'LORKHAN Worker','file'=>'worker.log','path'=>'/var/log/lorkhanserver/worker.log','raw'=>false],
    ['id'=>'apache','title'=>'Apache / PHP Errors','file'=>'lorkhanserver-error.log','path'=>'/var/log/apache2/lorkhanserver-error.log','raw'=>false],
    ['id'=>'access','title'=>'Apache Requests','file'=>'lorkhanserver-access.log','path'=>'/var/log/apache2/lorkhanserver-access.log','raw'=>true],
];
foreach ($logSources as &$source) {
    $contents=lorkhan_ui_log_tail($source['path']);
    $source['entries']=lorkhan_ui_log_entries($contents??'', $source['raw']);
    $source['empty']=$contents===null?'No readable log output is available.':'Log is empty.';
    unset($source['path']);
}
unset($source);
$additionalStylesheets=['server-logs.css?v='.filemtime(__DIR__.'/css/server-logs.css')];
include __DIR__.'/tmpl/head.html'; if (!$embedded) include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/server_logs.html.php';
?>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/server-logs.js?v=<?= filemtime(__DIR__.'/js/server-logs.js') ?>"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
