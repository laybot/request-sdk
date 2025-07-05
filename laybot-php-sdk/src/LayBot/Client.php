<?php
namespace LayBot;

use GuzzleHttp\Client as Guzzle;

class Client
{
    private Guzzle $http;
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.laybot.cn',
        array  $guzzleOpt = []
    ){
        $this->http = new Guzzle(array_merge_recursive([
            'base_uri' => rtrim($baseUrl,'/').'/',
            'timeout'  => 600,
            'headers'  => ['X-API-Key'=>$apiKey],
        ], $guzzleOpt));
    }
    public function postJson(string $uri,array $body,bool $stream=false){
        return $this->http->post($uri,['json'=>$body,'stream'=>$stream]);
    }
    public function get(string $uri){
        return $this->http->get($uri);
    }
    public function raw(): Guzzle { return $this->http; }
}