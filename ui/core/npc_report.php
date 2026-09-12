<?php

declare(strict_types=1);
use LorkhanServer\Infrastructure\NpcEvolutionReportRepository;

$pageTitle='NPC Report';$topNavSection='configuration';$BODY_CLASS='npc-report-page';
require dirname(__DIR__).'/ui_bootstrap.php';
$reports=new NpcEvolutionReportRepository($database);
$installation=is_string($_GET['installation_id']??null)?$_GET['installation_id']:'';
$profile=is_string($_GET['profile_id']??null)?$_GET['profile_id']:'';
$npc=null;$error='';$status=200;
try{$npc=$reports->profile($installation,$profile);}catch(InvalidArgumentException){$status=400;$error='Choose an NPC from Profile Versions.';}catch(RuntimeException){$status=404;$error='NPC not found.';}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    header('Content-Type: application/json; charset=utf-8');
    try{
        if(!is_string($_POST['_csrf']??null)||!hash_equals($csrf,$_POST['_csrf'])){http_response_code(401);echo json_encode(['error'=>'unauthorized']);exit;}
        if(!$npc){http_response_code($status);echo json_encode(['error'=>'not_found']);exit;}
        $operation=$_POST['operation']??'';
        if($operation==='generate'&&is_string($_POST['request_id']??null)){$result=$reports->enqueue($installation,$profile,$_POST['request_id']);http_response_code(202);}
        elseif($operation==='status'&&is_string($_POST['job_id']??null))$result=$reports->status($installation,$profile,$_POST['job_id']);
        else throw new InvalidArgumentException('invalid_report_request');
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
    catch(Throwable){http_response_code(503);echo json_encode(['error'=>'report_unavailable']);}
    exit;
}
http_response_code($status);include dirname(__DIR__).'/tmpl/head.html';
?>
<link rel="stylesheet" href="<?= lorkhan_ui_h($webRoot) ?>/ui/css/npc-report.css?v=<?= filemtime(dirname(__DIR__).'/css/npc-report.css') ?>">
<main class="npc-report-main">
    <h1 class="api-title">NPC Report<?= $npc?' - '.lorkhan_ui_h($npc['name']):'' ?></h1>
    <div class="content-grid"><section class="content-section"><h2>Report</h2>
    <?php if($error!==''): ?><p><?= lorkhan_ui_h($error) ?></p><?php else: ?>
        <form method="post" data-npc-report-form><input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
            <p class="report-help">Generate an evolution report from this NPC's saved personality history. Uses the Background &amp; Memory Tasks connector in Global Settings. This makes an AI request; the NPC profile is not changed.</p>
            <button type="submit">Generate report (AI request)</button>
        </form>
        <p role="status" data-report-status>No report requested.</p>
        <div class="report-body" data-report-body></div>
    <?php endif; ?>
    </section></div>
</main>
<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/npc-report.js?v=<?= filemtime(dirname(__DIR__).'/js/npc-report.js') ?>" defer></script>
<?php include dirname(__DIR__).'/tmpl/footer.html'; ?>
