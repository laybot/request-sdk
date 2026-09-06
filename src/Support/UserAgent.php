<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

final class UserAgent
{
    public static function default(): string
    {
        return sprintf(
            'laybot-request-sdk/2.0 PHP/%s',
            PHP_VERSION
        );
    }

    private function __construct()
    {
    }
}
