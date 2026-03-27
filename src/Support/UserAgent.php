<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

final class UserAgent
{
    public static function default(): string
    {
        return 'PHP-HTTP-Client/0.5';
    }

    private function __construct()
    {
    }
}
