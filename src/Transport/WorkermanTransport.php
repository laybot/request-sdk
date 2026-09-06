<?php
declare(strict_types=1);

namespace LayBot\Request\Transport;

use LayBot\Request\Contract\AsyncHttpTransportInterface;
use LayBot\Request\DTO\PreparedRequest;
use LayBot\Request\DTO\Response;
use LayBot\Request\DTO\ResponseHead;
use LayBot\Request\DTO\StreamResult;
use LayBot\Request\Enum\StreamTermination;
use LayBot\Request\Exception\CancelledException;
use LayBot\Request\Exception\ConfigurationException;
use LayBot\Request\Exception\ConnectionException;
use LayBot\Request\Exception\ConnectTimeoutException;
use LayBot\Request\Exception\RequestException;
use LayBot\Request\Exception\RequestTimeoutException;
use LayBot\Request\Exception\StreamCallbackException;
use LayBot\Request\Exception\StreamIdleTimeoutException;
use LayBot\Request\Exception\TlsException;
use LayBot\Request\Exception\UnexpectedEofException;
use LayBot\Request\Stream\AsyncRequestHandle;
use LayBot\Request\Stream\CancellationRegistration;
use LayBot\Request\Support\Env;
use LayBot\Request\Support\ExceptionFactory;
use LayBot\Request\Support\Header;
use LayBot\Request\Transport\Internal\ManagedHttpRequest;
use Psr\Http\Message\ResponseInterface;
use Workerman\Http\ConnectionPool;
use Workerman\Timer;

final class WorkermanTransport implements AsyncHttpTransportInterface
{
    private ConnectionPool $pool;

    public function __construct(
        private readonly bool $verify = true
    ) {
        $this->pool = new ConnectionPool([
            'max_conn_per_addr' => 128,
            'keepalive_timeout' => 15,

            /*
             * 精确超时由本 Transport 自己的 Timer 控制。
             * 这里设置较大值，只作为底层连接池兜底。
             */
            'connect_timeout' => 3600,
            'timeout' => 86400,

            'context' => [
                'ssl' => [
                    'verify_peer' => $this->verify,
                    'verify_peer_name' => $this->verify,
                    'allow_self_signed' => !$this->verify,
                    'SNI_enabled' => true,
                    'disable_compression' => true,
                ],
            ],
        ]);
    }

    public function requestAsync(
        PreparedRequest $request,
        callable $onComplete,
        callable $onError
    ): AsyncRequestHandle {
        return $this->execute(
            prepared: $request,
            streaming: false,
            onOpen: static function (ResponseHead $head): void {
            },
            onChunk: static function (string $chunk): bool {
                return true;
            },
            onComplete: $onComplete,
            onError: $onError
        );
    }

    public function streamAsync(
        PreparedRequest $request,
        callable $onOpen,
        callable $onChunk,
        callable $onComplete,
        callable $onError
    ): AsyncRequestHandle {
        return $this->execute(
            prepared: $request,
            streaming: true,
            onOpen: $onOpen,
            onChunk: $onChunk,
            onComplete: $onComplete,
            onError: $onError
        );
    }

