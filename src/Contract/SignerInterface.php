<?php
// src/Contract/SignerInterface.php
namespace LayBot\Request\Contract;

interface SignerInterface
{
    /** @return array<string,string> 覆盖/追加到最终 Headers */
    public function sign(string $method,string $path,string $body=''): array;
}
