<?php
declare(strict_types=1);
namespace LayBot;

use LayBot\Exception\LayBotException;
use Psr\Http\Message\ResponseInterface;

/**
 * Portal —— 企业侧运营 / 查询接口统一入口
 * ------------------------------------------------------------
 *   $pt = new Portal('demo_key');            // 共享 1 个 Client
 *
 *   // 通用：method = 'GET'|'POST'|'DELETE'...
 *   $json = $pt->call('POST','/v1/usage/doc', ['start_date'=>'2025-07-01']);
 *
 *   // 便捷糖：
 *   $mods = $pt->models();
 *   $doc  = $pt->docUsage(['start_date'=>'2025-07-01','end_date'=>'2025-07-31']);
 *   $acct = $pt->accountUsage();
 */
final class Portal
{
    private Client $cli;

    public function __construct(string|array|Client $cfg)
    {
        $this->cli = $cfg instanceof Client ? $cfg
            : new Client(
                is_string($cfg) ? $cfg : ($cfg['apikey'] ?? ''),
                is_array($cfg)  ? ($cfg['base'] ?? Vendor::defaultBase(Vendor::DEFAULT))
                    : Vendor::defaultBase(Vendor::DEFAULT),
                is_array($cfg)  ? $cfg : []
            );
    }
    /**
     * 通用直通
     *
     * @param string $method  HTTP 动词，默认 POST
     * @param string $path    形如 /v1/usage/doc
     * @param array  $body    JSON 参数（GET 时仍放此处 → SDK 自动放 query）
     * @param bool   $whole   true=返回完整 LayBot 包；false=data 字段
     */
    public function call(
        string $method,
        string $path,
        array  $body = [],
        bool   $whole = false
    ): array {
        $path = ltrim($path, '/');
        $opt  = strtoupper($method) === 'GET'
            ? ['query' => $body]
            : ['json'  => $body];

        /** @var ResponseInterface $res */
        $res = $this->cli->raw()->{$method}($path, $opt);
        $arr = json_decode((string)$res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $whole ? $arr : ($arr['data'] ?? $arr);
    }

    /* ==================== 便捷糖（全部 POST） ==================== */

    /** /v1/models  [可传 {"with_detail":1} 等扩展] */
    public function models(array $filter = []): array
    {
        return $this->call('POST', '/v1/models', $filter);
    }

    /** /v1/usage/doc  start_date/end_date 等放 body */
    public function docUsage(array $params = []): array
    {
        return $this->call('POST', '/v1/usage/doc', $params);
    }

    /** /v1/usage/account  基本无参 */
    public function accountUsage(): array
    {
        return $this->call('POST', '/v1/usage/account');
    }
}