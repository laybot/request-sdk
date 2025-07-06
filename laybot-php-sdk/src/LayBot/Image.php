<?php
declare(strict_types=1);
namespace LayBot;

use LayBot\Exception\ValidationException;

/** 图像生成 */
final class Image extends Base
{
    public function generate(array $body): array
    {
        if (!isset($body['model'])) throw new ValidationException('model required');
        $prep = $this->ready($body,'vision','/v1/chat');
        $prep['body']['endpoint'] = '/v1/images/generations';
        $res  = $this->cli->post($prep['url'],$prep['body']);
        return json_decode((string)$res->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
}
