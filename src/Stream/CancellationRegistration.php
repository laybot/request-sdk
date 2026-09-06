<?php
declare(strict_types=1);

namespace LayBot\Request\Stream;

/**
 * 取消监听器注册句柄。
 *
 * Transport 完成后必须调用 unregister()，避免常驻进程中闭包泄漏。
 */
final class CancellationRegistration
{
    private bool $registered = true;

    /** @var \Closure():void */
    private \Closure $unregisterCallback;

    public function __construct(callable $unregisterCallback)
    {
        $this->unregisterCallback = \Closure::fromCallable(
            $unregisterCallback
        );
    }

    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }

        $this->registered = false;
        ($this->unregisterCallback)();
    }

    public function __destruct()
    {
        $this->unregister();
    }
}
