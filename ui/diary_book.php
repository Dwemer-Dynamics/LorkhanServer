<?php
declare(strict_types=1);

// Herika's printable diary presentation, backed by exact scoped Lorkhan identities.
require __DIR__.'/ui_bootstrap.php';
$installation=(string)($_GET['installation_id']??'');
$playthrough=(string)($_GET['playthrough_id']??'');
$person=(string)($_GET['person']??'');
foreach([$installation,$person,...($playthrough!==''?[$playthrough]:[])] as $id) {
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$id)) {
        http_response_code(400); echo 'Choose a diary author from Filter by Person.'; exit;
    }
}
$author=$database->prepare('SELECT name FROM profiles WHERE profile_id=:person AND installation_id=:installation AND deleted_at IS NULL');
$author->execute(['person'=>$person,'installation'=>$installation]);
$name=$author->fetchColumn();
if($name===false){http_response_code(404);echo 'Diary author or playthrough not found.';exit;}
$scopes=$database->prepare('SELECT playthrough_id,name FROM playthroughs WHERE installation_id=:installation AND deleted_at IS NULL ORDER BY created_at DESC,playthrough_id');
$scopes->execute(['installation'=>$installation]);$playthroughs=$scopes->fetchAll(PDO::FETCH_KEY_PAIR);
if($playthrough!==''&&!array_key_exists($playthrough,$playthroughs)){http_response_code(404);echo 'Diary author or playthrough not found.';exit;}
// NPC headers have no playthrough selector. A sole scope is unambiguous; otherwise ask before reading entries.
if($playthrough===''&&count($playthroughs)===1){
    header('Location: '.$webRoot.'/ui/diary_book.php?'.http_build_query(['installation_id'=>$installation,'person'=>$person,'playthrough_id'=>(string)array_key_first($playthroughs)]));exit;
}
$entries=null;
if($playthrough!==''){
    $entries=$database->prepare("SELECT content FROM narrative_records WHERE installation_id=:installation AND playthrough_id=:playthrough AND profile_id=:person AND kind='diary' AND deleted_at IS NULL ORDER BY created_at,narrative_id");
    $entries->execute(['installation'=>$installation,'playthrough'=>$playthrough,'person'=>$person]);
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>The Diary of <?= lorkhan_ui_h($name) ?></title>
<link rel="icon" href="<?= lorkhan_ui_h($webRoot) ?>/ui/images/favicon.ico">
<link rel="stylesheet" href="<?= lorkhan_ui_h($webRoot) ?>/ui/css/diary-book.css?v=<?= filemtime(__DIR__.'/css/diary-book.css') ?>">
<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/diary-book.js" defer></script>
</head><body><main class="book">
<div class="actions"><button class="btn" type="button" data-diary-print>Print / Save as PDF</button></div>
<header class="book-header"><h1>The Diary of <?= lorkhan_ui_h($name) ?></h1></header>
<?php if(count($playthroughs)>1): ?>
<form class="book-scope" method="get">
    <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($installation) ?>"><input type="hidden" name="person" value="<?= lorkhan_ui_h($person) ?>">
    <label for="book-playthrough">Playthrough</label><select id="book-playthrough" name="playthrough_id" required>
        <option value="">Choose a playthrough</option><?php foreach($playthroughs as$id=>$label): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $playthrough===$id?' selected':'' ?>><?= lorkhan_ui_h($label) ?></option><?php endforeach; ?>
    </select><button class="btn" type="submit">Open Diary</button>
</form>
<?php endif; ?>
<?php $hasEntries=false; while($entries!==null&&($entry=$entries->fetch(PDO::FETCH_ASSOC))): $hasEntries=true; ?>
<article class="entry"><div class="paper"><?= lorkhan_ui_h($entry['content']) ?></div></article>
<?php endwhile; if(!$hasEntries): ?><p class="book-empty"><?= $playthrough!==''?'No diary entries for this author in this playthrough.':($playthroughs===[]?'No playthroughs are available for this NPC.':'Choose a playthrough to read this NPC’s diary.') ?></p><?php endif; ?>
</main></body></html>
