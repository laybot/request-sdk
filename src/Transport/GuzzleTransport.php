<?php
declare(strict_types=1);

namespace LayBot\Request\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Exception\HttpException;
use LayBot\Request\Exception\StreamException;
use LayBot\Request\Middleware\Retry;
use LayBot\Request\Middleware\Trace;
use LayBot\Request\Util\StreamDecoder;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class GuzzleTransport implements TransportInterface
{
    private Client           $cli;
    private LoggerInterface|null $logger;

    public function __construct(
        string               $baseUri,
        float                $timeout     = 10.0,     // 连接超时
        bool                 $verify      = false,
        int                  $retryTimes  = 2,
        ?LoggerInterface     $logger      = null
    ) {
        $this->logger = $logger;

        /* ---------------- 1. HandlerStack ---------------- */
        $stack = HandlerStack::create();

        /* 1-1 Retry */
        if ($retryTimes > 0) {
            $stack->push(Retry::middleware($retryTimes), 'retry');
        }

        /* 1-2 Trace 日志 —— 必须放在“转换 Response”之前 */
        if ($logger) {
            $stack->push(Trace::middleware($logger), 'trace');
        }

        /* 1-3 mapResponse：把 ResponseInterface 转成数组，供上层统一处理
               注意此中间件必须排在 Trace 之后 */
        $stack->push(Middleware::mapResponse(
            static function (ResponseInterface $res): array {
                return [
                    'status'  => $res->getStatusCode(),
                    'headers' => $res->getHeaders(),
                    'body'    => (string)$res->getBody(),
                ];
            }
        ), 'to_array');

        /* ---------------- 2. Guzzle Client ---------------- */
        $this->cli = new Client([
            'base_uri'        => rtrim($baseUri, '/') . '/',
            'handler'         => $stack,
            'verify'          => $verify,
            'connect_timeout' => $timeout,     // 连接阶段
            'timeout'         => 0.0,          // 读写阶段不限；idle 由 curl 低速选项控制
            'http_errors'     => false,        // 统一由我们抛异常
        ]);
    }

    /* =========================================================
       普通请求
    ========================================================= */
    public function request(string $method, string $uri, array $options): array
    {
        /** @var array{status:int,headers:array,body:string} $res */
        $res  = $this->cli->request(strtoupper($method), ltrim($uri, '/'), $options);

        $code = $res['status'];
        if ($code < 200 || $code >= 300) {
            throw new HttpException("HTTP $code $uri", $code, $res['body']);
        }
        return $res;
    }


    /* =========================================================
       流式 (SSE / ChatGPT)
    ========================================================= */
    public function stream(string $method, string $uri, array $opt, callable $onChunk): void
    {
        $opt['stream'] = true;

        /* 低速限制：连续 idle 秒 <1B/s 视为超时 */
        if (isset($opt['idleTimeout']) && $opt['idleTimeout'] > 0) {
            $opt['curl'] = [
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME  => $opt['idleTimeout'],
            ];
            unset($opt['idleTimeout']);
        }

        /** @var ResponseInterface $res */
        $res = $this->cli->request(strtoupper($method), ltrim($uri, '/'), $opt);

        if ($res->getStatusCode() >= 400) {
            throw new StreamException('stream http ' . $res->getStatusCode(), $res->getStatusCode());
        }

        /** @var StreamInterface $body */
        $body = $res->getBody();
        StreamDecoder::decode($body, $onChunk);
    }
}
