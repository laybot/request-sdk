<?php
declare(strict_types=1);

namespace LayBot\Request\Middleware;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * 打印请求/响应链路日志（调试用）
 * – 兼容 ResponseInterface | array 两种返回值，避免类型冲突
 */
final class Trace
{
    public static function middleware(LoggerInterface $logger): callable
    {
        return static function (callable $handler) use ($logger): callable {
            return static function (RequestInterface $req, array $opt) use ($handler, $logger): PromiseInterface {
                $logger->debug('[HTTP] send', [
                    'method'  => $req->getMethod(),
                    'uri'     => (string)$req->getUri(),
                    'headers' => $req->getHeaders(),
                    'body'    => (string)$req->getBody(),
                ]);

                return $handler($req, $opt)->then(
                /** @param array|ResponseInterface $res */
                    static function ($res) use ($req, $logger) {
                        if ($res instanceof ResponseInterface) {
                            $logger->debug('[HTTP] recv', [
                                'status'  => $res->getStatusCode(),
                                'headers' => $res->getHeaders(),
                                'body'    => (string)$res->getBody(),
                            ]);
                        } elseif (is_array($res)) {
                            $logger->debug('[HTTP] recv', $res);
                        }

                        return $res; // 继续传递
                    }
                );
            };
        };
    }

    // 禁止实例化
    private function __construct() {}
}
