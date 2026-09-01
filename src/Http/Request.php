<?php

declare(strict_types=1);

namespace LorkhanServer\Http;

final readonly class Request
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @param array<string, mixed> $form
     * @param array<string, array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers = [],
        public array $query = [],
        public string $body = '',
        public array $form = [],
        public array $files = [],
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $normalized = [];
        foreach ($headers as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $normalized[$key] = $value;
            }
        }
        /** @var array<string, string> $query */
        $query = array_filter($_GET, 'is_string');
        $form = [];
        foreach ($_POST as $key => $value) {
            if (is_string($key) && (is_string($value) || is_array($value))) $form[$key] = $value;
        }
        $files = [];
        foreach ($_FILES as $key => $value) {
            if (!is_string($key) || !is_array($value)) continue;
            $name = $value['name'] ?? null;
            $type = $value['type'] ?? null;
            $temporary = $value['tmp_name'] ?? null;
            $error = $value['error'] ?? null;
            $size = $value['size'] ?? null;
            if (is_string($name) && is_string($type) && is_string($temporary) && is_int($error) && is_int($size)) {
                $files[$key] = ['name'=>$name,'type'=>$type,'tmp_name'=>$temporary,'error'=>$error,'size'=>$size];
            }
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $normalized,
            $query,
            file_get_contents('php://input') ?: '',
            $form,
            $files,
        );
    }
}
