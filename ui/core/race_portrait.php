<?php
declare(strict_types=1);

/** Select a bundled CHIM race portrait without allowing profile text to become a file path. */
function lorkhan_race_portrait(string $race): string
{
    $race = preg_replace('/[^a-z]/', '', strtolower($race));
    $race = preg_replace('/race$/', '', $race);
    $portraits = [
        'argonian'=>'argonian', 'breton'=>'breton', 'imperial'=>'imperial',
        'darkelf'=>'darkelf', 'dunmer'=>'darkelf',
        'highelf'=>'highelf', 'altmer'=>'highelf',
        'woodelf'=>'woodelf', 'bosmer'=>'woodelf',
        'khajiit'=>'khajit', 'khajit'=>'khajit',
        'nord'=>'nord', 'redguard'=>'redguard', 'orc'=>'orc', 'orsimer'=>'orc',
    ];
    return ($portraits[$race] ?? 'default') . '.png';
}
