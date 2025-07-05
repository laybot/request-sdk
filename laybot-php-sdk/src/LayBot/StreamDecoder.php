<?php

namespace LayBot;

use Psr\Http\Message\StreamInterface;

class StreamDecoder
{
    public static function decode(StreamInterface $body, callable $callback): void
    {
        $buf = '';
        while (!$body->eof()) {
            $buf .= $body->read(1024);
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = trim(substr($buf, 0, $pos));
                $buf = substr($buf, $pos + 1);
                if (str_starts_with($line, 'data:')) {
                    $data = trim(substr($line, 5));
                    $callback($data);
                }
            }
        }
    }
}
