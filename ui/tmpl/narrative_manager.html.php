<main class="narrative-manager-page" data-narrative-manager>
    <header class="page-header"><h1>📝 Narratives</h1><p>Create and edit narrator, diary and summary entries.</p></header>
    <?php $status=(string)($_GET['status']??''); ?>
    <?php if ($status==='diary-requested'): ?><p class="narrative-notice" role="status">Diary generation queued. Check Workers &amp; Jobs for progress, then refresh this page to read the entry.</p><?php elseif ($status==='saved'): ?><p class="narrative-notice" role="status">Narrative saved.</p><?php endif; ?>
    <div class="narrative-toolbar">
        <button class="log-button" type="button" data-open-narrative="narrative-create"<?= !$diaryScopeReady?' disabled':'' ?>>Create narrative</button>
        <button class="log-button" type="button" data-open-narrative="narrative-generate"<?= !$diaryScopeReady?' disabled':'' ?>>Request a diary</button>
        <a class="log-button" href="<?= lorkhan_ui_h($webRoot) ?>/ui/events-memories.php?tab=diaries">View Diaries</a>
        <a class="log-button secondary" href="<?= lorkhan_ui_h(lorkhan_control_url($state)) ?>">Refresh</a>
    </div>
    <?php if (!$diaryScopeReady): ?><p class="narrative-scope-note">Creating an entry needs an installation, a profile and a playthrough.</p><?php endif; ?>
    <details class="narrative-filters"><summary>Filters and installation</summary><form method="get">
        <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label>Installation<select name="installation_id"><option value="">All Installations</option><?php foreach ($installations as $row): ?><option value="<?= lorkhan_ui_h($row['installation_id']) ?>"<?= $row['installation_id']===$state['installation']?' selected':'' ?>><?= lorkhan_ui_h($row['display_name']) ?></option><?php endforeach; ?></select></label>
        <label>Period<select name="period"><?php foreach ($state['periods'] as $key=>$label): ?><option value="<?= $key ?>"<?= $key===$state['period']?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
        <label>Kind<select name="state"><option value="">All Kinds</option><?php foreach ($kinds as $key=>$label): ?><option value="<?= $key ?>"<?= $key===$state['state']?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
        <label class="narrative-search">Search<input type="search" name="q" maxlength="200" value="<?= lorkhan_ui_h($state['query']) ?>" placeholder="Author, title or content"></label><button class="log-button secondary" type="submit">Filter</button>
    </form></details>
    <p class="narrative-count"><?= $state['total'] ?> entries · Page <?= $state['page'] ?> of <?= $state['pages'] ?></p>
    <div class="narrative-table-scroll" tabindex="0" role="region" aria-label="Narrative entries"><table class="event-table"><thead><tr><th scope="col">Author</th><th scope="col">Content</th><th scope="col">Time (UTC)</th><th scope="col">Actions</th></tr></thead><tbody>
        <?php foreach ($rows as $row): $id=lorkhan_ui_h($row['narrative_id']); ?><tr>
            <td><?= lorkhan_ui_h($row['author']) ?><small><?= lorkhan_ui_h($kinds[$row['kind']]??$row['kind']) ?></small></td>
            <td class="entry-cell"><button type="button" class="entry-preview" data-open-narrative="narrative-edit-<?= $id ?>"><strong><?= lorkhan_ui_h($row['title']) ?></strong><span><?= nl2br(lorkhan_ui_h(mb_substr($row['content'],0,700))) ?><?= mb_strlen($row['content'])>700?'…':'' ?></span></button></td>
            <td><?= lorkhan_ui_h($row['created_utc']) ?></td><td><div class="entry-actions"><button class="log-button secondary" type="button" data-open-narrative="narrative-edit-<?= $id ?>">Edit</button><button class="log-button danger" type="button" data-open-narrative="narrative-delete-<?= $id ?>">Delete</button></div></td>
        </tr><?php endforeach; ?>
        <?php if ($rows===[]): ?><tr><td colspan="4" class="narrative-empty">No narratives match these filters. Create an entry or request a diary above.</td></tr><?php endif; ?>
    </tbody></table></div>
    <nav class="narrative-pagination" aria-label="Narrative pages"><?php if ($state['page']>1): ?><a class="log-button secondary" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>1])) ?>">First</a><a class="log-button secondary" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>$state['page']-1])) ?>">Previous</a><?php endif; ?><span><?= $state['page'] ?> / <?= $state['pages'] ?></span><?php if ($state['page']<$state['pages']): ?><a class="log-button secondary" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>$state['page']+1])) ?>">Next</a><a class="log-button secondary" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>$state['pages']])) ?>">Last</a><?php endif; ?></nav>

    <?php foreach ($rows as $row): $id=lorkhan_ui_h($row['narrative_id']); ?>
    <dialog class="narrative-modal" id="narrative-edit-<?= $id ?>" aria-labelledby="narrative-edit-title-<?= $id ?>"><h2 id="narrative-edit-title-<?= $id ?>">Edit Entry</h2>
        <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/narrative-revise">
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="narrative_id" value="<?= $id ?>">
            <div class="modal-body"><label for="narrative-content-<?= $id ?>">Content:</label><small>Edit the content of the diary entry below.</small><textarea id="narrative-content-<?= $id ?>" name="content" required maxlength="65536" autofocus><?= lorkhan_ui_h($row['content']) ?></textarea>
                <details class="entry-metadata"><summary>Title, kind and provenance</summary><label for="narrative-title-<?= $id ?>">Title</label><input id="narrative-title-<?= $id ?>" name="title" value="<?= lorkhan_ui_h($row['title']) ?>" required maxlength="256"><label for="narrative-kind-<?= $id ?>">Kind</label><select id="narrative-kind-<?= $id ?>" name="kind"><?php foreach ($kinds as $key=>$label): ?><option value="<?= $key ?>"<?= $key===$row['kind']?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><label for="narrative-source-<?= $id ?>">Provenance</label><input id="narrative-source-<?= $id ?>" name="provenance" value="management edit" required></details>
            </div><div class="modal-footer"><button class="log-button save" type="submit">Save Changes</button><button class="log-button secondary" type="button" data-close-narrative>Cancel</button></div>
        </form>
    </dialog>
    <dialog class="narrative-modal narrative-delete-modal" id="narrative-delete-<?= $id ?>" aria-labelledby="narrative-delete-title-<?= $id ?>"><h2 id="narrative-delete-title-<?= $id ?>">Delete Entry</h2><form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/narrative-delete"><input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="narrative_id" value="<?= $id ?>"><div class="modal-body"><p>Delete <strong><?= lorkhan_ui_h($row['title']) ?></strong> from this playthrough?</p></div><div class="modal-footer"><button class="log-button danger" type="submit">Delete Entry</button><button class="log-button secondary" type="button" data-close-narrative autofocus>Cancel</button></div></form></dialog>
    <?php endforeach; ?>
    <?php if ($diaryScopeReady): ?>
    <dialog class="narrative-modal" id="narrative-create" aria-labelledby="narrative-create-title"><h2 id="narrative-create-title">Create Entry</h2><form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/narratives" data-scope-form><input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><div class="modal-body">
        <label for="create-title">Title</label><input id="create-title" name="title" required maxlength="256" autofocus><label for="create-content">Content:</label><textarea id="create-content" name="content" required maxlength="65536"></textarea>
        <details class="entry-metadata" open><summary>Scope and entry settings</summary><?php $scopeFields('create'); ?><label for="create-kind">Kind</label><select id="create-kind" name="kind"><?php foreach ($kinds as $key=>$label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select><label for="create-provenance">Provenance</label><input id="create-provenance" name="provenance" value="management" required></details>
        </div><div class="modal-footer"><button class="log-button save" type="submit">Create Narrative</button><button class="log-button secondary" type="button" data-close-narrative>Cancel</button></div></form></dialog>
    <dialog class="narrative-modal" id="narrative-generate" aria-labelledby="narrative-generate-title"><h2 id="narrative-generate-title">Request a diary</h2><form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/narrative-generate" data-scope-form><input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><div class="modal-body">
        <p>Queues one diary narrative from the witnessed context available to the selected profile and playthrough.</p><?php $scopeFields('generate'); ?>
        <details class="entry-metadata"><summary>Diary generation requirements</summary><p>The profile must have <strong>Diary Generation</strong> enabled and a <strong>Diary LLM</strong> selected in Core Profiles. Without both, nothing is queued. Installation, profile and playthrough must belong together.</p><p>Automatic timer, sleep, and optional wait diaries use the same pipeline when enabled. A queued job retains the profile and connector revisions current when it was submitted. Your provider may charge for generation.</p></details>
        </div><div class="modal-footer"><button class="log-button save" type="submit">Queue Diary Generation</button><button class="log-button secondary" type="button" data-close-narrative>Cancel</button></div></form></dialog>
    <?php endif; ?>
</main>
