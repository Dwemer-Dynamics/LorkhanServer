<?php

declare(strict_types=1);

namespace LorkhanServer\Http;

final readonly class Response
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = ['Content-Type' => 'application/json; charset=utf-8'],
    ) {
    }

    /** @param array<string, mixed> $value */
    public static function json(int $status, array $value): self
    {
        return new self($status, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function error(int $status, string $code, string $correlationId, bool $retriable = false, ?int $retryAfterMs = null): self
    {
        $body = [
            'schema' => 'lorkhan.error.v1',
            'code' => $code,
            'message' => 'Request rejected',
            'correlation_id' => $correlationId,
            'retriable' => $retriable,
        ];
        if ($retryAfterMs !== null) {
            $body['retry_after_ms'] = $retryAfterMs;
        }
        return self::json($status, $body);
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $headerValue) header($name . ': ' . $headerValue, false);
        }
        header('Content-Length: ' . strlen($this->body));
        header('Cache-Control: no-store');
        echo $this->body;
    }
}
