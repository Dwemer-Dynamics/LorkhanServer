<?php
declare(strict_types=1);
$pageTitle='Relationship LLM Logs';$topNavSection='control';$BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';
$additionalStylesheets=['lorkhan-pages.css'];
$installations=[];foreach($uiRepository->rows('installations') as $row)$installations[(string)$row['installation_id']]=(string)($row['display_name']??$row['installation_id']);
$selected=is_string($_GET['installation_id']??null)?$_GET['installation_id']:'';
if(!isset($installations[$selected]))$selected=(string)(array_key_first($installations)??'');
$rows=$selected===''?[]:$uiRepository->rows('relationships',$selected);
$history=$selected===''?[]:$uiRepository->rows('relationship_logs',$selected);
$relationshipTypes=\LorkhanServer\Application\RelationshipType::available(array_values(array_filter(
    array_column($rows,'relationship_type'),'is_string')));
$owners=[];$actors=[];$playthroughs=[];$buildOwners=[];
foreach($selected===''?[]:$uiRepository->rows('relationship_profiles',$selected) as $row){
    $identity=$row['actor_identity']??[];
    $owners[(string)$row['profile_id']]=(string)$row['name'];
    if(!in_array($identity['kind']??null,['player','narrator'],true))$buildOwners[(string)$row['profile_id']]=(string)$row['name'];
    if(!in_array($identity['kind']??null,['npc','creature','player'],true))continue;
    if(isset($identity['refnum']['index'],$identity['refnum']['content_file'])&&($identity['content_file']??'')!=='')
        $actors[(string)$row['profile_id']]=(string)$row['name'].' — '.($identity['record_id']??'').' #'.$identity['refnum']['index'];
}
foreach($selected===''?[]:$productRepository->listRevisioned('playthrough',$selected) as $row)$playthroughs[(string)$row['id']]=(string)$row['name'];
$buildProfile=is_string($_GET['profile_id']??null)&&isset($buildOwners[$_GET['profile_id']])?$_GET['profile_id']:'';
$buildPlaythrough=is_string($_GET['playthrough_id']??null)&&isset($playthroughs[$_GET['playthrough_id']])?$_GET['playthrough_id']:'';
$buildLimit=filter_var($_GET['history_limit']??100,FILTER_VALIDATE_INT);
if(!in_array($buildLimit,[10,25,50,100],true))$buildLimit=100;
$buildScope=['installation_id'=>$selected,'profile_id'=>$buildProfile,'playthrough_id'=>$buildPlaythrough];
$buildScoped=$selected!==''&&$buildProfile!==''&&$buildPlaythrough!=='';
$buildJobs=$buildScoped?(new \LorkhanServer\Infrastructure\RelationshipBuildRepository($database))->recentJobs($buildScope):[];
$buildQuery=$buildScope+['history_limit'=>$buildLimit];if($embedded)$buildQuery['embed']='1';
$buildUrl=$webRoot.'/ui/relationship_logs.php?'.http_build_query($buildQuery).'#relationship-builder';
$buildStatus=is_string($_GET['status']??null)?$_GET['status']:'';
$notice=match($buildStatus){
    'saved'=>'Changes saved.',
    'relationship_build_requested'=>'Relationship build requested. Reload for its current status.',
    'relationship_build_pending'=>'A build is already pending for this NPC and playthrough. Wait for it to finish.',
    'relationship_build_locked'=>'Relationship Lock is on. Unlock it in the NPC editor or its Core Profile, then try again.',
    'relationship_build_no_connector'=>'Choose a Relationship LLM in the NPC editor or its Core Profile, then try again.',
    'relationship_build_no_history'=>'No eligible played conversations were found in this window for that NPC and playthrough.',
    'relationship_build_too_large'=>'This history is too large to analyze. Choose fewer conversations.',
    'relationship_build_ambiguous_owner'=>'This profile covers more than one actor in the selected history. Choose fewer conversations or review its bindings.',
    'relationship_build_ambiguous_records'=>'More than one saved record matches a participant. Review the current records before building.',
    'relationship_build_request_conflict'=>'That request was already used with different settings. Reload the page and try again.',
    'relationship_revision_conflict'=>'This record changed. The latest values are shown, including Custom Info. Unsaved edits were not kept; review the saved values before trying again.',
    'relationship_already_exists'=>'This actor already has a relationship in that profile and playthrough. Edit the existing record.',
    default=>'',
};

/** Render the scoped selectors without adding a second settings or script system. */
function lorkhan_relationship_select(string $name,string $label,array $options,string $selected=''):void
{
    static $counter=0;$id='relationship-'.$name.'-'.++$counter;
    echo '<label for="'.lorkhan_ui_h($id).'">'.lorkhan_ui_h($label).'</label><select id="'.lorkhan_ui_h($id).'" name="'.lorkhan_ui_h($name).'">';
    foreach($options as $value=>$text)echo '<option value="'.lorkhan_ui_h($value).'"'.((string)$value===$selected?' selected':'').'>'.lorkhan_ui_h($text).'</option>';
    echo '</select>';
}

/** Show the scored state without exposing a raw internal JSON document in every history cell. */
function lorkhan_relationship_state(mixed $value):string
{
    if(!is_array($value)||$value===[])return 'No previous state';
    if(($value['deleted']??false)===true)return 'Deleted';
    return 'Disposition '.($value['disposition']??'—').'; affinity '.($value['affinity']??'—')
        .(isset($value['relationship_type'])?'; type '.(string)$value['relationship_type']:'')
        .(isset($value['revision'])?' (r'.$value['revision'].')':'')
        .(($value['custom_info_changed']??false)===true?'; Custom Info updated':'');
}

/** Present a canonical lowercase type without rewriting a player-created label. */
function lorkhan_relationship_type_label(string $type):string
{
    return ucfirst($type===''?'neutral':$type);
}

$logType=is_string($_GET['type']??null)?$_GET['type']:'';
$logPage=filter_var($_GET['page']??1,FILTER_VALIDATE_INT)?:1;
$logLimit=filter_var($_GET['limit']??50,FILTER_VALIDATE_INT)?:50;
$logs=(new \LorkhanServer\Infrastructure\RelationshipLogRepository($database))->page($selected,$logType,$logPage,$logLimit);
$logUrl=static fn(int $page):string=>'?'.http_build_query(['installation_id'=>$selected,'type'=>$logs['type'],'limit'=>$logs['limit'],'page'=>$page,'embed'=>$embedded?'1':'0']);
$additionalStylesheets[]='relationship-logs.css?v='.filemtime(__DIR__.'/css/relationship-logs.css');
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/relationship_logs.html.php';
?>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/relationship-logs.js?v=<?= filemtime(__DIR__.'/js/relationship-logs.js') ?>"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
