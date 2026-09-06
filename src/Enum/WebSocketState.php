<?php
declare(strict_types=1);

namespace LayBot\Request\Enum;

enum WebSocketState: string
{
    case CONNECTING = 'connecting';
    case OPEN = 'open';
    case CLOSING = 'closing';
    case CLOSED = 'closed';
}
