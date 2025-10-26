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
use Psr\Log\LoggerInterface;
use Psr\Http\Message\StreamInterface;

final class GuzzleTransport implements TransportInterface
{
    private Client $cli;

    public function __construct(
        string $base,
        float  $timeout,
        bool   $verify,
        int    $retry,
        LoggerInterface $logger
    ){
        $stack = HandlerStack::create();
        if ($retry > 0) {
            $stack->push(Retry::middleware($retry));
        }
        $stack->push(Trace::middleware($logger));

        $this->cli = new Client([
            'base_uri' => $base,
            'timeout'  => 0,          // 总时长不限制；idle 单独控制
            'verify'   => $verify,
            'handler'  => $stack,
            'connect_timeout' => $timeout,
        ]);
    }

    /* ---------------- 普通请求 ---------------- */
    public function request(string $method, string $uri, array $opt): array
    {
        $res  = $this->cli->request($method, ltrim($uri,'/'), $opt);
        $code = $res->getStatusCode();
        if ($code < 200 || $code >= 300) {
            throw new HttpException("HTTP $code",$code,(string)$res->getBody());
        }
        return [
            'status'  => $code,
            'body'    => (string)$res->getBody(),
            'headers' => $res->getHeaders(),
        ];
    }

    /* ---------------- 流式 (SSE) --------------- */
    public function stream(string $method, string $uri, array $opt, callable $onChunk): void
    {
        $opt['stream'] = true;

        // 低速限制：连续 idle 秒平均 <1B/s 视为超时
        if (isset($opt['idleTimeout']) && $opt['idleTimeout'] > 0) {
            $opt['curl'] = [
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME  => $opt['idleTimeout'],
            ];
            unset($opt['idleTimeout']);
        }

        $res = $this->cli->request($method, ltrim($uri,'/'), $opt);
        if ($res->getStatusCode() >= 400) {
            throw new StreamException('stream http '.$res->getStatusCode(),$res->getStatusCode());
        }
        /** @var StreamInterface $body */
        $body = $res->getBody();
        StreamDecoder::decode($body, $onChunk);
    }
}
