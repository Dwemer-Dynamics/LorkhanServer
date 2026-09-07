<?php

declare(strict_types=1);

// Provider field order derives from HerikaServer 529364c conf_schema.json and core/tts_connectors.php.
// Names map to Lorkhan's existing typed connector document; non-counterpart runtime fields stay advanced.
$primaryFields = [
    'pockettts'=>['model'], 'omnivoice'=>['language'], 'chatterbox'=>[], 'xtts-fastapi'=>[],
    'inworld'=>['option__workspace','language','model','option__temperature','option__speed'],
    'cartesia'=>['language','model','option__speed'], 'openai'=>['model'],
    '11labs'=>['model','option__stability','option__similarity_boost','option__style','option__use_speaker_boost'],
    'melotts'=>['language','option__speed'], 'mimic3'=>['option__rate'],
    'piper-tts'=>['option__length_scale','option__noise_scale','option__noise_w_scale','option__speaker','option__speaker_id'],
    'xvasynth'=>['language','option__model_type','option__version','option__game','option__pace','option__waveglow_path','option__vocoder','option__distro'],
    'zonos_gradio'=>['language','model','option__pitch_std','option__speaking_rate','option__cfg_scale'],
    'deepgram'=>[], 'azure'=>[], 'kokoro'=>[], 'koboldcpp'=>[],
];
$providerTitles = ['inworld'=>'Inworld TTS','cartesia'=>'Cartesia TTS','openai'=>'OpenAI TTS',
    '11labs'=>'ElevenLabs Text-To-Speech','azure'=>'Azure Text-To-Speech','deepgram'=>'Deepgram TTS',
    'piper-tts'=>'Piper-TTS','mimic3'=>'MIMIC3','zonos_gradio'=>'Zonos TTS','kokoro'=>'KOKORO 82M','koboldcpp'=>'KoboldCPP TTS'];
$languageChoices = [
    'inworld'=>['en-US','zh-CN','ko-KR','ja-JP','ru-RU','it-IT','es-ES','pt-BR','de-DE','fr-FR','ar-SA','pl-PL','nl-NL','hi-IN','he-IL'],
    'cartesia'=>['en','fr','de','es','pt','zh','ja','hi','it','ko','nl','pl','ru','sv','tr','tl','bg','ro','ar','cs','el','fi','hr','ms','sk','da','ta','uk','hu','no','vi','bn','th','he','ka','id','te','gu','kn','ml','mr','pa'],
    'melotts'=>['EN','ES','FR','ZH','JP','KR'],
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
    'openai'=>['model'=>'Model'], 'melotts'=>['language'=>'Language Model. Should be EN if using default installation','option__speed'=>'Speech Speed'],
];

/** Render one provider control in either the primary grid or the preserved advanced section. */
function lorkhan_tts_provider_field(array $field, mixed $value, string $driver, bool $active, string $formId): void
{
    $id = 'tts-' . $driver . '-' . $field['name'];
    $type = $field['type'];
    ?><div class="field-block"><label for="<?php echo lorkhan_ui_h($id); ?>"><?php echo lorkhan_ui_h($field['label']); ?></label>
    <?php if ($type === 'select'): ?>
        <select id="<?php echo lorkhan_ui_h($id); ?>" name="<?php echo lorkhan_ui_h($field['name']); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"<?php echo $active ? '' : ' disabled'; ?>>
            <?php $choices = $field['values']; if (!in_array((string)$value, $choices, true)) array_unshift($choices, (string)$value); ?>
            <?php foreach ($choices as $choice): ?><option value="<?php echo lorkhan_ui_h($choice); ?>"<?php echo (string)$value === $choice ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($choice === '' ? 'Connector default' : ($field['choice_labels'][$choice] ?? $choice)); ?></option><?php endforeach; ?>
        </select>
    <?php elseif ($type === 'boolean'): ?>
        <select id="<?php echo lorkhan_ui_h($id); ?>" name="<?php echo lorkhan_ui_h($field['name']); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"<?php echo $active ? '' : ' disabled'; ?>><option value="false"<?php echo $value !== true ? ' selected' : ''; ?>>False</option><option value="true"<?php echo $value === true ? ' selected' : ''; ?>>True</option></select>
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
    if ($providerDriver === 'xvasynth') foreach (['model_type'=>'Modeltype','waveglow_path'=>'Waveglowpath','distro'=>'Distroname'] as $name=>$label) $fields['option__'.$name]['label']=$label;
    foreach ($fieldHelp[$providerDriver] ?? [] as $name=>$help) $fields[$name]['help']=$help;
    $primary = $primaryFields[$providerDriver] ?? array_keys($fields);
    $advanced = array_diff(array_keys($fields), $primary);
    $values = $providerContent + $connectorDefaults[$providerDriver] + ['timeout_ms'=>30000];
    foreach ($providerOptions as $name=>$value) $values['option__'.$name]=$value;
    if ($providerDriver === 'inworld') $values += ['option__temperature'=>1.0,'option__speed'=>1.0];
    if ($providerDriver === 'cartesia') $values += ['option__speed'=>'normal'];
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
