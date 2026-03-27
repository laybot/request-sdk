<?php
declare(strict_types=1);

namespace LayBot\Request\Util;

use Psr\Http\Message\StreamInterface;

final class StreamDecoder
{
    /**
     * 按行解析 SSE data: 帧
     *
     * @param callable(string $chunk,bool $done):void $cb
     */
    public static function decode(StreamInterface $body, callable $cb): void
    {
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(8192);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

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

    private function __construct()
    {
    }
}
