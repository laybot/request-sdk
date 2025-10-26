<?php
declare(strict_types=1);

namespace LayBot\Request\Transport;

use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Exception\HttpException;
use LayBot\Request\Exception\StreamException;
use LayBot\Request\Util\StreamDecoder;
use GuzzleHttp\Psr7\Utils;
use Psr\Log\LoggerInterface;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/**
 * Workerman 事件循环下的低延迟流式传输。
 * 若当前不在事件循环或未安装 workerman，则自动回退 GuzzleTransport。
 */
final class WorkermanTransport implements TransportInterface
{
    private GuzzleTransport $fallback;

    public function __construct(
        string $baseUri,
        float  $timeout,
        bool   $verify,
        int    $retry,
        LoggerInterface $logger
    ){
        $this->fallback = new GuzzleTransport($baseUri,$timeout,$verify,$retry,$logger);
    }

    /* ---------- 普通 HTTP 一律走 Guzzle ---------- */
    public function request(string $method, string $uri, array $opt): array
    {
        return $this->fallback->request($method, $uri, $opt);
    }

    /* ---------- 流式 ---------- */
    public function stream(string $method,string $url,array $opt,callable $onChunk): void
    {
        // 如果没有事件循环，直接 fallback
        if (!class_exists(\Workerman\Worker::class, false)
            || empty(\Workerman\Worker::getAllWorkers())) {
            $this->fallback->stream($method,$url,$opt,$onChunk);
            return;
        }

        /* -------------------- AsyncTcpConnection 实现 -------------------- */
        $json      = $opt['body']    ?? '';
        $headers   = $opt['headers'] ?? [];
        $connectT  = $opt['connectTimeout'] ?? 10;
        $idleT     = $opt['idleTimeout']    ?? 180;

        /* URL 解析 */
        $u    = parse_url($url);
        $ssl  = ($u['scheme'] ?? 'http') === 'https';
        $addr = 'tcp://' . $u['host'] . ':' . ($u['port'] ?? ($ssl ? 443 : 80));
        $path = ($u['path'] ?? '/') . (isset($u['query']) && $u['query'] !== '' ? '?' . $u['query'] : '');

        /* 拼 HTTP 报文 */
        $req  = "POST $path HTTP/1.1\r\n";
        $req .= "Host: {$u['host']}\r\n";
        $req .= "Connection: keep-alive\r\n";
        foreach ($headers as $k => $v) {
            $req .= (is_int($k) ? $v : "$k: $v") . "\r\n";
        }
        $req .= "Content-Length: " . strlen($json) . "\r\n\r\n";
        $req .= $json;

        $conn = new AsyncTcpConnection($addr);
        if ($ssl) $conn->transport = 'ssl';
        $conn->connectTimeout = $connectT;

        /* idle timer */
        $idleTimer = null;
        $touch = static function() use (&$idleTimer,$idleT,$conn){
            if($idleT<=0) return;
            $idleTimer && Timer::del($idleTimer);
            $idleTimer = Timer::add($idleT, static fn()=>$conn->close(),[],false);
        };

        /* 数据处理 */
        $buf=''; $headerDone=false;
        $conn->onConnect = static function($c) use ($req,$touch){ $c->send($req); $touch(); };
        $conn->onMessage = static function($c,string $chunk) use (&$buf,&$headerDone,$onChunk,$touch)
        {
            $touch(); $buf.=$chunk;
            if(!$headerDone && ($p=strpos($buf,"\r\n\r\n"))!==false){
                $buf=substr($buf,$p+4); $headerDone=true;
            }
            while(($n=strpos($buf,"\n"))!==false){
                $line=trim(substr($buf,0,$n)); $buf=substr($buf,$n+1);
                if($line===''||!str_starts_with($line,'data:')) continue;
                $payload=trim(substr($line,5));
                $onChunk($payload==='[DONE]'?'':$payload,$payload==='[DONE]');
            }
        };
        $end = static function() use (&$idleTimer,$onChunk){
            $idleTimer && Timer::del($idleTimer);
            $onChunk('',true);
        };
        $conn->onClose = $end;
        $conn->onError = static function() use ($end){ $end(); };

        $conn->connect();

        /* 防 GC */
        static $pool=[]; $pool[spl_object_id($conn)]=$conn;
    }
}
