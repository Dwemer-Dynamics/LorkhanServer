<?php

declare(strict_types=1);

// Provider field order derives from HerikaServer 529364c conf_schema.json and core/tts_connectors.php.
// Names map to Lorkhan's existing typed connector document; non-counterpart runtime fields stay advanced.
$primaryFields = [
    'pockettts'=>['model'], 'omnivoice'=>['language'],
    'chatterbox'=>['option__paralinguistic_tags_enabled','option__paralinguistic_tags_prompt','option__paralinguistic_tags_list'],
    'xtts-fastapi'=>['option__paralinguistic_tags_enabled','option__paralinguistic_tags_prompt','option__paralinguistic_tags_list'],
    'inworld'=>['option__workspace','language','model','option__temperature','option__speed'],
    'cartesia'=>['language','model','option__speed'], 'openai'=>['model','option__instructions'],
    '11labs'=>['option__optimize_streaming_latency','model','option__stability','option__similarity_boost','option__style','option__speed',
        'option__use_speaker_boost','option__apply_text_normalization','option__apply_language_text_normalization','option__v3_audio_tags'],
    'melotts'=>['language','option__speed'], 'mimic3'=>['option__rate'],
    'piper-tts'=>['option__length_scale','option__noise_scale','option__noise_w_scale','option__speaker','option__speaker_id'],
    'xvasynth'=>['language','option__model_type','option__version','option__game','option__pace','option__waveglow_path','option__vocoder','option__distro'],
    'zonos_gradio'=>['language','model','option__pitch_std','option__speaking_rate','option__cfg_scale'],
    'deepgram'=>['option__bitrate'], 'azure'=>['option__fixedMood','option__region','option__volume','option__rate','option__countour','option__validMoods'], 'kokoro'=>['option__speed'], 'koboldcpp'=>[],
];
$providerTitles = ['inworld'=>'Inworld TTS','cartesia'=>'Cartesia TTS','openai'=>'OpenAI TTS',
    '11labs'=>'ElevenLabs Text-To-Speech','azure'=>'Azure Text-To-Speech','deepgram'=>'Deepgram TTS',
    'piper-tts'=>'Piper-TTS','mimic3'=>'MIMIC3','zonos_gradio'=>'Zonos TTS','kokoro'=>'KOKORO 82M','koboldcpp'=>'KoboldCPP TTS'];
