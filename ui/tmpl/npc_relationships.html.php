<?php
// NPC relationship controls share the existing revisioned handlers; opening the editor never starts AI work.
$relUiRoot = preg_replace('#/manage$#', '', $managementBasePath) ?: '/LorkhanServer';
$relPlaythroughs=is_array($playthroughOptions[$installationId]??null)?$playthroughOptions[$installationId]:[];
$relPlaythrough=is_string($_GET['rel_playthrough']??null)&&isset($relPlaythroughs[$_GET['rel_playthrough']])?$_GET['rel_playthrough']:(string)(array_key_first($relPlaythroughs)??'');
$relScope=['profile_id'=>$profileId,'playthrough_id'=>$relPlaythrough];
$relData=$row['relationship_editor_data']??null;
if(!is_array($relData)){
    $relReady=!$creating&&$relPlaythrough!=='';
    $relData=['records'=>$relReady?$GLOBALS['uiRepository']->rows('relationships',$installationId,$relScope):[],
        'history'=>$relReady?$GLOBALS['uiRepository']->rows('relationship_logs',$installationId,$relScope):[],
        'actors'=>$creating?[]:$GLOBALS['uiRepository']->rows('relationship_profiles',$installationId),
        'build_jobs'=>$relReady?(new \LorkhanServer\Infrastructure\RelationshipBuildRepository($GLOBALS['database']))->recentJobs(['installation_id'=>$installationId]+$relScope):[],
        'clear_snapshot'=>$relReady?$GLOBALS['productRepository']->relationshipClearSnapshot(['installation_id'=>$installationId]+$relScope):['count'=>0,'token'=>md5('')]];
}
$relRecords=$relData['records'];$relHistory=$relData['history'];$relActors=[];
foreach($relData['actors']as$actor){
    $actorIdentity=$actor['actor_identity']??[];
    if(($actor['profile_id']??'')===$profileId||!in_array($actorIdentity['kind']??'',['npc','creature','player'],true))continue;
    if(!isset($actorIdentity['refnum']['index'],$actorIdentity['refnum']['content_file'])||($actorIdentity['content_file']??'')==='')continue;
    $relActors[(string)$actor['profile_id']]=(string)$actor['name'].' — '.($actorIdentity['record_id']??'');
}
$relTypes=array_merge(\LorkhanServer\Application\RelationshipType::BUILT_INS,array_values(array_diff(\LorkhanServer\Application\RelationshipType::available(array_column($relRecords,'relationship_type')),\LorkhanServer\Application\RelationshipType::BUILT_INS)));
$relTypeIcons=array_combine(\LorkhanServer\Application\RelationshipType::BUILT_INS,['💘','🤝','👨‍👩‍👧','💼','⚔️','🗡️','➖','☠️','💔','💰','🛡️','🙏','🔥','📚','🎓','🧹','🛒','👑','💗','💢','🔪','👀','⭐','💚','😨','🌀','😲','😤','😢','🥹','🧐','🙄']);
$relTypeOptions=static function(string$current)use($relTypes,$relTypeIcons):void{foreach($relTypes as$type)echo'<option value="'.lorkhan_ui_h($type).'"'.($type===$current?' selected':'').'>'.($relTypeIcons[$type]??'🏷️').' '.lorkhan_ui_h(ucfirst($type)).'</option>';};
$relKey='npc-rel-'.substr(hash('sha256',$profileId),0,12);
$relHidden=['_csrf'=>$csrf,'installation_id'=>$installationId,'profile_id'=>$profileId,'playthrough_id'=>$relPlaythrough,'relationship_page'=>'npc']+$listState;
$relReturnQuery=['rel_profile'=>$profileId,'rel_playthrough'=>$relPlaythrough];
foreach($listState as$key=>$value)if(str_starts_with($key,'ui_'))$relReturnQuery[substr($key,3)]=$value;
$relHiddenFields=static function(array$values):void{foreach($values as$key=>$value)echo'<input type="hidden" name="'.lorkhan_ui_h($key).'" value="'.lorkhan_ui_h((string)$value).'">';};
$relDefaults=[];
foreach($coreProfileRows as$core){
    $scope=(string)($core['installation_id']??'');$id=(string)$core['core_profile_id'];
    $relDefaults[$scope][$id]=($core['content']['settings_overrides']['relationship']['locked']??false)===true;
    if(filter_var($core['default_npc']??false,FILTER_VALIDATE_BOOL))$relDefaults[$scope]['']=$relDefaults[$scope][$id];
}
$relOwn=$content['relationship']['locked']??null;$relLockValue=is_bool($relOwn)?($relOwn?'1':'0'):'inherit';
$relLocked=is_bool($relOwn)?$relOwn:($relDefaults[$installationId][$coreProfileId]??false);
$relTiers=[[91,'Bonded','#22c55e'],[76,'Devoted','#4ade80'],[56,'Fond','#86efac'],[31,'Friendly','#a7f3d0'],[6,'Acquaintance','#d9f99d'],[-5,'Neutral','#e5e7eb'],[-30,'Wary','#fde68a'],[-55,'Cold','#fed7aa'],[-75,'Resentful','#fca5a5'],[-90,'Hateful','#f87171'],[-100,'Hostile','#ef4444']];
$relStatus=is_string($_GET['rel_profile']??null)&&$_GET['rel_profile']===$profileId&&is_string($_GET['status']??null)?$_GET['status']:'';
$relNotice=match($relStatus){
    'saved'=>'Relationship changes saved.',
    'npc_relationships_saved'=>'NPC and relationship changes saved.',
    'relationships_cleared'=>'The confirmed relationships were cleared. Their change history was kept.',
    'relationship_revision_conflict'=>'This relationship changed. Review the latest values before saving again.',
    'relationship_already_exists'=>'This actor already has a relationship. Edit the saved row.',
    'relationship_build_requested'=>'Build requested. Reload to see its result.',
    'relationship_build_pending'=>'A relationship build is already pending.',
    'relationship_build_locked'=>'Relationship Lock is on. Unlock it in the NPC settings or Core Profile before building.',
    'relationship_build_no_connector'=>'Choose a Relationship LLM in the NPC settings or Core Profile before building.',
    'relationship_build_no_history'=>'No eligible played conversations were found in this playthrough.',
    'relationship_build_too_large'=>'Choose fewer conversations; this history exceeds the build limit.',
    'relationship_build_ambiguous_owner','relationship_build_ambiguous_records'=>'The recorded actor identity is ambiguous. Review its bindings and saved relationships.',
    'relationship_build_request_conflict'=>'Reload before requesting another build.',
    default=>'',
};

