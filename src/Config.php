<?php
declare(strict_types=1);

namespace LayBot\Request;

use LayBot\Request\Contract\ContextSignerInterface;
use LayBot\Request\Contract\SignerInterface;
use LayBot\Request\Middleware\RetryPolicy;
use LayBot\Request\Signer\NoneSigner;
use LayBot\Request\Timeout\TimeoutConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Config
{
    /**
     * @param list<string> $sensitiveHeaders
     */
    public function __construct(
        public string                                 $baseUri,
        public array                                  $headers = [],
        public SignerInterface|ContextSignerInterface $signer =
        new NoneSigner(),
        public LoggerInterface|NullLogger             $logger = new NullLogger(),
        public bool                                   $verify = true,
        public TimeoutConfig                          $timeouts = new TimeoutConfig(),
        public RetryPolicy                            $retryPolicy = new RetryPolicy(),
        public string                                 $queryArrayFormat = 'brackets',
        public ?string                                $userAgent = null,
        public bool                                   $logBodies = false,
        public int                                    $maxResponseBytes = 16_777_216,
        public array                                  $sensitiveHeaders = [],
    ) {
        $this->baseUri = rtrim($this->baseUri, '/') . '/';

        if (!preg_match('#^https?://#i', $this->baseUri)) {
            throw new \InvalidArgumentException(
                'baseUri must use HTTP or HTTPS'
            );
        }

        if ($this->maxResponseBytes < 1) {
            throw new \InvalidArgumentException(
                'maxResponseBytes must be greater than zero'
            );
        }

        $this->sensitiveHeaders = self::normalizeHeaderNames(
            $this->sensitiveHeaders
        );
    }

    public function withSigner(
        SignerInterface|ContextSignerInterface $signer
    ): self {
        $new = clone $this;
        $new->signer = $signer;

        return $new;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $new = clone $this;
        $new->logger = $logger;

        return $new;
    }

    public function withHeaders(array $headers): self
    {
        $new = clone $this;
        $new->headers = array_merge($this->headers, $headers);

        return $new;
    }

    public function withTimeouts(TimeoutConfig $timeouts): self
    {
        $new = clone $this;
        $new->timeouts = $timeouts;

        return $new;
    }

    public function withVerify(bool $verify): self
    {
        $new = clone $this;
        $new->verify = $verify;

        return $new;
    }

    public function withRetryPolicy(RetryPolicy $policy): self
    {
        $new = clone $this;
        $new->retryPolicy = $policy;

        return $new;
    }

    /**
     * 注册日志中需要脱敏的自定义 Header。
     *
     * 支持完整名称及末尾通配符：
     *
     *   X-Gateway-Credential
     *   X-Proxy-*
     *
     * @param list<string> $headers
     */
    public function withSensitiveHeaders(
        array $headers,
        bool $replace = false
    ): self {
        $new = clone $this;

        $values = $replace
            ? $headers
            : array_merge($this->sensitiveHeaders, $headers);

        $new->sensitiveHeaders = self::normalizeHeaderNames($values);

        return $new;
    }

    /**
     * @param list<string> $headers
     * @return list<string>
     */
    private static function normalizeHeaderNames(array $headers): array
    {
        $result = [];

        foreach ($headers as $header) {
            $name = strtolower(trim((string)$header));

            if ($name !== '') {
                $result[] = $name;
            }
        }

        return array_values(array_unique($result));
    }
}
