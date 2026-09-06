<?php
declare(strict_types=1);

namespace LayBot\Request\Contract;

use LayBot\Request\Enum\AsyncState;

interface AsyncHandleInterface
{
    public function cancel(?string $reason = null): void;

    public function state(): AsyncState;

    public function isSettled(): bool;
}
