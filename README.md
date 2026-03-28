<h1 align="center">LayBot / Request-SDK · PHP</h1>
<p align="center">
  <b>通用 PHP HTTP 请求与 SSE 流式工具库</b><br>
  <samp>稳定 · 易用 · 框架无关 · 适合作为第三方平台 SDK 底座</samp>
</p>

---

## 1. 为什么选择 Request-SDK？

在实际工程中，你既需要覆盖 **绝大多数普通 HTTP 请求场景**，  
又希望在 Webman / Workerman 或其他PHP框架环境下，能够处理 **SSE 风格流式输出**。

常见方案往往各有侧重：

- **Guzzle**：功能成熟，适合普通同步 HTTP 请求；
- **Workerman**：适合长连接与事件循环场景；
- 但在真实项目里，你还会需要：
  - Header 鉴权
  - JSON 编解码
  - 重试机制
  - Trace 日志
  - 文件上传下载
  - 统一异常体系

此外，Request-SDK 也支持通过 `custom_headers` 或 `HeaderSigner` 注入任意静态自定义请求头，适合：

- 内部微服务固定 Header Token
- 导出服务 Token
- 平台间服务身份标识
- 任意 `X-XXX-*` 风格静态 Header 鉴权

这样既能保持 SDK 的统一调用方式，也避免每出现一种新 Header 鉴权就重复手写请求逻辑。

`laybot/request-sdk` 的目标，就是把这些能力收敛成一个**可长期复用的基础网络组件库**，适合作为：

- OpenAI / Gemini /Claude 等管理类 SDK 的底层网络基座
- Webman / Workerman 后台服务的通用 HTTP 组件
- 内部 OpenAPI / 微服务调用工具
- 定时同步、管理后台、Server-to-Server 通信基础库

> 目标：成为一套在 PHP 大型项目中“趁手、稳定、可复用”的网络请求底座。

---

## 2. 定位与边界

`laybot/request-sdk` 的定位是：

- **通用服务端 HTTP 请求组件库**
- **第三方平台 SDK 的底层网络基座**
- **适用于 Webman / Workerman / CLI / FPM 的网络通信层**

本库主要负责：

- HTTP 请求发送
- JSON / Form / Upload / Download
- Bearer / ApiKey / Basic / Hmac / Inner 鉴权
- Retry / Trace / Timeout
- 原始响应获取
- SSE 风格基础流式请求

本库**不负责**：

- 大模型对话语义封装
- Chat / Messages / Tool Calls / Function Calls 抽象
- 多模型厂商（OpenAI / Gemini / Claude）对话协议适配
- 流式对话增量聚合与最终消息拼装
- 模型调用层的业务语义封装

如果你的目标是：

- 调用大模型对话接口
- 统一封装 OpenAI / Gemini / Claude
- 处理流式对话增量
- 封装 Chat / Embedding / Image / Audio 等模型能力

请使用上层语义 SDK：

## `laybot/ai-sdk`

也就是说：

- `request-sdk` 负责：**怎么请求**
- `ai-sdk` 负责：**怎么调用大模型**

---

## 3. 推荐分层

在实际项目中，建议按以下分层使用：

- `request-sdk`：底层网络请求层
- `openai` / `gemini`：平台管理接口层
- `laybot/ai-sdk`：大模型调用语义层
- 业务项目：业务逻辑层

---

## 4. 安装

```bash
composer require laybot/request-sdk:^0.5
```

---

## 5. 适用场景

适合：

- 各类AI大模型 Admin 等管理类 SDK 底座
- Webman / Workerman 后台服务
- 定时同步任务
- 管理后台
- 内部 OpenAPI / 微服务调用
- 文件上传下载
- SSE 基础流式请求
- 其他企业级server-to-server网络请求
不直接面向：

- 大模型对话语义封装
- Chat / Embedding / Tool Calls 等模型调用层

普通请求默认使用 Guzzle，同步稳定优先；流式请求在 Workerman 事件循环中可启用 WorkermanTransport。

---

## 6. 快速开始

### 6.1 普通 GET

```php
use LayBot\Request\Client;

$http = Client::make([
    'base_uri' => 'https://httpbin.org',
]);

$res = $http->get('/get', [
    'foo' => 'bar',
]);

var_dump($res);
```

---

### 6.2 POST JSON

```php
$res = $http->postJson('/post', [
    'name' => 'LayBot',
    'scene' => 'demo',
]);

var_dump($res);
```

---

### 6.3 POST Form

```php
$res = $http->postForm('/post', [
    'username' => 'demo',
    'password' => '123456',
]);
```

---

### 6.4 获取原始响应

```php
$raw = $http->requestRaw('GET', '/get', [
    'query' => ['a' => 1],
]);

/*
[
  'status' => 200,
  'headers' => [...],
  'body' => '...'
]
*/
```

---

### 6.5 Bearer 鉴权

```php
$http = Client::make([
    'base_uri' => 'https://api.openai.com/v1',
    'token' => 'sk-xxx',
    'timeout' => 30,
    'retry' => 2,
    'verify' => true,
]);
```

---

### 6.6 文件上传

