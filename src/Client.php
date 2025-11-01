<?php
declare(strict_types=1);

namespace LayBot\Request;

use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Exception\JsonException;
use LayBot\Request\Support\Env;
use LayBot\Request\Transport\GuzzleTransport;
use LayBot\Request\Transport\WorkermanTransport;
use LayBot\Request\Signer\{Hmac, Bearer, Basic, ApiKey, Inner, None};
use Psr\Log\LoggerInterface;

/**
 * 统一网络请求客户端
 *
 * ① 支持 Hmac / Bearer / Basic / ApiKey / Inner / None 六种签名器
 * ② 根据运行环境自动选择 Guzzle 或 Workerman Transport
 * ③ 自带常用便捷方法：get / postJson / postForm / post / put / patch /
 *    delete / head / options / upload / download / stream
 * ④ send() 为底层万能入口 —— 参数完全透传到 Transport
 * ⑤ retry / timeout / verify / logger 等能力由 Config 控制
 *
 * @author LayBot
 */
final class Client
{
    /* ===============================================================
       0. 对外快捷入口
    =============================================================== */
    public static function make(array $opts): self
    {
        return new self($opts);
    }

    /* ===============================================================
       1. 构造：既可传 Config，也可传数组
    =============================================================== */
    private Config              $cfg;
    private TransportInterface  $driver;

    public function __construct(Config|array $opts)
    {
        $this->cfg    = \is_array($opts) ? self::normalize($opts) : $opts;
        $this->driver = $this->pick();
    }

