<?php
declare(strict_types=1);

namespace LayBot\Request\DTO;

use LayBot\Request\Stream\CancellationToken;
use LayBot\Request\Timeout\TimeoutConfig;

final class PreparedRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?array $multipart,
        public readonly TimeoutConfig $timeouts,
        public readonly int $maxResponseBytes,
        public readonly ?CancellationToken $cancellation,
        public readonly ?string $proxy = null,
        public readonly mixed $sink = null,
    ) {
    }
}
