<?php
declare(strict_types=1);
namespace LayBot;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;
use LayBot\Exception\{
    LayBotException, HttpException, RateLimitException, CreditException
};
use LayBot\Stream\{StreamSession, WorkermanTransport};

/**
 * HTTP 客户端：负责重试／异常映射／全局回调
 *
 * $cfg = [
 *   'guzzle'  => [...],                 // 额外 Guzzle 选项
 *   'timeout' => ['connect'=>10,'idle'=>180],  // idle=0 关闭空闲检测
 *   'onReq'   => fn($method,$url,$opt),
 *   'onResp'  => fn(ResponseInterface)
 * ]
 */
final class Client
{
    private const DEF_TMO = ['connect'=>10, 'idle'=>180];

    private Guzzle $guzzle;
    private string $base;
    private array  $headers = [];        // 最终发送的 headers
    private array  $tmo     = [];        // connect / idle
    private ?\Closure $onReq  = null;
    private ?\Closure $onResp = null;

    /* ---------------- 构造 ---------------- */
    public function __construct(string $apiKey, string $base, array $cfg = [])
    {
        $this->base   = rtrim($base, '/') . '/';
        $this->tmo    = array_replace(self::DEF_TMO, $cfg['timeout'] ?? []);
        $this->onReq  = $cfg['onReq']  ?? null;
        $this->onResp = $cfg['onResp'] ?? null;

        /* 1. 重试中间件 */
        $stack = HandlerStack::create();
        $stack->push(\GuzzleHttp\Middleware::retry(
            static fn($n, $req, $res) => $n < 3
                && in_array($res?->getStatusCode(), [429, 500, 502, 503, 504], true),
            static fn($n) => 200 * (2 ** $n)                       // 200ms / 400 / 800
        ));

        /* 2. headers */
        $defHdr = [
                'Content-Type' => 'application/json',
            ] + Vendor::patchHeaders($cfg['vendor'] ?? Vendor::DEFAULT, $apiKey);

        $this->headers = array_merge($defHdr, $cfg['guzzle']['headers'] ?? []);

        /* 3. curl 低速检测：idle 秒内平均 <1B/s 视为超时 */
        $curlOpt = [];
        if ($this->tmo['idle'] > 0) {
            $curlOpt = [
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME  => $this->tmo['idle'],
            ];
        }

        /* 4. 实例化 Guzzle */
        $this->guzzle = new Guzzle(array_replace_recursive([
            'base_uri'        => $this->base,
            'connect_timeout' => $this->tmo['connect'],
            'timeout'         => 0,               // 不限制总时长
            'headers'         => $this->headers,
            'handler'         => $stack,
            'curl'            => $curlOpt,
        ], $cfg['guzzle'] ?? []));
    }

    /* ----------- 对外 getter ----------- */
    public function baseUri(): string { return $this->base; }
    public function headers(): array  { return $this->headers; }
    public function timeout(): array  { return $this->tmo; }
    public function idleTimeout(): int { return $this->tmo['idle']; }
    public function raw(): Guzzle    { return $this->guzzle; }

    /* ----------- 常规 HTTP ----------- */
    public function post(string $url, array $json,
                         bool $stream = false, array $override = []): ResponseInterface
    {
        if ($stream) {
            return $this->streamPost($url, $json, $override);
        }
        return $this->req('post', $url, ['json' => $json]);
    }

    public function get(string $url): ResponseInterface
    {
        return $this->req('get', $url);
    }

    public function delete(string $url): ResponseInterface
    {
        return $this->req('delete', $url);
    }

    /* ---------- 私有统一入口 ---------- */
    private function req(string $m, string $u, array $opt = []): ResponseInterface
    {
        ($this->onReq) && ($this->onReq)($m, $u, $opt);
        try {
            $res = $this->guzzle->{$m}($u, $opt);
            ($this->onResp) && ($this->onResp)($res);
            return $res;
        } catch (RequestException $e) {
            $code = $e->getResponse()?->getStatusCode() ?? 0;
            match ($code) {
                402     => throw new CreditException('credit exhausted', 402, $e),
                429     => throw new RateLimitException('rate limited', 429, $e),
                default => throw new HttpException($e->getMessage(), $code, $e),
            };
        } catch (\Throwable $e) {
            throw new LayBotException($e->getMessage(), 0, $e);
        }
    }

    /* ---------- 流式 POST (SSE) ---------- */
    private function streamPost(string $url,array $json,array $override): ResponseInterface
    {
        $idle    = $override['idle']    ?? $this->tmo['idle'];
        $connect = $override['connect'] ?? $this->tmo['connect'];
        $tp      = new WorkermanTransport();

        $starter = function(callable $cb) use ($tp,$url,$json,$connect,$idle){
            $tp->post(
                $url,
                json_encode($json,JSON_UNESCAPED_UNICODE),
                $this->headers,
                $connect,
                $idle,
                $cb
            );
        };
        return new StreamSession($starter);
    }
}
