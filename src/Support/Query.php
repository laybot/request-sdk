<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

final class Query
{
    /**
     * 规范化 query 参数
     *
     * 当前支持：
     * - brackets: a[]=1&a[]=2
     * - repeat:   a=1&a=2
     *
     * 返回值：
     * - brackets: 直接返回数组，交给 Guzzle 编码
     * - repeat:   返回 query string
     */
    public static function normalize(array $query, string $format = 'brackets'): array|string
    {
        $format = strtolower(trim($format));

        return match ($format) {
            'repeat' => self::buildRepeatQuery($query),
            default => $query,
        };
    }

    private static function buildRepeatQuery(array $query): string
    {
        $pairs = [];
        self::appendPairs($pairs, $query);
        return implode('&', $pairs);
    }

    private static function appendPairs(array &$pairs, array $data, ?string $prefix = null): void
    {
        foreach ($data as $key => $value) {
            $key = (string)$key;
            $name = $prefix === null ? $key : "{$prefix}[{$key}]";

            if (is_array($value)) {
                if (self::isList($value)) {
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            self::appendPairs($pairs, $item, $name);
                        } else {
                            $pairs[] = rawurlencode($name) . '=' . rawurlencode(self::scalarToString($item));
                        }
                    }
                } else {
                    self::appendPairs($pairs, $value, $name);
                }
                continue;
            }

            $pairs[] = rawurlencode($name) . '=' . rawurlencode(self::scalarToString($value));
        }
    }

    private static function scalarToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return Json::encode($value);
    }

    private static function isList(array $arr): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($arr);
        }

        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }

    private function __construct()
    {
    }
}
