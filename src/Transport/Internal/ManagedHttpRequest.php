<?php
declare(strict_types=1);

namespace LayBot\Request\Transport\Internal;

use LayBot\Request\Exception\ResponseTooLargeException;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Http\ConnectionPool;
use Workerman\Http\Request;
use Workerman\Http\Response;

/**
 * 可管理的 workerman/http-client Request。
 *
 * 改进：
 * - 流式和 sink 模式不累计完整响应正文；
 * - 支持安全取消；
 * - 支持连接池归还或销毁；
 * - 支持响应总字节数限制。
 */
final class ManagedHttpRequest extends Request
{
    private int $receivedBytes = 0;

    /** @var null|\Closure():void */
    private ?\Closure $connectedCallback = null;

    public function __construct(
        string $url,
        private readonly bool $storeResponseBody,
        private readonly int $maxResponseBytes
    ) {
        parent::__construct($url);
    }

    public function onConnected(callable $callback): void
    {
        $this->connectedCallback = \Closure::fromCallable($callback);
    }

    public function onConnect(): void
    {
        if ($this->connectedCallback !== null) {
            ($this->connectedCallback)();
        }

        parent::onConnect();
    }

    public function writeToResponse($buffer): void
    {
        $buffer = (string)$buffer;
        $this->receivedBytes += strlen($buffer);

        if ($this->receivedBytes > $this->maxResponseBytes) {
            throw new ResponseTooLargeException(
                "response exceeds {$this->maxResponseBytes} bytes"
            );
        }

        $this->emit('progress', $buffer);

        if ($this->storeResponseBody) {
            $this->response->getBody()->write($buffer);
        }
    }

    public function handleData($connection, $data): void
    {
        try {
            $this->writeToResponse((string)$data);

            if (
                $this->expectedLength > 0
                && $this->receivedBytes >= $this->expectedLength
            ) {
                $this->emitSuccess();
            }
        } catch (\Throwable $error) {
            $this->emitError($error);
        }
    }

    public function responseObject(): ?Response
    {
        return $this->response;
    }

    public function receivedBytes(): int
    {
        return $this->receivedBytes;
    }

    /**
     * 请求正常完成时释放连接。
     *
     * 完整消费响应后，池连接才允许复用。
     */
    public function release(
        ?ConnectionPool $pool,
        bool $pooled,
        bool $healthy
    ): void {
        $connection = $this->getConnection();

        $this->removeAllListeners();

        if (!$connection instanceof AsyncTcpConnection) {
            return;
        }

        $connection->onConnect = null;
        $connection->onMessage = null;
        $connection->onError = null;
        $connection->onClose = null;
        $connection->onBufferFull = null;
        $connection->onBufferDrain = null;

        if ($pooled && $pool !== null) {
            $this->detachConnection();

            if ($healthy) {
                $pool->recycle($connection);
                return;
            }

            $pool->delete($connection);
            $connection->destroy();
            return;
        }

        /*
         * 非池连接由 Request 自己创建，detachConnection() 会负责关闭。
         */
        $this->detachConnection();
    }

    /**
     * 请求取消、提前停止或失败时销毁连接。
     *
     * 因为响应可能尚未消费完，所以绝不能将该连接放回池中。
     */
    public function abort(
        ?ConnectionPool $pool = null,
        bool $pooled = false
    ): void {
        $connection = $this->getConnection();

        $this->removeAllListeners();

        if (!$connection instanceof AsyncTcpConnection) {
            return;
        }

        $connection->onConnect = null;
        $connection->onMessage = null;
        $connection->onError = null;
        $connection->onClose = null;
        $connection->onBufferFull = null;
        $connection->onBufferDrain = null;

        if ($pooled && $pool !== null) {
            $pool->delete($connection);
        }

        $connection->destroy();
    }
}
