<?php

declare(strict_types=1);

use LorkhanServer\Application\ConnectorCatalog;
use LorkhanServer\Application\CredentialStore;
use LorkhanServer\Application\SttTestSample;

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'STT Connector';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page excluded-connector-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$installationId = trim((string) ($_GET['installation_id'] ?? ''));
if ($installationId === '' && isset($installations[0])) $installationId = (string) $installations[0]['installation_id'];
$rows = array_values(array_filter($uiRepository->rows('stt'), static fn(array $row): bool =>
    ($row['installation_id'] ?? '') === $installationId));
$selected = null;
foreach ($rows as $row) {
    if (filter_var($row['active'] ?? false, FILTER_VALIDATE_BOOL)) { $selected = $row; break; }
}
$selected ??= $rows[0] ?? null;
$content = is_array($selected['content'] ?? null) ? $selected['content'] : [];
$activeDriver = (string) ($_GET['driver'] ?? ($content['driver'] ?? 'deepgram'));

$groups = [
    'Recommended' => [['deepgram','Deepgram','DEEPGRAM',"Deepgram's Whisper Speech-to-Text"],
        ['parakeet','Parakeet','PARAKEET','parakeet-api-server']],
    'Other Services' => [['whisper','Whisper','WHISPER',"OpenAI's Whisper"],
        ['localwhisper','Local Whisper','LOCALWHISPER','Local Whisper (Installed in DwemerDistro)'],
        ['gemini','Gemini','GEMINI','Google Gemini STT + Emotion Detection'],
        ['azure','Azure','AZURE','Azure Speech-to-Text'],['inworld','Inworld','INWORLD','Inworld STT']],
    'System' => [['none','Disabled','SYSTEM','Disabled']],
];
$definitions = [];$defaults = [];$optionCatalog = [];
foreach (ConnectorCatalog::all('stt_provider') as $definition) {
    $driver = (string) $definition['driver'];$definitions[$driver] = $definition;
    $defaults[$driver] = ConnectorCatalog::defaults('stt_provider', $driver);
    $optionCatalog[$driver] = ConnectorCatalog::optionFields('stt_provider', $driver);
}
if (!isset($definitions[$activeDriver])) $activeDriver = 'deepgram';
$driverDefaults = $defaults[$activeDriver];
$sameDriver = ($content['driver'] ?? '') === $activeDriver;
$options = $sameDriver && is_array($content['options'] ?? null) ? $content['options'] : [];
$credentialVariable = (string) ($definitions[$activeDriver]['credential_environment'] ?? '');
$credentialStatus = null;
if ($credentialVariable !== '') {
    $store = new CredentialStore((string) $config['credential_storage_path']);
    foreach ($store->statuses() as $status) if ($status['variable'] === $credentialVariable) $credentialStatus = $status;
}
$pageUrl = $webRoot . '/ui/core/stt_connectors.php';
$queryFor = static function (array $query) use ($pageUrl,$installationId,$embedded): string {
    if ($installationId !== '') $query['installation_id']=$installationId;if($embedded)$query['embed']='1';
    return $pageUrl.'?'.http_build_query($query);
};

