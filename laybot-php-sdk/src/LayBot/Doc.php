<?php
declare(strict_types=1);
namespace LayBot;

use LayBot\Exception\LayBotException;

/** LayBot 专属文件解析接口 */
final class Doc extends Base
{
    private function guard(): void
    {
        if (!$this->isLaybot) {
            throw new LayBotException('Doc API only available on LayBot base');
        }
    }

    /**
     * 提交解析任务（≤3 MiB 同步 / >3 MiB 异步）
     *
     * @param string $endpoint  缺省 "/v1/doc"，可填 "/v2/doc"
     */
    public function extract(string $url,string $mode='text',bool $math=false,
                            string $endpoint='/v1/doc'): array
    {
        $this->guard();
        $body = compact('url','mode','math');
        $prep = $this->ready($body,'', $endpoint);       // capability 空
        $res  = $this->cli->post($prep['url'],$prep['body']);
        return json_decode((string)$res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** 轮询任务状态 */
    public function status(string $jobId): array
    {
        $this->guard();
        $res = $this->cli->get("v1/doc/$jobId");
        return json_decode((string)$res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
