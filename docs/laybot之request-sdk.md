下面把「面向所有 PHP 项目（Laravel / Webman / CLI …）的通用网络请求工具」设计、命名、目录结构与核心代码一次性给出，并把 `_inner` 调用能力做成**插件**挂进去——既保持生态统一，也不会让公众看到 `admin` 这种容易被误解的名字。

──────────────────────────────────────────  
1  软件包命名  
──────────────────────────────────────────  
• 包名（Composer）：**laybot/request-sdk**  
• GitHub Repo：`laybot/request-sdk`  
– 听起来就像「请求工具」，对外不暴露“后台、管理”等敏感语义；  
– 以后 LayBot 生态下的其它包可以并列：`laybot/ai-sdk`、`laybot/chatbot` 等。

命名空间建议
```
LayBot\Request\            # 对外通用能力
LayBot\Request\Inner\      # 仅 AI 中台内部插件
```

──────────────────────────────────────────  
2  功能分层  
──────────────────────────────────────────

┌─── Core ────┐                      ┌─ Plugin ─ InnerApi ─┐
│ HttpClient  │  ← Guzzle7 封装      │  generateKey()     │
│ • get/post  │  + 中间件            │  encrypt() …       │
│ • json()    │                      └────────────────────┘
│ • upload()  │                      （可选更多插件）       
│ • retry()   │                                        
└─────────────┘

• Core：任何项目都能用，默认不含 Inner-Token。  
• Inner\InnerApi：只有引用方显式调用时才需要在 `.env` 或构造函数里放 Token。

──────────────────────────────────────────  
3  目录 & composer.json  
──────────────────────────────────────────

```
request-sdk/
├─ src/
│   ├─ HttpClient.php         # 核心封装
│   └─ Inner/
│       └─ InnerApi.php       # 插件
├─ bin/
│   └─ laybot-cli             # 可选 CLI
├─ .env.example
└─ composer.json
```

composer.json
```jsonc
{
  "name": "laybot/request-sdk",
  "description": "LayBot universal HTTP request kit (with optional AI-mid inner plugin)",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": ">=8.1",
    "guzzlehttp/guzzle": "^7.0"
  },
  "autoload": { "psr-4": { "LayBot\\Request\\": "src/" } },
  "bin": [ "bin/laybot-cli" ]
}
```

──────────────────────────────────────────  
4  核心 HttpClient.php  
──────────────────────────────────────────

```php
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
```

──────────────────────────────────────────  
5  Inner\InnerApi.php  （插件）  
──────────────────────────────────────────

```php
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
    public function generateKey(int $userId, ?string $remark = null): string
    {
        $ret = $this->call('POST', '/_inner/key/generate', [
            'user_id' => $userId,
            'remark'  => $remark
        ]);
        return $ret['api_key'];
    }
    public function flushKey(string $key): void   { $this->call('DELETE','/_inner/key/flush',['api_key'=>$key]); }
    public function encrypt(string $plain): string{ return $this->call('POST','/_inner/account/encrypt',['plain_key'=>$plain])['cipher']; }
    /* …更多接口可按需追加… */
}
```

──────────────────────────────────────────  
6  使用示例（聚合平台 ／ 任何 PHP 项目）  
──────────────────────────────────────────

```php
use LayBot\Request\Inner\InnerApi;

// .env 或配置文件
$base   = getenv('LAYBOT_AI_BASE')   ?: 'https://api.laybot.cn';
$token  = getenv('LAYBOT_AI_TOKEN')  ?: 'SuperSecret';

$inner  = new InnerApi($base, $token);

// 生成 Key
$key = $inner->generateKey(1, 'Webman Demo');
echo $key.PHP_EOL;

// 上游账号加密
$cipher = $inner->encrypt('sk-openai-secret');
```

在 Webman 中放进 `support/bootstrap.php` 注册为单例即可：
```php
Container::set(InnerApi::class, new InnerApi($base,$token));
```

──────────────────────────────────────────  
7  CLI 工具（可选）  
──────────────────────────────────────────

```bash
# 生成 Key
LAYBOT_AI_TOKEN=SuperSecret laybot-cli gen 1 "演示KEY"
# 加密明文
laybot-cli enc "sk-openai-secret"
```
CLI 只是调用 `InnerApi`，便于 DevOps 脚本或人工调试。

──────────────────────────────────────────  
8  安全要点（开源无忧）  
──────────────────────────────────────────
1. 项目中 **不内置** `inner_token`，用户必须自己注入。
2. 中台仍有 **IP 白名单**，即便 token 泄露也无法调用。
3. `decrypt()` 方法可在插件里用 `if (!getenv('APP_ENV_DEV')) throw …` 约束生产禁用。

──────────────────────────────────────────  
9  今后扩展  
──────────────────────────────────────────  
• 任意新 `_inner` 接口 → 在 `InnerApi` 里再加一个方法；  
• 若有其它服务（图片转码、OSS 签名）也可出一个 `LayBot\Request\OssApi` 插件；  
• 需要流式下载 / 上传时在 Core `HttpClient` 中再加 `stream()`、`sink()` 方法即可。

至此，你拥有：  
 • 一个对外通用、对内可插拔的网络请求 SDK；  
 • 易于扩展到 Webman、Laravel、CLI；  
 • 开源到 GitHub 也不会暴露任何敏感关键词。

现在只需：
```
composer install
composer dump-autoload
```
即可在聚合平台的任何 PHP 服务中一键调用 AI 中台内部 API。祝使用愉快！