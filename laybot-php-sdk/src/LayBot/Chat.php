<?php
declare(strict_types=1);
namespace LayBot;

use LayBot\Exception\ValidationException;
use LayBot\Stream\{Transport, GuzzleTransport, WorkermanTransport};

final class Chat extends Base
{
    /** stream_engine 可选：auto | guzzle | workerman */
    private string $streamEngine = 'auto';

    public function __construct(string|array|Client $cfg)
    {
        if (is_array($cfg) && isset($cfg['stream_engine'])) {
            $this->streamEngine = $cfg['stream_engine'];
            unset($cfg['stream_engine']);
        }
        parent::__construct($cfg);
    }

    /** 根据运行时环境挑选 Transport */
    private function pickTransport(): Transport
    {
        if ($this->streamEngine === 'guzzle') {
            return new GuzzleTransport($this->cli);
        }
        if ($this->streamEngine === 'workerman') {
            if (!self::isWorkermanRuntime()) {
                throw new \RuntimeException('stream_engine=workerman but loop not running');
            }
            return new WorkermanTransport();
        }
        /* auto */
        return self::isWorkermanRuntime()
            ? new WorkermanTransport()
            : new GuzzleTransport($this->cli);
    }

    /**
     * 聊天 / 补全
     *
     * @param array          $body  请求体
     * @param array<string,\Closure> $cb  ['stream'=>fn($delta,$done), 'complete'=>fn($json)]
     * @return array|null
     */
    public function completions(array $body, array $cb = []): ?array
    {
        /* 参数校验 */
        if (!isset($body['model'], $body['messages'])) {
            throw new ValidationException('model & messages required');
        }

        /* 缺省端点 */
        $defPath = $body['endpoint']
            ?? Vendor::defaultEndpoint($this->vendor, 'chat')
            ?? '/v1/chat';

        /* -------- 统一准备 URL + body -------- */
        $prep   = $this->ready($body, 'chat', $defPath);
        $stream = !empty($body['stream']);

        /* ---------- 非流式 ---------- */
        if (!$stream) {
            $resp = $this->cli->post($prep['rel'], $prep['body'], false);
            $json = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
            ($cb['complete'] ?? null) && $cb['complete']($json);
            return $json;
        }

        /* ---------- 流式 ---------- */
        $transport     = $this->pickTransport();
        $headers       = $this->cli->headers();
        $idleTimeout   = $this->cli->idleTimeout();       // 连续静默 N 秒
        $connectTmo    = $this->cli->timeout()['connect']; // TCP 建连超时

        $transport->post(
            $prep['url'],
            json_encode($prep['body'], JSON_UNESCAPED_UNICODE),
            $headers,
            $connectTmo,
            $idleTimeout,
            function (string $raw, bool $done) use ($cb): void {
                if ($done) {                     // 结束帧
                    ($cb['stream'] ?? fn() => null)([], true);
                    return;
                }
                $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                ($cb['stream'] ?? fn() => null)($json, false);
            }
        );

        return null;  // 流式情况下立即返回，让回调持续接收数据
    }
}
