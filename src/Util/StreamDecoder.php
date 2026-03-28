<?php
declare(strict_types=1);

namespace LayBot\Request\Util;

use Psr\Http\Message\StreamInterface;

final class StreamDecoder
{
    /**
     * 基础流式解析器
     *
     * 支持模式：
     * - data-line: 仅提取 data: 行（默认）
     * - raw-line:  按行原样输出
     *
     * @param callable(string $chunk,bool $done):void $cb
     * @param array{
     *   mode?:string,
     *   done_token?:?string
     * } $opt
     */
    public static function decode(StreamInterface $body, callable $cb, array $opt = []): void
    {
        $mode = strtolower(trim((string)($opt['mode'] ?? 'data-line')));
        $doneToken = array_key_exists('done_token', $opt) ? $opt['done_token'] : '[DONE]';

        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(8192);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                if ($mode === 'raw-line') {
                    if ($line === '') {
                        continue;
                    }

                    if ($doneToken !== null && $line === $doneToken) {
                        $cb('', true);
                        return;
                    }

                    $cb($line, false);
                    continue;
                }

                // 默认 data-line
                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));

                if ($doneToken !== null && $payload === $doneToken) {
                    $cb('', true);
                    return;
                }

                if ($payload === '') {
                    continue;
                }

                $cb($payload, false);
            }
        }
    }

    private function __construct()
    {
    }
}
