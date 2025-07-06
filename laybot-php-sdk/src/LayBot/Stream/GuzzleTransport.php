<?php
declare(strict_types=1);
namespace LayBot\Stream;

use LayBot\Client;
use LayBot\StreamDecoder;

/** 默认跨框架实现：Guzzle Stream */
final class GuzzleTransport implements Transport
{
    public function __construct(private Client $cli){}

    public function post(string $url, string $json, array $headers,
                         int $timeout, callable $onFrame): void
    {
        $res = $this->cli->raw()->post($url,[
            'headers'=>$headers,
            'body'   =>$json,
            'stream' =>true,
            'timeout'=>$timeout,
        ]);
        /* 交由 StreamDecoder 解析帧 */
        StreamDecoder::decode($res->getBody(), function(array $chunk,bool $done) use ($onFrame){
            $onFrame(json_encode($chunk,JSON_UNESCAPED_UNICODE),$done);
        });
    }
}