<?php
declare(strict_types=1);

// Herika's printable diary presentation, backed by exact scoped Lorkhan identities.
require __DIR__.'/ui_bootstrap.php';
$installation=(string)($_GET['installation_id']??'');
$playthrough=(string)($_GET['playthrough_id']??'');
$person=(string)($_GET['person']??'');
foreach([$installation,$playthrough,$person] as $id) {
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$id)) {
        http_response_code(400); echo 'Choose a diary author from Filter by Person.'; exit;
    }
}
$author=$database->prepare('SELECT p.name FROM profiles p JOIN playthroughs t ON t.installation_id=p.installation_id WHERE p.profile_id=:person AND p.installation_id=:installation AND t.playthrough_id=:playthrough AND p.deleted_at IS NULL AND t.deleted_at IS NULL');
$author->execute(['person'=>$person,'installation'=>$installation,'playthrough'=>$playthrough]);
$name=$author->fetchColumn();
if($name===false){http_response_code(404);echo 'Diary author or playthrough not found.';exit;}
$entries=$database->prepare("SELECT content FROM narrative_records WHERE installation_id=:installation AND playthrough_id=:playthrough AND profile_id=:person AND kind='diary' AND deleted_at IS NULL ORDER BY created_at,narrative_id");
$entries->execute(['installation'=>$installation,'playthrough'=>$playthrough,'person'=>$person]);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>The Diary of <?= lorkhan_ui_h($name) ?></title>
<link rel="icon" href="<?= lorkhan_ui_h($webRoot) ?>/ui/images/favicon.ico">
<link rel="stylesheet" href="<?= lorkhan_ui_h($webRoot) ?>/ui/css/diary-book.css">
<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/diary-book.js" defer></script>
</head><body><main class="book">
<div class="actions"><button class="btn" type="button" data-diary-print>Print / Save as PDF</button></div>
<header class="book-header"><h1>The Diary of <?= lorkhan_ui_h($name) ?></h1></header>
<?php $hasEntries=false; while($entry=$entries->fetch(PDO::FETCH_ASSOC)): $hasEntries=true; ?>
<article class="entry"><div class="paper"><?= lorkhan_ui_h($entry['content']) ?></div></article>
<?php endwhile; if(!$hasEntries): ?><p class="book-empty">No diary entries for this author in this playthrough.</p><?php endif; ?>
</main></body></html>