    private function execute(
        PreparedRequest $prepared,
        bool $streaming,
        callable $onOpen,
        callable $onChunk,
        callable $onComplete,
        callable $onError
    ): AsyncRequestHandle {
        if (!Env::inWorkermanLoop()) {
            throw new ConfigurationException(
                'WorkermanTransport requires an active Workerman event loop'
            );
        }

        $prepared->cancellation?->throwIfCancelled();

        $handle = new AsyncRequestHandle();
        $handle->markRunning();

        $startedAt = hrtime(true);
        $terminal = false;
        $head = null;
        $errorBody = '';
        $pooled = false;

        $connectTimer = null;
        $requestTimer = null;
        $idleTimer = null;

        $cancellationRegistration = null;

        [$sink, $closeSink] = $this->openSink($prepared->sink);

        $request = new ManagedHttpRequest(
            url: $prepared->url,
            storeResponseBody: !$streaming && $sink === null,
            maxResponseBytes: $prepared->maxResponseBytes
        );

        $clearTimers = static function () use (
            &$connectTimer,
            &$requestTimer,
            &$idleTimer
        ): void {
            foreach (
                [$connectTimer, $requestTimer, $idleTimer]
                as $timer
            ) {
                if ($timer !== null) {
                    Timer::del($timer);
                }
            }

            $connectTimer = null;
            $requestTimer = null;
            $idleTimer = null;
        };

        $releaseSink = static function () use (
            &$sink,
            $closeSink
        ): void {
            if (!is_resource($sink)) {
                return;
            }

            @fflush($sink);

            if ($closeSink) {
                @fclose($sink);
            }

            $sink = null;
        };

        $clearCommon = static function () use (
            $clearTimers,
            $releaseSink,
            &$cancellationRegistration
        ): void {
            $clearTimers();
            $releaseSink();

            if (
                $cancellationRegistration
                instanceof CancellationRegistration
            ) {
                $cancellationRegistration->unregister();
                $cancellationRegistration = null;
            }
        };

        $finishError = function (\Throwable $error) use (
            &$terminal,
            &$pooled,
            $clearCommon,
            $request,
            $handle,
            $onError,
            $prepared
        ): void {
            if ($terminal) {
                return;
            }

            $terminal = true;
            $clearCommon();
            $request->abort($this->pool, $pooled);

            if (!$error instanceof RequestException) {
                $error = $this->mapNetworkError(
                    $error,
                    $prepared
                );
            }

            if ($error instanceof CancelledException) {
                $handle->markCancelled();
            } else {
                $handle->markFailed();
            }

            try {
                $onError($error);
            } catch (\Throwable) {
                // onError 是异步生命周期最终边界。
            }
        };

        $touchIdle = function () use (
            &$idleTimer,
            $prepared,
            $streaming,
            $finishError
        ): void {
            if (!$streaming || $prepared->timeouts->idle <= 0) {
                return;
            }

            if ($idleTimer !== null) {
                Timer::del($idleTimer);
            }

            $idleTimer = Timer::delay(
                $prepared->timeouts->idle,
                static function () use ($finishError): void {
                    $finishError(new StreamIdleTimeoutException(
                        'stream idle timeout',
                        retryable: true
                    ));
                }
            );
        };

        $finishEarly = function (
            StreamTermination $termination
        ) use (
            &$terminal,
            &$head,
            &$pooled,
            $clearCommon,
            $request,
            $handle,
            $onComplete,
            $onError,
            $prepared,
            $startedAt
        ): void {
            if ($terminal) {
                return;
            }

            if (!$head instanceof ResponseHead) {
                return;
            }

            $terminal = true;
            $clearCommon();

            /*
             * 提前结束时响应尚未消费完，该连接不可复用。
             */
            $request->abort($this->pool, $pooled);

            $result = new StreamResult(
                status: $head->status,
                headers: $head->headers,
                url: $prepared->url,
                termination: $termination,
                bytesReceived: $request->receivedBytes(),
                durationMs: self::elapsed($startedAt)
            );

            try {
                $onComplete($result);
                $handle->markCompleted();
            } catch (\Throwable $error) {
                $handle->markFailed();

                try {
                    $onError(new StreamCallbackException(
                        'completion callback failed: '
                        . $error->getMessage(),
                        previous: $error
                    ));
                } catch (\Throwable) {
                }
            }
        };

        $finishSuccess = function (
            ResponseInterface $nativeResponse
        ) use (
            &$terminal,
            &$pooled,
            &$errorBody,
            $clearCommon,
            $request,
            $handle,
            $prepared,
            $streaming,
            $onComplete,
            $onError,
            $startedAt
        ): void {
            if ($terminal) {
                return;
            }

            $status = $nativeResponse->getStatusCode();
            $headers = Header::normalize(
                $nativeResponse->getHeaders()
            );

            $body = $streaming
                ? $errorBody
                : (
                $prepared->sink !== null
                    ? ''
                    : (string)$nativeResponse->getBody()
                );

            if ($status < 200 || $status >= 300) {
                $terminal = true;
                $clearCommon();

                /*
                 * HTTP 消息已完整接收，连接本身仍可正常归还。
                 */
                $request->release(
                    $this->pool,
                    $pooled,
                    true
                );

                $handle->markFailed();

                try {
                    $onError(ExceptionFactory::http(
                        $prepared->method,
                        $prepared->url,
                        $status,
                        $headers,
                        $body
                    ));
                } catch (\Throwable) {
                }

                return;
            }

            $terminal = true;
            $clearCommon();

            $request->release(
                $this->pool,
                $pooled,
                true
            );

            try {
                if ($streaming) {
                    $onComplete(new StreamResult(
                        status: $status,
                        headers: $headers,
                        url: $prepared->url,
                        termination:
                        StreamTermination::MESSAGE_COMPLETE,
                        bytesReceived: $request->receivedBytes(),
                        durationMs: self::elapsed($startedAt)
                    ));
                } else {
                    $onComplete(new Response(
                        status: $status,
                        headers: $headers,
                        body: $body,
                        url: $prepared->url,
                        durationMs: self::elapsed($startedAt),
                        protocolVersion:
                        $nativeResponse->getProtocolVersion()
                    ));
                }

                $handle->markCompleted();
            } catch (\Throwable $callbackError) {
                $handle->markFailed();

                try {
                    $onError(new StreamCallbackException(
                        'completion callback failed: '
                        . $callbackError->getMessage(),
                        previous: $callbackError
                    ));
                } catch (\Throwable) {
                }
            }
        };

        $markConnected = function () use (
            &$connectTimer,
            $touchIdle
        ): void {
            if ($connectTimer !== null) {
                Timer::del($connectTimer);
                $connectTimer = null;
            }

            $touchIdle();
        };

        $request->onConnected($markConnected);

        $request->on(
            'response',
            function (ResponseInterface $nativeResponse) use (
                &$head,
                $prepared,
                $streaming,
                $onOpen,
                $touchIdle,
                $markConnected
            ): void {
                $markConnected();

                $head = new ResponseHead(
                    status: $nativeResponse->getStatusCode(),
                    headers: Header::normalize(
                        $nativeResponse->getHeaders()
                    ),
                    url: $prepared->url,
                    protocolVersion:
                    $nativeResponse->getProtocolVersion()
                );

                $touchIdle();

                if (
                    $streaming
                    && $head->status >= 200
                    && $head->status < 300
                ) {
                    $onOpen($head);
                }
            }
        );

        $request->on(
            'progress',
            function (string $chunk) use (
                &$head,
                &$errorBody,
                &$sink,
                $streaming,
                $onChunk,
                $touchIdle,
                $finishEarly
            ): void {
                $touchIdle();

                if ($chunk === '') {
                    return;
                }

                if (
                    $head instanceof ResponseHead
                    && ($head->status < 200 || $head->status >= 300)
                ) {
                    if (strlen($errorBody) < 65_536) {
                        $errorBody .= substr(
                            $chunk,
                            0,
                            65_536 - strlen($errorBody)
                        );
                    }

                    return;
                }

                if (is_resource($sink)) {
                    $remaining = $chunk;

                    while ($remaining !== '') {
                        $written = fwrite($sink, $remaining);

                        if ($written === false || $written === 0) {
                            throw new \RuntimeException(
                                'failed to write response sink'
                            );
                        }

                        $remaining = substr($remaining, $written);
                    }
                }

                if (!$streaming) {
                    return;
                }

                try {
                    $continue = $onChunk($chunk);
                } catch (\Throwable $error) {
                    throw new StreamCallbackException(
                        'stream callback failed: '
                        . $error->getMessage(),
                        previous: $error
                    );
                }

                if ($continue === false) {
                    $finishEarly(
                        StreamTermination::CALLBACK_STOP
                    );
                }
            }
        );

        $request->once(
            'success',
            static function (
                ResponseInterface $response
            ) use ($finishSuccess): void {
                $finishSuccess($response);
            }
        );

        $request->once(
            'error',
            static function (\Throwable $error) use (
                $finishError
            ): void {
                $finishError($error);
            }
        );

        $handle->setCanceller(
            static function (?string $reason) use (
                $finishError
            ): void {
                $finishError(new CancelledException(
                    $reason ?: 'request cancelled'
                ));
            }
        );

        if ($prepared->cancellation !== null) {
            $cancellationRegistration =
                $prepared->cancellation->subscribe(
                    static function (?string $reason) use (
                        $finishError
                    ): void {
                        $finishError(new CancelledException(
                            $reason ?: 'request cancelled'
                        ));
                    }
                );
        }

        if ($terminal) {
            return $handle;
        }

        $connectTimer = Timer::delay(
            $prepared->timeouts->connect,
            static function () use ($finishError): void {
                $finishError(new ConnectTimeoutException(
                    'connection timeout',
                    retryable: true
                ));
            }
        );

        if ($prepared->timeouts->request > 0) {
            $requestTimer = Timer::delay(
                $prepared->timeouts->request,
                static function () use ($finishError): void {
                    $finishError(new RequestTimeoutException(
                        'request timeout',
                        retryable: true
                    ));
                }
            );
        }

        try {
            $parts = parse_url($prepared->url);

            if ($parts === false || empty($parts['host'])) {
                throw new ConfigurationException(
                    'invalid asynchronous HTTP URL'
                );
            }

            $host = (string)$parts['host'];
            $secure = strtolower((string)$parts['scheme']) === 'https';
            $port = (int)($parts['port'] ?? ($secure ? 443 : 80));

            $context = [
                'ssl' => [
                    'verify_peer' => $this->verify,
                    'verify_peer_name' => $this->verify,
                    'allow_self_signed' => !$this->verify,
                    'peer_name' => $host,
                    'SNI_enabled' => true,
                    'disable_compression' => true,
                ],
            ];

            $request->setOptions([
                'method' => $prepared->method,
                'headers' => $prepared->headers,
                'context' => $context,
                'proxy' => $prepared->proxy ?? '',
                'allow_redirects' => ['max' => 0],
            ]);

            if ($prepared->multipart !== null) {
                $request->write([
                    'multipart' => $prepared->multipart,
                ]);
            } elseif ($prepared->body !== '') {
                $request->write($prepared->body);
            }

            $addressHost = str_contains($host, ':')
                ? '[' . trim($host, '[]') . ']'
                : $host;

            $connection = $this->pool->fetch(
                "tcp://{$addressHost}:{$port}",
                $secure,
                $prepared->proxy ?? ''
            );

            if ($connection !== null) {
                $pooled = true;
                $request->attachConnection($connection);

                if ($connection->getStatus(false) === 'ESTABLISHED') {
                    $markConnected();
                }
            }

            /*
             * 如果连接池达到上限，Request 会建立独占连接。
             * 这避免事件循环被同步等待；该独占连接完成后不会复用。
             */
            $request->end();
        } catch (\Throwable $error) {
            $finishError($error);
        }

        return $handle;
    }

