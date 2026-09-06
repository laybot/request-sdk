<?php
declare(strict_types=1);

namespace LayBot\Request\DTO;

final class SigningContext
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly string $scheme,
        public readonly string $host,
        public readonly ?int $port,
        public readonly string $path,
        public readonly string $canonicalQuery,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    public function bodySha256(): string
    {
        return hash('sha256', $this->body);
    }
}
