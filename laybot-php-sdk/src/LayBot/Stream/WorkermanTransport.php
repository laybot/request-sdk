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

        /* 请求行 + 头 */
        $hdr  = "POST $path HTTP/1.1\r\nHost: {$u['host']}\r\nConnection: keep-alive\r\n";
        $hdr .= "Accept: text/event-stream\r\n";
        $hdr .= "Content-Length: " . strlen($json) . "\r\n";
        foreach ($headers as $k => $v) {
            $hdr .= (is_int($k) ? $v : "$k: $v") . "\r\n";
        }
        /* ---------- 建连 ---------- */
        $conn = new AsyncTcpConnection($addr);
        if ($ssl) $conn->transport = 'ssl';
        $conn->connectTimeout = $connectTimeout;

        /* ---------- idle 定时 ---------- */
        $idleTimer = null;
        $resetIdle = static function() use (&$idleTimer,$idleTimeout,$conn){
            if ($idleTimeout<=0) return;
            if ($idleTimer) Timer::del($idleTimer);
            $idleTimer = Timer::add($idleTimeout, static fn()=>$conn->close(), [], false);
        };

        $headerDone = false;

        $conn->onConnect = static function($c) use ($hdr,$json,$resetIdle){
            $c->send($hdr."\r\n".$json);
            $resetIdle();
        };

        $conn->onMessage = static function($c,$buf) use (&$headerDone,$onFrame,$resetIdle){
            if(!$headerDone){
                $pos=strpos($buf,"\r\n\r\n");
                if($pos===false) return;
                $buf        = substr($buf,$pos+4);
                $headerDone = true;
            }
            $resetIdle();
            foreach (explode("\n",$buf) as $line){
                $line = trim($line);
                if (!str_starts_with($line,'data:')) continue;
                $payload = trim(substr($line,5));
                if ($payload === '[DONE]'){ $onFrame('',true); continue; }
                $onFrame($payload,false);
            }
        };

        $close = static function() use (&$idleTimer,$onFrame){
            if ($idleTimer) Timer::del($idleTimer);
            $onFrame('',true);
        };
        $conn->onClose = $close;
        $conn->onError = $close;

        $conn->connect();

        /* 防 GC */
        static $pool=[];
        $pool[spl_object_id($conn)]=$conn;
    }
}