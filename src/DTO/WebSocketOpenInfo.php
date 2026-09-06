<?php
declare(strict_types=1);

namespace LayBot\Request\DTO;

final class WebSocketOpenInfo
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $url,
        public readonly ?string $subProtocol = null,
    ) {
    }

    public function requestId(): ?string
    {
        return (new ResponseHead(
            $this->status,
            $this->headers,
            $this->url
        ))->requestId();
    }
}
