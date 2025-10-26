<?php
// None.php
namespace LayBot\Request\Signer;
use LayBot\Request\Contract\SignerInterface;
final class None implements SignerInterface{
    public function sign(string $m,string $p,string $b=''): array { return []; }
}
