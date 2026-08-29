<?php
declare(strict_types=1);

namespace LORKHANserver\Application;

interface SpeechProvider
{
    /** @return array{bytes:string,codec:string,mime_type:string,duration_ms:int} */
    public function synthesize(string $text, CancellationToken $cancellation, array $context = []): array;
}
