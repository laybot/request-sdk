<?php
declare(strict_types=1);

namespace LayBot\Request;

use LayBot\Request\Contract\SignerInterface;
use LayBot\Request\Signer\NoneSigner;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Config
{
    public string $baseUri;
    public array $headers;
    public float $timeout;
    public string $transport;
    public int $retryTimes;
    public bool $verify;
    public ?LoggerInterface $logger;
    public SignerInterface $signer;
    public string $queryArrayFormat;
    public ?string $userAgent;

    public function __construct(
        string $baseUri,
        array $headers = [],
        float $timeout = 10.0,
        string $transport = 'auto',
        int $retryTimes = 2,
        bool $verify = true,
        ?SignerInterface $signer = null,
        ?LoggerInterface $logger = null,
        string $queryArrayFormat = 'brackets',
        ?string $userAgent = null,
    ) {
        $this->baseUri = rtrim($baseUri, '/') . '/';
        $this->headers = $headers;
        $this->timeout = $timeout;
        $this->transport = $transport;
        $this->retryTimes = max(0, $retryTimes);
        $this->verify = $verify;
        $this->signer = $signer ?? new NoneSigner();
        $this->logger = $logger ?? new NullLogger();
        $this->queryArrayFormat = $queryArrayFormat;
        $this->userAgent = $userAgent;
    }

    public function withSigner(SignerInterface $signer): self
    {
        $new = clone $this;
        $new->signer = $signer;
        return $new;
    }

    public function withLogger(?LoggerInterface $logger): self
    {
        $new = clone $this;
        $new->logger = $logger ?? new NullLogger();
        return $new;
    }

    public function withRetry(int $times): self
    {
        $new = clone $this;
        $new->retryTimes = max(0, $times);
        return $new;
    }

    /**
     * 追加/覆盖 headers，而不是整体替换
     */
    public function withHeaders(array $headers): self
    {
        $new = clone $this;
        $new->headers = array_merge($this->headers, $headers);
        return $new;
    }

    public function withTimeout(float $timeout): self
    {
        $new = clone $this;
        $new->timeout = max(0.1, $timeout);
        return $new;
    }

    public function withVerify(bool $verify): self
    {
        $new = clone $this;
        $new->verify = $verify;
        return $new;
    }

    public function withTransport(string $transport): self
    {
        $new = clone $this;
        $new->transport = $transport;
        return $new;
    }

    public function withQueryArrayFormat(string $format): self
    {
        $new = clone $this;
        $new->queryArrayFormat = $format;
        return $new;
    }

    public function withUserAgent(?string $userAgent): self
    {
        $new = clone $this;
        $new->userAgent = $userAgent;
        return $new;
    }
}
