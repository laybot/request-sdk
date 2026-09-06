<?php
declare(strict_types=1);

namespace LayBot\Request\Contract;

use LayBot\Request\Enum\WebSocketState;

interface WebSocketConnectionInterface
{
    public function sendText(string $payload): bool;

    public function sendBinary(string $payload): bool;

    public function ping(string $payload = ''): bool;

    public function close(
        int $code = 1000,
        string $reason = ''
    ): void;

    public function cancel(?string $reason = null): void;

    public function state(): WebSocketState;

    public function isOpen(): bool;

    public function bufferedBytes(): int;
}
