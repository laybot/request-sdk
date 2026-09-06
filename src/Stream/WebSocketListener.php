<?php
declare(strict_types=1);

namespace LayBot\Request\Stream;

use LayBot\Request\Contract\WebSocketConnectionInterface;
use LayBot\Request\Contract\WebSocketListenerInterface;
use LayBot\Request\DTO\WebSocketCloseInfo;
use LayBot\Request\DTO\WebSocketOpenInfo;

/**
 * 可按需覆写方法的空 Listener。
 */
class WebSocketListener implements WebSocketListenerInterface
{
    public function onOpen(
        WebSocketConnectionInterface $connection,
        WebSocketOpenInfo $info
    ): void {
    }

    public function onText(
        WebSocketConnectionInterface $connection,
        string $payload
    ): void {
    }

    public function onBinary(
        WebSocketConnectionInterface $connection,
        string $payload
    ): void {
    }

    public function onPing(
        WebSocketConnectionInterface $connection,
        string $payload
    ): void {
    }

    public function onPong(
        WebSocketConnectionInterface $connection,
        string $payload
    ): void {
    }

    public function onBufferFull(
        WebSocketConnectionInterface $connection
    ): void {
    }

    public function onBufferDrain(
        WebSocketConnectionInterface $connection
    ): void {
    }

    public function onClose(
        WebSocketConnectionInterface $connection,
        WebSocketCloseInfo $info
    ): void {
    }

    public function onError(
        WebSocketConnectionInterface $connection,
        \Throwable $error
    ): void {
    }
}
