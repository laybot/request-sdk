<?php
declare(strict_types=1);

namespace LayBot\Request\DTO;

use LayBot\Request\Support\Json;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $url,
        public readonly float $durationMs,
        public readonly int $attempts = 1,
        public readonly string $protocolVersion = '1.1',
    ) {
    }

    public function head(): ResponseHead
    {
        return new ResponseHead(
            $this->status,
            $this->headers,
            $this->url,
            $this->protocolVersion
        );
    }

    public function header(string $name): array
    {
        return $this->head()->header($name);
    }

    public function headerLine(string $name): string
    {
        return $this->head()->headerLine($name);
    }

    public function requestId(): ?string
    {
        return $this->head()->requestId();
    }

    public function traceId(): ?string
    {
        return $this->head()->traceId();
    }

    public function jsonAny(): mixed
    {
        return Json::decodeAny($this->body);
    }

    public function jsonArray(): array
    {
        return Json::decodeArray($this->body);
    }

    public function withAttempts(int $attempts): self
    {
        return new self(
            $this->status,
            $this->headers,
            $this->body,
            $this->url,
            $this->durationMs,
            $attempts,
            $this->protocolVersion,
        );
    }

    public function toLegacyArray(): array
    {
        return [
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
        ];
    }
}
