<?php

declare(strict_types=1);

use LORKHANserver\Infrastructure\EventLogRepository;

$pageTitle = 'LORKHAN Roleplay';
$topNavSection = 'roleplay';
$BODY_CLASS = 'hub-page';
require __DIR__ . '/ui_bootstrap.php';
require __DIR__ . '/tmpl/memory_policy.php';
$roleplay = $uiRepository->roleplay();
$eventLogRepository = new EventLogRepository($database);
$eventLogState = $eventLogRepository->page([
    'installation_id'=>$_GET['installation_id']??null,'playthrough_id'=>$_GET['playthrough_id']??null,
    'page'=>$_GET['page']??1,'limit'=>$_GET['limit']??100,'event_type'=>$_GET['event_type']??'',
]);
$allowedTabs = ['eventlog', 'responselog', 'adventure', 'memory', 'diaries', 'books', 'questgen', 'backgroundlife', 'journal'];
$tabAliases = ['eventlog-tab'=>'eventlog','responses-tab'=>'responselog','memories-tab'=>'memory',
    'relationships-tab'=>'journal','relationships'=>'journal','quests'=>'journal','narratives-tab'=>'adventure',
    'journal-tab'=>'journal','books-tab'=>'books'];
$requestedTab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'eventlog';
$requestedTab = $tabAliases[$requestedTab] ?? $requestedTab;
$activeTab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'eventlog';
$memoryPolicies=$uiRepository->rows('memory_policy');
$memoryEmbeddingPolicy=$uiRepository->rows('memory_embedding_policy');
$memoryConnectors=$uiRepository->rows('llm');
$installationOptions=[];foreach($uiRepository->rows('installations')as$row){$id=(string)($row['installation_id']??'');if($id!=='')$installationOptions[$id]=(string)($row['display_name']??$id);}
$profileOptions=[];foreach(array_merge($uiRepository->rows('profiles'),$uiRepository->rows('player'))as$row){$id=(string)($row['profile_id']??'');if($id!=='')$profileOptions[$id]=(string)($row['name']??$id);}
$playthroughOptions=[];foreach($uiRepository->rows('playthroughs')as$row){$id=(string)($row['playthrough_id']??'');if($id!=='')$playthroughOptions[$id]=(string)($row['playthrough']??$id);}

/** Render one labelled scope selector shared by the memory and relationship managers. */
function lorkhan_roleplay_scope_select(string $name,string $label,array $options,string $selected=''):void
{
    static $counter=0;$counter++;$id='roleplay-'.$name.'-'.$counter;echo'<label for="'.lorkhan_ui_h($id).'">'.lorkhan_ui_h($label).'</label><select id="'.lorkhan_ui_h($id).'" name="'.lorkhan_ui_h($name).'">';
    foreach($options as$value=>$optionLabel)echo'<option value="'.lorkhan_ui_h($value).'"'.((string)$value===$selected?' selected':'').'>'.lorkhan_ui_h($optionLabel).'</option>';
    echo'</select>';
}

/** Read one persisted MiniMe policy field without trusting the shape of the stored record. */
function lorkhan_memory_embedding_field(mixed $policy,string $key,mixed $default):mixed
{
    $content=is_array($policy)?($policy['content']??null):(is_object($policy)?($policy->content??null):null);
    if(is_object($content))$content=get_object_vars($content);
    if(!is_array($content)||($content[$key]??null)===null)return $default;
    return $content[$key];
}

