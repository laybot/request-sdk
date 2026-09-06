<?php
declare(strict_types=1);

namespace LayBot\Request\Contract;

use LayBot\Request\DTO\SigningContext;

interface ContextSignerInterface
{
    /**
     * 用最终 URL、Query、Header 和 Body 生成签名。
     *
     * @return array<string,string>
     */
    public function signRequest(SigningContext $context): array;
}