$languageChoices = [
    'inworld'=>['en-US','zh-CN','ko-KR','ja-JP','ru-RU','it-IT','es-ES','pt-BR','de-DE','fr-FR','ar-SA','pl-PL','nl-NL','hi-IN','he-IL'],
    'cartesia'=>['en','fr','de','es','pt','zh','ja','hi','it','ko','nl','pl','ru','sv','tr','tl','bg','ro','ar','cs','el','fi','hr','ms','sk','da','ta','uk','hu','no','vi','bn','th','he','ka','id','te','gu','kn','ml','mr','pa'],
    'melotts'=>['EN','ES','FR','ZH','JP','KR'],
    'zonos_gradio'=>['af','am','an','ar','as','az','ba','bg','bn','bpy','bs','ca','cmn','cs','cy','da','de','el',
        'en-029','en-gb','en-gb-scotland','en-gb-x-gbclan','en-gb-x-gbcwmd','en-gb-x-rp','en-us','eo','es','es-419','et','eu',
        'fa','fa-latn','fi','fr-be','fr-ch','fr-fr','ga','gd','gn','grc','gu','hak','hi','hr','ht','hu','hy','hyw','ia','id','is',
        'it','ja','jbo','ka','kk','kl','kn','ko','kok','ku','ky','la','lfn','lt','lv','mi','mk','ml','mr','ms','mt','my','nb',
        'nci','ne','nl','om','or','pa','pap','pl','pt','pt-br','py','quc','ro','ru','ru-lv','sd','shn','si','sk','sl','sq','sr',
        'sv','sw','ta','te','tn','tr','tt','ur','uz','vi','vi-vn-x-central','vi-vn-x-south','yue'],
];
$modelChoices = [
    'inworld'=>['inworld-tts-1','inworld-tts-1-max','inworld-tts-1.5-mini','inworld-tts-1.5-max','inworld-tts-2'],
    'cartesia'=>['sonic-3','sonic-english','sonic-multilingual'],
    'openai'=>['tts-1','tts-1-hd','gpt-4o-mini-tts'],
    'zonos_gradio'=>['Zyphra/Zonos-v0.1-transformer','Zyphra/Zonos-v0.1-hybrid'],
];
$fieldHelp = [
    'pockettts'=>['model'=>'audio.cpp model id. Default: pocket-tts.'],
    'inworld'=>['option__workspace'=>'Inworld workspace ID for voice cloning. Format: workspaces/{workspace} or just the workspace ID. Leave blank to keep account-default routing.',
        'language'=>'Language to use for TTS generation. Uses BCP-47 regional language codes.',
        'model'=>'Inworld model to use. inworld-tts-2 is the higher quality model.',
        'option__temperature'=>'Sampling temperature (0-2). Higher values make output more random. Default: 1.0',
        'option__speed'=>'Speaking rate/speed (0.5-1.5). Default: 1.0'],
    'cartesia'=>['language'=>'Language to use for TTS generation. Sonic 3 supports 42 languages.', 'model'=>'Cartesia model to use. sonic-3 is the latest model with 42 languages, volume/speed/emotion controls. Use sonic-3-2025-10-27 to pin a specific snapshot.', 'option__speed'=>'Speaking speed for the voice'],
    'openai'=>['model'=>'Model','option__instructions'=>'Control the voice of your generated audio with additional instructions. Does not work with tts-1 or tts-1-hd.'],
    '11labs'=>[
        'option__optimize_streaming_latency'=>'Reduces response delay at some cost to quality and text handling. Use 0 for default behavior; higher values favor speed more aggressively.',
        'model'=>'ElevenLabs model to use for this connector. Use eleven_v3 if you want V3 enhancers or audio tags.',
        'option__stability'=>'Controls how consistent each generation sounds. Lower values are more expressive, while higher values are steadier and less varied.',
        'option__similarity_boost'=>'Controls how closely the output sticks to the selected voice. Higher values usually sound more like the source voice, but can reduce flexibility.',
        'option__style'=>'Adds extra stylization and exaggeration to the delivery. Higher values can sound more dramatic, but may increase latency.',
        'option__speed'=>'Adjusts the speaking rate of the generated audio. 1.0 is normal speed.',
        'option__use_speaker_boost'=>'Boosts resemblance to the original voice at a small latency cost. Ignored by eleven_v3.',
        'option__apply_text_normalization'=>'Controls whether numbers, dates, abbreviations, and similar text are rewritten before speech. Auto lets ElevenLabs decide, on always normalizes, and off keeps the raw text.',
        'option__apply_language_text_normalization'=>'Enables extra language-specific cleanup before synthesis. This is mainly useful for languages that need special text handling, such as Japanese.',
        'option__v3_audio_tags'=>'Optional Eleven v3 prompt tags added before the text, such as [whispers] or [curious]. Only used when model_id is eleven_v3.',
    ],
    'kokoro'=>['option__speed'=>'Speed'],
    'deepgram'=>['option__bitrate'=>'Output sample rate (Hz): 8000, 16000, 24000, 32000 or 48000.'],
    'azure'=>['option__fixedMood'=>'Force mood (voice style)',
        'option__region'=>'Region location of your API key. Leave blank to use the advanced endpoint.',
        'option__volume'=>'Volume', 'option__rate'=>'Talk speed', 'option__countour'=>'Voice contour',
        'option__validMoods'=>'Allowed voice styles'],
    'melotts'=>['language'=>'Language Model. Should be EN if using default installation','option__speed'=>'Speech Speed'],
    'mimic3'=>['option__rate'=>'Voice speed'],
    'zonos_gradio'=>['language'=>'Language','model'=>'Model to use.',
        'option__pitch_std'=>'Pitch standard deviation [0-200]',
        'option__speaking_rate'=>'Speaking rate. Higher is faster. [1-40]',
        'option__cfg_scale'=>'CFG scale. Controls how closely the audio matches the sample voice. Higher numbers will be a closer match. [0-20]'],
    'piper-tts'=>[
        'option__length_scale'=>'speaking time scale. Use a value over 1.0 to play slower, a value under 1.0 is faster.',
        'option__noise_scale'=>'speaking variability. Leave 0 to use voice model internal value. Experiment with values around 0.667',
        'option__noise_w_scale'=>'phoneme width variability. Leave 0 to use voice model internal value. Experiment with values around 0.8',
        'option__speaker'=>'Name of speaker for multi-speaker voices (if you have an .onnx voice file with multiple voices).',
        'option__speaker_id'=>'Speaker id for multi-speaker voices, overrides speaker name if used. 0 is default (first) speaker.',
    ],
    'xvasynth'=>[
        'language'=>'Base language','option__model_type'=>'Model Type',
        'option__version'=>'xVASynth version (e.g. 3.0 is default. Older models are 1.0 or 2.0)',
        'option__game'=>'xVASynth gameID (e.g. morrowind)','option__pace'=>'Pace',
        'option__waveglow_path'=>'Wave Glow Path (relative)','option__vocoder'=>'Vocoder','option__distro'=>'Leave as default!',
    ],
];

