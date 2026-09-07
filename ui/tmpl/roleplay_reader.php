<?php
declare(strict_types=1);

use LorkhanServer\Application\SpeechPreviewCatalog;
use LorkhanServer\Application\MorrowindCalendar;
require __DIR__.'/roleplay_calendar.php';
require __DIR__.'/adventure_log.php';

/** Read one scoped page before applying the limit, so older diaries remain reachable. */
function lorkhan_roleplay_reader_state(PDO $database, array $installationOptions, string $tab): array
{
    $installation = (string) ($_GET['installation_id'] ?? array_key_first($installationOptions) ?? '');
    if (!isset($installationOptions[$installation])) $installation = (string) (array_key_first($installationOptions) ?? '');
    $state = ['installation' => $installation, 'playthrough' => '', 'person' => '', 'date' => '', 'query' => '',
        'page' => 1, 'pages' => 1, 'total' => 0, 'rows' => [], 'people' => [], 'playthroughs' => []];
    $state += ['calendar_mode'=>in_array($tab,['diaries','adventure'],true) && ($_GET['calendar']??'')==='tamrielic'?'tamrielic':'regular','game_year'=>427,'game_month'=>8,'game_date'=>''];
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
        'books' => "SELECT b.rowid::text AS narrative_id,m.installation_id,m.playthrough_id,''::text AS profile_id,'Observed book'::text AS person,'book'::text AS kind,b.title,COALESCE(b.content,'') AS content,to_timestamp(b.localts) AS created_at,NULL::timestamptz AS deleted_at,b.ts,t.context#>'{world,calendar}' AS calendar_data FROM public.books b JOIN lorkhan_internal.book_metadata m ON m.rowid=b.rowid LEFT JOIN turns t ON t.turn_id=m.source_turn_id",
        'journal' => "SELECT q.rowid::text AS narrative_id,m.installation_id,m.playthrough_id,''::text AS profile_id,'Morrowind Journal'::text AS person,'journal'::text AS kind,q.id_quest AS title,COALESCE(NULLIF(q.briefing2,''),NULLIF(q.briefing,''),q.data) AS content,to_timestamp(q.localts) AS created_at,NULL::timestamptz AS deleted_at,q.ts,t.context#>'{world,calendar}' AS calendar_data FROM public.questlog q JOIN lorkhan_internal.questlog_metadata m ON m.rowid=q.rowid LEFT JOIN turns t ON t.turn_id=m.source_turn_id",
        'responselog' => "SELECT l.rowid::text AS narrative_id,s.installation_id,s.playthrough_id,COALESCE(p.profile_id::text,'') AS profile_id,COALESCE(p.name,'Unknown') AS person,t.state AS kind,COALESCE(p.name,'Unknown') AS title,COALESCE(l.response,'') AS content,to_timestamp(l.localts) AS created_at,NULL::timestamptz AS deleted_at,m.turn_id,m.request_id,t.input_kind,t.input_text,EXTRACT(EPOCH FROM (t.completed_at-t.accepted_at)) AS turn_seconds,snapshot.source_manifest#>'{message,_prompt,_messages}' AS prompt_messages FROM public.log l JOIN lorkhan_internal.log_metadata m ON m.rowid=l.rowid JOIN turns t ON t.turn_id=m.turn_id JOIN sessions s ON s.session_id=t.session_id LEFT JOIN prompt_traces trace ON trace.prompt_trace_id=m.prompt_trace_id LEFT JOIN profiles p ON p.profile_id=COALESCE(trace.selected_profile_id,trace.profile_id,s.profile_id) LEFT JOIN turn_provider_snapshots snapshot ON snapshot.turn_id=t.turn_id",
        'adventure' => "SELECT e.rowid::text AS narrative_id,m.installation_id,m.playthrough_id,''::text AS profile_id,COALESCE(e.people,'') AS person,e.type AS kind,e.type AS title,e.data AS content,to_timestamp(e.localts) AS created_at,m.suppressed_at AS deleted_at,e.location,e.gamets,COALESCE(m.payload#>'{context,world,calendar}',m.payload->'calendar',t.context#>'{world,calendar}') AS calendar_data FROM public.eventlog e JOIN lorkhan_internal.eventlog_metadata m ON m.rowid=e.rowid LEFT JOIN turns t ON t.turn_id=m.turn_id WHERE e.type IN ('im_alive','chat','infoaction','rpg_word','rpg_lvlup','rechat','quest','itemfound','inputtext','goodnight','goodmorning','ginputtext','death','combatendmighty','combatend','location','travel','sleep','wait','book','journal','bored','info')",
        default => "SELECT n.narrative_id::text,n.installation_id,n.playthrough_id,n.profile_id::text,p.name AS person,n.kind,n.title,n.content,n.created_at,n.deleted_at,COALESCE(n.provenance->'calendar',(SELECT t.context#>'{world,calendar}' FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE s.installation_id=n.installation_id AND s.playthrough_id=n.playthrough_id AND jsonb_exists(COALESCE(n.provenance->'source_turn_ids','[]'::jsonb),t.turn_id::text) AND t.context#>'{world,calendar}' IS NOT NULL ORDER BY t.accepted_at DESC LIMIT 1)) AS calendar_data FROM narrative_records n JOIN profiles p ON p.profile_id=n.profile_id AND p.installation_id=n.installation_id",
    };
    $from = ' FROM ('.$source.') n';
    $params = ['installation' => $installation, 'playthrough' => $playthrough];
    $where = 'n.installation_id=:installation AND n.playthrough_id=:playthrough AND n.deleted_at IS NULL';
    if ($tab === 'diaries') $where .= " AND n.kind='diary'";

    $statement = $database->prepare('SELECT DISTINCT n.profile_id,n.person AS name'.$from.' WHERE '.$where.' ORDER BY name,n.profile_id');
    $statement->execute($params);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) if ((string) $row['profile_id'] !== '') $state['people'][(string) $row['profile_id']] = (string) $row['name'];
    $person = (string) ($_GET['person'] ?? '');
    if (isset($state['people'][$person])) { $state['person'] = $person; $where .= ' AND n.profile_id=:person'; $params['person'] = $person; }
    $state['month'] = gmdate('Y-m'); $state['calendar'] = [];
    if (in_array($tab, ['diaries', 'adventure'], true) && $state['calendar_mode']==='regular') {
        $month = (string) ($_GET['month'] ?? gmdate('Y-m'));
        $monthDate = preg_match('/^\d{4}-\d{2}$/D', $month) === 1 ? DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone('UTC')) : false;
        if ($monthDate === false || $monthDate->format('Y-m') !== $month) $monthDate = new DateTimeImmutable('first day of this month', new DateTimeZone('UTC'));
        $state['month'] = $monthDate->format('Y-m');
        $calendarQuery = $database->prepare("SELECT to_char(n.created_at AT TIME ZONE 'UTC','YYYY-MM-DD') AS day,count(*) AS total".$from.' WHERE '.$where.' AND n.created_at>=CAST(:month_from AS timestamptz) AND n.created_at<CAST(:month_to AS timestamptz) GROUP BY day');
        $calendarQuery->execute($params + ['month_from' => $monthDate->format('Y-m-01\T00:00:00P'), 'month_to' => $monthDate->modify('+1 month')->format('Y-m-01\T00:00:00P')]);
        foreach ($calendarQuery->fetchAll(PDO::FETCH_ASSOC) as $day) $state['calendar'][$day['day']] = (int) $day['total'];
    }
    if ($state['calendar_mode']==='tamrielic') {
        $latestQuery=$database->prepare('SELECT n.calendar_data'.$from.' WHERE '.$where.' AND n.calendar_data IS NOT NULL ORDER BY n.created_at DESC LIMIT 1');
        $latestQuery->execute($params); $latest=MorrowindCalendar::parse($latestQuery->fetchColumn());
        $year=filter_var($_GET['game_year']??($latest['year']??427),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>9999]]);
        $month=filter_var($_GET['game_month']??(isset($latest['month'])?$latest['month']+1:8),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>12]]);
        $state['game_year']=$year===false?427:$year; $state['game_month']=$month===false?8:$month;
        $monthScope=['year'=>$state['game_year'],'month'=>$state['game_month']-1];
        $calendarQuery=$database->prepare("SELECT n.calendar_data->>'day' AS day,count(*) AS total".$from.' WHERE '.$where.' AND n.calendar_data @> CAST(:calendar_month AS jsonb) GROUP BY day');
        $calendarQuery->execute($params+['calendar_month'=>json_encode($monthScope)]);
        foreach($calendarQuery->fetchAll(PDO::FETCH_ASSOC) as $day) {
            $parsedDay=MorrowindCalendar::parse($monthScope+['day'=>(int)$day['day']]);
            if($parsedDay!==null)$state['calendar'][$parsedDay['date']]=(int)$day['total'];
        }
        $gameDate=(string)($_GET['game_date']??'');
        if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$gameDate,$parts)) {
            $dateScope=['year'=>(int)$parts[1],'month'=>(int)$parts[2]-1,'day'=>(int)$parts[3]];
            if(MorrowindCalendar::parse($dateScope)!==null) {
                $state['game_date']=$gameDate; $where.=' AND n.calendar_data @> CAST(:calendar_date AS jsonb)';
                $params['calendar_date']=json_encode($dateScope);
            }
        }
    }
    $date = $state['calendar_mode']==='tamrielic'?'':(string) ($_GET['date'] ?? '');
    $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC')) : false;
    if ($parsed !== false && $parsed->format('Y-m-d') === $date) {
        $state['date'] = $date; $where .= " AND n.created_at>=CAST(:date_from AS timestamptz) AND n.created_at<CAST(:date_to AS timestamptz)";
        $params['date_from'] = $parsed->format(DATE_ATOM); $params['date_to'] = $parsed->modify('+1 day')->format(DATE_ATOM);
    }
    $query = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 200));
    if ($query !== '') { $state['query'] = $query; $where .= " AND (n.title ILIKE :title_query OR n.content ILIKE :content_query)"; $params['title_query'] = '%'.$query.'%'; $params['content_query'] = '%'.$query.'%'; }
    $export = ($_GET['export'] ?? '') === '1';
    if ($tab === 'adventure' && $state['date'] === '' && $state['game_date'] === '') {
        if (!$export) {
            // The calendar stays populated, but the reference does not show events until a day is selected.
            $where .= ' AND FALSE';
        } elseif (($_GET['export_all'] ?? '') !== '1') {
            // Download Current Date falls back to the latest recorded UTC day, not the entire log.
            $latest = $database->prepare('SELECT max(n.created_at)'.$from.' WHERE '.$where);
            $latest->execute($params);
            $latestDate = $latest->fetchColumn();
            if ($latestDate) {
                $start = (new DateTimeImmutable((string) $latestDate))->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
                $where .= ' AND n.created_at>=CAST(:download_from AS timestamptz) AND n.created_at<CAST(:download_to AS timestamptz)';
                $params['download_from'] = $start->format(DATE_ATOM);
                $params['download_to'] = $start->modify('+1 day')->format(DATE_ATOM);
            }
        }
    }
    $statement = $database->prepare('SELECT count(*)'.$from.' WHERE '.$where); $statement->execute($params);
    $state['total'] = (int) $statement->fetchColumn(); $pageSize = match($tab) { 'responselog'=>50, 'books','journal'=>150, default=>20 }; $state['pages'] = max(1, (int) ceil($state['total'] / $pageSize));
    $state['page'] = max(1, min($state['pages'], (int) ($_GET['reader_page'] ?? 1)));
    $extra = match ($tab) { 'responselog' => ',n.turn_id,n.request_id,n.prompt_messages,n.input_kind,n.input_text,n.turn_seconds', 'adventure' => ',n.location,n.gamets,n.calendar_data', 'diaries' => ',n.calendar_data', 'books','journal' => ',n.calendar_data,n.ts', default => '' };
    $orderBy = $tab === 'adventure' ? 'n.created_at ASC,n.narrative_id::bigint ASC' : 'n.created_at DESC,n.narrative_id';
    $statement = $database->prepare('SELECT n.narrative_id,n.kind,n.title,n.content,n.created_at,n.person'.$extra.$from.' WHERE '.$where.' ORDER BY '.$orderBy.($export ? '' : ' LIMIT '.$pageSize.' OFFSET :offset'));
    foreach ($params as $key => $value) $statement->bindValue(':'.$key, $value, PDO::PARAM_STR);
    if (!$export) $statement->bindValue(':offset', ($state['page'] - 1) * $pageSize, PDO::PARAM_INT);
    $statement->execute();
    if ($export) {
        // Stream the selected scope without loading the entire log or exporting provider configuration.
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$tab.'-log.csv"');
        header('Cache-Control: private, no-store');
        $output = fopen('php://output', 'wb');
        fputcsv($output, $tab === 'adventure' ? ['Context', 'Nearby People', 'Location & Tamrielic Time', 'Time(UTC)'] : ['Time (UTC)', 'Person', 'Title', 'Content', 'State / Kind', 'ID', 'Tamrielic Time']);
        while ($record = $statement->fetch(PDO::FETCH_ASSOC)) {
            $values = [$record['created_at'], $record['person'], $record['title'], $record['content'], $record['kind'], $record['narrative_id'], MorrowindCalendar::parse($record['calendar_data']??null)['label']??'Not recorded'];
            if ($tab === 'adventure') {
                $entry = lorkhan_adventure_record($record);
                $values = [$entry['context'], $entry['people'], ($entry['location'] !== '' ? $entry['location'] : 'Not recorded').' - '.$entry['game_time'], $entry['time_utc']];
            }
            foreach ($values as &$value) { $value = (string) $value; if (preg_match('/^[\s]*[=+@-]/u', $value) === 1) $value = "'".$value; }
            unset($value); fputcsv($output, $values);
        }
        fclose($output); exit;
    }
    $state['rows'] = $statement->fetchAll(PDO::FETCH_ASSOC);
    foreach($state['rows'] as &$calendarRow)$calendarRow['game_date_label']=MorrowindCalendar::parse($calendarRow['calendar_data']??null)['label']??'Not recorded';
    unset($calendarRow);
    if ($tab === 'responselog') {
        $topics = $database->prepare("SELECT DISTINCT d.topic FROM retrieval_traces r JOIN knowledge_documents d ON d.document_id=ANY(r.result_ids) WHERE r.turn_id=:turn AND r.installation_id=:installation AND r.playthrough_id=:playthrough ORDER BY d.topic LIMIT 50");
        foreach ($state['rows'] as &$row) {
            $topics->execute(['turn' => $row['turn_id'], 'installation' => $installation, 'playthrough' => $playthrough]);
            $row['topics'] = $topics->fetchAll(PDO::FETCH_COLUMN);
            $row['prompt_messages'] = lorkhan_control_prompt_messages($row['prompt_messages']);
        }
        unset($row);
    }
    return $state;
}

