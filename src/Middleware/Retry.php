<?php
declare(strict_types=1);

namespace LayBot\Request\Middleware;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Retry
{
    public static function middleware(
        int $times,
        int $baseDelayMs = 200,
        bool $retryOn429 = true
    ): callable {
        $times = max(0, $times);
        $baseDelayMs = max(0, $baseDelayMs);

        return Middleware::retry(
            static function (
                int $retries,
                RequestInterface $request,
                ?ResponseInterface $response = null,
                ?GuzzleRequestException $exception = null
            ) use ($times, $retryOn429): bool {
                if ($retries >= $times) {
                    return false;
                }

                if ($exception instanceof ConnectException) {
                    return true;
                }

                if ($exception instanceof GuzzleRequestException && $response === null) {
                    return true;
                }

                if ($response) {
                    $status = $response->getStatusCode();

                    if ($status >= 500) {
                        return true;
                    }

                    if ($retryOn429 && $status === 429) {
                        return true;
                    }
                }

                return false;
            },
            static function (int $retryNumber, ?ResponseInterface $response = null) use ($baseDelayMs): int {
                if ($response && $response->hasHeader('Retry-After')) {
                    $retryAfter = trim($response->getHeaderLine('Retry-After'));
                    if (ctype_digit($retryAfter)) {
                        return max(0, (int)$retryAfter * 1000);
                    }
                }

                $delay = $baseDelayMs * (2 ** max(0, $retryNumber - 1));
                $jitter = random_int(0, 100);

                return $delay + $jitter;
            }
        );
    }

    private function __construct()
    {
    }
}
