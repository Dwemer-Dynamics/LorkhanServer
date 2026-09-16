<?php

declare(strict_types=1);

/** Share the CHIM voice preset selector and explicit paid-provider preview across profile editors. */
function lorkhan_ui_tts_filter_field(array $content,string $profileId,string $managementBasePath,string $csrf,string $formId=''):void
{
    $id='tts-filter-'.substr(hash('sha256',$profileId.$formId),0,16);
    $selected=$content['tts_filter_preset']??'none';
    echo '<div class="field-block" data-profile-voice-preview data-endpoint="'.lorkhan_ui_h($managementBasePath.'/api/v1/profile-voice-preview').'" data-profile="'.lorkhan_ui_h($profileId).'" data-csrf="'.lorkhan_ui_h($csrf).'">';
    echo '<label for="'.$id.'">Voice Filter</label><select id="'.$id.'" name="tts_filter_preset"'.($formId!==''?' form="'.lorkhan_ui_h($formId).'"':'').'>';
    foreach(\LorkhanServer\Application\TtsFilterPresets::catalog()as$preset){
        if(!$preset['exposed'])continue;
        echo '<option value="'.lorkhan_ui_h($preset['id']).'"'.($selected===$preset['id']?' selected':'').' title="'.lorkhan_ui_h($preset['description']).'">'.lorkhan_ui_h($preset['label']).'</option>';
    }
    echo '</select><p class="field-help">Applied after TTS for this profile. Preview uses the saved voice and connector with the selected filter; cloud previews may incur provider charges.</p>';
    if($profileId!=='')echo '<button type="button" data-filter-preview-play>▶ Preview Voice</button> <button type="button" data-filter-preview-stop>Stop</button><audio controls hidden></audio><span role="status"></span>';
    else echo '<p>Save the profile before previewing its voice.</p>';
    echo '</div>';
    static $script=false;
    if(!$script){$script=true;echo '<script defer src="'.lorkhan_ui_h(preg_replace('~/manage$~','',$managementBasePath).'/ui/js/tts-filter-preview.js').'"></script>';}
}
