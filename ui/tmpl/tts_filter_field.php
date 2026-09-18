<?php

declare(strict_types=1);

/** Share CHIM's voice filter controls across NPC, player and narrator editors. */
function lorkhan_ui_tts_filter_field(array $content,string $profileId,string $managementBasePath,string $csrf,string $formId=''):void
{
    $id='tts-filter-'.substr(hash('sha256',$profileId.$formId),0,16);
    $catalog=\LorkhanServer\Application\TtsFilterPresets::catalog();
    $selected=$content['tts_filter_preset']??'none';
    $base=preg_replace('~/manage$~','',$managementBasePath);
    static $assets=false;
    if(!$assets){
        $assets=true;
        echo '<link rel="stylesheet" href="'.lorkhan_ui_h($base.'/ui/css/tts-filter-preview.css?v='.filemtime(dirname(__DIR__).'/css/tts-filter-preview.css')).'">';
        echo '<script defer src="'.lorkhan_ui_h($base.'/ui/js/tts-filter-preview.js?v='.filemtime(dirname(__DIR__).'/js/tts-filter-preview.js')).'"></script>';
    }
    echo '<div class="form-item" data-profile-voice-preview data-endpoint="'.lorkhan_ui_h($managementBasePath.'/api/v1/profile-voice-preview').'" data-profile="'.lorkhan_ui_h($profileId).'" data-csrf="'.lorkhan_ui_h($csrf).'">';
    echo '<label for="'.$id.'">Voice Filter</label><div class="npc-voice-filter-row"><select id="'.$id.'" name="tts_filter_preset" aria-describedby="'.$id.'-hint '.$id.'-desc"'.($formId!==''?' form="'.lorkhan_ui_h($formId).'"':'').'>';
    foreach($catalog as$preset){
        if(!$preset['exposed'])continue;
        echo '<option value="'.lorkhan_ui_h($preset['id']).'"'.($selected===$preset['id']?' selected':'').' data-filter-desc="'.lorkhan_ui_h($preset['description']).'">'.lorkhan_ui_h($preset['label']).'</option>';
    }
    echo '</select><button type="button" class="npc-voice-filter-play" data-filter-preview-play title="Play sample with this voice filter" aria-label="Play sample with this voice filter" aria-describedby="'.$id.'-status"'.($profileId===''?' disabled':'').'>';
    echo '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><path d="M8.52 2.18 4.93 5.05H2.32a.8.8 0 0 0-.8.8v4.3c0 .44.36.8.8.8h2.61l3.59 2.87a.6.6 0 0 0 .98-.47V2.65a.6.6 0 0 0-.98-.47z"/><path d="M11.66 5.36a.7.7 0 0 0-.9 1.07 2.03 2.03 0 0 1 0 3.14.7.7 0 0 0 .9 1.07 3.43 3.43 0 0 0 0-5.28z"/></svg><span class="npc-voice-filter-spinner" aria-hidden="true"></span></button></div>';
    echo '<audio class="npc-voice-filter-audio" controls preload="none" aria-label="Voice filter preview"></audio>';
    echo '<small class="hint" id="'.$id.'-hint">Audio effect applied to everything this NPC says. Presets are fixed and cannot be edited.</small>';
    echo '<small class="hint npc-voice-filter-desc" id="'.$id.'-desc" data-npc-voice-filter-desc role="status" aria-live="polite">'.lorkhan_ui_h($catalog[$selected]['description']??'').'</small>';
    echo '<small class="hint npc-voice-filter-status" id="'.$id.'-status" data-filter-preview-status role="status" aria-live="polite">'.($profileId===''?'Save the profile before previewing its voice.':'').'</small></div>';
}
