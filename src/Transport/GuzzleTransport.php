<?php
declare(strict_types=1);

namespace LayBot\Request\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Exception\HttpException;
use LayBot\Request\Exception\StreamException;
use LayBot\Request\Middleware\Retry;
use LayBot\Request\Middleware\Trace;
use LayBot\Request\Util\StreamDecoder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

final class GuzzleTransport implements TransportInterface
{
    private Client $cli;

    public function __construct(
        string            $baseUri,
        float             $timeout     = 10.0,
        bool              $verify      = false,
        int               $retryTimes  = 2,
        ?LoggerInterface  $logger      = null
    ) {
        /* 1. HandlerStack（只放 Trace + Retry，保持 ResponseInterface 流通） */
        $stack = HandlerStack::create();

        if ($logger) {
            $stack->push(Trace::middleware($logger), 'trace');
        }
        if ($retryTimes > 0) {
            $stack->push(Retry::middleware($retryTimes), 'retry');
        }

        /* 2. Guzzle Client */
        $this->cli = new Client([
            'base_uri'        => rtrim($baseUri, '/') . '/',
            'handler'         => $stack,
            'verify'          => $verify,
            'connect_timeout' => $timeout,
            'timeout'         => 0.0,
            'http_errors'     => false,
        ]);
    }

    /* =============== 普通请求 =============== */
    public function request(string $method, string $uri, array $opt): array
    {
        /** @var ResponseInterface $res */
        $res  = $this->cli->request(strtoupper($method), ltrim($uri,'/'), $opt);
        $code = $res->getStatusCode();

        if ($code < 200 || $code >= 300) {
            throw new HttpException("HTTP $code $uri", $code, (string)$res->getBody());
        }

        /* 仅在这里把 ResponseInterface 转成数组，供上层统一使用 */
        return [
            'status'  => $code,
            'headers' => $res->getHeaders(),
            'body'    => (string)$res->getBody(),
        ];
    }

    /* =============== 流式请求 (SSE) =============== */
    public function stream(string $method, string $uri, array $opt, callable $onChunk): void
    {
        $opt['stream'] = true;

        if (isset($opt['idleTimeout']) && $opt['idleTimeout'] > 0) {
            $opt['curl'] = [
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME  => $opt['idleTimeout'],
            ];
            unset($opt['idleTimeout']);
        }

        /** @var ResponseInterface $res */
        $res = $this->cli->request(strtoupper($method), ltrim($uri,'/'), $opt);
        if ($res->getStatusCode() >= 400) {
            throw new StreamException('stream http '.$res->getStatusCode(), $res->getStatusCode());
        }

        /** @var StreamInterface $body */
        $body = $res->getBody();
        StreamDecoder::decode($body, $onChunk);
    }
}
