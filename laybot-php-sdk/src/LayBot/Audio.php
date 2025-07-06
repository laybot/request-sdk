<?php
declare(strict_types=1);
namespace LayBot;

use LayBot\Exception\ValidationException;

/** Text-to-Speech & Speech-to-Text */
final class Audio extends Base
{
    public function speech(array $body): array
    {
        if (!isset($body['model']))  throw new ValidationException('model required');
        $prep = $this->ready($body,'audio','/v1/chat');   // endpoint=/v1/chat
        $prep['body']['endpoint'] = '/v1/audio/speech';   // 指定子端点
        $res  = $this->cli->post($prep['url'],$prep['body']);
        return json_decode((string)$res->getBody(),true,512,JSON_THROW_ON_ERROR);
    }

    public function transcript(array $body): array
    {
        if (!isset($body['model']))  throw new ValidationException('model required');
        $prep = $this->ready($body,'audio','/v1/chat');
        $prep['body']['endpoint'] = '/v1/audio/transcriptions';
        $res  = $this->cli->post($prep['url'],$prep['body']);
        return json_decode((string)$res->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
}
