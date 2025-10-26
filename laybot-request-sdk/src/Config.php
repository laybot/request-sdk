<?php
// src/Config.php
namespace LayBot\Request;

use LayBot\Request\Signer\None;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use LayBot\Request\Contract\SignerInterface;

final class Config
{
    public string $baseUri;
    public array  $headers;
    public float  $timeout;
    public string $transport;
    public int    $retryTimes;
    public bool   $verify;
    public ?LoggerInterface $logger;
    public SignerInterface $signer;

    public function __construct(
        string $baseUri,
        array  $headers     = [],
        float  $timeout     = 10.0,
        string $transport   = 'auto',
        int    $retryTimes  = 3,
        bool   $verify      = false,
        ?SignerInterface $signer = null,
        ?LoggerInterface $logger = null,
    ){
        $this->baseUri   = rtrim($baseUri,'/').'/';
        $this->headers   = $headers;
        $this->timeout   = $timeout;
        $this->transport = $transport;
        $this->retryTimes= $retryTimes;
        $this->verify    = $verify;
        $this->signer    = $signer ?? new None;
        $this->logger    = $logger ?? new NullLogger;
    }
}
