<?php
declare(strict_types=1);

/** Render CHIM's log-table hierarchy using scoped records and escaped prompt messages. */
function lorkhan_roleplay_log_table(array $state, array $installations, string $tab, string $webRoot, string $base, string $csrf): void
{
    $responses = $tab === 'responselog';
    $journal = $tab === 'journal';
    $books = $tab === 'books';
    $recordLabel = $responses ? 'Response' : ($journal ? 'Journal' : 'Book');
    $link = static fn(array $changes): string => $webRoot.'/ui/events-memories.php?'.http_build_query(array_merge([
        'tab'=>$tab,'installation_id'=>$state['installation'],'playthrough_id'=>$state['playthrough'],
        'reader_page'=>$state['page'],'q'=>$state['query'],'person'=>$state['person'],'date'=>$state['date'],
    ], $changes));
    ?>
    <div class="roleplay-log-page<?= $responses?'':' book-log-page' ?><?= $books?' observed-books-page':'' ?>" data-log-page>
        <?php if($responses): ?><div class="roleplay-description"><strong>AI Responses:</strong>
            Complete log of AI-generated responses including the full context payload sent to the LLM. Use this to debug model behavior, prompt composition, Oghma topics, and timing.
        </div><?php else: ?><p class="book-log-intro"><?= $journal?'<strong>Morrowind Journal:</strong> Entries captured from your in-game journal.':'Books observed during your Morrowind playthrough.' ?></p><?php endif; ?>
        <?php if($books) ob_start(); ?>
        <details class="log-scope"><summary>Filters and playthrough</summary><form method="get" class="reader-filters">
            <input type="hidden" name="tab" value="<?= lorkhan_ui_h($tab) ?>">
            <label>Installation<select name="installation_id"><?php foreach($installations as $id=>$name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id===$state['installation']?' selected':'' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label>Playthrough<select name="playthrough_id"><?php foreach($state['playthroughs'] as $id=>$name): ?><option value="<?= lorkhan_ui_h($id) ?>"<?= $id===$state['playthrough']?' selected':'' ?>><?= lorkhan_ui_h($name) ?></option><?php endforeach; ?></select></label>
            <label>Search<input type="search" name="q" value="<?= lorkhan_ui_h($state['query']) ?>" maxlength="200"></label>
            <button type="submit" class="roleplay-button">Filter</button><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['q'=>'','person'=>'','date'=>'','reader_page'=>1])) ?>">Reset</a>
        </form><?php if(!$responses&&!$journal): ?><a class="roleplay-button log-export" href="<?= lorkhan_ui_h($link(['export'=>'1'])) ?>">Export Book Log</a><?php endif; ?></details>
        <?php if($books) $bookFilters=ob_get_clean(); ?>
        <?php if($responses||$journal||$state['pages']>1): ?>
        <div class="log-pagination"><nav aria-label="Log pages"><span>Page <?= $state['page'] ?> / <?= $state['pages'] ?> (<?= $state['total'] ?> rows)</span>
            <?php if($state['page']>1): ?><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page'=>$state['page']-1])) ?>">Previous</a><?php endif; ?>
            <?php if($state['page']<$state['pages']): ?><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page'=>$state['page']+1])) ?>">Next</a><?php endif; ?>
        </nav><?php if($responses||$journal): ?><div class="log-page-actions"><?php if($responses)lorkhan_roleplay_clear_button($state,'responses',$base,$csrf); ?><a class="roleplay-button log-export" href="<?= lorkhan_ui_h($link(['export'=>'1'])) ?>">Export <?= $recordLabel ?> Log</a></div><?php endif; ?></div>
        <?php endif; ?>
        <p role="status" data-roleplay-maintenance-status></p>
        <div class="log-table-container" tabindex="0" role="region" aria-label="<?= $responses?'AI response':$recordLabel ?> log">
            <?php if(!$responses&&!$journal&&$state['rows']===[]): ?>
                <p class="books-empty"><?= $state['query']!==''?'No books match this filter.':'No books found. Read some books in-game to see them here!' ?></p>
            <?php else: ?>
            <table class="<?= $responses?'ai-response-table':'books-table' ?><?= $books?' table table-striped table-bordered table-sm':'' ?>" data-log-table>
                <?= $books?'<tbody>':'<thead>' ?><tr<?php if($books): ?> class="primary"<?php endif; ?>><?php foreach($responses?['Time (UTC)','AI Response','Oghma Topic','Prompt','HTTP Request','rowid']:[$journal?'Journal ID':'Title','Content','Tamrielic Time','Time (UTC)','TS'] as $column): ?><th scope="col"><?php if($books&&$column==='Tamrielic Time'): ?><a href="https://en.uesp.net/wiki/Lore:Calendar" target="_blank" rel="noopener noreferrer"><?= lorkhan_ui_h($column) ?></a><?php else: ?><?= lorkhan_ui_h($column) ?><?php endif; ?></th><?php endforeach; ?></tr><?= $books?'':'</thead><tbody>' ?>
                <?php foreach($state['rows'] as $row): $id='log-entry-'.(int)$row['narrative_id']; ?>
                    <tr><?php if($responses): ?>
                        <td><?= lorkhan_ui_h(gmdate('d-m-Y H:i:s', strtotime($row['created_at']))) ?></td>
                        <td class="log-response-text"><?= lorkhan_ui_h($row['content']) ?></td>
                        <td><?= lorkhan_ui_h(implode(', ',$row['topics']) ?: 'None') ?></td>
                        <td><button class="roleplay-button" type="button" data-log-open="<?= $id ?>"><span aria-hidden="true">🧾</span> View Prompt</button></td>
                        <td class="log-request-text"><span><?= lorkhan_ui_h($row['input_kind'].': '.$row['input_text']) ?></span><small><?= lorkhan_ui_h($row['request_id']) ?><br><?= lorkhan_ui_h($row['kind']) ?><?php if($row['turn_seconds']!==null): ?> · Turn <?= number_format((float)$row['turn_seconds'],2) ?>s<?php endif; ?></small></td>
                    <?php else: ?>
                        <td><?= lorkhan_ui_h($row['title']) ?></td><td><button class="log-content-link" type="button" data-log-open="<?= $id ?>"><?= lorkhan_ui_h($row['content']) ?></button></td>
                        <td><?= lorkhan_ui_h($row['game_date_label']) ?></td><td><?= lorkhan_ui_h(gmdate('d-m-Y H:i:s', strtotime($row['created_at']))) ?></td>
                    <?php endif; ?><td><?= lorkhan_ui_h((string)($responses?$row['narrative_id']:$row['ts'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if($state['rows']===[]): ?><tr><td colspan="<?= $responses?6:5 ?>" class="log-empty">No <?= $responses?'AI responses':($journal?'journal entries':'books') ?> match this playthrough and filter.</td></tr><?php endif; ?></tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php if($books) echo $bookFilters; ?>
        <?php if($responses): ?><nav class="log-footer log-bottom-pagination" aria-label="Bottom log pages">
            <span>Page <?= $state['page'] ?> / <?= $state['pages'] ?> (<?= $state['total'] ?> rows)</span>
            <?php if($state['page']>1): ?><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page'=>$state['page']-1])) ?>">Previous</a><?php endif; ?>
            <?php if($state['page']<$state['pages']): ?><a class="roleplay-button" href="<?= lorkhan_ui_h($link(['reader_page'=>$state['page']+1])) ?>">Next</a><?php endif; ?>
        </nav><?php else: ?><p class="log-footer">Page <?= $state['page'] ?> / <?= $state['pages'] ?> · <?= $state['total'] ?> rows</p><?php endif; ?>
        <?php foreach($state['rows'] as $row): $id='log-entry-'.(int)$row['narrative_id']; ?>
            <dialog id="<?= $id ?>" class="log-content-modal<?= $responses?' response-prompt-viewer':'' ?>" aria-labelledby="<?= $id ?>-title">
                <header><h2 id="<?= $id ?>-title"><?= $responses?'📜 Prompt Viewer':lorkhan_ui_h($row['title']) ?></h2><div><span role="status" data-log-status></span><button type="button" class="roleplay-button" data-log-copy><?= $responses?'📋 Copy':'Copy' ?></button><button type="button" class="roleplay-button" data-log-close aria-label="Close reader">✕</button></div></header>
                <div class="log-modal-body" data-log-copy-text>
                    <?php if($responses): ?>
                        <?php if(($row['prompt_label']??'')!==''||($row['prompt_driver']??'')!==''||($row['prompt_model']??'')!==''): ?><div class="prompt-metadata"><?php foreach(['prompt_label','prompt_driver','prompt_model'] as $metaKey): if(($row[$metaKey]??'')==='')continue; ?><span class="prompt-meta<?= $metaKey==='prompt_label'?' prompt-meta-label':'' ?>"><?= lorkhan_ui_h($row[$metaKey]) ?></span><?php endforeach; ?></div><?php endif; ?>
                        <?php foreach($row['prompt_messages'] as $index=>$message): ?><section class="prompt-modal-message prompt-role-<?= lorkhan_ui_h($message['role']) ?>"><header class="prompt-modal-message-header"><strong class="prompt-role"><?= lorkhan_ui_h(strtoupper($message['role'])) ?></strong><span class="prompt-index">#<?= $index ?></span></header><div class="prompt-modal-message-body"><?= lorkhan_ui_h($message['content']) ?></div></section><?php endforeach; ?>
                        <?php if($row['prompt_messages']===[]): ?><pre class="prompt-raw">No frozen prompt messages were recorded for this response.</pre><?php endif; ?>
                    <?php else: ?><div class="log-response-text"><?= lorkhan_ui_h($row['content']) ?></div><?php endif; ?>
                </div>
            </dialog>
        <?php endforeach; ?>
    </div>
    <?php
}

/** Keep bulk buttons identical on log and calendar pages, with explicit scope and CSRF metadata. */
function lorkhan_roleplay_clear_button(array $state, string $kind, string $base, string $csrf): void
{
    ?><button type="button" class="roleplay-button btn-danger log-clear" data-roleplay-clear="<?= lorkhan_ui_h($kind) ?>"
        data-endpoint="<?= lorkhan_ui_h($base.'/api/v1/roleplay/clear') ?>" data-csrf="<?= lorkhan_ui_h($csrf) ?>"
        data-installation="<?= lorkhan_ui_h($state['installation']) ?>" data-playthrough="<?= lorkhan_ui_h($state['playthrough']) ?>"<?= $state['playthrough']===''?' disabled':'' ?>><?= match($kind){'diaries'=>'Delete All Diary Entries','memories'=>'Delete All Memory Summaries',default=>'Clean Response Log'} ?></button><?php
}
