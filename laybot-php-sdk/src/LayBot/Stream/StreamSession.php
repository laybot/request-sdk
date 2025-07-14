<?php
declare(strict_types=1);
namespace LayBot\Stream;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * 伪装成 ResponseInterface 的流式会话
 *  – 满足类型检查
 *  – 仅真正实现一个 on() 方法，让业务侧启动流
 */
final class StreamSession implements ResponseInterface
{
    public function __construct(private \Closure $starter) {}

    /* 业务真正需要的接口 —— 启动 SSE */
    public function on(callable $cb): void
    {
        ($this->starter)($cb);
    }

    /* ===================================================================== */
    /*  以下均为 ResponseInterface 所需的「样板桩」，逻辑保持最简 / 恒定      */
    /* ===================================================================== */

    /* -------- 协议 -------- */
    public function getProtocolVersion(): string            { return '1.1'; }
    public function withProtocolVersion(string $version): self { return $this; }

    /* -------- Header 相关 -------- */
    public function getHeaders(): array                     { return []; }
    public function hasHeader(string $name): bool           { return false; }
    public function getHeader(string $name): array          { return []; }
    public function getHeaderLine(string $name): string     { return ''; }
    public function withHeader(string $name, $value): self         { return $this; }
    public function withAddedHeader(string $name, $value): self    { return $this; }
    public function withoutHeader(string $name): self              { return $this; }

    /* -------- Body -------- */
    public function getBody(): StreamInterface
    {
        /** 空实现的 NullStream —— 满足 StreamInterface */
        return new class implements StreamInterface {
            public function __toString(): string              { return ''; }
            public function close(): void                     {}
            public function detach()                          { return null; }
            public function getSize(): ?int                   { return null; }
            public function tell(): int                       { return 0; }
            public function eof(): bool                       { return true; }
            public function isSeekable(): bool                { return false; }
            public function seek(int $offset, int $whence = SEEK_SET): void {}
            public function rewind(): void                    {}
            public function isWritable(): bool                { return false; }
            public function write(string $string): int        { return 0; }
            public function isReadable(): bool                { return false; }
            public function read(int $length): string         { return ''; }
            public function getContents(): string             { return ''; }
            public function getMetadata(?string $key = null)  { return null; }
        };
    }
    public function withBody(StreamInterface $body): self          { return $this; }

    /* -------- Status -------- */
    public function getStatusCode(): int                { return 200; }
    public function withStatus($code, string $reasonPhrase = ''): self { return $this; }
    public function getReasonPhrase(): string           { return 'OK'; }
}
