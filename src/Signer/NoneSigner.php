<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\ContextSignerInterface;
use LayBot\Request\Contract\SignerInterface;
use LayBot\Request\DTO\SigningContext;

final class NoneSigner implements SignerInterface, ContextSignerInterface
{
    public function sign(
        string $method,
        string $path,
        string $body = ''
    ): array {
        return [];
    }

    public function signRequest(SigningContext $context): array
    {
        return [];
    }
}
