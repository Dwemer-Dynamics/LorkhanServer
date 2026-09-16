<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

/** Trusted graphs from HerikaServer 1d9a3d8ad1157e3fd429f85e3b992efc234dcfea, lib/core/tts_filter_presets.php (v2). */
final class TtsFilterPresets
{
    public const VERSION = 2;
    public static function catalog(): array
    {
    return [
        'none' => [
            'id' => 'none',
            'label' => 'None (default)',
            'description' => 'No additional filter. Speech uses the voice engine output.',
            'exposed' => true,
            'filters' => [],
        ],
        'warm' => [
            'id' => 'warm',
            'label' => 'Warm',
            'description' => 'Adds subtle warmth and presence while keeping volume even.',
            'exposed' => true,
            'filters' => [
                'highpass=f=70',
                'lowpass=f=15000',
                'equalizer=f=140:t=q:w=0.9:g=2.0',
                'equalizer=f=3000:t=q:w=1.0:g=1.0',
                'acompressor=threshold=-20dB:ratio=2:attack=10:release=120:makeup=1.5',
                'loudnorm=I=-16:TP=-1.5:LRA=8',
                'aresample=24000',
            ],
        ],
        'deep' => [
            'id' => 'deep',
            'label' => 'Deep',
            'description' => 'Adds low-end weight and reduces harshness without changing speed.',
            'exposed' => true,
            'filters' => [
                'highpass=f=55',
                'lowpass=f=11500',
                'equalizer=f=100:t=q:w=0.8:g=3.0',
                'equalizer=f=250:t=q:w=1.0:g=-1.0',
                'equalizer=f=2200:t=q:w=1.0:g=-1.5',
                'acompressor=threshold=-20dB:ratio=3:attack=10:release=150:makeup=2',
                'loudnorm=I=-16:TP=-1.5:LRA=7',
                'aresample=24000',
            ],
        ],
        'ethereal' => [
            'id' => 'ethereal',
            'label' => 'Ethereal',
            'description' => 'Adds airy presence with a soft, short double echo.',
            'exposed' => true,
            'filters' => [
                'highpass=f=120',
                'lowpass=f=12000',
                'equalizer=f=3500:t=q:w=1.0:g=1.5',
                'acompressor=threshold=-22dB:ratio=2:attack=12:release=180:makeup=1.5',
                'aecho=0.8:0.88:45|90:0.18|0.08',
                'loudnorm=I=-17:TP=-1.5:LRA=8',
                'aresample=24000',
            ],
        ],
        'sinister' => [
            'id' => 'sinister',
            'label' => 'Sinister',
            'description' => 'Darkens the voice and adds a restrained echo.',
            'exposed' => true,
            'filters' => [
                'highpass=f=60',
                'lowpass=f=9500',
                'equalizer=f=110:t=q:w=0.8:g=2.5',
                'equalizer=f=1800:t=q:w=1.0:g=-2.0',
                'equalizer=f=4200:t=q:w=1.1:g=1.0',
                'acompressor=threshold=-20dB:ratio=2.8:attack=10:release=160:makeup=2',
                'aecho=1.0:0.90:65:0.12',
                'loudnorm=I=-16:TP=-1.5:LRA=7',
                'aresample=24000',
            ],
        ],
        'automaton' => [
            'id' => 'automaton',
            'label' => 'Automaton',
            'description' => 'Adds a band-limited mechanical tone with light digital texture.',
            'exposed' => true,
            'filters' => [
                'highpass=f=240',
                'lowpass=f=3800',
                'equalizer=f=1200:t=q:w=0.8:g=3.0',
                'acompressor=threshold=-24dB:ratio=4:attack=5:release=90:makeup=2',
                'acrusher=bits=12:mix=0.18:mode=log',
                'aecho=1.0:0.85:28:0.08',
                'loudnorm=I=-16:TP=-1.5:LRA=6',
                'aresample=24000',
            ],
        ],
        'radio' => [
            'id' => 'radio',
            'label' => 'Radio',
            'description' => 'Creates a compressed communications tone with light digital grit.',
            'exposed' => true,
            'filters' => [
                'highpass=f=300',
                'lowpass=f=3400',
                'equalizer=f=1300:t=q:w=0.9:g=3',
                'acompressor=threshold=-24dB:ratio=4:attack=4:release=80:makeup=2',
                'acrusher=bits=13:mix=0.10:mode=log:samples=2',
                'loudnorm=I=-16:TP=-1.5:LRA=6',
                'aresample=24000',
            ],
        ],
        'haunted' => [
            'id' => 'haunted',
            'label' => 'Haunted',
            'description' => 'Darkens the voice with slow movement and a lingering double echo.',
            'exposed' => true,
            'filters' => [
                'highpass=f=90',
                'lowpass=f=10000',
                'equalizer=f=1800:t=q:w=1.0:g=-1.5',
                'aphaser=in_gain=0.65:out_gain=0.75:delay=3:decay=0.35:speed=0.35:type=sinusoidal',
                'aecho=0.85:0.88:95|190:0.16|0.07',
                'acompressor=threshold=-22dB:ratio=2.5:attack=10:release=160:makeup=1.5',
                'loudnorm=I=-17:TP=-1.5:LRA=8',
                'aresample=24000',
            ],
        ],
        'cavernous' => [
            'id' => 'cavernous',
            'label' => 'Cavernous',
            'description' => 'Adds body and a pair of long, spacious echoes.',
            'exposed' => true,
            'filters' => [
                'highpass=f=75',
                'lowpass=f=11500',
                'equalizer=f=220:t=q:w=0.9:g=1.0',
                'acompressor=threshold=-22dB:ratio=2.5:attack=10:release=180:makeup=1.5',
                'aecho=0.80:0.82:180|360:0.20|0.09',
                'loudnorm=I=-17:TP=-1.5:LRA=9',
                'aresample=24000',
            ],
        ],
        'underwater' => [
            'id' => 'underwater',
            'label' => 'Underwater',
            'description' => 'Heavily muffles the voice and adds slow, fluid movement.',
            'exposed' => true,
            'filters' => [
                'highpass=f=45',
                'lowpass=f=1500',
                'equalizer=f=280:t=q:w=0.8:g=3.0',
                'flanger=delay=2.5:depth=1.5:regen=5:width=22:speed=0.25:shape=sinusoidal:interp=quadratic',
                'acompressor=threshold=-22dB:ratio=3:attack=12:release=180:makeup=2',
                'loudnorm=I=-17:TP=-1.5:LRA=7',
                'aresample=24000',
            ],
        ],
        'quick' => [
            'id' => 'quick',
            'label' => 'Quick',
            'description' => 'Speeds up delivery slightly while preserving the original voice.',
            'exposed' => true,
            'filters' => [
                'highpass=f=70',
                'lowpass=f=15000',
                'atempo=1.12',
                'acompressor=threshold=-20dB:ratio=2:attack=8:release=100:makeup=1.5',
                'loudnorm=I=-16:TP=-1.5:LRA=8',
                'aresample=24000',
            ],
        ],
        'drawling' => [
            'id' => 'drawling',
            'label' => 'Drawling',
            'description' => 'Slows delivery slightly and adds a touch of warmth.',
            'exposed' => true,
            'filters' => [
                'highpass=f=65',
                'lowpass=f=14500',
                'equalizer=f=140:t=q:w=0.9:g=1.0',
                'atempo=0.88',
                'acompressor=threshold=-20dB:ratio=2:attack=10:release=140:makeup=1.5',
                'loudnorm=I=-16:TP=-1.5:LRA=8',
                'aresample=24000',
            ],
        ],
        'measured' => [
            'id' => 'measured',
            'label' => 'Measured',
            'description' => 'Makes delivery a little slower, steadier, and more even.',
            'exposed' => true,
            'filters' => [
                'highpass=f=75',
                'lowpass=f=14500',
                'equalizer=f=2800:t=q:w=1.0:g=0.8',
                'atempo=0.95',
                'acompressor=threshold=-21dB:ratio=2.4:attack=12:release=150:makeup=1.5',
                'loudnorm=I=-16:TP=-1.5:LRA=7',
                'aresample=24000',
            ],
        ],
        'soft_spoken' => [
            'id' => 'soft_spoken',
            'label' => 'Soft-Spoken',
            'description' => 'Softens harsh edges and lowers the voice slightly without changing its character.',
            'exposed' => true,
            'filters' => [
                'highpass=f=85',
                'lowpass=f=12000',
                'equalizer=f=3200:t=q:w=1.0:g=-1.2',
                'acompressor=threshold=-24dB:ratio=1.8:attack=18:release=180:makeup=1',
                'loudnorm=I=-19:TP=-2:LRA=9',
                'aresample=24000',
            ],
        ],
        'crisp' => [
            'id' => 'crisp',
            'label' => 'Crisp',
            'description' => 'Adds mild clarity and presence for cleaner everyday speech.',
            'exposed' => true,
            'filters' => [
                'highpass=f=85',
                'lowpass=f=15500',
                'equalizer=f=300:t=q:w=1.0:g=-1.0',
                'equalizer=f=3400:t=q:w=0.9:g=2.0',
                'acompressor=threshold=-21dB:ratio=2:attack=8:release=110:makeup=1.2',
                'loudnorm=I=-16:TP=-1.5:LRA=8',
                'aresample=24000',
            ],
        ],
        'commanding' => [
            'id' => 'commanding',
            'label' => 'Commanding',
            'description' => 'Adds restrained weight and firmness while keeping the voice natural.',
            'exposed' => true,
            'filters' => [
                'highpass=f=60',
                'lowpass=f=13500',
                'equalizer=f=120:t=q:w=0.8:g=1.8',
                'equalizer=f=2600:t=q:w=1.0:g=1.0',
                'acompressor=threshold=-22dB:ratio=3:attack=8:release=130:makeup=2',
                'loudnorm=I=-16:TP=-1.5:LRA=6',
                'aresample=24000',
            ],
        ],
        'book_reading' => [
            'id' => 'book_reading',
            'label' => 'Book reading',
            'description' => 'Internal audiobook processing used by BookReader.',
            'exposed' => false,
            'filters' => [
                'highpass=f=70',
                'lowpass=f=14500',
                'equalizer=f=120:t=q:w=0.8:g=1.5',
                'equalizer=f=320:t=q:w=1.0:g=-1.5',
                'equalizer=f=3000:t=q:w=0.9:g=2.0',
                'acompressor=threshold=-18dB:ratio=2.5:attack=8:release=120:makeup=2',
                'aecho=1.0:0.92:55:0.16',
                'atempo=0.85',
                'loudnorm=I=-16:TP=-1.5:LRA=7',
                'aresample=24000',
            ],
        ],
    ];
    }
    public static function validate(mixed $id): string
    {
        if (!is_string($id) || !isset(self::catalog()[$id]) || !self::catalog()[$id]['exposed'])
            throw new \InvalidArgumentException('invalid_tts_filter_preset');
        return $id;
    }
    public static function identity(string $id): string { return self::validate($id).':'.self::VERSION; }
}
