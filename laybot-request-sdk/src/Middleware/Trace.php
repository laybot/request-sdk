<?php
namespace LayBot\Request\Middleware;

use GuzzleHttp\Middleware;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Trace
{
    public static function middleware(LoggerInterface $logger): callable
    {
        return Middleware::tap(
            static function (RequestInterface $req, array $opts) use ($logger) {
                $logger->debug('[HTTP] send', [
                    'method' => $req->getMethod(),
                    'uri'    => (string)$req->getUri(),
                ]);
            },
            static function (RequestInterface $req, ResponseInterface $res) use ($logger) {
                $logger->debug('[HTTP] recv', [
                    'code' => $res->getStatusCode(),
                    'uri'  => (string)$req->getUri(),
                ]);
            }
        );
    }
}
