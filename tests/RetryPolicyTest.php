<?php
declare(strict_types=1);

namespace LayBot\Request\Tests;

use LayBot\Request\Exception\ConnectionException;
use LayBot\Request\Middleware\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testPostDoesNotRetryWithoutIdempotency(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3);
        $error = new ConnectionException(
            'failed',
            retryable: true
        );

        self::assertFalse(
            $policy->shouldRetry('POST', 1, $error, false)
        );

        self::assertTrue(
            $policy->shouldRetry('POST', 1, $error, true)
        );
    }

    public function testRetryAfterSeconds(): void
    {
        self::assertSame(
            3000,
            RetryPolicy::parseRetryAfter('3')
        );
    }
}
