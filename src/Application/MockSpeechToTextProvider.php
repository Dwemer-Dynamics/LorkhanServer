<?php
declare(strict_types=1);
namespace LORKHANserver\Application;

use RuntimeException;

final class MockSpeechToTextProvider implements SpeechToTextProvider
{
    public function transcribe(string $bytes, string $codec, string $language, CancellationToken $cancellation): array
    {
        $cancellation->throwIfCancellationRequested();
        if ($codec !== 'wav' || strlen($bytes) < 44 || substr($bytes,0,4) !== 'RIFF' || substr($bytes,8,4) !== 'WAVE') {
            throw new RuntimeException('invalid_audio');
        }
        return ['text'=>'Deterministic mock transcript.','language'=>$language];
    }
}
