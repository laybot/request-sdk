<?php
declare(strict_types=1);

namespace LayBot\Stream;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/** 最低延迟的 SSE 传输：基于 Workerman 事件循环 */
final class WorkermanTransport implements Transport
{
    /**
     * @inheritDoc
     */
    public function post(
        string   $url,
        string   $json,
        array    $headers,
        int      $connectTimeout,
        int      $idleTimeout,
        callable $onFrame
    ): void
    {
        /* ---------- URL 解析 & TCP 地址 ---------- */
        $u    = parse_url($url);
        $ssl  = ($u['scheme'] ?? 'http') === 'https';
        $addr = 'tcp://' . $u['host'] . ':' . ($u['port'] ?? ($ssl ? 443 : 80));
        $path = ($u['path'] ?? '/') . (isset($u['query']) && $u['query'] !== '' ? '?' . $u['query'] : '');

        /* ---------- 组装 HTTP 请求报文 ---------- */
        $req  = "POST $path HTTP/1.1\r\n";
        $req .= "Host: {$u['host']}\r\n";
        $req .= "Connection: keep-alive\r\n";
        foreach ($headers as $k => $v) {
            $req .= (is_int($k) ? $v : "$k: $v") . "\r\n";
        }
        $req .= "Content-Length: " . strlen($json) . "\r\n\r\n";
        $req .= $json;

        /* ---------- 建立 AsyncTcpConnection ---------- */
        $conn = new AsyncTcpConnection($addr);
        if ($ssl) $conn->transport = 'ssl';
        $conn->connectTimeout = $connectTimeout;

        /* ---------- idle 超时计时器 ---------- */
        $idleTimer = null;
        $touchIdle = static function () use (&$idleTimer, $idleTimeout, $conn) {
            if ($idleTimeout <= 0) return;
            if ($idleTimer) Timer::del($idleTimer);
            $idleTimer = Timer::add($idleTimeout, static fn () => $conn->close(), [], false);
        };
        /* ---------- 状态缓存 ---------- */
        $headerDone = false;    // 是否已丢弃响应头
        $buffer     = '';       // 粘包缓冲区

        /* ---------- 事件回调 ---------- */
        $conn->onConnect = static function ($c) use ($req, $touchIdle) {
            $c->send($req);
            $touchIdle();
        };

        $conn->onMessage = static function ($c, string $chunk) use (&$buffer, &$headerDone, $onFrame, $touchIdle)
        {
            $touchIdle();
            $buffer .= $chunk;

            /* 丢掉响应头（可能粘包/半包） */
            if (!$headerDone) {
                $pos = strpos($buffer, "\r\n\r\n");
                if ($pos === false) return;
                $buffer     = substr($buffer, $pos + 4);
                $headerDone = true;
            }

            /* 逐行解析 data: 帧 */
            while (($nlPos = strpos($buffer, "\n")) !== false) {
                $line   = trim(substr($buffer, 0, $nlPos));
                $buffer = substr($buffer, $nlPos + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) continue;
                $payload = trim(substr($line, 5));
                if ($payload === '') continue;

                $onFrame($payload === '[DONE]' ? '' : $payload, $payload === '[DONE]');
            }
        };

        // 关闭 & 错误：都当成 done 事件
        $onEnd = static function () use (&$idleTimer, $onFrame) {
            if ($idleTimer) Timer::del($idleTimer);
            $onFrame('', true);
        };

        $conn->onClose = $onEnd;
        $conn->onError = static function ($c, $code, $msg) use ($onEnd) {
            $onEnd();
        };

        $conn->connect();

        /* 防止被 GC 回收 */
        static $pool = [];
        $pool[spl_object_id($conn)] = $conn;
    }
}
