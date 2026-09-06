<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\SignerInterface;

final class HmacSigner implements SignerInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $secret,
    ) {
    }

    public function sign(
        string $method,
        string $path,
        string $body = ''
    ): array {
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));

        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            hash('sha256', $body),
            $timestamp,
            $nonce,
        ]);

        return [
            'X-Api-Key' => $this->apiKey,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => hash_hmac(
                'sha256',
                $canonical,
                $this->secret
            ),
        ];
    }
}
