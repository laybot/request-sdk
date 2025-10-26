<?php
// src/Middleware/Retry.php
namespace LayBot\Request\Middleware;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;

final class Retry
{
    public static function middleware(int $times,int $base=200): callable
    {
        return Middleware::retry(
            static function(
                $retries,
                RequestInterface $req,
                ResponseInterface $res=null,
                RequestException $e=null
            ) use ($times){
                if($retries>=$times) return false;
                if($e) return true;
                return $res && $res->getStatusCode()>=500;
            },
            static fn($retry)=>$base*2**$retry
        );
    }
}
