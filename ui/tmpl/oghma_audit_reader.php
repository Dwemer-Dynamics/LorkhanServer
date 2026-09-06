<?php
declare(strict_types=1);

/** Present native retrieval evidence in the same metadata and trace sections as Herika's audit. */
function lorkhan_oghma_audit_card(array $row): array
{
    $reasons=is_array($row['reasons']??null)?$row['reasons']:[];
    $context=is_array($reasons['_context']??null)?$reasons['_context']:[];
    unset($reasons['_context']);
    $ids=is_array($row['result_ids']??null)?$row['result_ids']:[];
    $scores=is_array($row['scores']??null)?$row['scores']:[];
    $names=[];
    foreach (is_array($row['selected_topics']??null)?$row['selected_topics']:[] as $topic) {
        if (is_array($topic)) $names[(string)($topic['id']??'')]=(string)($topic['topic']??'');
    }
    $selected=[]; $ranks=[]; $signals=[]; $notes=[];
    foreach ($ids as $id) {
        $name=(string)($reasons[$id]['topic']??$names[$id]??$id);
        $selected[]=$name;
        if (isset($scores[$id]) && is_numeric($scores[$id])) $ranks[]=count($ids)>1?$name.': '.$scores[$id]:(string)$scores[$id];
    }
    foreach ($reasons as $reason) {
        if (!is_array($reason)) continue;
        $topic=(string)($reason['topic']??'');
        $signal=[];
        foreach (['signal','source','score','access_level','rank'] as $key) if (isset($reason[$key]) && is_scalar($reason[$key])) $signal[]=$key.'='.$reason[$key];
        if ($signal!==[]) $signals[]=($topic!==''?$topic.': ':'').implode(' | ',$signal);
        if (isset($reason['reason'])) $notes[]=($topic!==''?$topic.': ':'').(string)$reason['reason'];
    }
    $join=static fn(mixed $values):string=>implode(', ',array_map('strval',array_filter(is_array($values)?$values:[], 'is_scalar')));
    $snapshot=[];
    foreach (['conversation_signals'=>'conversation','race_signals'=>'race','location_signals'=>'location','denied_topics'=>'denied topics'] as $key=>$label) {
        $value=$join($context[$key]??[]); if ($value!=='') $snapshot[]=$label.'='.$value;
    }
    foreach (['master_enabled','request_eligible','fallback_eligible','racial_context_enabled','location_context_enabled'] as $key) {
        if (array_key_exists($key,$context)) $snapshot[]=$key.'='.($context[$key]?'true':'false');
    }
    return ['metadata'=>[
        'Status'=>(int)($row['result_count']??0)>0?'Matched':'No Match',
        'NPC'=>(string)($row['profile_name']??'(unknown)'),
        'Event'=>(string)($row['input_kind']??'(not recorded)'),
        'Selected Topic'=>$selected===[]?'(none)':implode(', ',$selected),
        'Rank'=>$ranks===[]?'(not recorded)':implode(', ',$ranks),
        'Mode'=>(string)($context['extractor_status']??$row['algorithm']??'(not recorded)'),
        'Entry ID'=>$ids===[]?'(n/a)':implode(', ',$ids),
        'Created'=>(string)($row['created_at']??''), 'Elapsed'=>'(not recorded)',
    ],'sections'=>[
        'Input'=>(string)($row['input_text']??$row['query']??''),
        'Extracted Topics'=>$join($context['extracted_topics']??[])?:'(none)',
        'Signals Used For Ranking'=>$signals===[]?'(not captured)':implode("\n",$signals),
        'Ranking Notes'=>$notes===[]?'(none)':implode("\n",$notes),
        'Context Snapshot'=>$snapshot===[]?'(none)':implode("\n",$snapshot),
    ],'details'=>(string)json_encode(['algorithm'=>$row['algorithm']??null,'trace_id'=>$row['retrieval_trace_id']??null,'retrieval_query'=>$row['query']??null,'context'=>$context,'decisions'=>$reasons],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)];
}
