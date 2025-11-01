<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

/**
 * 判断当前是否处在 Workerman/Webman Worker 进程
 * 只有真的进入事件循环后才返回 true，避免在 master/cli 误判。
 */
final class Env
{
    public static function inWorkermanLoop(): bool
    {
        if (!class_exists(\Workerman\Worker::class, false)) {
            return false;
        }

        /* (a) Worker 进程 fork 后，getAllWorkers() 一定非空 */
        if (method_exists(\Workerman\Worker::class, 'getAllWorkers')
            && !empty(\Workerman\Worker::getAllWorkers())) {
            return true;
        }

        /* (b) Webman Worker 进程（request 生命周期里）*/
        if (defined('WEBMAN') && php_sapi_name() !== 'cli') {
            return true;
        }

        return false;
    }
}
