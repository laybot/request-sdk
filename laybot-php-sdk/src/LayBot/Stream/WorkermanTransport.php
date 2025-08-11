<?php
declare(strict_types=1);
namespace LayBot\Stream;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/** 最低延迟的 SSE 传输：基于 Workerman 事件循环 */
final class WorkermanTransport implements Transport
{
    public function post(
        string   $url,
        string   $json,
        array    $headers,
        int      $connectTimeout,
        int      $idleTimeout,
        callable $onFrame
    ): void
    {
        $u    = parse_url($url);
        $ssl  = $u['scheme'] === 'https';
        $addr = ($ssl ? 'tls' : 'tcp') . '://' . $u['host'] . ':' .
            ($u['port'] ?? ($ssl ? 443 : 80));
        $query = isset($u['query']) && $u['query'] !== '' ? '?' . $u['query'] : '';
        $path  = ($u['path'] ?? '/') . $query;
        $host = $u['host'] ?? 'localhost';
        /* ============ 组装 HTTP 请求头 ============ */
        $hdr  = "POST {$path} HTTP/1.1\r\n";
        $hdr .= "Host: {$host}\r\n";
        $hdr .= "Connection: keep-alive\r\n";
        $hdr .= "Accept: text/event-stream\r\n";
        $hdr .= "Accept-Encoding: identity\r\n";             // 禁止 gzip，Workerman 无自动解压
        $hdr .= "Content-Type: application/json\r\n";
        $hdr .= "Content-Length: " . strlen($json) . "\r\n";
        foreach ($headers as $k => $v) {
            $hdr .= (is_int($k) ? $v : "$k: $v") . "\r\n";
        }
        /* ---------- 建连 ---------- */
        $conn = new AsyncTcpConnection($addr);
        if ($ssl) {
            $conn->transport = 'ssl';
        }
        $conn->connectTimeout = $connectTimeout;

        /* ---------- 关闭 / 超时 统一出口 ---------- */
        $idleTimer  = null;
        $closed     = false;
        $doneCalled = false;
        $finish = static function() use (&$doneCalled,$onFrame,&$idleTimer,&$closed){
            if ($doneCalled) return;
            $doneCalled = true;
            if ($idleTimer) Timer::del($idleTimer);
            $onFrame('', true);      // 通知上层流结束
            $closed = true;
        };

        $resetIdle = static function() use (&$idleTimer,$idleTimeout,$conn,$finish){
            if ($idleTimeout <= 0) return;
            if ($idleTimer) Timer::del($idleTimer);
            $idleTimer = Timer::add($idleTimeout, static function() use ($conn,$finish){
                $conn->close();      // 主动断开
                $finish();
            }, [], false);
        };
        /* ============ 缓冲区 ============ */
        $headerDone = false;
        $hdrBuf     = '';
        $bodyBuf    = '';
        $evtBuf     = '';            // 当前 SSE 事件 data 累加

        $emitData = static function(string $payload) use ($onFrame){
            if ($payload === '[DONE]') {
                // 真正结束由 finish() 统一处理
                return;
            }
            $onFrame($payload, false);
        };
        /* ============ 事件回调 ============ */
        $conn->onConnect = static function($c) use ($hdr,$json,$resetIdle){
            $c->send($hdr . "\r\n" . $json);
            $resetIdle();
        };
        $conn->onMessage = static function($c, $chunk) use (
            &$headerDone,&$hdrBuf,&$bodyBuf,&$evtBuf,
            $emitData,$resetIdle,$finish
        ){
            if ($chunk === '' || $chunk === null) return;

            /* -------- 头部解析 -------- */
            if (!$headerDone) {
                $hdrBuf .= $chunk;
                $pos = strpos($hdrBuf, "\r\n\r\n");
                if ($pos === false) {
                    // 头还没收全
                    return;
                }
                // 头已结束，取出多余正文部分
                $bodyBuf     = substr($hdrBuf, $pos + 4);
                $hdrBuf      = '';
                $headerDone  = true;
                $resetIdle();
            } else {
                $bodyBuf .= $chunk;
                $resetIdle();
            }

            /* -------- SSE 正文解析 -------- */
            while (true) {
                $nlPos = strpos($bodyBuf, "\n");
                if ($nlPos === false) break;

                $line   = rtrim(substr($bodyBuf, 0, $nlPos), "\r");
                $bodyBuf = substr($bodyBuf, $nlPos + 1);

                // 空行 ⇒ 事件结束
                if ($line === '') {
                    if ($evtBuf !== '') {
                        $emitData($evtBuf);
                        $evtBuf = '';
                    }
                    continue;
                }
                // 忽略注释
                if ($line[0] === ':') continue;

                // 仅处理 data: 前缀
                if (stripos($line, 'data:') === 0) {
                    $dataPart = ltrim(substr($line, 5));
                    $evtBuf   = $evtBuf === '' ? $dataPart : ($evtBuf . "\n" . $dataPart);
                }
            }
        };

        $onCloseOrError = static function() use (&$evtBuf,$emitData,$finish,&$closed){
            if ($closed) return;
            if ($evtBuf !== '') {
                $emitData($evtBuf);   // 残留最后事件
                $evtBuf = '';
            }
            $finish();
        };

        $conn->onClose = $onCloseOrError;
        $conn->onError = $onCloseOrError;

        $conn->connect();

        // 防止被 GC 回收
        static $pool = [];
        $pool[spl_object_id($conn)] = $conn;
    }
}