<div class="lorkhan-page relationship-tools">
    <?php if($notice!==''&&!str_starts_with($buildStatus,'relationship_build_')): ?><p role="<?php echo $buildStatus==='saved'?'status':'alert'; ?>"><?php echo lorkhan_ui_h($notice); ?></p><?php endif; ?>
    <?php if($installations===[]): ?><p class="empty-state">Connect OpenMW to manage relationships.</p><?php else: ?>
    <?php if(count($installations)>1): ?><form class="management-form" method="get">
        <?php lorkhan_relationship_select('installation_id','Installation',$installations,$selected); ?>
        <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <button class="btn-base" type="submit">Show</button>
    </form><?php endif; ?>
    <details class="management-section specialist-disclosure" id="relationship-builder"<?php echo $buildProfile!==''||str_starts_with($buildStatus,'relationship_build_')?' open':''; ?>>
        <summary>Build with AI</summary>
        <?php if($notice!==''&&str_starts_with($buildStatus,'relationship_build_')): ?><p role="<?php echo $buildStatus==='relationship_build_requested'?'status':'alert'; ?>"><?php echo lorkhan_ui_h($notice); ?></p><?php endif; ?>
        <p>Analyze recent played conversations for one NPC. This can replace its saved relationship scores.</p>
        <form class="management-form relationship-build-form relationship-build-scope" method="get" action="<?php echo lorkhan_ui_h($webRoot.'/ui/relationship_logs.php#relationship-builder'); ?>">
            <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($selected); ?>">
            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <div class="management-field"><?php lorkhan_relationship_select('profile_id','NPC to build for',[''=>'Choose an NPC']+$buildOwners,$buildProfile); ?></div>
            <div class="management-field"><?php lorkhan_relationship_select('playthrough_id','Playthrough to analyze',[''=>'Choose a playthrough']+$playthroughs,$buildPlaythrough); ?></div>
            <button class="btn-base" type="submit">Show build controls</button>
        </form>
        <?php if($buildScoped): ?>
        <form class="management-form relationship-build-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath.'/forms/relationship-history-build'); ?>">
            <?php foreach($buildScope as $field=>$value): ?><input type="hidden" name="<?php echo $field; ?>" value="<?php echo lorkhan_ui_h($value); ?>"><?php endforeach; ?>
            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
            <input type="hidden" name="request_id" value="<?php echo \LorkhanServer\Infrastructure\Uuid::v4(); ?>">
            <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
            <div class="management-field"><?php lorkhan_relationship_select('history_limit','Recent conversations to check',[10=>'10',25=>'25',50=>'50',100=>'100'],(string)$buildLimit); ?></div>
            <label class="management-field">Direction (optional):<textarea name="direction" maxlength="2000" rows="3" placeholder="e.g., Focus on House hierarchy, or this NPC's distrust of strangers"></textarea></label>
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
        }; ?><li><?php echo lorkhan_ui_h($job['created_at'].' · '.$job['source_count'].' exchanges selected · '.$outcome); ?></li><?php endforeach; ?>
        </ul><?php endif; ?>
        <a href="<?php echo lorkhan_ui_h($buildUrl); ?>">Reload for status</a>
        <?php endif; ?>
    </details>
    <details class="management-section specialist-disclosure"><summary>Add relationship</summary>
        <?php if($owners===[]||$playthroughs===[]): ?><p>Create an actor profile and playthrough first.</p><?php else: ?>
        <form class="management-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath.'/forms/relationships'); ?>">
            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
            <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($selected); ?>">
            <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
            <?php lorkhan_relationship_select('profile_id','Profile that owns this relationship',$owners); ?>
            <?php lorkhan_relationship_select('playthrough_id','Playthrough',$playthroughs); ?>
            <?php lorkhan_relationship_select('actor_profile_id','Relationship with',[''=>'Choose an actor, or enter its identity below']+$actors); ?>
            <details><summary>Exact actor identity</summary><label for="relationship-identity">Runtime identity JSON</label>
                <textarea id="relationship-identity" name="content_json" placeholder="Paste a copied runtime identity"></textarea>
            </details>
            <label for="relationship-type">Relationship Type</label><input id="relationship-type" name="relationship_type" type="text" list="relationship-type-options" maxlength="50" autocapitalize="none" autocorrect="off" spellcheck="false" value="neutral" required aria-describedby="relationship-type-help">
            <small id="relationship-type-help" class="relationship-type-help">Choose a listed type or enter a short custom label. Saved lowercase, sent to AI and shown in prompts, unlike Custom Info.</small>
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
    <section class="lorkhan-card specialist-section" aria-labelledby="relationship-current"><div class="specialist-section-heading"><h2 id="relationship-current">Current records</h2><span class="lorkhan-badge"><?php echo count($rows); ?> shown</span></div>
    <?php if($rows===[]): ?><p class="empty-state control-zero-state">No relationships are saved for this installation.</p><?php endif; ?>
    <div class="profile-grid">
    <?php foreach($rows as $row): $id=(string)$row['relationship_id'];$revision=(int)$row['revision']; ?>
        <article class="profile-card"><header><div><h3><?php echo lorkhan_ui_h($row['actor']); ?></h3>
            <p><?php echo lorkhan_ui_h($row['owner'].' · '.$row['playthrough']); ?></p></div><span class="status-badge">r<?php echo $revision; ?></span></header>
            <p>Type: <?php echo lorkhan_ui_h(lorkhan_relationship_type_label((string)$row['relationship_type'])); ?> · Disposition: <?php echo (int)$row['disposition']; ?> · Affinity: <?php echo (int)$row['affinity']; ?></p>
            <?php if($row['strongest_positive_delta']!==null||$row['strongest_negative_delta']!==null): ?><div class="relationship-signals">
                <?php if($row['strongest_positive_delta']!==null): ?><p><strong>Strongest affinity increase:</strong> +<?php echo (int)$row['strongest_positive_delta']; ?> · <?php echo lorkhan_ui_h($row['strongest_positive_reason'].' · '.substr((string)$row['strongest_positive_at'],0,10)); ?></p><?php endif; ?>
                <?php if($row['strongest_negative_delta']!==null): ?><p><strong>Strongest affinity decrease:</strong> <?php echo (int)$row['strongest_negative_delta']; ?> · <?php echo lorkhan_ui_h($row['strongest_negative_reason'].' · '.substr((string)$row['strongest_negative_at'],0,10)); ?></p><?php endif; ?>
            </div><?php endif; ?>
            <details><summary>Actor identity</summary><pre><?php echo lorkhan_ui_h(json_encode($row['actor_identity'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); ?></pre></details>
            <details><summary>Edit relationship</summary>
                <form class="management-form" aria-label="Edit relationship with <?php echo lorkhan_ui_h($row['actor'].' · '.$row['owner'].' · '.$row['playthrough']); ?>" method="post" action="<?php echo lorkhan_ui_h($managementBasePath.'/forms/relationships'); ?>">
                    <?php foreach(['installation_id','profile_id','playthrough_id','relationship_id'] as $field): ?>
                    <input type="hidden" name="<?php echo $field; ?>" value="<?php echo lorkhan_ui_h($row[$field]); ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                    <input type="hidden" name="expected_revision" value="<?php echo $revision; ?>">
                    <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
                    <label for="type-<?php echo lorkhan_ui_h($id); ?>">Relationship Type</label><input id="type-<?php echo lorkhan_ui_h($id); ?>" name="relationship_type" type="text" list="relationship-type-options" maxlength="50" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?php echo lorkhan_ui_h($row['relationship_type']); ?>" required aria-describedby="type-help-<?php echo lorkhan_ui_h($id); ?>">
                    <small id="type-help-<?php echo lorkhan_ui_h($id); ?>" class="relationship-type-help">Choose a listed type or enter a short custom label. Saved lowercase, sent to AI and shown in prompts, unlike Custom Info.</small>
                    <label for="disposition-<?php echo lorkhan_ui_h($id); ?>">Disposition</label><input id="disposition-<?php echo lorkhan_ui_h($id); ?>" name="disposition" type="number" min="-100" max="100" value="<?php echo (int)$row['disposition']; ?>" required>
                    <label for="affinity-<?php echo lorkhan_ui_h($id); ?>">Affinity</label><input id="affinity-<?php echo lorkhan_ui_h($id); ?>" name="affinity" type="number" min="-100" max="100" value="<?php echo (int)$row['affinity']; ?>" required>
                    <label for="reason-<?php echo lorkhan_ui_h($id); ?>">Reason</label><input id="reason-<?php echo lorkhan_ui_h($id); ?>" name="reason" maxlength="1024" value="Manual edit" required>
                    <label for="custom-info-<?php echo lorkhan_ui_h($id); ?>">Custom Info (optional)</label>
                    <?php // HTML strips one initial newline; prefix one so player-authored leading blank lines survive. ?>
                    <textarea id="custom-info-<?php echo lorkhan_ui_h($id); ?>" name="custom_info" rows="3" maxlength="2000" aria-describedby="custom-info-help-<?php echo lorkhan_ui_h($id); ?>"><?php echo "\n".lorkhan_ui_h($row['custom_info']??''); ?></textarea>
                    <small id="custom-info-help-<?php echo lorkhan_ui_h($id); ?>" class="relationship-custom-info-help">Your private notes, up to 2,000 characters. Never sent to AI or changed by AI builds. Clear the box and save to remove it. Exports include this text.</small>
                    <button class="btn-base btn-primary" type="submit">Save relationship</button>
                </form>
            </details>
            <form class="danger-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath.'/forms/relationship-delete'); ?>" data-confirm="Delete this relationship? Its history will be kept.">
                <input type="hidden" name="relationship_id" value="<?php echo lorkhan_ui_h($id); ?>">
                <input type="hidden" name="expected_revision" value="<?php echo $revision; ?>">
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($selected); ?>">
                <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <button class="btn-base btn-danger" type="submit">Delete relationship</button>
            </form>
        </article>
    <?php endforeach; ?>
    </div></section>
    <section class="lorkhan-card specialist-section" id="relationship-history" aria-labelledby="relationship-history-heading"><div class="specialist-section-heading"><h2 id="relationship-history-heading">Recent changes</h2><span class="lorkhan-badge"><?php echo count($history); ?> shown</span></div>
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
            'Source'=>$entry['source_mode'],'Before'=>lorkhan_relationship_state($entry['before_value']),
            'After'=>lorkhan_relationship_state($entry['after_value']),'Reason'=>$entry['reason'],
        ];}
        lorkhan_ui_table($auditRows,'No relationship changes are recorded yet.',['monitoring'=>true,'count_label'=>'relationship changes','formatters'=>['Time'=>'timestamp','Source'=>'status']]);
        ?></div>
    </section>
    <?php endif; ?>
    <datalist id="relationship-type-options"><?php foreach($relationshipTypes as $type): ?><option value="<?php echo lorkhan_ui_h($type); ?>"><?php endforeach; ?></datalist>
</div>
