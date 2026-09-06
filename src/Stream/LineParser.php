<?php
declare(strict_types=1);

namespace LayBot\Request\Stream;

use LayBot\Request\Exception\StreamProtocolException;

final class LineParser
{
    private string $buffer = '';
    private int $lineNumber = 0;

    public function __construct(
        private readonly int $maxLineBytes = 8_388_608,
        private readonly bool $ignoreEmptyLines = true,
    ) {
    }

    /**
     * @param callable(string,int):void $onLine
     */
    public function feed(string $bytes, callable $onLine): void
    {
        if ($bytes === '') {
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

            $this->emitLine($line, $onLine);
            $this->assertSize();
        }
    }

    /**
     * @param callable(string,int):void $onLine
     */
    public function finish(callable $onLine): void
    {
        if ($this->buffer === '') {
            return;
        }

        $line = $this->buffer;
        $this->buffer = '';

        if (str_ends_with($line, "\r")) {
            $line = substr($line, 0, -1);
        }

        $this->emitLine($line, $onLine);
    }

    private function emitLine(string $line, callable $onLine): void
    {
        if ($this->ignoreEmptyLines && trim($line) === '') {
            return;
        }

        $this->lineNumber++;
        $onLine($line, $this->lineNumber);
    }

    private function assertSize(): void
    {
        if (strlen($this->buffer) > $this->maxLineBytes) {
            throw new StreamProtocolException(
                'stream line exceeds configured size limit'
            );
        }
    }
}
