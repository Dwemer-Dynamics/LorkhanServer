<?php
// NPC relationship controls share the existing revisioned handlers; opening the editor never starts AI work.
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
?>
<div class="form-item span-2 npc-relationships" data-npc-relationships data-tiers="<?=lorkhan_ui_h(json_encode($relTiers))?>">
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
    <form method="get" class="npc-rel-scope" action="<?=lorkhan_ui_h($uiRoot.'/ui/core/npc_master.php')?>">
        <?php foreach($listState as$key=>$value)if(str_starts_with($key,'ui_'))$relHiddenFields([substr($key,3)=>$value]); ?>
        <?php $relHiddenFields(['rel_profile'=>$profileId]); ?>
        <label for="<?=$relKey?>-playthrough">Playthrough</label><select id="<?=$relKey?>-playthrough" name="rel_playthrough"><?php foreach($relPlaythroughs as$id=>$label): ?><option value="<?=lorkhan_ui_h($id)?>"<?=$id===$relPlaythrough?' selected':''?>><?=lorkhan_ui_h($label)?></option><?php endforeach; ?></select><button type="submit">Show</button>
    </form>
    <div class="npc-rel-table-region" tabindex="0" role="region" aria-label="Relationships for <?=lorkhan_ui_h($row['name']??'NPC')?>">
    <?php if($relRecords===[]): ?><p class="npc-rel-empty">No relationships tracked yet. Use "Build with AI" or add manually below.</p><?php else: ?>
    <table class="npc-rel-table"><thead><tr><th>Target</th><th>Affinity</th><th>Tier</th><th>Type</th><th>Signals</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
    <?php foreach($relRecords as$rel): $relId=(string)$rel['relationship_id'];$relForm=$relKey.'-'.$relId;$relTier=$relTiers[5];foreach($relTiers as$tier)if((int)$rel['affinity']>=$tier[0]){$relTier=$tier;break;} ?>
    <tr data-npc-rel-row>
        <td><span class="npc-rel-target" title="<?=lorkhan_ui_h(($rel['actor_identity']['record_id']??'').' · '.($rel['actor_identity']['content_file']??''))?>"><?=lorkhan_ui_h($rel['actor'])?></span></td>
        <td><input class="npc-rel-aff" aria-label="Affinity with <?=lorkhan_ui_h($rel['actor'])?>" type="number" name="affinity" min="-100" max="100" required value="<?=(int)$rel['affinity']?>" form="<?=$relForm?>"></td>
        <td><span class="npc-rel-tier" style="color:<?=$relTier[2]?>"><?=$relTier[1]?></span></td>
        <td><select class="npc-rel-type" aria-label="Relationship type with <?=lorkhan_ui_h($rel['actor'])?>" name="relationship_type" required form="<?=$relForm?>"><?php $relTypeOptions($rel['relationship_type']); ?></select></td>
        <td class="npc-rel-signals">
            <?php $latest=null;foreach($relHistory as$item)if($item['relationship_id']===$relId){$latest=$item;break;} ?>
            <?php if($latest!==null): ?><div class="npc-rel-last" title="<?=lorkhan_ui_h($latest['reason'])?>">Last: <?=lorkhan_ui_h(mb_strimwidth($latest['reason'],0,56,'…'))?></div><?php endif; ?>
            <?php foreach(['positive'=>['Best','+'],'negative'=>['Worst','']]as$sign=>[$label,$prefix]): if($rel['strongest_'.$sign.'_delta']===null)continue; ?><div class="npc-rel-<?=$sign?>" title="<?=lorkhan_ui_h($rel['strongest_'.$sign.'_reason'])?>"><?=$label?> <?=$prefix.(int)$rel['strongest_'.$sign.'_delta']?>: <?=lorkhan_ui_h(mb_strimwidth($rel['strongest_'.$sign.'_reason'],0,48,'…'))?></div><?php endforeach; ?>
            <?php if($latest===null&&$rel['strongest_positive_delta']===null&&$rel['strongest_negative_delta']===null): ?><span class="npc-rel-empty">No signals</span><?php endif; ?>
        </td>
        <td class="npc-rel-actions"><button type="submit" form="<?=$relForm?>" title="Save this relationship" aria-label="Save relationship with <?=lorkhan_ui_h($rel['actor'])?>">💾</button><button type="button" data-rel-details="<?=$relForm?>-details" aria-expanded="false" title="Edit details" aria-label="Edit details for <?=lorkhan_ui_h($rel['actor'])?>">✏️</button><button type="submit" class="npc-rel-delete" form="<?=$relForm?>-delete" title="Remove relationship" aria-label="Remove relationship with <?=lorkhan_ui_h($rel['actor'])?>">×</button></td>
    </tr>
    <tr id="<?=$relForm?>-details" hidden><td colspan="6"><div class="npc-rel-details">
        <label>Disposition<input type="number" name="disposition" min="-100" max="100" required value="<?=(int)$rel['disposition']?>" form="<?=$relForm?>"></label>
        <label>Reason<input name="reason" value="Manual edit" maxlength="1024" required form="<?=$relForm?>"></label>
        <label class="npc-rel-wide">Custom Info<textarea name="custom_info" maxlength="2000" rows="3" form="<?=$relForm?>"><?="\n".lorkhan_ui_h($rel['custom_info']??'')?></textarea><small>Private notes. Never sent to AI or changed by AI builds. Exports include this text.</small></label>
    </div></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?></div>
    <?php foreach($relRecords as$rel): $relForm=$relKey.'-'.$rel['relationship_id']; ?>
        <form id="<?=$relForm?>" method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationships')?>" data-track-dirty><?php $relHiddenFields($relHidden+['relationship_id'=>$rel['relationship_id'],'expected_revision'=>$rel['revision']]); ?></form>
        <form id="<?=$relForm?>-delete" method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationship-delete')?>" data-confirm="Delete this relationship? Its change history will be kept."><?php $relHiddenFields($relHidden+['relationship_id'=>$rel['relationship_id'],'expected_revision'=>$rel['revision']]); ?></form>
    <?php endforeach; ?>
    <form class="npc-rel-add" method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationships')?>" data-track-dirty>
        <?php $relHiddenFields($relHidden+['disposition'=>'0','reason'=>'Manual relationship']); ?>
        <select name="actor_profile_id" aria-label="New relationship target" required><option value="">Target name</option><?php foreach($relActors as$id=>$name): ?><option value="<?=lorkhan_ui_h($id)?>"><?=lorkhan_ui_h($name)?></option><?php endforeach; ?></select>
        <input type="number" name="affinity" aria-label="New relationship affinity" min="-100" max="100" value="0" required>
        <select name="relationship_type" aria-label="New relationship type" required><?php $relTypeOptions('neutral'); ?></select>
        <button type="submit"<?=$relActors===[]?' disabled title="No other bound actors are available"':''?>>+ Add</button>
    </form>
    <div class="npc-rel-quick-actions"><button type="button" data-rel-details="<?=$relKey?>-build" aria-expanded="false">🤖 Build with AI</button><button type="button" data-rel-details="<?=$relKey?>-custom-type" aria-expanded="false">🏷️ Add Custom Type</button><button type="button" data-rel-details="<?=$relKey?>-clear" aria-expanded="false"<?=(int)$relData['clear_snapshot']['count']===0?' disabled':''?>>🗑️ Clear All</button><a href="<?=lorkhan_ui_h($uiRoot.'/ui/relationship_logs.php?'.http_build_query(['installation_id'=>$installationId]))?>" target="_blank" rel="noopener">Relationship LLM Logs</a></div>
    <dialog id="<?=$relKey?>-clear" class="npc-rel-build" aria-label="Clear All Relationships" hidden><h3>🗑️ Clear All Relationships</h3><p>Remove all <?=(int)$relData['clear_snapshot']['count']?> relationships for <?=lorkhan_ui_h($row['name']??'this NPC')?> in <?=lorkhan_ui_h($relPlaythroughs[$relPlaythrough])?>?</p><p>Change history and private notes remain in the deleted records. If a relationship changed since this page loaded, nothing is cleared.</p><form method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationship-clear')?>">
        <?php $relHiddenFields($relHidden+['snapshot_token'=>$relData['clear_snapshot']['token']]); ?>
        <label>Type Clear to confirm<input name="confirm_clear" required pattern="Clear" autocomplete="off"></label><button type="button" data-rel-details="<?=$relKey?>-clear">Cancel</button><button type="submit" class="btn-danger">Clear All</button>
    </form></dialog>
    <dialog id="<?=$relKey?>-custom-type" class="npc-rel-build" aria-label="Add Custom Relationship Type" hidden><h3>🏷️ Add Custom Relationship Type</h3><p>Create a type such as client, mentor or servant. Select it on a relationship and save that row to retain it.</p><form data-rel-custom-type><label>Type Name<input name="custom_type" required maxlength="50" pattern="[a-zA-Z][a-zA-Z0-9_-]{0,49}" placeholder="e.g., client"></label><button type="button" data-rel-details="<?=$relKey?>-custom-type">Cancel</button><button type="submit">Add Type</button><span role="status" data-rel-custom-status></span></form></dialog>
    <dialog id="<?=$relKey?>-build" class="npc-rel-build npc-rel-history-build" aria-label="Build Relationships with AI" hidden><h3>🤖 Build Relationships with AI</h3><p>Uses recent played conversations involving this NPC to infer affinity scores and relationship types.</p><p class="npc-rel-warning"><strong>Merge warning:</strong> Existing scores for the same actors may be replaced. Custom Info stays unchanged.</p>
        <form method="post" action="<?=lorkhan_ui_h($managementBasePath.'/forms/relationship-history-build')?>">
            <?php $relHiddenFields($relHidden+['request_id'=>\LorkhanServer\Infrastructure\Uuid::v4()]); ?>
            <label>Recent conversations<select name="history_limit"><?php foreach([10,25,50,100]as$limit): ?><option value="<?=$limit?>"<?=$limit===100?' selected':''?>><?=$limit?></option><?php endforeach; ?></select></label>
            <label class="npc-rel-direction">Direction (optional):<textarea name="direction" maxlength="2000" rows="3" placeholder="e.g., Focus on House hierarchy, or this NPC's distrust of strangers"></textarea></label>
            <div class="npc-rel-build-buttons"><button type="button" data-rel-details="<?=$relKey?>-build">Cancel</button><button type="submit">🤖 Build</button></div>
        </form>
    </dialog>
    <?php $relBuildJobs=$relData['build_jobs']??[];if($relBuildJobs!==[]): ?>
    <div class="npc-rel-build-status" role="status">
        <?php $job=$relBuildJobs[0];$outcome=match($job['outcome']){
            'queued'=>'Waiting to start','leased'=>'Analyzing recent event history…',
            'succeeded'=>'Finished: '.(int)$job['changed_count'].' relationships updated',
            'stale'=>'Stopped before saving; nothing changed','dead'=>'Did not finish; nothing changed',default=>'Unavailable',
        }; ?>
        <strong><?=lorkhan_ui_h($outcome)?></strong>
        <span><?=(int)$job['source_count']?> conversations · <time datetime="<?=lorkhan_ui_h($job['created_at'])?>"><?=lorkhan_ui_h(gmdate('j M Y, H:i',strtotime($job['created_at'])))?> UTC</time></span>
        <a href="<?=lorkhan_ui_h($uiRoot.'/ui/core/npc_master.php?'.http_build_query($relReturnQuery))?>">Reload for status</a>
    </div>
    <?php endif; ?>
    <small class="npc-rel-save-note">Relationship rows save separately from the NPC profile. Use the row Save button after editing. Use Add Custom Type for another label.</small>
    <section class="npc-relationship-history"><header><h3>Recent Relationship Changes</h3><p>Read-only history for this NPC. The current relationships above remain editable.</p></header>
    <?php if($relHistory===[]): ?><p class="npc-rel-empty">No relationship changes recorded for this NPC yet.</p><?php else: ?><ol><?php foreach(array_slice($relHistory,0,20)as$change): $before=$change['before_value']??[];$after=$change['after_value']??[];$delta=(int)($after['affinity']??0)-(int)($before['affinity']??0); ?>
        <li><strong><?=lorkhan_ui_h($change['actor_identity']['display_name']??$change['actor_identity']['record_id']??'Actor')?></strong> <?php if(($after['deleted']??false)!==true): ?><span class="<?=$delta<0?'npc-rel-negative':'npc-rel-positive'?>"><?=($delta>0?'+':'').$delta?></span><?php endif; ?>
            <?php if(($after['deleted']??false)===true): ?> Deleted<?php elseif(($before['relationship_type']??'neutral')!==($after['relationship_type']??'neutral')): ?> <?=lorkhan_ui_h(($before['relationship_type']??'neutral').' → '.($after['relationship_type']??'neutral'))?><?php endif; ?>
            <span><?=lorkhan_ui_h($change['reason'])?></span><time datetime="<?=lorkhan_ui_h($change['created_at'])?>"><?=lorkhan_ui_h(gmdate('j M Y, H:i',strtotime($change['created_at'])))?> UTC</time></li>
    <?php endforeach; ?></ol><?php endif; ?></section>
    <?php endif; ?>
    <details class="npc-rel-legacy"><summary>Relationship text</summary><?php $field('relationships','Relationships text','textarea',(string)($content['relationships']??''),[],'span-2','Legacy biography text used by profile conversion. Scored relationships above are saved separately.'); ?></details>
</div>
