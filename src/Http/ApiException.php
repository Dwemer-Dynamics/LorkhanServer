<?php
declare(strict_types=1);

namespace ALMSIVIserver\Http;

final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly bool $retrySafe = false,
        public readonly ?int $retryAfterMs = null,
    ) {
        parent::__construct($message);
    }
}
