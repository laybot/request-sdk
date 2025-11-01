<?php
// src/Signer/Inner.php
namespace LayBot\Request\Signer;
use LayBot\Request\Contract\SignerInterface;
final class Inner implements SignerInterface
{
    public function __construct(private string $token){}
    public function sign(string $m,string $p,string $b=''): array{
        return ['X-Inner-Token'=>$this->token];
    }
}
