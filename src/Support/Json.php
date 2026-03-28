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
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
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

    /**
     * 兼容旧行为：要求解码结果必须为 array
     *
     * @return array<mixed>
     */
    public static function decode(string $json): array
    {
        return self::decodeArray($json);
    }

    /**
     * 通用 JSON 解码：返回 mixed
     *
     * 支持：
     * - object => array
     * - list   => array
     * - string => string
     * - number => int/float
     * - bool   => bool
     * - null   => null
     */
    public static function decodeAny(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new JsonException(
                'json decode failed: ' . $e->getMessage(),
                0,
                $json,
                $e
            );
        }
    }

    /**
     * JSON 解码并要求结果必须为 array
     *
     * @return array<mixed>
     */
    public static function decodeArray(string $json): array
    {
        $decoded = self::decodeAny($json);

        if (!is_array($decoded)) {
            throw new JsonException('json decode result is not array', 0, $json);
        }

        return $decoded;
    }

    /**
     * JSON 解码为对象（stdClass / scalar / null）
     */
    public static function decodeObject(string $json): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new JsonException(
                'json decode failed: ' . $e->getMessage(),
                0,
                $json,
                $e
            );
        }
    }

    /**
     * 判断字符串是否为合法 JSON
     */
    public static function isJson(string $json): bool
    {
        if (trim($json) === '') {
            return false;
        }

        try {
            json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return true;
        } catch (\JsonException) {
            return false;
        }
    }

    private function __construct()
    {
    }
}
