<?php
declare(strict_types=1);

use LorkhanServer\Application\MorrowindCalendar;

/** Share display/export formatting while keeping raw source events untouched. */
function lorkhan_adventure_record(array $row): array
{
    $context = trim((string) preg_replace('/\(Context location:[^)]+\)/i', '', (string) $row['content']));
    $people = array_values(array_filter(array_map('trim', explode('|', trim((string) $row['person'], "|() "))), static fn(string $name): bool => $name !== ''));
    $speaker = preg_match('/^([^:\r\n]+):/u', $context, $match) === 1 ? trim($match[1]) : ($people[0] ?? 'Narrator');
    $gameTime = $row['game_date_label'] ?? MorrowindCalendar::parse($row['calendar_data'] ?? null)['label'] ?? 'Not recorded';
    if ($gameTime === 'Not recorded' && (int) ($row['gamets'] ?? 0) > 0) {
        $seconds = (int) $row['gamets'];
        $gameTime = 'Day '.(intdiv($seconds, 86400) + 1).', '.sprintf('%02d:%02d', intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60));
    }
    return ['context' => $context, 'people' => implode(', ', $people), 'speaker' => $speaker,
        // OpenMW supplies complete cell names; commas belong to those names, not Skyrim location metadata.
        'location' => trim((string) ($row['location'] ?? '')), 'game_time' => $gameTime,
        'time_utc' => gmdate('d-m-Y H:i:s', strtotime((string) $row['created_at']))];
}

/** Render chronological events with the reference's location dividers and contiguous speaker bands. */
function lorkhan_adventure_table(array $rows, bool $dateSelected = true): void
{
    $previousLocation = null;
    $previousSpeaker = null;
    $speakerGroup = 0;
    ?>
    <div class="calendar-event-scroll" tabindex="0" role="region" aria-label="Adventure events">
        <table class="calendar-event-table adventure-event-table" id="adventure-events">
            <colgroup><col class="col-context"><col class="col-people"><col class="col-gamets"><col class="col-time"></colgroup>
            <tbody><tr><th scope="col">Context</th><th scope="col">Nearby People</th><th scope="col"><a href="https://en.uesp.net/wiki/Lore:Calendar" target="_blank" rel="noopener noreferrer">Tamrielic Time</a></th><th scope="col">Time (UTC)</th></tr>
            <?php foreach ($rows as $row): $entry = lorkhan_adventure_record($row);
                if ($previousLocation !== $entry['location']): ?>
                <tr class="location-change-row"><td colspan="4"><?= $previousLocation === null ? 'Current Location: ' : 'Location Change: ' ?><?= lorkhan_ui_h($entry['location'] !== '' ? $entry['location'] : 'Not recorded') ?></td></tr>
                <?php $previousLocation = $entry['location']; endif;
                if ($previousSpeaker !== $entry['speaker']) { $speakerGroup++; $previousSpeaker = $entry['speaker']; }
                ?>
                <tr class="<?= $speakerGroup % 2 === 0 ? 'speaker-even' : 'speaker-odd' ?>" data-adventure-row="<?= lorkhan_ui_h($row['narrative_id']) ?>"><td><?= nl2br(lorkhan_ui_h($entry['context'])) ?></td><td><?= lorkhan_ui_h($entry['people']) ?></td><td><?= lorkhan_ui_h($entry['game_time']) ?></td><td><?= lorkhan_ui_h($entry['time_utc']) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="4" class="log-empty"><?= $dateSelected ? 'No events found for this date.' : 'Select a date to view events.' ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
