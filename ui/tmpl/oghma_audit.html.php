<main class="oghma-audit-page page-wrap container-fluid" data-oghma-audit>
    <div class="page-header"><h1>Oghma Audit</h1><div>Review Oghma retrieval attempts, selected topics, ranks, and captured search signals.</div></div>
    <div class="toolbar-wrap">
        <div><input id="auditSearch" class="search-input" type="search" aria-label="Filter current page" placeholder="Filter current page by input, selected topic, signals, notes..."></div>
        <label class="quick-toggle"><input type="checkbox" id="matchedOnlyToggle"<?= $filters['matched']==='matched'?' checked':'' ?>><span>Only Matched</span></label>
        <form method="get" class="per-page-form">
            <?php foreach ($filters as $key=>$value): if ($key==='page_size') continue; ?><input type="hidden" name="<?= lorkhan_ui_h($key) ?>" value="<?= lorkhan_ui_h($value) ?>"><?php endforeach; ?>
            <input type="hidden" name="page" value="1"><input type="hidden" name="embed" value="<?= $embedded?'1':'0' ?>">
            <label><span>Per page</span><select class="per-page-select" name="page_size"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>"<?= $audit['page_size']===$size?' selected':'' ?>><?= $size ?></option><?php endforeach; ?></select></label>
        </form>
    </div>
    <div class="pager-wrap"><div class="pager-meta">Showing <?= $audit['total']>0?($audit['page']-1)*$audit['page_size']+1:0 ?>-<?= min($audit['page']*$audit['page_size'],$audit['total']) ?> of <?= $audit['total'] ?> rows<?= $filters['matched']==='matched'?' (matched only)':'' ?>
        <details class="audit-tools"><summary>More filters</summary><form method="get" class="audit-tools-body">
            <input type="hidden" name="embed" value="<?= $embedded?'1':'0' ?>"><input type="hidden" name="page_size" value="<?= $audit['page_size'] ?>">
            <label>Installation<select name="installation_id"><option value="">All installations</option><?php foreach ($installations as $installation): ?><option value="<?= lorkhan_ui_h($installation['installation_id']) ?>"<?= $filters['installation_id']===$installation['installation_id']?' selected':'' ?>><?= lorkhan_ui_h($installation['display_name']) ?></option><?php endforeach; ?></select></label>
            <label>Search all rows<input type="search" name="search" maxlength="100" value="<?= lorkhan_ui_h($filters['search']) ?>"></label>
            <label>Results<select name="matched"><?php foreach (['all'=>'All','matched'=>'Matched','unmatched'=>'Unmatched'] as $value=>$label): ?><option value="<?= $value ?>"<?= $filters['matched']===$value?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
            <label>Extractor status<select name="extractor"><?php foreach (['all','grounded','no_match','fallback_succeeded','fallback_unresolved','fallback_failed','fallback_disabled','fallback_unconfigured','disabled','ineligible','unavailable','not_run','legacy'] as $value): ?><option value="<?= $value ?>"<?= $filters['extractor']===$value?' selected':'' ?>><?= lorkhan_ui_h(ucwords(str_replace('_',' ',$value))) ?></option><?php endforeach; ?></select></label>
            <button class="pager-link" type="submit">Apply</button>
        </form></details>
    </div><div class="pager-links">
        <a class="pager-link" href="<?= lorkhan_ui_h($pageUrl(max(1,$audit['page']-1))) ?>" aria-disabled="<?= $audit['page']<=1?'true':'false' ?>"<?= $audit['page']<=1?' tabindex="-1"':'' ?>>Prev</a>
        <span class="pager-meta">Page <?= $audit['page'] ?> / <?= $audit['pages'] ?></span>
        <a class="pager-link" href="<?= lorkhan_ui_h($pageUrl(min($audit['pages'],$audit['page']+1))) ?>" aria-disabled="<?= $audit['page']>=$audit['pages']?'true':'false' ?>"<?= $audit['page']>=$audit['pages']?' tabindex="-1"':'' ?>>Next</a>
    </div></div>
    <?php if ($audit['rows']===[]): ?><div class="audit-card empty-state">No Oghma retrieval traces match these filters.</div><?php endif; ?>
    <?php foreach ($audit['rows'] as $row): $card=lorkhan_oghma_audit_card($row); ?>
    <section class="audit-card" data-search="<?= lorkhan_ui_h(mb_strtolower(implode(' ',$card['metadata']).' '.implode(' ',$card['sections']).' '.$card['details'])) ?>">
        <div class="meta-grid"><?php foreach ($card['metadata'] as $label=>$value): ?><div class="meta-pill"><div class="meta-label"><?= lorkhan_ui_h($label) ?></div><div class="meta-value"<?= isset(['Event'=>1,'Rank'=>1,'Mode'=>1][$label])?' title="'.lorkhan_ui_h(['Event'=>'Recorded input kind','Rank'=>'Recorded relevance score for each selected topic','Mode'=>'Recorded native extractor status or retrieval algorithm'][$label]).'"':'' ?>><?= lorkhan_ui_h($value) ?></div></div><?php endforeach; ?></div>
        <?php foreach ($card['sections'] as $label=>$value): ?><div class="section-label"><?= lorkhan_ui_h($label) ?></div><div class="trace-box"><?= lorkhan_ui_h($value) ?></div><?php endforeach; ?>
        <details class="native-decisions"><summary>Native retrieval decisions</summary><pre class="trace-box"><?= lorkhan_ui_h($card['details']) ?></pre></details>
    </section><?php endforeach; ?>
    <div class="audit-card empty-state" data-audit-no-match role="status" hidden>No rows on this page match your filter.</div>
</main>
