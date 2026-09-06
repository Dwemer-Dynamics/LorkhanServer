<?php

declare(strict_types=1);

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Prompts Manager';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page prompts-manager-shell' . ($embedded ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';
$rows = $uiRepository->rows('prompts');
$installations = $uiRepository->rows('installations');
$installationId=(string)($installations[0]['installation_id']??'');
$requestedInstallation=(string)($_GET['installation_id']??$_POST['installation_id']??'');
foreach($installations as $installation)if($installation['installation_id']===$requestedInstallation)$installationId=$requestedInstallation;
$rows=array_values(array_filter($rows,static fn(array $row):bool=>$row['installation_id']===$installationId));
$csvNotice='';$csvError='';
if(($_GET['export']??'')==='csv'){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="custom_prompts.csv"');header('Cache-Control: no-store');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['prompt_key','custom_prompt'],',','"','');
    foreach($rows as$row)if(trim((string)($row['content']['custom_prompt']??''))!=='')fputcsv($out,[$row['prompt_key'],$row['content']['custom_prompt']],',','"','');
    fclose($out);exit;
}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??'')==='import_csv'){
    try{
        if(!hash_equals($csrf,(string)($_POST['_csrf']??'')))throw new RuntimeException('Invalid browser session.');
        $file=$_FILES['csv_file']??[];
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??''))||(int)($file['size']??0)>1_048_576)throw new RuntimeException('Choose a CSV file up to 1 MiB.');
        $handle=fopen($file['tmp_name'],'rb');if($handle===false)throw new RuntimeException('Cannot read CSV.');
        $batch=[];$known=array_column($rows,null,'prompt_key');
        try{
            $header=fgetcsv($handle,65538,',','"','');if(isset($header[0]))$header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);
            if($header!==['prompt_key','custom_prompt'])throw new RuntimeException('Expected prompt_key,custom_prompt columns.');
            while(($row=fgetcsv($handle,65538,',','"',''))!==false){
                if($row===[null])continue;
                if(count($row)!==2||!isset($known[$row[0]])||isset($batch[$row[0]])||strlen($row[1])>65536||!mb_check_encoding($row[1],'UTF-8'))throw new RuntimeException('CSV contains an invalid, duplicate, or unknown prompt.');
                $batch[$row[0]]=$row[1];if(count($batch)>100)throw new RuntimeException('Import at most 100 prompts.');
            }
        }finally{fclose($handle);}
        if($batch===[])throw new RuntimeException('CSV contains no prompts.');
        $productRepository->transaction(function()use($batch,$known,$productRepository):void{
            $service=new \LorkhanServer\Application\ProductService($productRepository,new \LorkhanServer\Application\DeterministicClock());
            foreach($batch as$key=>$instruction){$row=$known[$key];$current=$productRepository->getRevisioned('prompt',$row['configuration_id']);$content=$current['content'];
                $content['default_prompt']=(string)($row['content']['default_prompt']??'');
                $content['custom_prompt']=trim($instruction)===''?null:$instruction;
                $content['instruction']=$content['custom_prompt']??$content['default_prompt'];
                $service->revise('prompt',$row['configuration_id'],$content,'CHIM-compatible prompt CSV import');
            }
        });
        $csvNotice=count($batch).' prompts imported.';
        $rows=array_values(array_filter($uiRepository->rows('prompts'),static fn(array$row):bool=>$row['installation_id']===$installationId));
    }catch(Throwable $error){$csvError=$error instanceof RuntimeException&&!($error instanceof PDOException)?$error->getMessage():'Prompt import failed.';}
}

