<?php
declare(strict_types=1);

namespace LayBot\Request\DTO;

use LayBot\Request\Enum\StreamTermination;

final class StreamResult
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $url,
        public readonly StreamTermination $termination,
        public readonly int $bytesReceived,
        public readonly float $durationMs,
        public readonly int $itemsReceived = 0,
    ) {
    }

    public function head(): ResponseHead
    {
        return new ResponseHead(
            $this->status,
            $this->headers,
            $this->url
        );
    }

    public function requestId(): ?string
    {
        return $this->head()->requestId();
    }

    public function traceId(): ?string
    {
        return $this->head()->traceId();
    }

    public function withTermination(StreamTermination $termination): self
    {
        return new self(
            $this->status,
            $this->headers,
            $this->url,
            $termination,
            $this->bytesReceived,
            $this->durationMs,
            $this->itemsReceived,
        );
    }

    public function withItemsReceived(int $items): self
    {
        return new self(
            $this->status,
            $this->headers,
            $this->url,
            $this->termination,
            $this->bytesReceived,
            $this->durationMs,
            $items,
        );
    }
}