    /* ===============================================================
       2. 万能发送器 —— 三方 API 参数完全透传
           $opt 支持键：
           - headers / query / body / json / multipart / form_params / timeout 等
    =============================================================== */
    public function send(
        string $method,
        string $path,
        array  $opt        = [],
        bool   $jsonDecode = true
    ) {
        /* ---------- 2-1 json → body（先编码，确保 signer 拿到最终字符串） ---------- */
        if (isset($opt['json'])) {
            $opt['body'] = json_encode($opt['json'], JSON_UNESCAPED_UNICODE);
            unset($opt['json']);
            $opt['headers']['Content-Type'] ??= 'application/json';
        }

        /* ---------- 2-2 form_params → body（便捷支持 x-www-form-urlencoded） ---------- */
        if (isset($opt['form_params'])) {
            $opt['body'] = http_build_query($opt['form_params']);
            unset($opt['form_params']);
            $opt['headers']['Content-Type'] ??= 'application/x-www-form-urlencoded';
        }

        /* ---------- 2-3 公共 Header + 签名 ---------- */
        $opt['headers'] = array_merge(
            $opt['headers'] ?? [],
            $this->cfg->headers,
            $this->cfg->signer->sign($method, $path, $opt['body'] ?? '')
        );

        /* ---------- 2-4 默认超时 ---------- */
        $opt['timeout'] ??= $this->cfg->timeout;

        /* ---------- 2-5 真请求 ---------- */
        $res = $this->driver->request($method, ltrim($path, '/'), $opt);

        /* ---------- 2-6 是否自动解析 JSON ---------- */
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
       3. 快捷 HTTP 方法
    =============================================================== */

    /** GET 请求（自动解析 JSON） */
    public function get(string $path, array $query = [], array $hdr = []): array
    {
        return $this->send('GET', $path, ['query' => $query, 'headers' => $hdr]);
    }

    /** POST application/json */
    public function postJson(string $path, array $json = [], array $hdr = []): array
    {
        return $this->send('POST', $path, ['json' => $json, 'headers' => $hdr]);
    }

    /** POST application/x-www-form-urlencoded */
    public function postForm(string $path, array $form = [], array $hdr = []): array
    {
        return $this->send('POST', $path, ['form_params' => $form, 'headers' => $hdr]);
    }

    /** POST 任意 body（字符串或数组） */
    public function post(string $path, string|array $body = '', array $hdr = []): array
    {
        $opt = is_array($body)
            ? ['form_params' => $body, 'headers' => $hdr]   // 默认当表单
            : ['body' => $body, 'headers' => $hdr];
        return $this->send('POST', $path, $opt);
    }

    /** PUT */
    public function put(string $path, string|array $body = '', array $hdr = []): array
    {
        $opt = is_array($body)
            ? ['json' => $body, 'headers' => $hdr]
            : ['body' => $body, 'headers' => $hdr];
        return $this->send('PUT', $path, $opt);
    }

    /** PATCH */
    public function patch(string $path, string|array $body = '', array $hdr = []): array
    {
        $opt = is_array($body)
            ? ['json' => $body, 'headers' => $hdr]
            : ['body' => $body, 'headers' => $hdr];
        return $this->send('PATCH', $path, $opt);
    }

    /** DELETE */
    public function delete(string $path, array $query = [], array $hdr = []): array
    {
        return $this->send('DELETE', $path, ['query' => $query, 'headers' => $hdr]);
    }

    /** HEAD */
    public function head(string $path, array $query = [], array $hdr = []): array
    {
        return $this->send('HEAD', $path, ['query' => $query, 'headers' => $hdr], false);
    }

    /** OPTIONS */
    public function options(string $path, array $query = [], array $hdr = []): array
    {
        return $this->send('OPTIONS', $path, ['query' => $query, 'headers' => $hdr]);
    }

    /** 文件上传（multipart/form-data） */
    public function upload(
        string $path,
        string $field,
        string $file,
        array  $extra = [],
        array  $hdr   = []
    ): array {
        $multi = [
            [
                'name'     => $field,
                'contents' => fopen($file, 'r'),
                'filename' => basename($file),
            ],
        ];
        foreach ($extra as $k => $v) {
            $multi[] = ['name' => $k, 'contents' => $v];
        }
        return $this->send('POST', $path, ['multipart' => $multi, 'headers' => $hdr]);
    }

    /** 下载到本地文件，返回保存后的绝对路径 */
    public function download(
        string $path,
        string $saveTo,
        array  $query = [],
        array  $hdr   = []
    ): string {
        $body = $this->send('GET', $path, ['query' => $query, 'headers' => $hdr], false);
        file_put_contents($saveTo, $body);
        return realpath($saveTo);
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
       5. 链式扩展 —— 运行期替换 signer / logger / retry
    =============================================================== */
    public function withSigner(\LayBot\Request\Contract\SignerInterface $signer): self
    {
        $this->cfg = $this->cfg->withSigner($signer);
        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->cfg = $this->cfg->withLogger($logger);
        return $this;
    }

    public function withRetry(int $times): self
    {
        $this->cfg = $this->cfg->withRetry($times);
        return $this;
    }

    /* ===============================================================
       6. 把数组 opts => Config（Signer 自动推断）
    =============================================================== */
    private static function normalize(array $o): Config
    {
        if (empty($o['base_uri'])) {
            throw new \InvalidArgumentException('base_uri required');
        }

        $signer = $o['signer'] ?? match (true) {
            isset($o['api_key'], $o['api_secret'])       => new Hmac($o['api_key'], $o['api_secret']),
            isset($o['token'])                            => new Bearer($o['token']),
            isset($o['username'], $o['password'])         => new Basic($o['username'], $o['password']),
            isset($o['inner_token'])                      => new Inner($o['inner_token']),
            isset($o['api_key'])                          => new ApiKey($o['api_key'], $o['header'] ?? 'X-API-Key'),
            default                                       => new None(),
        };

        return new Config(
            baseUri    : $o['base_uri'],
            headers    : $o['headers']   ?? [],
            timeout    : $o['timeout']   ?? 10.0,
            transport  : $o['transport'] ?? 'auto',
            retryTimes : $o['retry']     ?? 2,
            verify     : $o['verify']    ?? false,
            signer     : $signer,
            logger     : $o['logger']    ?? null,
        );
    }

    /* ===============================================================
       7. Driver 选择（常规请求）
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
