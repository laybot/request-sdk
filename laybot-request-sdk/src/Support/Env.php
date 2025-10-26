<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

/**
 * 运行环境工具
 */
final class Env
{
    /**
     * 判断当前进程是否处在 Workerman/Webman 的事件循环里
     */
    public static function inWorkermanLoop(): bool
    {
        if (!class_exists(\Workerman\Worker::class, false)) {
            return false;
        }

        /* (a) worker 进程启动后，Worker::getAllWorkers() 必定非空 */
        if (method_exists(\Workerman\Worker::class, 'getAllWorkers')
            && !empty(\Workerman\Worker::getAllWorkers())) {
            return true;
        }

        /* (b) Webman Worker 进程中（FPM-style 请求），PHP SAPI 不是 cli */
        if (defined('WEBMAN') && php_sapi_name() !== 'cli') {
            return true;
        }

        return false;
    }
}
