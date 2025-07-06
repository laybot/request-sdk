<?php
declare(strict_types=1);
namespace LayBot;

/** 微调作业：创建 / 查询 / 取消 */
final class FineTune extends Base
{
    public function create(array $body): array
    {
        $body = $this->prepare($body,'tune','fine-tune');
        $uri  = $this->isLaybot ? 'v1/chat' : 'fine_tuning/jobs';
        $r    = $this->cli->post($uri,$body);
        return json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR);
    }

    public function retrieve(string $jobId): array
    {
        $prefix = $this->isLaybot ? 'v1/fine_tuning/jobs' : 'fine_tuning/jobs';
        $r=$this->cli->get("$prefix/$jobId");
        return json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR);
    }

    public function cancel(string $jobId): array
    {
        $prefix=$this->isLaybot ? 'v1/fine_tuning/jobs' : 'fine_tuning/jobs';
        $r=$this->cli->post("$prefix/$jobId/cancel",[]);
        return json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
}