/** Render one provider control in either the primary grid or the preserved advanced section. */
function lorkhan_tts_provider_field(array $field, mixed $value, string $driver, bool $active, string $formId): void
{
    $id = 'tts-' . $driver . '-' . $field['name'];
    $type = $field['type'];
    if ($driver === 'omnivoice' && $field['name'] === 'language') {
        $languages = \LorkhanServer\Application\OmniVoiceLanguages::available();
        if ($languages !== []) {
            $type = 'select';
            $field['values'] = array_keys($languages);
            $field['choice_labels'] = $languages;
            $field['help'] = isset($languages[(string)$value])
                ? 'Select an available local OmniVoice language profile.'
                : 'Saved language '.(string)$value.' is not available as an OmniVoice profile. It is retained until you choose another language.';
        } else {
            $field['help'] = 'No OmniVoice language profiles were found in /home/dwemer/omnivoice-tts/languages.';
        }
    }
    ?><div class="field-block"><label for="<?php echo lorkhan_ui_h($id); ?>"><?php echo lorkhan_ui_h($field['label']); ?></label>
    <?php if ($type === 'multiselect'): ?>
        <select multiple id="<?= lorkhan_ui_h($id) ?>" name="<?= lorkhan_ui_h($field['name']) ?>[]" form="<?= lorkhan_ui_h($formId) ?>"<?= $active ? '' : ' disabled' ?>>
            <?php foreach ($field['values'] as $choice): ?><option value="<?= lorkhan_ui_h($choice) ?>"<?= is_array($value) && in_array($choice,$value,true) ? ' selected' : '' ?>><?= lorkhan_ui_h($choice) ?></option><?php endforeach; ?>
        </select>
    <?php elseif ($type === 'select'): ?>
        <select id="<?php echo lorkhan_ui_h($id); ?>" name="<?php echo lorkhan_ui_h($field['name']); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"<?php echo $active ? '' : ' disabled'; ?>>
            <?php $choices = $field['values']; if (!in_array((string)$value, $choices, true)) array_unshift($choices, (string)$value); ?>
            <?php foreach ($choices as $choice): ?><option value="<?php echo lorkhan_ui_h($choice); ?>"<?php echo (string)$value === $choice ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($choice === '' ? 'Connector default' : ($field['choice_labels'][$choice] ?? $choice)); ?></option><?php endforeach; ?>
        </select>
    <?php elseif ($type === 'boolean'): ?>
        <select id="<?php echo lorkhan_ui_h($id); ?>" name="<?php echo lorkhan_ui_h($field['name']); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"<?php echo $active ? '' : ' disabled'; ?>><option value="true"<?php echo $value === true ? ' selected' : ''; ?>>Enabled</option><option value="false"<?php echo $value !== true ? ' selected' : ''; ?>>Disabled</option></select>
    <?php elseif ($type === 'longstring'): ?>
        <textarea id="<?php echo lorkhan_ui_h($id); ?>" name="<?php echo lorkhan_ui_h($field['name']); ?>" maxlength="<?php echo (int)$field['maxlength']; ?>" form="<?php echo lorkhan_ui_h($formId); ?>"<?php echo $active ? '' : ' disabled'; ?>><?php echo lorkhan_ui_h($value); ?></textarea>
    <?php else: ?>
        <input id="<?php echo lorkhan_ui_h($id); ?>" name="<?php echo lorkhan_ui_h($field['name']); ?>" type="<?php echo in_array($type, ['number','integer'], true) ? 'number' : 'text'; ?>" value="<?php echo lorkhan_ui_h($value); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"<?php echo $active ? '' : ' disabled'; ?>
            <?php if (in_array($type, ['number','integer'], true)): ?>step="<?php echo $type === 'integer' ? '1' : 'any'; ?>" min="<?php echo lorkhan_ui_h($field['minimum']); ?>" max="<?php echo lorkhan_ui_h($field['maximum']); ?>"<?php else: ?>maxlength="<?php echo (int)($field['maxlength'] ?? 512); ?>"<?php endif; ?>>
    <?php endif; ?>
    <?php if (!empty($field['help'])): ?><div class="field-help"><?php echo lorkhan_ui_h($field['help']); ?></div><?php endif; ?>
    </div><?php
}
?>
<div data-tts-provider-editor data-selected-driver="<?php echo lorkhan_ui_h($currentDriver); ?>" data-connector-defaults="<?php echo lorkhan_ui_h(json_encode($connectorDefaults, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); ?>">
<?php foreach ($drivers as $providerDriver => $providerLabel):
    $activeDriver = $providerDriver === $currentDriver;
    $providerContent = $activeDriver ? $content : $connectorDefaults[$providerDriver];
    $providerOptions = $activeDriver ? $options : [];
    $fields = [
        'model'=>['name'=>'model','label'=>in_array($providerDriver,['inworld','cartesia','openai','11labs'],true) ? 'Model Id' : 'Model','type'=>'string','maxlength'=>256],
        'voice'=>['name'=>'voice','label'=>'Default Voice','type'=>'string'],
        'language'=>['name'=>'language','label'=>$providerDriver === 'xvasynth' ? 'Base Lang' : 'Language','type'=>'string','maxlength'=>35],
        'timeout_ms'=>['name'=>'timeout_ms','label'=>'Timeout (ms)','type'=>'integer','minimum'=>1000,'maximum'=>120000],
    ];
    if (isset($languageChoices[$providerDriver])) $fields['language'] += ['values'=>$languageChoices[$providerDriver]];
    if (isset($modelChoices[$providerDriver])) $fields['model'] += ['values'=>$modelChoices[$providerDriver]];
    foreach (['language','model'] as $fieldName) if (isset($fields[$fieldName]['values'])) $fields[$fieldName]['type'] = 'select';
    if ($providerDriver === 'inworld') $fields['model']['choice_labels'] = ['inworld-tts-1'=>'inworld-tts-1 (deprecated)','inworld-tts-1-max'=>'inworld-tts-1-max (deprecated)'];
    foreach ($optionCatalog[$providerDriver] as $field) {
        $field['name'] = 'option__' . $field['name'];
        $fields[$field['name']] = $field;
    }
    // Herika presents latency as a text box; the native save path still validates the integer 0-4.
    if ($providerDriver === '11labs') $fields['option__optimize_streaming_latency']['type'] = 'string';
    if ($providerDriver === 'xvasynth') foreach (['model_type'=>'Modeltype','version'=>'Version','game'=>'Game','waveglow_path'=>'Waveglowpath','distro'=>'Distroname'] as $name=>$label) $fields['option__'.$name]['label']=$label;
    if ($providerDriver === 'piper-tts') foreach (['length_scale'=>'Length Scale','noise_scale'=>'Noise Scale','noise_w_scale'=>'Noise W Scale','speaker_id'=>'Speaker Id'] as $name=>$label) $fields['option__'.$name]['label']=$label;
    if ($providerDriver === 'zonos_gradio') foreach (['pitch_std'=>'Pitch Std','speaking_rate'=>'Speaking Rate','cfg_scale'=>'Cfg Scale'] as $name=>$label) $fields['option__'.$name]['label']=$label;
    foreach ($fieldHelp[$providerDriver] ?? [] as $name=>$help) $fields[$name]['help']=$help;
    if (in_array($providerDriver,['chatterbox','xtts-fastapi'],true)) {
        $fields['option__paralinguistic_tags_enabled']['help']='Enable paralinguistic tags like [laugh], [sigh] for expressive TTS output.';
        $fields['option__paralinguistic_tags_prompt']['help']='Prompt snippet instructing the LLM to use paralinguistic tags. Added to system prompt when enabled.';
        $fields['option__paralinguistic_tags_list']['help']='Comma-separated list of supported paralinguistic tags (e.g., [laugh],[sigh],[gasp]). Tags are case-insensitive.';
    }
    $primary = $primaryFields[$providerDriver] ?? array_keys($fields);
    $advanced = array_diff(array_keys($fields), $primary);
    $values = $providerContent + $connectorDefaults[$providerDriver] + ['timeout_ms'=>30000];
    foreach ($providerOptions as $name=>$value) $values['option__'.$name]=$value;
    if (in_array($providerDriver,['chatterbox','xtts-fastapi'],true)) $values += [
        'option__paralinguistic_tags_list'=>\LorkhanServer\Application\ParalinguisticSpeech::DEFAULT_TAGS];
    if ($providerDriver === 'inworld') $values += ['option__temperature'=>1.0,'option__speed'=>1.0];
    if ($providerDriver === 'azure' && (!$activeDriver || $creating)) $values += ['option__validMoods'=>['whispering','default','dazed']];
    if ($providerDriver === 'cartesia') $values += ['option__speed'=>'normal'];
    if ($providerDriver === 'kokoro') $values += ['option__speed'=>1.0];
    if ($providerDriver === 'deepgram' && (!$activeDriver || $creating)) $values += ['option__bitrate'=>32000];
    // Display effective native defaults without inventing values for optional provider parameters.
    if ($providerDriver === 'mimic3') $values += ['option__rate'=>1.0];
    if ($providerDriver === 'melotts') $values += ['option__speed'=>1.0];
    if ($providerDriver === 'piper-tts') $values += ['option__length_scale'=>1.0];
    if ($providerDriver === 'xvasynth') $values += ['option__model_type'=>'xVAPitch','option__version'=>'3.0',
        'option__game'=>'morrowind','option__pace'=>1.0,'option__distro'=>'DwemerAI4Skyrim3'];
    if ($providerDriver === 'azure' && (!$activeDriver || $creating)) $values += [
        'option__region'=>'westeurope','option__volume'=>20,'option__rate'=>1.25,
        'option__countour'=>'(11%, +15%) (60%, -23%) (80%, -34%)',
    ];
    if ($providerDriver === '11labs' && (!$activeDriver || $creating)) $values += [
        'option__optimize_streaming_latency'=>0,'option__stability'=>0.75,'option__similarity_boost'=>0.75,
        'option__style'=>0.0,'option__speed'=>1.0,'option__use_speaker_boost'=>true,
        'option__apply_text_normalization'=>'auto','option__apply_language_text_normalization'=>false,
    ];
