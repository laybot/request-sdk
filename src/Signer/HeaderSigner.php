<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\SignerInterface;

final class HeaderSigner implements SignerInterface
{
    public function __construct(
        private readonly array $headers
    ) {
    }

    public function sign(
        string $method,
        string $path,
        string $body = ''
    ): array {
        return $this->headers;
    }
}
