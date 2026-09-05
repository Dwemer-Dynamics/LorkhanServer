<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

interface SpeechToTextProvider
{
    /** @return array{text:string,language:string} */
    public function transcribe(string $bytes, string $codec, string $language, CancellationToken $cancellation): array;
}
