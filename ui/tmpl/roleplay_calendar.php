<?php
declare(strict_types=1);

/** Calendar navigation uses recorded UTC dates; it never applies Skyrim's epoch to Morrowind. */
function lorkhan_roleplay_calendar(array $state, callable $link, string $tab): void
{
    $month = new DateTimeImmutable(($state['month'] ?? gmdate('Y-m')).'-01', new DateTimeZone('UTC'));
    $personMode = ($_GET['view'] ?? '') === 'people' && $tab === 'diaries';
    ?>
    <div class="calendar-mode-toggle">
        <a class="roleplay-button<?= !$personMode?' active':'' ?>" href="<?= lorkhan_ui_h($link(['view'=>'calendar','person'=>''])) ?>">Regular Calendar</a>
        <?php if($tab==='diaries'): ?><a class="roleplay-button<?= $personMode?' active':'' ?>" href="<?= lorkhan_ui_h($link(['view'=>'people','date'=>''])) ?>">Filter by Person</a><?php endif; ?>
    </div>
    <?php if($personMode): ?>
        <div class="calendar-people"><?php foreach($state['people'] as $id=>$name): ?><a class="roleplay-button<?= $state['person']===$id?' active':'' ?>" href="<?= lorkhan_ui_h($link(['person'=>$id,'date'=>'','reader_page'=>1,'view'=>'people'])) ?>"><?= lorkhan_ui_h($name) ?></a><?php endforeach; ?><?php if($state['people']===[]): ?><p>No diary authors in this playthrough yet.</p><?php endif; ?></div>
    <?php else: ?>
        <nav class="calendar-navigation" aria-label="Calendar month">
            <a href="<?= lorkhan_ui_h($link(['month'=>$month->modify('-1 month')->format('Y-m'),'date'=>'','reader_page'=>1])) ?>">« <?= lorkhan_ui_h($month->modify('-1 month')->format('F')) ?></a>
            <span><?= lorkhan_ui_h($month->format('F Y')) ?></span>
            <a href="<?= lorkhan_ui_h($link(['month'=>$month->modify('+1 month')->format('Y-m'),'date'=>'','reader_page'=>1])) ?>"><?= lorkhan_ui_h($month->modify('+1 month')->format('F')) ?> »</a>
        </nav>
        <div class="calendar-scroll"><table class="calendar diary-calendar"><thead><tr><?php foreach(['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $day): ?><th scope="col"><?= $day ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php $offset=(int)$month->format('w'); $days=(int)$month->format('t'); $cells=(int)(ceil(($offset+$days)/7)*7);
        for($cell=0;$cell<$cells;$cell++): if($cell%7===0)echo '<tr>'; $number=$cell-$offset+1;
            if($number<1 || $number>$days): ?><td></td><?php else:
                $date=$month->format('Y-m-').sprintf('%02d',$number);$count=$state['calendar'][$date]??0;
            ?><td class="<?= $count>0?'has-event':'' ?><?= $date===$state['date']?' selected-date':'' ?><?= $date===gmdate('Y-m-d')?' current-date':'' ?>"><a href="<?= lorkhan_ui_h($link(['date'=>$date,'reader_page'=>1])) ?>" title="<?= $number ?>: <?= $count ?> entries"<?= $date===$state['date']?' aria-current="date"':'' ?>><?= $number ?><?php if($count>0): ?><small><?= $count ?> entries</small><?php endif; ?></a></td><?php endif;
            if($cell%7===6)echo '</tr>';endfor; ?>
        </tbody></table></div>
    <?php endif;
}
