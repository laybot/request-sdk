<?php
declare(strict_types=1);

namespace LayBot\Request\Tests;

use LayBot\Request\Support\Header;
use PHPUnit\Framework\TestCase;

final class HeaderTest extends TestCase
{
    public function testCaseInsensitiveOverride(): void
    {
        $headers = Header::merge(
            ['Authorization' => 'old'],
            ['authorization' => 'new']
        );

        self::assertSame(
            'new',
            Header::line($headers, 'Authorization')
        );
    }

    public function testCredentialsAreRemoved(): void
    {
        $headers = Header::withoutCredentials([
            'Authorization' => 'Bearer secret',
            'X-Api-Key' => 'secret',
            'X-System-Id' => 'xiaoyi',
        ]);

        self::assertFalse(
            Header::has($headers, 'Authorization')
        );

        self::assertFalse(
            Header::has($headers, 'X-Api-Key')
        );

        self::assertSame(
            'xiaoyi',
            Header::line($headers, 'X-System-Id')
        );
    }
}
