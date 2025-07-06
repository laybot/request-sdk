<?php
declare(strict_types=1);
namespace LayBot;

use LayBot\Exception\ValidationException;

/** 微调：创建 / 查询 / 取消 */
final class FineTune extends Base
{
    public function create(array $body): array
    {
        if (!isset($body['model'])) throw new ValidationException('model required');
        $prep = $this->ready($body,'tune','/v1/chat');
        $prep['body']['endpoint'] = '/v1/fine_tuning/jobs';
        $res  = $this->cli->post($prep['url'],$prep['body']);
        return json_decode((string)$res->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
    public function retrieve(string $jobId): array
    {
        $path = $this->isLaybot ? "v1/fine_tuning/jobs/$jobId" : "fine_tuning/jobs/$jobId";
        $res  = $this->cli->get($path);
        return json_decode((string)$res->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
    public function cancel(string $jobId): array
    {
        $path = $this->isLaybot ? "v1/fine_tuning/jobs/$jobId/cancel"
            : "fine_tuning/jobs/$jobId/cancel";
        $res  = $this->cli->post($path,[]);
        return json_decode((string)$res->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
}
