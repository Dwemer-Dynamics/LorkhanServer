<?php

declare(strict_types=1);

$pageTitle = 'ALMSIVI Roleplay';
$topNavSection = 'roleplay';
$bodyClass = 'hub-page';
require __DIR__ . '/ui_bootstrap.php';
$roleplay = $uiRepository->roleplay();
$allowedTabs = ['eventlog-tab', 'responses-tab', 'memories-tab', 'relationships-tab', 'narratives-tab', 'knowledge-tab', 'journal-tab', 'books-tab'];
$requestedTab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'eventlog-tab';
$activeTab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'eventlog-tab';
$installationOptions=[];foreach($uiRepository->rows('global_settings')as$row){$id=(string)($row['installation_id']??'');if($id!=='')$installationOptions[$id]=(string)($row['display_name']??$id);}
$profileOptions=[];foreach(array_merge($uiRepository->rows('profiles'),$uiRepository->rows('player'))as$row){$id=(string)($row['profile_id']??'');if($id!=='')$profileOptions[$id]=(string)($row['name']??$id);}
$playthroughOptions=[];foreach($uiRepository->rows('playthroughs')as$row){$id=(string)($row['playthrough_id']??'');if($id!=='')$playthroughOptions[$id]=(string)($row['playthrough']??$id);}

/** Render one labelled scope selector shared by the memory and relationship managers. */
function almsivi_roleplay_scope_select(string $name,string $label,array $options,string $selected=''):void
{
    static $counter=0;$counter++;$id='roleplay-'.$name.'-'.$counter;echo'<label for="'.almsivi_ui_h($id).'">'.almsivi_ui_h($label).'</label><select id="'.almsivi_ui_h($id).'" name="'.almsivi_ui_h($name).'">';
    foreach($options as$value=>$optionLabel)echo'<option value="'.almsivi_ui_h($value).'"'.((string)$value===$selected?' selected':'').'>'.almsivi_ui_h($optionLabel).'</option>';
    echo'</select>';
}

/** Render the editable CHIM-style memory manager while preserving ALMSIVI retrieval provenance. */
function almsivi_roleplay_memory_manager(array $rows,array $installations,array $profiles,array $playthroughs,string $base,string $csrf):void
{
    echo'<details class="management-section"><summary>Add or rebuild memories</summary><div class="profile-grid"><form class="management-form" method="post" action="'.almsivi_ui_h($base.'/forms/memory').'"><fieldset><legend>Add memory</legend>';
    almsivi_roleplay_scope_select('installation_id','Installation',$installations);almsivi_roleplay_scope_select('profile_id','Profile',$profiles);almsivi_roleplay_scope_select('playthrough_id','Playthrough',$playthroughs);
    echo'<label for="memory-tier">Tier</label><select id="memory-tier" name="tier"><option value="recent">Recent</option><option value="mid">Middle term</option><option value="long">Long term</option></select>';
    echo'<label for="memory-content">Memory content</label><textarea id="memory-content" name="content" required></textarea><label for="memory-provenance">Provenance</label><input id="memory-provenance" name="provenance" value="management" required><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base btn-primary" type="submit">Add memory</button></fieldset></form>';
    echo'<form class="management-form" method="post" action="'.almsivi_ui_h($base.'/forms/memory-rebuild').'"><fieldset><legend>Rebuild retrieval index</legend>';
    almsivi_roleplay_scope_select('installation_id','Installation',$installations);almsivi_roleplay_scope_select('profile_id','Profile',$profiles);almsivi_roleplay_scope_select('playthrough_id','Playthrough',$playthroughs);
    echo'<p>Recalculate deterministic lexical and vector fields for the selected playthrough without changing memory text.</p><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base" type="submit">Rebuild memories</button></fieldset></form></div></details>';
    if($rows===[]){echo'<p class="empty-state">No memories are available yet.</p>';return;}echo'<div class="profile-grid">';
    foreach($rows as$row){$id=(string)($row['memory_id']??'');echo'<article class="profile-card"><header><div><span class="connector-kind">'.almsivi_ui_h($row['tier']??'memory').'</span><h3>'.almsivi_ui_h($row['occurred_at']??'Memory').'</h3></div></header><p>'.nl2br(almsivi_ui_h($row['content']??'')).'</p><details><summary>Edit memory</summary><form class="management-form" method="post" action="'.almsivi_ui_h($base.'/forms/memory-revise').'"><fieldset><legend>Save memory</legend><label for="memory-edit-'.almsivi_ui_h($id).'">Memory content</label><textarea id="memory-edit-'.almsivi_ui_h($id).'" name="content" required>'.almsivi_ui_h($row['content']??'').'</textarea><input type="hidden" name="memory_id" value="'.almsivi_ui_h($id).'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base btn-primary" type="submit">Save memory</button></fieldset></form></details><form class="danger-form" method="post" action="'.almsivi_ui_h($base.'/forms/memory-delete').'"><input type="hidden" name="memory_id" value="'.almsivi_ui_h($id).'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base btn-danger" type="submit">Delete memory</button></form></article>';}
    echo'</div>';
}

