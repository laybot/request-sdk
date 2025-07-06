<?php
declare(strict_types=1);
namespace LayBot;

use LayBot\Exception\ValidationException;

/** 向量生成 */
final class Embed extends Base
{
    public function embeddings(array $body): array
    {
        if (!isset($body['model'])) throw new ValidationException('model required');
        $prep = $this->ready($body,'embed','/v1/chat');
        $prep['body']['endpoint'] = '/v1/embeddings';
        $res = $this->cli->post($prep['url'],$prep['body']);
        return json_decode((string)$res->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
}
