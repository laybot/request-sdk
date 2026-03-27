<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

final class Env
{
    public static function inWorkermanLoop(): bool
    {
        if (!class_exists(\Workerman\Worker::class, false)) {
            return false;
        }

        if (
            method_exists(\Workerman\Worker::class, 'getAllWorkers')
            && !empty(\Workerman\Worker::getAllWorkers())
        ) {
            return true;
        }

        if (defined('WEBMAN') && php_sapi_name() !== 'cli') {
            return true;
        }

        return false;
    }

    private function __construct()
    {
    }
}
