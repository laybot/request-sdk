<?php
declare(strict_types=1);

namespace LayBot\Request\Stream;

use LayBot\Request\Exception\CancelledException;

final class CancellationToken
{
    private bool $cancelled = false;

    private ?string $reason = null;

    private int $listenerSequence = 0;

    /** @var array<int,\Closure(?string):void> */
    private array $listeners = [];

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function throwIfCancelled(): void
    {
        if (!$this->cancelled) {
            return;
        }

        throw new CancelledException(
            $this->reason ?: 'request cancelled'
        );
    }

    /**
     * 订阅动态取消通知。
     *
     * 如果当前 Token 已经取消，监听器会立即执行。
     *
     * @param callable(?string):void $listener
     */
    public function subscribe(callable $listener): CancellationRegistration
    {
        $closure = \Closure::fromCallable($listener);

        if ($this->cancelled) {
            $closure($this->reason);

            return new CancellationRegistration(
                static function (): void {
                }
            );
        }

        $id = ++$this->listenerSequence;
        $this->listeners[$id] = $closure;

        return new CancellationRegistration(
            function () use ($id): void {
                unset($this->listeners[$id]);
            }
        );
    }

    /**
     * @internal 只能由 CancellationSource 调用。
     */
    public function cancel(?string $reason = null): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->reason = $reason;

        $listeners = $this->listeners;
        $this->listeners = [];

        foreach ($listeners as $listener) {
            try {
                $listener($reason);
            } catch (\Throwable) {
                // 一个取消监听器失败不能阻止其余监听器收到通知。
            }
        }
    }
}
