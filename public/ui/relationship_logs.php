<?php
declare(strict_types=1);
$pageTitle='Relationship Audit';$topNavSection='control';$BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';
$additionalStylesheets=['almsivi-pages.css'];
$installations=[];foreach($uiRepository->rows('installations') as $row)$installations[(string)$row['installation_id']]=(string)($row['display_name']??$row['installation_id']);
$selected=is_string($_GET['installation_id']??null)?$_GET['installation_id']:'';
if(!isset($installations[$selected]))$selected=(string)(array_key_first($installations)??'');
$rows=$selected===''?[]:$uiRepository->rows('relationships',$selected);
$history=$selected===''?[]:$uiRepository->rows('relationship_logs',$selected);
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
$buildJobs=$buildScoped?(new \ALMSIVIserver\Infrastructure\RelationshipBuildRepository($database))->recentJobs($buildScope):[];
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
function almsivi_relationship_select(string $name,string $label,array $options,string $selected=''):void
{
    static $counter=0;$id='relationship-'.$name.'-'.++$counter;
    echo '<label for="'.almsivi_ui_h($id).'">'.almsivi_ui_h($label).'</label><select id="'.almsivi_ui_h($id).'" name="'.almsivi_ui_h($name).'">';
    foreach($options as $value=>$text)echo '<option value="'.almsivi_ui_h($value).'"'.((string)$value===$selected?' selected':'').'>'.almsivi_ui_h($text).'</option>';
    echo '</select>';
}

/** Show the scored state without exposing a raw internal JSON document in every history cell. */
function almsivi_relationship_state(mixed $value):string
{
    if(!is_array($value)||$value===[])return 'No previous state';
    if(($value['deleted']??false)===true)return 'Deleted';
    return 'Disposition '.($value['disposition']??'—').'; affinity '.($value['affinity']??'—')
        .(isset($value['revision'])?' (r'.$value['revision'].')':'')
        .(($value['custom_info_changed']??false)===true?'; Custom Info updated':'');
}

