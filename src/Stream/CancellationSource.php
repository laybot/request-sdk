<?php
declare(strict_types=1);

namespace LayBot\Request\Stream;

final class CancellationSource
{
    private readonly CancellationToken $token;

    public function __construct()
    {
        $this->token = new CancellationToken();
    }

    public function token(): CancellationToken
    {
        return $this->token;
    }

    public function cancel(?string $reason = null): void
    {
        $this->token->cancel($reason);
    }
}
