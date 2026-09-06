<?php
declare(strict_types=1);

namespace LayBot\Request\Contract;

use LayBot\Request\DTO\WebSocketRequest;

interface WebSocketConnectorInterface
{
    public function connectAsync(
        WebSocketRequest $request,
        WebSocketListenerInterface $listener
    ): WebSocketConnectionInterface;
}
