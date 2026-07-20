<?php
declare(strict_types=1);

namespace ALMSIVIserver\Application;

interface Provider
{
    /**
     * Return one to four final utterances. Legacy text/action output remains accepted by the
     * orchestrator while provider implementations migrate to the utterance envelope.
     *
     * @return array<string,mixed>
     */
    public function complete(array $turn, CancellationToken $cancellation): array;
}
