<?php
namespace LayBot\Request\Inner;

use LayBot\Request\HttpClient;
use RuntimeException;

final class InnerApi
{
    private HttpClient $http;
    private string $token;

    public function __construct(string $baseUri, string $innerToken, float $timeout = 5.0)
    {
        $this->http  = new HttpClient(['base_uri' => rtrim($baseUri, '/'), 'timeout' => $timeout]);
        $this->token = $innerToken;
    }

    private function call(string $method, string $path, array $json = []): array
    {
        $ret = $this->http->{$method === 'GET' ? 'get' : 'post'}(
            $path,
            $json,
            ['X-Inner-Token' => $this->token]
        );
        if (isset($ret['code']) && $ret['code'] !== 0) {
            throw new RuntimeException($ret['message'] ?? 'biz error', $ret['code']);
        }
        return $ret;
    }

    /* ==== 公开方法 ==== */
    public function generateKey(string $endpoint,int $userId, ?string $remark = null): string
    {
        $ret = $this->call('POST', $endpoint, [
            'user_id' => $userId,
            'remark'  => $remark
        ]);
        return $ret['api_key'];
    }
    public function flushKey(string $endpoint, string $key): void   {
        $this->call('DELETE',$endpoint,['api_key'=>$key]);
    }
    public function encrypt(string $endpoint,string $plain): string{
        return $this->call('POST',$endpoint,['plain_key'=>$plain])['cipher'];
    }
    /* …更多接口可按需追加… */
}
