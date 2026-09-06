<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

final class Header
{
    /**
     * @param array<string,mixed> $headers
     * @return array<string,list<string>>
     */
    public static function normalize(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $name = strtolower(trim((string)$name));

            if ($name === '') {
                continue;
            }

            foreach ((array)$values as $value) {
                $normalized[$name][] = trim((string)$value);
            }
        }

        return $normalized;
    }

    /**
     * 大小写不敏感地合并 Header，后面的 Header 覆盖前面的 Header。
     *
     * @return array<string,mixed>
     */
    public static function merge(array ...$groups): array
    {
        $output = [];
        $indexes = [];

        foreach ($groups as $headers) {
            foreach ($headers as $name => $value) {
                $name = trim((string)$name);

                if ($name === '') {
                    continue;
                }

                self::assertSafe($name, $value);

                $lower = strtolower($name);

                if (isset($indexes[$lower])) {
                    unset($output[$indexes[$lower]]);
                }

                $indexes[$lower] = $name;
                $output[$name] = $value;
            }
        }

        return $output;
    }

    public static function has(array $headers, string $name): bool
    {
        $name = strtolower($name);

        foreach ($headers as $key => $_) {
            if (strtolower((string)$key) === $name) {
                return true;
            }
        }

        return false;
    }

    public static function values(array $headers, string $name): array
    {
        return self::normalize($headers)[strtolower($name)] ?? [];
    }

    public static function line(array $headers, string $name): string
    {
        return implode(', ', self::values($headers, $name));
    }

    /**
     * @param list<string> $names
     */
    public static function first(array $headers, array $names): ?string
    {
        foreach ($names as $name) {
            $value = self::line($headers, $name);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function setIfMissing(
        array $headers,
        string $name,
        mixed $value
    ): array {
        if (!self::has($headers, $name)) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    public static function remove(array $headers, string ...$names): array
    {
        $remove = array_map('strtolower', $names);

        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower((string)$name), $remove, true)) {
                unset($headers[$name]);
            }
        }

        return $headers;
    }

    /**
     * 跨域请求默认删除所有已知或调用方声明的凭证 Header。
     *
     * @param list<string> $customCredentialHeaders
     */
    public static function withoutCredentials(
        array $headers,
        array $customCredentialHeaders = []
    ): array {
        foreach (array_keys($headers) as $name) {
            $lower = strtolower((string)$name);

            $sensitive = in_array($lower, [
                    'authorization',
                    'proxy-authorization',
                    'cookie',
                    'set-cookie',
                    'x-api-key',
                    'x-api-access-key',
                    'x-api-app-key',
                    'x-api-app-id',
                    'x-inner-token',
                ], true)
                || str_contains($lower, 'authorization')
                || str_contains($lower, 'credential')
                || str_contains($lower, 'password')
                || str_contains($lower, 'token')
                || str_contains($lower, 'secret')
                || str_contains($lower, 'signature')
                || str_ends_with($lower, '-key');

            if (!$sensitive) {
                foreach ($customCredentialHeaders as $pattern) {
                    $pattern = strtolower(trim((string)$pattern));

                    if ($pattern === '') {
                        continue;
                    }

                    if (
                        str_ends_with($pattern, '*')
                        && str_starts_with(
                            $lower,
                            substr($pattern, 0, -1)
                        )
                    ) {
                        $sensitive = true;
                        break;
                    }

                    if ($lower === $pattern) {
                        $sensitive = true;
                        break;
                    }
                }
            }

            if ($sensitive) {
                unset($headers[$name]);
            }
        }

        return $headers;
    }


    public static function assertSafe(
        string $name,
        mixed $value
    ): void {
        $name = trim($name);

        /*
         * RFC 9110 field-name = token。
         * 允许任意合法自定义 Header，但禁止空格、冒号和控制字符。
         */
        if (
            $name === ''
            || preg_match(
                '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/',
                $name
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                "invalid HTTP header name: {$name}"
            );
        }

        foreach ((array)$value as $item) {
            if (
                strpbrk((string)$item, "\r\n") !== false
            ) {
                throw new \InvalidArgumentException(
                    "unsafe HTTP header value for {$name}"
                );
            }
        }
    }

    private function __construct()
    {
    }
}
