<?php

declare(strict_types=1);

$embedded=(string)($_GET['embed']??'')==='1';$pageTitle='Oghma Infinium';$topNavSection='configuration';
$BODY_CLASS='hub-page oghma-page-shell'.($embedded?' embedded-page':'');require __DIR__.'/ui_bootstrap.php';
$installations=$uiRepository->rows('installations');$selectedInstallation=(string)($_GET['installation_id']??($installations[0]['installation_id']??''));
$filters=['search'=>(string)($_GET['search']??''),'category'=>(string)($_GET['category']??''),
    'order'=>(string)($_GET['order']??'asc'),'page'=>(int)($_GET['page']??1),'installation_id'=>$selectedInstallation];
$catalog=$uiRepository->oghmaCatalog($filters);$rows=$catalog['rows'];$categories=$uiRepository->oghmaCategories();
$catalogStatus=$uiRepository->oghmaCatalogStatus();
$additionalStylesheets=['herika-oghma.css?v='.(string)filemtime(__DIR__.'/css/herika-oghma.css')];include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
$query=static function(array$replace=[])use($filters,$embedded):string{return'?'.http_build_query(array_merge($filters,['embed'=>$embedded?'1':'0'],$replace));};
$formFields=static function(array$row=[]):void{?>
    <div class="entry-form-grid">
        <label>Topic<input name="topic" required maxlength="256" value="<?php echo almsivi_ui_h($row['topic']??''); ?>"></label>
        <label>Display title<input name="title" maxlength="256" value="<?php echo almsivi_ui_h($row['title']??''); ?>"></label>
        <label class="wide">Aliases<input name="aliases" value="<?php echo almsivi_ui_h($row['aliases']??''); ?>" placeholder="Comma-separated aliases"></label>
        <label class="wide">Advanced article<textarea name="content" required><?php echo almsivi_ui_h($row['content']??''); ?></textarea></label>
        <label>Advanced knowledge classes<input name="knowledge_class" value="<?php echo almsivi_ui_h($row['knowledge_class']??''); ?>"></label>
        <label>Category<input name="category" required maxlength="128" value="<?php echo almsivi_ui_h($row['category']??'lore'); ?>"></label>
        <label class="wide">Basic article<textarea name="topic_desc_basic" required><?php echo almsivi_ui_h($row['topic_desc_basic']??''); ?></textarea></label>
        <label>Basic knowledge classes<input name="knowledge_class_basic" value="<?php echo almsivi_ui_h($row['knowledge_class_basic']??'common'); ?>"></label>
        <label>Tags<input name="tags" value="<?php echo almsivi_ui_h($row['tags']??''); ?>"></label>
        <input type="hidden" name="provenance" value="management">
    </div>
<?php };
?>
<main class="oghma-page">
    <header class="page-header"><h1 id="page-title"><span class="oghma-title-icon" aria-hidden="true">&#x1F4D9;</span><span>Oghma Infinium</span></h1><div class="header-content">
        <p><strong>Static Morrowind knowledge</strong> with CHIM-compatible advanced and basic access classes.</p>
        <p>Global knowledge tags inherit through Core Profiles to NPC overrides. Only relevant, authorized articles enter prompts.</p>
        <div class="logic-section"><h3 class="logic-title">&#x1F50D; Article Search Logic</h3><div class="logic-steps">
            <?php foreach([['1','Topic & Alias','Match canonical topics and aliases first.'],['2','Bounded Rank','Rank a small deterministic candidate set.'],['3','Access Gate','Select advanced, basic, or reject from effective tags.'],['4','Audited Prompt','Record every decision and never fabricate a match.']]as[$number,$title,$text]): ?>
            <div class="logic-step"><span class="step-number"><?php echo $number; ?></span><span class="step-content"><strong><?php echo almsivi_ui_h($title); ?></strong><span><?php echo almsivi_ui_h($text); ?></span></span></div><?php endforeach; ?>
        </div></div></div></header>
    <nav class="tab-navigation" aria-label="Oghma pages"><button type="button" class="tab-button active">&#x1F4DA; Oghma Infinium</button><span class="status-control"><button type="button" class="tab-button" disabled>&#x26A1; Dynamic Oghma</button><?php echo almsivi_ui_feature_badge('config.oghma.dynamic',true); ?></span></nav>
    <?php if(isset($_GET['status'])): ?><div class="oghma-notice"><?php echo almsivi_ui_h($_GET['status']==='imported'?'CSV validated and imported.':'Knowledge record saved.'); ?></div><?php endif; ?>
    <div class="content-grid">
        <section class="content-section"><h2>Batch Upload</h2><form method="post" enctype="multipart/form-data" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/knowledge-import">
            <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><label>Installation<select name="installation_id" required><?php foreach($installations as$installation): ?><option value="<?php echo almsivi_ui_h($installation['installation_id']); ?>"<?php echo $selectedInstallation===$installation['installation_id']?' selected':''; ?>><?php echo almsivi_ui_h($installation['display_name']??$installation['installation_id']); ?></option><?php endforeach; ?></select></label>
            <label>Select CHIM-format UTF-8 CSV<input name="csv_file" type="file" accept=".csv,text/csv" required></label><div class="button-group"><button class="action-button upload-csv" type="submit">Validate &amp; Import CSV</button><a class="action-button download-csv" href="<?php echo almsivi_ui_h($managementBasePath); ?>/exports/oghma/example.csv">Download Example CSV</a></div>
        </form></section>
        <section class="content-section"><h2>Factory Catalog</h2><?php if($catalogStatus): ?><dl><dt>Version</dt><dd><?php echo almsivi_ui_h($catalogStatus['catalog_version']); ?></dd><dt>Articles</dt><dd><?php echo (int)$catalogStatus['row_count']; ?></dd><dt>SHA-256</dt><dd><code><?php echo almsivi_ui_h($catalogStatus['articles_sha256']); ?></code></dd><dt>Activated</dt><dd><?php echo almsivi_ui_h($catalogStatus['activated_at']); ?></dd></dl><?php else: ?><p>No factory catalog is active.</p><?php endif; ?><p>Factory records show their provenance and are read-only. User-authored records remain editable and soft-deletable.</p></section>
    </div>
    <section class="full-width-section"><h2 id="entries">&#x1F4CB; Oghma Infinium Entries</h2>
        <div class="action-container"><button type="button" class="action-button add-new" data-oghma-create>Add New Entry</button><form class="oghma-filter-form" method="get"><input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>"><select name="installation_id" aria-label="Installation"><?php foreach($installations as$installation): ?><option value="<?php echo almsivi_ui_h($installation['installation_id']); ?>"<?php echo $selectedInstallation===$installation['installation_id']?' selected':''; ?>><?php echo almsivi_ui_h($installation['display_name']??$installation['installation_id']); ?></option><?php endforeach; ?></select><input type="search" name="search" placeholder="Search topics and aliases..." value="<?php echo almsivi_ui_h($filters['search']); ?>"><select name="category"><option value="">All Categories</option><?php foreach($categories as$category): ?><option value="<?php echo almsivi_ui_h($category); ?>"<?php echo $filters['category']===$category?' selected':''; ?>><?php echo almsivi_ui_h(ucwords(str_replace('_',' ',$category))); ?></option><?php endforeach; ?></select><select name="order"><option value="asc"<?php echo $filters['order']==='asc'?' selected':''; ?>>Ascending</option><option value="desc"<?php echo $filters['order']==='desc'?' selected':''; ?>>Descending</option></select><button class="action-button edit">Apply</button></form></div>
        <p class="oghma-result-count"><?php echo (int)$catalog['total']; ?> entries &middot; page <?php echo (int)$catalog['page']; ?> of <?php echo (int)$catalog['pages']; ?></p>
        <section class="new-entry-panel" data-oghma-create-panel hidden><h2>Add New Entry</h2><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/knowledge"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($selectedInstallation); ?>"><?php $formFields(); ?><div class="button-group"><button type="submit" class="action-button add-new">Add Knowledge</button><button type="button" class="action-button" data-oghma-create-cancel>Cancel</button></div></form></section>
        <div class="oghma-entry-grid"><?php foreach($rows as$row): $factory=($row['provenance']['source']??null)==='factory-oghma'; ?><article class="oghma-entry"><header><h3><?php echo almsivi_ui_h($row['title']); ?></h3><span class="almsivi-badge"><?php echo almsivi_ui_h($row['category']); ?></span></header><p><strong>Topic:</strong> <code><?php echo almsivi_ui_h($row['topic']); ?></code></p><p><?php echo nl2br(almsivi_ui_h($row['content'])); ?></p><details><summary>Basic article and access</summary><p><?php echo nl2br(almsivi_ui_h($row['topic_desc_basic'])); ?></p><dl><dt>Advanced</dt><dd><?php echo almsivi_ui_h($row['knowledge_class']); ?></dd><dt>Basic</dt><dd><?php echo almsivi_ui_h($row['knowledge_class_basic']); ?></dd><dt>Aliases</dt><dd><?php echo almsivi_ui_h($row['aliases']); ?></dd><dt>Tags</dt><dd><?php echo almsivi_ui_h($row['tags']); ?></dd></dl></details><small><?php echo $factory?'Factory '.$row['provenance']['catalog_version']:'User-authored '.$row['provenance']['source']; ?></small>
            <?php if(!$factory): ?><details class="oghma-edit"><summary>Edit</summary><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/knowledge-revise"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="document_id" value="<?php echo almsivi_ui_h($row['document_id']); ?>"><?php $formFields($row); ?><button class="action-button edit">Save Changes</button></form><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/knowledge-delete"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="document_id" value="<?php echo almsivi_ui_h($row['document_id']); ?>"><button class="btn-danger">Delete Entry</button></form></details><?php endif; ?>
        </article><?php endforeach; ?><?php if($rows===[]): ?><p class="oghma-empty">No knowledge entries match these filters.</p><?php endif; ?></div>
        <nav class="oghma-pagination" aria-label="Oghma pages"><?php if($catalog['page']>1): ?><a class="alphabet-button" href="<?php echo almsivi_ui_h($query(['page'=>$catalog['page']-1])); ?>">Previous</a><?php endif; ?><?php if($catalog['page']<$catalog['pages']): ?><a class="alphabet-button" href="<?php echo almsivi_ui_h($query(['page'=>$catalog['page']+1])); ?>">Next</a><?php endif; ?></nav>
    </section>
</main><script defer src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/oghma.js?v=<?php echo almsivi_ui_h((string)filemtime(__DIR__.'/js/oghma.js')); ?>"></script><?php include __DIR__.'/tmpl/footer.html'; ?>
