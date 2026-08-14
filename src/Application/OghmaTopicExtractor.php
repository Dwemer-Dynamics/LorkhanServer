<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

interface OghmaTopicExtractor
{
    /** @return list<string> */
    public function extract(string $context,int $limit,CancellationToken $cancellation):array;
}
