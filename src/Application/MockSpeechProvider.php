<?php
declare(strict_types=1);

namespace ALMSIVIserver\Application;

final class MockSpeechProvider implements SpeechProvider
{
    public function synthesize(string $text, CancellationToken $cancellation): array
    {
        $cancellation->throwIfCancellationRequested();
        // A deterministic, legal PCM WAV containing 20 ms of silence at 8 kHz, mono, 8-bit.
        $samples = str_repeat("\x80", 160);
        $rate = 8000;
        $channels = 1;
        $bits = 8;
        $blockAlign = intdiv($channels * $bits, 8);
        $byteRate = $rate * $blockAlign;
        $header = 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt ' . pack('VvvVVvv',
            16, 1, $channels, $rate, $byteRate, $blockAlign, $bits) . 'data' . pack('V', strlen($samples));
        $cancellation->throwIfCancellationRequested();
        return ['bytes' => $header . $samples, 'codec' => 'wav', 'mime_type' => 'audio/wav', 'duration_ms' => 20];
    }
}