?>
    <section class="meta-group<?php echo $activeDriver ? ' active' : ''; ?> runtime-settings" data-tts-provider-fields="<?php echo lorkhan_ui_h($providerDriver); ?>"<?php echo $activeDriver ? '' : ' hidden'; ?>>
        <h3><?php echo lorkhan_ui_h($providerTitles[$providerDriver] ?? $providerLabel); ?> Settings</h3>
        <?php if ($primary === []): ?><div class="settings-empty-note">Additional runtime options are available below.</div><?php else: ?>
        <div class="inline-two"><?php foreach ($primary as $name) lorkhan_tts_provider_field($fields[$name], $values[$name] ?? '', $providerDriver, $activeDriver, $formId); ?></div>
        <?php endif; ?>
        <details class="advanced-json"><summary>Advanced connector options</summary>
            <div class="inline-two"><?php foreach ($advanced as $name) lorkhan_tts_provider_field($fields[$name], $values[$name] ?? '', $providerDriver, $activeDriver, $formId); ?></div>
            <div data-tts-advanced-endpoint="<?php echo lorkhan_ui_h($providerDriver); ?>"><?php if ($activeDriver && in_array($currentDriver, $cloudDrivers, true)) lorkhan_tts_endpoint_field($content, $defaults, $formId); ?></div>
            <div class="field-block"><label for="tts-options-<?php echo lorkhan_ui_h($providerDriver); ?>">Connector options (JSON)</label><textarea id="tts-options-<?php echo lorkhan_ui_h($providerDriver); ?>" name="options_json" form="<?php echo lorkhan_ui_h($formId); ?>"<?php echo $activeDriver ? '' : ' disabled'; ?>><?php echo lorkhan_ui_h(json_encode($providerOptions === [] ? (object)[] : $providerOptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea></div>
        </details>
    </section>
<?php endforeach; ?>
</div>
