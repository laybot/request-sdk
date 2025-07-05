<?php
namespace LayBot;

use LayBot\Exceptions\LayBotException;
use Psr\Http\Message\ResponseInterface;

class Chat extends Base
{
    /**
     * @param array $payload OpenAI 风格参数
     * @param array $cb ['stream','complete','error'] 回调
     * @throws LayBotException
     */
    public function completions(array $payload,array $cb=[]): ?array
    {
        $stream = $payload['stream'] ?? false;
        try{
            $resp = $this->client->postJson('v1/chat',$payload,$stream);
            if(!$stream){
                $json=json_decode($resp->getBody(),true);
                    $cb['complete']??null and $cb['complete']($json,$resp);
                return $json;
            }
            StreamDecoder::decode($resp->getBody(),function($line)use(&$cb){
                if($line==='[DONE]'){
                        $cb['stream']??null and $cb['stream'](null,true);
                }else{
                    $chunk=json_decode($line,true);
                        $cb['stream']??null and $cb['stream']($chunk,false);
                }
            });
            return null;
        }catch(\Throwable $e){
                $cb['error']??null and $cb['error']($e);
            throw new LayBotException($e->getMessage(),previous:$e);
        }
    }
}
