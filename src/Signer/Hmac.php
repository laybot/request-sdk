<?php
// src/Signer/Hmac.php  (PartnerAuth)
namespace LayBot\Request\Signer;
use LayBot\Request\Contract\SignerInterface;

final class Hmac implements SignerInterface
{
    public function __construct(
        private string $appKey,
        private string $secret
    ){}

    public function sign(string $method,string $path,string $body=''): array
    {
        $ts  = (string)round(microtime(true)*1000);
        $md5 = $body ? md5($body, false) : '';
        $plain = "{$this->appKey}\n{$ts}\n{$path}\n{$md5}";
        $sig = hash_hmac('sha256', $plain, $this->secret);
        return [
            'X-App-Id'    => $this->appKey,
            'X-Timestamp' => $ts,
            'X-Sign'      => $sig
        ];
    }
}
