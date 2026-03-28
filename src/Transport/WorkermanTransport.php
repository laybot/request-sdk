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
    private GuzzleTransport $fallback;
    private ?LoggerInterface $logger;

    public function __construct(
        string $baseUri,
        float $timeout = 10.0,
        bool $verify = true,
        int $retryTimes = 2,
        ?LoggerInterface $logger = null
    ) {
        $this->baseUri = rtrim($baseUri, '/') . '/';
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
        $doneToken = array_key_exists('done_token', $decode) ? $decode['done_token'] : '[DONE]';

        $url = $this->absoluteUrl($uri);
        $parsed = parse_url($url);

        if (!$parsed || empty($parsed['host'])) {
            $this->fallback->stream($method, $uri, $options, $onChunk);
            return;
        }

        $headers = $options['headers'] ?? [];
        $body = (string)($options['body'] ?? '');
        $connectTimeout = (float)($options['connectTimeout'] ?? 10);
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

        $buffer = '';
        $headerDone = false;
        $ended = false;
        $statusChecked = false;
        $statusCode = 0;
        $rawHeader = '';

        $end = function () use (&$idleTimer, &$ended, $onChunk, $connId): void {
            if ($ended) {
                return;
            }
            $ended = true;

            if ($idleTimer !== null) {
                Timer::del($idleTimer);
                $idleTimer = null;
            }

            unset(self::$pool[$connId]);
            $onChunk('', true);
        };

        $fail = function (string $message) use (&$idleTimer, &$ended, $connId): void {
            if ($ended) {
                return;
            }
            $ended = true;

            if ($idleTimer !== null) {
                Timer::del($idleTimer);
                $idleTimer = null;
            }

            unset(self::$pool[$connId]);
            throw new StreamException($message);
        };

        $emitLine = static function (string $line) use ($mode, $doneToken, $onChunk): void {
            if ($mode === 'raw-line') {
                if ($line === '') {
                    return;
                }

                if ($doneToken !== null && $line === $doneToken) {
                    $onChunk('', true);
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
                $onChunk('', true);
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
            &$statusChecked,
            &$statusCode,
            &$rawHeader,
            $emitLine,
            $touch,
            $fail,
            $end
        ): void {
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
            }

            if (!$statusChecked) {
                $statusChecked = true;
                $lines = explode("\r\n", $rawHeader);
                $statusLine = $lines[0] ?? '';

                if (!preg_match('#^HTTP/\d+\.\d+\s+(\d{3})#', $statusLine, $m)) {
                    $fail('invalid stream response status line');
                    return;
                }

                $statusCode = (int)$m[1];
                if ($statusCode < 200 || $statusCode >= 300) {
                    $fail('stream http ' . $statusCode);
                    return;
                }
            }

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    continue;
                }

                $beforeDone = $buffer;
                $emitLine($line);

                // 若上面触发了 done，连接会在服务端关闭；这里不主动 close，避免回调重入
                if ($beforeDone !== $buffer) {
                    // no-op，仅保留结构一致性
                }
            }
        };

        $conn->onClose = static function () use ($end): void {
            $end();
        };

        $conn->onError = static function ($connection, $code = 0, $msg = '') use ($fail): void {
            $message = 'stream connection error';
            if ($code || $msg !== '') {
                $message .= " [{$code}] {$msg}";
            }
            $fail($message);
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
}