/** Render bounded manual relationship creation, editing, and audited deletion controls. */
function almsivi_roleplay_relationship_manager(array $rows,array $installations,array $profiles,array $playthroughs,string $base,string $csrf):void
{
    echo'<details class="management-section"><summary>Add relationship</summary><form class="management-form" method="post" action="'.almsivi_ui_h($base.'/forms/relationships').'"><fieldset><legend>Add relationship</legend>';
    almsivi_roleplay_scope_select('installation_id','Installation',$installations);almsivi_roleplay_scope_select('profile_id','Profile',$profiles);almsivi_roleplay_scope_select('playthrough_id','Playthrough',$playthroughs);
    echo'<label for="relationship-actor">Actor identity JSON</label><textarea id="relationship-actor" name="content_json" required>{"kind":"npc","record_id":"fargoth","content_file":"Morrowind.esm","display_name":"Fargoth"}</textarea><label for="relationship-disposition">Disposition</label><input id="relationship-disposition" name="disposition" type="number" value="0" required><label for="relationship-affinity">Affinity</label><input id="relationship-affinity" name="affinity" type="number" value="0" required><label for="relationship-reason">Reason</label><input id="relationship-reason" name="reason" value="management" required><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base btn-primary" type="submit">Add relationship</button></fieldset></form></details>';
    if($rows===[]){echo'<p class="empty-state">No relationships are available yet.</p>';return;}echo'<div class="profile-grid">';
    foreach($rows as$row){$id=(string)($row['relationship_id']??'');$identity=is_array($row['actor_identity']??null)?$row['actor_identity']:[];$identityJson=json_encode($identity===[]?(object)[]:$identity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        echo'<article class="profile-card"><header><div><span class="connector-kind">Relationship</span><h3>'.almsivi_ui_h($row['actor']??'Actor').'</h3></div><span class="status-badge">'.almsivi_ui_h($row['source_mode']??'manual').'</span></header><dl><dt>Disposition</dt><dd>'.almsivi_ui_h($row['disposition']??0).'</dd><dt>Affinity</dt><dd>'.almsivi_ui_h($row['affinity']??0).'</dd><dt>Updated</dt><dd>'.almsivi_ui_h($row['updated_at']??'').'</dd></dl><details><summary>Edit relationship</summary><form class="management-form" method="post" action="'.almsivi_ui_h($base.'/forms/relationships').'"><fieldset><legend>Save relationship</legend><label for="relationship-identity-'.almsivi_ui_h($id).'">Actor identity JSON</label><textarea id="relationship-identity-'.almsivi_ui_h($id).'" name="content_json" required>'.almsivi_ui_h($identityJson).'</textarea><label for="relationship-disposition-'.almsivi_ui_h($id).'">Disposition</label><input id="relationship-disposition-'.almsivi_ui_h($id).'" name="disposition" type="number" value="'.almsivi_ui_h($row['disposition']??0).'" required><label for="relationship-affinity-'.almsivi_ui_h($id).'">Affinity</label><input id="relationship-affinity-'.almsivi_ui_h($id).'" name="affinity" type="number" value="'.almsivi_ui_h($row['affinity']??0).'" required><label for="relationship-reason-'.almsivi_ui_h($id).'">Reason</label><input id="relationship-reason-'.almsivi_ui_h($id).'" name="reason" value="management edit" required>';
        foreach(['installation_id','profile_id','playthrough_id']as$field)echo'<input type="hidden" name="'.almsivi_ui_h($field).'" value="'.almsivi_ui_h($row[$field]??'').'">';
        echo'<input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base btn-primary" type="submit">Save relationship</button></fieldset></form></details><form class="danger-form" method="post" action="'.almsivi_ui_h($base.'/forms/relationship-delete').'"><input type="hidden" name="relationship_id" value="'.almsivi_ui_h($id).'"><input type="hidden" name="_csrf" value="'.almsivi_ui_h($csrf).'"><button class="btn-base btn-danger" type="submit">Delete relationship</button></form></article>';}
    echo'</div>';
}
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="container-fluid events-memories-page">
    <div class="tab-container">
        <?php include __DIR__ . '/tmpl/events_memories_navigation.php'; ?>

        <?php
        $panels = [
            'eventlog-tab' => ['Events', $roleplay['events'], 'No source events have been recorded yet.'],
            'responses-tab' => ['AI Responses', $roleplay['responses'], 'No AI dialogue has been recorded yet.'],
            'memories-tab' => ['Memories', $roleplay['memories'], 'No memories are available yet.'],
            'relationships-tab' => ['Relationships', $roleplay['relationships'], 'No relationships are available yet.'],
            'narratives-tab' => ['Narratives', $roleplay['narratives'], 'No narratives are available yet.'],
            'knowledge-tab' => ['Knowledge Records', $roleplay['knowledge'], 'No world knowledge is available yet.'],
            'journal-tab' => ['Morrowind Journal', $roleplay['journal'], 'No Morrowind journal entries have been received from OpenMW yet.'],
            'books-tab' => ['Read Books', $roleplay['books'], 'No books have been observed during an ALMSIVI session yet.'],
        ];
        foreach ($panels as $tabId => [$heading, $rows, $emptyMessage]):
        ?>
            <section id="<?php echo almsivi_ui_h($tabId); ?>" class="tab-content<?php echo $activeTab === $tabId ? ' active' : ''; ?>">
                <div class="tab-panel-inner"><h2><?php echo almsivi_ui_h($heading); ?></h2><?php
                    if($tabId==='memories-tab')almsivi_roleplay_memory_manager($rows,$installationOptions,$profileOptions,$playthroughOptions,$managementBasePath,$csrf);
                    elseif($tabId==='relationships-tab')almsivi_roleplay_relationship_manager($rows,$installationOptions,$profileOptions,$playthroughOptions,$managementBasePath,$csrf);
                    elseif($tabId==='narratives-tab'){echo'<p><a class="btn-base btn-primary" href="'.almsivi_ui_h($webRoot.'/ui/narrative_manager.php').'">Manage narratives</a></p>';almsivi_ui_table($rows,$emptyMessage);}
                    else almsivi_ui_table($rows,$emptyMessage);
                ?></div>
            </section>
        <?php endforeach; ?>
    </div>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
