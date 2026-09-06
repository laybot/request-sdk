<?php
declare(strict_types=1);

namespace LayBot\Request\Facade;

use LayBot\Request\Client;
use LayBot\Request\Signer\InnerSigner;

final class InnerApi
{
    public static function make(
        string $baseUri,
        string $token,
        array $options = []
    ): Client {
        return Client::make(array_merge($options, [
            'base_uri' => $baseUri,
            'signer' => new InnerSigner($token),
        ]));
    }

    private function __construct()
    {
    }
}
