<?php
declare(strict_types=1);

namespace LayBot\Request\Protocol;

final class WebSocketOutboundFrame
{
    public function __construct(
        public readonly int $opcode,
        public readonly string $payload = ''
    ) {
    }
}
