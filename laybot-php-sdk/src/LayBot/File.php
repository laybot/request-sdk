<?php
declare(strict_types=1);
namespace LayBot;

use LayBot\Exception\FileException;

/**
 * 上传 / 下载 / 删除文件
 *   – purpose 默认为 batch
 *   – download() 返回字符串内容；大文件请自己保存到磁盘
 */
final class File extends Base
{
    /** 上传本地文件，返回 OpenAI/LayBot file 对象 */
    public function upload(string $path, string $purpose = 'batch'): array
    {
        if (!is_readable($path)) {
            throw new FileException("unreadable file $path");
        }
        /* OpenAI/LayBot 路径相同：POST /v1/files */
        $res = $this->cli->raw()->request('POST', 'v1/files', [
            'multipart' => [
                ['name' => 'purpose', 'contents' => $purpose],
                ['name' => 'file',    'contents' => fopen($path, 'r'),
                    'filename' => basename($path)]
            ]
        ]);
        return json_decode((string)$res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** 下载文件内容（string）*/
    public function download(string $fileId): string
    {   return (string)$this->cli->get("v1/files/$fileId/content")->getBody(); }

    /** 删除文件，返回 {"id":"...","deleted":true} */
    public function delete(string $fileId): array
    {
        $res = $this->cli->delete("v1/files/$fileId");
        return json_decode((string)$res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}