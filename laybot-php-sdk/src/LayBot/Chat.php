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

        $buffer = '';          // 用来拼接可能被分片的 JSON

        $transport->post(
            $prep['url'],
            json_encode($prep['body'], JSON_UNESCAPED_UNICODE),
            $headers,
            $connectTmo,
            $idleTimeout,
            function (string $raw, bool $done) use (&$buffer, $cb): void {
                /* ---- 服务器宣告结束 or 连接关闭 ---- */
                if ($done) {
                    // 如果还有残留半截 JSON，尝试最后一次解码
                    if ($buffer !== '') {
                        try {
                            $json   = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);
                            ($cb['stream'] ?? fn () => null)($json, false);
                        } catch (\JsonException) {
                            // 忽略残缺碎片
                        }
                        $buffer = '';
                    }
                    ($cb['stream'] ?? fn () => null)(null, true);
                    return;
                }
                /* ---- 正常数据帧 ---- */
                if ($raw === '') {
                    // 心跳行（极少出现），直接忽略
                    return;
                }
                $buffer .= $raw;
                try {
                    $json   = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);
                    $buffer = '';                              // 解码成功，清空缓冲
                    ($cb['stream'] ?? fn () => null)($json, false);
                } catch (\JsonException) {
                    // JSON 还没接全，继续等待下一帧
                }
            }
        );

        return null;  // 流式情况下立即返回，让回调持续接收数据
    }
}
