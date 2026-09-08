<?php

declare(strict_types=1);

use LorkhanServer\Infrastructure\EventLogRepository;

$pageTitle = 'Roleplay';
$topNavSection = 'roleplay';
$BODY_CLASS = 'hub-page';
require __DIR__ . '/ui_bootstrap.php';
require __DIR__ . '/tmpl/memory_policy.php';
require __DIR__ . '/tmpl/roleplay_memory_table.php';
require __DIR__ . '/tmpl/control_reader.php';
require __DIR__ . '/tmpl/roleplay_reader.php';
require __DIR__ . '/tmpl/roleplay_logs.php';
$eventLogRepository = new EventLogRepository($database);
$eventLogState = $eventLogRepository->page([
    'installation_id'=>$_GET['installation_id']??$_GET['policy_installation_id']??null,'playthrough_id'=>$_GET['playthrough_id']??null,
    'page'=>$_GET['page']??1,'limit'=>$_GET['limit']??100,'event_type'=>$_GET['event_type']??'',
]);
$allowedTabs = ['eventlog', 'responselog', 'adventure', 'memory', 'diaries', 'books', 'journal'];
$roleplay = $uiRepository->roleplay($eventLogState['scope']??[]);
$tabAliases = ['eventlog-tab'=>'eventlog','responses-tab'=>'responselog','memories-tab'=>'memory',
    'relationships-tab'=>'journal','relationships'=>'journal','narratives-tab'=>'adventure',
    'journal-tab'=>'journal','books-tab'=>'books'];
