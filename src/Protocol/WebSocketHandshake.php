<?php
declare(strict_types=1);

namespace LayBot\Request\Protocol;

final class WebSocketHandshake
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly bool $valid,
        public readonly ?string $error = null,
    ) {
    }
}
