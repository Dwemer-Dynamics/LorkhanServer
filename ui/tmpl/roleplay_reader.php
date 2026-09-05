<?php
declare(strict_types=1);

use LorkhanServer\Application\SpeechPreviewCatalog;

/** Read one scoped page before applying the limit, so older diaries remain reachable. */
function lorkhan_roleplay_reader_state(PDO $database, array $installationOptions, string $tab): array
{
    $installation = (string) ($_GET['installation_id'] ?? array_key_first($installationOptions) ?? '');
    if (!isset($installationOptions[$installation])) $installation = (string) (array_key_first($installationOptions) ?? '');
    $state = ['installation' => $installation, 'playthrough' => '', 'person' => '', 'date' => '', 'query' => '',
        'page' => 1, 'pages' => 1, 'total' => 0, 'rows' => [], 'people' => [], 'playthroughs' => []];
    if ($installation === '') return $state;
    $statement = $database->prepare('SELECT playthrough_id,name FROM playthroughs WHERE installation_id=:installation AND deleted_at IS NULL ORDER BY created_at DESC,playthrough_id');
    $statement->execute(['installation' => $installation]);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $state['playthroughs'][(string) $row['playthrough_id']] = (string) $row['name'];
    $playthrough = (string) ($_GET['playthrough_id'] ?? '');
    if (!isset($state['playthroughs'][$playthrough])) $playthrough = (string) (array_key_first($state['playthroughs']) ?? '');
    $state['playthrough'] = $playthrough;
    if ($playthrough === '') return $state;
    // These projections are fixed allowlisted SELECTs, never table names or SQL from the browser.
    $source = match ($tab) {
        'books' => "SELECT b.rowid::text AS narrative_id,m.installation_id,m.playthrough_id,''::text AS profile_id,'Observed book'::text AS person,'book'::text AS kind,b.title,b.content,to_timestamp(b.localts) AS created_at,NULL::timestamptz AS deleted_at FROM public.books b JOIN lorkhan_internal.book_metadata m ON m.rowid=b.rowid",
        'journal' => "SELECT q.rowid::text AS narrative_id,m.installation_id,m.playthrough_id,''::text AS profile_id,'Morrowind Journal'::text AS person,'journal'::text AS kind,q.id_quest AS title,COALESCE(NULLIF(q.briefing2,''),NULLIF(q.briefing,''),q.data) AS content,to_timestamp(q.localts) AS created_at,NULL::timestamptz AS deleted_at FROM public.questlog q JOIN lorkhan_internal.questlog_metadata m ON m.rowid=q.rowid",
        'responselog' => "SELECT s.rowid::text AS narrative_id,m.installation_id,m.playthrough_id,COALESCE(s.speaker,'Unknown') AS profile_id,COALESCE(s.speaker,'Unknown') AS person,COALESCE(m.delivery_state,'unknown') AS kind,COALESCE(s.speaker,'Unknown') AS title,s.speech AS content,m.created_at,NULL::timestamptz AS deleted_at FROM public.speech s JOIN lorkhan_internal.speech_metadata m ON m.rowid=s.rowid",
        default => "SELECT n.narrative_id::text,n.installation_id,n.playthrough_id,n.profile_id::text,p.name AS person,n.kind,n.title,n.content,n.created_at,n.deleted_at FROM narrative_records n JOIN profiles p ON p.profile_id=n.profile_id AND p.installation_id=n.installation_id",
    };
    $from = ' FROM ('.$source.') n';
    $params = ['installation' => $installation, 'playthrough' => $playthrough];
    $where = 'n.installation_id=:installation AND n.playthrough_id=:playthrough AND n.deleted_at IS NULL';
    if ($tab === 'diaries') $where .= " AND n.kind='diary'";
    elseif ($tab === 'adventure') $where .= " AND n.kind IN ('narrator','summary')";
    $statement = $database->prepare('SELECT DISTINCT n.profile_id,n.person AS name'.$from.' WHERE '.$where.' ORDER BY name,n.profile_id');
    $statement->execute($params);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) if ((string) $row['profile_id'] !== '') $state['people'][(string) $row['profile_id']] = (string) $row['name'];
    $person = (string) ($_GET['person'] ?? '');
    if (isset($state['people'][$person])) { $state['person'] = $person; $where .= ' AND n.profile_id=:person'; $params['person'] = $person; }
    $date = (string) ($_GET['date'] ?? '');
    $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC')) : false;
    if ($parsed !== false && $parsed->format('Y-m-d') === $date) {
        $state['date'] = $date; $where .= " AND n.created_at>=CAST(:date_from AS timestamptz) AND n.created_at<CAST(:date_to AS timestamptz)";
        $params['date_from'] = $parsed->format(DATE_ATOM); $params['date_to'] = $parsed->modify('+1 day')->format(DATE_ATOM);
    }
    $query = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 200));
    if ($query !== '') { $state['query'] = $query; $where .= " AND (n.title ILIKE :title_query OR n.content ILIKE :content_query)"; $params['title_query'] = '%'.$query.'%'; $params['content_query'] = '%'.$query.'%'; }
    $statement = $database->prepare('SELECT count(*)'.$from.' WHERE '.$where); $statement->execute($params);
    $state['total'] = (int) $statement->fetchColumn(); $state['pages'] = max(1, (int) ceil($state['total'] / 20));
    $state['page'] = max(1, min($state['pages'], (int) ($_GET['reader_page'] ?? 1)));
    $statement = $database->prepare('SELECT n.narrative_id,n.kind,n.title,n.content,n.created_at,n.person'.$from.' WHERE '.$where.' ORDER BY n.created_at DESC,n.narrative_id LIMIT 20 OFFSET :offset');
    foreach ($params as $key => $value) $statement->bindValue(':'.$key, $value, PDO::PARAM_STR);
    $statement->bindValue(':offset', ($state['page'] - 1) * 20, PDO::PARAM_INT); $statement->execute();
    $state['rows'] = $statement->fetchAll(PDO::FETCH_ASSOC);
    return $state;
}

