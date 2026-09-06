<?php
declare(strict_types=1);

/** Keep the add/edit biography dialogs in the same Herika field order while preserving each POST contract. */
function lorkhan_biography_fields(bool $create):void
{
    $prefix=$create?'new-bio-':'biography-';
    $fields=[
        'name'=>['NPC Name:','text','NPC names cannot be changed after creation.'],
        'oghma_tags'=>['Oghma Tags:','text','Optional Oghma knowledge tags, separated by commas.'],
        'core'=>['Core:',3,'1–2 sentences about the character.'],
        'biography'=>['Static (Details):',4,'Detailed history, origins, and past experiences that shaped this character.'],
        'appearance'=>['Appearance:',4,'Physical features and distinguishing characteristics.'],
        'personality'=>['Personality:',4,'Character traits, behavioral patterns, and psychological characteristics.'],
        'relationships'=>['Relationships:',4,'Use a JSON object seed, for example {"Player":{"aff":25,"type":"professional"}}.'],
        'occupation'=>['Occupation:',3,'Current job, duties, and position in society or organizations.'],
        'skills'=>['Skills:',3,'Special talents, combat abilities, magical knowledge, and areas of expertise.'],
        'speech_style'=>['Speech Style:',3,'Vocabulary, accent, mannerisms, and communication patterns.'],
        'goals'=>['Goals:',3,'Long-term objectives, personal ambitions, and life goals.'],
        'voice_id'=>['Voice ID:','text','Optional unified voice identifier.'],
        'gender'=>['Gender:','text','Optional gender for reference.'],
        'race'=>['Race:','text','Optional race for reference.'],
        'record_id'=>['Record ID:','text','Stable OpenMW record ID, such as fargoth.'],
    ];
    $editNames=['oghma_tags'=>'oghma_knowledge_tags','biography'=>'npc_static_bio','speech_style'=>'speechstyle','voice_id'=>'voiceid','record_id'=>'refid'];
    $editIds=['name'=>'display-name','oghma_tags'=>'oghma-tags','biography'=>'text','speech_style'=>'speechstyle','voice_id'=>'voiceid','record_id'=>'refid'];
    foreach($fields as$key=>[$label,$type,$help]){
        if($create)$label=['occupation'=>'Occupation & Role:','skills'=>'Skills & Abilities:','goals'=>'Goals & Aspirations:'][$key]??$label;
        if($key==='biography')echo '<h3 class="biography-field-section">Extended Profile</h3>';
        if($key==='voice_id')echo '<h3 class="biography-field-section">Voice &amp; Meta</h3>';
        $id=$prefix.($create?$key:($editIds[$key]??$key));$name=$create?$key:($editNames[$key]??$key);
        $required=in_array($key,$create?['name','core','record_id']:['core'],true);
        $readonly=!$create&&in_array($key,['name','record_id'],true);
        $limit=$key==='name'?128:($key==='oghma_tags'?4096:($type==='text'?256:16384));
        echo '<label for="'.lorkhan_ui_h($id).'">'.lorkhan_ui_h($label).'</label><small id="'.lorkhan_ui_h($id).'-help">'.lorkhan_ui_h($help).'</small>';
        $attributes=' id="'.lorkhan_ui_h($id).'"'.(!$create&&$key==='name'?'':' name="'.lorkhan_ui_h($name).'"').' maxlength="'.$limit.'" aria-describedby="'.lorkhan_ui_h($id).'-help"'.($required?' required':'').($readonly?' readonly':'');
        echo $type==='text'?'<input type="text"'.$attributes.'>':'<textarea'.$attributes.' rows="'.$type.'"></textarea>';
    }
    if($create)echo '<label for="new-bio-content_file">Content File:</label><small id="new-bio-content-file-help">The plugin defining this record, for example Morrowind.esm.</small><input type="text" name="content_file" id="new-bio-content_file" maxlength="256" required aria-describedby="new-bio-content-file-help">';
}
