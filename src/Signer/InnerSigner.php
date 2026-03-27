<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\SignerInterface;

final class InnerSigner implements SignerInterface
{
    public function __construct(private string $token)
    {
    }

    public function sign(string $method, string $path, string $body = ''): array
    {
        return [
            'X-Inner-Token' => $this->token,
        ];
    }
}
