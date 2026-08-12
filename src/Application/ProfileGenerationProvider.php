<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

interface ProfileGenerationProvider
{
    /** @return array<string,string> */
    public function generate(array $profile, CancellationToken $cancellation): array;
}
