<?php
declare(strict_types=1);

namespace LayBot\Request\Contract;

use LayBot\Request\DTO\JsonLine;
use LayBot\Request\DTO\Response;
use LayBot\Request\DTO\ResponseHead;
use LayBot\Request\DTO\SseEvent;
use LayBot\Request\DTO\StreamResult;
use LayBot\Request\Stream\AsyncRequestHandle;
use LayBot\Request\DTO\WebSocketRequest;
use LayBot\Request\Contract\WebSocketConnectionInterface;
use LayBot\Request\Contract\WebSocketListenerInterface;

interface ClientInterface
{
    public function request(
        string $method,
        string $path,
        array $options = []
    ): Response;

    public function requestAsync(
        string $method,
        string $path,
        array $options,
        callable $onComplete,
        callable $onError
    ): AsyncRequestHandle;

    public function streamRaw(
        string $method,
        string $path,
        array $options,
        callable $onChunk
    ): StreamResult;

    public function streamRawAsync(
        string $method,
        string $path,
        array $options,
        callable $onOpen,
        callable $onChunk,
        callable $onComplete,
        callable $onError
    ): AsyncRequestHandle;

    public function streamSse(
        string $method,
        string $path,
        array $options,
        callable $onEvent,
        ?string $doneToken = null
    ): StreamResult;

    public function streamSseAsync(
        string $method,
        string $path,
        array $options,
        callable $onOpen,
        callable $onEvent,
        callable $onComplete,
        callable $onError,
        ?string $doneToken = null
    ): AsyncRequestHandle;

    public function streamJsonLines(
        string $method,
        string $path,
        array $options,
        callable $onLine
    ): StreamResult;

    public function streamJsonLinesAsync(
        string $method,
        string $path,
        array $options,
        callable $onOpen,
        callable $onLine,
        callable $onComplete,
        callable $onError
    ): AsyncRequestHandle;

    public function connectWebSocketAsync(
        WebSocketRequest $request,
        WebSocketListenerInterface $listener
    ): WebSocketConnectionInterface;
}
