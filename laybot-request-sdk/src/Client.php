<?php
declare(strict_types=1);

namespace LayBot\Request;

use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Exception\JsonException;
use LayBot\Request\Support\Env;
use LayBot\Request\Transport\GuzzleTransport;
use LayBot\Request\Transport\WorkermanTransport;

final class Client
{
    private TransportInterface $driver;

    public function __construct(private Config $cfg)
    {
        $this->driver = $this->pick();
    }

    /* ---------- public 简易 API ---------- */
    public function get(string $path, array $query = [], array $hdr = []): array
    {
        return $this->req('GET', $path, ['query' => $query, 'headers' => $hdr]);
    }

    public function postJson(string $path, array $json = [], array $hdr = []): array
    {
        return $this->req('POST', $path, ['json' => $json, 'headers' => $hdr]);
    }

    public function upload(
        string $path,
        string $field,
        string $file,
        array  $extra = [],
        array  $hdr = []
    ): array {
        $multi = [['name'      => $field,
            'contents'  => fopen($file, 'r'),
            'filename'  => basename($file)]];
        foreach ($extra as $k => $v) {
            $multi[] = ['name' => $k, 'contents' => $v];
        }
        return $this->req('POST', $path, ['multipart' => $multi, 'headers' => $hdr]);
    }

    /**
     * 发起 SSE / ChatGPT 类流式请求
     *
     * @param array          $opt 可选 ['connect'=>10,'idle'=>180,'transport'=>'auto|guzzle|workerman']
     */
    public function stream(
        string   $path,
        array    $json,
        callable $cb,
        array    $hdr = [],
        array    $opt = []
    ): void {
        $body = json_encode($json, JSON_UNESCAPED_UNICODE);

        $hdr = array_merge(
            $hdr,
            $this->cfg->headers,
            $this->cfg->signer->sign('POST', $path, $body)
        );

        /* ---------- 运行时挑选流式 Driver ---------- */
        $mode = $opt['transport']
            ?? ($this->cfg->transport === 'workerman' ? 'workerman' : 'auto');

        $driver = $this->driver; // 默认 Guzzle

        if ($mode === 'workerman'
            || ($mode === 'auto' && Env::inWorkermanLoop())) {
            $driver = new WorkermanTransport(
                $this->cfg->baseUri,
                $this->cfg->timeout,
                $this->cfg->verify,
                $this->cfg->retryTimes,
                $this->cfg->logger
            );
        }

        /* ---------- 发起流式 ---------- */
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

    /* ---------- inner ---------- */
    private function req(string $m, string $u, array $opt): array
    {
        $body = $opt['json'] ?? ($opt['multipart'] ?? '');

        if (isset($opt['json'])) {
            $opt['body'] = json_encode($opt['json'], JSON_UNESCAPED_UNICODE);
            unset($opt['json']);
        }

        $opt['timeout'] = $this->cfg->timeout;
        $opt['headers'] = array_merge(
            $opt['headers'] ?? [],
            $this->cfg->headers,
            $this->cfg->signer->sign($m, $u, is_string($body) ? $body : json_encode($body))
        );

        $res = $this->driver->request($m, ltrim($u, '/'), $opt);
        $arr = json_decode($res['body'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonException('invalid json ' . $res['body'], $res['status'], $res['body']);
        }

        return $arr;
    }

    /* ---------- 只决定“常规请求” Driver ---------- */
    private function pick(): TransportInterface
    {
        // 手动强制 workerman：所有请求都走它
        if ($this->cfg->transport === 'workerman') {
            return new WorkermanTransport(
                $this->cfg->baseUri,
                $this->cfg->timeout,
                $this->cfg->verify,
                $this->cfg->retryTimes,
                $this->cfg->logger
            );
        }

        // 其余情况一律 Guzzle
        return new GuzzleTransport(
            $this->cfg->baseUri,
            $this->cfg->timeout,
            $this->cfg->verify,
            $this->cfg->retryTimes,
            $this->cfg->logger
        );
    }
}