// Player mood cue templates live on the prompt document; labelled controls own them so the raw JSON editor stays free of them.
$playerMoodSupplied = method_exists(\LorkhanServer\Application\PlayerMoodPolicy::class, 'defaultTemplates') ? (array) \LorkhanServer\Application\PlayerMoodPolicy::defaultTemplates() : [];
$playerMoodDefaults = ['happy' => '(speaks in a happy tone.)', 'sad' => '(speaks in a sad tone.)', 'angry' => '(speaks in an angry tone.)', 'annoyed' => '(speaks in an annoyed tone.)', 'scared' => '(speaks in a frightened tone.)', 'surprised' => '(speaks in a surprised tone.)', 'confused' => '(speaks in a confused tone.)', 'suspicious' => '(speaks in a suspicious tone.)', 'playful' => '(speaks in a playful tone.)', 'flirty' => '(speaks in a flirtatious tone.)', 'custom' => '(speaks {CUSTOM_MOOD}.)'];
foreach (array_keys($playerMoodDefaults) as $moodKey) {
    $suppliedDefault = $playerMoodSupplied[$moodKey] ?? null;
    if (is_string($suppliedDefault) && trim($suppliedDefault) !== '') $playerMoodDefaults[$moodKey] = $suppliedDefault;
}
$playerMoodLabels = ['happy' => 'Happy', 'sad' => 'Sad', 'angry' => 'Angry', 'annoyed' => 'Annoyed', 'scared' => 'Scared', 'surprised' => 'Surprised', 'confused' => 'Confused', 'suspicious' => 'Suspicious', 'playful' => 'Playful', 'flirty' => 'Flirty', 'custom' => 'Custom'];
$playerMoodHelp = ['custom' => 'Used when the player types their own mood. Keep {CUSTOM_MOOD} so the typed wording is included.'];
$playerMoodPlaceholderHint = 'Placeholders, used the same way in every field below: {PLAYER_NAME} is the player character, {MOOD} is the selected mood name, and {CUSTOM_MOOD} is the mood the player typed. Custom should normally keep {CUSTOM_MOOD}; the other ten moods do not use it. Leave a field at its default to keep current behaviour.';
$renderPlayerMoodFields = static function (string $idPrefix, array $current) use ($playerMoodDefaults, $playerMoodLabels, $playerMoodHelp, $playerMoodPlaceholderHint): void {
    $sharedId = $idPrefix . '-moods-help';
    echo '<details class="prompt-mood-details"><summary class="prompt-mood-summary">Player mood prompt templates <span class="prompt-mood-count">11 fields</span></summary><div class="prompt-mood-body"><p class="prompt-format-hint" id="' . lorkhan_ui_h($sharedId) . '">' . lorkhan_ui_h($playerMoodPlaceholderHint) . '</p><div class="prompt-mood-grid">';
    foreach ($playerMoodDefaults as $moodKey => $moodDefault) {
        $rawValue = $current[$moodKey] ?? null;
        $value = is_string($rawValue) && trim($rawValue) !== '' ? $rawValue : $moodDefault;
        $fieldId = $idPrefix . '-mood-' . $moodKey;
        $help = $playerMoodHelp[$moodKey] ?? 'Used when the player sets the mood to ' . strtolower($playerMoodLabels[$moodKey]) . '.';
        echo '<div class="prompt-mood-field"><label for="' . lorkhan_ui_h($fieldId) . '">' . lorkhan_ui_h($playerMoodLabels[$moodKey]) . '</label><input type="text" maxlength="512" id="' . lorkhan_ui_h($fieldId) . '" name="player_mood_prompt_' . lorkhan_ui_h($moodKey) . '" value="' . lorkhan_ui_h($value) . '" aria-describedby="' . lorkhan_ui_h($fieldId . '-help ' . $sharedId) . '"><p class="prompt-mood-hint" id="' . lorkhan_ui_h($fieldId . '-help') . '">' . lorkhan_ui_h($help) . '</p></div>';
    }
    echo '</div></div></details>';
};
$additionalStylesheets = ['herika-prompts.css?v=' . (string) filemtime(__DIR__ . '/css/herika-prompts.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="prompts-page">
    <header class="page-header lorkhan-page-head"><h1 class="lorkhan-page-head-title">Prompts Manager</h1><p class="lorkhan-page-head-note">Manage system and custom prompts used throughout LORKHAN</p><details class="prompt-document-tools"><summary class="prompt-button">Prompt documents</summary><div><button type="button" class="prompt-button" data-prompt-create>Create Prompt</button><button type="button" class="prompt-button" data-prompt-import>Import LORKHAN JSON</button></div></details></header>
    <?php if (isset($_GET['status'])): ?><div class="prompt-notice">Prompt saved.</div><?php endif; ?>
    <?php if($csvNotice!==''): ?><p role="status" class="prompt-notice"><?php echo lorkhan_ui_h($csvNotice); ?></p><?php endif; ?>
    <?php if($csvError!==''): ?><p role="alert" class="prompt-notice"><?php echo lorkhan_ui_h($csvError); ?></p><?php endif; ?>
    <div class="transfer-grid">
        <section><p><strong>📤 Export Custom Prompts</strong></p><p>Download all your custom prompts as a CSV file to share with others.</p><a class="prompt-button btn-export" href="?export=csv&amp;installation_id=<?php echo lorkhan_ui_h($installationId); ?>">⬇️ Export Custom Prompts</a></section>
        <section><p><strong>📥 Import Custom Prompts</strong></p><p>Upload a CSV file to import custom prompts shared by others.</p><form method="post" enctype="multipart/form-data" data-prompt-csv-form><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="action" value="import_csv"><div class="prompt-file-picker"><input id="prompts-csv" type="file" name="csv_file" accept=".csv,text/csv" required><label for="prompts-csv">📁 Choose CSV File</label><span data-prompt-csv-name role="status"></span></div><br><button class="prompt-button btn-import" type="submit" disabled>⬆️ Import Custom Prompts</button></form></section>
        <aside class="prompt-help">
            <p><strong>Note:</strong> Recommended for advanced users only. Changing prompts can cause unexpected behavior that may worsen the roleplay experience.</p>
            <p><strong>Default Prompt:</strong> System-maintained baseline that updates with LORKHAN. <strong>Custom Prompt:</strong> Your versioned override that takes precedence when set.</p>
            <p>Click <strong>Edit</strong> to view and modify prompts. Click <strong>Clear</strong> to revert to default.</p>
        </aside>
    </div>

    <section class="prompt-panel" data-prompt-create-panel hidden><div class="panel-heading"><h2>Create prompt</h2><button type="button" class="prompt-close" aria-label="Close create prompt" data-prompt-create-close>&times;</button></div><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/prompts"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><div class="prompt-form-grid"><label>Installation<select name="installation_id"><?php foreach ($installations as $row): ?><option value="<?php echo lorkhan_ui_h($row['installation_id']); ?>"><?php echo lorkhan_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label><label>Name<input name="name" required maxlength="128"></label><?php $renderPlayerMoodFields('prompt-create', []); ?><label class="wide">Prompt instructions<textarea name="prompt_instruction" required maxlength="65536">Respond in character using only scoped Morrowind context.</textarea></label><details class="wide"><summary>Additional prompt document fields</summary><label>Additional fields (JSON)<textarea name="content_json">{}</textarea></label></details></div><button type="submit" class="prompt-button primary">Create Prompt</button></form></section>
    <section class="prompt-panel" data-prompt-import-panel hidden><div class="panel-heading"><h2>Import LORKHAN prompt</h2><button type="button" class="prompt-close" aria-label="Close import prompt" data-prompt-import-close>&times;</button></div><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/prompt-import"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><div class="prompt-form-grid"><label>Installation<select name="installation_id"><?php foreach ($installations as $row): ?><option value="<?php echo lorkhan_ui_h($row['installation_id']); ?>"><?php echo lorkhan_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label><label>Choose JSON file<input type="file" accept="application/json,.json" data-json-import-target="prompt-import-json"></label><label class="wide">Portable LORKHAN prompt JSON<textarea id="prompt-import-json" name="prompt_json" required placeholder="Choose a JSON file or paste its contents here."></textarea></label></div><button type="submit" class="prompt-button primary">Import Prompt</button></form></section>

    <?php include __DIR__ . '/tmpl/prompt_editor.php'; ?>
</main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/prompts-manager.js?v=<?php echo lorkhan_ui_h((string) filemtime(__DIR__ . '/js/prompts-manager.js')); ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
