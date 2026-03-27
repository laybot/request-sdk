<?php
declare(strict_types=1);

namespace LayBot\Request\Facade;

use LayBot\Request\Client;
use LayBot\Request\Config;
use LayBot\Request\Signer\HmacSigner;

final class PartnerApi
{
    private Client $cli;

    public function __construct(string $base, string $appKey, string $secret, float $timeout = 8.0)
    {
        $cfg = new Config(
            baseUri: $base,
            timeout: $timeout,
            signer: new HmacSigner($appKey, $secret)
        );

        $this->cli = new Client($cfg);
    }

    public function call(string $method, array $data = []): array
    {
        return $this->cli->postJson('/partner/api', [
            'method' => $method,
            'data' => $data,
        ]);
    }

    public function accountSync(array $data = []): array
    {
        return $this->call('accountSync', $data);
    }
}
