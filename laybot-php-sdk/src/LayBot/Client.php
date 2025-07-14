<?php
declare(strict_types=1);
namespace LayBot;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;
use LayBot\Exception\{
    LayBotException,HttpException,RateLimitException,CreditException
};

/**
 * HTTP 客户端：负责重试／异常映射／全局回调
 *
 * $cfg = [
 *   'guzzle'  => [...],                 // 额外 Guzzle 选项
 *   'onReq'   => fn($method,$url,$opt), // 请求前回调
 *   'onResp'  => fn(ResponseInterface)  // 响应后回调
 * ]
 */
final class Client
{
    private Guzzle $guzzle;
    private string $base;
    private ?\Closure $onReq  = null;
    private ?\Closure $onResp = null;
    private array $headers = [];
    private int   $timeout = 60;

    public function __construct(string $apiKey, string $base, array $cfg = [])
    {
        $this->base   = rtrim($base,'/').'/';
        $this->onReq  = $cfg['onReq']  ?? null;
        $this->onResp = $cfg['onResp'] ?? null;

        /* ---------- 1. Retry stack ---------- */
        $stack = HandlerStack::create();
        $stack->push(\GuzzleHttp\Middleware::retry(
            fn($n,$req,$res)=>$n<3 && in_array($res?->getStatusCode(),[429,500,502,503,504],true),
            fn($n)=>200*(2**$n)
        ));

        /* ---------- 2. default headers ---------- */
        $hdr = ['Content-Type'=>'application/json'];
        $hdr = array_merge(
            $hdr,
            Vendor::patchHeaders($cfg['vendor'] ?? Vendor::DEFAULT, $apiKey)   // ★
        );
        /* ---------- 3. merge user headers / timeout ---------- */
        $this->headers = array_merge($hdr, $cfg['guzzle']['headers'] ?? []);
        $this->timeout = $cfg['guzzle']['timeout'] ?? 60;

        /* ---------- 4. build guzzle ---------- */
        $this->guzzle = new Guzzle(array_replace_recursive([
            'base_uri'=> $this->base,
            'timeout' => $this->timeout,
            'headers' => $this->headers,
            'handler' => $stack,
        ], $cfg['guzzle'] ?? []));
    }
    public function baseUri(): string { return $this->base; }
    public function headers(): array  { return $this->headers; }
    public function timeout(): int    { return $this->timeout; }

    /* ---------------- 常用 HTTP ---------------- */
    public function post(string $url,array $json,bool $stream=false): ResponseInterface
    {
        return $this->req('post',$url,['json'=>$json,'stream'=>$stream]);
    }

    public function get(string $url): ResponseInterface
    {
        return $this->req('get',$url);
    }

    public function delete(string $url): ResponseInterface
    {
        return $this->req('delete',$url);
    }

    public function raw(): Guzzle { return $this->guzzle; }

    /* ---------------- 统一请求入口 ---------------- */
    private function req(string $m,string $u,array $opt=[]): ResponseInterface
    {
        ($this->onReq)  && ($this->onReq)($m,$u,$opt);
        try {
            $res = $this->guzzle->{$m}($u,$opt);
            ($this->onResp)&&($this->onResp)($res);
            return $res;
        } catch (RequestException $e) {
            $code = $e->getResponse()?->getStatusCode() ?? 0;
            match($code){
                402 => throw new CreditException('credit exhausted',402,$e),
                429 => throw new RateLimitException('rate limited',429,$e),
                default => throw new HttpException($e->getMessage(),$code,$e),
            };
        } catch (\Throwable $e) {
            throw new LayBotException($e->getMessage(),0,$e);
        }
    }
}
