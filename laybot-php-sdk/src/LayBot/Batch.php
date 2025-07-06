<?php
declare(strict_types=1);
namespace LayBot;

/** Batch：上传 jsonl → 创建 / 查询 / 列表 / 取消；文件下载删除复用 File */
final class Batch extends Base
{
    public function uploadJsonl(string $path): array
    {   return (new File($this->cli))->upload($path,'batch'); }

    public function create(string $inputFileId,string $target='/v1/chat/completions',
                           string $window='24h',array $meta=[]): array
    {
        $payload = [
            'input_file_id'    => $inputFileId,
            'target_endpoint'  => $target,
            'completion_window'=> $window,
            'metadata'         => $meta
        ];
        $prep = $this->ready($payload,'batch','/v1/batch');
        $url  = $this->isLaybot ? 'v1/chat' : $prep['url'];   // LayBot 经 /v1/chat
        $res  = $this->cli->post($url,$prep['body']);
        return json_decode((string)$res->getBody(),true,512,JSON_THROW_ON_ERROR);
    }

    public function retrieve(string $batchId): array
    {
        $p=$this->isLaybot? "v1/batch/$batchId" : "v1/batches/$batchId";
        $r=$this->cli->get($p);
        return json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
    public function list(int $limit=20): array
    {
        $p=$this->isLaybot? "v1/batch?limit=$limit" : "v1/batches?limit=$limit";
        return json_decode((string)$this->cli->get($p)->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
    public function cancel(string $batchId): array
    {
        $p=$this->isLaybot? "v1/batch/$batchId/cancel" : "v1/batches/$batchId/cancel";
        return json_decode((string)$this->cli->post($p,[])->getBody(),true,512,JSON_THROW_ON_ERROR);
    }

    /* 文件工具 */
    public function downloadFile(string $fileId): string
    {   return (new File($this->cli))->download($fileId); }
    public function deleteFile(string $fileId): array
    {   return (new File($this->cli))->delete($fileId); }
}