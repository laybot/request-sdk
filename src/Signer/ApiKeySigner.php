<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\SignerInterface;

final class ApiKeySigner implements SignerInterface
{
    public function __construct(
        private string $key,
        private string $header = 'X-API-Key'
    ) {
    }

    public function sign(string $method, string $path, string $body = ''): array
    {
        return [
            $this->header => $this->key,
        ];
    }
}
