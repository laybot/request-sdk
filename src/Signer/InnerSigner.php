<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\SignerInterface;

final class InnerSigner implements SignerInterface
{
    public function __construct(
        private readonly string $token,
        private readonly string $header = 'X-Inner-Token'
    ) {
    }

    public function sign(
        string $method,
        string $path,
        string $body = ''
    ): array {
        return [$this->header => $this->token];
    }
}
