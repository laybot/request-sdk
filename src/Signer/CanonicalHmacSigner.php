<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\ContextSignerInterface;
use LayBot\Request\DTO\SigningContext;

final class CanonicalHmacSigner implements ContextSignerInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $secret,
        private readonly string $keyHeader = 'X-Api-Key',
        private readonly string $signatureHeader = 'X-Signature',
    ) {
    }

    public function signRequest(SigningContext $context): array
    {
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));

        $canonical = implode("\n", [
            strtoupper($context->method),
            $context->path,
            $context->canonicalQuery,
            $context->bodySha256(),
            $timestamp,
            $nonce,
        ]);

        return [
            $this->keyHeader => $this->apiKey,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            $this->signatureHeader => hash_hmac(
                'sha256',
                $canonical,
                $this->secret
            ),
        ];
    }
}