/** Render compact installation-scoped MiniMe retrieval controls beside the model summary policy. */
function lorkhan_roleplay_memory_embedding_policy(mixed $policy,array $installations,string $installationId,string $base,string $csrf):void
{
    if(is_array($policy)&&!isset($policy['content'])){$match=null;foreach($policy as$row)if(is_array($row)&&(string)($row['installation_id']??'')===$installationId){$match=$row;break;}$policy=$match;}
    $enabled=filter_var(lorkhan_memory_embedding_field($policy,'enabled',false),FILTER_VALIDATE_BOOL);
    $endpoint=(string)lorkhan_memory_embedding_field($policy,'endpoint','');
    $timeout=(int)lorkhan_memory_embedding_field($policy,'timeout_ms',1500);
    if($timeout<250||$timeout>5000)$timeout=1500;
    $revision=is_array($policy)?(int)($policy['current_revision']??0):0;
    echo'<section class="memory-policy-card memory-embedding-card" aria-labelledby="memory-embedding-heading">'
        .'<header><h3 id="memory-embedding-heading">Semantic memory retrieval</h3>'
        .'<span class="status-badge">'.($enabled?'On':'Off').($revision>0?' · r'.$revision:'').'</span></header>';
    if($installations===[]){echo'<p>Connect OpenMW once to configure semantic retrieval.</p></section>';return;}
    echo'<form class="management-form" method="post" action="'.lorkhan_ui_h($base.'/forms/memory-embedding-policy').'">'
        .'<input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'">'
        .'<input type="hidden" name="installation_id" value="'.lorkhan_ui_h($installationId).'">'
        .'<label class="memory-policy-toggle"><input type="checkbox" name="enabled" value="1"'
        .' aria-describedby="memory-embedding-enabled-help"'.($enabled?' checked':'').'> Use MiniMe semantic retrieval</label>'
        .'<p id="memory-embedding-enabled-help" class="memory-policy-hint">Off by default. When on, new and revised memories are indexed in the background and each prompt may make one MiniMe query. If the endpoint fails, deterministic retrieval is preserved.</p>'
        .'<label for="memory-embedding-endpoint">MiniMe endpoint</label>'
        .'<input id="memory-embedding-endpoint" name="endpoint" type="url" inputmode="url" spellcheck="false" autocomplete="off"'
        .' placeholder="http://127.0.0.1:8085" aria-describedby="memory-embedding-endpoint-help"'
        .' value="'.lorkhan_ui_h($endpoint).'">'
        .'<p id="memory-embedding-endpoint-help" class="memory-policy-hint">Required when retrieval is on. HTTP is accepted only for loopback addresses; every other endpoint must use HTTPS.</p>'
        .'<label for="memory-embedding-timeout">Query timeout</label>'
        .'<input id="memory-embedding-timeout" name="timeout_ms" type="number" min="250" max="5000" required'
        .' aria-describedby="memory-embedding-timeout-help" value="'.$timeout.'">'
        .'<p id="memory-embedding-timeout-help" class="memory-policy-hint">Milliseconds, between 250 and 5000. Default 1500.</p>'
        .'<button class="btn-base btn-primary" type="submit">Save retrieval policy</button></form>'
        .'<p class="roleplay-note">Saving this policy never calls MiniMe and does not queue existing memories. Run a backfill to index memories written earlier.</p>'
        .'<form class="memory-embedding-backfill" method="post" action="'.lorkhan_ui_h($base.'/forms/memory-embedding-backfill').'">'
        .'<input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'">'
        .'<input type="hidden" name="installation_id" value="'.lorkhan_ui_h($installationId).'">'
        .'<label for="memory-embedding-limit">Backfill batch size</label>'
        .'<input id="memory-embedding-limit" name="limit" type="number" min="1" max="500" value="100" required'
        .' aria-describedby="memory-embedding-limit-help">'
        .'<button class="btn-base" type="submit">Queue backfill</button>'
        .'<p id="memory-embedding-limit-help" class="memory-policy-hint">Queues up to this many existing memories that have no current vector. Between 1 and 500.</p></form>'
        .'<details class="memory-policy-help"><summary>How semantic retrieval works</summary>'
        .'<p>Indexing runs as a background job, so no browser action here contacts the endpoint. Retrieval adds at most one MiniMe query per prompt and merges its result with the deterministic ranking.</p>'
        .'<p>When MiniMe is unreachable or times out, the prompt keeps its deterministic retrieval, so memories are never lost. Turning this off stops new queries and leaves stored vectors in place.</p></details></section>';
}

