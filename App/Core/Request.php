<?php

declare(strict_types=1);

namespace App\Core;

class Request
{
    public function __construct(
        public array $query = [],
        public array $request = [],
        public array $server = [],
        public array $json = []
    ) {
    }

    public static function fromGlobals(): self
    {
        $json = [];

        $raw = file_get_contents('php://input');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return new self(
            $_GET,
            $_REQUEST,
            $_SERVER,
            $json
        );
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';

        $path = parse_url($uri, PHP_URL_PATH);

        return rtrim($path ?: '/', '/');
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request[$key]
            ?? $this->json[$key]
            ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->request[$key]) || isset($this->query[$key]) || isset($this->json[$key]);
    }

    public function all(): array
    {
        return array_merge(
            $this->query,
            $this->request,
            $this->json
        );
    }
}