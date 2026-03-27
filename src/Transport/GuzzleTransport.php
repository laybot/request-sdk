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
        string $baseUri,
        float $timeout = 10.0,
        bool $verify = true,
        int $retryTimes = 2,
        ?LoggerInterface $logger = null
    ) {
        $stack = HandlerStack::create();

        if ($logger) {
            $stack->push(Trace::middleware($logger), 'trace');
        }

        if ($retryTimes > 0) {
            $stack->push(Retry::middleware($retryTimes), 'retry');
        }

        $this->cli = new Client([
            'base_uri' => rtrim($baseUri, '/') . '/',
            'handler' => $stack,
            'verify' => $verify,
            'connect_timeout' => $timeout,
            'timeout' => $timeout,
            'http_errors' => false,
        ]);
    }

    public function request(string $method, string $uri, array $options): array
    {
        /** @var ResponseInterface $res */
        $res = $this->cli->request(strtoupper($method), ltrim($uri, '/'), $options);

        $status = $res->getStatusCode();
        $headers = $res->getHeaders();
        $body = (string)$res->getBody();

        if ($status < 200 || $status >= 300) {
            throw new HttpException(
                message: sprintf('HTTP %d %s', $status, $uri),
                statusCode: $status,
                responseBody: $body,
                responseHeaders: $headers,
                method: strtoupper($method),
                uri: $uri
            );
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    public function stream(string $method, string $uri, array $options, callable $onChunk): void
    {
        $options['stream'] = true;

        if (isset($options['idleTimeout']) && (int)$options['idleTimeout'] > 0) {
            $options['curl'] = [
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => (int)$options['idleTimeout'],
            ];
            unset($options['idleTimeout']);
        }

        /** @var ResponseInterface $res */
        $res = $this->cli->request(strtoupper($method), ltrim($uri, '/'), $options);

        if ($res->getStatusCode() >= 400) {
            throw new StreamException(
                'stream http ' . $res->getStatusCode(),
                $res->getStatusCode()
            );
        }

        /** @var StreamInterface $body */
        $body = $res->getBody();
        StreamDecoder::decode($body, $onChunk);
    }
}
