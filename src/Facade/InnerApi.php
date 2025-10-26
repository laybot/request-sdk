<?php
namespace LayBot\Request\Facade;

use LayBot\Request\{Client,Config};
use LayBot\Request\Signer\Inner;

final class InnerApi
{
    private Client $cli;
    public function __construct(string $base,string $token,float $timeout=5.0)
    {
        $cfg=new Config(baseUri:$base,timeout:$timeout,signer:new Inner($token));
        $this->cli=new Client($cfg);
    }
    public function generateKey(int $uid,string $remark=null): string
    {
        $r=$this->cli->postJson('/_inner/key/generate',['user_id'=>$uid,'remark'=>$remark]);
        return $r['api_key']??'';
    }
    public function flushKey(string $key): void
    {
        $this->cli->postJson('/_inner/key/flush',['api_key'=>$key]);
    }
}
