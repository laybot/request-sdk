<?php
declare(strict_types=1);
namespace LayBot\Stream;

/** 流式发送抽象接口 */
interface Transport
{
    /**
     * @param string   $url       完整 URL（不带 base）
     * @param string   $json      请求体
     * @param string[] $headers   HTTP header 列表
     * @param int      $timeout   秒
     * @param callable $onFrame   fn(string $rawLine,bool $done):void
     */
    public function post(string $url, string $json, array $headers, int $timeout, callable $onFrame): void;
}
