<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

final class LogSanitizer
{
    /**
     * SDK 内置敏感 Header。
     *
     * @var list<string>
     */
    private const SENSITIVE = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-api-access-key',
        'x-api-app-key',
        'x-api-app-id',
        'x-inner-token',
    ];

    /**
     * 对 Header 进行日志脱敏。
     *
     * $sensitiveHeaders 支持：
     *
     *   X-Custom-Identity
     *   X-Proxy-*
     *
     * 名称大小写不敏感；末尾星号表示前缀匹配。
     *
     * @param array<string,mixed> $headers
     * @param list<string> $sensitiveHeaders
     * @return array<string,mixed>
     */
    public static function headers(
        array $headers,
        array $sensitiveHeaders = []
    ): array {
        $output = [];

        foreach ($headers as $name => $value) {
            $lower = strtolower(trim((string)$name));

            if (self::isSensitive($lower, $sensitiveHeaders)) {
                $output[$name] = '***';
                continue;
            }

            $output[$name] = $value;
        }

        return $output;
    }

    /**
     * URL 日志默认完全移除 Query、Fragment 和用户信息。
     */
    public static function url(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return '[invalid-url]';
        }

        $scheme = isset($parts['scheme'])
            ? strtolower((string)$parts['scheme']) . '://'
            : '';

        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port'])
            ? ':' . (int)$parts['port']
            : '';

        $path = (string)($parts['path'] ?? '/');

        return $scheme . $host . $port . $path;
    }

    /**
     * 请求/响应正文默认禁止写入日志。
     */
    public static function body(
        string $body,
        string $contentType = '',
        bool $enabled = false,
        int $maxBytes = 1024
    ): string {
        if (!$enabled) {
            return '[body logging disabled]';
        }

        $contentType = strtolower($contentType);

        if (
            $contentType !== ''
            && !str_contains($contentType, 'json')
            && !str_starts_with($contentType, 'text/')
            && !str_contains($contentType, 'xml')
            && !str_contains($contentType, 'form-urlencoded')
        ) {
            return '[binary body omitted]';
        }

        if (strlen($body) <= $maxBytes) {
            return $body;
        }

        return substr($body, 0, $maxBytes) . '...<truncated>';
    }

    /**
     * @param list<string> $customNames
     */
    private static function isSensitive(
        string $headerName,
        array $customNames
    ): bool {
        if (in_array($headerName, self::SENSITIVE, true)) {
            return true;
        }

        /*
         * 常见凭证名称自动识别。
         */
        if (
            str_contains($headerName, 'authorization')
            || str_contains($headerName, 'credential')
            || str_contains($headerName, 'password')
            || str_contains($headerName, 'token')
            || str_contains($headerName, 'secret')
            || str_contains($headerName, 'signature')
            || str_ends_with($headerName, '-key')
        ) {
            return true;
        }

        foreach ($customNames as $customName) {
            $pattern = strtolower(trim($customName));

            if ($pattern === '') {
                continue;
            }

            if (str_ends_with($pattern, '*')) {
                $prefix = substr($pattern, 0, -1);

                if (
                    $prefix !== ''
                    && str_starts_with($headerName, $prefix)
                ) {
                    return true;
                }

                continue;
            }

            if (hash_equals($pattern, $headerName)) {
                return true;
            }
        }

        return false;
    }

    private function __construct()
    {
    }
}
