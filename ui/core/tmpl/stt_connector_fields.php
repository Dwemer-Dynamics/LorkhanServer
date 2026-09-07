<?php
declare(strict_types=1);

// Provider field order/types follow Herika's pinned STT schema; transport extras stay advanced.
$modelChoices = [
    'deepgram'=>['nova-3','nova-2','whisper-medium','enhanced','nova','base'],
    'gemini'=>['gemini-2.5-flash','gemini-2.5-flash-lite','gemini-2.5-pro'],
];
$fieldOrder = [
    'none'=>[], 'localwhisper'=>['option__file_field'], 'parakeet'=>['language'],
    'whisper'=>['language','option__translate'], 'azure'=>['language','option__profanity'],
    'deepgram'=>['language','model'], 'gemini'=>['language','model'], 'inworld'=>['model','language'],
];
$badgeLabels = ['LORKHAN_TTS_OPENAI_API_KEY'=>'OpenAI speech key','LORKHAN_TTS_DEEPGRAM_API_KEY'=>'Deepgram',
    'LORKHAN_TTS_AZURE_API_KEY'=>'Azure','LORKHAN_STT_GEMINI_API_KEY'=>'Gemini STT',
    'LORKHAN_TTS_INWORLD_API_KEY'=>'Inworld','LORKHAN_TTS_GCP_API_KEY'=>'Google',
    'LORKHAN_LLM_API_KEY'=>'Default LLM key (OpenRouter)','LORKHAN_STT_API_KEY'=>'Default STT key'];
