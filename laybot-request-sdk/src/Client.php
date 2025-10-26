<?php
namespace LayBot\Request;

use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\Exception\JsonException;
use LayBot\Request\Transport\GuzzleTransport;
use LayBot\Request\Transport\WorkermanTransport;

final class Client
{
    private TransportInterface $driver;
    public function __construct(private Config $cfg)
    {
        $this->driver = $this->pick();
    }

    /* ---------- public 简易 API ---------- */
    public function get(string $path,array $query=[],array $hdr=[]): array
    {
        return $this->req('GET',$path,['query'=>$query,'headers'=>$hdr]);
    }
    public function postJson(string $path,array $json=[],array $hdr=[]): array
    {
        return $this->req('POST',$path,['json'=>$json,'headers'=>$hdr]);
    }
    public function upload(string $path,string $field,string $file,array $extra=[],array $hdr=[]): array
    {
        $multi=[['name'=>$field,'contents'=>fopen($file,'r'),'filename'=>basename($file)]];
        foreach($extra as $k=>$v){ $multi[]=['name'=>$k,'contents'=>$v]; }
        return $this->req('POST',$path,['multipart'=>$multi,'headers'=>$hdr]);
    }
    public function stream(string $path,array $json,callable $cb,array $hdr=[]): void
    {
        $body=json_encode($json,JSON_UNESCAPED_UNICODE);
        $hdr=array_merge($hdr,$this->cfg->headers,$this->cfg->signer->sign('POST',$path,$body));
        $this->driver->stream('POST',$path,[
            'headers'=>$hdr,
            'body'   =>$body,
            'timeout'=>$this->cfg->timeout,
        ],$cb);
    }

    /* ---------- inner ---------- */
    private function req(string $m,string $u,array $opt): array
    {
        $body = $opt['json'] ?? ($opt['multipart'] ?? '');
        if(isset($opt['json'])){
            $opt['body'] = json_encode($opt['json'],JSON_UNESCAPED_UNICODE);
            unset($opt['json']);
        }
        $opt['timeout'] = $this->cfg->timeout;
        $opt['headers'] = array_merge(
            $opt['headers'] ?? [],
            $this->cfg->headers,
            $this->cfg->signer->sign($m,$u,is_string($body)?$body:json_encode($body))
        );
        $res = $this->driver->request($m,ltrim($u,'/'),$opt);
        $arr = json_decode($res['body'],true);
        if(json_last_error()!==JSON_ERROR_NONE){
            throw new JsonException('invalid json '.$res['body'],$res['status'],$res['body']);
        }
        return $arr;
    }

    private function pick(): TransportInterface
    {
        if($this->cfg->transport==='guzzle'){
            return new GuzzleTransport($this->cfg->baseUri,$this->cfg->timeout,$this->cfg->verify,
                $this->cfg->retryTimes,$this->cfg->logger);
        }
        if($this->cfg->transport==='workerman'
            || ($this->cfg->transport==='auto' && class_exists(\Workerman\Worker::class))){
            return new WorkermanTransport();
        }
        return new GuzzleTransport($this->cfg->baseUri,$this->cfg->timeout,$this->cfg->verify,
            $this->cfg->retryTimes,$this->cfg->logger);
    }
}
