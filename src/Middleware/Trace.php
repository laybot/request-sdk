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
                    'body' => self::formatBodyForLog(
                        (string)$request->getBody(),
                        $request->getHeaderLine('Content-Type')
                    ),
                ]);

                return $handler($request, $options)->then(
                    static function ($response) use ($logger) {
                        if ($response instanceof ResponseInterface) {
                            $logger->debug('[HTTP] recv', [
                                'status' => $response->getStatusCode(),
                                'headers' => self::maskHeaders($response->getHeaders()),
                                'body' => self::formatBodyForLog(
                                    (string)$response->getBody(),
                                    $response->getHeaderLine('Content-Type')
                                ),
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

        foreach ($headers as $key => $value) {
            if (self::isSensitiveHeader((string)$key)) {
                $masked[$key] = ['***'];
                continue;
            }
            $masked[$key] = $value;
        }

        return $masked;
    }

    private static function isSensitiveHeader(string $headerName): bool
    {
        $name = strtolower(trim($headerName));
        if ($name === '') {
            return false;
        }

        if (in_array($name, ['authorization', 'proxy-authorization'], true)) {
            return true;
        }

        foreach (['token', 'secret', 'key', 'signature', 'sign'] as $kw) {
            if (str_contains($name, $kw)) {
                return true;
            }
        }

        return false;
    }

    private static function formatBodyForLog(string $body, string $contentType, int $max = 2000): string
    {
        if ($body === '') {
            return '';
        }

        $contentType = strtolower(trim($contentType));

        if ($contentType !== '' && !self::isTextLikeContentType($contentType)) {
            return '[binary omitted]';
        }

        return self::limitBody($body, $max);
    }

    private static function isTextLikeContentType(string $contentType): bool
    {
        foreach ([
                     'application/json',
                     'application/xml',
                     'application/x-www-form-urlencoded',
                     'application/javascript',
                     'application/x-ndjson',
                     'text/',
                     'xml',
                     'json',
                 ] as $needle) {
            if (str_contains($contentType, $needle)) {
                return true;
            }
        }

        return false;
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
