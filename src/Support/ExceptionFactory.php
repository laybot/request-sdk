<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

use LayBot\Request\DTO\ResponseHead;
use LayBot\Request\Exception\AuthenticationException;
use LayBot\Request\Exception\AuthorizationException;
use LayBot\Request\Exception\HttpException;
use LayBot\Request\Exception\RateLimitException;

final class ExceptionFactory
{
    public static function http(
        string $method,
        string $url,
        int $status,
        array $headers,
        string $body
    ): HttpException {
        $requestId = Header::first($headers, [
            'x-request-id',
            'x-api-request-id',
            'x-tt-logid',
            'request-id',
            'trace-id',
        ]);

        $arguments = [
            'message' => sprintf(
                'HTTP request failed with status %d',
                $status
            ),
            'statusCode' => $status,
            'responseBody' => $body,
            'responseHeaders' => Header::normalize($headers),
            'method' => strtoupper($method),
            'url' => LogSanitizer::url($url),
            'requestId' => $requestId,
            'retryable' => $status === 429
                || in_array($status, [502, 503, 504], true),
        ];

        return match ($status) {
            401 => new AuthenticationException(...$arguments),
            403 => new AuthorizationException(...$arguments),
            429 => new RateLimitException(...$arguments),
            default => new HttpException(...$arguments),
        };
    }

    private function __construct()
    {
    }
}
