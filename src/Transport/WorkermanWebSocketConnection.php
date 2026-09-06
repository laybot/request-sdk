<?php
declare(strict_types=1);

namespace LayBot\Request\Transport;

use LayBot\Request\Contract\WebSocketConnectionInterface;
use LayBot\Request\Contract\WebSocketListenerInterface;
use LayBot\Request\DTO\WebSocketCloseInfo;
use LayBot\Request\DTO\WebSocketOpenInfo;
use LayBot\Request\DTO\WebSocketRequest;
use LayBot\Request\Enum\WebSocketState;
use LayBot\Request\Exception\BackpressureException;
use LayBot\Request\Exception\CancelledException;
use LayBot\Request\Exception\ConnectTimeoutException;
use LayBot\Request\Exception\StreamIdleTimeoutException;
use LayBot\Request\Exception\WebSocketException;
use LayBot\Request\Exception\WebSocketHandshakeException;
use LayBot\Request\Exception\WebSocketProtocolException;
use LayBot\Request\Protocol\WebSocketFrame;
use LayBot\Request\Protocol\WebSocketHandshake;
use LayBot\Request\Protocol\WebSocketOutboundFrame;
use LayBot\Request\Support\Header;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

final class WorkermanWebSocketConnection implements
    WebSocketConnectionInterface
{
    private WebSocketState $state = WebSocketState::CONNECTING;

    private bool $errorEmitted = false;

    private bool $closeEmitted = false;

    private bool $bufferFull = false;

    private bool $remoteCloseReceived = false;

    private ?int $fragmentOpcode = null;

    private string $fragmentBuffer = '';

    private ?int $connectTimer = null;

    private ?int $idleTimer = null;

    private ?int $pingTimer = null;

    private ?int $closeTimer = null;

    private ?int $bufferWatchTimer = null;

    private ?WebSocketCloseInfo $closeInfo = null;

    public function __construct(
        private readonly AsyncTcpConnection $native,
        private readonly WebSocketRequest $request,
        private readonly WebSocketListenerInterface $listener
    ) {
        $this->bind();
    }

    public function connect(): void
    {
        $this->connectTimer = Timer::delay(
            $this->request->connectTimeout,
            function (): void {
                $this->fail(new ConnectTimeoutException(
                    'WebSocket connection timeout',
                    retryable: true
                ));
            }
        );

        $this->native->connect();
    }

    public function state(): WebSocketState
    {
        return $this->state;
    }

    public function isOpen(): bool
    {
        return $this->state === WebSocketState::OPEN;
    }

    public function bufferedBytes(): int
    {
        return $this->native->getSendBufferQueueSize();
    }

    public function sendText(string $payload): bool
    {
        if (preg_match('//u', $payload) !== 1) {
            throw new WebSocketProtocolException(
                'WebSocket text payload must be valid UTF-8'
            );
        }

        return $this->send(0x1, $payload);
    }

    public function sendBinary(string $payload): bool
    {
        return $this->send(0x2, $payload);
    }

    public function ping(string $payload = ''): bool
    {
        if (strlen($payload) > 125) {
            throw new WebSocketProtocolException(
                'WebSocket ping payload cannot exceed 125 bytes'
            );
        }

        return $this->send(0x9, $payload);
    }

    /**
     * 发起标准 WebSocket Close 握手。
     *
     * 此处只发送 Close 帧，不立即关闭 TCP；等待对端返回 Close，
     * 或等待 closeTimeout 后强制关闭。
     */
    public function close(
        int $code = 1000,
        string $reason = ''
    ): void {
        if (
            $this->state === WebSocketState::CLOSING
            || $this->state === WebSocketState::CLOSED
        ) {
            return;
        }

        $this->assertCloseCode($code);

        if (
            strlen($reason) > 123
            || preg_match('//u', $reason) !== 1
        ) {
            throw new WebSocketProtocolException(
                'invalid WebSocket close reason'
            );
        }

        if (!$this->isOpen()) {
            $this->native->destroy();
            return;
        }

        $this->state = WebSocketState::CLOSING;

        $this->closeInfo = new WebSocketCloseInfo(
            code: $code,
            reason: $reason,
            remote: false,
            clean: false
        );

        $this->clearActivityTimers();

        /*
         * 先创建超时 Timer，再发送 Close，避免极端情况下响应同步到达
         * 导致 Timer 在连接关闭后才被创建。
         */
        $this->closeTimer = Timer::delay(
            $this->request->closeTimeout,
            function (): void {
                $this->native->destroy();
            }
        );

        $result = $this->native->send(
            new WebSocketOutboundFrame(
                0x8,
                pack('n', $code) . $reason
            )
        );

        if ($result === false) {
            $this->fail(new WebSocketException(
                'failed to send WebSocket close frame'
            ));
        }
    }

    public function cancel(?string $reason = null): void
    {
        if ($this->state === WebSocketState::CLOSED) {
            return;
        }

        $this->closeInfo = new WebSocketCloseInfo(
            1006,
            $reason ?: 'cancelled',
            false,
            false
        );

        $this->emitError(new CancelledException(
            $reason ?: 'WebSocket connection cancelled'
        ));

        $this->native->destroy();
    }

    private function bind(): void
    {
        $this->native->onMessage = function (
            AsyncTcpConnection $connection,
            mixed $message
        ): void {
            try {
                if ($message instanceof WebSocketHandshake) {
                    $this->handleHandshake($message);
                    return;
                }

                if ($message instanceof WebSocketFrame) {
                    $this->touchIdle();
                    $this->handleFrame($message);
                }
            } catch (\Throwable $error) {
                $this->fail($error);
            }
        };

        $this->native->onError = function (
            AsyncTcpConnection $connection,
            int $code,
            mixed $message
        ): void {
            $this->fail(new WebSocketException(
                'WebSocket transport error: ' . (string)$message,
                code: $code
            ));
        };

        $this->native->onClose = function (): void {
            $this->clearAllTimers();
            $this->state = WebSocketState::CLOSED;

            $protocolError =
                $this->native->context->laybotWsProtocolError
                ?? null;

            if ($protocolError !== null) {
                $this->emitError(new WebSocketProtocolException(
                    (string)$protocolError
                ));
            }

            $info = $this->closeInfo
                ?? new WebSocketCloseInfo(
                    1006,
                    'connection closed without close frame',
                    true,
                    false
                );

            if ($this->remoteCloseReceived) {
                $info = new WebSocketCloseInfo(
                    $info->code,
                    $info->reason,
                    true,
                    true
                );
            }

            $this->emitClose($info);
        };

        $this->native->onBufferFull = function (): void {
            $this->enterBackpressure();
        };

        $this->native->onBufferDrain = function (): void {
            $this->checkBufferDrain();
        };
    }

    private function handleHandshake(
        WebSocketHandshake $handshake
    ): void {
        if (!$handshake->valid) {
            throw new WebSocketHandshakeException(
                $handshake->error ?: 'WebSocket handshake failed',
                code: $handshake->status
            );
        }

        if ($this->connectTimer !== null) {
            Timer::del($this->connectTimer);
            $this->connectTimer = null;
        }

        $this->state = WebSocketState::OPEN;
        $this->touchIdle();

        if ($this->request->pingInterval > 0) {
            $this->pingTimer = Timer::repeat(
                $this->request->pingInterval,
                function (): void {
                    if ($this->isOpen()) {
                        $this->ping();
                    }
                }
            );
        }

        $this->listener->onOpen(
            $this,
            new WebSocketOpenInfo(
                status: $handshake->status,
                headers: Header::normalize($handshake->headers),
                url: $this->request->url,
                subProtocol: Header::line(
                    $handshake->headers,
                    'Sec-WebSocket-Protocol'
                ) ?: null
            )
        );
    }

    private function handleFrame(WebSocketFrame $frame): void
    {
        switch ($frame->opcode) {
            case 0x0:
                $this->handleContinuation($frame);
                return;

            case 0x1:
            case 0x2:
                $this->handleDataFrame($frame);
                return;

            case 0x8:
                $this->handleRemoteClose($frame->payload);
                return;

            case 0x9:
                $this->listener->onPing($this, $frame->payload);

                if ($this->state === WebSocketState::OPEN) {
                    $this->send(0xA, $frame->payload);
                }

                return;

            case 0xA:
                $this->listener->onPong($this, $frame->payload);
                return;
        }
    }

    private function handleDataFrame(WebSocketFrame $frame): void
    {
        if ($this->state !== WebSocketState::OPEN) {
            throw new WebSocketProtocolException(
                'received data frame while connection is closing'
            );
        }

        if ($this->fragmentOpcode !== null) {
            throw new WebSocketProtocolException(
                'received a new data frame before fragmented message completed'
            );
        }

        if ($frame->final) {
            $this->dispatchMessage(
                $frame->opcode,
                $frame->payload
            );
            return;
        }

        $this->fragmentOpcode = $frame->opcode;
        $this->fragmentBuffer = $frame->payload;
        $this->assertMessageSize();
    }

    private function handleContinuation(WebSocketFrame $frame): void
    {
        if ($this->fragmentOpcode === null) {
            throw new WebSocketProtocolException(
                'unexpected WebSocket continuation frame'
            );
        }

        $this->fragmentBuffer .= $frame->payload;
        $this->assertMessageSize();

        if (!$frame->final) {
            return;
        }

        $opcode = $this->fragmentOpcode;
        $payload = $this->fragmentBuffer;

        $this->fragmentOpcode = null;
        $this->fragmentBuffer = '';

        $this->dispatchMessage($opcode, $payload);
    }

    private function dispatchMessage(
        int $opcode,
        string $payload
    ): void {
        if (strlen($payload) > $this->request->maxMessageBytes) {
            throw new WebSocketProtocolException(
                'WebSocket message exceeds configured size limit'
            );
        }

        if ($opcode === 0x1) {
            if (preg_match('//u', $payload) !== 1) {
                throw new WebSocketProtocolException(
                    'received invalid UTF-8 text frame'
                );
            }

            $this->listener->onText($this, $payload);
            return;
        }

        $this->listener->onBinary($this, $payload);
    }

    private function handleRemoteClose(string $payload): void
    {
        if (strlen($payload) === 1) {
            throw new WebSocketProtocolException(
                'invalid one-byte WebSocket close payload'
            );
        }

        $code = 1000;
        $reason = '';

        if (strlen($payload) >= 2) {
            $code = unpack(
                'ncode',
                substr($payload, 0, 2)
            )['code'];

            $this->assertCloseCode($code);

            $reason = substr($payload, 2);

            if (
                $reason !== ''
                && preg_match('//u', $reason) !== 1
            ) {
                throw new WebSocketProtocolException(
                    'invalid UTF-8 WebSocket close reason'
                );
            }
        }

        $this->remoteCloseReceived = true;

        $this->closeInfo = new WebSocketCloseInfo(
            code: $code,
            reason: $reason,
            remote: true,
            clean: true
        );

        $wasClosing =
            $this->state === WebSocketState::CLOSING;

        $this->state = WebSocketState::CLOSING;
        $this->clearActivityTimers();

        if ($wasClosing) {
            /*
             * 本地 Close 已经发送，对端确认后可以关闭 TCP。
             */
            $this->native->destroy();
            return;
        }

        /*
         * 对端先发起 Close，需要回复同样的 Close 帧后关闭连接。
         * Workerman close($data) 会尝试发送完数据后销毁连接。
         */
        $this->native->close(
            new WebSocketOutboundFrame(
                0x8,
                $payload === ''
                    ? pack('n', 1000)
                    : $payload
            )
        );
    }

    private function send(int $opcode, string $payload): bool
    {
        if (!$this->isOpen()) {
            throw new WebSocketException(
                'WebSocket connection is not open'
            );
        }

        $predicted =
            $this->bufferedBytes() + strlen($payload) + 14;

        if ($predicted > $this->request->hardBufferLimit) {
            $error = new BackpressureException(
                'WebSocket send buffer hard limit exceeded'
            );

            $this->fail($error);
            throw $error;
        }

        if ($predicted >= $this->request->highWaterMark) {
            $this->enterBackpressure();
        }

        $result = $this->native->send(
            new WebSocketOutboundFrame($opcode, $payload)
        );

        if ($result === false) {
            throw new BackpressureException(
                'WebSocket send failed or buffer is full'
            );
        }

        return true;
    }

    private function enterBackpressure(): void
    {
        if (!$this->bufferFull) {
            $this->bufferFull = true;
            $this->listener->onBufferFull($this);
        }

        if ($this->bufferWatchTimer !== null) {
            return;
        }

        /*
         * Workerman 原生 onBufferDrain 只在缓冲清空时触发。
         * 这里轮询低水位，使恢复生产不必等待缓冲完全归零。
         */
        $this->bufferWatchTimer = Timer::repeat(
            0.02,
            function (): void {
                $this->checkBufferDrain();
            }
        );
    }

    private function checkBufferDrain(): void
    {
        if (
            !$this->bufferFull
            || $this->bufferedBytes()
            > $this->request->lowWaterMark
        ) {
            return;
        }

        $this->bufferFull = false;

        if ($this->bufferWatchTimer !== null) {
            Timer::del($this->bufferWatchTimer);
            $this->bufferWatchTimer = null;
        }

        $this->listener->onBufferDrain($this);
    }

    private function touchIdle(): void
    {
        if ($this->request->idleTimeout <= 0) {
            return;
        }

        if ($this->idleTimer !== null) {
            Timer::del($this->idleTimer);
        }

        $this->idleTimer = Timer::delay(
            $this->request->idleTimeout,
            function (): void {
                $this->fail(new StreamIdleTimeoutException(
                    'WebSocket idle timeout',
                    retryable: true
                ));
            }
        );
    }

    private function assertMessageSize(): void
    {
        if (
            strlen($this->fragmentBuffer)
            > $this->request->maxMessageBytes
        ) {
            throw new WebSocketProtocolException(
                'fragmented WebSocket message exceeds size limit'
            );
        }
    }

    private function assertCloseCode(int $code): void
    {
        $valid = $code >= 1000
            && $code <= 4999
            && !in_array(
                $code,
                [1004, 1005, 1006, 1015],
                true
            );

        if (!$valid) {
            throw new WebSocketProtocolException(
                "invalid WebSocket close code: {$code}"
            );
        }
    }

    private function fail(\Throwable $error): void
    {
        if ($this->state === WebSocketState::CLOSED) {
            return;
        }

        $this->emitError($error);
        $this->native->destroy();
    }

    private function emitError(\Throwable $error): void
    {
        if ($this->errorEmitted) {
            return;
        }

        $this->errorEmitted = true;

        try {
            $this->listener->onError($this, $error);
        } catch (\Throwable) {
        }
    }

    private function emitClose(WebSocketCloseInfo $info): void
    {
        if ($this->closeEmitted) {
            return;
        }

        $this->closeEmitted = true;

        try {
            $this->listener->onClose($this, $info);
        } catch (\Throwable) {
        }
    }

    private function clearActivityTimers(): void
    {
        foreach (
            [
                $this->connectTimer,
                $this->idleTimer,
                $this->pingTimer,
                $this->bufferWatchTimer,
            ] as $timer
        ) {
            if ($timer !== null) {
                Timer::del($timer);
            }
        }

        $this->connectTimer = null;
        $this->idleTimer = null;
        $this->pingTimer = null;
        $this->bufferWatchTimer = null;
    }

    private function clearAllTimers(): void
    {
        $this->clearActivityTimers();

        if ($this->closeTimer !== null) {
            Timer::del($this->closeTimer);
            $this->closeTimer = null;
        }
    }
}
