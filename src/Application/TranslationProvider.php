<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

interface TranslationProvider
{
    /** @param list<string> $texts @return list<string> */
    public function translate(array $texts,string $sourceLanguage,string $targetLanguage,CancellationToken $token):array;
}
