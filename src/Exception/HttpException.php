<?php
declare(strict_types=1);

namespace LayBot\Request\Exception;

class HttpException extends RequestException
{
    private readonly string $responseBody;

    public function __construct(
        string $message,
        private readonly int $statusCode,
        string $responseBody = '',
        array $responseHeaders = [],
        ?string $method = null,
        ?string $url = null,
        ?string $requestId = null,
        bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        $this->responseBody = self::truncate($responseBody, 65_536);

        parent::__construct(
            message: $message,
            code: $statusCode,
            previous: $previous,
            method: $method,
            url: $url,
            requestId: $requestId,
            responseHeaders: $responseHeaders,
            responseSummary: self::truncate($responseBody, 2_048),
            retryable: $retryable,
        );
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * 为避免 Token 兑换等错误响应泄漏和常驻内存膨胀，
     * 此处最多保留响应正文前 64KB。
     */
    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    private static function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit) . '...<truncated>';
    }
}
