<?php
declare(strict_types=1);
namespace LayBot;

/** 图像生成 / 编辑 */
final class Image extends Base
{
    public function generate(array $body): array
    {
        $body = $this->prepare($body,'vision','images_gen');
        $uri  = $this->isLaybot ? 'v1/chat' : 'images/generations';
        $r    = $this->cli->post($uri,$body);
        return json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
}
