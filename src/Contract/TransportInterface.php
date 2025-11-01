<?php
// src/Contract/TransportInterface.php
namespace LayBot\Request\Contract;

interface TransportInterface
{
    /** @return array{status:int,body:string,headers:array<string,string>} */
    public function request(string $method,string $uri,array $options): array;

    /**
     * SSE / chunk 流；逐帧回调
     * @param callable(string $chunk,bool $done):void $onChunk
     */
    public function stream(string $method,string $uri,array $options,callable $onChunk): void;
}
