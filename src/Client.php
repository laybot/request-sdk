<?php
declare(strict_types=1);

namespace LayBot\Request;

use LayBot\Request\DTO\SigningContext;
use LayBot\Request\Exception\ConfigurationException;
use LayBot\Request\Support\UserAgent;
use LayBot\Request\Contract\ClientInterface;
use LayBot\Request\Contract\ContextSignerInterface;
use LayBot\Request\Contract\SignerInterface;
use LayBot\Request\Contract\WebSocketConnectionInterface;
use LayBot\Request\Contract\WebSocketListenerInterface;
use LayBot\Request\DTO\JsonLine;
use LayBot\Request\DTO\Response;
use LayBot\Request\DTO\ResponseHead;
use LayBot\Request\DTO\SseEvent;
use LayBot\Request\DTO\StreamResult;
use LayBot\Request\DTO\WebSocketRequest;
use LayBot\Request\Enum\StreamTermination;
use LayBot\Request\Exception\CancelledException;
use LayBot\Request\Exception\StreamCallbackException;
use LayBot\Request\Middleware\RequestPreparer;
use LayBot\Request\Middleware\RetryPolicy;
use LayBot\Request\Signer\ApiKeySigner;
use LayBot\Request\Signer\BasicSigner;
use LayBot\Request\Signer\BearerSigner;
use LayBot\Request\Signer\HmacSigner;
use LayBot\Request\Signer\InnerSigner;
use LayBot\Request\Signer\NoneSigner;
use LayBot\Request\Stream\AsyncRequestHandle;
use LayBot\Request\Stream\LineParser;
use LayBot\Request\Stream\SseParser;
use LayBot\Request\Support\Header;
use LayBot\Request\Support\Json;
use LayBot\Request\Support\LogSanitizer;
use LayBot\Request\Timeout\TimeoutConfig;
use LayBot\Request\Transport\GuzzleTransport;
use LayBot\Request\Transport\WorkermanTransport;
use LayBot\Request\Transport\WorkermanWebSocketTransport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\Timer;

final class Client implements ClientInterface
{
    private Config $config;
    private RequestPreparer $preparer;
    private GuzzleTransport $syncTransport;
    private WorkermanTransport $asyncTransport;
    private WorkermanWebSocketTransport $webSocketTransport;

    public static function make(array $options): self
    {
        return new self($options);
    }

    public function __construct(Config|array $options)
    {
        $this->config = $options instanceof Config
            ? $options
            : self::normalizeConfig($options);

        $this->rebuild();
    }

