<?php
declare(strict_types=1);

namespace LayBot\Request\Contract;

use LayBot\Request\DTO\PreparedRequest;
use LayBot\Request\DTO\Response;
use LayBot\Request\DTO\StreamResult;

interface TransportInterface
{
    public function request(PreparedRequest $request): Response;

    /**
     * 同步阻塞式原始响应流。
     *
     * onChunk 返回 false 表示正常提前终止读取。
     *
     * @param callable(string):(bool|null) $onChunk
     */
    public function stream(
        PreparedRequest $request,
        callable $onChunk
    ): StreamResult;
}