$requestedTab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'eventlog';
$requestedTab = $tabAliases[$requestedTab] ?? $requestedTab;
$activeTab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'eventlog';
$pageTitle = match ($activeTab) {
    'responselog'=>'AI Responses', 'adventure'=>'Adventure Log', 'memory'=>'Memories',
    'diaries'=>'Diaries', 'books'=>'Books', 'journal'=>'Journal', default=>'Roleplay',
};
$memoryPolicies=$uiRepository->rows('memory_policy');
$memoryEmbeddingPolicy=$uiRepository->rows('memory_embedding_policy');
$memoryConnectors=$uiRepository->rows('llm');
$installationOptions=[];foreach($uiRepository->rows('installations')as$row){$id=(string)($row['installation_id']??'');if($id!=='')$installationOptions[$id]=(string)($row['display_name']??$id);}
$profileOptions=[];foreach(array_merge($uiRepository->rows('profiles'),$uiRepository->rows('player'))as$row){$id=(string)($row['profile_id']??'');if($id!=='')$profileOptions[$id]=(string)($row['name']??$id);}
$playthroughOptions=[];foreach($uiRepository->rows('playthroughs')as$row){$id=(string)($row['playthrough_id']??'');if($id!=='')$playthroughOptions[$id]=(string)($row['playthrough']??$id);}
$readerState = null; $readerPreview = [];
if (in_array($activeTab, ['adventure', 'diaries', 'books', 'journal', 'responselog'], true)) {
    $readerState = lorkhan_roleplay_reader_state($database, $installationOptions, $activeTab);
    $readerInstallation = $readerState['installation'];
    if ($readerInstallation !== '') {
        $readerNarrator = $productRepository->narratorProfileForInstallation($readerInstallation);
        $readerPreview = \LorkhanServer\Application\SpeechPreviewCatalog::options(
            $productRepository->listRevisioned('tts_provider', $readerInstallation), $productRepository->connectorVoiceCatalog(),
            (string) ($config['voice_storage_path'] ?? ''),
            (string) ($productRepository->connectorForInstallation($readerInstallation, 'tts_provider')['configuration_id'] ?? ''),
            \LorkhanServer\Application\SpeechPreviewCatalog::narratorVoice($readerNarrator),
            \LorkhanServer\Application\SpeechPreviewCatalog::narratorConnector($readerNarrator));
    }
}

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
    array $memoryPolicies,array $memoryConnectors,string $webRoot,mixed $memoryEmbeddingPolicy=null,array $scope=[]):void
{
    $selected=(string)($scope['installation_id']??'');
    if(!isset($installations[$selected]))$selected=(string)(array_key_first($installations)??'');
    $playthrough=(string)($scope['playthrough_id']??'');
    $rows=array_values(array_filter($rows,static fn(array $row):bool=>($row['installation_id']??'')===$selected && ($row['playthrough_id']??'')===$playthrough));
    $policy=[];foreach($memoryPolicies as$item)if(($item['installation_id']??'')===$selected){$policy=$item;break;}
    $connectors=[];foreach($memoryConnectors as$item)if(($item['installation_id']??'')===$selected)$connectors[(string)$item['configuration_id']]=(string)$item['name'];
    $embedding=[];foreach(is_array($memoryEmbeddingPolicy)?$memoryEmbeddingPolicy:[] as $item)if(($item['installation_id']??'')===$selected){$embedding=$item;break;}
    $summariesOn=filter_var($policy['content']['enabled']??false,FILTER_VALIDATE_BOOL);
    $embeddingsOn=filter_var(lorkhan_memory_embedding_field($embedding,'enabled',false),FILTER_VALIDATE_BOOL);
    // Show the service address without credentials or query-string secrets.
    $embeddingUrl=parse_url((string)lorkhan_memory_embedding_field($embedding,'endpoint',''));
    $embeddingAddress=is_array($embeddingUrl)&&isset($embeddingUrl['host'])
        ?($embeddingUrl['scheme']??'http').'://'.$embeddingUrl['host'].(isset($embeddingUrl['port'])?':'.$embeddingUrl['port']:'').($embeddingUrl['path']??''):'Not configured';
    echo '<section class="memory-overview" aria-labelledby="memory-overview-title"><div class="memory-overview-head"><div class="memory-overview-main"><h3 id="memory-overview-title">Memory System Configuration</h3><p><strong>🧠 Memories:</strong> Memory summaries with scope, participants and period coverage. Review long-term context and edit summaries below.</p></div><div class="memory-actions">';
    echo '<button type="button" class="roleplay-button memory-sync" data-memory-sync data-endpoint="'.lorkhan_ui_h($base.'/api/v1/roleplay/sync-memories').'" data-installation="'.lorkhan_ui_h($selected).'" data-playthrough="'.lorkhan_ui_h($playthrough).'" data-csrf="'.lorkhan_ui_h($csrf).'"'.(!$summariesOn||$playthrough===''?' disabled':'').'>Sync Memory Summaries Now</button>';
    lorkhan_roleplay_clear_button(['installation'=>$selected,'playthrough'=>$playthrough],'memories',$base,$csrf);
    echo '</div></div><div class="memory-status-bar"><span>Model Summaries <b class="'.($summariesOn?'is-on':'is-off').'">'.($summariesOn?'Enabled':'Disabled').'</b></span><span>TXT2VEC (Embeddings) <b class="'.($embeddingsOn?'is-on':'is-off').'">'.($embeddingsOn?'Enabled':'Disabled').'</b> <span class="memory-status-url">URL: '.lorkhan_ui_h($embeddingAddress).'</span></span><a class="roleplay-button memory-config-link" href="'.lorkhan_ui_h($webRoot.'/ui/global_settings.php?installation_id='.$selected).'">Configure Settings</a></div>';
    if (!$embeddingsOn) echo '<p class="memory-status-warning"><strong>Warning:</strong> TXT2VEC is disabled. Vector search is unavailable; lexical memory retrieval remains active.</p>';
    echo '<p role="status" data-roleplay-maintenance-status></p></section><details class="memory-scope"><summary>Current playthrough</summary><form method="get" class="reader-filters"><input type="hidden" name="tab" value="memory">';
    lorkhan_roleplay_scope_select('installation_id','Installation',$installations,$selected);
    lorkhan_roleplay_scope_select('playthrough_id','Playthrough',$playthroughs,$playthrough);
    echo '<button type="submit" class="roleplay-button">Show</button></form></details><details class="memory-advanced-settings"><summary>Advanced memory tools</summary><div class="memory-policy-columns">';
    lorkhan_roleplay_memory_policy($policy,$installations,$selected,$connectors,$base,$csrf,$webRoot);
    lorkhan_roleplay_memory_embedding_policy($memoryEmbeddingPolicy,$installations,$selected,$base,$csrf);
    echo'</div>';
    echo'<details class="management-section"><summary>Add or rebuild memories</summary><div class="profile-grid"><form class="management-form" method="post" action="'.lorkhan_ui_h($base.'/forms/memory').'"><fieldset><legend>Add memory</legend>';
    lorkhan_roleplay_scope_select('installation_id','Installation',$installations);lorkhan_roleplay_scope_select('profile_id','Profile',$profiles);lorkhan_roleplay_scope_select('playthrough_id','Playthrough',$playthroughs);
    echo'<label for="memory-tier">Tier</label><select id="memory-tier" name="tier"><option value="recent">Recent (source memory)</option><option value="mid" selected>Middle term</option><option value="long">Long term</option></select>';
    echo'<label for="memory-content">Memory content</label><textarea id="memory-content" name="content" required></textarea><label for="memory-provenance">Provenance</label><input id="memory-provenance" name="provenance" value="management" required><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base btn-primary" type="submit">Add memory</button></fieldset></form>';
    echo'<form class="management-form" method="post" action="'.lorkhan_ui_h($base.'/forms/memory-rebuild').'"><fieldset><legend>Rebuild retrieval index</legend>';
    lorkhan_roleplay_scope_select('installation_id','Installation',$installations);lorkhan_roleplay_scope_select('profile_id','Profile',$profiles);lorkhan_roleplay_scope_select('playthrough_id','Playthrough',$playthroughs);
    echo'<p>Recalculate deterministic lexical and vector fields for the selected playthrough without changing memory text.</p><input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'"><button class="btn-base" type="submit">Rebuild memories</button></fieldset></form></div></details>';
    echo '</details>';
    lorkhan_roleplay_memory_table($rows,$profiles,$playthroughs,$base,$csrf);
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
    echo'<div class="roleplay-description"><span class="roleplay-description-icon" aria-hidden="true">&#x1F4DD;</span><strong>Events:</strong> Raw log of in-game events for inspection and, where applicable, AI context. Events used in prompts are filtered by relevance.</div>';
    echo'<div class="roleplay-note event-log-note"><span aria-hidden="true">&#x2139;&#xFE0F;</span><strong>Note:</strong> Not all events will show up in AI context. Any blacklist settings will not be used for context. This is a raw log of some of the more relevant events.</div>';
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
    echo'</div><p class="roleplay-result-count" data-eventlog-count>'.lorkhan_ui_h($count).'</p></div>';
    if($scope!==[])echo'<details class="eventlog-scope"><summary>Current playthrough</summary><strong>'.lorkhan_ui_h($scope['installation_name']??'Installation').'</strong><span>'.lorkhan_ui_h($scope['playthrough_name']??'Playthrough').'</span><p>Hidden and suppressed entries remain available in immutable LORKHAN source traces.</p></details>';
    echo'</div>';
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
    if ($rows === []) return;
    echo'<table class="eventlog-table table table-striped table-bordered table-sm"><thead><tr><th><input type="checkbox" data-eventlog-select-all aria-label="Select all events"></th><th>Event</th><th>Events</th><th>People Present</th><th><a href="https://en.uesp.net/wiki/Lore:Calendar" target="_blank" rel="noopener noreferrer">Tamrielic Time</a></th><th>Time (UTC)</th><th>Record</th></tr></thead><tbody>';
    foreach($rows as$row){$id=(int)($row['rowid']??0);$chat=($row['type']??'')==='chat';echo'<tr data-eventlog-row="'.$id.'"><td><input type="checkbox" class="event-checkbox" data-eventlog-rowid="'.$id.'" aria-label="Select event '.$id.'"></td><td'.($chat?' class="eventlog-chat"':'').'>'.lorkhan_ui_h($row['type']??'').'</td><td'.($chat?' class="eventlog-chat"':'').'>'.nl2br(lorkhan_ui_h($row['data']??'')).'</td><td>'.lorkhan_ui_h($row['people']??'').'</td><td>'.lorkhan_ui_h($row['game_time']??'—').'</td><td>'.lorkhan_ui_h($row['time_utc']??'').'</td><td><button type="button" class="eventlog-row-delete" data-eventlog-delete-row="'.$id.'" title="Delete event" aria-label="Delete event '.$id.'">'.$id.' <span class="eventlog-trash-icon" aria-hidden="true"></span></button></td></tr>';}
    echo'</tbody></table>';
}
$additionalStylesheets=['lorkhan-pages.css?v='.(string)filemtime(__DIR__.'/css/lorkhan-pages.css'),'herika-roleplay.css?v='.(string)filemtime(__DIR__.'/css/herika-roleplay.css')];
$additionalStylesheets[] = 'roleplay-logs.css?v='.(string)filemtime(__DIR__.'/css/roleplay-logs.css');
$additionalStylesheets[] = 'roleplay-reader.css?v='.(string)filemtime(__DIR__.'/css/roleplay-reader.css');
$includeManagementStyles=false;
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<link rel="stylesheet" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/css/main.css">
<link rel="stylesheet" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/css/hub-navigation.css?v=<?php echo lorkhan_ui_h((string)filemtime(__DIR__.'/css/hub-navigation.css')); ?>">
<main class="container-fluid events-memories-page<?= $activeTab === 'responselog' ? ' ai-response-page' : '' ?>">
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
            'adventure' => ['Adventure Log', [], 'No adventure narratives are available yet.'],
            'memory' => ['Memories', $roleplay['memories'], 'No memories are available yet.'],
            'diaries' => ['LORKHAN Diaries', [], 'No diary narratives are available yet.'],
            'books' => ['Books', $roleplay['books'], 'No books have been observed during an LORKHAN session yet.'],
            'journal' => ['Morrowind Journal', $roleplay['journal'], 'No Morrowind journal entries have been received from OpenMW yet.'],
        ];
        foreach ($panels as $tabId => [$heading, $rows, $emptyMessage]):
        ?>
            <section id="<?php echo lorkhan_ui_h($tabId); ?>-tab" class="tab-content<?php echo $activeTab === $tabId ? ' active' : ''; ?>">
                <?php if(in_array($tabId,['adventure','diaries','books','journal','responselog'],true)){
                    if($tabId===$activeTab && $readerState!==null){
                        if(in_array($tabId,['responselog','books','journal'],true))lorkhan_roleplay_log_table($readerState,$installationOptions,$tabId,$webRoot,$managementBasePath,$csrf);
                        else {echo '<div class="calendar-reader-viewport" tabindex="0" role="region" aria-label="'.lorkhan_ui_h($heading).'">';lorkhan_roleplay_reader($readerState,$installationOptions,$tabId,$webRoot,$managementBasePath,$csrf,$readerPreview);echo '</div>';}
                    }
                }elseif($tabId==='memory'){
                    if($tabId===$activeTab){echo '<div class="tab-panel-inner roleplay-panel"><h2 class="visually-hidden">Memories</h2>';
                        lorkhan_roleplay_memory_manager($rows,$installationOptions,$profileOptions,$playthroughOptions,$managementBasePath,$csrf,$memoryPolicies,$memoryConnectors,$webRoot,$memoryEmbeddingPolicy??null,$eventLogState['scope']??[]);
                        echo '</div>';}
                }elseif($tabId==='eventlog'){lorkhan_roleplay_eventlog($eventLogState,$managementBasePath.'/api/v1/eventlog',$csrf,isset($_GET['autorefresh'])&&$_GET['autorefresh']==='true');} ?>
            </section>
        <?php endforeach; ?>
    </div>
</main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/roleplay.js?v=<?php echo lorkhan_ui_h((string)filemtime(__DIR__.'/js/roleplay.js')); ?>"></script>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/roleplay-reader.js?v=<?= (int) filemtime(__DIR__.'/js/roleplay-reader.js') ?>"></script>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/roleplay-logs.js?v=<?= (int) filemtime(__DIR__.'/js/roleplay-logs.js') ?>"></script>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/roleplay-maintenance.js?v=<?= (int) filemtime(__DIR__.'/js/roleplay-maintenance.js') ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
