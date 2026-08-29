<?php

declare(strict_types=1);

$pageTitle='NPC Oghma Knowledge';$topNavSection='configuration';$BODY_CLASS='hub-page oghma-knowledge-shell';require __DIR__.'/ui_bootstrap.php';
$installationId=trim((string)($_GET['installation_id']??''));$profileId=trim((string)($_GET['profile_id']??''));
if(preg_match('/^[0-9a-f-]{36}$/D',$installationId)!==1||preg_match('/^[0-9a-f-]{36}$/D',$profileId)!==1){http_response_code(404);exit;}
try{$viewer=$productRepository->oghmaKnowledgeForProfile($installationId,$profileId,['search'=>$_GET['search']??'',
    'category'=>$_GET['category']??'','access'=>$_GET['access']??'all','page'=>$_GET['page']??1]);}catch(Throwable){http_response_code(404);exit;}
$filters=$viewer['filters'];$additionalStylesheets=['herika-oghma-runtime.css?v='.(string)filemtime(__DIR__.'/css/herika-oghma-runtime.css')];
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
$pageUrl=static function(int$page)use($installationId,$profileId,$filters,$embedded):string{return'?'.http_build_query($filters+['installation_id'=>$installationId,'profile_id'=>$profileId,'page'=>$page,'embed'=>$embedded?'1':'0']);};
?>
<main class="oghma-runtime-page">
    <header class="runtime-header"><div><h1><?php echo lorkhan_ui_h($viewer['profile']['name']); ?>: Oghma Knowledge</h1><p>Every article this NPC can draw on in conversation, each shown at the most detailed level they are allowed to know.</p>
        <details class="runtime-note"><summary>How access is decided</summary><p>Effective articles after Global &rarr; Core Profile &rarr; NPC knowledge tags and advanced/basic access checks.</p></details></div><span class="runtime-count"><?php echo lorkhan_ui_h($viewer['total']); ?> visible</span></header>
    <section class="knowledge-summary"><article><span>Advanced</span><strong><?php echo $viewer['counts']['advanced']; ?></strong></article><article><span>Basic</span><strong><?php echo $viewer['counts']['basic']; ?></strong></article><article><span>Denied</span><strong><?php echo $viewer['counts']['denied']; ?></strong></article><article><span>Effective tags</span><strong><?php echo lorkhan_ui_h(implode(', ',$viewer['knowledge_tags'])?:'None'); ?></strong></article></section>
    <form class="runtime-filters" method="get"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="profile_id" value="<?php echo lorkhan_ui_h($profileId); ?>"><?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label>Search<input name="search" maxlength="100" value="<?php echo lorkhan_ui_h($filters['search']); ?>" placeholder="Topic, alias or tag"></label>
        <label>Category<select name="category"><option value="">All categories</option><?php foreach($viewer['categories']as$category): ?><option<?php echo $filters['category']===$category?' selected':''; ?>><?php echo lorkhan_ui_h($category); ?></option><?php endforeach; ?></select></label>
        <label>Access<select name="access"><?php foreach(['all'=>'All accessible','advanced'=>'Advanced','basic'=>'Basic']as$value=>$label): ?><option value="<?php echo $value; ?>"<?php echo $filters['access']===$value?' selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
        <button class="btn-base" type="submit">Apply filters</button>
    </form>
    <section class="knowledge-list"><?php foreach($viewer['items']as$item): ?><article class="knowledge-card"><header><div><h2><?php echo lorkhan_ui_h($item['topic']); ?></h2><p><?php echo lorkhan_ui_h($item['category']); ?></p></div><span class="access-badge <?php echo lorkhan_ui_h($item['access_level']); ?>"><?php echo lorkhan_ui_h(ucfirst($item['access_level'])); ?></span></header><p><?php echo nl2br(lorkhan_ui_h($item['effective_content'])); ?></p><footer><strong>Aliases:</strong> <?php echo lorkhan_ui_h($item['aliases']?:'None'); ?> &middot; <strong>Tags:</strong> <?php echo lorkhan_ui_h($item['tags']?:'None'); ?></footer></article><?php endforeach; ?></section>
    <?php if($viewer['items']===[]): ?><section class="runtime-empty">No accessible articles match these filters.</section><?php endif; ?>
    <nav class="runtime-pagination" aria-label="NPC knowledge pages"><?php if($viewer['page']>1): ?><a href="<?php echo lorkhan_ui_h($pageUrl($viewer['page']-1)); ?>">Previous</a><?php endif; ?><span>Page <?php echo $viewer['page']; ?> of <?php echo $viewer['pages']; ?></span><?php if($viewer['page']<$viewer['pages']): ?><a href="<?php echo lorkhan_ui_h($pageUrl($viewer['page']+1)); ?>">Next</a><?php endif; ?></nav>
</main>
<?php include __DIR__.'/tmpl/footer.html'; ?>