    /**
     * @return array{0:resource|null,1:bool}
     */
    private function openSink(mixed $sink): array
    {
        if ($sink === null) {
            return [null, false];
        }

        if (is_resource($sink)) {
            return [$sink, false];
        }

        if (!is_string($sink) || $sink === '') {
            throw new ConfigurationException(
                'sink must be a writable resource or file path'
            );
        }

        $resource = @fopen($sink, 'wb');

        if ($resource === false) {
            throw new ConfigurationException(
                "unable to open response sink: {$sink}"
            );
        }

        return [$resource, true];
    }

    private function mapNetworkError(
        \Throwable $error,
        PreparedRequest $prepared
    ): \Throwable {
        $message = $error->getMessage();

        if (preg_match(
            '/closed|unexpected eof|connection reset|broken pipe/i',
            $message
        )) {
            return new UnexpectedEofException(
                message: 'HTTP response ended unexpectedly: ' . $message,
                previous: $error,
                method: $prepared->method,
                url: $prepared->url,
                retryable: true
            );
        }

        if (preg_match(
            '/ssl|tls|certificate|handshake/i',
            $message
        )) {
            return new TlsException(
                message: 'TLS request failed: ' . $message,
                previous: $error,
                method: $prepared->method,
                url: $prepared->url
            );
        }

        if (preg_match('/connect.*timeout/i', $message)) {
            return new ConnectTimeoutException(
                message: 'connection timeout: ' . $message,
                previous: $error,
                method: $prepared->method,
                url: $prepared->url,
                retryable: true
            );
        }

        if (preg_match('/timeout/i', $message)) {
            return new RequestTimeoutException(
                message: 'request timeout: ' . $message,
                previous: $error,
                method: $prepared->method,
                url: $prepared->url,
                retryable: true
            );
        }

        return new ConnectionException(
            message: 'request connection failed: ' . $message,
            previous: $error,
            method: $prepared->method,
            url: $prepared->url,
            retryable: true
        );
    }

    private static function elapsed(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
