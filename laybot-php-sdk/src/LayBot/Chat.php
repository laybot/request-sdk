<?php
declare(strict_types=1);
namespace LayBot;

use LayBot\Exception\ValidationException;
use LayBot\Stream\{Transport,GuzzleTransport,WorkermanTransport};

final class Chat extends Base
{
    /** stream_engine: auto|guzzle|workerman */
    private string $streamEngine = 'auto';

    public function __construct(string|array|Client $cfg)
    {
        if (is_array($cfg) && isset($cfg['stream_engine'])) {
            $this->streamEngine = $cfg['stream_engine'];
            unset($cfg['stream_engine']);
        }
        parent::__construct($cfg);
    }

    private function pickTransport(): Transport
    {
        if ($this->streamEngine==='guzzle') return new GuzzleTransport($this->cli);
        if ($this->streamEngine==='workerman') {
            if (!self::isWorkermanRuntime()) {
                throw new \RuntimeException('stream_engine=workerman but loop not running');
            }
            return new WorkermanTransport();
        }
        /* auto */
        return self::isWorkermanRuntime()
            ? new WorkermanTransport()
            : new GuzzleTransport($this->cli);
    }

    public function completions(array $body,array $cb=[]): ?array
    {


        if (!isset($body['model'],$body['messages'])) {
            throw new ValidationException('model & messages required');
        }

        $defPath = $body['endpoint']
            ?? Vendor::defaultEndpoint($this->vendor, 'chat')
            ?? '/v1/chat';

        /* --------- 统一准备 URL + body -------- */
        $prep = $this->ready($body, 'chat', $defPath);
        $stream = !empty($body['stream']);
        if (!$stream) {                      // 非流式直接走 Client
            $resp=$this->cli->post($prep['url'],$prep['body'],false);
            $json=json_decode((string)$resp->getBody(),true,512,JSON_THROW_ON_ERROR);
            ($cb['complete']??null) && $cb['complete']($json);
            return $json;
        }

        /* ---- 流式：挑选 Transport ---- */
        $transport=$this->pickTransport();
        $headers  = $this->cli->headers();
        $timeout  = $this->cli->timeout();

        $transport->post($prep['url'], json_encode($prep['body'],JSON_UNESCAPED_UNICODE),
            $headers, $timeout,
            function(string $raw,bool $done) use ($cb){
                if ($done) { ($cb['stream']??fn()=>null)([],true); return; }
                $json=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                ($cb['stream']??fn()=>null)($json,false);
            });

        return null;
    }
}
