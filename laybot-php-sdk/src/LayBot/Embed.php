<?php
declare(strict_types=1);
namespace LayBot;

/** 向量生成 */
final class Embed extends Base
{
    public function embeddings(array $body): array
    {
        $body = $this->prepare($body,'embed','embeddings');
        $uri  = $this->isLaybot ? 'v1/chat' : 'embeddings';
        $r    = $this->cli->post($uri,$body);
        return json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
}
