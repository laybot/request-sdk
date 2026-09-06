<?php
declare(strict_types=1);

namespace LayBot\Request\Tests;

use LayBot\Request\Stream\LineParser;
use PHPUnit\Framework\TestCase;

final class LineParserTest extends TestCase
{
    public function testSplitLines(): void
    {
        $parser = new LineParser();
        $lines = [];

        $emit = static function (
            string $line,
            int $number
        ) use (&$lines): void {
            $lines[$number] = $line;
        };

        $parser->feed("{\"a\":", $emit);
        $parser->feed("1}\n{\"b\":2}\r\n", $emit);
        $parser->feed("{\"c\":3}", $emit);
        $parser->finish($emit);

        self::assertSame([
            1 => '{"a":1}',
            2 => '{"b":2}',
            3 => '{"c":3}',
        ], $lines);
    }
}
