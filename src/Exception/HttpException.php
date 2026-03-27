<?php
declare(strict_types=1);

namespace LayBot\Request\Exception;

class HttpException extends RequestException
{
    private int $statusCode;
    private string $responseBody;
    private array $responseHeaders;
    private ?string $method;
    private ?string $uri;

    public function __construct(
        string $message,
        int $statusCode,
        string $responseBody = '',
        array $responseHeaders = [],
        ?string $method = null,
        ?string $uri = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->responseHeaders = $responseHeaders;
        $this->method = $method;
        $this->uri = $uri;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function getUri(): ?string
    {
        return $this->uri;
    }
}
