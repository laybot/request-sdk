<?php
declare(strict_types=1);

namespace LayBot\Request\Transport;

use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Support\Env;
use Psr\Log\LoggerInterface;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/**
 * 在 Workerman/Webman 事件循环中使用真·低延迟流式；
 * 非事件循环场景自动回退到 GuzzleTransport。
 */
final class WorkermanTransport implements TransportInterface
{
    private GuzzleTransport $fallback;

    public function __construct(
        string            $baseUri,
        float             $timeout     = 10.0,
        bool              $verify      = false,
        int               $retryTimes  = 2,
        ?LoggerInterface  $logger      = null
    ) {
        $this->fallback = new GuzzleTransport($baseUri, $timeout, $verify, $retryTimes, $logger);
    }

    /* ------------ 普通请求：全部走 Guzzle ------------ */
    public function request(string $method, string $uri, array $opt): array
    {
        return $this->fallback->request($method, $uri, $opt);
    }

    /* ------------ 流式请求 ------------ */
    public function stream(string $method, string $url, array $opt, callable $onChunk): void
    {
        if (!Env::inWorkermanLoop()) {
            /* 没事件循环 → 回退 Guzzle 流实现 */
            $this->fallback->stream($method, $url, $opt, $onChunk);
            return;
        }

        /* -------- 真·workerman 流式 -------- */
        $headers    = $opt['headers']        ?? [];
        $body       = $opt['body']           ?? '';
        $connectT   = $opt['connectTimeout'] ?? 10;
        $idleT      = $opt['idleTimeout']    ?? 180;

        /* 解析 URL */
        $p     = parse_url($url);
        $ssl   = ($p['scheme'] ?? 'http') === 'https';
        $addr  = 'tcp://' . $p['host'] . ':' . ($p['port'] ?? ($ssl ? 443 : 80));
        $path  = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');

        /* 拼装 HTTP 报文 */
        $req  = "$method $path HTTP/1.1\r\n";
        $req .= "Host: {$p['host']}\r\nConnection: keep-alive\r\n";
        foreach ($headers as $k => $v) {
            $req .= (is_int($k) ? $v : "$k: $v") . "\r\n";
        }
        $req .= "Content-Length: " . strlen($body) . "\r\n\r\n" . $body;

        /* 建立异步连接 */
        $conn = new AsyncTcpConnection($addr);
        if ($ssl) $conn->transport = 'ssl';
        $conn->connectTimeout = $connectT;

        /* idle 超时检测 */
        $idleTimer = null;
        $touch = static function() use (&$idleTimer, $idleT, $conn) {
            if ($idleT <= 0) return;
            $idleTimer && Timer::del($idleTimer);
            $idleTimer = Timer::add($idleT, static fn() => $conn->close(), [], false);
        };

        /* 处理响应流 */
        $buffer = '';  $headerDone = false;
        $conn->onConnect = static function($c) use ($req, $touch) {
            $c->send($req); $touch();
        };
        $conn->onMessage = static function($c, string $chunk) use (&$buffer,&$headerDone,$onChunk,$touch) {
            $touch();
            $buffer .= $chunk;

            /* 去掉响应头 */
            if (!$headerDone && ($p = strpos($buffer, "\r\n\r\n")) !== false) {
                $buffer = substr($buffer, $p + 4);
                $headerDone = true;
            }

            /* 按行切 data: ...\n */
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || !str_starts_with($line, 'data:')) continue;

                $payload = trim(substr($line, 5));
                $finish  = $payload === '[DONE]';
                $onChunk($finish ? '' : $payload, $finish);
            }
        };
        $end = static function() use (&$idleTimer, $onChunk) {
            $idleTimer && Timer::del($idleTimer);
            $onChunk('', true);
        };
        $conn->onClose = $end;
        $conn->onError = static function() use ($end) { $end(); };
        $conn->connect();

        /* 防止 GC */
        static $pool = []; $pool[spl_object_id($conn)] = $conn;
    }
}
