<?php
declare(strict_types=1);

namespace LayBot\Request\Signer;

use LayBot\Request\Contract\SignerInterface;

/**
 * HeaderSigner
 *
 * 用于注入任意静态自定义请求头。
 *
 * 适用场景：
 * - X-Export-Token
 * - X-Service-Token
 * - X-Internal-App
 * - 其他任意固定 Header 鉴权/标识
 */
final class HeaderSigner implements SignerInterface
{
    /**
     * @param array<string,string|int|float|bool|null> $headers
     */
    public function __construct(private array $headers)
    {
    }

    public function sign(string $method, string $path, string $body = ''): array
    {
        $out = [];

        foreach ($this->headers as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }

            if ($value === null) {
                $out[$key] = '';
                continue;
            }

            if (is_bool($value)) {
                $out[$key] = $value ? '1' : '0';
                continue;
            }

            $out[$key] = (string)$value;
        }

        return $out;
    }
}
