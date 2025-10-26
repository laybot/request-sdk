<?php
declare(strict_types=1);
namespace LayBot\Request\Transport;

use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Util\StreamDecoder;
use LayBot\Request\Exception\HttpException;
use LayBot\Request\Exception\StreamException;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

final class WorkermanTransport implements TransportInterface
{
    public function request(string $m,string $url,array $opt): array
    {
        $done = false; $ret = [];
        $cli  = new \Workerman\Http\Client;
        $cli->request($m,$url,$opt,function($res) use(&$done,&$ret){
            $ret = $res; $done=true;
        });
        // yield until callback
        while(!$done){ \Swoole\Coroutine::sleep(0.001); }
        if($ret['status']>=400){
            throw new HttpException('HTTP '.$ret['status'],$ret['status'],$ret['body']);
        }
        return ['status'=>$ret['status'],'body'=>$ret['body'],'headers'=>$ret['headers']];
    }

    /** 完全复用你在 ai-sdk 中的实现，支持 idleTimeout */
    public function stream(string $method, string $url, array $opt, callable $onChunk): void
    {
        $json      = $opt['body'] ?? '';
        $headers   = $opt['headers'] ?? [];
        $connectT  = $opt['connectTimeout'] ?? 10;
        $idleT     = $opt['idleTimeout'] ?? 180;

        /* ---------- URL 解析 & TCP 地址 ---------- */
        $u    = parse_url($url);
        $ssl  = ($u['scheme'] ?? 'http') === 'https';
        $addr = 'tcp://' . $u['host'] . ':' . ($u['port'] ?? ($ssl ? 443 : 80));
        $path = ($u['path'] ?? '/') . (isset($u['query']) && $u['query'] !== '' ? '?' . $u['query'] : '');

        /* ---------- HTTP 报文 ---------- */
        $req  = "POST $path HTTP/1.1\r\n";
        $req .= "Host: {$u['host']}\r\n";
        $req .= "Connection: keep-alive\r\n";
        foreach ($headers as $k => $v) {
            $req .= (is_int($k) ? $v : "$k: $v") . "\r\n";
        }
        $req .= "Content-Length: " . strlen($json) . "\r\n\r\n";
        $req .= $json;

        /* ---------- AsyncTcpConnection ---------- */
        $conn = new AsyncTcpConnection($addr);
        if ($ssl) $conn->transport = 'ssl';
        $conn->connectTimeout = $connectT;

        /* idle 计时 —— 同 ai-sdk */
        $idleTimer = null;
        $touch = static function() use (&$idleTimer,$idleT,$conn){
            if($idleT<=0) return;
            $idleTimer && Timer::del($idleTimer);
            $idleTimer = Timer::add($idleT, static fn()=> $conn->close(),[],false);
        };

        /* 逻辑同 ai-sdk 代码 */
        $headerDone=false; $buf='';
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
                $payload = trim(substr($line,5));
                $onChunk($payload==='[DONE]'?'':$payload,$payload==='[DONE]');
            }
        };
        $end = static function() use (&$idleTimer,$onChunk){
            $idleTimer && Timer::del($idleTimer);
            $onChunk('',true);
        };
        $conn->onClose=$end; $conn->onError=static fn()=>$end();
        $conn->connect();

        static $pool=[]; $pool[spl_object_id($conn)]=$conn;
    }
}
