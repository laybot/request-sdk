<?php
namespace LayBot\Request\Util;

use Psr\Http\Message\StreamInterface;

final class StreamDecoder
{
    /**
     * 按行拆 data: 帧
     * @param callable(string $chunk,bool $done):void $cb
     */
    public static function decode(StreamInterface $body, callable $cb): void
    {
        $buf = '';
        while (!$body->eof()) {
            $buf .= $body->read(8192);
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = rtrim(substr($buf, 0, $pos), "\r");
                $buf  = substr($buf, $pos + 1);
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $payload = trim(substr($line, 5));
                if ($payload === '[DONE]') {
                    $cb('', true);
                    return;
                }
                $cb($payload, false);
            }
        }
    }
}
