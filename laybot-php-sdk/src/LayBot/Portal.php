<?php
declare(strict_types=1);

namespace LayBot;

use Psr\Http\Message\ResponseInterface;
use LayBot\Exception\LayBotException;

/**
 * Portal —— 企业侧运营 / 查询接口统一入口（针对 api.laybot.cn）
 *
 * $pt = new Portal('demo_key');
 * $mods = $pt->models();                     // GET /v1/models
 * $doc  = $pt->docUsage(['start_date'=>'2025-07-01']);
 * $any  = $pt->call('POST','/v1/billing', ['month'=>'2025-07']);
 */
final class Portal extends Base
{
    /**
     * 构造
     *  - $cfg 可是 apiKey 字符串、数组、或者已经 new 好的 Client
     *  - vendor 固定 laybot，base 默认 https://api.laybot.cn/
     */
    public function __construct(string|array|Client $cfg)
    {
        // 强制 vendor=laybot，保证 patchHeaders 带 X-API-Key
        if (is_array($cfg)) {
            $cfg += ['vendor' => Vendor::DEFAULT];
        } elseif (is_string($cfg)) {
            $cfg = ['apikey' => $cfg, 'vendor' => Vendor::DEFAULT];
        }
        parent::__construct($cfg);
    }

    /**
     * 通用直通
     *
     * @param string $method HTTP 动词 GET / POST / DELETE 等
     * @param string $path   以 / 开头的网关路径，如 /v1/usage/doc
     * @param array  $params GET->query / POST->json
     * @param bool   $whole  true=返回完整 LayBot 包；false=仅 data 字段
     */
    public function call(
        string $method,
        string $path,
        array  $params = [],
        bool   $whole = false
    ): array {
        $method = strtoupper($method);
        $path   = ltrim($path, '/');

        /* ---------- 组装 Guzzle 选项 ---------- */
        $opt = [];
        if ($method === 'GET') {
            $opt['query'] = $params;
        } else {
            // 解决 [] 被编码成 "[]" 422 —— 当为空发送 {}
            $opt['json'] = $params === [] ? (object)[] : $params;
        }

        /** @var ResponseInterface $res */
        $res = $this->cli->raw()->{$method}($path, $opt);
        $arr = json_decode((string)$res->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $whole ? $arr : ($arr['data'] ?? $arr);
    }

    /* ====================== 便捷方法 ====================== */

    /** GET /v1/models —— 授权模型列表 */
    public function models(): array
    {   return $this->call('POST', '/v1/models'); }

    /** POST /v1/usage/doc —— 用量统计（可传起止日期） */
    public function docUsage(array $params = []): array
    {   return $this->call('POST', '/v1/usage/doc', $params); }

    /** GET /v1/usage/account —— 钱包/余额 */
    public function accountUsage(): array
    {   return $this->call('POST', '/v1/usage/account'); }
}
