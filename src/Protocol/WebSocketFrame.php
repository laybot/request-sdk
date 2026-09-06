<?php
declare(strict_types=1);

namespace LayBot\Request\Protocol;

final class WebSocketFrame
{
    public function __construct(
        public readonly int $opcode,
        public readonly bool $final,
        public readonly string $payload
    ) {
    }
}
