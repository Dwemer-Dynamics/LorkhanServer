<?php

declare(strict_types=1);

/** Compact opt-in controls; separate from profile-card so memory search never hides the policy. */
function lorkhan_roleplay_memory_policy(array $policy,array $installations,string $installationId,
    array $connectors,string $base,string $csrf,string $webRoot):void
{
    $content=is_array($policy['content']??null)?$policy['content']:[];
    $enabled=($content['enabled']??false)===true;
    $selected=(string)($content['provider_configuration_id']??'');
    $revision=(int)($policy['current_revision']??0);
    if($selected!==''&&!isset($connectors[$selected]))$connectors[$selected]='Unavailable connector';
    ?>
    <section class="memory-policy-card" aria-labelledby="memory-policy-heading">
        <header><h3 id="memory-policy-heading">Model memory summaries</h3>
            <span class="status-badge"><?php echo $enabled?'On':'Off'; ?><?php if($revision>0)echo ' · r'.$revision; ?></span></header>
        <?php if($installations===[]): ?>
            <p>Connect OpenMW once to configure memory summaries.</p>
        <?php else: ?>
            <?php if(count($installations)>1): ?>
            <form method="get" class="memory-policy-scope">
                <input type="hidden" name="tab" value="memory">
                <?php lorkhan_roleplay_scope_select('policy_installation_id','Installation',$installations,$installationId); ?>
                <button type="submit" class="btn-base">Show</button>
            </form>
            <?php endif; ?>
            <form class="management-form" method="post" action="<?php echo lorkhan_ui_h($base.'/forms/memory-policy'); ?>">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                <label class="memory-policy-toggle"><input type="checkbox" name="enabled" value="1"
                    aria-describedby="memory-policy-enabled-help"<?php echo $enabled?' checked':''; ?>> Use model summaries for new mid and long memories</label>
                <p id="memory-policy-enabled-help" class="memory-policy-hint">Off by default. Originals are always kept. Turning this off uses the original text again.</p>
                <label for="memory-policy-connector">LLM connector</label>
                <select id="memory-policy-connector" name="provider_configuration_id" aria-describedby="memory-policy-connector-help">
                    <option value="">Choose an LLM connector</option>
                    <?php foreach($connectors as$id=>$name): ?>
                    <option value="<?php echo lorkhan_ui_h($id); ?>"<?php echo $id===$selected?' selected':''; ?>><?php echo lorkhan_ui_h($name); ?></option>
                    <?php endforeach; ?>
                </select>
                <p id="memory-policy-connector-help" class="memory-policy-hint">Required when summaries are on. Switching connectors applies to future jobs; queued jobs keep their saved connector revision.</p>
                <?php if($connectors===[]): ?><p><a href="<?php echo lorkhan_ui_h($webRoot.'/ui/core/llm_connectors.php'); ?>">Add an LLM connector</a> to enable summaries.</p><?php endif; ?>
                <label for="memory-policy-reason">Revision note</label>
                <input id="memory-policy-reason" name="change_reason" maxlength="512" required value="Memory policy update">
                <button class="btn-base btn-primary" type="submit">Save summary policy</button>
            </form>
            <p class="roleplay-note">Future consolidations may call a paid provider. Saving this form does not call a provider or process existing memories.</p>
            <details class="memory-policy-help"><summary>How model summaries work</summary>
                <p>Every four eligible memories form the next memory tier. With summaries on, new mid and long memories receive a separate model summary. Source and witness filters still apply. Turning summaries off cancels or discards pending work.</p>
                <p>Use “Summarize with model” on an existing eligible memory to request a summary. Rebuild memories only recalculates the deterministic retrieval index and never calls a provider.</p>
            </details>
        <?php endif; ?>
    </section>
    <?php
}

/** Show generated text separately and offer paid work only for server-approved consolidated records. */
function lorkhan_roleplay_memory_summary_control(array $row,string $base,string $csrf):string
{
    $text=(string)($row['summary_content']??'');
    if($text!=='')return '<details class="memory-model-text"><summary>Model summary</summary><p>'.nl2br(lorkhan_ui_h($text)).'</p></details>';
    if(!filter_var($row['summarizable']??false,FILTER_VALIDATE_BOOL))return '';
    if(($row['summary_state']??'')==='summary queued')return '<button class="btn-base" type="button" disabled>Summary queued</button>';
    if(!filter_var($row['summary_policy_enabled']??false,FILTER_VALIDATE_BOOL))return '';
    return '<form method="post" class="memory-summary-request" action="'.lorkhan_ui_h($base.'/forms/memory-summarize').'">'
        .'<input type="hidden" name="_csrf" value="'.lorkhan_ui_h($csrf).'">'
        .'<input type="hidden" name="installation_id" value="'.lorkhan_ui_h($row['installation_id']).'">'
        .'<input type="hidden" name="memory_id" value="'.lorkhan_ui_h($row['memory_id']).'">'
        .'<input type="hidden" name="base_revision" value="'.(int)$row['current_revision'].'">'
        .'<button class="btn-base" type="submit">Summarize with model</button></form>';
}
