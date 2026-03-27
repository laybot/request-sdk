<?php
declare(strict_types=1);

namespace LayBot\Request\Contract;

interface TransportInterface
{
    /**
     * @return array{status:int,headers:array,body:string}
     */
    public function request(string $method, string $uri, array $options): array;

    /**
     * @param callable(string $chunk,bool $done):void $onChunk
     */
    public function stream(string $method, string $uri, array $options, callable $onChunk): void;
}
