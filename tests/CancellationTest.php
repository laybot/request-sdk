<?php
declare(strict_types=1);

namespace LayBot\Request\Tests;

use LayBot\Request\Stream\CancellationSource;
use PHPUnit\Framework\TestCase;

final class CancellationTest extends TestCase
{
    public function testDynamicCancellationNotification(): void
    {
        $source = new CancellationSource();
        $reason = null;

        $registration = $source->token()->subscribe(
            static function (?string $value) use (&$reason): void {
                $reason = $value;
            }
        );

        $source->cancel('user stopped');

        self::assertTrue($source->token()->isCancelled());
        self::assertSame('user stopped', $reason);

        $registration->unregister();
    }

    public function testUnregisteredListenerIsNotCalled(): void
    {
        $source = new CancellationSource();
        $called = false;

        $registration = $source->token()->subscribe(
            static function () use (&$called): void {
                $called = true;
            }
        );

        $registration->unregister();
        $source->cancel();

        self::assertFalse($called);
    }
}
