<?php
declare(strict_types=1);

namespace LayBot\Request\DTO;

use LayBot\Request\Support\Header;

final class ResponseHead
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $url,
        public readonly string $protocolVersion = '1.1',
    ) {
    }

    public function header(string $name): array
    {
        return Header::values($this->headers, $name);
    }

    public function headerLine(string $name): string
    {
        return Header::line($this->headers, $name);
    }

    public function requestId(): ?string
    {
        return Header::first($this->headers, [
            'x-request-id',
            'x-api-request-id',
            'x-tt-logid',
            'request-id',
        ]);
    }

    public function traceId(): ?string
    {
        return Header::first($this->headers, [
            'trace-id',
            'x-trace-id',
            'x-b3-traceid',
            'x-tt-trace-id',
        ]);
    }
}
