<?php
declare(strict_types=1);
namespace LayBot\Stream;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/** Workerman 事件循环下的超低延迟实现 */
final class WorkermanTransport implements Transport
{
    public function post(string $url, string $json, array $headers,
                         int $timeout, callable $onFrame): void
    {
        $u=parse_url($url); $ssl=$u['scheme']==='https';
        $addr  = ($ssl ? 'tls' : 'tcp').'://'.$u['host'].':'.($u['port'] ?? ($ssl ? 443 : 80));
        $query = isset($u['query']) && $u['query'] !== '' ? '?'.$u['query'] : '';
        $path  = ($u['path'] ?? '/') . $query;


        /* 构造 HTTP 请求行+头 */
        $hdr="POST $path HTTP/1.1\r\nHost: {$u['host']}\r\nConnection: close\r\n".
            "Accept: text/event-stream\r\n".
            "Content-Length: ".strlen($json)."\r\n";
        foreach ($headers as $k=>$v) {
            $hdr.= (is_int($k)?$v:"$k: $v")."\r\n";
        }
        $conn=new AsyncTcpConnection($addr);
        if($ssl)$conn->transport='ssl';
        $conn->onConnect=fn($c)=>$c->send($hdr."\r\n".$json);

        $headerRead=false;
        $timer=Timer::add($timeout,fn()=>$conn->close(),[],false);

        $conn->onMessage=function($c,$buf) use (&$headerRead,$onFrame){
            /* 跳过响应头 */
            if(!$headerRead){
                $pos=strpos($buf,"\r\n\r\n");
                if($pos===false)return;
                $buf=substr($buf,$pos+4);
                $headerRead=true;
            }
            /* buf 里可能有多行 SSE */
            foreach (explode("\n",$buf) as $line){
                $line=trim($line); if(!str_starts_with($line,'data:')) continue;
                $payload=trim(substr($line,5));
                if($payload==='[DONE]'){ $onFrame('',true); continue; }
                $onFrame($payload,false);
            }
        };
        $conn->onClose = function() use($timer,$onFrame){
            Timer::del($timer);
            $onFrame('', true);          // 通知 Chat 流结束
        };
        $conn->onError = function() use($timer,$onFrame){
            Timer::del($timer);
            $onFrame('', true);
        };
        $conn->connect();
        /* ────── 防止连接对象被垃圾回收 ────── */
        static $pool = [];                               // ① 声明静态池
        $pool[spl_object_id($conn)] = $conn;             // ② 保存强引用
    }
}