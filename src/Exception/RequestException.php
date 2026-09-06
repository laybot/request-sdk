<?php
declare(strict_types=1);

namespace LayBot\Request\Exception;

class RequestException extends \RuntimeException
{
    /**
     * @param array<string,list<string>> $responseHeaders
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?string $method = null,
        private readonly ?string $url = null,
        private readonly ?string $requestId = null,
        private readonly array $responseHeaders = [],
        private readonly string $responseSummary = '',
        private readonly bool $retryable = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function getResponseSummary(): string
    {
        return $this->responseSummary;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
