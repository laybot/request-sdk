<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\SignerInterface;

final class NoneSigner implements SignerInterface
{
    public function sign(string $method, string $path, string $body = ''): array
    {
        return [];
    }
}