/** Render the editable CHIM-style memory manager while preserving LORKHAN retrieval provenance. */
function lorkhan_roleplay_memory_manager(array $rows,array $installations,array $profiles,array $playthroughs,string $base,string $csrf,
    array $memoryPolicies,array $memoryConnectors,string $webRoot,mixed $memoryEmbeddingPolicy=null):void
{
    $selected=is_string($_GET['policy_installation_id']??null)?$_GET['policy_installation_id']:'';
    if(!isset($installations[$selected]))$selected=(string)(array_key_first($installations)??'');
    $policy=[];foreach($memoryPolicies as$item)if(($item['installation_id']??'')===$selected){$policy=$item;break;}
    $connectors=[];foreach($memoryConnectors as$item)if(($item['installation_id']??'')===$selected)$connectors[(string)$item['configuration_id']]=(string)$item['name'];
    echo'<div class="memory-policy-columns">';
    lorkhan_roleplay_memory_policy($policy,$installations,$selected,$connectors,$base,$csrf,$webRoot);
    lorkhan_roleplay_memory_embedding_policy($memoryEmbeddingPolicy,$installations,$selected,$base,$csrf);
    echo'</div>';
    echo'<details class="management-section"><summary>Add or rebuild memories</summary><div class="profile-grid"><form class="management-form" method="post" action="'.lorkhan_ui_h($base.'/forms/memory').'"><fieldset><legend>Add memory</legend>';
    lorkhan_roleplay_scope_select('installation_id','Installation',$installations);lorkhan_roleplay_scope_select('profile_id','Profile',$profiles);lorkhan_roleplay_scope_select('playthrough_id','Playthrough',$playthroughs);
    echo'<label for="memory-tier">Tier</label><select id="memory-tier" name="tier"><option value="recent">Recent</option><option value="mid">Middle term</option><option value="long">Long term</option></select>';
    echo'<label for="memory-content">Memory content</label><textarea id="memory-content" name="content" required></textarea><label for="memory-provenance">Provenance</label><input id="memory-provenance" name="provenance" value="management" required><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base btn-primary" type="submit">Add memory</button></fieldset></form>';
    echo'<form class="management-form" method="post" action="'.lorkhan_ui_h($base.'/forms/memory-rebuild').'"><fieldset><legend>Rebuild retrieval index</legend>';
    lorkhan_roleplay_scope_select('installation_id','Installation',$installations);lorkhan_roleplay_scope_select('profile_id','Profile',$profiles);lorkhan_roleplay_scope_select('playthrough_id','Playthrough',$playthroughs);
    echo'<p>Recalculate deterministic lexical and vector fields for the selected playthrough without changing memory text.</p><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base" type="submit">Rebuild memories</button></fieldset></form></div></details>';
    if($rows===[]){echo'<p class="empty-state">No memories are available yet.</p>';return;}echo'<div class="profile-grid">';
    foreach($rows as$row){$id=(string)($row['memory_id']??'');$revision=(int)($row['current_revision']??1);$revisions=is_array($row['revisions']??null)?$row['revisions']:[];echo'<article class="profile-card"><header><div><span class="connector-kind">'.lorkhan_ui_h($row['tier']??'memory').'</span><h3>'.lorkhan_ui_h($row['occurred_at']??'Memory').'</h3></div><span class="status-badge">r'.lorkhan_ui_h($revision).' · '.lorkhan_ui_h($row['eligibility']??'authored').'</span></header><p>'.nl2br(lorkhan_ui_h($row['content']??'')).'</p><details><summary>Revision history ('.count($revisions).')</summary>';if($revisions===[])echo'<p>No revision history is available.</p>';else{echo'<ol class="revision-list">';foreach($revisions as$history)echo'<li><strong>r'.lorkhan_ui_h($history['revision']??'').'</strong> '.lorkhan_ui_h($history['reason']??'revised').' <small>'.lorkhan_ui_h($history['created_at']??'').'</small></li>';echo'</ol>';}echo'</details><details><summary>Edit memory</summary><form class="management-form" method="post" action="'.lorkhan_ui_h($base.'/forms/memory-revise').'"><fieldset><legend>Save memory</legend><label for="memory-edit-'.lorkhan_ui_h($id).'">Memory content</label><textarea id="memory-edit-'.lorkhan_ui_h($id).'" name="content" required>'.lorkhan_ui_h($row['content']??'').'</textarea><input type="hidden" name="memory_id" value="'.lorkhan_ui_h($id).'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base btn-primary" type="submit">Save memory</button></fieldset></form></details>' . lorkhan_roleplay_memory_summary_control($row,$base,$csrf) . '<form class="danger-form" method="post" action="'.lorkhan_ui_h($base.'/forms/memory-delete').'"><input type="hidden" name="memory_id" value="'.lorkhan_ui_h($id).'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base btn-danger" type="submit">Delete memory</button></form></article>';}
    echo'</div>';
}

