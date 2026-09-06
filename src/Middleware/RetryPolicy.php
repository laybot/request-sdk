<?php
declare(strict_types=1);

namespace LayBot\Request\Middleware;

use LayBot\Request\Exception\ConnectionException;
use LayBot\Request\Exception\ConnectTimeoutException;
use LayBot\Request\Exception\HttpException;
use LayBot\Request\Exception\RequestException;
use LayBot\Request\Exception\RequestTimeoutException;

final class RetryPolicy
{
    /**
     * maxAttempts 包含第一次请求。
     *
     * @param list<int> $statusCodes
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $baseDelayMs = 200,
        public readonly int $maxDelayMs = 5000,
        public readonly int $jitterMs = 100,
        public readonly array $statusCodes = [429, 502, 503, 504],
        public readonly bool $safeMethodsOnly = true,
    ) {
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException(
                'maxAttempts must be at least 1'
            );
        }
    }

    public static function disabled(): self
    {
        return new self(maxAttempts: 1);
    }

    public static function fromMixed(
        mixed $value,
        self $default
    ): self {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === false || $value === 0) {
            return self::disabled();
        }

        if (is_int($value)) {
            return new self(maxAttempts: max(1, $value + 1));
        }

        if (is_array($value)) {
            return new self(
                maxAttempts: (int)($value['max_attempts']
                    ?? $default->maxAttempts),
                baseDelayMs: (int)($value['base_delay_ms']
                    ?? $default->baseDelayMs),
                maxDelayMs: (int)($value['max_delay_ms']
                    ?? $default->maxDelayMs),
                jitterMs: (int)($value['jitter_ms']
                    ?? $default->jitterMs),
                statusCodes: array_map(
                    'intval',
                    $value['retry_statuses']
                    ?? $value['status_codes']
                    ?? $default->statusCodes
                ),
                safeMethodsOnly: (bool)($value['safe_methods_only']
                    ?? $default->safeMethodsOnly),
            );
        }

        return $default;
    }

    public function shouldRetry(
        string $method,
        int $attempt,
        \Throwable $error,
        bool $idempotent = false
    ): bool {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        $method = strtoupper($method);

        if (
            $this->safeMethodsOnly
            && !$idempotent
            && !in_array(
                $method,
                ['GET', 'HEAD', 'OPTIONS', 'TRACE'],
                true
            )
        ) {
            return false;
        }

        if (
            $error instanceof ConnectTimeoutException
            || $error instanceof ConnectionException
            || $error instanceof RequestTimeoutException
        ) {
            return true;
        }

        if ($error instanceof HttpException) {
            return in_array(
                $error->getStatusCode(),
                $this->statusCodes,
                true
            );
        }

        return $error instanceof RequestException
            && $error->isRetryable();
    }

    public function delayMs(
        int $attempt,
        ?string $retryAfter = null
    ): int {
        $headerDelay = self::parseRetryAfter($retryAfter);

        if ($headerDelay !== null) {
            return min($headerDelay, $this->maxDelayMs);
        }

        $delay = $this->baseDelayMs
            * (2 ** max(0, $attempt - 1));

        $jitter = $this->jitterMs > 0
            ? random_int(0, $this->jitterMs)
            : 0;

        return min($delay + $jitter, $this->maxDelayMs);
    }

    public static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (ctype_digit($value)) {
            return (int)$value * 1000;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0, ($timestamp - time()) * 1000);
    }
}
