<?php
declare(strict_types=1);

namespace LayBot\Request\Contract;

interface SignerInterface
{
    /**
     * 旧版签名接口。返回值会合并到最终请求 Header。
     *
     * @return array<string,string>
     */
    public function sign(
        string $method,
        string $path,
        string $body = ''
    ): array;
}
