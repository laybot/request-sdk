# LayBot 灵语智教 · PHP SDK  
> 教育智能中枢引擎 · 为教学场景深度优化  
> Powered by **LayBot LingTeach AI**   |   官网 <https://ai.laybot.cn>

[![LayBot® Certified](https://img.shields.io/badge/LayBot%E2%84%A2-灵语智教-0F1C3F?logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0iI0ZGRiIgZD0iTTEyIDBDNS4zNyAwIDAgNS4zNyAwIDEyczUuMzcgMTIgMTIgMTIgMTItNS4zNyAxMi0xMlMxOC42MyAwIDEyIDB6bTAgMjJhMTAgMTAgMCAxIDEgMC0yMCAxMCAxMCAwIDAgMSAwIDIweiIvPjxwYXRoIGZpbGw9IiNGREQ2MDAiIGQ9Ik0xMiA1bDQuMzggNC4zOEwxMiAxMy43NyA3LjYyIDkuNCAxMiA1em0wIDQuM2wtMS40IDEuNEwxMiAxMmw0LjQtNC40TDEyIDkuM3oiLz48L3N2Zz4=)](https://ai.laybot.cn)
[![Packagist](https://img.shields.io/packagist/v/laybot/ai-sdk?label=sdk&logo=composer&color=885630)](https://packagist.org/packages/laybot/ai-sdk)
[![License](https://img.shields.io/badge/License-Apache_2.0-3DA639?logo=apache&logoColor=white)](LICENSE)
[![PHP](https://img.shields.io/packagist/php-v/laybot/ai-sdk?logo=php&color=777BB3)](https://www.php.net/)
[![GDPR](https://img.shields.io/badge/GDPR-Compliant-0C77B8?logo=privacytools)](https://ai.laybot.cn/compliance)
[![K12](https://img.shields.io/badge/K12%E6%95%99%E8%82%B2%E5%AE%89%E5%85%A8-认证通过-2E7D32?logo=openaccess)](https://edu.laybot.cn/safety)

**LayBot 灵语智教** 是一套专为 **分层教学支持、课堂教学、作业批改、教研创作、动态Q&A** 打磨的 AI 引擎。  
本 SDK 以 OpenAI 完全兼容的调用方式，一键接入 **灵语智教** 教育核心模型矩阵，自动完成计费与合规审计，让开发者专注教学业务本身。

### ⚡ Webman 专享 · 真·思维链流式

- 本 SDK 内置 **自动流式驱动检测**  
  Webman / Workerman 进程 → AsyncTcpConnection，毫秒级逐 token 推流，对于Webman框架在AI流式输出提速更友好；  
  在 Laravel / ThinkPHP / Yii 等其他 FPM 环境 → Guzzle Stream，零依赖零配置。
- 告别“整段再返”，实时展示思维链。
- 本 SDK 默认为LayBot专属模型调用，同时也支持使用本SDK的能力直接面向DeepSeek,OpenAI这些大模型api调用场景
>一句话：**更快、更稳、更省心**。尤其在 Webman / Workerman 框架下，可将思维链 (CoT) 推流延迟压到毫秒级，彻底解决官方 `workerman/http-client` “整段返回” 的痛点
---

## ✨ 为什么选择 LayBot 灵语智教？

| 功能                  | 价值                                                          |
|---------------------|-------------------------------------------------------------|
| 🛰️ **流式 SSE**      | `stream:true` 即获毫秒级增量反馈                                     |
| 🛡️ **企业级安全**       | API-Key / IP 白名单、余额预扣、敏感词脱敏                                 |
| 🛰️ **Idle-Guard™** | 只检测“连续空闲 N 秒”而**不限制总时长**。超长输出 & 网络抖动再大也不会被硬掐。               |
| ♻️ **3 次指数退避**      | 429 / 5xx 自动重试 200 ms → 400 ms → 800 ms，失败率骤降。              |
| 🛡️ **细粒度异常**       | Credit 枯竭、RateLimit、HTTP 异常分流捕获。                            |
| 💰 **成本透明**         | 按教育任务颗粒度计费，Credit 明细实时可查                                    |
| 🛡️ **可作为AI调用工具**   | 该SDK除了作为LayBot系列模型的调用工具，也支持直连OpenAI/DeepSeek等系列大模型平台，工具极简易用 |
| 🧠 **教学深度适配**       | 预置 K12/高教/国际课程专用推理参数                                        |
| 🚀 **智能分层教学**       | 动态生成梯度化习题（基础→拓展→竞赛）                                         |
| ✍️ 作业批改引擎           | 支持数学公式/图文混排版面解析（LB-Aethel）                                  |
| 🌐 国际课程适配           | A-Level/AP/IB成绩单智能分析+选课推荐                                   |
| 📝 教研创作加速           | 3秒生成考点明确的试卷（支持LaTeX输出）                                      |
| 🛡️ 教育合规保障          | 内置K12内容过滤+GDPR合规审计                                          |
---

## 📚 旗舰教育智能模型（节选）

| 教育场景       | 解决方案                     | 模型推荐         | 模型代号       | 核心优势                                                                 |
|----------------|------------------------------|------------------|---------------|--------------------------------------------------------------------------|
| **口语评测** | 实时发音打分+口语表达能力分析    | 灵语·语韵         | LB-Phona  | 毫秒级语音识别+精准发音纠错，定制化口语评测方案，适配K12与成人口语训练场景                     |
| **能力素养测评**  | 综合素养分析+成长路径建议          | 灵语·慧学        | LB-Skillwise   | 多维度素质能力测评，自动生成个性化成长建议，覆盖学科+创新+综合素质等模块                      |
| **K12分层教学** | 动态梯度习题生成              | 明心·洞玄         | LB-Insight    | 中英双语分层训练 → 5分钟生成千人千面习题，智能匹配学力水平                  |
| **智能组卷批改**| 图文混排试卷生成+批改         | 灵语·玄穹         | LB-Aethel     | 数学公式/图文解析 → 精准识别手写解题步骤，自动生成错题本                    |
| **学业诊断**    | 成绩单分析+薄弱点定位         | 灵语·太初         | LB-Primordius | 多步链式解题 → 深度追踪思维漏洞，提供针对性补强方案                         |
| **跨学科教学**  | 学科知识串联教学              | 灵语·寰宇         | LB-Cosmos     | 高中/大学跨学科推理 → 打破学科壁垒，构建知识网络图谱                        |

> *完整模型列表与价格，请参考控制台「模型与价格」页。所有模型均针对教学场景深度优化，支持 K12/高教/国际课程等全学段教育需求。*

---

## 📦 安装

```bash
composer require laybot/ai-sdk
```

---

## 🏃‍♂️ 快速上手

## 🧩 与 LayBot 前端框架深度集成

若您使用 **LayBot MPA/SPA 前端框架**（发明专利申请号：2025108367676），可无缝嵌入 AI 组件：
```html  ⬅️ 改为 HTML 示例 ⬅️
<laybot-ai-chat
        model="LB-Cosmos"
        api-key="sk-teach-xxxxxxxx"
        prompt="用高中难度讲解牛顿第二定律"
>
    <!-- 组件自动处理流式输出 -->
</laybot-ai-chat>
```
### 连接 LayBot（推荐）
```php
<?php
// 课堂教学场景：动态分层讲解
$chat->completions([
    'model' => 'LB-Cosmos',
    'stream'   => true,
    'messages' => [
        ['role' => 'user', 'content' => '为初二学生讲解浮力定律']
    ],
    'edu_features' => [
        'difficulty' => 'middle_school', // K12学段适配
        'tiered' => true  // 启用分层教学（自动生成基础+拓展内容）
    ]
]);
// 作业批改场景：数学图文解析
$chat->completions([
    'model' => 'LB-Aethel',
    'messages' => [
        ['role' => 'user', 'content' => file_get_contents('math_homework.jpg')]
    ],
    'task_type' => 'grading' // 激活批改模式
]);
```
### 直连 OpenAI / DeepSeek / Groq…

```php
$chat = new Chat([
    'apikey' => 'sk-teach-xxxxx',
    'vendor' => 'openai',          // 一行切换
    'guzzle' => ['proxy'=>'http://127.0.0.1:7890']
]);
$chat->completions([...]);

```

---

## ✨ 教育专属能力一览

| 能力 | 典型场景 | 对应端点 |
|------|----------|----------|
| **Smart Chat** | 课堂 Q&A / 知识点讲解 | `/v1/chat` |
| **Doc Parser** | 课件\|试卷 → 结构化文本 | `/v1/doc` |
| **Essay Grader** | 作文评分、润色 | `/v1/chat` + rubric 模板 |
| **Item Generator** | 习题 / 试卷批量生成 | `/v1/chat` batch 模式 |
| **Vision QA** | 图片实验报告解析 | `/v1/chat` + image-in |

---

## ⛑️ 常见错误码

| code | http | 描述 |
|------|------|------|
| 40101 | 401 | API_KEY_INVALID — Key 不存在或禁用 |
| 40200 | 402 | INSUFFICIENT_CREDIT — 余额不足 |
| 42900 | 429 | RATE_LIMITED — 触发限流 |

> 完整列表见文档 <https://ai.laybot.cn/docs/errors>

---

## 🔧 高级用法
### 🛠️ 一键覆写 HTTP 行为
DK 把 全部 Guzzle 选项 透传出来，让你可以像写配置文件一样控制底层 HTTP：
```php
$chat = new Chat([
    'apikey' => 'sk-xxx',

    /* ① 直接改域名 / 端口 */
    'base'   => 'https://my.corp.gateway',

    /* ② 切换官方厂商，自动附带对应 Endpoint/Headers */
    'vendor' => 'openai',

    /* ③ 深度覆写 Guzzle 选项（将与 SDK 默认配置递归合并） */
    'guzzle' => [
        'proxy'        => 'http://127.0.0.1:7890', // HTTP/SOCKS 代理
        'verify'       => false,                   // 跳过 SSL 证书
        'http_errors'  => false,                   // 请求 4xx/5xx 不抛异常
        'debug'        => fopen('php://stderr','w')// 输出原始报文
    ],

    /* ④ 超时分离：connect / idle */
    'timeout' => ['connect'=>5,'idle'=>300]
]);

/* ⑤ 仅对本次请求改 Endpoint（body 内写 endpoint 字段即可） */
$chat->completions([
    'model'     => 'gpt-4o-mini',
    'messages'  => [['role'=>'user','content'=>'Hi']],
    'endpoint'  => '/v1/chat/completions'   // 单次覆盖路径
]);

```
- proxy / keepalive / headers / debug … 想配就配，不必改 SDK 源码。
- base + endpoint 双重覆盖，兼容公司内网网关 / API Mock / AB 测试。
- 所有配置都是“增量合并”，只写差异即可；默认值继续生效。
- 仍然享受 Idle-Guard™、自动重试、细粒度异常等全部特性。
---

## 🚀 路线图
- Embed / Audio / Vision 端点
- WebSocket 多轮上下文通道
- SDK 自动指数退避重试
- Webman & Thinkphp & Laravel & Yii ServiceProvider

---

## 🤝 参与贡献
PR / Issue 欢迎提交！  
代码规范：PSR-12 + PHPStan 8 + PHPUnit。

```bash
composer run test   # 运行完整单测
```

---

## 📜 知识产权与专利保护

### 1. SDK 许可证
本项目采用 **Apache License 2.0**，核心保障：
- ✅ 允许商业闭源使用
- ✅ 明确专利授权
- ✅ 防御性专利终止条款

## 📄 License
Apache-2.0 © 2025 LayBot Inc. – LayBot LingTeach AI
