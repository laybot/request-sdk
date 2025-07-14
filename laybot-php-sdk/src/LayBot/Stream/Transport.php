<?php
declare(strict_types=1);
namespace LayBot\Stream;

/** 流式发送抽象接口 */
interface Transport
{
    /**
     * @param string   $url             完整 URL
     * @param string   $json            JSON 字符串
     * @param string[] $headers         HTTP 头
     * @param int      $connectTimeout  建连超时（秒）
     * @param int      $idleTimeout     空闲超时（秒，0 = 不限制）
     * @param callable $onFrame         fn(string $raw,bool $done):void
     */
    public function post(
        string   $url,
        string   $json,
        array    $headers,
        int      $connectTimeout,
        int      $idleTimeout,
        callable $onFrame
    ): void;
}
