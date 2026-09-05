<?php
declare(strict_types=1);
$pageTitle='Request Logs';$topNavSection='control';$BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';require __DIR__.'/tmpl/control_reader.php';
$state=lorkhan_control_state($uiRepository->rows('installations'));
$states=['accepted'=>'Accepted','processing'=>'Processing','complete'=>'Complete','failed'=>'Failed','cancelled'=>'Cancelled'];
if(!isset($states[$state['state']]))$state['state']='';
$conditions=[];$params=[];
if($state['installation']!==''){$conditions[]='trace.installation_id=:installation';$params['installation']=$state['installation'];}
if($state['since']!==null){$conditions[]='trace.created_at>=CAST(:since AS timestamptz)';$params['since']=$state['since'];}
if($state['state']!==''){$conditions[]='t.state=:state';$params['state']=$state['state'];}
if($state['query']!==''){$conditions[]='(p.name ILIKE :name_query OR trace.request_id::text ILIKE :id_query)';$params['name_query']='%'.$state['query'].'%';$params['id_query']='%'.$state['query'].'%';}
$from="FROM prompt_traces trace LEFT JOIN profiles p ON p.profile_id=COALESCE(trace.selected_profile_id,trace.profile_id) LEFT JOIN turns t ON t.turn_id=trace.turn_id LEFT JOIN turn_provider_snapshots snapshot ON snapshot.turn_id=trace.turn_id";
$select="trace.prompt_trace_id,trace.request_id,trace.turn_id,trace.algorithm,trace.input_bytes,trace.input_sha256,trace.created_at,trace.truncated,p.name AS profile_name,t.state AS turn_state,snapshot.source_manifest#>'{message,_prompt,_messages}' AS prompt_messages";
$state=lorkhan_control_query($database,$state,$select,$from,$conditions,$params,'trace.created_at DESC,trace.prompt_trace_id');
$sectionQuery=$database->prepare('SELECT section_order,section_key,inclusion_reason,source_characters,estimated_tokens,redacted_preview FROM prompt_trace_sections WHERE prompt_trace_id=:trace ORDER BY section_order LIMIT 32');
$attemptQuery=$database->prepare("SELECT provider_name,provider_kind,model,operation,state,duration_ms,error_code,metadata->'usage' AS usage FROM provider_attempts WHERE turn_id=:turn ORDER BY started_at,provider_attempt_id LIMIT 100");
foreach($state['rows']as&$row){
    $sectionQuery->execute(['trace'=>$row['prompt_trace_id']]);$row['sections']=$sectionQuery->fetchAll(PDO::FETCH_ASSOC);
    $attemptQuery->execute(['turn'=>$row['turn_id']]);$row['attempts']=$attemptQuery->fetchAll(PDO::FETCH_ASSOC);
    $row['prompt_messages']=lorkhan_control_prompt_messages($row['prompt_messages']);
}unset($row);
$additionalStylesheets=['control-reader.css?v='.(string)filemtime(__DIR__.'/css/control-reader.css')];include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="control-reader"><header class="control-reader-heading"><div><h1>Request Logs</h1><p>Frozen prompt messages, section coverage and measured provider usage. Provider settings and credentials are never included.</p></div></header>
<?php lorkhan_control_filters($state,$states);lorkhan_control_pagination($state); ?>
<div class="control-reader-table-wrap"><table><thead><tr><th>Time / Request</th><th>Profile / State</th><th>Input</th><th>Request Details</th></tr></thead><tbody>
<?php foreach($state['rows']as$row): ?>
<tr><td><?= lorkhan_ui_h($row['created_at']) ?><small><code><?= lorkhan_ui_h($row['request_id']) ?></code></small></td><td><?= lorkhan_ui_h($row['profile_name']??'Unknown') ?><small class="state"><?= lorkhan_ui_h($row['turn_state']??'unknown') ?></small></td><td><?= number_format((int)$row['input_bytes']/1024,1) ?> KiB<small><?= count($row['sections']) ?> sections</small><?php if(filter_var($row['truncated'],FILTER_VALIDATE_BOOL)): ?><small>Input budget reached</small><?php endif; ?></td><td>
<details><summary>View Prompt</summary><p class="muted">Read-only messages captured for this request. Legacy records without message snapshots show section metadata below. Display is bounded to 128 KiB.</p>
<?php foreach($row['prompt_messages']as$message): ?><section class="control-reader-section"><h3><?= lorkhan_ui_h(ucfirst($message['role'])) ?></h3><pre><?= lorkhan_ui_h($message['content']) ?></pre></section><?php endforeach; ?>
<?php if($row['prompt_messages']===[]): ?><p class="muted">No frozen message text is available for this request.</p><?php endif; ?>
</details>
<details><summary>Section Coverage</summary><table><thead><tr><th>Order / Section</th><th>Included</th><th>Characters</th><th>Estimated Tokens</th></tr></thead><tbody><?php foreach($row['sections']as$section): ?><tr><td><?= (int)$section['section_order'] ?> · <?= lorkhan_ui_h(str_replace('_',' ',$section['section_key'])) ?></td><td><?= lorkhan_ui_h($section['inclusion_reason']) ?></td><td><?= number_format((int)$section['source_characters']) ?></td><td><?= number_format((int)$section['estimated_tokens']) ?></td></tr><?php endforeach; ?></tbody></table><small class="muted">Input SHA-256: <?= lorkhan_ui_h($row['input_sha256']) ?></small></details>
<details><summary>Provider Attempts &amp; Tokens</summary><?php if($row['attempts']===[]): ?><p class="muted">No correlated attempts recorded.</p><?php endif; ?>
<?php foreach($row['attempts']as$attempt): $usage=is_string($attempt['usage'])?json_decode($attempt['usage'],true):[];if(!is_array($usage))$usage=[]; ?>
<section class="control-reader-section"><h3><?= lorkhan_ui_h($attempt['provider_name']) ?> · <?= lorkhan_ui_h($attempt['model']??$attempt['provider_kind']) ?></h3><p><?= lorkhan_ui_h($attempt['operation']) ?> · <?= lorkhan_ui_h($attempt['state']) ?> · <?= $attempt['duration_ms']===null?'Duration not recorded':number_format((int)$attempt['duration_ms']).' ms' ?></p><p class="muted"><?php foreach(['prompt_tokens'=>'Input','completion_tokens'=>'Output','total_tokens'=>'Total']as$key=>$label): ?><?= $label ?> tokens: <?= is_numeric($usage[$key]??null)?number_format((int)$usage[$key]):'Unknown' ?> &nbsp; <?php endforeach; ?></p><p class="muted">Recorded cost: <?= is_numeric($usage['cost_usd']??null)?'$'.number_format((float)$usage['cost_usd'],6):'Unknown' ?></p><?php if($attempt['error_code']!==null): ?><p class="muted">Error: <?= lorkhan_ui_h($attempt['error_code']) ?></p><?php endif; ?></section>
<?php endforeach; ?></details>
</td></tr>
<?php endforeach; ?>
<?php if($state['rows']===[]): ?><tr><td colspan="4" class="control-reader-empty">No requests match these filters.</td></tr><?php endif; ?>
</tbody></table></div><?php lorkhan_control_pagination($state); ?></main><?php include __DIR__.'/tmpl/footer.html'; ?>
