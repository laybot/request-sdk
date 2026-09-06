<?php
declare(strict_types=1);

namespace LayBot\Request\Enum;

enum AsyncState: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isSettled(): bool
    {
        return match ($this) {
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED => true,
            default => false,
        };
    }
}
