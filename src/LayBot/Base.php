<?php
namespace LayBot;

abstract class Base
{
    protected Client $client;
    protected function __construct(string|array|Client $conf){
        if ($conf instanceof Client){
            $this->client=$conf; return;
        }
        if (is_string($conf)){ $conf=['apikey'=>$conf]; }
        $this->client = new Client(
            $conf['apikey'],
            $conf['base']   ?? 'https://api.laybot.cn',
            $conf['guzzle'] ?? []
        );
    }
    public static function factory(string|array|Client $conf): static{
        return new static($conf);
    }
}