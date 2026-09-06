<main class="audio-cache-page" data-audio-cache>
    <div class="cache-head">
        <div><h1 class="cache-title">Audio Cache</h1><div class="cache-meta">Audio: Private authenticated cache</div></div>
        <details class="cache-tools"><summary class="cache-btn">Filters</summary><div class="cache-tools-body">
            <?php lorkhan_control_filters($state,$states); ?>
            <p class="cache-meta">Newest playable clips are shown first. Expired and deleted entries can be viewed with the status filter but cannot be played. Files remain private; there is no public audio folder.</p>
        </div></details>
    </div>
    <section class="cache-panel" aria-labelledby="cache-list-title">
        <h2 id="cache-list-title">Audio Cache</h2>
        <?php if ($state['rows']===[]): ?><div class="empty">No cached audio files found.</div>
        <?php else: ?><div class="cache-list">
            <?php foreach ($state['rows'] as $row):
                $bytes=(int)$row['byte_count'];
                $size=$bytes>=1048576?number_format($bytes/1048576,2).' MB':($bytes>=1024?number_format($bytes/1024,1).' KB':$bytes.' B');
                $filename=$row['media_id'].'.'.$row['codec'];
                $description=$row['speaker'].' · '.number_format((int)$row['duration_ms']/1000,1).' seconds · Expires '.$row['expires_at'];
            ?>
            <div class="cache-row">
                <div class="cache-name" title="<?= lorkhan_ui_h($filename.' · '.$description) ?>" tabindex="0" aria-label="<?= lorkhan_ui_h($filename.' · '.$description) ?>"><?= lorkhan_ui_h($filename) ?></div>
                <div class="cache-small"><?= lorkhan_ui_h(strtoupper($row['codec']).' / '.$size) ?></div>
                <div class="cache-small" title="Created (UTC)"><?= lorkhan_ui_h(gmdate('Y-m-d H:i',strtotime($row['created_at']))) ?></div>
                <div class="cache-player">
                    <?php if ($row['cache_state']==='available'): ?><audio controls preload="none" aria-label="Cached speech by <?= lorkhan_ui_h($row['speaker']) ?>" src="<?= lorkhan_ui_h($webRoot.'/ui/cache_audio.php?'.http_build_query(['media_id'=>$row['media_id'],'installation_id'=>$row['installation_id']])) ?>"></audio><div class="cache-small" data-cache-status role="status"></div>
                    <?php else: ?><span class="cache-small"><?= lorkhan_ui_h(ucfirst($row['cache_state'])) ?> · No playable audio</span><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </section>
    <?php if ($state['pages']>1): lorkhan_control_pagination($state); endif; ?>
</main>
