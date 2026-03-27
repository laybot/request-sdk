<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\SignerInterface;

final class BearerSigner implements SignerInterface
{
    public function __construct(private string $token)
    {
    }

    public function sign(string $method, string $path, string $body = ''): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token,
        ];
    }
}
