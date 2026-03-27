<?php
declare(strict_types=1);

namespace LayBot\Request\Support;

use LayBot\Request\Exception\JsonException;

final class Json
{
    public static function encode(mixed $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new JsonException(
                'json encode failed: ' . $e->getMessage(),
                0,
                '',
                $e
            );
        }
    }

    public static function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new JsonException(
                'json decode failed: ' . $e->getMessage(),
                0,
                $json,
                $e
            );
        }

        if (!is_array($decoded)) {
            throw new JsonException('json decode result is not array', 0, $json);
        }

        return $decoded;
    }

    private function __construct()
    {
    }
}