/** Render the diary/adventure reader using saved narrative forms and the existing speech-preview service. */
function lorkhan_roleplay_reader(array $state, array $installationOptions, string $tab, string $webRoot, string $managementBasePath, string $csrf, array $preview): void
{
    $heading = match ($tab) { 'diaries' => 'LORKHAN Diaries', 'books' => 'Books', 'journal' => 'Morrowind Journal', 'responselog' => 'AI Responses', default => 'Adventure Log' };
    $description = match ($tab) { 'diaries' => 'Read the diaries written by your companions, the Player and the Narrator.', 'books' => 'Read books observed during your Morrowind playthrough.', 'journal' => 'Read captured Morrowind Journal entries.', 'responselog' => 'Recorded AI dialogue with its delivery state.', default => 'Narrator entries and summaries from your Morrowind adventures.' };
    $editable = in_array($tab, ['diaries', 'adventure'], true);
    $ready = ($preview['default_connector_id'] ?? '') !== '' && ($preview['default_voice'] ?? '') !== '';
    $link = static fn(array $extra): string => $webRoot.'/ui/events-memories.php?'.http_build_query(array_merge([
        'tab' => $tab, 'installation_id' => $state['installation'], 'playthrough_id' => $state['playthrough'],
        'person' => $state['person'], 'date' => $state['date'], 'q' => $state['query'], 'reader_page' => $state['page'],
    ], $extra));
    ?>
    <div class="roleplay-reader" data-reader data-preview-endpoint="<?= lorkhan_ui_h($managementBasePath.'/api/v1/tts-previews') ?>" data-installation="<?= lorkhan_ui_h($state['installation']) ?>" data-csrf="<?= lorkhan_ui_h($csrf) ?>" data-connector="<?= lorkhan_ui_h($preview['default_connector_id'] ?? '') ?>" data-voice="<?= lorkhan_ui_h($preview['default_voice'] ?? '') ?>" data-max-length="<?= SpeechPreviewCatalog::MAX_TEXT_LENGTH ?>">
        <header class="reader-heading"><div><h1><?= lorkhan_ui_h($heading) ?></h1><p><?= lorkhan_ui_h($description) ?></p></div><?php if ($editable): ?><a class="roleplay-button" href="<?= lorkhan_ui_h($webRoot.'/ui/narrative_manager.php') ?>">Create / Generate Entry</a><?php endif; ?></header>
        <form class="reader-filters" method="get">
            <input type="hidden" name="tab" value="<?= lorkhan_ui_h($tab) ?>">
            <label>Installation<select name="installation_id" data-reader-scope><?php foreach ($installationOptions as $id => $name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id === $state['installation'] ? ' selected' : '' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label>Playthrough<select name="playthrough_id" data-reader-scope><?php foreach ($state['playthroughs'] as $id => $name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id === $state['playthrough'] ? ' selected' : '' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label>Person<select name="person"><option value="">All People</option><?php foreach ($state['people'] as $id => $name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id === $state['person'] ? ' selected' : '' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label>Date (UTC)<input type="date" name="date" value="<?= lorkhan_ui_h($state['date']) ?>"></label>
            <label class="reader-search">Search<input type="search" name="q" maxlength="200" value="<?= lorkhan_ui_h($state['query']) ?>" placeholder="Search titles and entries"></label>
            <button class="roleplay-button" type="submit">Filter</button><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['person' => '', 'date' => '', 'q' => '', 'reader_page' => 1])) ?>">Reset</a>
        </form>
        <div class="reader-toolbar"><span><?= $state['total'] ?> entries · Page <?= $state['page'] ?> of <?= $state['pages'] ?></span><div><button class="roleplay-button" type="button" data-reader-refresh>Refresh</button><button class="roleplay-button" type="button" data-reader-stop hidden>Stop Reading</button></div></div>
        <p class="reader-audio-note"><?= $ready ? 'Read Aloud uses the Narrator voice, or the available TTS default. Each sentence is generated only when it is ready to play. Your provider may charge for speech.' : 'To use Read Aloud, configure a TTS connector and voice in TTS Studio.' ?></p>
        <p class="reader-status" role="status" aria-live="polite" data-reader-status></p><audio controls preload="none" data-reader-audio hidden></audio>
        <div class="reader-entries">
        <?php foreach ($state['rows'] as $row): $id = (string) $row['narrative_id']; ?>
            <article class="reader-entry" data-reader-entry>
                <header><div><span class="reader-person"><?= lorkhan_ui_h($row['person']) ?><?php if ($tab === 'responselog'): ?> · <?= lorkhan_ui_h($row['kind']) ?><?php endif; ?></span><h2 data-reader-title><?= lorkhan_ui_h($row['title']) ?></h2></div><time><?= lorkhan_ui_h((string) $row['created_at']) ?></time></header>
                <div class="reader-prose" data-reader-text><?= nl2br(lorkhan_ui_h($row['content'])) ?></div>
                <div class="reader-entry-actions"><?php if ($ready): ?><button type="button" class="roleplay-button" data-reader-play>Read Aloud</button><?php endif; ?><button type="button" class="roleplay-button" data-reader-export>Export Text</button></div>
                <?php if ($editable): ?><details class="reader-edit"><summary>Edit Entry</summary><form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/narrative-revise') ?>" data-reader-form>
                    <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="narrative_id" value="<?= lorkhan_ui_h($id) ?>"><input type="hidden" name="kind" value="<?= lorkhan_ui_h($row['kind']) ?>"><input type="hidden" name="provenance" value="management diary edit">
                    <label>Title<input name="title" maxlength="256" required value="<?= lorkhan_ui_h($row['title']) ?>"></label><label>Entry<textarea name="content" maxlength="65536" required rows="10"><?= lorkhan_ui_h($row['content']) ?></textarea></label><button class="roleplay-button" type="submit">Save Entry</button>
                </form></details>
                <form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/narrative-delete') ?>" data-reader-form data-reader-delete><input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="narrative_id" value="<?= lorkhan_ui_h($id) ?>"><button class="roleplay-button danger" type="submit">Delete Entry</button></form><?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if ($state['rows'] === []): ?><p class="reader-empty">No entries match these filters. Choose another person, date or playthrough.</p><?php endif; ?>
        </div>
        <nav class="reader-pagination" aria-label="Entry pages"><?php if ($state['page'] > 1): ?><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page' => 1])) ?>">First</a><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page' => $state['page'] - 1])) ?>">Previous</a><?php endif; ?><span><?= $state['page'] ?> / <?= $state['pages'] ?></span><?php if ($state['page'] < $state['pages']): ?><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page' => $state['page'] + 1])) ?>">Next</a><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page' => $state['pages']])) ?>">Last</a><?php endif; ?></nav>
    </div>
    <?php
}
