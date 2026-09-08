<?php
declare(strict_types=1);

use LorkhanServer\Application\MorrowindCalendar;

/** Render either UTC or recorded Tamrielic dates, using Morrowind's fixed-length year. */
function lorkhan_roleplay_calendar(array $state, callable $link, string $tab): void
{
    $month = new DateTimeImmutable(($state['month'] ?? gmdate('Y-m')).'-01', new DateTimeZone('UTC'));
    $personMode = ($_GET['view'] ?? '') === 'people' && $tab === 'diaries';
    $game = ($state['calendar_mode'] ?? '') === 'tamrielic';
    $selectedDate = $game ? ($state['game_date'] ?? '') : $state['date'];
    $dayNames = $game ? ['Sundas','Morndas','Tirdas','Middas','Turdas','Fredas','Loredas'] : ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    if ($game) {
        $year=$state['game_year']??427; $index=($state['game_month']??8)-1;
        $days=MorrowindCalendar::DAYS_PER_MONTH[$index];
        // OpenMW's Morrowind calendar anchors 16 Last Seed, 3E 427 on Fredas; no leap years.
        $ordinal=($year-427)*365+array_sum(array_slice(MorrowindCalendar::DAYS_PER_MONTH,0,$index));
        $offset=(($ordinal-227+5)%7+7)%7;
        $prefix=sprintf('%04d-%02d-',$year,$index+1);
        $heading=MorrowindCalendar::MONTHS[$index].', 3E '.$year;
        $previousName=MorrowindCalendar::MONTHS[($index+11)%12]; $nextName=MorrowindCalendar::MONTHS[($index+1)%12];
        $previous=['game_year'=>max(1,$year-($index===0?1:0)),'game_month'=>($index+11)%12+1,'game_date'=>'','reader_page'=>1];
        $next=['game_year'=>min(9999,$year+($index===11?1:0)),'game_month'=>($index+1)%12+1,'game_date'=>'','reader_page'=>1];
    } else {
        $days=(int)$month->format('t'); $offset=(int)$month->format('w'); $prefix=$month->format('Y-m-'); $heading=$month->format('F Y');
        $previousName=$month->modify('-1 month')->format('F'); $nextName=$month->modify('+1 month')->format('F');
        $previous=['month'=>$month->modify('-1 month')->format('Y-m'),'date'=>'','reader_page'=>1];
        $next=['month'=>$month->modify('+1 month')->format('Y-m'),'date'=>'','reader_page'=>1];
    }
    ?>
    <div class="calendar-mode-toggle">
        <a class="roleplay-button<?= !$personMode&&!$game?' active':'' ?>" href="<?= lorkhan_ui_h($link(['view'=>'calendar','calendar'=>'regular','person'=>'','game_date'=>''])) ?>">Regular Calendar</a>
        <a class="roleplay-button<?= !$personMode&&$game?' active':'' ?>" href="<?= lorkhan_ui_h($link(['view'=>'calendar','calendar'=>'tamrielic','person'=>'','date'=>''])) ?>">Tamrielic Calendar</a>
        <?php if($tab==='diaries'): ?><a class="roleplay-button<?= $personMode?' active':'' ?>" href="<?= lorkhan_ui_h($link(['view'=>'people','date'=>'','game_date'=>'','reader_page'=>1])) ?>">Filter by Person</a><?php endif; ?>
    </div>
    <?php if($personMode): ?>
        <div class="diary-people-list" data-diary-people>
            <input type="search" class="diary-people-search" placeholder="Search people..." aria-label="Search diary authors" data-diary-people-search>
            <?php foreach($state['people'] as $id=>$name): ?><a class="diary-people-item<?= $state['person']===$id?' active':'' ?>" data-diary-person href="<?= lorkhan_ui_h($link(['person'=>$id,'date'=>'','game_date'=>'','reader_page'=>1,'view'=>'people'])) ?>"<?= $state['person']===$id?' aria-current="true"':'' ?>><span data-person-name><?= lorkhan_ui_h($name) ?></span><span class="diary-people-count" aria-label="Entry count"><?= (int)($state['people_counts'][$id]??0) ?></span></a><?php endforeach; ?>
            <p data-diary-people-empty<?= $state['people']===[]?'':' hidden' ?>>No diary authors found.</p>
        </div>
        <?php if(isset($state['people'][$state['person']])): $bookPath=preg_replace('/events-memories\.php.*$/','diary_book.php',$link([])); ?>
            <div class="diary-book-link"><a class="roleplay-button" target="_blank" rel="noopener" title="Open as book (print to PDF)" href="<?= lorkhan_ui_h($bookPath.'?'.http_build_query(['installation_id'=>$state['installation'],'playthrough_id'=>$state['playthrough'],'person'=>$state['person']])) ?>">📄 Open The Diary of <?= lorkhan_ui_h($state['people'][$state['person']]) ?></a></div>
        <?php endif; ?>
    <?php else: ?>
        <nav class="calendar-navigation" aria-label="Calendar month">
            <a href="<?= lorkhan_ui_h($link($previous)) ?>">« <?= lorkhan_ui_h($previousName) ?></a>
            <span><b><?= lorkhan_ui_h($heading) ?></b></span>
            <a href="<?= lorkhan_ui_h($link($next)) ?>"><?= lorkhan_ui_h($nextName) ?> »</a>
        </nav>
        <?php if($game && ($state['calendar']??[])===[]): ?><p class="calendar-empty-note">No recorded Morrowind dates in this month. Entries without a recorded game date remain available in Regular Calendar.</p><?php endif; ?>
        <table class="calendar diary-calendar"><thead><tr><?php foreach($dayNames as $day): ?><th scope="col"><?= $day ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php $cells=(int)(ceil(($offset+$days)/7)*7);
        for($cell=0;$cell<$cells;$cell++): if($cell%7===0)echo '<tr>'; $number=$cell-$offset+1;
            if($number<1 || $number>$days): ?><td></td><?php else:
                $date=$prefix.sprintf('%02d',$number);$count=$state['calendar'][$date]??0;
            ?><td class="<?= $count>0?'has-event':'' ?><?= $date===$selectedDate?' selected-date':'' ?><?= !$game&&$date===gmdate('Y-m-d')?' current-date':'' ?>"><a href="<?= lorkhan_ui_h($link([$game?'game_date':'date'=>$date,'reader_page'=>1]).($tab==='adventure'?'#adventure-events':'')) ?>" data-event-count="<?= $count ?>" aria-label="<?= lorkhan_ui_h($date) ?>: <?= $count ?> entries"<?= $date===$selectedDate?' aria-current="date"':'' ?>><?= $number ?></a></td><?php endif;
            if($cell%7===6)echo '</tr>';endfor; ?>
        </tbody></table>
    <?php endif;
}