include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="almsivi-page<?php echo $embedded?' embedded':''; ?>">
    <header class="almsivi-page-header"><div><h1>Relationship Audit</h1><p>Edit saved relationships and review their change history.</p></div>
        <a href="#relationship-history">Recent changes</a></header>
    <?php if($notice!==''&&!str_starts_with($buildStatus,'relationship_build_')): ?><p role="<?php echo $buildStatus==='saved'?'status':'alert'; ?>"><?php echo almsivi_ui_h($notice); ?></p><?php endif; ?>
    <?php if($installations===[]): ?><p class="empty-state">Connect OpenMW to manage relationships.</p><?php else: ?>
    <?php if(count($installations)>1): ?><form class="management-form" method="get">
        <?php almsivi_relationship_select('installation_id','Installation',$installations,$selected); ?>
        <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <button class="btn-base" type="submit">Show</button>
    </form><?php endif; ?>
    <details class="management-section" id="relationship-builder"<?php echo $buildProfile!==''||str_starts_with($buildStatus,'relationship_build_')?' open':''; ?>>
        <summary>Build with AI</summary>
        <?php if($notice!==''&&str_starts_with($buildStatus,'relationship_build_')): ?><p role="<?php echo $buildStatus==='relationship_build_requested'?'status':'alert'; ?>"><?php echo almsivi_ui_h($notice); ?></p><?php endif; ?>
        <p>Analyze recent played conversations for one NPC. This can replace its saved relationship scores.</p>
        <form class="management-form relationship-build-form relationship-build-scope" method="get" action="<?php echo almsivi_ui_h($webRoot.'/ui/relationship_logs.php#relationship-builder'); ?>">
            <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($selected); ?>">
            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <div class="management-field"><?php almsivi_relationship_select('profile_id','NPC to build for',[''=>'Choose an NPC']+$buildOwners,$buildProfile); ?></div>
            <div class="management-field"><?php almsivi_relationship_select('playthrough_id','Playthrough to analyze',[''=>'Choose a playthrough']+$playthroughs,$buildPlaythrough); ?></div>
            <button class="btn-base" type="submit">Show build controls</button>
        </form>
        <?php if($buildScoped): ?>
        <form class="management-form relationship-build-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath.'/forms/relationship-history-build'); ?>">
            <?php foreach($buildScope as $field=>$value): ?><input type="hidden" name="<?php echo $field; ?>" value="<?php echo almsivi_ui_h($value); ?>"><?php endforeach; ?>
            <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
            <input type="hidden" name="request_id" value="<?php echo \ALMSIVIserver\Infrastructure\Uuid::v4(); ?>">
            <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
            <div class="management-field"><?php almsivi_relationship_select('history_limit','Recent conversations to check',[10=>'10',25=>'25',50=>'50',100=>'100'],(string)$buildLimit); ?></div>
            <button class="btn-base btn-primary" type="submit">Build relationships</button>
        </form>
        <details><summary>How this works</summary><p>Uses the saved Relationship LLM and honors Relationship Lock. Automatic update chance does not apply, even at 0%. You can build after a session ends.</p>
            <p>Checks up to 100 recent conversations and 20 known participants. Only fully played, visible exchanges are used. Oversized requests are rejected; other relationships stay unchanged.</p>
            <p>Loading, starting or stopping a session, changing settings, editing a relationship or hiding source history before saving stops the build. Reload for status; this page does not refresh automatically.</p></details>
        <h3>Recent builds for this NPC and playthrough</h3>
        <?php if($buildJobs===[]): ?><p>No builds requested yet.</p><?php else: ?><ul>
        <?php foreach($buildJobs as $job): $outcome=match($job['outcome']){
            'queued'=>'Waiting to start','leased'=>'Analyzing','succeeded'=>'Finished: '.(int)$job['changed_count'].' relationships updated',
            'stale'=>'Stopped before saving; nothing changed','dead'=>'Did not finish; nothing changed',default=>'Unavailable',
        }; ?><li><?php echo almsivi_ui_h($job['created_at'].' · '.$job['source_count'].' exchanges selected · '.$outcome); ?></li><?php endforeach; ?>
        </ul><?php endif; ?>
        <a href="<?php echo almsivi_ui_h($buildUrl); ?>">Reload for status</a>
        <?php endif; ?>
    </details>
    <details class="management-section"><summary>Add relationship</summary>
        <?php if($owners===[]||$playthroughs===[]): ?><p>Create an actor profile and playthrough first.</p><?php else: ?>
        <form class="management-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath.'/forms/relationships'); ?>">
            <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
            <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($selected); ?>">
            <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
            <?php almsivi_relationship_select('profile_id','Profile that owns this relationship',$owners); ?>
            <?php almsivi_relationship_select('playthrough_id','Playthrough',$playthroughs); ?>
            <?php almsivi_relationship_select('actor_profile_id','Relationship with',[''=>'Choose an actor, or enter its identity below']+$actors); ?>
            <details><summary>Exact actor identity</summary><label for="relationship-identity">Runtime identity JSON</label>
                <textarea id="relationship-identity" name="content_json" placeholder="Paste a copied runtime identity"></textarea>
            </details>
            <label for="relationship-disposition">Disposition</label><input id="relationship-disposition" name="disposition" type="number" min="-100" max="100" value="0" required>
            <label for="relationship-affinity">Affinity</label><input id="relationship-affinity" name="affinity" type="number" min="-100" max="100" value="0" required>
            <label for="relationship-reason">Reason</label><input id="relationship-reason" name="reason" maxlength="1024" value="Manual relationship" required>
            <label for="relationship-custom-info">Custom Info (optional)</label>
            <textarea id="relationship-custom-info" name="custom_info" rows="3" maxlength="2000" aria-describedby="relationship-custom-info-help"></textarea>
            <small id="relationship-custom-info-help" class="relationship-custom-info-help">Your private notes, up to 2,000 characters. Never sent to AI or changed by AI builds. Exports include this text.</small>
            <button class="btn-base btn-primary" type="submit">Add relationship</button>
        </form>
        <?php endif; ?>
    </details>
    <section aria-labelledby="relationship-current"><h2 id="relationship-current">Current records (<?php echo count($rows); ?> shown)</h2>
    <?php if($rows===[]): ?><p class="empty-state">No relationships are saved for this installation.</p><?php endif; ?>
    <div class="profile-grid">
    <?php foreach($rows as $row): $id=(string)$row['relationship_id'];$revision=(int)$row['revision']; ?>
        <article class="profile-card"><header><div><h3><?php echo almsivi_ui_h($row['actor']); ?></h3>
            <p><?php echo almsivi_ui_h($row['owner'].' · '.$row['playthrough']); ?></p></div><span class="status-badge">r<?php echo $revision; ?></span></header>
            <p>Disposition: <?php echo (int)$row['disposition']; ?> · Affinity: <?php echo (int)$row['affinity']; ?></p>
            <details><summary>Actor identity</summary><pre><?php echo almsivi_ui_h(json_encode($row['actor_identity'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); ?></pre></details>
            <details><summary>Edit relationship</summary>
                <form class="management-form" aria-label="Edit relationship with <?php echo almsivi_ui_h($row['actor'].' · '.$row['owner'].' · '.$row['playthrough']); ?>" method="post" action="<?php echo almsivi_ui_h($managementBasePath.'/forms/relationships'); ?>">
                    <?php foreach(['installation_id','profile_id','playthrough_id','relationship_id'] as $field): ?>
                    <input type="hidden" name="<?php echo $field; ?>" value="<?php echo almsivi_ui_h($row[$field]); ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                    <input type="hidden" name="expected_revision" value="<?php echo $revision; ?>">
                    <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
                    <label for="disposition-<?php echo almsivi_ui_h($id); ?>">Disposition</label><input id="disposition-<?php echo almsivi_ui_h($id); ?>" name="disposition" type="number" min="-100" max="100" value="<?php echo (int)$row['disposition']; ?>" required>
                    <label for="affinity-<?php echo almsivi_ui_h($id); ?>">Affinity</label><input id="affinity-<?php echo almsivi_ui_h($id); ?>" name="affinity" type="number" min="-100" max="100" value="<?php echo (int)$row['affinity']; ?>" required>
                    <label for="reason-<?php echo almsivi_ui_h($id); ?>">Reason</label><input id="reason-<?php echo almsivi_ui_h($id); ?>" name="reason" maxlength="1024" value="Manual edit" required>
                    <label for="custom-info-<?php echo almsivi_ui_h($id); ?>">Custom Info (optional)</label>
                    <?php // HTML strips one initial newline; prefix one so player-authored leading blank lines survive. ?>
                    <textarea id="custom-info-<?php echo almsivi_ui_h($id); ?>" name="custom_info" rows="3" maxlength="2000" aria-describedby="custom-info-help-<?php echo almsivi_ui_h($id); ?>"><?php echo "\n".almsivi_ui_h($row['custom_info']??''); ?></textarea>
                    <small id="custom-info-help-<?php echo almsivi_ui_h($id); ?>" class="relationship-custom-info-help">Your private notes, up to 2,000 characters. Never sent to AI or changed by AI builds. Clear the box and save to remove it. Exports include this text.</small>
                    <button class="btn-base btn-primary" type="submit">Save relationship</button>
                </form>
            </details>
            <form class="danger-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath.'/forms/relationship-delete'); ?>" data-confirm="Delete this relationship? Its history will be kept.">
                <input type="hidden" name="relationship_id" value="<?php echo almsivi_ui_h($id); ?>">
                <input type="hidden" name="expected_revision" value="<?php echo $revision; ?>">
                <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($selected); ?>">
                <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
                <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                <button class="btn-base btn-danger" type="submit">Delete relationship</button>
            </form>
        </article>
    <?php endforeach; ?>
    </div></section>
    <section id="relationship-history" aria-labelledby="relationship-history-heading"><h2 id="relationship-history-heading">Recent changes (<?php echo count($history); ?> shown)</h2>
        <div class="table-responsive"><?php
        $auditRows=[];foreach($history as $entry){
            $identity=$entry['actor_identity'];
            $record=is_string($identity['record_id']??null)?$identity['record_id']:'legacy identity';
            $actor=is_string($identity['display_name']??null)?$identity['display_name']:$record;
            $reference=$identity['refnum']??null;
            if(is_array($reference)&&is_int($reference['content_file']??null)&&is_int($reference['index']??null))
                $actor.=' ['.$reference['content_file'].':'.$reference['index'].']';
            $auditRows[]=[
            'Time'=>$entry['created_at'],'Owner'=>$entry['owner'],'Playthrough'=>$entry['playthrough'],
            'Actor'=>$actor.' · '.$record,
            'Source'=>$entry['source_mode'],'Before'=>almsivi_relationship_state($entry['before_value']),
            'After'=>almsivi_relationship_state($entry['after_value']),'Reason'=>$entry['reason'],
        ];}
        almsivi_ui_table($auditRows,'No relationship changes are recorded yet.');
        ?></div>
    </section>
    <?php endif; ?>
</main>
<?php include __DIR__.'/tmpl/footer.html'; ?>