/** Render the diary/adventure reader using saved narrative forms and the existing speech-preview service. */
function lorkhan_roleplay_reader(array $state, array $installationOptions, string $tab, string $webRoot, string $managementBasePath, string $csrf, array $preview): void
{
    $heading = match ($tab) { 'diaries' => 'Diary Log', 'books' => 'Books', 'journal' => 'Morrowind Journal', 'responselog' => 'AI Responses', default => 'Adventure Log' };
    $description = match ($tab) { 'diaries' => 'Read the diaries written by your companions, the Player and the Narrator.', 'books' => 'Read books observed during your Morrowind playthrough.', 'journal' => 'Read captured Morrowind Journal entries.', 'responselog' => 'Recorded AI dialogue with its delivery state.', default => 'Recorded dialogue and events from your Morrowind adventures.' };
    $editable = $tab === 'diaries';
    $calendar = in_array($tab, ['diaries', 'adventure'], true);
    $ready = ($preview['default_connector_id'] ?? '') !== '' && ($preview['default_voice'] ?? '') !== '';
    $link = static fn(array $extra): string => $webRoot.'/ui/events-memories.php?'.http_build_query(array_merge([
        'tab' => $tab, 'installation_id' => $state['installation'], 'playthrough_id' => $state['playthrough'],
        'person' => $state['person'], 'date' => $state['date'], 'q' => $state['query'], 'reader_page' => $state['page'],
        'month' => $state['month'] ?? gmdate('Y-m'), 'calendar'=>$state['calendar_mode'],'game_year'=>$state['calendar_mode']==='tamrielic'?$state['game_year']:null,'game_month'=>$state['calendar_mode']==='tamrielic'?$state['game_month']:null,'game_date'=>$state['game_date'], 'view' => ($_GET['view'] ?? '') === 'people' ? 'people' : 'calendar',
    ], $extra));
    ?>
    <div class="roleplay-reader<?= $calendar?' calendar-log-page':'' ?><?= $editable?' diary-log-page':'' ?>" data-reader data-preview-endpoint="<?= lorkhan_ui_h($managementBasePath.'/api/v1/tts-previews') ?>" data-installation="<?= lorkhan_ui_h($state['installation']) ?>" data-csrf="<?= lorkhan_ui_h($csrf) ?>" data-connector="<?= lorkhan_ui_h($preview['default_connector_id'] ?? '') ?>" data-voice="<?= lorkhan_ui_h($preview['default_voice'] ?? '') ?>" data-max-length="<?= SpeechPreviewCatalog::MAX_TEXT_LENGTH ?>">
        <header class="reader-heading"><div><h1><?= $tab==='diaries'?'<span aria-hidden="true">📝</span>':($tab==='adventure'?'<span aria-hidden="true">📆</span>':'') ?><?= lorkhan_ui_h($heading) ?></h1><p><?= lorkhan_ui_h($description) ?></p></div><?php if ($editable && !$calendar): ?><a class="roleplay-button" href="<?= lorkhan_ui_h($webRoot.'/ui/narrative_manager.php') ?>">Create / Generate Entry</a><?php endif; ?></header>
        <?php if($calendar): ?><div class="calendar-downloads"><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['export'=>'1'])) ?>">Download Current <?= $tab==='diaries'?'Diaries':'Date' ?></a><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['export'=>'1','export_all'=>$tab==='adventure'?'1':null,'date'=>'','game_date'=>'','person'=>'','q'=>''])) ?>">Download <?= $tab==='diaries'?'All Diary Entries':'Entire Adventure Log' ?></a><?php if($editable): lorkhan_roleplay_clear_button($state,'diaries',$managementBasePath,$csrf); ?><a class="roleplay-button" href="<?= lorkhan_ui_h($webRoot.'/ui/narrative_manager.php') ?>">Create / Generate Entry</a><?php endif; ?></div>
        <?php lorkhan_roleplay_calendar($state,$link,$tab); ?><details class="log-scope"><summary>Filters and playthrough</summary><?php endif; ?>
        <form class="reader-filters" method="get">
            <?php foreach(['game_year','game_month','game_date'] as $filter): ?><input type="hidden" name="<?= $filter ?>" value="<?= lorkhan_ui_h((string)$state[$filter]) ?>"><?php endforeach; ?>
            <input type="hidden" name="view" value="<?= ($_GET['view']??'')==='people'?'people':'calendar' ?>">
            <input type="hidden" name="tab" value="<?= lorkhan_ui_h($tab) ?>"><input type="hidden" name="calendar" value="<?= lorkhan_ui_h($state['calendar_mode']) ?>">
            <label>Installation<select name="installation_id" data-reader-scope><?php foreach ($installationOptions as $id => $name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id === $state['installation'] ? ' selected' : '' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label>Playthrough<select name="playthrough_id" data-reader-scope><?php foreach ($state['playthroughs'] as $id => $name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id === $state['playthrough'] ? ' selected' : '' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label>Person<select name="person"><option value="">All People</option><?php foreach ($state['people'] as $id => $name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id === $state['person'] ? ' selected' : '' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <?php if($state['calendar_mode']==='regular'): ?><label>Date (UTC)<input type="date" name="date" value="<?= lorkhan_ui_h($state['date']) ?>"></label><?php endif; ?>
            <label class="reader-search">Search<input type="search" name="q" maxlength="200" value="<?= lorkhan_ui_h($state['query']) ?>" placeholder="Search titles and entries"></label>
            <button class="roleplay-button" type="submit">Filter</button><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['person' => '', 'date' => '', 'game_date'=>'', 'q' => '', 'reader_page' => 1])) ?>">Reset</a>
        </form>
        <?php if($calendar): ?></details><?php endif; ?>
        <div class="reader-toolbar"><span><?= $state['total'] ?> entries · Page <?= $state['page'] ?> of <?= $state['pages'] ?></span><div><button class="roleplay-button" type="button" data-reader-refresh>Refresh</button><button class="roleplay-button" type="button" data-reader-stop hidden>Stop Reading</button></div></div>
        <?php if($tab!=='adventure'): ?><details class="reader-audio-help"><summary>Read Aloud help</summary><p class="reader-audio-note"><?= $ready ? 'Read Aloud uses the Narrator voice, or the available TTS default. Each sentence is generated only when it is ready to play. Your provider may charge for speech.' : 'To use Read Aloud, configure a TTS connector and voice in TTS Studio.' ?></p></details><?php endif; ?>
        <p role="status" data-roleplay-maintenance-status></p>
        <div data-reader-dock><p class="reader-status" role="status" aria-live="polite" data-reader-status></p><audio controls preload="none" data-reader-audio hidden></audio></div>
        <?php if($tab==='adventure'): lorkhan_adventure_table($state['rows'],$state['date']!=='' || $state['game_date']!==''); elseif($calendar): ?>
        <div class="calendar-event-scroll"><table class="calendar-event-table"><thead><tr><?php foreach(['Author','Content','Tamrielic Time','Time (UTC)','Actions'] as $label): ?><th scope="col"><?= lorkhan_ui_h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach($state['rows'] as $row): ?><tr>
        <td><?= lorkhan_ui_h($row['person']) ?></td><td><button type="button" class="log-content-link" data-calendar-open="entry-<?= lorkhan_ui_h($row['narrative_id']) ?>"><?= nl2br(lorkhan_ui_h($row['content'])) ?></button></td><td><?= lorkhan_ui_h($row['game_date_label']) ?></td><td><?= lorkhan_ui_h(gmdate('d-m-Y H:i:s',strtotime($row['created_at']))) ?></td><td><div class="diary-row-actions">
            <button type="button" class="roleplay-button diary-audio-button" data-reader-play data-reader-target="entry-<?= lorkhan_ui_h($row['narrative_id']) ?>"<?= $ready?'':' disabled' ?> title="<?= $ready?'Uses the Narrator voice or TTS default. Your provider may charge.':'Configure a TTS connector and voice in TTS Studio.' ?>">▶ Play</button>
            <button type="button" class="roleplay-button" data-calendar-open="edit-<?= lorkhan_ui_h($row['narrative_id']) ?>">Edit</button>
            <button type="button" class="roleplay-button danger" data-calendar-open="delete-<?= lorkhan_ui_h($row['narrative_id']) ?>">Delete</button>
        </div></td></tr>
        <?php endforeach; ?><?php if($state['rows']===[]): ?><tr><td colspan="5" class="log-empty">No entries match this date, person or playthrough.</td></tr><?php endif; ?></tbody></table></div>
        <?php endif; ?>
        <div class="reader-entries">
        <?php foreach ($tab==='adventure'?[]:$state['rows'] as $row): $id = (string) $row['narrative_id']; ?>
            <?php if($calendar): ?><dialog class="log-content-modal diary-entry-modal" id="entry-<?= lorkhan_ui_h($id) ?>" aria-label="Diary entry"><?php endif; ?>
            <article class="reader-entry" data-reader-entry>
                <header><div><span class="reader-person"><?= lorkhan_ui_h($row['person']) ?><?php if ($tab === 'responselog'): ?> · <?= lorkhan_ui_h($row['kind']) ?><?php endif; ?></span><h2 data-reader-title><?= lorkhan_ui_h($row['title']) ?></h2></div><time><?= lorkhan_ui_h((string) $row['created_at']) ?></time></header>
                <div class="reader-prose" data-reader-text><?= lorkhan_ui_h($row['content']) ?></div>
                <div class="reader-entry-actions"><button type="button" class="roleplay-button diary-audio-button" data-reader-play<?= $ready?'':' disabled' ?>>▶ Play Audio</button><button type="button" class="roleplay-button" data-reader-export>Export Text</button><?php if($calendar): ?><button type="button" class="roleplay-button" data-calendar-close autofocus>Close</button><?php endif; ?></div>
            </article>
            <?php if($calendar): ?></dialog><?php endif; ?>
            <?php if($editable) include __DIR__.'/diary_entry_editor.php'; ?>
        <?php endforeach; ?>
        <?php if ($state['rows'] === [] && !$calendar): ?><p class="reader-empty">No entries match these filters. Choose another person, date or playthrough.</p><?php endif; ?>
        </div>
        <nav class="reader-pagination" aria-label="Entry pages"><?php if ($state['page'] > 1): ?><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page' => 1])) ?>">First</a><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page' => $state['page'] - 1])) ?>">Previous</a><?php endif; ?><span><?= $state['page'] ?> / <?= $state['pages'] ?></span><?php if ($state['page'] < $state['pages']): ?><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page' => $state['page'] + 1])) ?>">Next</a><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page' => $state['pages']])) ?>">Last</a><?php endif; ?></nav>
    </div>
    <?php
}
