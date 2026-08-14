<?php

declare(strict_types=1);

$pageTitle='Oghma Audit';$topNavSection='control';$BODY_CLASS='hub-page oghma-audit-shell';require __DIR__.'/ui_bootstrap.php';
$installations=$uiRepository->rows('installations');$filters=['installation_id'=>(string)($_GET['installation_id']??''),
    'search'=>(string)($_GET['search']??''),'matched'=>(string)($_GET['matched']??'all'),
    'extractor'=>(string)($_GET['extractor']??'all'),'page'=>(int)($_GET['page']??1),'page_size'=>(int)($_GET['page_size']??50)];
$audit=$uiRepository->oghmaAudit($filters);$filters=$audit['filters']+['page_size'=>$audit['page_size']];
$additionalStylesheets=['herika-oghma-runtime.css?v='.(string)filemtime(__DIR__.'/css/herika-oghma-runtime.css')];
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
$pageUrl=static function(int$page)use($filters,$embedded):string{return'?'.http_build_query($filters+['page'=>$page,'embed'=>$embedded?'1':'0']);};
$prettyJson=static fn(mixed$value):string=>(string)json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$titleCase=static fn(string$value):string=>ucwords(strtolower(trim(str_replace(['_','-'],' ',$value))));
/* Friendly labels only; the raw status values below are the stored filter values and must not change. */
$extractorLabels=['grounded'=>'Matched locally','no_match'=>'No topics found','fallback_succeeded'=>'Matched with connector help',
    'fallback_unresolved'=>'Connector found nothing','fallback_failed'=>'Connector failed','fallback_disabled'=>'Connector help off',
    'fallback_unconfigured'=>'Connector not configured','disabled'=>'Oghma turned off','ineligible'=>'Request not eligible',
    'unavailable'=>'Unavailable','not_run'=>'Not run','legacy'=>'Older trace'];
$extractorLabel=static function(string$status)use($extractorLabels,$titleCase):string{
    $status=trim($status);
    return $status===''?'Unknown':($extractorLabels[$status]??($titleCase($status)?:'Unknown'));
};
$accessLabels=['advanced'=>'Detailed','basic'=>'Basic','denied'=>'Not shared'];
$accessLabel=static function(string$access)use($accessLabels,$titleCase):string{
    $access=strtolower(trim($access));
    return $access===''?'Shared':($accessLabels[$access]??($titleCase($access)?:'Shared'));
};
/* Summary tiles are derived from the rows already fetched for this page; no extra query is issued,
   so every count except the filtered total is explicitly labelled as page-scoped. */
