<?php
declare(strict_types=1);

namespace LayBot\Request;

use InvalidArgumentException;
use LayBot\Request\Contract\SignerInterface;
use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Signer\ApiKeySigner;
use LayBot\Request\Signer\BasicSigner;
use LayBot\Request\Signer\BearerSigner;
use LayBot\Request\Signer\HmacSigner;
use LayBot\Request\Signer\InnerSigner;
use LayBot\Request\Signer\NoneSigner;
use LayBot\Request\Support\Env;
use LayBot\Request\Support\Json;
use LayBot\Request\Support\Query;
use LayBot\Request\Support\UserAgent;
use LayBot\Request\Transport\GuzzleTransport;
use LayBot\Request\Transport\WorkermanTransport;
use Psr\Log\LoggerInterface;

final class Client
{
    private Config $cfg;
    private TransportInterface $driver;

    public static function make(array $opts): self
    {
        return new self($opts);
    }

    public function __construct(Config|array $opts)
    {
        $this->cfg = is_array($opts) ? self::normalize($opts) : $opts;
        $this->driver = $this->pickRequestDriver();
    }

    /**
     * 发送请求并默认解析 JSON
     */
    public function send(
        string $method,
        string $path,
        array $opt = [],
        bool $jsonDecode = true
    ): mixed {
        $prepared = $this->prepareOptions($method, $path, $opt);
        $res = $this->driver->request(strtoupper($method), ltrim($path, '/'), $prepared);

        if (!$jsonDecode) {
            return $res['body'];
        }

        if ($res['body'] === '') {
            return [];
        }

        return Json::decode($res['body']);
    }

    /**
     * 获取原始响应
     *
     * @return array{status:int,headers:array,body:string}
     */
    public function requestRaw(string $method, string $path, array $opt = []): array
    {
        $prepared = $this->prepareOptions($method, $path, $opt);
        return $this->driver->request(strtoupper($method), ltrim($path, '/'), $prepared);
    }

    public function get(string $path, array $query = [], array $hdr = []): array
    {
        return $this->send('GET', $path, [
            'query' => $query,
            'headers' => $hdr,
        ]);
    }

    public function postJson(string $path, array $json = [], array $hdr = []): array
    {
        return $this->send('POST', $path, [
            'json' => $json,
            'headers' => $hdr,
        ]);
    }

    public function postForm(string $path, array $form = [], array $hdr = []): array
    {
        return $this->send('POST', $path, [
            'form_params' => $form,
            'headers' => $hdr,
        ]);
    }

    public function post(string $path, string|array $body = '', array $hdr = []): array
    {
        $opt = is_array($body)
            ? ['form_params' => $body, 'headers' => $hdr]
            : ['body' => $body, 'headers' => $hdr];

        return $this->send('POST', $path, $opt);
    }

    public function put(string $path, string|array $body = '', array $hdr = []): array
    {
        $opt = is_array($body)
            ? ['json' => $body, 'headers' => $hdr]
            : ['body' => $body, 'headers' => $hdr];

        return $this->send('PUT', $path, $opt);
    }

    public function patch(string $path, string|array $body = '', array $hdr = []): array
    {
        $opt = is_array($body)
            ? ['json' => $body, 'headers' => $hdr]
            : ['body' => $body, 'headers' => $hdr];

        return $this->send('PATCH', $path, $opt);
    }

    public function delete(string $path, array $query = [], array $hdr = []): array
    {
        return $this->send('DELETE', $path, [
            'query' => $query,
            'headers' => $hdr,
        ]);
    }

    /**
     * HEAD 更适合返回原始响应（状态码/响应头）
     *
     * @return array{status:int,headers:array,body:string}
     */
    public function head(string $path, array $query = [], array $hdr = []): array
    {
        return $this->requestRaw('HEAD', $path, [
            'query' => $query,
            'headers' => $hdr,
        ]);
    }

