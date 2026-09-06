<?php
declare(strict_types=1);

namespace LayBot\Request\DTO;

final class JsonLine
{
    public function __construct(
        public readonly int $number,
        public readonly mixed $value,
        public readonly string $raw,
    ) {
    }
}
