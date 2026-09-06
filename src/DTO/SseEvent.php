<?php
declare(strict_types=1);

namespace LayBot\Request\DTO;

final class SseEvent
{
    public function __construct(
        public readonly string $data,
        public readonly ?string $event = null,
        public readonly ?string $id = null,
        public readonly ?int $retry = null,
        public readonly bool $comment = false,
    ) {
    }
}
