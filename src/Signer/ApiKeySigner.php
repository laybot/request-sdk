<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\SignerInterface;

final class ApiKeySigner implements SignerInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $header = 'X-API-Key'
    ) {
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('API key required');
        }
    }

    public function sign(
        string $method,
        string $path,
        string $body = ''
    ): array {
        return [$this->header => $this->apiKey];
    }
}
