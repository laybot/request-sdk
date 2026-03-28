<?php
declare(strict_types=1);

namespace LayBot\Request\Transport;

use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Exception\StreamException;
use LayBot\Request\Support\Env;
use Psr\Log\LoggerInterface;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

final class WorkermanTransport implements TransportInterface
{
    /**
     * 持有活跃连接，避免被 GC 提前回收
     *
     * @var array<int, AsyncTcpConnection>
     */
    private static array $pool = [];

    private string $baseUri;
    private float $timeout;
    private bool $verify;
    private int $retryTimes;
    private ?LoggerInterface $logger;
    private GuzzleTransport $fallback;

    public function __construct(
        string $baseUri,
        float $timeout = 10.0,
        bool $verify = true,
        int $retryTimes = 2,
        ?LoggerInterface $logger = null
    ) {
        $this->baseUri = rtrim($baseUri, '/') . '/';
        $this->timeout = $timeout;
        $this->verify = $verify;
        $this->retryTimes = $retryTimes;
        $this->logger = $logger;
        $this->fallback = new GuzzleTransport(
            $baseUri,
            $timeout,
            $verify,
            $retryTimes,
            $logger
        );
    }

    public function request(string $method, string $uri, array $options): array
    {
        return $this->fallback->request($method, $uri, $options);
    }

    public function stream(string $method, string $uri, array $options, callable $onChunk): void
    {
        if (!Env::inWorkermanLoop()) {
            $this->fallback->stream($method, $uri, $options, $onChunk);
            return;
        }

        $decode = (array)($options['decode'] ?? []);
        unset($options['decode']);

        $mode = strtolower(trim((string)($decode['mode'] ?? 'data-line')));
        if (!in_array($mode, ['data-line', 'raw-line'], true)) {
            $mode = 'data-line';
        }

        $doneToken = array_key_exists('done_token', $decode) ? $decode['done_token'] : '[DONE]';

        $url = $this->absoluteUrl($uri);
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            $this->fallback->stream($method, $uri, $options + ['decode' => $decode], $onChunk);
            return;
        }

        $headers = $options['headers'] ?? [];
        $body = (string)($options['body'] ?? '');
        $connectTimeout = (float)($options['connectTimeout'] ?? $this->timeout);
        $idleTimeout = (int)($options['idleTimeout'] ?? 180);

        $scheme = strtolower((string)($parsed['scheme'] ?? 'http'));
        $ssl = $scheme === 'https';
        $host = (string)$parsed['host'];
        $port = (int)($parsed['port'] ?? ($ssl ? 443 : 80));
        $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

        $addr = 'tcp://' . $host . ':' . $port;

        $request = strtoupper($method) . " {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Connection: close\r\n";

