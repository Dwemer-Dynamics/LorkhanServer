<?php
declare(strict_types=1);

// Shared reader setup and shell; each public page chooses a fixed reader before authentication.
require __DIR__.'/control_reader.php';
require __DIR__.'/roleplay_reader.php';
require __DIR__.'/roleplay_logs.php';
$installationOptions=[];
foreach($uiRepository->rows('installations') as $row){
    $id=(string)($row['installation_id']??'');
    if($id!=='')$installationOptions[$id]=(string)($row['display_name']??$id);
}
$readerState = null; $readerPreview = [];
if (in_array($activeTab, ['adventure', 'diaries', 'books', 'journal', 'responselog'], true)) {
    $readerState = lorkhan_roleplay_reader_state($database, $installationOptions, $activeTab);
    $readerInstallation = $readerState['installation'];
    if ($readerInstallation !== '') {
        $readerNarrator = $productRepository->narratorProfileForInstallation($readerInstallation);
        $readerPreview = \LorkhanServer\Application\SpeechPreviewCatalog::options(
            $productRepository->listRevisioned('tts_provider', $readerInstallation), $productRepository->connectorVoiceCatalog(),
            (string) ($config['voice_storage_path'] ?? ''),
            (string) ($productRepository->connectorForInstallation($readerInstallation, 'tts_provider')['configuration_id'] ?? ''),
            \LorkhanServer\Application\SpeechPreviewCatalog::narratorVoice($readerNarrator),
            \LorkhanServer\Application\SpeechPreviewCatalog::narratorConnector($readerNarrator));
    }
}

$uiRoot=dirname(__DIR__);
$additionalStylesheets=[];
foreach(['lorkhan-pages','herika-roleplay','roleplay-logs','roleplay-reader'] as $style){
    $additionalStylesheets[]=$style.'.css?v='.(string)filemtime($uiRoot.'/css/'.$style.'.css');
}
$includeManagementStyles=false;
include __DIR__.'/head.html';
if(!$embedded)include __DIR__.'/navbar.php';
?>
<link rel="stylesheet" href="<?= lorkhan_ui_h($webRoot) ?>/ui/css/main.css">
<link rel="stylesheet" href="<?= lorkhan_ui_h($webRoot) ?>/ui/css/hub-navigation.css?v=<?= (int)filemtime($uiRoot.'/css/hub-navigation.css') ?>">
<main class="container-fluid events-memories-page<?= $activeTab==='responselog'?' ai-response-page':'' ?>">
    <?php if(($_GET['status']??'')==='saved'): ?><p class="lorkhan-status" role="status">Changes saved.</p><?php endif; ?>
    <div class="tab-container">
        <?php if(!$embedded)include __DIR__.'/events_memories_navigation.php'; ?>
        <section id="<?= lorkhan_ui_h($activeTab) ?>-tab" class="tab-content active">
            <?php if($activeTab==='responselog'){
                lorkhan_roleplay_log_table($readerState,$installationOptions,$activeTab,$webRoot,$managementBasePath,$csrf);
            }else{
                echo '<div class="calendar-reader-viewport" tabindex="0" role="region" aria-label="'.lorkhan_ui_h($pageTitle).'">';
                lorkhan_roleplay_reader($readerState,$installationOptions,$activeTab,$webRoot,$managementBasePath,$csrf,$readerPreview);
                echo '</div>';
            } ?>
        </section>
    </div>
</main>
<?php foreach(['roleplay-reader','roleplay-logs','roleplay-maintenance'] as $script): ?>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/<?= $script ?>.js?v=<?= (int)filemtime($uiRoot.'/js/'.$script.'.js') ?>"></script>
<?php endforeach; include __DIR__.'/footer.html'; ?>