```php
$res = $http->upload(
    '/post',
    'file',
    __DIR__ . '/demo.txt',
    ['scene' => 'test']
);
```

---

### 6.7 文件下载

```php
$path = $http->download(
    '/image/png',
    __DIR__ . '/runtime/demo.png'
);

echo $path;
```

> `download()` 使用流式写入，不会将整个响应体一次性读入内存。

---

### 6.8 SSE 风格流式请求

```php
$http = Client::make([
    'base_uri' => 'https://api.openai.com',
    'token' => 'sk-xxx',
    'transport' => 'auto',
]);

$http->stream('/v1/chat/completions', [
    'model' => 'gpt-4o-mini',
    'stream' => true,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello']
    ],
], function (string $chunk, bool $done) {
    if ($done) {
        echo PHP_EOL . '[DONE]' . PHP_EOL;
        return;
    }

    echo $chunk;
});
```

> 当前 `stream()` 主要面向 **SSE / `data:` 行流** 场景。  
> 若要进行完整的大模型对话封装，请使用 `laybot-ai-sdk`。

---

## 7. 配置项

| 配置项 | 说明 | 默认值 |
|---|---|---|
| `base_uri` | 基础地址，必填 | - |
| `headers` | 默认请求头 | `[]` |
| `timeout` | 请求超时（秒） | `10.0` |
| `transport` | `auto` / `guzzle` / `workerman` | `auto` |
| `retry` | 重试次数 | `2` |
| `verify` | 是否校验证书 | `true` |
| `query_array_format` | `brackets` / `repeat` | `brackets` |
| `user_agent` | 自定义 UA | `null` |
| `token` | Bearer Token | `null` |
| `api_key` | API Key | `null` |
| `api_secret` | Hmac Secret | `null` |
| `username` | Basic 用户名 | `null` |
| `password` | Basic 密码 | `null` |
| `inner_token` | 内部服务 Token | `null` |
| `logger` | PSR-3 Logger | `null` |
| `custom_headers` | 自定义静态请求头（自动转 HeaderSigner） | `[]` |
---

## 8. 鉴权方式

### 8.1 Bearer

```php
$http = Client::make([
    'base_uri' => 'https://api.example.com',
    'token' => 'your-token',
]);
```

---

### 8.2 ApiKey

```php
$http = Client::make([
    'base_uri' => 'https://api.example.com',
    'api_key' => 'your-api-key',
    'header' => 'X-API-Key',
]);
```

---

### 8.3 Basic

```php
$http = Client::make([
    'base_uri' => 'https://api.example.com',
    'username' => 'demo',
    'password' => '123456',
]);
```

---

### 8.4 Hmac

```php
$http = Client::make([
    'base_uri' => 'https://api.example.com',
    'api_key' => 'app-key',
    'api_secret' => 'secret',
]);
```

---

### 8.5 Inner

```php
$http = Client::make([
    'base_uri' => 'https://api.example.com',
    'inner_token' => 'inner-token',
]);
```

### 8.6 自定义 Header 鉴权

适用于以下场景：

- `X-Export-Token`
- `X-Service-Token`
- `X-Internal-App`
- 其他任意静态 Header 鉴权

```php
$http = Client::make([
    'base_uri' => 'https://api.example.com',
    'custom_headers' => [
        'X-Export-Token' => 'your-export-token',
        'X-Service-Name' => 'paper-export',
    ],
]);
```

### 8.7 `token` 与 `custom_headers` 的区别

`token` 是一个**语义化快捷配置**，专门表示 Bearer Token：

```php
$http = Client::make([
    'base_uri' => 'https://api.example.com',
    'token' => 'your-bearer-token',
]);
```

等价于： Authorization: Bearer your-bearer-token

而 custom_headers 表示任意静态自定义 Header，例如：
```php
$http = Client::make([
    'base_uri' => 'https://api.example.com',
    'custom_headers' => [
        'X-Export-Token' => 'your-export-token',
    ],
]);
```
等价于： X-Export-Token: your-export-token

### 8.8 多 token / 多 Header 共存

在实际项目中，常见场景是：

- 同时需要 `Authorization: Bearer xxx`
- 又需要额外的 `X-Export-Token: yyy`
- 或其他自定义服务身份 Header

这种情况下，可以直接组合使用：

```php
$http = Client::make([
    'base_uri' => 'https://api.example.com',
    'token' => 'bearer-xxx',
    'headers' => [
        'X-Export-Token' => 'export-yyy',
        'X-Service-Name' => 'paper-export',
    ],
]);
```
最终会同时携带：

```php
Authorization: Bearer bearer-xxx
X-Export-Token: export-yyy
X-Service-Name: paper-export

```
# 鉴权自动推断规则

当未显式传入 `signer` 时，Client 会按以下顺序自动推断：

1. `custom_headers` → `HeaderSigner`
2. `api_key + api_secret` → `HmacSigner`
3. `token` → `BearerSigner`
4. `username + password` → `BasicSigner`
5. `inner_token` → `InnerSigner`
6. `api_key` → `ApiKeySigner`
7. 默认 `NoneSigner`

如果你希望完全控制行为，建议直接传入 `signer`。

