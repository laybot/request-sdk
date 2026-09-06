<?php
declare(strict_types=1);

namespace LayBot\Request\Stream;

use LayBot\Request\Contract\AsyncHandleInterface;
use LayBot\Request\Enum\AsyncState;

final class AsyncRequestHandle implements AsyncHandleInterface
{
    private AsyncState $state = AsyncState::PENDING;

    /** @var null|\Closure(?string):void */
    private ?\Closure $canceller = null;

    public function state(): AsyncState
    {
        return $this->state;
    }

    public function isSettled(): bool
    {
        return $this->state->isSettled();
    }

    /**
     * @internal Transport 注入真正的取消实现。
     */
    public function setCanceller(callable $canceller): void
    {
        if ($this->isSettled()) {
            return;
        }

        $this->canceller = \Closure::fromCallable($canceller);
    }

    public function cancel(?string $reason = null): void
    {
        if ($this->isSettled()) {
            return;
        }

        $canceller = $this->canceller;

        if ($canceller !== null) {
            $canceller($reason);
        }

        if (!$this->isSettled()) {
            $this->markCancelled();
        }
    }

    /** @internal */
    public function markRunning(): void
    {
        if ($this->state === AsyncState::PENDING) {
            $this->state = AsyncState::RUNNING;
        }
    }

    /** @internal */
    public function markCompleted(): void
    {
        if ($this->isSettled()) {
            return;
        }

        $this->state = AsyncState::COMPLETED;
        $this->canceller = null;
    }

    /** @internal */
    public function markFailed(): void
    {
        if ($this->isSettled()) {
            return;
        }

        $this->state = AsyncState::FAILED;
        $this->canceller = null;
    }

    /** @internal */
    public function markCancelled(): void
    {
        if ($this->isSettled()) {
            return;
        }

        $this->state = AsyncState::CANCELLED;
        $this->canceller = null;
    }
}