        foreach ($headers as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $vv) {
                    $request .= "{$k}: {$vv}\r\n";
                }
            } else {
                $request .= "{$k}: {$v}\r\n";
            }
        }

        if ($body !== '') {
            $request .= 'Content-Length: ' . strlen($body) . "\r\n";
        }

        $request .= "\r\n" . $body;

        $conn = new AsyncTcpConnection($addr);
        if ($ssl) {
            $conn->transport = 'ssl';
        }
        $conn->connectTimeout = $connectTimeout;

        $connId = spl_object_id($conn);
        self::$pool[$connId] = $conn;

        $idleTimer = null;
        $buffer = '';
        $headerDone = false;
        $ended = false;
        $doneEmitted = false;
        $rawHeader = '';
        $responseHeaders = [];
        $statusCode = 0;
        $fallbackMode = false;

        $cleanup = static function () use (&$idleTimer, $connId): void {
            if ($idleTimer !== null) {
                Timer::del($idleTimer);
                $idleTimer = null;
            }
            unset(self::$pool[$connId]);
        };

        $touch = static function () use (&$idleTimer, $idleTimeout, $conn): void {
            if ($idleTimeout <= 0) {
                return;
            }
            if ($idleTimer !== null) {
                Timer::del($idleTimer);
            }
            $idleTimer = Timer::add($idleTimeout, static function () use ($conn) {
                $conn->close();
            }, [], false);
        };

        $end = function (bool $emitDone = true) use (&$ended, &$doneEmitted, $cleanup, $onChunk): void {
            if ($ended) {
                return;
            }
            $ended = true;
            $cleanup();

            if ($emitDone && !$doneEmitted) {
                $doneEmitted = true;
                $onChunk('', true);
            }
        };

        $log = function (string $level, string $message, array $context = []) : void {
            if ($this->logger === null) {
                return;
            }
            try {
                $this->logger->log($level, $message, $context);
            } catch (\Throwable) {
                // ignore logger failure
            }
        };

        $fallbackToGuzzle = function (string $reason) use (
            &$fallbackMode,
            &$ended,
            $cleanup,
            $method,
            $uri,
            $options,
            $decode,
            $onChunk,
            $log
        ): void {
            if ($fallbackMode || $ended) {
                return;
            }

            $fallbackMode = true;
            $ended = true;
            $cleanup();

            $log('warning', '[stream] workerman fallback to guzzle', [
                'reason' => $reason,
                'uri' => $uri,
            ]);

            $this->fallback->stream($method, $uri, $options + ['decode' => $decode], $onChunk);
        };

        $emitLine = function (string $line) use ($mode, $doneToken, $onChunk, &$doneEmitted, $conn, $end): void {
            if ($mode === 'raw-line') {
                if ($line === '') {
                    return;
                }

                if ($doneToken !== null && $line === $doneToken) {
                    if (!$doneEmitted) {
                        $doneEmitted = true;
                        $onChunk('', true);
                    }
                    $conn->close();
                    return;
                }

                $onChunk($line, false);
                return;
            }

            if ($line === '' || !str_starts_with($line, 'data:')) {
                return;
            }

            $payload = trim(substr($line, 5));

            if ($doneToken !== null && $payload === $doneToken) {
                if (!$doneEmitted) {
                    $doneEmitted = true;
                    $onChunk('', true);
                }
                $conn->close();
                return;
            }

            if ($payload === '') {
                return;
            }

            $onChunk($payload, false);
        };

        $conn->onConnect = static function ($connection) use ($request, $touch): void {
            $connection->send($request);
            $touch();
        };

        $conn->onMessage = function ($connection, string $chunk) use (
            &$buffer,
            &$headerDone,
            &$rawHeader,
            &$responseHeaders,
            &$statusCode,
            &$fallbackMode,
            $touch,
            $emitLine,
            $fallbackToGuzzle,
            $log
        ): void {
            if ($fallbackMode) {
                return;
            }

            $touch();
            $buffer .= $chunk;

            if (!$headerDone) {
                $pos = strpos($buffer, "\r\n\r\n");
                if ($pos === false) {
                    return;
                }

                $rawHeader = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 4);
                $headerDone = true;

                [$statusCode, $responseHeaders] = $this->parseResponseHeader($rawHeader);

                if ($statusCode < 200 || $statusCode >= 300) {
                    $fallbackToGuzzle('non-2xx-stream-response');
                    return;
                }

                if ($this->shouldFallbackByHeaders($responseHeaders)) {
                    $fallbackToGuzzle('complex-stream-headers');
                    return;
                }

                $contentType = strtolower($responseHeaders['content-type'][0] ?? '');
                if ($contentType !== '' && !$this->isSafeTextStreamContentType($contentType)) {
                    $fallbackToGuzzle('unsupported-content-type');
                    return;
                }

                $log('debug', '[stream] workerman stream accepted', [
                    'status' => $statusCode,
                    'headers' => $responseHeaders,
                ]);
            }

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                $emitLine($line);
            }
        };

        $conn->onClose = function () use (&$fallbackMode, $end): void {
            if ($fallbackMode) {
                return;
            }
            $end(true);
        };

        $conn->onError = function ($connection, $code = 0, $msg = '') use (&$fallbackMode, $fallbackToGuzzle, $log, $end): void {
            if ($fallbackMode) {
                return;
            }

            $log('warning', '[stream] workerman connection error', [
                'code' => $code,
                'message' => $msg,
            ]);

            // 连接阶段/协议阶段异常，优先回退
            $fallbackToGuzzle('connection-error');

            // 若 fallback 未接管，则至少结束回调
            $end(true);
        };

        try {
            $conn->connect();
        } catch (\Throwable $e) {
            unset(self::$pool[$connId]);
            throw new StreamException('stream connect failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function absoluteUrl(string $uri): string
    {
        if (preg_match('#^https?://#i', $uri)) {
            return $uri;
        }

        return $this->baseUri . ltrim($uri, '/');
    }

    /**
     * @return array{0:int,1:array<string,array<int,string>>}
     */
    private function parseResponseHeader(string $rawHeader): array
    {
        $lines = explode("\r\n", $rawHeader);
        $statusLine = array_shift($lines) ?? '';

        if (!preg_match('#^HTTP/\d+\.\d+\s+(\d{3})#', $statusLine, $m)) {
            throw new StreamException('invalid stream response status line');
        }

        $status = (int)$m[1];
        $headers = [];

        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));

            if ($name === '') {
                continue;
            }

            $headers[$name][] = $value;
        }

        return [$status, $headers];
    }

    /**
     * 发现复杂编码时，直接回退到 Guzzle
     *
     * @param array<string,array<int,string>> $headers
     */
    private function shouldFallbackByHeaders(array $headers): bool
    {
        $transferEncoding = strtolower(implode(',', $headers['transfer-encoding'] ?? []));
        if ($transferEncoding !== '' && str_contains($transferEncoding, 'chunked')) {
            return true;
        }

        $contentEncoding = strtolower(implode(',', $headers['content-encoding'] ?? []));
        foreach (['gzip', 'deflate', 'br'] as $encoding) {
            if ($contentEncoding !== '' && str_contains($contentEncoding, $encoding)) {
                return true;
            }
        }

        return false;
    }

    private function isSafeTextStreamContentType(string $contentType): bool
    {
        foreach ([
                     'text/event-stream',
                     'application/json',
                     'application/x-ndjson',
                     'text/plain',
                 ] as $allowed) {
            if (str_contains($contentType, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
