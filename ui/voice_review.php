<?php
declare(strict_types=1);
use LorkhanServer\Application\VoiceDesignReview;
$pageTitle='Character Voice Review';$topNavSection='configuration';
require __DIR__.'/ui_bootstrap.php';
$review=new VoiceDesignReview(($config['voice_storage_path']??'/var/lib/lorkhanserver/voices').'/design-review');
$document=$review->document();$notice='';
// Serve only generated, allowlisted review audio through the normal management session.
if(isset($_GET['audio'])){
    $id=(string)$_GET['audio'];$candidate=null;
    foreach($document['characters'] as $row)foreach($row['candidates']??[] as $entry)if($entry['id']===$id)$candidate=$entry;
    if($candidate===null||preg_match('/^[a-f0-9]{32}$/D',$id)!==1){http_response_code(404);exit;}
    $path=$review->root.'/'.$id.'.wav';
    if(!is_file($path)){http_response_code(404);exit;}
    header('Content-Type: audio/wav');header('Cache-Control: private, max-age=3600');
    header('Content-Length: '.filesize($path));readfile($path);exit;
}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(!hash_equals($csrf,(string)($_POST['_csrf']??''))){http_response_code(403);exit('Invalid review session. Reload the page.');}
    try{
        $review->choose((string)($_POST['character']??''),(string)($_POST['candidate']??''));
        header('Location: '.$webRoot.'/ui/voice_review.php?saved=1#'.rawurlencode((string)$_POST['character']),true,303);exit;
    }catch(InvalidArgumentException){http_response_code(400);$notice='That candidate is not available. Reload and try again.';}
    catch(Throwable){http_response_code(503);$notice='The choice could not be saved. Please try again.';}
}
$decisions=$review->decisions();$approved=0;
foreach($document['characters'] as $row)if(in_array($decisions[$row['key']]['candidate']??'',array_column($row['candidates']??[],'id'),true))$approved++;
$additionalStylesheets=['voice-review.css?v='.(string)filemtime(__DIR__.'/css/voice-review.css')];
include __DIR__.'/tmpl/head.html';include __DIR__.'/tmpl/navbar.php';
?>
<main class="voice-review">
<header class="review-intro"><p class="review-eyebrow">LORKHAN / INWORLD VOICE DESIGN</p><h1>Character Voice Review</h1>
<p>Two original voices per character. Listen, compare, and approve your favorite.</p>
<p class="review-muted">Approvals are saved here only. Nothing is published to Inworld or assigned in-game yet. These are character-inspired designs, not imitations of the original actors.</p>
<strong><?= $approved ?> / <?= count($document['characters']) ?> approved</strong>
<?php if(isset($_GET['saved'])): ?><p role="status">Choice saved. Game voices are unchanged.</p><?php endif; ?>
<?php if($notice!==''): ?><p role="alert"><?= lorkhan_ui_h($notice) ?></p><?php endif; ?>
</header>
<nav class="review-jump" aria-label="Characters"><?php foreach($document['characters'] as $row): ?><a href="#<?= lorkhan_ui_h($row['key']) ?>"><?= lorkhan_ui_h($row['name']) ?></a><?php endforeach; ?></nav>
<?php if($document['characters']===[]): ?><p>No candidates generated yet.</p><?php endif; ?>
<?php foreach($document['characters'] as $row): $choice=$decisions[$row['key']]['candidate']??'pending'; ?>
<section class="review-character" id="<?= lorkhan_ui_h($row['key']) ?>" aria-labelledby="title-<?= lorkhan_ui_h($row['key']) ?>">
<h2 id="title-<?= lorkhan_ui_h($row['key']) ?>"><?= lorkhan_ui_h($row['name']) ?></h2>
<p><?= lorkhan_ui_h($row['character']) ?></p>
<details><summary>Voice direction</summary><p><?= lorkhan_ui_h($row['design_prompt']) ?></p></details>
<blockquote><?= lorkhan_ui_h($row['preview_text']) ?></blockquote>
<form method="post">
<input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="character" value="<?= lorkhan_ui_h($row['key']) ?>">
<div class="review-candidates">
<?php foreach($row['candidates'] as $candidate): $selected=$choice===$candidate['id']; ?>
<article class="review-candidate <?= $selected?'is-approved':'' ?>">
<h3>Candidate <?= lorkhan_ui_h($candidate['label']) ?> <?= $selected?'— Approved':'' ?></h3>
<audio controls preload="none" aria-label="<?= lorkhan_ui_h($row['name'].' candidate '.$candidate['label']) ?>" src="?audio=<?= lorkhan_ui_h($candidate['id']) ?>"></audio>
<p class="review-muted"><?= round($candidate['duration_ms']/1000,1) ?> seconds</p>
<button type="submit" name="candidate" value="<?= lorkhan_ui_h($candidate['id']) ?>" <?= $selected?'disabled':'' ?>><?= $selected?'Approved':'Approve '.$candidate['label'] ?></button>
</article>
<?php endforeach; ?>
</div>
<div class="review-decisions"><button class="secondary" type="submit" name="candidate" value="none" <?= $choice==='none'?'disabled':'' ?>><?= $choice==='none'?'Both rejected':'Neither — needs another pass' ?></button>
<button class="secondary" type="submit" name="candidate" value="pending" <?= $choice==='pending'?'disabled':'' ?>>Clear choice</button><span><?= $choice==='pending'?'Awaiting review':($choice==='none'?'Marked for redesign':'Selection saved') ?></span></div>
</form></section>
<?php endforeach; ?>
</main>
<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/voice-review.js"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
