<?php
declare(strict_types=1);
$pageTitle='Narratives'; $topNavSection='roleplay'; $BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';
require __DIR__.'/tmpl/control_reader.php';
$installations=$uiRepository->rows('installations');
$profiles=array_merge($uiRepository->rows('characters'),$uiRepository->rows('player'),$uiRepository->rows('narrator'));
$playthroughs=$uiRepository->rows('playthroughs');
$diaryScopeReady=$installations!==[] && $profiles!==[] && $playthroughs!==[];
$state=lorkhan_control_state($installations);
if (!isset($_GET['installation_id'])) $state['installation']='';
if (!isset($_GET['period'])) { $state['period']='all'; $state['since']=null; }
$state['limit']=50;
$kinds=['narrator'=>'Narrator','diary'=>'Diary','summary'=>'Summary'];
if (!isset($kinds[$state['state']])) $state['state']='';
$conditions=[]; $params=[];
if ($state['installation']!=='') { $conditions[]='m.installation_id=:installation'; $params['installation']=$state['installation']; }
if ($state['since']!==null) { $conditions[]='to_timestamp(d.localts)>=CAST(:since AS timestamptz)'; $params['since']=$state['since']; }
if ($state['state']!=='') { $conditions[]='d.tags=:kind'; $params['kind']=$state['state']; }
if ($state['query']!=='') {
    $conditions[]="concat_ws(' ',p.name,d.topic,d.content) ILIKE :query";
    $params['query']='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$state['query']).'%';
}
$state=lorkhan_control_query($database,$state,
    "m.narrative_id,m.installation_id,m.profile_id,m.playthrough_id,d.tags AS kind,d.topic AS title,d.content,
        COALESCE(p.name,'Unknown') AS author,to_char(to_timestamp(d.localts) AT TIME ZONE 'UTC','DD-MM-YYYY HH24:MI:SS') AS created_utc",
    'FROM public.diarylog d JOIN lorkhan_internal.diarylog_metadata m ON m.rowid=d.rowid LEFT JOIN profiles p ON p.profile_id=m.profile_id',
    $conditions,$params,'d.localts DESC,d.rowid DESC');
$rows=$state['rows'];
// The same scoped selectors are used by manual creation and diary generation.
$scopeFields=static function(string $prefix)use($installations,$profiles,$playthroughs):void {
    foreach ([['installation_id','Installation',$installations,'installation_id','display_name'],['profile_id','Profile',$profiles,'profile_id','name'],['playthrough_id','Playthrough',$playthroughs,'playthrough_id','playthrough']] as [$name,$label,$choices,$id,$text]) {
        ?><label for="<?= $prefix.'-'.$name ?>"><?= $label ?></label><select id="<?= $prefix.'-'.$name ?>" name="<?= $name ?>" required data-narrative-scope><?php foreach ($choices as $choice): ?><option value="<?= lorkhan_ui_h($choice[$id]) ?>" data-installation="<?= lorkhan_ui_h($choice['installation_id']) ?>"><?= lorkhan_ui_h($choice[$text]) ?></option><?php endforeach; ?></select><?php
    }
};
$additionalStylesheets=['narrative-manager.css?v='.(string)filemtime(__DIR__.'/css/narrative-manager.css')];
include __DIR__.'/tmpl/head.html'; if (!$embedded) include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/narrative_manager.html.php';
?>
<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/narrative-manager.js?v=<?= filemtime(__DIR__.'/js/narrative-manager.js') ?>" defer></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
