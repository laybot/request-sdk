<?php
declare(strict_types=1);
namespace LayBot;

use Psr\Http\Message\StreamInterface;

/**
 * 将 text/event-stream 拆帧为 JSON；遇到 [DONE] 触发 $done=true
 *
 * $cb = function(array $chunk,bool $done):void{}
 */
final class StreamDecoder
{
    public static function decode(StreamInterface $body, callable $cb): void
    {
        $buf = '';
        while (!$body->eof()) {
            $buf .= $body->read(8192);

            /* 逐行处理，可同时消费多帧 */
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = rtrim(substr($buf, 0, $pos), "\r");
                $buf  = substr($buf, $pos + 1);

                if ($line === '') {
                    continue;                           // keep-alive
                }
                if (str_starts_with($line, 'event:')) {
                    continue;                           // 忽略 event 行
                }
                if (!str_starts_with($line, 'data:')) {
                    continue;                           // 非 data 行
                }

                $payload = trim(substr($line, 5));
                if ($payload === '[DONE]') {
                    $cb([], true);
                    return;
                }

                /* 尝试 JSON 解码；不成功则忽略此帧 */
                try {
                    $json = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                    $cb($json, false);
                } catch (\JsonException) {
                    // 忽略异常帧
                }
            }
        }
    }
}
