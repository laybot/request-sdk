<?php
declare(strict_types=1);

namespace LayBot\Request\DTO;

final class WebSocketRequest
{
    public function __construct(
        public readonly string $url,
        public readonly array $headers = [],
        public readonly bool $verify = true,
        public readonly float $connectTimeout = 10.0,
        public readonly float $idleTimeout = 180.0,
        public readonly float $pingInterval = 30.0,
        public readonly float $closeTimeout = 5.0,
        public readonly int $maxMessageBytes = 16_777_216,
        public readonly int $lowWaterMark = 1_048_576,
        public readonly int $highWaterMark = 4_194_304,
        public readonly int $hardBufferLimit = 16_777_216,
        public readonly ?string $origin = null,
        public readonly ?string $subProtocol = null,
        public readonly ?string $proxy = null,

        /**
         * 是否允许 URL 与 Client base_uri 不同源。
         */
        public readonly bool $allowCrossOrigin = false,

        /**
         * 跨 Origin 时是否继续携带 Client 全局凭证和执行 Signer。
         */
        public readonly bool $forwardCrossOriginCredentials = false,
    ) {
        if (!preg_match('#^wss?://#i', $this->url)) {
            throw new \InvalidArgumentException(
                'WebSocket URL must use ws or wss'
            );
        }

        if (
            $this->connectTimeout <= 0
            || $this->idleTimeout < 0
            || $this->pingInterval < 0
            || $this->closeTimeout <= 0
        ) {
            throw new \InvalidArgumentException(
                'invalid WebSocket timeout configuration'
            );
        }

        if (
            $this->lowWaterMark < 0
            || $this->highWaterMark < 1
            || $this->lowWaterMark >= $this->highWaterMark
            || $this->hardBufferLimit < $this->highWaterMark
        ) {
            throw new \InvalidArgumentException(
                'invalid WebSocket buffer limits'
            );
        }

        if ($this->maxMessageBytes < 1) {
            throw new \InvalidArgumentException(
                'maxMessageBytes must be greater than zero'
            );
        }

        if (
            $this->origin !== null
            && strpbrk($this->origin, "\r\n") !== false
        ) {
            throw new \InvalidArgumentException(
                'invalid WebSocket Origin'
            );
        }

        if (
            $this->subProtocol !== null
            && !preg_match(
                '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/',
                $this->subProtocol
            )
        ) {
            throw new \InvalidArgumentException(
                'invalid WebSocket subprotocol'
            );
        }
    }

    public function withHeaders(array $headers): self
    {
        return new self(
            url: $this->url,
            headers: $headers,
            verify: $this->verify,
            connectTimeout: $this->connectTimeout,
            idleTimeout: $this->idleTimeout,
            pingInterval: $this->pingInterval,
            closeTimeout: $this->closeTimeout,
            maxMessageBytes: $this->maxMessageBytes,
            lowWaterMark: $this->lowWaterMark,
            highWaterMark: $this->highWaterMark,
            hardBufferLimit: $this->hardBufferLimit,
            origin: $this->origin,
            subProtocol: $this->subProtocol,
            proxy: $this->proxy,
            allowCrossOrigin: $this->allowCrossOrigin,
            forwardCrossOriginCredentials:
            $this->forwardCrossOriginCredentials,
        );
    }
}
