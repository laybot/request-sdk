<?php
declare(strict_types=1);
namespace LayBot;

/** 能力类公共父类：注入 Client；拼装 URL+Body */
abstract class Base
{
    protected Client $cli;
    protected bool   $isLaybot;

    public function __construct(string|array|Client $cfg)
    {
        if ($cfg instanceof Client) {
            $this->cli      = $cfg;
            $this->isLaybot = self::looksLikeLaybot($cfg->baseUri());
            return;
        }
        if (is_string($cfg)) { $cfg=['apikey'=>$cfg]; }
        $base = $cfg['base'] ?? 'https://api.laybot.cn';
        $this->cli      = new Client($cfg['apikey'],$base,$cfg);
        $this->isLaybot = self::looksLikeLaybot($base);
    }
    private static function looksLikeLaybot(string $base): bool
    {   return str_contains($base,'api.laybot.cn'); }

    /**
     * @param array  $body    原始 body
     * @param string $cap     固定能力名（chat / batch / audio…）; 若空则不写
     * @param string $defPath 缺省 URL 路径，如 /v1/chat
     * @return array{url:string,body:array}
     */
    final protected function ready(array $body,string $cap,string $defPath): array
    {
        /* 1) vendor_extra flatten */
        if (isset($body['vendor_extra']) && is_array($body['vendor_extra'])) {
            $body = array_merge($body,$body['vendor_extra']);
            unset($body['vendor_extra']);
        }

        /* 2) endpoint -> URL path (并从 body 移除) */
        $path = $body['endpoint'] ?? $defPath;
        unset($body['endpoint']);

        /* 3) LayBot：写 capability */
        if ($this->isLaybot && $cap !== '') {
            $body['capability'] = $cap;          // 覆盖同名字段
        } else {
            unset($body['capability']);          // 直连官方
        }
        return ['url'=>ltrim($path,'/'),'body'=>$body];
    }

    /** Workerman 环境探测：只在 loop 已启动时返回真 */
    protected static function isWorkermanRuntime(): bool
    {
        return class_exists('Workerman\Worker',false)
            && method_exists('Workerman\Worker','getAllWorkers')
            && !empty(\Workerman\Worker::getAllWorkers());
    }
}