$badgeChoices = $credentialStatuses;
foreach ($badgeChoices as $variable=>&$status) $status['label']=$status['label']??$badgeLabels[$variable] ?? ucwords(strtolower(str_replace('_',' ',preg_replace('/^LORKHAN_|_API_KEY$/','',$variable))));
unset($status);
uasort($badgeChoices,static fn(array $a,array $b):int=>($b['configured']<=>$a['configured'])?:strnatcasecmp($a['label'],$b['label']));
foreach ($groups as $providers) foreach ($providers as [$panelDriver,$panelName,$panelBadge,$panelTitle]) {
    $panelContent = ($content['driver'] ?? '') === $panelDriver ? $content : $defaults[$panelDriver];
    $panelOptions = is_array($panelContent['options'] ?? null) ? $panelContent['options'] : [];
    $disabled = $panelDriver === $activeDriver ? '' : ' disabled';
    $panelCredential = (string) ($definitions[$panelDriver]['credential_environment'] ?? '');
    $selectedCredential = (string) ($panelContent['credential'] ?? ($panelCredential!==''?$panelCredential:'none'));
    $panelStatus = $credentialStatuses[$selectedCredential] ?? null;
    $panelBadgeChoices=$badgeChoices;
    if($selectedCredential!=='none'&&!isset($panelBadgeChoices[$selectedCredential]))$panelBadgeChoices[$selectedCredential]=['label'=>$badgeLabels[$selectedCredential]??$selectedCredential,'configured'=>false];
    $fields = [
        'language'=>['label'=>$panelDriver==='inworld'?'Language':'Lang', 'type'=>'text', 'value'=>$panelContent['language'] ?? 'en', 'maxlength'=>35],
        'model'=>['label'=>$panelDriver==='inworld'?'Model Id':'Model', 'type'=>isset($modelChoices[$panelDriver])?'select':'text', 'value'=>$panelContent['model'] ?? '', 'maxlength'=>256, 'values'=>$modelChoices[$panelDriver] ?? []],
        'timeout_ms'=>['label'=>'Timeout (ms)', 'type'=>'number', 'value'=>$panelContent['timeout_ms'] ?? 30000, 'min'=>1000, 'max'=>120000],
        'endpoint'=>['label'=>'URL', 'type'=>'text', 'value'=>$panelContent['endpoint'] ?? $defaults[$panelDriver]['endpoint'], 'maxlength'=>2048],
    ];
    $fields['language']['help']=match($panelDriver){'inworld'=>'Language code in BCP-47 format, e.g. en-US. Leave blank for auto-detect.','gemini'=>'Language (e.g., en, es, ja)','deepgram'=>'Language',default=>'Language to detect for STT.'};
    $fields['model']['help']=match($panelDriver){'inworld'=>'Model identifier in provider/model format, e.g. groq/whisper-large-v3.','gemini'=>'Gemini model. 2.5 Flash recommended for best speed and quality.',default=>'Model to use'};
    $fields['endpoint']['help']='Used for local or remote STT endpoints such as Local Whisper.';
    foreach ($optionCatalog[$panelDriver] as $option) {
        $optionName = (string) $option['name'];
        $boolean = $option['type'] === 'boolean';
        $fields['option__'.$optionName] = ['label'=>match($optionName) {'file_field'=>'Formfield','translate'=>'Translate','profanity'=>'Profanity',default=>$option['label']},
            'type'=>'select', 'value'=>$boolean ? (($panelOptions[$optionName] ?? false) ? '1' : '0') : ($panelOptions[$optionName] ?? $option['values'][0] ?? ''),
            'values'=>$boolean ? ['0','1'] : $option['values'], 'boolean'=>$boolean];
        $fields['option__'.$optionName]['help']=match($optionName){'file_field'=>'Form field name for the audio file. Some Whisper implementations require file instead of audio_file.',
            'translate'=>'Will try to translate to English.','profanity'=>'Masked replaces profanity with asterisks; removed omits it; raw includes it.',
            default=>'Prefix detected vocal tone in the transcript.'};
    }
    // A shared renderer gives every submitted field one stable, driver-specific label target.
    $renderField = static function (string $name) use ($fields,$panelDriver,$disabled): void {
        $field=$fields[$name];$id='stt-'.$panelDriver.'-'.$name;$value=(string)$field['value'];
        echo '<div class="field-block"><label for="'.lorkhan_ui_h($id).'">'.lorkhan_ui_h($field['label']).'</label>';
        if ($field['type']==='select') {
            $choices=$field['values'];if(!in_array($value,$choices,true)&&$value!=='')array_unshift($choices,$value);
            echo '<select id="'.lorkhan_ui_h($id).'" name="'.lorkhan_ui_h($name).'"'.$disabled.'>';
            foreach($choices as $choice)echo '<option value="'.lorkhan_ui_h($choice).'"'.($value===(string)$choice?' selected':'').'>'.lorkhan_ui_h(($field['boolean']??false)?($choice==='1'?'True':'False'):$choice).'</option>';
            echo '</select>';
        } else {
            echo '<input id="'.lorkhan_ui_h($id).'" name="'.lorkhan_ui_h($name).'" type="'.$field['type'].'" value="'.lorkhan_ui_h($value).'"'.$disabled;
            foreach(['maxlength','min','max'] as $attribute)if(isset($field[$attribute]))echo ' '.$attribute.'="'.(int)$field[$attribute].'"';
            echo '>';
        }
        if(isset($field['help']))echo '<div class="field-help">'.lorkhan_ui_h($field['help']).'</div>';
        echo '</div>';
    };
    $visible = $fieldOrder[$panelDriver];
    $advanced = array_values(array_diff(array_keys($fields),$visible,$panelDriver==='localwhisper'?['endpoint']:[]));
    // The same badge control is advanced only for local services whose reference omits it.
    $renderBadge = static function () use ($panelDriver,$selectedCredential,$panelStatus,$panelBadgeChoices,$disabled,$webRoot): void {
?>
  <div class="field-block"><label for="stt-<?php echo lorkhan_ui_h($panelDriver); ?>-api-badge">API Badge</label>
   <select id="stt-<?php echo lorkhan_ui_h($panelDriver); ?>-api-badge" name="credential" data-stt-badge<?php echo $disabled; ?>>
    <option value="none" data-configured="0"<?php echo $selectedCredential==='none'?' selected':''; ?>>-- None --</option>
    <?php $missingHeading=false; foreach($panelBadgeChoices as $variable=>$status): if(!$status['configured']&&!$missingHeading):$missingHeading=true; ?><option disabled>— Missing Key —</option><?php endif; ?>
    <option value="<?php echo lorkhan_ui_h($variable); ?>" data-configured="<?php echo $status['configured']?'1':'0'; ?>"<?php echo $selectedCredential===$variable?' selected':''; ?>><?php echo lorkhan_ui_h(($status['configured']?'🟢 ':'🔴 ').$status['label'].($status['configured']?'':' — No key')); ?></option>
    <?php endforeach; ?>
   </select>
   <div data-stt-badge-notice class="api-key-notice <?php echo ($panelStatus['configured']??false)?'ok':'warn'; ?>"><?php echo $selectedCredential==='none'?'No API key selected. Some STT services require one.':(($panelStatus['configured']??false)?'Selected API badge is configured.':'Selected API badge does not have a configured key yet.'); ?></div>
   <div class="field-help">Cloud STT services require an API key from the <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/api_keys.php">API Keys</a> page. Only the selected key reference is saved; key values remain private.</div>
  </div>
<?php };
?>
<section data-stt-driver-fields="<?php echo lorkhan_ui_h($panelDriver); ?>"<?php echo $panelDriver===$activeDriver?'':' hidden'; ?>>
 <div class="editor-grid">
  <?php if($panelDriver==='localwhisper') $renderField('endpoint'); ?>
  <?php if($panelCredential!=='' && $panelDriver!=='parakeet') $renderBadge(); ?>
 </div>
 <div class="meta-group active"><h3><?php echo lorkhan_ui_h($panelTitle); ?> Settings</h3>
  <?php if($visible===[]): ?><div class="settings-empty-note">This STT provider does not have connector-level settings to configure here.</div>
  <?php else: ?><div class="inline-two"><?php foreach($visible as $field) $renderField($field); ?></div><?php endif; ?>
  <details class="advanced-json"><summary>Advanced connector options</summary>
   <?php if($panelCredential==='' || $panelDriver==='parakeet') $renderBadge(); ?>
   <div class="inline-two"><?php foreach($advanced as $field) $renderField($field); ?></div>
   <div class="field-block"><label for="stt-<?php echo lorkhan_ui_h($panelDriver); ?>-options">Connector options (JSON)</label><textarea id="stt-<?php echo lorkhan_ui_h($panelDriver); ?>-options" name="options_json"<?php echo $disabled; ?>><?php echo lorkhan_ui_h(json_encode($panelOptions===[]?(object)[]:$panelOptions,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></textarea></div>
  </details>
 </div>
</section>
<?php } ?>
