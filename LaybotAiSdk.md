====================================================================  
AI-中台（FastAPI）接口总览  
====================================================================

公共说明  
• Base-URL: `https://api.laybot.cn`  
• Header: `X-API-Key`；IP 白名单校验  
• 所有错误都返回 JSON `{code,message,request_id}`

┌────────────────────────────────────────┐  
│ 1. 通用 /v1/chat（POST）              │  
└────────────────────────────────────────┘  
body 字段  
| 字段          | 必填 | 说明                                        |
|---------------|------|---------------------------------------------|
| model         | ✔️   | LB-Cosmos / LB-Vega …                       |
| capability    | ✔️   | chat / audio / vision / embed / batch / tune|
| endpoint      | ✔️   | /v1/chat & 子路径，如 /v1/audio/speech      |
| messages      | *chat 必填* | OpenAI 风格 array                     |
| …OpenAI 参数  | ¶    | temperature/top_p/max_tokens/...            |

• capability 固定值由 SDK 决定，用户不可改  
• endpoint 缺省 `/v1/chat`，允许 `/v2/chat` 等向后兼容

返回（非流式示例）
```
{
  "choices":[...],
  "usage":{"bill_tokens":168,"bill_credit":0.014},
  "request_id":"uuid"
}
```

┌────────────────────────────────────────┐  
│ 2. 文件接口                            │  
└────────────────────────────────────────┘  
POST /v1/files        | multipart | purpose=batch / tune / general  
GET  /v1/files/{id}/content  
DELETE /v1/files/{id}

┌────────────────────────────────────────┐  
│ 3. Batch 专用                           │  
└────────────────────────────────────────┘  
POST   /v1/batch                （中台内部转 /v1/chat capability=batch）  
GET    /v1/batch/{id}  
POST   /v1/batch/{id}/cancel  
GET    /v1/batch?limit=20

字段同 OpenAI Batch：input_file_id / status / output_file_id …

┌────────────────────────────────────────┐  
│ 4. Doc 解析                             │  
└────────────────────────────────────────┘  
POST /v1/doc           {url,mode,text,math}   ≤3 MiB 同步 / 其余异步  
GET  /v1/doc/{job_id}  返回 status/progress/result_url/credit_cost

┌────────────────────────────────────────┐  
│ 5. Fine-Tune                            │  
└────────────────────────────────────────┘  
POST /v1/fine_tuning/jobs       （仍走 /v1/chat capability=tune）  
GET  /v1/fine_tuning/jobs/{id}  
POST /v1/fine_tuning/jobs/{id}/cancel

====================================================================  
使用范例（Webman 流式 + Batch）  
====================================================================
```php
use LayBot\Chat;
use LayBot\Batch;

/* ----------- 流式聊天 ----------- */
$chat = new Chat('sk-live');   // Webman 里自动用 AsyncTcp

$chat->completions([
  'model'  => 'LB-Cosmos',
  'stream' => true,
  'messages'=>[['role'=>'user','content'=>'解释牛顿第二定律']]
],[
  'stream'=>fn($c,$done)=>echo $done?PHP_EOL:($c['choices'][0]['delta']['content']??'')
]);

/* ----------- 批量任务 ----------- */
$batch = new Batch('sk-live');
$file  = $batch->uploadJsonl(__DIR__.'/req.jsonl');
$job   = $batch->create($file['id']);
echo "batch id: {$job['id']}\n";
```

至此：  
• SDK：Chat / Doc / File / Batch / Audio / Embed / FineTune / Image 全部到位；  
• 中台接口字段表亦给出，开发、测试、联调均可直接照此执行。  
如需再扩扩能力，只需复制模板调用 `$this->ready()` 并改默认 endpoint 即可。祝顺利上线 🚀

```text
laybot-php-sdk/
├─ composer.json                # Composer 依赖 & 自动加载
├─ LICENSE                      # Apache-2.0 英文
├─ LICENSE-zh-CN.txt            # Apache-2.0 中文说明
├─ README.md                    # 总体使用文档
├─ phpstan.neon                 # 静态分析规则
├─ release.sh                   # 半自动发布脚本（split → 推 tag）
└─ src/LayBot/                  #—— 业务代码根
├─ Base.php                 # 能力类父类：构造 Client、ready() 参数处理
├─ Client.php               # HTTP 客户端：重试 / onReq & onResp 钩子
├─ StreamDecoder.php        # SSE 行解析器（data:… → JSON）
├─ helpers.php              # 简易全局函数 lb_chat()/lb_doc()
│
├─ Chat.php                 # 聊天 / 思维链流式（自动选流驱动）
├─ Doc.php                  # 文档/网页解析（LayBot 专属）
│
├─ Audio.php                # 语音：speech / transcript
├─ Embed.php                # Embeddings 向量
├─ Image.php                # 图像生成 / 编辑
├─ FineTune.php             # 微调作业：create / retrieve / cancel
│
├─ File.php                 # 文件上传 / 下载 / 删除（共用）
├─ Batch.php                # 批处理：uploadJsonl / create / list / cancel
│
├─ Exception/               #—— 统一异常体系
│   ├─ LayBotException.php     # SDK 总父类
│   ├─ HttpException.php       # 4xx/5xx 其它
│   ├─ CreditException.php     # 402 余额不足
│   ├─ RateLimitException.php  # 429 超速
│   ├─ ValidationException.php # 参数缺失等
│   └─ FileException.php       # 文件不可读等
│
└─ Stream/                 #—— 流式请求驱动
├─ Transport.php          # 接口定义（post + onFrame 回调）
├─ GuzzleTransport.php    # 通用实现（curl + StreamDecoder）
└─ WorkermanTransport.php # Webman / Workerman 专用超低延迟实现
```