<?php
declare(strict_types=1);

namespace LayBot\Request\Middleware;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class Trace
{
    public static function middleware(LoggerInterface $logger): callable
    {
        return static function (callable $handler) use ($logger): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $logger): PromiseInterface {
                $logger->debug('[HTTP] send', [
                    'method' => $request->getMethod(),
                    'uri' => (string)$request->getUri(),
                    'headers' => self::maskHeaders($request->getHeaders()),
                    'body' => self::limitBody((string)$request->getBody()),
                ]);

                return $handler($request, $options)->then(
                    static function ($response) use ($logger) {
                        if ($response instanceof ResponseInterface) {
                            $logger->debug('[HTTP] recv', [
                                'status' => $response->getStatusCode(),
                                'headers' => self::maskHeaders($response->getHeaders()),
                                'body' => self::limitBody((string)$response->getBody()),
                            ]);
                        } elseif (is_array($response)) {
                            $logger->debug('[HTTP] recv', $response);
                        }

                        return $response;
                    }
                );
            };
        };
    }

    private static function maskHeaders(array $headers): array
    {
        $masked = [];
        $sensitive = [
            'authorization',
            'x-api-key',
            'proxy-authorization',
            'x-inner-token',
        ];

        foreach ($headers as $key => $value) {
            $lower = strtolower((string)$key);
            if (in_array($lower, $sensitive, true)) {
                $masked[$key] = ['***'];
                continue;
            }
            $masked[$key] = $value;
        }

        return $masked;
    }

    private static function limitBody(string $body, int $max = 2000): string
    {
        if ($max <= 0) {
            return '';
        }

        if (strlen($body) <= $max) {
            return $body;
        }

        return substr($body, 0, $max) . '...<truncated>';
    }

    private function __construct()
    {
    }
}
