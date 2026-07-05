<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use InvalidArgumentException;
use LayBot\Request\Contract\SignerInterface;

/**
 * CanonicalHmacSigner
 *
 * 通用可配置 HMAC 签名器。
 *
 * 不绑定任何具体业务协议，通过 canonical_fields 控制签名原文。
 *
 * 支持字段：
 * - app_key
 * - method
 * - path
 * - timestamp
 * - nonce
 * - body
 * - body_md5
 * - body_sha256
 *
 * 默认适配通用 server-to-server 场景：
 *
 * METHOD
 * /path
 * timestamp
 * nonce
 * raw_body
 */
final class CanonicalHmacSigner implements SignerInterface
{
    private string $appKey;
    private string $secret;

    private string $appKeyHeader;
    private string $timestampHeader;
    private string $nonceHeader;
    private string $signatureHeader;

    private string $timestampUnit;
    private int $nonceBytes;
    private string $algo;
    private array $canonicalFields;
    private bool $uppercaseMethod;

    /**
     * @param array{
     *     app_key_header?:string,
     *     timestamp_header?:string,
     *     nonce_header?:string,
     *     signature_header?:string,
     *     timestamp_unit?:string,
     *     nonce_bytes?:int,
     *     algo?:string,
     *     canonical_fields?:array<int,string>,
     *     uppercase_method?:bool
     * } $options
     */
    public function __construct(string $appKey, string $secret, array $options = [])
    {
        $this->appKey = trim($appKey);
        $this->secret = (string)$secret;

        if ($this->appKey === '') {
            throw new InvalidArgumentException('appKey required');
        }

        if ($this->secret === '') {
            throw new InvalidArgumentException('secret required');
        }

        $this->appKeyHeader = (string)($options['app_key_header'] ?? 'X-App-Id');
        $this->timestampHeader = (string)($options['timestamp_header'] ?? 'X-Timestamp');
        $this->nonceHeader = (string)($options['nonce_header'] ?? 'X-Nonce');
        $this->signatureHeader = (string)($options['signature_header'] ?? 'X-Signature');

        $this->timestampUnit = strtolower((string)($options['timestamp_unit'] ?? 'ms'));
        if (!in_array($this->timestampUnit, ['ms', 'sec'], true)) {
            throw new InvalidArgumentException('timestamp_unit must be ms or sec');
        }

        $this->nonceBytes = max(4, (int)($options['nonce_bytes'] ?? 8));
        $this->algo = strtolower((string)($options['algo'] ?? 'sha256'));

        if (!in_array($this->algo, hash_hmac_algos(), true)) {
            throw new InvalidArgumentException("unsupported hmac algo: {$this->algo}");
        }

        $this->canonicalFields = (array)($options['canonical_fields'] ?? [
            'method',
            'path',
            'timestamp',
            'nonce',
            'body',
        ]);

        if (!$this->canonicalFields) {
            throw new InvalidArgumentException('canonical_fields required');
        }

        $this->uppercaseMethod = (bool)($options['uppercase_method'] ?? true);
    }

    public function sign(string $method, string $path, string $body = ''): array
    {
        $timestamp = $this->makeTimestamp();
        $nonce = bin2hex(random_bytes($this->nonceBytes));

        $ctx = [
            'app_key' => $this->appKey,
            'method' => $this->uppercaseMethod ? strtoupper($method) : $method,
            'path' => $this->normalizePath($path),
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'body' => $body,
            'body_md5' => $body !== '' ? md5($body, false) : '',
            'body_sha256' => $body !== '' ? hash('sha256', $body) : '',
        ];

        $lines = [];

        foreach ($this->canonicalFields as $field) {
            $field = strtolower(trim((string)$field));

            if (!array_key_exists($field, $ctx)) {
                throw new InvalidArgumentException("unsupported canonical field: {$field}");
            }

            $lines[] = (string)$ctx[$field];
        }

        $plain = implode("\n", $lines);
        $signature = hash_hmac($this->algo, $plain, $this->secret);

        return [
            $this->appKeyHeader => $this->appKey,
            $this->timestampHeader => $timestamp,
            $this->nonceHeader => $nonce,
            $this->signatureHeader => $signature,
        ];
    }

    private function makeTimestamp(): string
    {
        if ($this->timestampUnit === 'sec') {
            return (string)time();
        }

        return (string)round(microtime(true) * 1000);
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        if (preg_match('#^https?://#i', $path)) {
            $parts = parse_url($path);
            $p = (string)($parts['path'] ?? '/');

            if (!empty($parts['query'])) {
                $p .= '?' . $parts['query'];
            }

            return '/' . ltrim($p, '/');
        }

        return '/' . ltrim($path, '/');
    }
}
