<?php
declare(strict_types=1);
$pageTitle='Oghma Audit'; $topNavSection='control'; $BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php'; require __DIR__.'/tmpl/oghma_audit_reader.php';
$installations=$uiRepository->rows('installations');
$filters=[];
foreach (['installation_id'=>'','search'=>'','matched'=>'all','extractor'=>'all'] as $key=>$default) $filters[$key]=is_string($_GET[$key]??null)?$_GET[$key]:$default;
if (!in_array($filters['installation_id'],array_column($installations,'installation_id'),true)) $filters['installation_id']='';
if ($filters['matched']==='1') $filters['matched']='matched';
$filters['page']=filter_var($_GET['page']??1,FILTER_VALIDATE_INT)?:1;
$filters['page_size']=filter_var($_GET['page_size']??50,FILTER_VALIDATE_INT);
if (!in_array($filters['page_size'],[25,50,100],true)) $filters['page_size']=50;
$audit=$uiRepository->oghmaAudit($filters); $filters=$audit['filters']+['page_size'=>$audit['page_size']];
$pageUrl=static fn(int $page):string=>'?'.http_build_query($filters+['page'=>$page,'embed'=>$embedded?'1':'0']);
$additionalStylesheets=['oghma-audit.css?v='.filemtime(__DIR__.'/css/oghma-audit.css')];
include __DIR__.'/tmpl/head.html'; if (!$embedded) include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/oghma_audit.html.php';
?>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/oghma-audit.js?v=<?= filemtime(__DIR__.'/js/oghma-audit.js') ?>"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
