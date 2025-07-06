<?php
declare(strict_types=1);
namespace LayBot;

abstract class Base
{
    protected Client $cli;
    protected bool   $isLaybot;

    public function __construct(string|array|Client $cfg)
    {
        if ($cfg instanceof Client) {
            $this->cli = $cfg;
            $this->isLaybot = self::isLaybotBase($cfg->baseUri());
            return;
        }
        if (is_string($cfg)) {                 // 仅 API-Key
            $cfg = ['apikey'=>$cfg];
        }
        $base = $cfg['base'] ?? 'https://api.laybot.cn';
        $this->cli = new Client($cfg['apikey'], $base, $cfg['guzzle'] ?? []);
        $this->isLaybot = self::isLaybotBase($base);
    }

    private static function isLaybotBase(string $base): bool
    {   return str_contains($base, 'api.laybot.cn'); }

    /**
     * 将 vendor_extra 拉平；并根据 isLaybot 决定 capability / endpoint 是否保留。
     *
     * @param string $cap   固定 capability 值（chat、batch…）
     * @param string $dftEp 当 body 未明确 endpoint 时的默认值
     */
    final protected function prepare(array $body, string $cap, string $dftEp): array
    {
        /* 1) vendor_extra 扁平化 */
        if (isset($body['vendor_extra']) && is_array($body['vendor_extra'])) {
            $body = array_merge($body, $body['vendor_extra']);
            unset($body['vendor_extra']);
        }

        /* 2) LayBot 中台：补 capability / endpoint（endpoint 必存在） */
        if ($this->isLaybot) {
            $body['capability'] = $cap;
            $body['endpoint']   = $body['endpoint'] ?? $dftEp;
        } else {
            /* 3) 直连 OpenAI：移除自定义字段 */
            unset($body['capability'], $body['endpoint']);
        }
        return $body;
    }
}
