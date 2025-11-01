<?php
// ApiKey.php
namespace LayBot\Request\Signer;
use LayBot\Request\Contract\SignerInterface;
final class ApiKey implements SignerInterface{
    public function __construct(private string $key, private string $header='X-API-Key'){}
    public function sign(string $m,string $p,string $b=''): array{
        return [$this->header => $this->key];
    }
}