    public function request(
        string $method,
        string $path,
        array $options = []
    ): Response {
        $method = strtoupper($method);
        $policy = RetryPolicy::fromMixed(
            $options['retry'] ?? $this->config->retryPolicy,
            $this->config->retryPolicy
        );

        $idempotent = (bool)(
            $options['idempotent']
            ?? ($options['retry']['idempotent'] ?? false)
        );

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $prepared = $this->preparer->prepare(
                    $method,
                    $path,
                    $options
                );

                $this->logRequest(
                    $method,
                    $prepared->url,
                    $prepared->headers,
                    $prepared->body,
                    $attempt
                );

                $response = $this->syncTransport
                    ->request($prepared)
                    ->withAttempts($attempt);

                $this->logResponse($response);

                return $response;
            } catch (\Throwable $error) {
                if (!$policy->shouldRetry(
                    $method,
                    $attempt,
                    $error,
                    $idempotent
                )) {
                    throw $error;
                }

                $retryAfter = method_exists(
                    $error,
                    'getResponseHeaders'
                )
                    ? Header::line(
                        $error->getResponseHeaders(),
                        'Retry-After'
                    )
                    : null;

                $delay = $policy->delayMs(
                    $attempt,
                    $retryAfter ?: null
                );

                $this->config->logger->warning(
                    '[HTTP] retry',
                    [
                        'method' => $method,
                        'url' => LogSanitizer::url($path),
                        'attempt' => $attempt,
                        'delay_ms' => $delay,
                        'error' => $error::class,
                    ]
                );

                usleep($delay * 1000);
            }
        }
    }

    public function requestAsync(
        string $method,
        string $path,
        array $options,
        callable $onComplete,
        callable $onError
    ): AsyncRequestHandle {
        $method = strtoupper($method);
        $policy = RetryPolicy::fromMixed(
            $options['retry'] ?? $this->config->retryPolicy,
            $this->config->retryPolicy
        );

        $idempotent = (bool)(
            $options['idempotent']
            ?? ($options['retry']['idempotent'] ?? false)
        );

        $outer = new AsyncRequestHandle();
        $outer->markRunning();

        $attempt = 0;
        $current = null;
        $retryTimer = null;

        $run = null;

        $run = function () use (
            &$run,
            &$attempt,
            &$current,
            &$retryTimer,
            $outer,
            $method,
            $path,
            $options,
            $policy,
            $idempotent,
            $onComplete,
            $onError
        ): void {
            if ($outer->isSettled()) {
                return;
            }

            $attempt++;

            try {
                $prepared = $this->preparer->prepare(
                    $method,
                    $path,
                    $options
                );
            } catch (\Throwable $error) {
                $outer->markFailed();

                try {
                    $onError($error);
                } catch (\Throwable) {
                }

                return;
            }

            $this->logRequest(
                $method,
                $prepared->url,
                $prepared->headers,
                $prepared->body,
                $attempt
            );

            $current = $this->asyncTransport->requestAsync(
                $prepared,
                function (Response $response) use (
                    $outer,
                    $attempt,
                    $onComplete,
                    $onError
                ): void {
                    if ($outer->isSettled()) {
                        return;
                    }

                    $response = $response->withAttempts($attempt);
                    $this->logResponse($response);

                    try {
                        $onComplete($response);
                        $outer->markCompleted();
                    } catch (\Throwable $error) {
                        $outer->markFailed();
                        $onError(new StreamCallbackException(
                            'completion callback failed: '
                            . $error->getMessage(),
                            previous: $error
                        ));
                    }
                },
                function (\Throwable $error) use (
                    &$run,
                    &$retryTimer,
                    $outer,
                    $method,
                    $attempt,
                    $policy,
                    $idempotent,
                    $onError
                ): void {
                    if ($outer->isSettled()) {
                        return;
                    }

                    if ($error instanceof CancelledException) {
                        $outer->markCancelled();
                        $onError($error);
                        return;
                    }

                    if (!$policy->shouldRetry(
                        $method,
                        $attempt,
                        $error,
                        $idempotent
                    )) {
                        $outer->markFailed();
                        $onError($error);
                        return;
                    }

                    $retryAfter = method_exists(
                        $error,
                        'getResponseHeaders'
                    )
                        ? Header::line(
                            $error->getResponseHeaders(),
                            'Retry-After'
                        )
                        : null;

                    $delay = $policy->delayMs(
                        $attempt,
                        $retryAfter ?: null
                    );

                    $retryTimer = Timer::delay(
                        $delay / 1000,
                        $run
                    );
                }
            );
        };

        $outer->setCanceller(
            function (?string $reason) use (
                &$current,
                &$retryTimer,
                $outer,
                $onError
            ): void {
                if ($retryTimer !== null) {
                    Timer::del($retryTimer);
                    $retryTimer = null;
                }

                if ($current instanceof AsyncRequestHandle) {
                    $current->cancel($reason);
                    return;
                }

                $error = new CancelledException(
                    $reason ?: 'request cancelled'
                );

                $outer->markCancelled();
                $onError($error);
            }
        );

        $run();

        return $outer;
    }

    public function streamRaw(
        string $method,
        string $path,
        array $options,
        callable $onChunk
    ): StreamResult {
        $prepared = $this->preparer->prepare(
            $method,
            $path,
            $options,
            true
        );

        return $this->syncTransport->stream(
            $prepared,
            $onChunk
        );
    }

    public function streamRawAsync(
        string $method,
        string $path,
        array $options,
        callable $onOpen,
        callable $onChunk,
        callable $onComplete,
        callable $onError
    ): AsyncRequestHandle {
        $prepared = $this->preparer->prepare(
            $method,
            $path,
            $options,
            true
        );

        return $this->asyncTransport->streamAsync(
            $prepared,
            $onOpen,
            $onChunk,
            $onComplete,
            $onError
        );
    }

    public function streamSse(
        string $method,
        string $path,
        array $options,
        callable $onEvent,
        ?string $doneToken = null
    ): StreamResult {
        $options['headers']['Accept'] ??= 'text/event-stream';
        $options['headers']['Cache-Control'] ??= 'no-cache';

        $parser = new SseParser(
            $doneToken,
            (int)($options['max_event_bytes'] ?? 8_388_608)
        );

        $items = 0;

        $emit = static function (SseEvent $event) use (
            $onEvent,
            &$items
        ): void {
            $items++;
            $onEvent($event);
        };

        $result = $this->streamRaw(
            $method,
            $path,
            $options,
            static function (string $chunk) use (
                $parser,
                $emit
            ): bool {
                $parser->feed($chunk, $emit);

                /*
                 * false 表示正常提前停止，不作为取消或异常处理。
                 */
                return !$parser->doneSeen();
            }
        );

        $parser->finish($emit);

        if ($parser->doneSeen()) {
            $result = $result->withTermination(
                StreamTermination::DONE_TOKEN
            );
        }

        return $result->withItemsReceived($items);
    }


    public function streamSseAsync(
        string $method,
        string $path,
        array $options,
        callable $onOpen,
        callable $onEvent,
        callable $onComplete,
        callable $onError,
        ?string $doneToken = null
    ): AsyncRequestHandle {
        $options['headers']['Accept'] ??= 'text/event-stream';
        $options['headers']['Cache-Control'] ??= 'no-cache';

        $parser = new SseParser(
            $doneToken,
            (int)($options['max_event_bytes'] ?? 8_388_608)
        );

        $items = 0;

        $emit = static function (SseEvent $event) use (
            $onEvent,
            &$items
        ): void {
            $items++;
            $onEvent($event);
        };

        return $this->streamRawAsync(
            $method,
            $path,
            $options,
            $onOpen,
            static function (string $chunk) use (
                $parser,
                $emit
            ): bool {
                $parser->feed($chunk, $emit);
                return !$parser->doneSeen();
            },
            static function (StreamResult $result) use (
                $parser,
                $emit,
                &$items,
                $onComplete
            ): void {
                $parser->finish($emit);

                if ($parser->doneSeen()) {
                    $result = $result->withTermination(
                        StreamTermination::DONE_TOKEN
                    );
                }

                $onComplete(
                    $result->withItemsReceived($items)
                );
            },
            $onError
        );
    }


    public function streamJsonLines(
        string $method,
        string $path,
        array $options,
        callable $onLine
    ): StreamResult {
        $parser = new LineParser(
            (int)($options['max_line_bytes'] ?? 8_388_608)
        );

        $items = 0;

        $emit = static function (
            string $raw,
            int $number
        ) use ($onLine, &$items): void {
            $items++;
            $onLine(new JsonLine(
                $number,
                Json::decodeAny($raw),
                $raw
            ));
        };

        $result = $this->streamRaw(
            $method,
            $path,
            $options,
            static function (string $chunk) use (
                $parser,
                $emit
            ): void {
                $parser->feed($chunk, $emit);
            }
        );

        $parser->finish($emit);

        return $result->withItemsReceived($items);
    }

    public function streamJsonLinesAsync(
        string $method,
        string $path,
        array $options,
        callable $onOpen,
        callable $onLine,
        callable $onComplete,
        callable $onError
    ): AsyncRequestHandle {
        $parser = new LineParser(
            (int)($options['max_line_bytes'] ?? 8_388_608)
        );

        $items = 0;

        $emit = static function (
            string $raw,
            int $number
        ) use ($onLine, &$items): void {
            $items++;
            $onLine(new JsonLine(
                $number,
                Json::decodeAny($raw),
                $raw
            ));
        };

        return $this->streamRawAsync(
            $method,
            $path,
            $options,
            $onOpen,
            static function (string $chunk) use (
                $parser,
                $emit
            ): void {
                $parser->feed($chunk, $emit);
            },
            static function (StreamResult $result) use (
                $parser,
                $emit,
                &$items,
                $onComplete
            ): void {
                $parser->finish($emit);
                $onComplete(
                    $result->withItemsReceived($items)
                );
            },
            $onError
        );
    }

    public function connectWebSocketAsync(
        WebSocketRequest $request,
        WebSocketListenerInterface $listener
    ): WebSocketConnectionInterface {
        $parts = parse_url($request->url);
        $base = parse_url($this->config->baseUri);

        if (
            $parts === false
            || $base === false
            || empty($parts['host'])
            || empty($base['host'])
        ) {
            throw new ConfigurationException(
                'invalid WebSocket or Client base URI'
            );
        }

        $wsPort = (int)(
            $parts['port']
            ?? (
        strtolower((string)$parts['scheme']) === 'wss'
            ? 443
            : 80
        )
        );

        $basePort = (int)(
            $base['port']
            ?? (
        strtolower((string)$base['scheme']) === 'https'
            ? 443
            : 80
        )
        );

        /*
         * HTTP/HTTPS 与 WS/WSS 的同源判断采用 host + effective port。
         */
        $crossOrigin =
            strtolower((string)$parts['host'])
            !== strtolower((string)$base['host'])
            || $wsPort !== $basePort;

        if ($crossOrigin && !$request->allowCrossOrigin) {
            throw new ConfigurationException(
                'cross-origin WebSocket URL is disabled'
            );
        }

        $headers = Header::merge(
            $this->config->headers,
            $request->headers
        );

        $applySigner = true;

        if (
            $crossOrigin
            && !$request->forwardCrossOriginCredentials
        ) {
            $headers = Header::withoutCredentials(
                $headers,
                $this->config->sensitiveHeaders
            );
            $applySigner = false;
        }

        $headers = Header::setIfMissing(
            $headers,
            'User-Agent',
            $this->config->userAgent ?: UserAgent::default()
        );

        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';

        if ($applySigner) {
            if (
                $this->config->signer
                instanceof ContextSignerInterface
            ) {
                $signed = $this->config->signer->signRequest(
                    new SigningContext(
                        method: 'GET',
                        url: $request->url,
                        scheme: strtolower((string)$parts['scheme']),
                        host: (string)$parts['host'],
                        port: isset($parts['port'])
                            ? (int)$parts['port']
                            : null,
                        path: $path,
                        canonicalQuery: $query,
                        body: '',
                        headers: $headers,
                    )
                );
            } else {
                $signed = $this->config->signer->sign(
                    'GET',
                    $path,
                    ''
                );
            }

            $headers = Header::merge($headers, $signed);
        }

        return $this->webSocketTransport->connectAsync(
            $request->withHeaders($headers),
            $listener
        );
    }


    public function send(
        string $method,
        string $path,
        array $options = [],
        bool $jsonDecode = true
    ): mixed {
        $response = $this->request($method, $path, $options);

        if (!$jsonDecode) {
            return $response->body;
        }

        return $response->body === ''
            ? []
            : $response->jsonArray();
    }

    public function sendAny(
        string $method,
        string $path,
        array $options = [],
        bool $jsonDecode = true
    ): mixed {
        $response = $this->request($method, $path, $options);

        if (!$jsonDecode) {
            return $response->body;
        }

        return $response->body === ''
            ? null
            : $response->jsonAny();
    }

    public function requestJsonAny(
        string $method,
        string $path,
        array $options = []
    ): mixed {
        return $this->sendAny($method, $path, $options);
    }

    public function requestJsonArray(
        string $method,
        string $path,
        array $options = []
    ): array {
        return $this->send($method, $path, $options);
    }

    public function requestRaw(
        string $method,
        string $path,
        array $options = []
    ): array {
        return $this->request(
            $method,
            $path,
            $options
        )->toLegacyArray();
    }

    public function get(
        string $path,
        array $query = [],
        array $headers = []
    ): array {
        return $this->send('GET', $path, compact('query', 'headers'));
    }

    public function getAny(
        string $path,
        array $query = [],
        array $headers = []
    ): mixed {
        return $this->sendAny(
            'GET',
            $path,
            compact('query', 'headers')
        );
    }

    public function postJson(
        string $path,
        array $json = [],
        array $headers = []
    ): array {
        return $this->send(
            'POST',
            $path,
            compact('json', 'headers')
        );
    }

    public function postJsonAny(
        string $path,
        array $json = [],
        array $headers = []
    ): mixed {
        return $this->sendAny(
            'POST',
            $path,
            compact('json', 'headers')
        );
    }

    public function postForm(
        string $path,
        array $form = [],
        array $headers = []
    ): array {
        return $this->send('POST', $path, [
            'form_params' => $form,
            'headers' => $headers,
        ]);
    }

    public function postFormAny(
        string $path,
        array $form = [],
        array $headers = []
    ): mixed {
        return $this->sendAny('POST', $path, [
            'form_params' => $form,
            'headers' => $headers,
        ]);
    }

    public function post(
        string $path,
        string|array $body = '',
        array $headers = []
    ): array {
        return $this->send('POST', $path, is_array($body)
            ? ['form_params' => $body, 'headers' => $headers]
            : ['body' => $body, 'headers' => $headers]);
    }

    public function postAny(
        string $path,
        string|array $body = '',
        array $headers = []
    ): mixed {
        return $this->sendAny('POST', $path, is_array($body)
            ? ['form_params' => $body, 'headers' => $headers]
            : ['body' => $body, 'headers' => $headers]);
    }

    public function put(
        string $path,
        string|array $body = '',
        array $headers = []
    ): array {
        return $this->send('PUT', $path, is_array($body)
            ? ['json' => $body, 'headers' => $headers]
            : ['body' => $body, 'headers' => $headers]);
    }

    public function putAny(
        string $path,
        string|array $body = '',
        array $headers = []
    ): mixed {
        return $this->sendAny('PUT', $path, is_array($body)
            ? ['json' => $body, 'headers' => $headers]
            : ['body' => $body, 'headers' => $headers]);
    }

    public function patch(
        string $path,
        string|array $body = '',
        array $headers = []
    ): array {
        return $this->send('PATCH', $path, is_array($body)
            ? ['json' => $body, 'headers' => $headers]
            : ['body' => $body, 'headers' => $headers]);
    }

    public function patchAny(
        string $path,
        string|array $body = '',
        array $headers = []
    ): mixed {
        return $this->sendAny('PATCH', $path, is_array($body)
            ? ['json' => $body, 'headers' => $headers]
            : ['body' => $body, 'headers' => $headers]);
    }

    public function delete(
        string $path,
        array $query = [],
        array $headers = []
    ): array {
        return $this->send(
            'DELETE',
            $path,
            compact('query', 'headers')
        );
    }

    public function deleteAny(
        string $path,
        array $query = [],
        array $headers = []
    ): mixed {
        return $this->sendAny(
            'DELETE',
            $path,
            compact('query', 'headers')
        );
    }

    public function head(
        string $path,
        array $query = [],
        array $headers = []
    ): array {
        return $this->requestRaw(
            'HEAD',
            $path,
            compact('query', 'headers')
        );
    }

    public function options(
        string $path,
        array $query = [],
        array $headers = []
    ): array {
        return $this->requestRaw(
            'OPTIONS',
            $path,
            compact('query', 'headers')
        );
    }

    public function upload(
        string $path,
        string $field,
        string $file,
        array $extra = [],
        array $headers = []
    ): array {
        if ($field === '' || !is_file($file) || !is_readable($file)) {
            throw new \InvalidArgumentException(
                'invalid upload file or field'
            );
        }

        $stream = fopen($file, 'rb');

        if ($stream === false) {
            throw new \RuntimeException(
                "cannot open upload file: {$file}"
            );
        }

        $multipart = [[
            'name' => $field,
            'contents' => $stream,
            'filename' => basename($file),
        ]];

        foreach ($extra as $name => $value) {
            $multipart[] = [
                'name' => (string)$name,
                'contents' => is_scalar($value) || $value === null
                    ? (string)$value
                    : Json::encode($value),
            ];
        }

        try {
            return $this->send('POST', $path, [
                'multipart' => $multipart,
                'headers' => $headers,
                'retry' => false,
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function download(
        string $path,
        string $saveTo,
        array $query = [],
        array $headers = [],
        int $maxBytes = 536_870_912
    ): string {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException(
                'maxBytes must be greater than zero'
            );
        }

        $directory = dirname($saveTo);

        if (
            $directory !== '.'
            && !is_dir($directory)
            && !mkdir($directory, 0775, true)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException(
                "cannot create directory: {$directory}"
            );
        }

        $temporary = $saveTo . '.part';
        @unlink($temporary);

        try {
            $this->request('GET', $path, [
                'query' => $query,
                'headers' => $headers,
                'sink' => $temporary,
                'max_response_bytes' => $maxBytes,
            ]);

            if (!rename($temporary, $saveTo)) {
                throw new \RuntimeException(
                    'cannot atomically move downloaded file'
                );
            }
        } catch (\Throwable $error) {
            @unlink($temporary);
            throw $error;
        }

        return realpath($saveTo) ?: $saveTo;
    }

    /**
     * Webman/Workerman 异步低内存文件下载。
     *
     * 下载时写入 .part 文件，成功后原子重命名，失败或取消时删除临时文件。
     *
     * @param callable(string):void $onComplete
     * @param callable(\Throwable):void $onError
     */
    public function downloadAsync(
        string $path,
        string $saveTo,
        array $query,
        array $headers,
        callable $onComplete,
        callable $onError,
        int $maxBytes = 536_870_912
    ): AsyncRequestHandle {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException(
                'maxBytes must be greater than zero'
            );
        }

        $directory = dirname($saveTo);

        if (
            $directory !== '.'
            && !is_dir($directory)
            && !mkdir($directory, 0775, true)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException(
                "cannot create directory: {$directory}"
            );
        }

        $temporary = $saveTo . '.part';
        @unlink($temporary);

        return $this->requestAsync(
            method: 'GET',
            path: $path,
            options: [
                'query' => $query,
                'headers' => $headers,
                'sink' => $temporary,
                'max_response_bytes' => $maxBytes,
                'retry' => false,
            ],
            onComplete: static function () use (
                $temporary,
                $saveTo,
                $onComplete
            ): void {
                if (!rename($temporary, $saveTo)) {
                    @unlink($temporary);

                    throw new \RuntimeException(
                        'cannot atomically move downloaded file'
                    );
                }

                $onComplete(realpath($saveTo) ?: $saveTo);
            },
            onError: static function (\Throwable $error) use (
                $temporary,
                $onError
            ): void {
                @unlink($temporary);
                $onError($error);
            }
        );
    }

    /**
     * 旧版兼容入口：固定为同步阻塞式 SSE。
     *
     * @param callable(string,bool):void $callback
     */
    public function stream(
        string $path,
        array $json,
        callable $callback,
        array $headers = [],
        array $options = []
    ): void {
        $doneToken = $options['decode']['done_token']
            ?? '[DONE]';

        $this->streamSse(
            'POST',
            $path,
            [
                'json' => $json,
                'headers' => $headers,
                'connect_timeout' =>
                    $options['connect_timeout']
                    ?? $this->config->timeouts->connect,
                'request_timeout' => 0,
                'idle_timeout' =>
                    $options['idle_timeout']
                    ?? $this->config->timeouts->idle,
            ],
            static function (SseEvent $event) use (
                $callback
            ): void {
                if (!$event->comment) {
                    $callback($event->data, false);
                }
            },
            $doneToken
        );

        $callback('', true);
    }

    public function withSigner(
        SignerInterface|ContextSignerInterface $signer
    ): self {
        return $this->reconfigured(
            $this->config->withSigner($signer)
        );
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return $this->reconfigured(
            $this->config->withLogger($logger)
        );
    }

    public function withHeaders(array $headers): self
    {
        return $this->reconfigured(
            $this->config->withHeaders($headers)
        );
    }

    public function withTimeout(float $timeout): self
    {
        return $this->withTimeouts(new TimeoutConfig(
            connect: $timeout,
            request: $timeout,
            idle: $this->config->timeouts->idle
        ));
    }

    public function withTimeouts(TimeoutConfig $timeouts): self
    {
        return $this->reconfigured(
            $this->config->withTimeouts($timeouts)
        );
    }

    public function withVerify(bool $verify): self
    {
        return $this->reconfigured(
            $this->config->withVerify($verify)
        );
    }

    public function withRetry(int $times): self
    {
        return $this->withRetryPolicy(
            new RetryPolicy(
                maxAttempts: max(1, $times + 1)
            )
        );
    }

    public function withRetryPolicy(RetryPolicy $policy): self
    {
        return $this->reconfigured(
            $this->config->withRetryPolicy($policy)
        );
    }

    public function withUserAgent(?string $userAgent): self
    {
        $config = clone $this->config;
        $config->userAgent = $userAgent;

        return $this->reconfigured($config);
    }

    public function withQueryArrayFormat(string $format): self
    {
        $config = clone $this->config;
        $config->queryArrayFormat = $format;

        return $this->reconfigured($config);
    }
    /**
     * 注册自定义敏感 Header，防止其出现在日志和跨域请求中。
     *
     * @param list<string> $headers
     */
    public function withSensitiveHeaders(
        array $headers,
        bool $replace = false
    ): self {
        return $this->reconfigured(
            $this->config->withSensitiveHeaders(
                $headers,
                $replace
            )
        );
    }

    private function reconfigured(Config $config): self
    {
        $new = clone $this;
        $new->config = $config;
        $new->rebuild();

        return $new;
    }

    private function rebuild(): void
    {
        $this->preparer = new RequestPreparer($this->config);
        $this->syncTransport = new GuzzleTransport(
            $this->config->verify
        );
        $this->asyncTransport = new WorkermanTransport(
            $this->config->verify
        );
        $this->webSocketTransport =
            new WorkermanWebSocketTransport();
    }

    private function logRequest(
        string $method,
        string $url,
        array $headers,
        string $body,
        int $attempt
    ): void {
        $this->config->logger->debug('[HTTP] send', [
            'method' => $method,
            'url' => LogSanitizer::url($url),
            'headers' => LogSanitizer::headers(
                $headers,
                $this->config->sensitiveHeaders
            ),
            'body' => LogSanitizer::body(
                $body,
                Header::line($headers, 'Content-Type'),
                $this->config->logBodies
            ),
            'attempt' => $attempt,
        ]);
    }

    private function logResponse(Response $response): void
    {
        $this->config->logger->debug('[HTTP] receive', [
            'status' => $response->status,
            'url' => LogSanitizer::url($response->url),
            'headers' => LogSanitizer::headers(
                $response->headers,
                $this->config->sensitiveHeaders
            ),
            'duration_ms' => $response->durationMs,
            'attempts' => $response->attempts,
            'request_id' => $response->requestId(),
            'trace_id' => $response->traceId(),
        ]);
    }

    private static function normalizeConfig(array $options): Config
    {
        if (empty($options['base_uri'])) {
            throw new \InvalidArgumentException(
                'base_uri is required'
            );
        }

        $signer = $options['signer'] ?? match (true) {
            isset($options['api_key'], $options['api_secret']) =>
            new HmacSigner(
                (string)$options['api_key'],
                (string)$options['api_secret']
            ),
            isset($options['token']) =>
            new BearerSigner((string)$options['token']),
            isset($options['username'], $options['password']) =>
            new BasicSigner(
                (string)$options['username'],
                (string)$options['password']
            ),
            isset($options['inner_token']) =>
            new InnerSigner(
                (string)$options['inner_token']
            ),
            isset($options['api_key']) =>
            new ApiKeySigner(
                (string)$options['api_key'],
                (string)($options['header'] ?? 'X-API-Key')
            ),
            default => new NoneSigner(),
        };

        $timeout = max(
            0.1,
            (float)($options['timeout'] ?? 30)
        );

        $timeouts = TimeoutConfig::fromArray(
            (array)($options['timeouts'] ?? []),
            new TimeoutConfig(
                connect: min($timeout, 10),
                request: $timeout,
                idle: 180
            )
        );

        $retry = max(0, (int)($options['retry'] ?? 2));

        return new Config(
            baseUri: (string)$options['base_uri'],
            headers: Header::merge(
                (array)($options['headers'] ?? []),
                (array)($options['custom_headers'] ?? [])
            ),
            signer: $signer,
            logger: $options['logger'] instanceof LoggerInterface
                ? $options['logger']
                : new NullLogger(),
            verify: self::toBool(
                $options['verify'] ?? true,
                true
            ),
            timeouts: $timeouts,
            retryPolicy: new RetryPolicy(
                maxAttempts: $retry + 1
            ),
            queryArrayFormat:
            (string)($options['query_array_format']
                ?? 'brackets'),
            userAgent: isset($options['user_agent'])
                ? (string)$options['user_agent']
                : null,
            logBodies: self::toBool(
                $options['log_bodies'] ?? false,
                false
            ),
            maxResponseBytes:
            (int)($options['max_response_bytes']
                ?? 16_777_216),
            sensitiveHeaders: array_values(array_map(
                'strval',
                (array)($options['sensitive_headers'] ?? [])
            )),
        );
    }

    private static function toBool(
        mixed $value,
        bool $default
    ): bool {
        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower(trim((string)$value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }
}