    /**
     * OPTIONS 更适合返回原始响应
     *
     * @return array{status:int,headers:array,body:string}
     */
    public function options(string $path, array $query = [], array $hdr = []): array
    {
        return $this->requestRaw('OPTIONS', $path, [
            'query' => $query,
            'headers' => $hdr,
        ]);
    }

    public function upload(
        string $path,
        string $field,
        string $file,
        array $extra = [],
        array $hdr = []
    ): array {
        if ($field === '') {
            throw new InvalidArgumentException('upload field required');
        }

        if ($file === '') {
            throw new InvalidArgumentException('upload file path required');
        }

        if (!is_file($file)) {
            throw new InvalidArgumentException("file not found: {$file}");
        }

        if (!is_readable($file)) {
            throw new InvalidArgumentException("file not readable: {$file}");
        }

        $fp = fopen($file, 'r');
        if ($fp === false) {
            throw new InvalidArgumentException("file open failed: {$file}");
        }

        $multi = [
            [
                'name' => $field,
                'contents' => $fp,
                'filename' => basename($file),
            ],
        ];

        foreach ($extra as $k => $v) {
            $multi[] = [
                'name' => (string)$k,
                'contents' => is_scalar($v) || $v === null ? (string)$v : Json::encode($v),
            ];
        }

        if (isset($hdr['Content-Type']) || isset($hdr['content-type'])) {
            throw new InvalidArgumentException('do not set Content-Type manually when using multipart upload');
        }

        try {
            return $this->send('POST', $path, [
                'multipart' => $multi,
                'headers' => $hdr,
            ]);
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /**
     * 流式下载到本地文件，避免将整个响应体读入内存
     */
    public function download(
        string $path,
        string $saveTo,
        array $query = [],
        array $hdr = []
    ): string {
        $dir = dirname($saveTo);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new InvalidArgumentException("create save directory failed: {$dir}");
            }
        }

        $tmp = $saveTo . '.part';

        try {
            $this->requestRaw('GET', $path, [
                'query' => $query,
                'headers' => $hdr,
                'sink' => $tmp,
            ]);

            if (!rename($tmp, $saveTo)) {
                throw new InvalidArgumentException("move downloaded file failed: {$tmp} -> {$saveTo}");
            }
        } catch (\Throwable $e) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            throw $e;
        }

        $real = realpath($saveTo);
        if ($real === false) {
            throw new InvalidArgumentException("resolve saved file failed: {$saveTo}");
        }

