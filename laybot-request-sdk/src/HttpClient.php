<?php
namespace LayBot\Request;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

final class HttpClient
{
    private Client $cli;

    public function __construct(array $opts = [])
    {
        $defaults = [
            'timeout'   => $opts['timeout']   ?? 5.0,
            'base_uri'  => $opts['base_uri']  ?? '',
            'verify'    => $opts['verify']    ?? false,
            'headers'   => $opts['headers']   ?? ['User-Agent' => 'laybot-request-sdk/1.0'],
        ];
        $this->cli = new Client($defaults);
    }

    /* 基础方法 ------------------------------------------------------------ */
    public function get(string $url, array $query = [], array $headers = [])
    {
        return $this->req('GET', $url, ['query' => $query, 'headers' => $headers]);
    }

    public function post(string $url, array $json = [], array $headers = [])
    {
        return $this->req('POST', $url, ['json' => $json, 'headers' => $headers]);
    }

    public function upload(string $url, array $multipart, array $headers = [])
    {
        return $this->req('POST', $url, ['multipart' => $multipart, 'headers' => $headers]);
    }

    /* 内部封装：自动 JSON 解包、错误抛出 -------------------------- */
    private function req(string $method, string $url, array $opts): array
    {
        try {
            $res  = $this->cli->request($method, $url, $opts);
        } catch (GuzzleException $e) {
            throw new RuntimeException("HTTP {$method} {$url} failed: ".$e->getMessage(), 0, $e);
        }

        $body = json_decode((string)$res->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("invalid json response");
        }
        return $body;
    }
}