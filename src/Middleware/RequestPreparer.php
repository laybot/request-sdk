<?php
declare(strict_types=1);

namespace LayBot\Request\Middleware;

use LayBot\Request\Config;
use LayBot\Request\Contract\ContextSignerInterface;
use LayBot\Request\DTO\PreparedRequest;
use LayBot\Request\DTO\SigningContext;
use LayBot\Request\Exception\ConfigurationException;
use LayBot\Request\Signer\NoneSigner;
use LayBot\Request\Stream\CancellationToken;
use LayBot\Request\Support\Header;
use LayBot\Request\Support\Json;
use LayBot\Request\Support\Query;
use LayBot\Request\Support\UserAgent;
use LayBot\Request\Timeout\TimeoutConfig;

final class RequestPreparer
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * 生成不可变的最终请求。
     *
     * 安全规则：
     * 1. 默认禁止向其他 Origin 发送绝对 URL；
     * 2. 显式允许跨 Origin 时，默认移除认证 Header 并跳过 Signer；
     * 3. Signer 执行后 Transport 不得修改 Query、Body 或签名 Header。
     */
    public function prepare(
        string $method,
        string $path,
        array $options = [],
        bool $stream = false
    ): PreparedRequest {
        $method = strtoupper($method);
        $this->assertPayloadMode($options);

        $body = '';
        $multipart = null;

        $headers = Header::merge(
            $this->config->headers,
            (array)($options['headers'] ?? [])
        );

        if (array_key_exists('json', $options)) {
            $body = Json::encode($options['json']);
            $headers = Header::setIfMissing(
                $headers,
                'Content-Type',
                'application/json'
            );
        } elseif (array_key_exists('form_params', $options)) {
            $body = Query::build(
                (array)$options['form_params'],
                'brackets'
            );
            $headers = Header::setIfMissing(
                $headers,
                'Content-Type',
                'application/x-www-form-urlencoded'
            );
        } elseif (array_key_exists('multipart', $options)) {
            $multipart = (array)$options['multipart'];

            /*
             * Multipart Boundary 和最终 Body 由底层传输生成。
             * 对正文做 Hash 的上下文 Signer 无法在此获得最终字节。
             */
            if (
                $this->config->signer instanceof ContextSignerInterface
                && !$this->config->signer instanceof NoneSigner
                && !($options['allow_unsigned_multipart'] ?? false)
            ) {
                throw new ConfigurationException(
                    'multipart with ContextSigner requires '
                    . 'allow_unsigned_multipart=true or a provider-specific '
                    . 'multipart signer'
                );
            }

            $headers = Header::remove($headers, 'Content-Type');
        } elseif (array_key_exists('body', $options)) {
            if (!is_string($options['body'])) {
                throw new ConfigurationException(
                    'body must be a string; use multipart for file streams'
                );
            }

            $body = $options['body'];
        }

        $query = Query::build(
            $options['query'] ?? null,
            $this->config->queryArrayFormat
        );

        [$url, $crossOrigin] = $this->buildUrl($path, $query);

        if (
            $crossOrigin
            && !($options['allow_cross_origin'] ?? false)
        ) {
            throw new ConfigurationException(
                'cross-origin absolute URL is disabled'
            );
        }

        $forwardCredentials = (bool)(
            $options['forward_cross_origin_credentials'] ?? false
        );

        $applySigner = true;

        if ($crossOrigin && !$forwardCredentials) {
            $headers = Header::withoutCredentials(
                $headers,
                $this->config->sensitiveHeaders
            );
            $applySigner = false;
        }

        $parts = parse_url($url);

        if (
            $parts === false
            || empty($parts['scheme'])
            || empty($parts['host'])
        ) {
            throw new ConfigurationException(
                "invalid request URL: {$url}"
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ConfigurationException(
                'URL user information is not allowed'
            );
        }

        if (isset($parts['fragment'])) {
            throw new ConfigurationException(
                'URL fragment is not allowed in an HTTP request'
            );
        }

        $headers = Header::setIfMissing(
            $headers,
            'User-Agent',
            $this->config->userAgent ?: UserAgent::default()
        );

        $headers = Header::setIfMissing(
            $headers,
            'Accept-Encoding',
            'identity'
        );

        $hostHeader = $this->hostHeader($parts);

        $headers = Header::setIfMissing(
            $headers,
            'Host',
            $hostHeader
        );

        $pathOnly = $parts['path'] ?? '/';

        if ($applySigner) {
            if ($this->config->signer instanceof ContextSignerInterface) {
                $signatureHeaders = $this->config->signer->signRequest(
                    new SigningContext(
                        method: $method,
                        url: $url,
                        scheme: strtolower((string)$parts['scheme']),
                        host: (string)$parts['host'],
                        port: isset($parts['port'])
                            ? (int)$parts['port']
                            : null,
                        path: $pathOnly,
                        canonicalQuery: $parts['query'] ?? '',
                        body: $body,
                        headers: $headers,
                    )
                );
            } else {
                $signatureHeaders = $this->config->signer->sign(
                    $method,
                    $pathOnly,
                    $body
                );
            }

            $headers = Header::merge($headers, $signatureHeaders);
        }

        $timeouts = $this->resolveTimeouts($options, $stream);
        $cancellation = $options['cancellation'] ?? null;

        if (
            $cancellation !== null
            && !$cancellation instanceof CancellationToken
        ) {
            throw new ConfigurationException(
                'cancellation must be CancellationToken'
            );
        }

        return new PreparedRequest(
            method: $method,
            url: $url,
            headers: $headers,
            body: $body,
            multipart: $multipart,
            timeouts: $timeouts,
            maxResponseBytes: max(
                1,
                (int)($options['max_response_bytes']
                    ?? $this->config->maxResponseBytes)
            ),
            cancellation: $cancellation,
            proxy: isset($options['proxy'])
                ? (string)$options['proxy']
                : null,
            sink: $options['sink'] ?? null,
        );
    }

    /**
     * @return array{0:string,1:bool}
     */
    private function buildUrl(string $path, string $query): array
    {
        $absolute = preg_match('#^https?://#i', $path) === 1;

        $url = $absolute
            ? $path
            : $this->config->baseUri . ltrim($path, '/');

        if ($query !== '') {
            $url .= str_contains($url, '?') ? '&' : '?';
            $url .= $query;
        }

        return [
            $url,
            $absolute && !$this->sameOrigin(
                $url,
                $this->config->baseUri
            ),
        ];
    }

    private function sameOrigin(string $left, string $right): bool
    {
        $a = parse_url($left);
        $b = parse_url($right);

        if ($a === false || $b === false) {
            return false;
        }

        return strtolower((string)($a['scheme'] ?? ''))
            === strtolower((string)($b['scheme'] ?? ''))
            && strtolower((string)($a['host'] ?? ''))
            === strtolower((string)($b['host'] ?? ''))
            && $this->effectivePort($a)
            === $this->effectivePort($b);
    }

    private function effectivePort(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int)$parts['port'];
        }

        return strtolower((string)($parts['scheme'] ?? '')) === 'https'
            ? 443
            : 80;
    }

    private function hostHeader(array $parts): string
    {
        $host = (string)$parts['host'];

        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $port = $this->effectivePort($parts);
        $default = strtolower((string)$parts['scheme']) === 'https'
            ? 443
            : 80;

        return $port === $default ? $host : "{$host}:{$port}";
    }

    private function resolveTimeouts(
        array $options,
        bool $stream
    ): TimeoutConfig {
        if (($options['timeouts'] ?? null) instanceof TimeoutConfig) {
            return $options['timeouts'];
        }

        $values = is_array($options['timeouts'] ?? null)
            ? $options['timeouts']
            : [];

        $values['connect'] ??=
            $options['connect_timeout']
            ?? $this->config->timeouts->connect;

        $values['request'] ??=
            $options['request_timeout']
            ?? ($stream ? 0 : $this->config->timeouts->request);

        $values['idle'] ??=
            $options['idle_timeout']
            ?? $this->config->timeouts->idle;

        return TimeoutConfig::fromArray(
            $values,
            $this->config->timeouts
        );
    }

    private function assertPayloadMode(array $options): void
    {
        $count = 0;

        foreach (['json', 'form_params', 'multipart', 'body'] as $key) {
            $count += array_key_exists($key, $options) ? 1 : 0;
        }

        if ($count > 1) {
            throw new ConfigurationException(
                'only one payload mode may be used'
            );
        }
    }
}
