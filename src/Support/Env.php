<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

use Workerman\Worker;

final class Env
{
    public static function inWorkermanLoop(): bool
    {
        return class_exists(Worker::class)
            && Worker::$globalEvent !== null;
    }

    private function __construct()
    {
    }
}
