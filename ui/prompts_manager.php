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
                $content['instruction']=$instruction===''?(string)($row['content']['default_prompt']??''):$instruction;
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
$promptMoodJsonNote = 'The eleven player mood templates are edited with the labelled controls above and merged into this document when you save, so they are not repeated in this raw JSON.';
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
    <header class="page-header lorkhan-page-head"><h1 class="lorkhan-page-head-title">Prompts Manager</h1><p class="lorkhan-page-head-note">Manage system and custom prompts used throughout LORKHAN</p><div class="lorkhan-page-head-actions"><button type="button" class="prompt-button primary" data-prompt-create>Create Prompt</button></div></header>
    <?php if (isset($_GET['status'])): ?><div class="prompt-notice">Prompt saved.</div><?php endif; ?>
    <?php if($csvNotice!==''): ?><p role="status" class="prompt-notice"><?php echo lorkhan_ui_h($csvNotice); ?></p><?php endif; ?>
    <?php if($csvError!==''): ?><p role="alert" class="prompt-notice"><?php echo lorkhan_ui_h($csvError); ?></p><?php endif; ?>
    <div class="transfer-grid">
        <section><h2>&#x1F4E4; Export Custom Prompts</h2><p>Download custom overrides in the same two-column CSV format as CHIM.</p><a class="prompt-button" href="?export=csv&amp;installation_id=<?php echo lorkhan_ui_h($installationId); ?>">Export Custom Prompts</a></section>
        <section><h2>&#x1F4E5; Import Custom Prompts</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="action" value="import_csv"><label for="prompts-csv">Choose CSV File</label><input id="prompts-csv" type="file" name="csv_file" accept=".csv,text/csv" required><button class="prompt-button" type="submit">Import Custom Prompts</button><button type="button" class="prompt-button" data-prompt-import>Import LORKHAN JSON</button></form></section>
        <aside class="prompt-help">
            <p><strong>Note:</strong> Recommended for advanced users only. Changing prompts can cause unexpected behavior that may worsen the roleplay experience.</p>
            <p><strong>Default Prompt:</strong> System-maintained baseline that updates with LORKHAN. <strong>Custom Prompt:</strong> Your versioned override that takes precedence when assigned through Global Settings, a Core Profile, or an NPC.</p>
            <p>Click <strong>Edit</strong> to view and modify prompts. Use <strong>Revision history</strong> to restore an earlier typed version.</p>
        </aside>
    </div>

    <section class="prompt-panel" data-prompt-create-panel hidden><div class="panel-heading"><h2>Create prompt</h2><button type="button" class="prompt-close" data-prompt-create-close>&times;</button></div><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/prompts"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><div class="prompt-form-grid"><label>Installation<select name="installation_id"><?php foreach ($installations as $row): ?><option value="<?php echo lorkhan_ui_h($row['installation_id']); ?>"><?php echo lorkhan_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label><label>Name<input name="name" required maxlength="128"></label><?php $renderPlayerMoodFields('prompt-create', []); ?><label class="wide">Prompt document JSON<textarea name="content_json" required>{"instruction":"Respond in character using only scoped Morrowind context."}</textarea></label><p class="prompt-format-hint prompt-json-note"><?php echo lorkhan_ui_h($promptMoodJsonNote); ?></p></div><button type="submit" class="prompt-button primary">Create Prompt</button></form></section>
    <section class="prompt-panel" data-prompt-import-panel hidden><div class="panel-heading"><h2>Import LORKHAN prompt</h2><button type="button" class="prompt-close" data-prompt-import-close>&times;</button></div><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/prompt-import"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><div class="prompt-form-grid"><label>Installation<select name="installation_id"><?php foreach ($installations as $row): ?><option value="<?php echo lorkhan_ui_h($row['installation_id']); ?>"><?php echo lorkhan_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label><label>Choose JSON file<input type="file" accept="application/json,.json" data-json-import-target="prompt-import-json"></label><label class="wide">Portable LORKHAN prompt JSON<textarea id="prompt-import-json" name="prompt_json" required placeholder="Choose a JSON file or paste its contents here."></textarea></label></div><button type="submit" class="prompt-button primary">Import Prompt</button></form></section>

    <section class="prompt-database"><div class="search-heading"><div><strong>Search Prompts</strong><p>Filter by prompt key, description, status, or preview text.</p></div><input type="search" placeholder="Search prompts..." aria-label="Search Prompts" data-prompt-search></div><div class="prompt-table-wrap"><table><thead><tr><th>Prompt Key</th><th>Description</th><th>Status</th><th>Preview</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $row): $content = is_array($row['content']) ? $row['content'] : []; $documentContent = $content; unset($documentContent['format'], $documentContent['player_mood_prompts']); $promptMoodValues = is_array($content['player_mood_prompts'] ?? null) ? $content['player_mood_prompts'] : []; $documentJson = $documentContent === [] ? '{}' : (string) json_encode($documentContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); $preview = (string) ($content['instruction'] ?? json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?><tr data-prompt-row data-search="<?php echo lorkhan_ui_h(strtolower((string) ($row['prompt_key'] . ' ' . $row['name'] . ' ' . $preview))); ?>"><td><code><?php echo lorkhan_ui_h($row['prompt_key']); ?></code><small class="prompt-display-name"><?php echo lorkhan_ui_h($row['name']); ?></small></td><td>Versioned LORKHAN prompt document. Used by <?php echo lorkhan_ui_h($row['profile_usage']); ?> explicit profile assignments.</td><td><span class="prompt-status">&#x270D;&#xFE0F; Custom</span><small>Revision <?php echo lorkhan_ui_h($row['current_revision']); ?></small></td><td><div class="prompt-preview"><?php echo lorkhan_ui_h(mb_strimwidth($preview, 0, 170, '...')); ?></div></td><td><div class="row-actions"><button type="button" class="prompt-button" data-prompt-edit="<?php echo lorkhan_ui_h($row['configuration_id']); ?>">&#x270F;&#xFE0F; Edit</button><a class="prompt-button" href="<?php echo lorkhan_ui_h($managementBasePath); ?>/exports/prompts/<?php echo lorkhan_ui_h($row['configuration_id']); ?>.json">Export</a></div></td></tr><tr class="prompt-editor-row" data-prompt-editor="<?php echo lorkhan_ui_h($row['configuration_id']); ?>" hidden><td colspan="5"><div class="editor-grid"><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/configuration-revise"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="kind" value="prompt"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>"><?php $renderPlayerMoodFields('prompt-' . (string) $row['configuration_id'], $promptMoodValues); ?><label>Prompt document JSON<textarea name="content_json" required><?php echo lorkhan_ui_h($documentJson); ?></textarea></label><p class="prompt-format-hint prompt-json-note"><?php echo lorkhan_ui_h($promptMoodJsonNote); ?></p><label>Revision note<input name="change_reason" required value="Management prompt update"></label><button type="submit" class="prompt-button primary">Save Revision</button></form><div><details><summary>Revision history</summary><?php $history = is_array($row['revisions']) ? $row['revisions'] : []; lorkhan_ui_table($history); ?></details><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/prompt-clone"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>"><label>Clone name<input name="name" required value="<?php echo lorkhan_ui_h($row['name'] . ' copy'); ?>"></label><button type="submit" class="prompt-button">Clone</button></form><?php if ((int) $row['profile_usage'] === 0): ?><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/configuration-delete" data-confirm="Delete this prompt?"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="kind" value="prompt"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>"><button type="submit" class="prompt-button danger">Clear</button></form><?php else: ?><p>Assigned prompts cannot be deleted.</p><?php endif; ?></div></div></td></tr><?php endforeach; ?><?php if ($rows === []): ?><tr><td colspan="5" class="empty-prompts">No custom prompt documents exist yet. Use Create Prompt to add one.</td></tr><?php endif; ?></tbody></table></div></section>
</main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/prompts-manager.js?v=<?php echo lorkhan_ui_h((string) filemtime(__DIR__ . '/js/prompts-manager.js')); ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
