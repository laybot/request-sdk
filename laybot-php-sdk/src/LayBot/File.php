<?php
declare(strict_types=1);
namespace LayBot;

use LayBot\Exception\FileException;

/** 文件上传 / 下载 / 删除（供 Batch、Fine-Tune 使用）*/
final class File extends Base
{
    public function upload(string $path,string $purpose='batch'): array
    {
        if(!is_readable($path)) throw new FileException("unreadable $path");

        $r = $this->cli->raw()->request('POST','v1/files',[
            'multipart'=>[
                ['name'=>'purpose','contents'=>$purpose],
                ['name'=>'file','contents'=>fopen($path,'r'),
                    'filename'=>basename($path)]
            ]
        ]);
        return json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR);
    }

    public function download(string $fileId): string
    {
        return (string)$this->cli->get("v1/files/$fileId/content")->getBody();
    }

    public function delete(string $fileId): array
    {
        $r=$this->cli->delete("v1/files/$fileId");
        return json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
}