// Saved records and new drafts use the same row markup and labelled controls.
$relRenderRow=static function(array$rel,string$relForm)use($relTiers,$relTypeOptions,$relHistory):void{ ?>
    <?php $relId=(string)$rel['relationship_id'];$relTier=$relTiers[5];foreach($relTiers as$tier)if((int)$rel['affinity']>=$tier[0]){$relTier=$tier;break;} ?>
    <tr data-npc-rel-row data-rel-form="<?=$relForm?>">
        <td><span class="npc-rel-target" title="<?=lorkhan_ui_h(($rel['actor_identity']['record_id']??'').' · '.($rel['actor_identity']['content_file']??''))?>"><?=lorkhan_ui_h($rel['actor'])?></span></td>
        <td><input class="npc-rel-aff" aria-label="Affinity with <?=lorkhan_ui_h($rel['actor'])?>" type="number" name="affinity" min="-100" max="100" required value="<?=(int)$rel['affinity']?>" form="<?=$relForm?>"></td>
        <td><span class="npc-rel-tier" style="color:<?=$relTier[2]?>"><?=$relTier[1]?></span></td>
        <td><select class="npc-rel-type" aria-label="Relationship type with <?=lorkhan_ui_h($rel['actor'])?>" name="relationship_type" required form="<?=$relForm?>"><?php $relTypeOptions($rel['relationship_type']); ?></select></td>
        <td class="npc-rel-signals">
            <?php $latest=null;foreach($relHistory as$item)if($item['relationship_id']===$relId){$latest=$item;break;}$savedDetails=$rel['details']??[];if(array_key_exists('note',$savedDetails))$latest=$savedDetails['note']!==''?['reason'=>$savedDetails['note']]:null; ?>
            <?php if($latest!==null): ?><div class="npc-rel-last" title="<?=lorkhan_ui_h($latest['reason'])?>">Last: <?=lorkhan_ui_h(mb_strimwidth($latest['reason'],0,56,'…'))?></div><?php endif; ?>
            <?php foreach(['positive'=>['Best','+'],'negative'=>['Worst','']]as$sign=>[$label,$prefix]): $memoryKey=$sign==='positive'?'best':'worst';$memoryOverride=array_key_exists($memoryKey,$savedDetails);if($memoryOverride){if($savedDetails[$memoryKey]==='')continue;$rel['strongest_'.$sign.'_reason']=$savedDetails[$memoryKey];}elseif($rel['strongest_'.$sign.'_delta']===null)continue; ?><div class="npc-rel-<?=$sign?>" title="<?=lorkhan_ui_h($rel['strongest_'.$sign.'_reason'])?>"><?=$label?> <?=$memoryOverride?'':$prefix.(int)$rel['strongest_'.$sign.'_delta']?>: <?=lorkhan_ui_h(mb_strimwidth($rel['strongest_'.$sign.'_reason'],0,48,'…'))?></div><?php endforeach; ?>
            <?php if($latest===null&&($savedDetails['best']??$rel['strongest_positive_reason']??'')===''&&($savedDetails['worst']??$rel['strongest_negative_reason']??'')===''): ?><span class="npc-rel-empty">No signals</span><?php endif; ?>
        </td>
        <td class="npc-rel-actions"><button type="submit" form="<?=$relForm?>" title="Save this relationship" aria-label="Save relationship with <?=lorkhan_ui_h($rel['actor'])?>">💾</button><button type="button" data-rel-details="<?=$relForm?>-details" aria-expanded="false" title="Edit details" aria-label="Edit details for <?=lorkhan_ui_h($rel['actor'])?>">✏️</button><button type="submit" class="npc-rel-delete" form="<?=$relForm?>-delete" title="Remove relationship" aria-label="Remove relationship with <?=lorkhan_ui_h($rel['actor'])?>">×</button></td>
    </tr>
    <?php $relDetails=$rel['details']??[];$relDetails+=['relation'=>'','note'=>$latest['reason']??'','best'=>$rel['strongest_positive_reason']??'','worst'=>$rel['strongest_negative_reason']??'']; ?>
    <tr id="<?=$relForm?>-details" hidden><td colspan="6"><div class="npc-rel-details">
        <?php foreach(['relation'=>'Relationship Detail','note'=>'Recent Interaction','best'=>'Best Memory','worst'=>'Worst Memory']as$key=>$label): ?><label><?=$label?><input name="details[<?=$key?>]" maxlength="1024" value="<?=lorkhan_ui_h($relDetails[$key])?>" form="<?=$relForm?>"></label><?php endforeach; ?>
        <label>Disposition<input type="number" name="disposition" min="-100" max="100" required value="<?=(int)$rel['disposition']?>" form="<?=$relForm?>"></label>
        <label>Reason<input name="reason" value="Manual edit" maxlength="1024" required form="<?=$relForm?>"></label>
        <label class="npc-rel-wide">Custom Info<textarea name="custom_info" maxlength="2000" rows="3" form="<?=$relForm?>"><?="\n".lorkhan_ui_h($rel['custom_info']??'')?></textarea><small>Private notes. Never sent to AI or changed by AI builds. Exports include this text.</small></label>
    </div></td></tr>
<?php };
?>
<div class="form-item span-2 npc-relationships" data-npc-relationships data-profile-form="<?=lorkhan_ui_h($formId)?>" data-profile-revision="<?=(int)($row['current_revision']??0)?>" data-playthrough="<?=lorkhan_ui_h($relPlaythrough)?>" data-tiers="<?=lorkhan_ui_h(json_encode($relTiers))?>">
    <label>Relationships</label>
    <div class="npc-rel-lock" data-npc-inherited data-profile-form="<?=lorkhan_ui_h($formId)?>" data-installation-id="<?=lorkhan_ui_h($installationId)?>" data-core-defaults="<?=lorkhan_ui_h(json_encode($relDefaults))?>">
        <label><input type="checkbox" form="<?=lorkhan_ui_h($formId)?>" data-npc-inherited-toggle<?=$relLocked?' checked':''?>> 🔒 Lock these relationships — stop the AI relationship model from changing them (your manual edits stay put)</label>
        <input type="hidden" name="npc_relationship_locked" form="<?=lorkhan_ui_h($formId)?>" value="<?=$relLockValue?>">
        <small data-npc-inherited-source><?=$relLockValue==='inherit'?'(Inherited from profile)':'(NPC override)'?></small>
        <button type="button" class="npc-inherit-button" data-npc-inherited-reset<?=$relLockValue==='inherit'?' disabled':''?>>Use Core Profile</button>
    </div>
    <?php if($relNotice!==''): ?><p role="status" class="npc-rel-notice"><?=lorkhan_ui_h($relNotice)?></p><?php endif; ?>
    <?php if($creating||$relPlaythrough===''): ?>
        <p class="npc-rel-empty"><?=$creating?'Save this NPC before managing relationships.':'Create a playthrough before managing relationships.'?></p>
    <?php else: ?>
    <form method="get" class="npc-rel-scope" action="<?=lorkhan_ui_h($relUiRoot.'/ui/core/npc_master.php')?>">
        <?php foreach($listState as$key=>$value)if(str_starts_with($key,'ui_'))$relHiddenFields([substr($key,3)=>$value]); ?>
        <?php $relHiddenFields(['rel_profile'=>$profileId]); ?>
        <label for="<?=$relKey?>-playthrough">Playthrough</label><select id="<?=$relKey?>-playthrough" name="rel_playthrough"><?php foreach($relPlaythroughs as$id=>$label): ?><option value="<?=lorkhan_ui_h($id)?>"<?=$id===$relPlaythrough?' selected':''?>><?=lorkhan_ui_h($label)?></option><?php endforeach; ?></select><button type="submit">Show</button>
    </form>
    <div class="npc-rel-table-region" tabindex="0" role="region" aria-label="Relationships for <?=lorkhan_ui_h($row['name']??'NPC')?>">
    <?php if($relRecords===[]): ?><p class="npc-rel-empty" data-rel-empty>No relationships tracked yet. Use "Build with AI" or add manually below.</p><?php endif; ?>
    <table class="npc-rel-table"<?=$relRecords===[]?' hidden':''?>><thead><tr><th>Target</th><th>Affinity</th><th>Tier</th><th>Type</th><th>Signals</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
    <?php foreach($relRecords as$rel)$relRenderRow($rel,$relKey.'-'.$rel['relationship_id']); ?></tbody></table></div>
    <?php foreach($relRecords as$rel): $relForm=$relKey.'-'.$rel['relationship_id']; ?>
        <form id="<?=$relForm?>" method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationships')?>" data-track-dirty><?php $relHiddenFields($relHidden+['relationship_id'=>$rel['relationship_id'],'expected_revision'=>$rel['revision']]); ?></form>
        <form id="<?=$relForm?>-delete" method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationship-delete')?>" data-confirm="Delete this relationship? Its change history will be kept."><?php $relHiddenFields($relHidden+['relationship_id'=>$rel['relationship_id'],'expected_revision'=>$rel['revision']]); ?></form>
    <?php endforeach; ?>
    <template data-rel-row-template><table><tbody><?php $relRenderRow(['relationship_id'=>'new','actor'=>'New relationship','actor_identity'=>[],
        'affinity'=>0,'disposition'=>0,'relationship_type'=>'neutral','custom_info'=>'','strongest_positive_delta'=>null,'strongest_negative_delta'=>null],'__REL_FORM__'); ?></tbody></table>
        <form id="__REL_FORM__" method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationships')?>"><?php $relHiddenFields($relHidden+['actor_profile_id'=>'']); ?></form>
        <form id="__REL_FORM__-delete" method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationship-delete')?>"></form>
    </template>
    <dialog id="<?=$relKey?>-details-dialog" class="npc-rel-build npc-rel-detail-dialog" data-rel-detail-dialog aria-labelledby="<?=$relKey?>-details-title" hidden>
        <h3 id="<?=$relKey?>-details-title">✏️ Details: <span data-rel-detail-target></span></h3>
        <p>⚠️ Relationship details and memories are used by the AI. Custom Info is player-only and is never sent to or changed by relationship AI.</p>
        <label for="<?=$relKey?>-relation">Relationship Detail</label>
        <div class="npc-rel-relation-input"><input id="<?=$relKey?>-relation" data-rel-detail-field="details[relation]" maxlength="1024" placeholder="son, ex-wife, employer"><button type="button" data-rel-suggestions-toggle aria-expanded="false" aria-controls="<?=$relKey?>-suggestions" title="Common suggestions">+</button></div>
        <div id="<?=$relKey?>-suggestions" class="npc-rel-suggestions" hidden><?php foreach(['son','daughter','father','mother','brother','sister','spouse','uncle','aunt','cousin','grandparent','in-law','stepchild','employer','employee','apprentice','partner','supplier','client','ex-wife','ex-husband','betrothed','ward','guardian','liege','vassal']as$suggestion): ?><button type="button" data-rel-suggestion="<?=$suggestion?>"><?=$suggestion?></button><?php endforeach; ?></div>
        <label for="<?=$relKey?>-note">Recent Interaction</label><input id="<?=$relKey?>-note" data-rel-detail-field="details[note]" maxlength="1024" placeholder="shared a drink, had argument">
        <label for="<?=$relKey?>-best" class="npc-rel-best-label">Best Memory</label><input id="<?=$relKey?>-best" data-rel-detail-field="details[best]" maxlength="1024" placeholder="helped escape captivity, saved life">
        <label for="<?=$relKey?>-worst" class="npc-rel-worst-label">Worst Memory</label><input id="<?=$relKey?>-worst" data-rel-detail-field="details[worst]" maxlength="1024" placeholder="killed his brother, betrayed trust">
        <label for="<?=$relKey?>-custom">Custom Info</label><textarea id="<?=$relKey?>-custom" data-rel-detail-field="custom_info" maxlength="2000" rows="3" placeholder="Write any player notes for this relationship"></textarea>
        <details class="npc-rel-disposition"><summary>OpenMW disposition</summary><label for="<?=$relKey?>-disposition">Disposition</label><input id="<?=$relKey?>-disposition" type="number" min="-100" max="100" required data-rel-detail-field="disposition"></details>
        <div class="npc-rel-detail-buttons"><button type="button" data-rel-detail-cancel>Cancel</button><button type="button" data-rel-detail-save>Save</button></div>
    </dialog>
    <form class="npc-rel-add" method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationships')?>" data-track-dirty>
        <?php $relHiddenFields($relHidden+['disposition'=>'0','reason'=>'Manual relationship']); ?>
        <select name="actor_profile_id" aria-label="New relationship target" required><option value="">Target name</option><?php foreach($relActors as$id=>$name): ?><option value="<?=lorkhan_ui_h($id)?>"><?=lorkhan_ui_h($name)?></option><?php endforeach; ?></select>
        <input type="number" name="affinity" aria-label="New relationship affinity" min="-100" max="100" value="0" required>
        <select name="relationship_type" aria-label="New relationship type" required><?php $relTypeOptions('neutral'); ?></select>
        <button type="submit"<?=$relActors===[]?' disabled title="No other bound actors are available"':''?>>+ Add</button>
    </form>
    <div class="npc-rel-quick-actions"><button type="button" data-rel-details="<?=$relKey?>-build" aria-expanded="false">🤖 Build with AI</button><button type="button" data-rel-details="<?=$relKey?>-custom-type" aria-expanded="false">🏷️ Add Custom Type</button><button type="button" data-rel-details="<?=$relKey?>-clear" aria-expanded="false"<?=(int)$relData['clear_snapshot']['count']===0?' disabled':''?>>🗑️ Clear All</button><a href="<?=lorkhan_ui_h($relUiRoot.'/ui/relationship_logs.php?'.http_build_query(['installation_id'=>$installationId]))?>" target="_blank" rel="noopener">Relationship LLM Logs</a></div>
    <dialog id="<?=$relKey?>-clear" class="npc-rel-build" aria-label="Clear All Relationships" hidden><h3>🗑️ Clear All Relationships</h3><p>Remove all <span data-rel-clear-count><?=(int)$relData['clear_snapshot']['count']?></span> relationships for <?=lorkhan_ui_h($row['name']??'this NPC')?> in <?=lorkhan_ui_h($relPlaythroughs[$relPlaythrough])?>?</p><p>Change history and private notes remain in the deleted records. If a relationship changed since this page loaded, nothing is cleared.</p><form method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationship-clear')?>">
        <?php $relHiddenFields($relHidden+['snapshot_token'=>$relData['clear_snapshot']['token']]); ?>
        <label>Type Clear to confirm<input name="confirm_clear" required pattern="Clear" autocomplete="off"></label><button type="button" data-rel-details="<?=$relKey?>-clear">Cancel</button><button type="submit" class="btn-danger">Clear All</button>
    </form></dialog>
    <dialog id="<?=$relKey?>-custom-type" class="npc-rel-build" aria-label="Add Custom Relationship Type" hidden><h3>🏷️ Add Custom Relationship Type</h3><p>Create a type such as client, mentor or servant. Select it on a relationship and save that row to retain it.</p><form data-rel-custom-type><label>Type Name<input name="custom_type" required maxlength="50" pattern="[a-zA-Z][a-zA-Z0-9_-]{0,49}" placeholder="e.g., client"></label><button type="button" data-rel-details="<?=$relKey?>-custom-type">Cancel</button><button type="submit">Add Type</button><span role="status" data-rel-custom-status></span></form></dialog>
    <dialog id="<?=$relKey?>-build" class="npc-rel-build npc-rel-history-build" aria-label="Build Relationships with AI" hidden><h3>🤖 Build Relationships with AI</h3><p>Uses recent played conversations involving this NPC to infer affinity scores and relationship types.</p><p class="npc-rel-warning"><strong>Merge warning:</strong> Generated scores for matching actors merge into your draft. Review them and click Save to keep them. Custom Info stays unchanged.</p>
        <form method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationship-preview')?>">
            <?php $relHiddenFields($relHidden+['operation'=>'generate','request_id'=>\LorkhanServer\Infrastructure\Uuid::v4()]); ?>
            <label>Recent conversations<select name="history_limit"><?php foreach([10,25,50,100]as$limit): ?><option value="<?=$limit?>"<?=$limit===100?' selected':''?>><?=$limit?></option><?php endforeach; ?></select></label>
            <label class="npc-rel-direction">Direction (optional):<textarea name="direction" maxlength="2000" rows="3" placeholder="e.g., Focus on House hierarchy, or this NPC's distrust of strangers"></textarea></label>
            <div class="npc-rel-build-buttons"><button type="button" data-rel-details="<?=$relKey?>-build">Cancel</button><button type="submit">🤖 Build</button></div>
        </form>
    </dialog>
    <?php $relBuildJobs=$relData['build_jobs']??[];$previewJob=null;foreach($relBuildJobs as$job)if(filter_var($job['preview']??false,FILTER_VALIDATE_BOOL)){$previewJob=$job;break;} ?>
    <p class="npc-rel-save-note" data-rel-preview-status role="status"></p>
    <button type="button" data-rel-preview-resume data-job="<?=lorkhan_ui_h($previewJob['job_id']??'')?>"<?=$previewJob===null?' hidden':''?>>Review result</button>
    <?php if($relBuildJobs!==[]): ?>
    <div class="npc-rel-build-status" role="status">
        <?php $job=$relBuildJobs[0];$outcome=match($job['outcome']){
            'queued'=>'Waiting to start','leased'=>'Analyzing recent event history…',
            'draft_ready'=>'Ready for review: '.(int)($job['draft_count']??0).' proposed relationships',
            'succeeded'=>'Finished: '.(int)$job['changed_count'].' relationships updated',
            'stale'=>'Stopped before saving; nothing changed','dead'=>'Did not finish; nothing changed',default=>'Unavailable',
        }; ?>
        <strong><?=lorkhan_ui_h($outcome)?></strong>
        <span><?=(int)$job['source_count']?> conversations · <time datetime="<?=lorkhan_ui_h($job['created_at'])?>"><?=lorkhan_ui_h(gmdate('j M Y, H:i',strtotime($job['created_at'])))?> UTC</time></span>
        <a href="<?=lorkhan_ui_h($relUiRoot.'/ui/core/npc_master.php?'.http_build_query($relReturnQuery))?>">Reload for status</a>
    </div>
    <?php endif; ?>
    <small class="npc-rel-save-note" data-rel-draft-status role="status">Relationship rows save separately from the NPC profile. Use the row Save button after editing. Use Add Custom Type for another label.</small>
    <section class="form-item span-2 npc-relationship-history" aria-labelledby="<?=$relKey?>-history-title"><header class="npc-relationship-history-header"><h3 id="<?=$relKey?>-history-title">Recent Relationship Changes</h3><p>Read-only history for this NPC. The current relationships above remain editable.</p></header>
    <?php if($relHistory===[]): ?><p class="npc-relationship-history-empty">No relationship changes recorded for this NPC yet.</p><?php else: ?><ol class="npc-relationship-history-list"><?php foreach(array_slice($relHistory,0,20)as$change):
        $before=$change['before_value']??[];$after=$change['after_value']??[];
        $delta=(int)($after['affinity']??0)-(int)($before['affinity']??0);
        $removed=($after['deleted']??false)===true;
        $typeChanged=($before['relationship_type']??'neutral')!==($after['relationship_type']??'neutral');
        $badgeClass='is-type';
        if($removed){$badge='Deleted';$spoken='Relationship deleted.';}
        elseif($delta!==0){$badge=sprintf('%+d',$delta);$badgeClass=$delta>0?'is-up':'is-down';$spoken='Affinity '.$badge.'.';}
        elseif($typeChanged){$badge='Type';$spoken='Relationship type change.';}
        else{$badge='Change';$spoken='Relationship change.';}
        $tiers=[];foreach([$before,$after]as$snapshot){foreach($relTiers as$tier){if((int)($snapshot['affinity']??0)>=$tier[0]){$tiers[]=$tier[1];break;}}}
        $tierChanged=!$removed&&count($tiers)===2&&$tiers[0]!==$tiers[1];
        $reason=trim((string)($change['reason']??''));
        $tierLabel=$reason!==''&&$tierChanged?$tiers[1]:'';
        if($reason===''){
            if($removed)$reason='Relationship removed';
            elseif($typeChanged)$reason='Now '.($after['relationship_type']??'neutral');
            elseif($tierChanged)$reason='Now '.$tiers[1];
            else $reason='No reason recorded';
        }
        ?>
        <li class="npc-relationship-history-item">
            <ul class="relationship-change-cell" role="list"><li class="relationship-change-entry">
                <span class="relationship-change-delta <?=$badgeClass?>"><span class="relationship-change-sr"><?=lorkhan_ui_h($spoken)?> </span><span aria-hidden="true"><?=lorkhan_ui_h($badge)?></span></span>
                <span class="relationship-change-entry-body"><span class="relationship-change-reason"><?=lorkhan_ui_h($reason)?></span>
                    <span class="relationship-change-entry-meta"><span class="relationship-change-sr"> toward </span><span class="relationship-change-arrow" aria-hidden="true">&rarr;</span><span class="relationship-change-target"><?=lorkhan_ui_h($change['actor_identity']['display_name']??$change['actor_identity']['record_id']??'Actor')?></span><?php if($tierLabel!==''): ?><span class="relationship-change-tier"><?=lorkhan_ui_h($tierLabel)?></span><?php endif; ?></span>
                </span>
            </li></ul>
            <time class="npc-relationship-history-time" datetime="<?=lorkhan_ui_h($change['created_at'])?>"><?=lorkhan_ui_h(gmdate('j M Y, H:i',strtotime($change['created_at'])))?> UTC</time>
        </li>
    <?php endforeach; ?></ol><?php endif; ?></section>
    <?php endif; ?>
    <details class="npc-rel-legacy"><summary>Relationship text</summary><?php $field('relationships','Relationships text','textarea',(string)($content['relationships']??''),[],'span-2','Legacy biography text used by profile conversion. Scored relationships above are saved separately.'); ?></details>
</div>
