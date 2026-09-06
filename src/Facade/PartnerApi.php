<?php
declare(strict_types=1);

namespace LayBot\Request\Facade;

use LayBot\Request\Client;
use LayBot\Request\Signer\ApiKeySigner;

final class PartnerApi
{
    public static function make(
        string $baseUri,
        string $apiKey,
        array $options = []
    ): Client {
        return Client::make(array_merge($options, [
            'base_uri' => $baseUri,
            'signer' => new ApiKeySigner($apiKey),
        ]));
    }

    private function __construct()
    {
    }
}