---

## 9. Query 数组格式

支持两种 query 数组编码方式：

### 9.1 brackets（默认）

适合常规 Guzzle 数组 query：

```php
[
  'group_by' => ['project_id', 'line_item']
]
```

---

### 9.2 repeat

适合某些 API 需要重复参数形式：

```php
$http = Client::make([
    'base_uri' => 'https://api.example.com',
    'query_array_format' => 'repeat',
]);
```

会编码为：

```txt
group_by=project_id&group_by=line_item
```

> `repeat` 主要适合一级数组重复参数场景。

---

## 10. 链式配置

```php
$http = Client::make([
    'base_uri' => 'https://api.example.com',
]);

$http2 = $http
    ->withTimeout(30)
    ->withRetry(3)
    ->withVerify(true)
    ->withUserAgent('AI-Admin-Client/1.0');
```

支持：

- `withSigner()`
- `withLogger()`
- `withRetry()`
- `withHeaders()`
- `withTimeout()`
- `withVerify()`
- `withUserAgent()`
- `withQueryArrayFormat()`

---

## 11. 便捷方法总览

| 方法 | 说明 |
|---|---|
| `get()` | GET 请求 |
| `postJson()` | POST JSON |
| `postForm()` | POST 表单 |
| `post()` | POST 原始 body 或表单 |
| `put()` | PUT |
| `patch()` | PATCH |
| `delete()` | DELETE |
| `head()` | HEAD，返回原始响应 |
| `options()` | OPTIONS |
| `upload()` | 文件上传 |
| `download()` | 流式下载到本地 |
| `requestRaw()` | 获取原始响应 |
| `stream()` | SSE 风格流式请求 |

---

## 12. 异常说明

### `LayBot\Request\Exception\RequestException`
基础请求异常。

### `LayBot\Request\Exception\HttpException`
HTTP 非 2xx 响应异常，可获取：

- `getStatusCode()`
- `getResponseBody()`
- `getResponseHeaders()`
- `getMethod()`
- `getUri()`

### `LayBot\Request\Exception\JsonException`
JSON 编解码异常。

### `LayBot\Request\Exception\StreamException`
流式请求异常。

---

## 13. Retry 与 Trace

### Retry
默认支持：

- 网络异常重试
- 5xx 重试
- 429 重试
- 指数退避 + 抖动
- `Retry-After` 识别

### Trace
如果传入 logger，会记录请求/响应调试日志，并自动脱敏：

- `Authorization`
- `X-API-Key`
- `X-Inner-Token`
- `Proxy-Authorization`

> 生产环境建议谨慎开启 debug 级别 trace。

Trace 中间件会自动对敏感 Header 做脱敏处理。

当前脱敏规则包括：

- 明确高风险头：`Authorization`、`Proxy-Authorization`
- 以及 Header 名中包含以下关键词的请求头：
  - `token`
  - `secret`
  - `key`
  - `signature`
  - `sign`

---

## 14. 设计说明

### 普通请求
普通请求默认走 Guzzle，稳定优先。

### 流式请求
`stream()` 在 Workerman/Webman 事件循环中可启用 `WorkermanTransport`。  
当前主要支持 **SSE / `data:` 行流**。

### 下载
`download()` 使用 `sink` 流式写入文件，适合大文件下载场景。

### 参数约束
同一次请求中，以下 payload 模式只能使用一种：

- `json`
- `form_params`
- `multipart`
- `body`

---

## 15. 模块总览

| 模块 | 组件 | 作用 |
|------|------|------|
| Client | `Client` | 统一请求入口与便捷方法 |
| Config | `Config` | 不可变配置对象 |
| Transport | `GuzzleTransport` / `WorkermanTransport` | 普通请求与流式请求传输层 |
| Signer | `BearerSigner` / `ApiKeySigner` / `BasicSigner` / `HmacSigner` / `InnerSigner` / `HeaderSigner` / `NoneSigner` | Header 鉴权插拔 |
| Middleware | `Retry` / `Trace` | 重试、追踪 |
| Support | `Json` / `Query` / `UserAgent` / `Env` | JSON、Query、UA、环境辅助 |
| Util | `StreamDecoder` | 解析 `text/event-stream` |
| Facade | `PartnerApi` / `InnerApi` | 官方快捷调用封装 |

---

## 16. 版本建议

当前建议版本：

```txt
0.5.x
```

如果你在大型项目中使用，建议固定小版本范围并做好 smoke test。

---

## 17. 关于 LayBot

**LayBot · 灵语智教** 专注教育与知识管理的 AIGC 平台，  
拥有自研大模型、矢量检索、知识图谱等核心能力，并陆续开源 **LayBot 系列 SDK**：

- `laybot-ai-sdk`：大模型调用语义层 SDK
- `laybot/request-sdk`：通用网络通信底座
- `storage-sdk`：存储相关能力

欢迎关注与 Star ❤️

---

## 18. 贡献指南

```bash
git clone https://github.com/laybot/request-sdk.git
cd request-sdk
composer install --dev

# 单元测试
vendor/bin/phpunit
```

---

## License

MIT License
