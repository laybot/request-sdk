<h1 >LayBot / Request-SDK · PHP</h1>
<p>
  Enterprise-grade HTTP &amp; Streaming Client<br>
  <b>Cross-Framework · Zero-Boilerplate · Ultra-Low Latency</b>
</p>

---

## ✨ 0. LayBot 是什么？

**LayBot · 灵语智教** —— 面向教育与知识管理场景的 AIGC 中台。  
平台自研大模型、矢量检索与知识图谱技术，并持续向社区开放 **LayBot 系列 SDK**，  
涵盖 AI 接口、消息推送、存储、网络通信等多个方向。

> `laybot/request-sdk` 正是该系列的一员：  
> 一把「瑞士军刀」式的 **Server-to-Server 网络通信基座**。  
> 任何 PHP 项目只需三行代码，即可畅享高速 HTTP 与低延迟 SSE 流。

---

## ✨ 1. 核心特性

| 类别 | 能力 |
|------|------|
| 双栈传输 | Guzzle 同步 & Workerman 协程 —— FPM / CLI / Webman 一键适配 |
| 全协议覆盖 | GET / POST / PUT / DELETE / 文件上传 / SSE 流式推送 |
| 企业级稳健性 | 指数退避重试、低速检测、空闲超时、严格异常分层 |
| 插件化架构 | Transport × Signer × Middleware 三层解耦，Trace / 熔断 / OpenTelemetry 随插随用 |
| 鉴权即插即用 | Bearer / ApiKey / Basic / Hmac-SHA256 / InnerToken …… 开箱即用，支持自定义 |
| 精准异常体系 | HttpException / JsonException / BizException / StreamException —— 一目了然 |
| 生态集成 | 官方附带两条快捷 Facade：<br>① LayBot OpenAPI 调用<br>② 内网微服务 Token 调用 |

---

## 📦 2. 安装

```bash
composer require laybot/request-sdk:^1.0

# 如需协程加速（Webman / Swoole 场景）
composer require workerman/http-client --dev
```

## 🛠 3. 模块总览

| 模块 | 组件 | 说明 |
|------|------|------|
| Client | `Client` | 核心入口：get / postJson / upload / stream |
| Transport | `GuzzleTransport` / `WorkermanTransport` | 同步 + 协程，按环境自动切换 |
| Signer | None / Bearer / Basic / ApiKey / Hmac / Inner | 一行代码替换 Header 签名 |
| Middleware | Retry / Trace / CircuitBreaker(预留) | PSR-3 追踪、熔断、限流等能力 |
| Stream | `Util\StreamDecoder` | 按行解析 `data:` 帧，自动识别 `[DONE]` |
| Facade | `PartnerApi` / `InnerApi` | LayBot 官方 OpenAPI & 微服务快捷封装 |

---

## 🚀 4. 快速上手

### 4.1 Webman 协程 + 大模型流式响应

```php
$cli = new Client(new Config(
        baseUri:   'https://api.openai.com',
        transport: 'workerman'));               // 协程零拷贝

$cli->stream('/v1/chat/completions', [
        'stream'   => true,
        'messages' => [['role'=>'user','content'=>'你好']]
    ],
    function(string $chunk,bool $done){
        if (!$done) {
            echo json_decode($chunk,true)['choices'][0]['delta']['content'];
        }
});
```

### 4.2 ThinkPHP / FPM 场景

```php
use LayBot\Request\{Client,Config};

$http = new Client(new Config('https://api.example.com'));

/* GET */
$user = $http->get('/v1/user/42');

/* POST JSON */
$http->postJson('/v1/user', ['name'=>'Alice']);
```



### 4.3 文件上传

```php
$http->upload(
    '/v1/file',                // URL
    'file',                    // 表单字段名
    __DIR__.'/avatar.png',     // 本地文件
    ['scene' => 'avatar']      // 额外表单
);
```

### 4.4 LayBot OpenAPI 一键调用

```php
$openapi = new \LayBot\Request\Facade\PartnerApi(
    'https://openapi.laybot.cn', $appKey, $secret);

$result = $openapi->accountSync(['since'=>'2024-01-01']);
```

---

## 📝 5. 路线图

| 版本 | 里程碑 |
|------|--------|
| 1.0  | 稳定版：双栈 Transport / 重试 / 签名 / SSE |
| 1.1  | 熔断器、速率限制中间件 |
| 1.2  | OpenTelemetry TraceId 自动注入 |
| 2.x  | Async PSR-18 Bridge、PHP 8.2 readonly 优化 |

---

## 🤝 6. 贡献方式

1. `git clone` → `composer install --dev`
2. 确保 `vendor/bin/phpunit` 全绿
3. 执行 `composer cs`（PSR-12）通过后提交 PR

---

## 📄 7. License

MIT License — 完全自由商用，转载请保留版权及作者信息。
```