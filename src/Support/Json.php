<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

use LayBot\Request\Exception\JsonException;

final class Json
{
    public static function encode(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (\JsonException $error) {
            throw new JsonException(
                'JSON encoding failed: ' . $error->getMessage(),
                previous: $error
            );
        }
    }

    public static function decodeAny(string $json): mixed
    {
        try {
            return json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $error) {
            throw new JsonException(
                'JSON decoding failed: ' . $error->getMessage(),
                previous: $error
            );
        }
    }

    public static function decodeArray(string $json): array
    {
        $value = self::decodeAny($json);

        if (!is_array($value)) {
            throw new JsonException('JSON root value is not an array/object');
        }

        return $value;
    }

    private function __construct()
    {
    }
}
