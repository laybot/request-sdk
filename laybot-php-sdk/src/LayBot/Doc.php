<?php
namespace LayBot;

class Doc extends Base
{
    public function extract(string $url,string $mode='text',bool $math=false): array
    {
        $r = $this->client->postJson('v1/doc',
            ['url'=>$url,'mode'=>$mode,'math'=>$math]);
        return json_decode($r->getBody(),true);
    }
    public function status(string $jobId): array
    {
        $r=$this->client->get("v1/doc/$jobId");
        return json_decode($r->getBody(),true);
    }
}
