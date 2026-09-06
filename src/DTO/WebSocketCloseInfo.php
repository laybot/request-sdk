<?php
declare(strict_types=1);

namespace LayBot\Request\DTO;

final class WebSocketCloseInfo
{
    public function __construct(
        public readonly int $code = 1006,
        public readonly string $reason = '',
        public readonly bool $remote = false,
        public readonly bool $clean = false,
    ) {
    }
}
