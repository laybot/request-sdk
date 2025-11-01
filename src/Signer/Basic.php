<?php
// Basic.php
namespace LayBot\Request\Signer;
use LayBot\Request\Contract\SignerInterface;
final class Basic implements SignerInterface{
    public function __construct(private string $user, private string $pass){}
    public function sign(string $m,string $p,string $b=''): array{
        return ['Authorization' => 'Basic '.base64_encode("{$this->user}:{$this->pass}")];
    }
}
