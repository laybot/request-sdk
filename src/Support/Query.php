<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

final class Query
{
    /**
     * 支持四种数组编码：
     *
     * indices:
     *   ids[0]=1&ids[1]=2
     *
     * brackets:
     *   ids[]=1&ids[]=2
     *
     * repeat:
     *   ids=1&ids=2
     *
     * comma:
     *   ids=1,2
     */
    public static function build(
        array|string|null $query,
        string $arrayFormat = 'brackets'
    ): string {
        if ($query === null || $query === '' || $query === []) {
            return '';
        }

        if (is_string($query)) {
            return ltrim($query, '?');
        }

        if (!in_array(
            $arrayFormat,
            ['indices', 'brackets', 'repeat', 'comma'],
            true
        )) {
            throw new \InvalidArgumentException(
                "unsupported query array format: {$arrayFormat}"
            );
        }

        $pairs = [];

        foreach ($query as $key => $value) {
            self::flatten(
                (string)$key,
                $value,
                $arrayFormat,
                $pairs
            );
        }

        return implode('&', array_map(
            static fn (array $pair): string =>
                rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]),
            $pairs
        ));
    }

    /**
     * @param list<array{0:string,1:string}> $pairs
     */
    private static function flatten(
        string $key,
        mixed $value,
        string $arrayFormat,
        array &$pairs
    ): void {
        if (!is_array($value)) {
            $pairs[] = [$key, self::scalar($value)];
            return;
        }

        if ($value === []) {
            return;
        }

        $isList = array_is_list($value);

        if (
            $isList
            && $arrayFormat === 'comma'
            && self::isScalarList($value)
        ) {
            $pairs[] = [
                $key,
                implode(',', array_map(self::scalar(...), $value)),
            ];
            return;
        }

        foreach ($value as $childKey => $childValue) {
            $childName = match (true) {
                !$isList => $key . '[' . $childKey . ']',
                $arrayFormat === 'indices' =>
                    $key . '[' . $childKey . ']',
                $arrayFormat === 'brackets' =>
                    $key . '[]',
                $arrayFormat === 'repeat' =>
                $key,
                $arrayFormat === 'comma' =>
                    $key . '[]',
                default => $key,
            };

            self::flatten(
                $childName,
                $childValue,
                $arrayFormat,
                $pairs
            );
        }
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value === true => '1',
            $value === false => '0',
            is_scalar($value) => (string)$value,
            $value instanceof \Stringable => (string)$value,
            default => throw new \InvalidArgumentException(
                'query value must be scalar, null, array or Stringable'
            ),
        };
    }

    private static function isScalarList(array $values): bool
    {
        foreach ($values as $value) {
            if (
                is_array($value)
                || is_object($value) && !$value instanceof \Stringable
            ) {
                return false;
            }
        }

        return true;
    }

    private function __construct()
    {
    }
}