/** Render bounded manual relationship creation, editing, and audited deletion controls. */
function lorkhan_roleplay_relationship_manager(array $rows,array $installations,array $profiles,array $playthroughs,string $base,string $csrf):void
{
    echo'<details class="management-section"><summary>Add relationship</summary><form class="management-form" method="post" action="'.lorkhan_ui_h($base.'/forms/relationships').'"><fieldset><legend>Add relationship</legend>';
    lorkhan_roleplay_scope_select('installation_id','Installation',$installations);lorkhan_roleplay_scope_select('profile_id','Profile',$profiles);lorkhan_roleplay_scope_select('playthrough_id','Playthrough',$playthroughs);
    echo'<label for="relationship-actor">Actor identity JSON</label><textarea id="relationship-actor" name="content_json" required>{"kind":"npc","record_id":"fargoth","content_file":"Morrowind.esm","display_name":"Fargoth"}</textarea><label for="relationship-disposition">Disposition</label><input id="relationship-disposition" name="disposition" type="number" value="0" required><label for="relationship-affinity">Affinity</label><input id="relationship-affinity" name="affinity" type="number" value="0" required><label for="relationship-reason">Reason</label><input id="relationship-reason" name="reason" value="management" required><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base btn-primary" type="submit">Add relationship</button></fieldset></form></details>';
    if($rows===[]){echo'<p class="empty-state">No relationships are available yet.</p>';return;}echo'<div class="profile-grid">';
    foreach($rows as$row){$id=(string)($row['relationship_id']??'');$identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];$identityJson=json_encode($identity===[]?(object)[]:$identity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        echo'<article class="profile-card"><header><div><span class="connector-kind">Relationship</span><h3>'.lorkhan_ui_h($row['actor']??'Actor').'</h3></div><span class="status-badge">'.lorkhan_ui_h($row['source_mode']??'manual').'</span></header><dl><dt>Disposition</dt><dd>'.lorkhan_ui_h($row['disposition']??0).'</dd><dt>Affinity</dt><dd>'.lorkhan_ui_h($row['affinity']??0).'</dd><dt>Updated</dt><dd>'.lorkhan_ui_h($row['updated_at']??'').'</dd></dl><details><summary>Edit relationship</summary><form class="management-form" method="post" action="'.lorkhan_ui_h($base.'/forms/relationships').'"><fieldset><legend>Save relationship</legend><label for="relationship-identity-'.lorkhan_ui_h($id).'">Actor identity JSON</label><textarea id="relationship-identity-'.lorkhan_ui_h($id).'" name="content_json" required>'.lorkhan_ui_h($identityJson).'</textarea><label for="relationship-disposition-'.lorkhan_ui_h($id).'">Disposition</label><input id="relationship-disposition-'.lorkhan_ui_h($id).'" name="disposition" type="number" value="'.lorkhan_ui_h($row['disposition']??0).'" required><label for="relationship-affinity-'.lorkhan_ui_h($id).'">Affinity</label><input id="relationship-affinity-'.lorkhan_ui_h($id).'" name="affinity" type="number" value="'.lorkhan_ui_h($row['affinity']??0).'" required><label for="relationship-reason-'.lorkhan_ui_h($id).'">Reason</label><input id="relationship-reason-'.lorkhan_ui_h($id).'" name="reason" value="management edit" required>';
        foreach(['installation_id','profile_id','playthrough_id']as$field)echo'<input type="hidden" name="'.lorkhan_ui_h($field).'" value="'.lorkhan_ui_h($row[$field]??'').'">';
        echo'<input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base btn-primary" type="submit">Save relationship</button></fieldset></form></details><form class="danger-form" method="post" action="'.lorkhan_ui_h($base.'/forms/relationship-delete').'"><input type="hidden" name="relationship_id" value="'.lorkhan_ui_h($id).'"><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base btn-danger" type="submit">Delete relationship</button></form></article>';}
    echo'</div>';
}

