<?php
declare(strict_types=1);

namespace LayBot\Request\Contract;

use LayBot\Request\DTO\PreparedRequest;
use LayBot\Request\DTO\Response;
use LayBot\Request\DTO\ResponseHead;
use LayBot\Request\DTO\StreamResult;
use LayBot\Request\Stream\AsyncRequestHandle;

interface AsyncHttpTransportInterface
{
    /**
     * @param callable(Response):void $onComplete
     * @param callable(\Throwable):void $onError
     */
    public function requestAsync(
        PreparedRequest $request,
        callable $onComplete,
        callable $onError
    ): AsyncRequestHandle;

    /**
     * onChunk 返回 false 表示正常提前结束流。
     *
     * @param callable(ResponseHead):void $onOpen
     * @param callable(string):(bool|null) $onChunk
     * @param callable(StreamResult):void $onComplete
     * @param callable(\Throwable):void $onError
     */
    public function streamAsync(
        PreparedRequest $request,
        callable $onOpen,
        callable $onChunk,
        callable $onComplete,
        callable $onError
    ): AsyncRequestHandle;
}
