<?php
declare(strict_types=1);

namespace LayBot\Request\Middleware;

/**
 * @deprecated 2.0 中请直接使用 RetryPolicy。
 */
final class Retry
{
    public static function policy(int $times = 2): RetryPolicy
    {
        return new RetryPolicy(
            maxAttempts: max(1, $times + 1)
        );
    }
}
