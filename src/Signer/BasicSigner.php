<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\SignerInterface;

final class BasicSigner implements SignerInterface
{
    public function __construct(
        private string $user,
        private string $pass
    ) {
    }

    public function sign(string $method, string $path, string $body = ''): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode("{$this->user}:{$this->pass}"),
        ];
    }
}