$pageRequests=count($audit['rows']);$pageShared=0;$pageArticles=0;$pageConnector=0;
foreach($audit['rows']as$summaryRow){
    $summaryContext=is_array($summaryRow['reasons']['_context']??null)?$summaryRow['reasons']['_context']:[];
    $summaryCount=(int)$summaryRow['result_count'];
    if($summaryCount>0)$pageShared++;
    $pageArticles+=$summaryCount;
    if((string)($summaryContext['extractor_status']??'')==='fallback_succeeded')$pageConnector++;
}
?>
<main class="oghma-runtime-page">
    <header class="runtime-header"><div><h1>Oghma Audit</h1><p>See which topics Oghma found in each conversation and what knowledge it gave the NPC.</p></div><span class="runtime-count"><?php echo almsivi_ui_h($audit['total']); ?> records</span></header>
    <section class="runtime-metrics" aria-label="Oghma audit summary">
        <div class="metric-panel"><span class="metric"><?php echo almsivi_ui_h($audit['total']); ?></span><span class="metric-label">Audited requests</span><span class="metric-note">matching these filters</span></div>
        <div class="metric-panel"><span class="metric"><?php echo $pageShared; ?></span><span class="metric-label">Gave the NPC knowledge</span><span class="metric-note">of <?php echo $pageRequests; ?> on this page</span></div>
        <div class="metric-panel"><span class="metric"><?php echo $pageArticles; ?></span><span class="metric-label">Articles shared</span><span class="metric-note">on this page</span></div>
        <div class="metric-panel"><span class="metric"><?php echo $pageConnector; ?></span><span class="metric-label">Matched with connector help</span><span class="metric-note">on this page</span></div>
    </section>
    <form class="runtime-filters" method="get">
        <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label>Installation<select name="installation_id"><option value="">All installations</option><?php foreach($installations as$row): ?><option value="<?php echo almsivi_ui_h($row['installation_id']); ?>"<?php echo $filters['installation_id']===$row['installation_id']?' selected':''; ?>><?php echo almsivi_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label>
        <label>Search<input name="search" maxlength="100" value="<?php echo almsivi_ui_h($filters['search']); ?>" placeholder="Query, NPC, topic or reason"></label>
        <label>Results<select name="matched"><?php foreach(['all'=>'All','matched'=>'Matched','unmatched'=>'Unmatched']as$value=>$label): ?><option value="<?php echo $value; ?>"<?php echo $filters['matched']===$value?' selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
        <label>Topic match<select name="extractor"><?php foreach(['all'=>'All']+$extractorLabels as$value=>$label): ?><option value="<?php echo almsivi_ui_h($value); ?>"<?php echo $filters['extractor']===$value?' selected':''; ?>><?php echo almsivi_ui_h($label); ?></option><?php endforeach; ?></select></label>
        <label>Rows<select name="page_size"><?php foreach([25,50,100]as$size): ?><option<?php echo $audit['page_size']===$size?' selected':''; ?>><?php echo $size; ?></option><?php endforeach; ?></select></label>
        <button class="btn-base" type="submit">Apply filters</button>
    </form>
    <?php if($audit['rows']===[]): ?><section class="runtime-empty">No Oghma retrieval traces match these filters.</section><?php endif; ?>
    <section class="trace-list">
    <?php foreach($audit['rows']as$row): $reasons=is_array($row['reasons']??null)?$row['reasons']:[];$context=is_array($reasons['_context']??null)?$reasons['_context']:[];unset($reasons['_context']);$selected=is_array($row['selected_topics']??null)?$row['selected_topics']:[];$resultCount=(int)$row['result_count']; ?>
        <article class="trace-card">
            <header><div><h2><?php echo almsivi_ui_h($extractorLabel((string)($context['extractor_status']??'legacy'))); ?> &middot; <?php echo almsivi_ui_h($row['profile_name']??'Unbound profile'); ?></h2><p><?php echo almsivi_ui_h($row['created_at']); ?></p></div><span class="trace-result <?php echo $resultCount>0?'matched':'unmatched'; ?>"><?php echo $resultCount>0?almsivi_ui_h($resultCount).' article'.($resultCount===1?'':'s').' shared':'Nothing shared'; ?></span></header>
            <div class="trace-row"><strong>Conversation</strong><span><?php echo almsivi_ui_h($row['query']); ?></span></div>
            <div class="trace-row"><strong>Topics found</strong><span><?php echo almsivi_ui_h(implode(', ',(array)($context['extracted_topics']??[]))?:'None'); ?></span></div>
            <div class="trace-row"><strong>Knowledge shared</strong><?php if($selected!==[]): ?><div class="selected-topics"><?php foreach($selected as$topic): $decision=$reasons[$topic['id']??'']??[];$category=trim((string)($topic['category']??'')); ?><span><?php echo almsivi_ui_h($topic['topic']??'Unknown'); ?><small><?php echo almsivi_ui_h($accessLabel((string)(is_array($decision)?($decision['access_level']??''):''))); ?><?php if($category!==''): ?> &middot; <?php echo almsivi_ui_h($category); ?><?php endif; ?></small></span><?php endforeach; ?></div><?php else: ?><span class="trace-none">Nothing shared with this NPC</span><?php endif; ?></div>
            <details class="trace-technical">
                <summary>Technical details</summary>
                <div class="signal-grid">
                    <div><strong>Algorithm</strong><span><?php echo almsivi_ui_h($row['algorithm']); ?></span></div>
                    <div><strong>Extractor status</strong><span><?php echo almsivi_ui_h($context['extractor_status']??'legacy'); ?></span></div>
                    <div><strong>Race injection</strong><span><?php echo almsivi_ui_h(implode(', ',(array)($context['race_signals']??[]))?:'None'); ?></span></div>
                    <div><strong>Location injection</strong><span><?php echo almsivi_ui_h(implode(', ',(array)($context['location_signals']??[]))?:'None'); ?></span></div>
                    <div><strong>Request eligible</strong><span><?php echo ($context['request_eligible']??false)?'Yes':'No'; ?></span></div>
                    <div><strong>Master switch</strong><span><?php echo ($context['master_enabled']??true)?'Enabled':'Disabled'; ?></span></div>
                    <div><strong>Fallback eligible</strong><span><?php echo ($context['fallback_eligible']??false)?'Yes':'No'; ?></span></div>
                    <div><strong>Denied topics</strong><span><?php echo almsivi_ui_h(implode(', ',(array)($context['denied_topics']??[]))?:'None'); ?></span></div>
                </div>
                <div class="trace-diagnostics">
                <?php foreach(['grounded_matches'=>'Grounded matches','grounded_rejections'=>'Grounded rejections','tag_decisions'=>'Tag decisions','suggested_topics'=>'Connector suggestions']as$key=>$label): $items=is_array($context[$key]??null)?$context[$key]:[]; ?>
                    <details><summary><?php echo almsivi_ui_h($label); ?> (<?php echo count($items); ?>)</summary><pre><?php echo almsivi_ui_h($prettyJson($items)); ?></pre></details>
                <?php endforeach; ?>
                </div>
                <details><summary>Full retrieval decisions (<?php echo count($reasons); ?>)</summary><div class="decision-table"><table><thead><tr><th>Topic</th><th>Signal</th><th>Source</th><th>Score</th><th>Access</th><th>Selected</th><th>Reason</th></tr></thead><tbody><?php foreach($reasons as$decision):if(!is_array($decision))continue; ?><tr><td><?php echo almsivi_ui_h($decision['topic']??''); ?></td><td><?php echo almsivi_ui_h($decision['signal']??''); ?></td><td><?php echo almsivi_ui_h($decision['source']??''); ?></td><td><?php echo almsivi_ui_h($decision['score']??''); ?></td><td><?php echo almsivi_ui_h($decision['access_level']??''); ?></td><td><?php echo ($decision['selected']??false)?'Yes':'No'; ?></td><td><?php echo almsivi_ui_h($decision['reason']??''); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
            </details>
        </article>
    <?php endforeach; ?>
    </section>
    <nav class="runtime-pagination" aria-label="Oghma audit pages"><?php if($audit['page']>1): ?><a href="<?php echo almsivi_ui_h($pageUrl($audit['page']-1)); ?>">Previous</a><?php endif; ?><span>Page <?php echo $audit['page']; ?> of <?php echo $audit['pages']; ?></span><?php if($audit['page']<$audit['pages']): ?><a href="<?php echo almsivi_ui_h($pageUrl($audit['page']+1)); ?>">Next</a><?php endif; ?></nav>
</main>
<?php include __DIR__.'/tmpl/footer.html'; ?>
