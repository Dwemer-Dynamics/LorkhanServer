<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

/** Shared date presentation for recorded Morrowind calendar globals, never Skyrim timestamps. */
final class MorrowindCalendar
{
    public const MONTHS = ['Morning Star', "Sun's Dawn", 'First Seed', "Rain's Hand", 'Second Seed', 'Midyear', "Sun's Height", 'Last Seed', 'Hearthfire', 'Frostfall', "Sun's Dusk", 'Evening Star'];
    public const DAYS_PER_MONTH = [31,28,31,30,31,30,31,31,30,31,30,31];

    /** Validate recorded Morrowind globals; missing dates stay unknown rather than becoming Skyrim dates. */
    public static function parse(mixed $value): ?array
    {
        $value = is_string($value) ? json_decode($value, true, 16) : $value;
        if (!is_array($value)) return null;
        foreach (['year','month','day'] as $key) if (!is_int($value[$key] ?? null)) return null;
        ['year'=>$year, 'month'=>$month, 'day'=>$day] = $value;
        if ($year<1 || $year>9999 || $month<0 || $month>11 || $day<1 || $day>self::DAYS_PER_MONTH[$month]) return null;
        $time = '';
        if (is_numeric($value['hour'] ?? null) && $value['hour']>=0 && $value['hour']<24) {
            $minutes = (int)floor((float)$value['hour']*60);
            $time = sprintf('%02d:%02d', intdiv($minutes,60), $minutes%60);
        } elseif (is_string($value['time'] ?? null) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value['time'])) {
            $time = $value['time'];
        }
        $minuteOfDay=$time===''?0:((int)substr($time,0,2)*60+(int)substr($time,3,2));
        return ['year'=>$year, 'month'=>$month, 'day'=>$day, 'time'=>$time,
            'minute'=>(($year-1)*365+array_sum(array_slice(self::DAYS_PER_MONTH,0,$month))+$day-1)*1440+$minuteOfDay,
            'date'=>sprintf('%04d-%02d-%02d',$year,$month+1,$day),
            'label'=>$day.' '.self::MONTHS[$month].', 3E '.$year.($time===''?'':' · '.$time)];
    }
}
