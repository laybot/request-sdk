<?php
declare(strict_types=1);

namespace LayBot\Request;

use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Exception\JsonException;
use LayBot\Request\Support\Env;
use LayBot\Request\Transport\GuzzleTransport;
use LayBot\Request\Transport\WorkermanTransport;
use LayBot\Request\Signer\{Hmac,Bearer,Basic,ApiKey,Inner,None};

final class Client
{
    /* ===============================================================
       0. 对外快捷入口
    =============================================================== */
    public static function make(array $opts): self
    {
        return new self($opts);               // 直接传数组
    }

    /* ===============================================================
       1. 构造：既可传 Config，也可传数组
    =============================================================== */
    private Config $cfg;
    private TransportInterface $driver;

    public function __construct(Config|array $opts)
    {
        $this->cfg    = is_array($opts) ? self::normalize($opts) : $opts;
        $this->driver = $this->pick();
    }

    /* ===============================================================
       2. 万能发送器 —— 三方 API 参数完全透传
    =============================================================== */
    public function send(
        string  $method,
        string  $path,
        array   $opt         = [],
        bool    $jsonDecode  = true
    ) {
        // 2-1 公共 header / 签名
        $opt['headers'] = array_merge(
            $opt['headers'] ?? [],
            $this->cfg->headers,
            $this->cfg->signer->sign(
                $method,
                $path,
                $opt['body'] ?? ($opt['json'] ?? '')
            )
        );

        // 2-2 默认超时
        $opt['timeout'] ??= $this->cfg->timeout;

        // 2-3 json => 自动编码到 body
        if (isset($opt['json'])) {
            $opt['body'] = json_encode($opt['json'], JSON_UNESCAPED_UNICODE);
            unset($opt['json']);
        }

        // 2-4 真请求
        $res = $this->driver->request($method, ltrim($path, '/'), $opt);

        // 2-5 是否自动解析 JSON
        if (!$jsonDecode) {
            return $res['body'];
        }
        $arr = json_decode($res['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonException('invalid json ' . $res['body'], $res['status'], $res['body']);
        }
        return $arr;
    }

    /* ===============================================================
       3. 常用便捷方法（全部基于 send）
    =============================================================== */
    public function get(string $path, array $query = [], array $hdr = []): array
    {
        return $this->send('GET', $path, ['query' => $query, 'headers' => $hdr]);
    }

    public function postJson(string $path, array $json = [], array $hdr = []): array
    {
        return $this->send('POST', $path, ['json' => $json, 'headers' => $hdr]);
    }

    public function upload(
        string $path,
        string $field,
        string $file,
        array  $extra = [],
        array  $hdr   = []
    ): array {
        $multi = [
            ['name'     => $field,
                'contents' => fopen($file, 'r'),
                'filename' => basename($file)]
        ];
        foreach ($extra as $k => $v) {
            $multi[] = ['name' => $k, 'contents' => $v];
        }
        return $this->send('POST', $path, ['multipart' => $multi, 'headers' => $hdr]);
    }

    /* ===============================================================
       4. SSE / ChatGPT 流式
    =============================================================== */
    public function stream(
        string   $path,
        array    $json,
        callable $cb,
        array    $hdr = [],
        array    $opt = []    // ['connect'=>10,'idle'=>180,'transport'=>'auto|guzzle|workerman']
    ): void {
        $body = json_encode($json, JSON_UNESCAPED_UNICODE);

        $hdr = array_merge(
            $hdr,
            $this->cfg->headers,
            $this->cfg->signer->sign('POST', $path, $body)
        );

        /* ---- 动态选流式驱动 ---- */
        $mode   = $opt['transport'] ?? ($this->cfg->transport === 'workerman' ? 'workerman' : 'auto');
        $driver = $this->driver;

        if ($mode === 'workerman' || ($mode === 'auto' && Env::inWorkermanLoop())) {
            $driver = new WorkermanTransport(
                $this->cfg->baseUri,
                $this->cfg->timeout,
                $this->cfg->verify,
                $this->cfg->retryTimes,
                $this->cfg->logger
            );
        }

        $driver->stream(
            'POST',
            ltrim($path, '/'),
            [
                'headers'        => $hdr,
                'body'           => $body,
                'connectTimeout' => $opt['connect'] ?? $this->cfg->timeout,
                'idleTimeout'    => $opt['idle']    ?? 0,
            ],
            $cb
        );
    }

    /* ===============================================================
       5. 把数组 opts => Config（Signer 自动推断）
    =============================================================== */
    private static function normalize(array $o): Config
    {
        if (empty($o['base_uri'])) {
            throw new \InvalidArgumentException('base_uri required');
        }

        $signer = $o['signer'] ?? match (true) {
            isset($o['api_key'],$o['api_secret'])          => new Hmac($o['api_key'],$o['api_secret']),
            isset($o['token'])                             => new Bearer($o['token']),
            isset($o['username'],$o['password'])           => new Basic($o['username'],$o['password']),
            isset($o['inner_token'])                       => new Inner($o['inner_token']),
            isset($o['api_key'])                           => new ApiKey($o['api_key'],$o['header']??'X-API-Key'),
            default                                        => new None(),
        };

        return new Config(
            baseUri   : $o['base_uri'],
            headers   : $o['headers']   ?? [],
            timeout   : $o['timeout']   ?? 10.0,
            transport : $o['transport'] ?? 'auto',
            retryTimes: $o['retry']     ?? 2,
            verify    : $o['verify']    ?? false,
            signer    : $signer,
            logger    : $o['logger']    ?? null,
        );
    }

    /* ===============================================================
       6. Driver 选择（常规请求）
    =============================================================== */
    private function pick(): TransportInterface
    {
        if ($this->cfg->transport === 'workerman') {
            return new WorkermanTransport(
                $this->cfg->baseUri,
                $this->cfg->timeout,
                $this->cfg->verify,
                $this->cfg->retryTimes,
                $this->cfg->logger
            );
        }
        return new GuzzleTransport(
            $this->cfg->baseUri,
            $this->cfg->timeout,
            $this->cfg->verify,
            $this->cfg->retryTimes,
            $this->cfg->logger
        );
    }
}
