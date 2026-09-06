<?php
declare(strict_types=1);

namespace LayBot\Request\Timeout;

final class TimeoutConfig
{
    public function __construct(
        public readonly float $connect = 10.0,
        public readonly float $request = 30.0,
        public readonly float $idle = 180.0,
    ) {
        if ($this->connect <= 0) {
            throw new \InvalidArgumentException(
                'connect timeout must be greater than zero'
            );
        }

        if ($this->request < 0 || $this->idle < 0) {
            throw new \InvalidArgumentException(
                'request and idle timeout cannot be negative'
            );
        }
    }

    public static function fromArray(
        array $values,
        ?self $defaults = null
    ): self {
        $defaults ??= new self();

        return new self(
            connect: (float)($values['connect'] ?? $defaults->connect),
            request: (float)($values['request'] ?? $defaults->request),
            idle: (float)($values['idle'] ?? $defaults->idle),
        );
    }

    public function with(
        ?float $connect = null,
        ?float $request = null,
        ?float $idle = null
    ): self {
        return new self(
            $connect ?? $this->connect,
            $request ?? $this->request,
            $idle ?? $this->idle,
        );
    }
}
