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

        foreach ($headers as $key => $value) {
            if (self::isSensitiveHeader((string)$key)) {
                $masked[$key] = ['***'];
                continue;
            }
            $masked[$key] = $value;
        }

        return $masked;
    }

    /**
     * 规则型敏感头判断
     *
     * 原则：
     * 1. 明确高风险头固定脱敏
     * 2. 对包含 token/secret/key/sign 等关键词的 header 自动脱敏
     * 3. 避免每新增一个 x-xxx-token 都要改 SDK
     */
    private static function isSensitiveHeader(string $headerName): bool
    {
        $name = strtolower(trim($headerName));
        if ($name === '') {
            return false;
        }

        // 明确高优先级敏感头
        if (in_array($name, ['authorization', 'proxy-authorization'], true)) {
            return true;
        }

        // 通用规则：包含这些关键词的 header 统一脱敏
        foreach (['token', 'secret', 'key', 'signature', 'sign'] as $kw) {
            if (str_contains($name, $kw)) {
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
