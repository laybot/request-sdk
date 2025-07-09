# LayBot Request SDK · PHP

> 现代化、极简、易扩展的 PHP 网络请求工具库  
> Powered by **LayBot**

[![LayBot® Certified](https://img.shields.io/badge/LayBot%E2%84%A2-Request_SDK-0F1C3F?logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0iI0ZGRiIgZD0iTTEyIDBDNS4zNyAwIDAgNS4zNyAwIDEyczUuMzcgMTIgMTIgMTIgMTItNS4zNyAxMi0xMlMxOC42MyAwIDEyIDB6bTAgMjJhMTAgMTAgMCAxIDEgMC0yMCAxMCAxMCAwIDAgMSAwIDIweiIvPjxwYXRoIGZpbGw9IiNGREQ2MDAiIGQ9Ik0xMiA1bDQuMzggNC4zOEwxMiAxMy43NyA3LjYyIDkuNCAxMiA1em0wIDQuM2wtMS40IDEuNEwxMiAxMmw0LjQtNC40TDEyIDkuM3oiLz48L3N2Zz4=)](https://ai.laybot.cn)
[![Packagist](https://img.shields.io/packagist/v/laybot/request-sdk?label=sdk&logo=composer&color=885630)](https://packagist.org/packages/laybot/request-sdk)
[![License](https://img.shields.io/badge/License-MIT-3DA639?logo=openaccess)](LICENSE)
[![PHP](https://img.shields.io/packagist/php-v/laybot/request-sdk?logo=php&color=777BB3)](https://www.php.net/)

---

## 🚀 特性一览

- ⚡ 依赖 GuzzleHttp 7，现代 API、强大中间件
- 🌀 简单、直观、支持 get/post/json/upload/retry 等常见场景
- 🔌 插件式扩展（InnerAPI/OSSAPI/自定义API）
- 🤝 完全兼容 Laravel / Webman / CLI / 传统 PHP
- 🏆 工业级错误处理与 JSON 自动解析
- 💡 不强依赖任何业务、无敏感地址硬编码，适于开源和二次封装

---

## 📦 安装

```bash
composer require laybot/request-sdk
```

---

## 🏃‍♂️ 快速上手

### 1. 基本用法

```php
require 'vendor/autoload.php';

use LayBot\Request\HttpClient;

$http = new HttpClient([
    'base_uri' => 'https://httpbin.org',
    'timeout'  => 6.0,
    'headers'  => ['User-Agent' => 'laybot-request-sdk']
]);

// GET 请求
$result = $http->get('/get');
print_r($result);

// POST JSON
$res = $http->post('/post', ['foo'=>'bar']);
print_r($res);

// 上传文件
$res = $http->upload('/post', [
    [
        'name'     => 'file',
        'contents' => fopen('logo.png', 'r'),
        'filename' => 'logo.png'
    ]
]);
```

### 2. 错误处理与异常捕获

```php
try {
    $data = $http->get('/404-not-found');
} catch (\RuntimeException $e) {
    // 统一捕捉请求异常
    echo $e->getMessage();
}
```

---

## 🔌 插件与自定义能力

支持以插件方式扩展内网调用、特殊签名、加解密等：

**示例：扩展 InnerApi 插件能力**
```php
use LayBot\Request\Inner\InnerApi;

// 由你的 config/.env 提供参数
$inner  = new InnerApi($baseUri, $token);

$apiKey = $inner->generateKey($endpoint, $userId);
// $endpoint 等接口路径由业务层指定，避免泄露内部结构
```

**可自定义插件能力**
- LayBot\Request\OssApi  (自定义 OSS 签名)
- LayBot\Request\YourApi  (如你的自定义外部微服务)

---

## 📝 设计理念

- **核心无业务耦合**  
  仅实现请求基础能力与容错，特殊能力插件化
- **高安全**  
  所有接口路径、密钥等敏感信息均从外部显式注入
- **即插即用**  
  支持任意 Composer 场景，“只管用，不需二次修改”

---

## 🔧 高级用法

#### 1. 配置 Guzzle 代理/证书/重试

```php
$http = new HttpClient([
    'timeout' => 10,
    'guzzle' => [
        'proxy'   => 'http://127.0.0.1:7890',
        'verify'  => false,
        // 也可自定义 Guzzle HandlerStack
    ]
]);
```

#### 2. Stream/Download (按需扩展)

```php
// 若需实现流式读取/大文件下载，可自定义追加 stream 方法
```

---

## 🛠️ 控制反转 & 框架集成

- Laravel：建议通过 ServiceProvider 注册
- Webman：可放至 support/bootstrap.php 并用依赖注入单例使用
- CLI/传统：实例化后直接用

---

## 📜 LICENSE

本项目基于 MIT 开源协议发布。欢迎商用及二次封装。

> © 2025 LayBot Inc. – LayBot LingTeach AI
---

## 🤝 贡献

欢迎 PR、Issue 参与共建！  
规范：PSR-12 + PHPStan 八级 + PHPUnit。

>如需咨询/商务接入等请访问 [https://ai.laybot.cn](https://ai.laybot.cn) 或邮件 larry@laybot.cn
---