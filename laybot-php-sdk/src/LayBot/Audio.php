<?php
declare(strict_types=1);
namespace LayBot;

/** 语音合成/识别 */
final class Audio extends Base
{
    public function speech(array $body): array
    {
        $body = $this->prepare($body,'audio','speech');
        $uri  = $this->isLaybot ? 'v1/chat' : 'audio/speech';
        $r    = $this->cli->post($uri,$body);
        return json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR);
    }

    public function transcript(array $body): array
    {
        $body = $this->prepare($body,'audio','transcript');
        $uri  = $this->isLaybot ? 'v1/chat' : 'audio/transcriptions';
        $r    = $this->cli->post($uri,$body);
        return json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
}
