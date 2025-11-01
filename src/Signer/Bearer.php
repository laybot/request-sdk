<?php
// src/Signer/Bearer.php
namespace LayBot\Request\Signer;
use LayBot\Request\Contract\SignerInterface;
final class Bearer implements SignerInterface{
    public function __construct(private string $token){}
    public function sign(string $m,string $p,string $b=''): array{
        return ['Authorization'=>'Bearer '.$this->token];
    }
}