        return $real;
    }

    /**
     * 基础流式请求
     *
     * 默认：
     * - POST JSON
     * - decode.mode = data-line
     * - decode.done_token = [DONE]
     *
     * @param callable(string $chunk,bool $done):void $cb
     * @param array{
     *   transport?:string,
     *   connect_timeout?:float|int,
     *   idle_timeout?:int,
     *   decode?:array{mode?:string,done_token?:?string}
     * } $opt
     */
    public function stream(
        string $path,
        array $json,
        callable $cb,
        array $hdr = [],
        array $opt = []
    ): void {
        $body = Json::encode($json);

        $headers = $this->buildHeaders('POST', $path, $body, $hdr);
        $headers['Content-Type'] ??= 'application/json';
        $headers['Accept'] ??= 'text/event-stream';
        $headers['Cache-Control'] ??= 'no-cache';
        $headers['User-Agent'] ??= $this->cfg->userAgent ?: UserAgent::default();

        $mode = self::normalizeTransport($opt['transport'] ?? $this->cfg->transport);
        $driver = $this->pickStreamDriver($mode);

        $driver->stream(
            'POST',
            ltrim($path, '/'),
            [
                'headers' => $headers,
                'body' => $body,
                'connectTimeout' => isset($opt['connect_timeout'])
                    ? (float)$opt['connect_timeout']
                    : $this->cfg->timeout,
                'idleTimeout' => isset($opt['idle_timeout'])
                    ? (int)$opt['idle_timeout']
                    : 0,
                'decode' => [
                    'mode' => $opt['decode']['mode'] ?? 'data-line',
                    'done_token' => array_key_exists('done_token', $opt['decode'] ?? [])
                        ? $opt['decode']['done_token']
                        : '[DONE]',
                ],
            ],
            $cb
        );
    }

    public function withSigner(SignerInterface $signer): self
    {
        $new = clone $this;
        $new->cfg = $this->cfg->withSigner($signer);
        $new->driver = $new->pickRequestDriver();
        return $new;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $new = clone $this;
        $new->cfg = $this->cfg->withLogger($logger);
        $new->driver = $new->pickRequestDriver();
        return $new;
    }

    public function withRetry(int $times): self
    {
        $new = clone $this;
        $new->cfg = $this->cfg->withRetry($times);
        $new->driver = $new->pickRequestDriver();
        return $new;
    }

    public function withHeaders(array $headers): self
    {
        $new = clone $this;
        $new->cfg = $this->cfg->withHeaders($headers);
        $new->driver = $new->pickRequestDriver();
        return $new;
    }

    public function withTimeout(float $timeout): self
    {
        $new = clone $this;
        $new->cfg = $this->cfg->withTimeout($timeout);
        $new->driver = $new->pickRequestDriver();
        return $new;
    }

    public function withVerify(bool $verify): self
    {
        $new = clone $this;
        $new->cfg = $this->cfg->withVerify($verify);
        $new->driver = $new->pickRequestDriver();
        return $new;
    }

    public function withUserAgent(?string $userAgent): self
    {
        $new = clone $this;
        $new->cfg = $this->cfg->withUserAgent($userAgent);
        $new->driver = $new->pickRequestDriver();
        return $new;
    }

    public function withQueryArrayFormat(string $format): self
    {
        $new = clone $this;
        $new->cfg = $this->cfg->withQueryArrayFormat($format);
        $new->driver = $new->pickRequestDriver();
        return $new;
    }

    private function prepareOptions(string $method, string $path, array $opt): array
    {
        $this->assertPayloadMode($opt);

        $body = $opt['body'] ?? null;

        if (array_key_exists('json', $opt)) {
            $body = Json::encode($opt['json']);
            unset($opt['json']);
            $opt['body'] = $body;
            $opt['headers']['Content-Type'] ??= 'application/json';
        }

        if (array_key_exists('form_params', $opt)) {
            $body = http_build_query($opt['form_params'], '', '&', PHP_QUERY_RFC3986);
            unset($opt['form_params']);
            $opt['body'] = $body;
            $opt['headers']['Content-Type'] ??= 'application/x-www-form-urlencoded';
        }

        if (isset($opt['query']) && is_array($opt['query'])) {
            $opt['query'] = Query::normalize($opt['query'], $this->cfg->queryArrayFormat);
        }

        $opt['headers'] = $this->buildHeaders(
            strtoupper($method),
            $path,
            is_string($body) ? $body : '',
            $opt['headers'] ?? []
        );

        $opt['timeout'] ??= $this->cfg->timeout;

        return $opt;
    }

    private function buildHeaders(string $method, string $path, string $body, array $headers = []): array
    {
        $merged = $this->mergeHeaders(
            $this->cfg->headers,
            $headers,
            $this->cfg->signer->sign($method, $path, $body)
        );

        $merged['User-Agent'] ??= $this->cfg->userAgent ?: UserAgent::default();

        return $merged;
    }

    private function mergeHeaders(array ...$groups): array
    {
        $result = [];
        $nameMap = [];

        foreach ($groups as $headers) {
            foreach ($headers as $key => $value) {
                $name = trim((string)$key);
                if ($name === '') {
                    continue;
                }

                $lower = strtolower($name);
                $canonical = $nameMap[$lower] ?? $name;
                $nameMap[$lower] = $canonical;
                $result[$canonical] = $value;
            }
        }

        return $result;
    }

    private function assertPayloadMode(array $opt): void
    {
        $modes = 0;
        $modes += array_key_exists('json', $opt) ? 1 : 0;
        $modes += array_key_exists('form_params', $opt) ? 1 : 0;
        $modes += array_key_exists('multipart', $opt) ? 1 : 0;
        $modes += array_key_exists('body', $opt) ? 1 : 0;

        if ($modes > 1) {
            throw new InvalidArgumentException(
                'only one of json/form_params/multipart/body can be used'
            );
        }
    }

    private static function normalize(array $o): Config
    {
        if (empty($o['base_uri'])) {
            throw new InvalidArgumentException('base_uri required');
        }

        $headers = array_merge(
            (array)($o['headers'] ?? []),
            (array)($o['custom_headers'] ?? [])
        );

        $signer = $o['signer'] ?? match (true) {
            isset($o['api_key'], $o['api_secret']) => new HmacSigner((string)$o['api_key'], (string)$o['api_secret']),
            isset($o['token']) => new BearerSigner((string)$o['token']),
            isset($o['username'], $o['password']) => new BasicSigner((string)$o['username'], (string)$o['password']),
            isset($o['inner_token']) => new InnerSigner((string)$o['inner_token']),
            isset($o['api_key']) => new ApiKeySigner((string)$o['api_key'], (string)($o['header'] ?? 'X-API-Key')),
            default => new NoneSigner(),
        };

        return new Config(
            baseUri: (string)$o['base_uri'],
            headers: $headers,
            timeout: self::toPositiveFloat($o['timeout'] ?? 10.0, 10.0),
            transport: self::normalizeTransport($o['transport'] ?? 'auto'),
            retryTimes: self::toNonNegativeInt($o['retry'] ?? 2, 2),
            verify: self::toBool($o['verify'] ?? true, true),
            signer: $signer,
            logger: $o['logger'] ?? null,
            queryArrayFormat: self::normalizeQueryArrayFormat($o['query_array_format'] ?? 'brackets'),
            userAgent: isset($o['user_agent']) ? (string)$o['user_agent'] : null,
        );
    }

    private function pickRequestDriver(): TransportInterface
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

    private function pickStreamDriver(string $mode): TransportInterface
    {
        return match ($mode) {
            'guzzle' => new GuzzleTransport(
                $this->cfg->baseUri,
                $this->cfg->timeout,
                $this->cfg->verify,
                $this->cfg->retryTimes,
                $this->cfg->logger
            ),
            'workerman' => new WorkermanTransport(
                $this->cfg->baseUri,
                $this->cfg->timeout,
                $this->cfg->verify,
                $this->cfg->retryTimes,
                $this->cfg->logger
            ),
            default => Env::inWorkermanLoop()
                ? new WorkermanTransport(
                    $this->cfg->baseUri,
                    $this->cfg->timeout,
                    $this->cfg->verify,
                    $this->cfg->retryTimes,
                    $this->cfg->logger
                )
                : new GuzzleTransport(
                    $this->cfg->baseUri,
                    $this->cfg->timeout,
                    $this->cfg->verify,
                    $this->cfg->retryTimes,
                    $this->cfg->logger
                ),
        };
    }

    private static function toBool(mixed $value, bool $default = true): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool)$value;
        }

        $str = strtolower(trim((string)$value));
        return match ($str) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    private static function toPositiveFloat(mixed $value, float $default): float
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $float = (float)$value;
        return $float > 0 ? $float : $default;
    }

    private static function toNonNegativeInt(mixed $value, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $int = (int)$value;
        return max(0, $int);
    }

    private static function normalizeTransport(mixed $value): string
    {
        $transport = strtolower(trim((string)$value));
        if (!in_array($transport, ['auto', 'guzzle', 'workerman'], true)) {
            throw new InvalidArgumentException("invalid transport: {$transport}");
        }
        return $transport;
    }

    private static function normalizeQueryArrayFormat(mixed $value): string
    {
        $format = strtolower(trim((string)$value));
        if (!in_array($format, ['brackets', 'repeat'], true)) {
            throw new InvalidArgumentException("invalid query_array_format: {$format}");
        }
        return $format;
    }
}
