<?php
declare(strict_types=1);
namespace LayBot\Stream;

use LayBot\Client;
use LayBot\StreamDecoder;

/** FPM 场景下的流式实现：Guzzle + cURL 低速检测 */
final class GuzzleTransport implements Transport
{
    public function __construct(private Client $cli) {}

    public function post(
        string   $url,
        string   $json,
        array    $headers,
        int      $connectTimeout,   // 接口保持一致，但 Guzzle 自己已含此逻辑，可忽略
        int      $idleTimeout,
        callable $onFrame
    ): void
    {
        $curlOpt = [];
        if ($idleTimeout > 0) {
            $curlOpt = [
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME  => $idleTimeout,
            ];
        }

        $res = $this->cli->raw()->post($url, [
            'headers'         => $headers,
            'body'            => $json,
            'stream'          => true,
            'timeout'         => 0,
            'connect_timeout' => $connectTimeout,
            'curl'            => $curlOpt,
        ]);

        /* 拆帧 */
        StreamDecoder::decode(
            $res->getBody(),
            static function(array $chunk,bool $done) use ($onFrame){
                $onFrame(json_encode($chunk,JSON_UNESCAPED_UNICODE),$done);
            }
        );
    }
}