<?php
declare(strict_types=1);

namespace LayBot\Request\Exception;

class JsonException extends RequestException
{
    private string $raw;

    public function __construct(
        string $message,
        int $code = 0,
        string $raw = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->raw = $raw;
    }

    public function getRaw(): string
    {
        return $this->raw;
    }
}