$additionalStylesheets = ['herika-excluded-connectors.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-excluded-connectors.css')];
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="excluded-connector-page<?php echo $embedded ? ' embedded' : ''; ?>">
 <div class="page-shell">
  <header class="page-header"><h1 class="api-title">STT Connector</h1><p class="page-subtitle">Speech-to-Text Setup Options.</p></header>
  <?php if (isset($_GET['status'])): ?><div class="notice" role="status"><?php echo lorkhan_ui_h($_GET['status']==='tested'?'Test completed: '.($_GET['detail']??'transcription received'):'STT connector saved.'); ?></div><?php endif; ?>
  <?php if ($installations === []): ?><div class="placeholder">Connect OpenMW once before configuring speech-to-text.</div><?php else: ?>
  <label class="visually-hidden">Installation<select data-installation-select><?php foreach($installations as $installation): ?><option value="<?php echo lorkhan_ui_h($installation['installation_id']); ?>"<?php echo $installation['installation_id']===$installationId?' selected':''; ?>><?php echo lorkhan_ui_h($installation['display_name']); ?></option><?php endforeach; ?></select></label>
  <div class="layout">
   <aside class="left-col"><div class="summary-note">This page edits the single installation-global STT connector. Switching services revises the active connector instead of creating parallel records.</div>
    <div class="list-wrap" id="stt_driver_list"><?php foreach($groups as $group=>$providers): ?><div class="group-title"><?php echo lorkhan_ui_h($group); ?></div><?php foreach($providers as [$driver,$name,$badge,$description]): ?>
     <a class="conn-card<?php echo $driver===$activeDriver?' active':''; ?>" href="<?php echo lorkhan_ui_h($queryFor(['driver'=>$driver])); ?>"><span class="conn-head"><span class="conn-name"><?php echo lorkhan_ui_h($name); ?></span><span class="conn-badge"><?php echo lorkhan_ui_h($badge); ?></span></span><span class="conn-sub"><?php echo lorkhan_ui_h($description); ?></span></a>
    <?php endforeach; endforeach; ?></div>
   </aside>
   <section class="right-col">
    <?php if ($selected === null): ?><div class="placeholder">The global STT connector has not been provisioned yet. Reconnect OpenMW or run the current database migrations.</div><?php else: ?>
    <div class="btn-row"><button class="btn-save" type="submit" form="stt-form">Save</button><button class="btn-primary" id="stt-test-open" type="button" aria-haspopup="dialog" aria-controls="stt-test-dialog">Test</button><span class="excluded-control"><button class="btn-secondary" type="button" disabled aria-disabled="true" title="Browser dictation cannot preserve the in-game target/session fence.">Google Free STT</button><?php echo lorkhan_ui_feature_badge('config.stt.google-free',true); ?></span></div>
    <div class="orm-note">Testing saves the current connector first, then sends the fixed test sample to the selected STT service. Cloud tests may incur provider charges.</div>
    <form id="stt-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/connector-revise">
     <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="kind" value="stt_provider"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><input type="hidden" name="change_reason" value="Management STT update"><input type="hidden" name="voice" value=""><input type="hidden" name="option_fields_present" value="1">
     <div class="editor-grid">
      <div class="field-block"><label for="stt-name">Name</label><input id="stt-name" type="text" value="<?php echo lorkhan_ui_h($selected['name']); ?>" readonly><div class="field-help">One connector is shared by every Core Profile and NPC in this installation.</div></div>
      <div class="field-block"><label for="stt-driver">Service</label><select id="stt-driver" name="driver" data-route-select><?php foreach($groups as $group=>$providers): ?><optgroup label="<?php echo lorkhan_ui_h($group); ?>"><?php foreach($providers as [$driver,$name]): ?><option value="<?php echo lorkhan_ui_h($driver); ?>" data-url="<?php echo lorkhan_ui_h($queryFor(['driver'=>$driver])); ?>"<?php echo $driver===$activeDriver?' selected':''; ?>><?php echo lorkhan_ui_h($name); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select><div class="field-help">Choose the speech-to-text backend LorkhanServer loads globally.</div></div>
      <div class="field-block"><label for="stt-api-badge">API Badge</label><select id="stt-api-badge" disabled aria-disabled="true"><option><?php echo $credentialVariable===''?'Not required':lorkhan_ui_h($definitions[$activeDriver]['label']); ?></option></select><div class="api-key-notice <?php echo $credentialVariable===''||($credentialStatus['configured']??false)?'ok':'warn'; ?>"><?php echo $credentialVariable===''?'This service does not require an API key.':(($credentialStatus['configured']??false)?'Selected API badge is configured via '.lorkhan_ui_h($credentialStatus['source']).'.':'Selected API badge does not have a configured key yet.'); ?> <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/api_keys.php">API Keys</a></div></div>
      <div class="field-block"><label for="stt-endpoint">URL</label><input id="stt-endpoint" type="text" name="endpoint" required maxlength="2048" value="<?php echo lorkhan_ui_h($sameDriver?($content['endpoint']??$driverDefaults['endpoint']):$driverDefaults['endpoint']); ?>"><div class="field-help">Provider endpoint; Parakeet adds /v1/audio/transcriptions automatically.</div></div>
     </div>
     <div class="meta-group active"><h3><?php echo lorkhan_ui_h($definitions[$activeDriver]['label']); ?> Settings</h3><div class="inline-two">
      <div class="field-block"><label for="stt-language">Lang</label><input id="stt-language" name="language" maxlength="35" value="<?php echo lorkhan_ui_h($sameDriver?($content['language']??$driverDefaults['language']):$driverDefaults['language']); ?>"><div class="field-help">Language tag sent with transcription requests.</div></div>
      <div class="field-block"><label for="stt-model">Model</label><input id="stt-model" name="model" maxlength="256" value="<?php echo lorkhan_ui_h($sameDriver?($content['model']??$driverDefaults['model']):$driverDefaults['model']); ?>"></div>
      <div class="field-block"><label for="stt-timeout">Timeout (ms)</label><input id="stt-timeout" type="number" min="1000" max="120000" name="timeout_ms" value="<?php echo (int)($sameDriver?($content['timeout_ms']??30000):30000); ?>"></div>
      <?php foreach($optionCatalog[$activeDriver] as $field): $name=(string)$field['name'];$value=$options[$name]??'';$fieldId='stt-option-'.$activeDriver.'-'.$name; ?><div class="field-block"><label for="<?php echo lorkhan_ui_h($fieldId); ?>"><?php echo lorkhan_ui_h($field['label']); ?></label><?php if($field['type']==='boolean'): ?><label class="boolean-field"><input id="<?php echo lorkhan_ui_h($fieldId); ?>" name="option__<?php echo lorkhan_ui_h($name); ?>" type="checkbox" value="1"<?php echo $value===true?' checked':''; ?>> Enabled</label><?php elseif($field['type']==='select'): ?><select id="<?php echo lorkhan_ui_h($fieldId); ?>" name="option__<?php echo lorkhan_ui_h($name); ?>"><?php foreach($field['values'] as $choice): ?><option value="<?php echo lorkhan_ui_h($choice); ?>"<?php echo (string)$value===(string)$choice?' selected':''; ?>><?php echo lorkhan_ui_h($choice); ?></option><?php endforeach; ?></select><?php endif; ?></div><?php endforeach; ?>
     </div><details class="advanced-json"><summary>Advanced connector options</summary><div class="field-block"><label for="stt-options">Connector options (JSON)</label><textarea id="stt-options" name="options_json"><?php echo lorkhan_ui_h(json_encode($options===[]?(object)[]:$options,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></textarea></div></details></div>
    </form>
    <?php endif; ?>
   </section>
  </div><?php endif; ?>
 </div>
</main>
<?php if ($selected !== null) include __DIR__ . '/tmpl/stt_connector_test.php'; ?>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo lorkhan_ui_h($uiAssetVersion); ?>" defer></script>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/stt-connector-test.js?v=<?php echo (string) filemtime(dirname(__DIR__) . '/js/stt-connector-test.js'); ?>" defer></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
