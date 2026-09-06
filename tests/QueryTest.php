<?php
declare(strict_types=1);

namespace LayBot\Request\Tests;

use LayBot\Request\Support\Query;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    public function testIndices(): void
    {
        self::assertSame(
            'ids%5B0%5D=1&ids%5B1%5D=2',
            Query::build(['ids' => [1, 2]], 'indices')
        );
    }

    public function testBrackets(): void
    {
        self::assertSame(
            'ids%5B%5D=1&ids%5B%5D=2',
            Query::build(['ids' => [1, 2]], 'brackets')
        );
    }

    public function testRepeat(): void
    {
        self::assertSame(
            'ids=1&ids=2',
            Query::build(['ids' => [1, 2]], 'repeat')
        );
    }

    public function testComma(): void
    {
        self::assertSame(
            'ids=1%2C2',
            Query::build(['ids' => [1, 2]], 'comma')
        );
    }
}