/** Render the CHIM Event Log controls and table while routing writes through LORKHAN browser security. */
function lorkhan_roleplay_eventlog(array $state,string $apiPath,string $csrf,bool $autoRefresh):void
{
    $scope=is_array($state['scope']??null)?$state['scope']:[];$pagination=is_array($state['pagination']??null)?$state['pagination']:[];
    $installation=(string)($scope['installation_id']??'');$playthrough=(string)($scope['playthrough_id']??'');
    echo'<div id="eventlog-app" data-eventlog-api="'.lorkhan_ui_h($apiPath).'" data-eventlog-csrf="'.lorkhan_ui_h($csrf).'" data-installation-id="'.lorkhan_ui_h($installation).'" data-playthrough-id="'.lorkhan_ui_h($playthrough).'" data-page="'.lorkhan_ui_h($pagination['current_page']??1).'" data-limit="'.lorkhan_ui_h($pagination['limit']??100).'" data-auto-refresh="'.($autoRefresh?'true':'false').'">';
    echo'<div class="roleplay-description"><span class="roleplay-description-icon" aria-hidden="true">&#x1F4DD;</span><strong>Events:</strong> Raw log of in-game events that provide context to the AI. These events are filtered and selectively added to prompts based on relevance.</div>';
    echo'<div class="roleplay-note"><span aria-hidden="true">&#x2139;&#xFE0F;</span><strong>Note:</strong> Not all events are added to AI context. Hidden and suppressed entries remain available in immutable LORKHAN source traces.</div>';
    if($scope!==[])echo'<div class="eventlog-scope"><strong>'.lorkhan_ui_h($scope['installation_name']??'Installation').'</strong><span>'.lorkhan_ui_h($scope['playthrough_name']??'Playthrough').'</span></div>';
    echo'<div class="roleplay-toolbar eventlog-toolbar"><div class="eventlog-live-controls"><button type="button" class="roleplay-button '.($autoRefresh?'':'active').'" data-eventlog-live>'.($autoRefresh?'&#x23F8;&#xFE0F; Stop Live':'Auto Refresh').'</button><span class="eventlog-live-indicator" data-eventlog-live-indicator'.($autoRefresh?'':' hidden').'>LIVE</span></div><div class="delete-controls"><button type="button" class="roleplay-button danger" data-eventlog-delete-selected hidden>Delete Selected (<span data-eventlog-selected-count>0</span>)</button><select data-eventlog-delete-preset><option value="5">Delete Latest 5</option><option value="10">Delete Latest 10</option><option value="20">Delete Latest 20</option><option value="50">Delete Latest 50</option><option value="100">Delete Latest 100</option><option value="all">Delete ALL</option></select><button type="button" class="roleplay-button danger" data-eventlog-delete>Delete</button></div></div>';
    echo'<div data-eventlog-status role="status"></div>';
    $rows=is_array($state['data']??null)?$state['data']:[];
    $count=lorkhan_eventlog_count_label(count($rows),$pagination);
    echo'<div class="roleplay-list-controls"><div class="pagination-shape" data-eventlog-pagination>';
    lorkhan_eventlog_pagination($pagination);
    echo'</div><p class="roleplay-result-count" data-eventlog-count>'.lorkhan_ui_h($count).'</p>';
    echo'<div class="eventlog-hide-controls"><label>Hide:<select data-eventlog-hide><option value="">Hide event...</option>';
    foreach(($state['event_types']??[])as$type){$value=(string)($type['type']??'');if($value!=='')echo'<option value="'.lorkhan_ui_h($value).'">'.lorkhan_ui_h($value).'</option>';}
    echo'</select></label><span data-eventlog-hidden>';
    foreach(($state['hidden_types']??[])as$type)echo'<button type="button" class="eventlog-hidden-chip" data-eventlog-show-type="'.lorkhan_ui_h($type).'">'.lorkhan_ui_h($type).' &times;</button>';
    echo'</span></div></div><div id="eventlog-table-container" class="roleplay-data table-responsive" data-eventlog-table>';
    lorkhan_eventlog_table($rows);
    // Herika repeats its pager under the table so a long page never strands the control.
    echo'</div><div class="roleplay-list-controls roleplay-list-footer"><div class="pagination-shape" data-eventlog-pagination>';
    lorkhan_eventlog_pagination($pagination);
    echo'</div><p class="roleplay-result-count" data-eventlog-count>'.lorkhan_ui_h($count).'</p></div></div>';
}

