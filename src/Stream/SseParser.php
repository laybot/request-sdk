<?php
declare(strict_types=1);

namespace LayBot\Request\Stream;

use LayBot\Request\DTO\SseEvent;
use LayBot\Request\Exception\StreamProtocolException;

final class SseParser
{
    private string $buffer = '';

    /** @var list<string> */
    private array $data = [];

    private ?string $event = null;
    private ?string $lastEventId = null;
    private ?int $retry = null;
    private bool $done = false;

    public function __construct(
        private readonly ?string $doneToken = null,
        private readonly int $maxEventBytes = 8_388_608,
    ) {
        if ($this->maxEventBytes < 1) {
            throw new \InvalidArgumentException(
                'maxEventBytes must be greater than zero'
            );
        }
    }

    /**
     * 输入任意网络字节。调用方不能假定传入数据按行或按事件对齐。
     *
     * @param callable(SseEvent):void $onEvent
     */
    public function feed(string $bytes, callable $onEvent): void
    {
        if ($bytes === '' || $this->done) {
            return;
        }

        $this->buffer .= $bytes;
        $this->assertSize();

        while (($position = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $position);
            $this->buffer = substr($this->buffer, $position + 1);

            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }

            $this->consumeLine($line, $onEvent);
            $this->assertSize();
        }
    }

    /**
     * HTTP 消息正常结束时调用，处理没有换行符的最后一行。
     *
     * @param callable(SseEvent):void $onEvent
     */
    public function finish(callable $onEvent): void
    {
        if ($this->done) {
            return;
        }

        if ($this->buffer !== '') {
            $line = $this->buffer;
            $this->buffer = '';

            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }

            $this->consumeLine($line, $onEvent);
        }

        $this->dispatch($onEvent);
    }

    public function doneSeen(): bool
    {
        return $this->done;
    }

    private function consumeLine(string $line, callable $onEvent): void
    {
        if ($line === '') {
            $this->dispatch($onEvent);
            return;
        }

        if ($line[0] === ':') {
            $onEvent(new SseEvent(
                data: substr($line, 1),
                id: $this->lastEventId,
                comment: true
            ));
            return;
        }

        $separator = strpos($line, ':');

        if ($separator === false) {
            $field = $line;
            $value = '';
        } else {
            $field = substr($line, 0, $separator);
            $value = substr($line, $separator + 1);

            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }
        }

        switch ($field) {
            case 'data':
                $this->data[] = $value;
                break;

            case 'event':
                $this->event = $value;
                break;

            case 'id':
                if (!str_contains($value, "\0")) {
                    $this->lastEventId = $value;
                }
                break;

            case 'retry':
                if (ctype_digit($value)) {
                    $this->retry = (int)$value;
                }
                break;
        }
    }

    private function dispatch(callable $onEvent): void
    {
        if ($this->data === []) {
            $this->resetEvent();
            return;
        }

        $data = implode("\n", $this->data);

        if (
            $this->doneToken !== null
            && hash_equals($this->doneToken, $data)
        ) {
            $this->done = true;
            $this->resetEvent();
            return;
        }

        $onEvent(new SseEvent(
            data: $data,
            event: $this->event,
            id: $this->lastEventId,
            retry: $this->retry
        ));

        $this->resetEvent();
    }

    private function resetEvent(): void
    {
        $this->data = [];
        $this->event = null;
        $this->retry = null;
    }

    private function assertSize(): void
    {
        $size = strlen($this->buffer);

        foreach ($this->data as $line) {
            $size += strlen($line);
        }

        if ($size > $this->maxEventBytes) {
            throw new StreamProtocolException(
                'SSE event exceeds configured size limit'
            );
        }
    }
}
