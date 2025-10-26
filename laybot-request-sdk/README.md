<h1 align="center">LayBot / Request-SDK · PHP</h1>
<p align="center">
  <b>业内顶级的 PHP 网络通信与流式推送工具库</b><br>
  <samp>高性能 · 零样板 · 框架无关 · 真·低延迟</samp>
</p>

---

## 0. 为什么选择 Request-SDK？

在实际工程中，你既需要 **99% 场景的一般 HTTP 调用**，  
又偶尔想在  Workerman 中拿到 **真正毫秒级的 SSE / Chat 流式输出**。  

大多数库只能满足其中一种：  
* Guzzle —— 功能全但不协程；  
* Workerman-Http —— 协程友好但无自动重试、异常体系；  
* 更别说 **Header 签名、低速超时、Trace 链路**……

`laybot/request-sdk` 将上述痛点一次性解决，提供：

1. 同时内置 **Guzzle 同步** 与 **Workerman 协程零拷贝** 双引擎；  
2. 非流式请求默认走 Guzzle；仅当 `->stream()` 且运行在事件循环中，才切换到真流式；  
3. 指数退避重试、低速检测、空闲超时、Header-Signer、PSR-3 Trace 一应俱全；  
4. 三行代码即可在任何框架启动：ThinkPHP、Laravel、Webman、Swoole、裸 PHP……  
5. 插件化设计，支持自定义 Transport / Signer / Middleware。

> 目标：**成为 PHP 领域最好用、最省心的 Server-to-Server 网络通信基座**。

---

## 1. 核心特性

| 分类 | 说明 |
|------|------|
| 极速双栈 | Guzzle 同步 + Workerman 协程<br>FPM / CLI / Webman 一键适配 |
| 流式智能切换 | 仅在 `->stream()` 且事件循环存在时改用 Workerman 真流式，其余均 Guzzle |
| 企业级稳健性 | 指数退避重试、低速检测、空闲超时、严格异常分层 |
| 插件化架构 | Transport × Signer × Middleware 解耦，CircuitBreaker / OTLP 可热插拔 |
| 丰富鉴权 | Bearer / ApiKey / Basic / Hmac-SHA256 / InnerToken / 自定义 |
| 精准异常 | HttpException / JsonException / BizException / StreamException |
| 官方 Facade | `PartnerApi` / `InnerApi` —— 一行代码直连 LayBot OpenAPI & 内网微服务 |



## 2. 安装

```bash
composer require laybot/request-sdk:^0.3

# 若需协程真流式 (Webman / Workerman)
composer require workerman/workerman --dev
```



## 3. 快速上手

### 3.1 普通 HTTP（框架无关）

```php
use LayBot\Request\{Client,Config};

$http = new Client(new Config('https://api.example.com'));

// GET
$user = $http->get('/v1/user/42');

// POST JSON
$http->postJson('/v1/user', ['name'=>'Alice']);
```

### 3.2 Webman + Chat 流式响应

```php
$cli = new Client(new Config(
    baseUri:   'https://api.openai.com',
    transport: 'auto'           // 默认为 auto，此处演示可省略
));

$cli->stream('/v1/chat/completions',
    ['stream'=>true,'messages'=>[['role'=>'user','content'=>'Hi']]],
    function(string $chunk,bool $done){
        if (!$done) {
            echo json_decode($chunk,true)['choices'][0]['delta']['content'];
        }
    }
);
```

### 3.3 文件上传

```php
$http->upload(
    '/v1/file',
    'file',
    __DIR__.'/avatar.png',
    ['scene' => 'avatar']
);
```

### 3.4 LayBot OpenAPI 极速调用

```php
$openapi = new \LayBot\Request\Facade\PartnerApi(
    'https://openapi.laybot.cn', $appKey, $secret);

$result = $openapi->accountSync(['since'=>'2024-01-01']);
```

---

## 4. 模块总览

| 模块 | 组件 | 作用 |
|------|------|------|
| Client | `Client` | get / postJson / upload / stream 统一入口 |
| Transport | `GuzzleTransport` / `WorkermanTransport` | 双栈引擎，按需切换 |
| Signer | None / Bearer / Basic / ApiKey / Hmac / Inner | Header 鉴权插拔 |
| Middleware | Retry / Trace / CircuitBreaker(预留) | 重试、追踪、熔断 |
| Util | `StreamDecoder` | 解析 `text/event-stream` |
| Facade | `PartnerApi` / `InnerApi` | 官方快捷调用封装 |

---

## 5. 路线图

| 版本 | 里程碑 |
|------|--------|
| 0.3.x | Workerman 真流式 / 低速检测 / Env 自适应 |

---

## 6. 关于 LayBot

**LayBot · 灵语智教** 专注教育与知识管理的 AIGC 平台，  
拥有自研大模型、矢量检索、知识图谱等核心能力，并陆续开源 **LayBot 系列 SDK**：  
`ai-sdk`（大模型）、`request-sdk`（网络通信）、`storage-sdk` 等等。  
欢迎关注与 Star ❤️！

---

## 7. 贡献指南

1. `git clone` → `composer install --dev`
2. 确保 `vendor/bin/phpunit` 全绿
3. `composer cs` (PSR-12) 通过后提交 PR

---

## 8. License

MIT License — 商业 & 开源项目均可免费使用，请保留版权信息。