/** Describe the visible slice of the paged event log using only counts the repository already returned. */
function lorkhan_eventlog_count_label(int $shown,array $pagination):string
{
    $total=max(0,(int)($pagination['total_records']??0));
    $page=max(1,(int)($pagination['current_page']??1));
    $pages=max(1,(int)($pagination['total_pages']??0));
    return 'Showing '.$shown.' of '.$total.' event'.($total===1?'':'s').' · Page '.$page.' of '.$pages;
}

/** Render CHIM smart pagination without embedding executable script in the CSP-locked page. */
function lorkhan_eventlog_pagination(array $pagination):void
{
    $page=max(1,(int)($pagination['current_page']??1));$pages=max(0,(int)($pagination['total_pages']??0));
    if($pages===0){echo'<button type="button" class="active" disabled>1</button>';return;}
    if($page>1)echo'<button type="button" data-eventlog-page="'.($page-1).'">Previous</button>';
    $numbers=$pages<=10?range(1,$pages):array_values(array_unique(array_merge([1],range(max(2,$page-2),min($pages-1,$page+2)),[$pages])));
    $previous=0;foreach($numbers as$number){if($previous&&$number>$previous+1)echo'<span class="pagination-ellipsis">...</span>';echo'<button type="button" data-eventlog-page="'.$number.'"'.($number===$page?' class="active"':'').'>'.$number.'</button>';$previous=$number;}
    if($page<$pages)echo'<button type="button" data-eventlog-page="'.($page+1).'">Next</button>';
}

