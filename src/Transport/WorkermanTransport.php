<?php
declare(strict_types=1);

namespace LayBot\Request\Transport;

use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Support\Env;
use Psr\Log\LoggerInterface;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

final class WorkermanTransport implements TransportInterface
{
    /**
     * 持有活跃连接，避免被 GC 提前回收
     * 必须在 onClose/onError 中及时释放，避免长驻进程内存泄漏
     *
     * @var array<int, AsyncTcpConnection>
     */
    private static array $pool = [];

    private GuzzleTransport $fallback;

    public function __construct(
        string $baseUri,
        float $timeout = 10.0,
        bool $verify = true,
        int $retryTimes = 2,
        ?LoggerInterface $logger = null
    ) {
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

    public function stream(string $method, string $url, array $options, callable $onChunk): void
    {
        if (!Env::inWorkermanLoop()) {
            $this->fallback->stream($method, $url, $options, $onChunk);
            return;
        }

        $headers = $options['headers'] ?? [];
        $body = (string)($options['body'] ?? '');
        $connectTimeout = (float)($options['connectTimeout'] ?? 10);
        $idleTimeout = (int)($options['idleTimeout'] ?? 180);

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            $this->fallback->stream($method, $url, $options, $onChunk);
            return;
        }

        $scheme = $parsed['scheme'] ?? 'http';
        $ssl = $scheme === 'https';
        $host = $parsed['host'];
        $port = $parsed['port'] ?? ($ssl ? 443 : 80);
        $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

        $addr = 'tcp://' . $host . ':' . $port;

        $request = strtoupper($method) . " {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Connection: keep-alive\r\n";

        foreach ($headers as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $vv) {
                    $request .= "{$k}: {$vv}\r\n";
                }
            } else {
                $request .= "{$k}: {$v}\r\n";
            }
        }

        $request .= 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;

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

        $end = static function () use (&$idleTimer, &$ended, $onChunk, $connId): void {
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

        $conn->onConnect = static function ($connection) use ($request, $touch): void {
            $connection->send($request);
            $touch();
        };

        $conn->onMessage = static function ($connection, string $chunk) use (&$buffer, &$headerDone, $onChunk, $touch): void {
            $touch();
            $buffer .= $chunk;

            if (!$headerDone) {
                $pos = strpos($buffer, "\r\n\r\n");
                if ($pos === false) {
                    return;
                }
                $buffer = substr($buffer, $pos + 4);
                $headerDone = true;
            }

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));

                if ($payload === '[DONE]') {
                    $connection->close();
                    return;
                }

                $onChunk($payload, false);
            }
        };

        $conn->onClose = $end;
        $conn->onError = static function () use ($end): void {
            $end();
        };

        $conn->connect();
    }
}
