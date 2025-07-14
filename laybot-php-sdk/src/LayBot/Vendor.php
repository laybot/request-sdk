<?php
declare(strict_types=1);
namespace LayBot;

final class Vendor
{
    public const DEFAULT = 'laybot';

    /**
     * TABLE 里只放可序列化数据（字符串 / 数组 / null）——
     * hdr 存的是当前类的 **私有静态方法名**，避免闭包限制。
     *
     * @var array<string, array{
     *      base:string,
     *      ep:array<string,string>,
     *      hdr?: string          // method name in this class
     * }>
     */
    private static array $TABLE = [
        'laybot' => [
            'base' => 'https://api.laybot.cn',
            'ep'   => [
                'chat'  => '/v1/chat',
                'embed' => '/v1/chat',
            ],
            'hdr'  => 'laybotHeader',
        ],

        'openai' => [
            'base' => 'https://api.openai.com',
            'ep'   => [
                'chat'  => '/v1/chat/completions',
                'embed' => '/v1/embeddings',
            ],
            'hdr'  => 'bearerHeader',
        ],

        'deepseek' => [
            'base' => 'https://api.deepseek.com',
            'ep'   => [
                'chat'  => '/v1/chat/completions',
                'embed' => '/v1/embeddings',
            ],
            'hdr'  => 'bearerHeader',
        ],

        'grok' => [
            'base' => 'https://api.groq.com',
            'ep'   => [
                'chat'  => '/openai/v1/chat/completions',
                'embed' => '/openai/v1/embeddings',
            ],
            'hdr'  => 'bearerHeader',
        ],

        'qwen3' => [
            'base' => 'https://dashscope.aliyuncs.com',
            'ep'   => [
                'chat' => '/v1/chat/completions',
            ],
            'hdr'  => 'qwenHeader',            // 自定义方法
        ],

        'azure-openai' => [
            'base' => 'https://{RESOURCE}.openai.azure.com',
            'ep'   => [
                'chat'  => '/openai/deployments/{DEPLOY}/chat/completions?api-version=2024-05-01-preview',
                'embed' => '/openai/deployments/{DEPLOY}/embeddings?api-version=2024-05-01-preview',
            ],
            'hdr'  => 'azureHeader',
        ],
        /* … append more vendors here … */
    ];

    /* ---------- 对外帮助函数 ---------- */

    public static function info(string $vendor): array
    {
        return self::$TABLE[$vendor] ?? self::$TABLE[self::DEFAULT];
    }

    public static function defaultBase(string $vendor): string
    {
        return self::info($vendor)['base'];
    }

    public static function defaultEndpoint(string $vendor, string $cap): ?string
    {
        return self::info($vendor)['ep'][$cap] ?? null;
    }

    public static function patchHeaders(string $vendor, string $apiKey): array
    {
        $method = self::info($vendor)['hdr'] ?? null;
        if ($method === null) {
            return [];                          // 没有定制 header
        }
        /** @var callable $cb */
        $cb = [self::class, $method];
        return $cb($apiKey);
    }

    /* ---------- 各厂商 Header 组装 ---------- */
    private static function laybotHeader(string $k): array
    {
        return ['X-API-Key' => $k];
    }

    private static function bearerHeader(string $k): array
    {
        return ['Authorization' => 'Bearer '.$k];
    }

    private static function qwenHeader(string $k): array
    {
        return [
            'Authorization'      => 'Bearer '.$k,
            'X-DashScope-SOURCE' => 'marketplace',
        ];
    }

    private static function azureHeader(string $k): array
    {
        return ['api-key' => $k];
    }
}