/** Render the exact CHIM event columns with all stored text escaped. */
function lorkhan_eventlog_table(array $rows):void
{
    echo'<table class="eventlog-table"><thead><tr><th><input type="checkbox" data-eventlog-select-all aria-label="Select all events"></th><th>Event</th><th>Events</th><th>People Present</th><th>Tamrielic Time</th><th>Time (UTC)</th><th>ROWID</th></tr></thead><tbody>';
    if($rows===[])echo'<tr class="eventlog-empty"><td colspan="7">No roleplay events have been recorded yet.</td></tr>';
    foreach($rows as$row){$id=(int)($row['rowid']??0);$chat=($row['type']??'')==='chat';echo'<tr data-eventlog-row="'.$id.'"><td><input type="checkbox" class="event-checkbox" data-eventlog-rowid="'.$id.'" aria-label="Select event '.$id.'"></td><td'.($chat?' class="eventlog-chat"':'').'>'.lorkhan_ui_h($row['type']??'').'</td><td'.($chat?' class="eventlog-chat"':'').'>'.nl2br(lorkhan_ui_h($row['data']??'')).'</td><td>'.lorkhan_ui_h($row['people']??'').'</td><td>'.lorkhan_ui_h($row['game_time']??'—').'</td><td>'.lorkhan_ui_h($row['time_utc']??'').'</td><td><button type="button" class="eventlog-row-delete" data-eventlog-delete-row="'.$id.'" title="Delete event">'.$id.' &#x1F5D1;&#xFE0F;</button></td></tr>';}
    echo'</tbody></table>';
}
$additionalStylesheets=['lorkhan-pages.css?v='.(string)filemtime(__DIR__.'/css/lorkhan-pages.css'),'herika-roleplay.css?v='.(string)filemtime(__DIR__.'/css/herika-roleplay.css')];
$includeManagementStyles=false;
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<link rel="stylesheet" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/css/main.css">
<link rel="stylesheet" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/css/hub-navigation.css?v=<?php echo lorkhan_ui_h((string)filemtime(__DIR__.'/css/hub-navigation.css')); ?>">
<main class="container-fluid events-memories-page">
    <?php if(($_GET['status']??'')==='saved'): ?><p class="lorkhan-status" role="status">Changes saved.</p>
    <?php elseif(($_GET['status']??'')==='summary-requested'): ?><p class="lorkhan-status" role="status">Summary requested. Check Jobs for progress.</p>
    <?php elseif(($_GET['status']??'')==='summary-failed'): ?><p class="lorkhan-status" role="status">The previous summary job failed. Check the connector and retry it in Jobs.</p>
    <?php elseif(($_GET['status']??'')==='embedding-saved'): ?><p class="lorkhan-status" role="status">Semantic retrieval policy saved. Existing memories are not queued until you run a backfill.</p>
    <?php elseif(($_GET['status']??'')==='embeddings-queued'): ?><p class="lorkhan-status" role="status">Queued <?php echo (int)($_GET['queued']??0); ?> memories for background embedding. Check Jobs for progress.</p>
    <?php elseif(($_GET['status']??'')==='embedding-backfill-empty'): ?><p class="lorkhan-status" role="status">No memories needed embedding, so nothing was queued.</p>
    <?php elseif(($_GET['status']??'')==='embedding-backfill-failed'): ?><p class="lorkhan-status" role="status">The embedding backfill could not be queued. Check the MiniMe endpoint and retry it.</p><?php endif; ?>
    <div class="tab-container">
        <?php include __DIR__ . '/tmpl/events_memories_navigation.php'; ?>

        <?php
        $panels = [
            'eventlog' => ['Events', $roleplay['events'], 'No source events have been recorded yet.'],
            'responselog' => ['AI Responses', $roleplay['responses'], 'No AI dialogue has been recorded yet.'],
            'adventure' => ['Adventure Log', $roleplay['narratives'], 'No adventure narratives are available yet.'],
            'memory' => ['Memories', $roleplay['memories'], 'No memories are available yet.'],
            'diaries' => ['LORKHAN Diaries', $roleplay['narratives'], 'No diary narratives are available yet.'],
            'books' => ['Books', $roleplay['books'], 'No books have been observed during an LORKHAN session yet.'],
            'journal' => ['Morrowind Journal', $roleplay['journal'], 'No Morrowind journal entries have been received from OpenMW yet.'],
        ];
        foreach ($panels as $tabId => [$heading, $rows, $emptyMessage]):
            $descriptions = [
                'eventlog' => 'Raw log of in-game events that provide context to the AI. These events are filtered and selectively added to prompts based on relevance.',
                'responselog' => 'AI responses produced by the bounded dialogue pipeline, including their delivery and terminal state.',
                'adventure' => 'Narrative summaries and important moments from the active Morrowind playthrough.',
                'memory' => 'Recent, middle-term, and long-term memories used by the scoped retrieval pipeline.',
                'diaries' => 'Versioned LORKHAN diary narratives written for the active playthrough.',
                'books' => 'Books observed through typed OpenMW context and retained for roleplay reference.',
                'journal' => 'Morrowind journal entries received from the OpenMW client.',
            ];
        ?>
            <section id="<?php echo lorkhan_ui_h($tabId); ?>-tab" class="tab-content<?php echo $activeTab === $tabId ? ' active' : ''; ?>">
                <?php if($tabId==='eventlog'){lorkhan_roleplay_eventlog($eventLogState,$managementBasePath.'/api/v1/eventlog',$csrf,isset($_GET['autorefresh'])&&$_GET['autorefresh']==='true');}else{
                    // Static tabs expose one bounded snapshot; do not imply that the UI can page it.
                    $recordCount=count($rows);
                    $countLabel='Showing '.$recordCount.' bounded record'.($recordCount===1?'':'s');
                ?><div class="tab-panel-inner roleplay-panel" data-roleplay-panel data-roleplay-total="<?php echo $recordCount; ?>"><h2 class="visually-hidden"><?php echo lorkhan_ui_h($heading); ?></h2><div class="roleplay-description"><span class="roleplay-description-icon" aria-hidden="true">&#x1F4DD;</span><strong><?php echo lorkhan_ui_h($heading); ?>:</strong> <?php echo lorkhan_ui_h($descriptions[$tabId]); ?></div><div class="roleplay-note"><span aria-hidden="true">&#x2139;&#xFE0F;</span><strong>Note:</strong> Browser tables show persisted typed records. Only bounded, relevant records are added to AI context.</div><div class="roleplay-toolbar"><button type="button" class="roleplay-button active" data-roleplay-refresh>Auto Refresh</button><div class="delete-controls"><select disabled aria-label="Delete records"><option>Delete...</option><option>Delete Latest 20</option><option>Delete Latest 50</option><option>Delete Latest 100</option><option>Delete ALL</option></select><button type="button" class="roleplay-button danger" disabled>Delete</button><?php echo lorkhan_ui_feature_badge('roleplay.destructive', true); ?></div></div><div class="roleplay-list-controls"><p class="roleplay-result-count" role="status" data-roleplay-count><?php echo lorkhan_ui_h($countLabel); ?></p><label>Filter:<input type="search" placeholder="Search <?php echo lorkhan_ui_h(strtolower($heading)); ?>..." data-roleplay-search></label></div><?php
                    if(in_array($tabId,['adventure','diaries'],true))echo'<p class="roleplay-actions"><a class="roleplay-button roleplay-button-link" href="'.lorkhan_ui_h($webRoot.'/ui/narrative_manager.php').'">Manage narratives</a></p>';
                ?><div class="roleplay-data" data-roleplay-data><?php
                    if($tabId==='memory')lorkhan_roleplay_memory_manager($rows,$installationOptions,$profileOptions,$playthroughOptions,$managementBasePath,$csrf,$memoryPolicies,$memoryConnectors,$webRoot,$memoryEmbeddingPolicy??null);
                    else lorkhan_ui_table($rows,$emptyMessage);
                ?></div><div class="roleplay-list-controls roleplay-list-footer"><p class="roleplay-result-count" data-roleplay-count><?php echo lorkhan_ui_h($countLabel); ?></p></div></div><?php } ?>
            </section>
        <?php endforeach; ?>
        <?php foreach (['questgen'=>'roleplay.quest-manager','backgroundlife'=>'roleplay.background-life'] as $tabId=>$featureId): if($activeTab!==$tabId)continue;$feature=lorkhan_ui_feature($featureId); ?>
        <section id="<?php echo lorkhan_ui_h($tabId); ?>-tab" class="tab-content active"><div class="tab-panel-inner feature-placeholder-panel"><div class="feature-placeholder-heading"><h2><?php echo lorkhan_ui_h($feature['title']); ?></h2><?php echo lorkhan_ui_feature_badge($featureId); ?></div><p><?php echo lorkhan_ui_h($feature['description']); ?></p><div class="feature-placeholder-controls"><?php foreach($feature['controls']as$control)echo lorkhan_ui_placeholder_control((string)$control,$featureId); ?></div></div></section>
        <?php endforeach; ?>
    </div>
</main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/roleplay.js?v=<?php echo lorkhan_ui_h((string)filemtime(__DIR__.'/js/roleplay.js')); ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
