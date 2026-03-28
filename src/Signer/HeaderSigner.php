<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\SignerInterface;

final class HeaderSigner implements SignerInterface
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(private array $headers)
    {
    }

    public function sign(string $method, string $path, string $body = ''): array
    {
        $out = [];
        foreach ($this->headers as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            $out[$key] = (string)$value;
        }
        return $out;
    }
}
