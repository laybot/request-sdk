<?php
declare(strict_types=1);

namespace LayBot\Request\Protocol;

use Workerman\Connection\AsyncTcpConnection;

/**
 * RFC 6455 WebSocket 客户端协议。
 *
 * 特性：
 * - 客户端帧使用随机 Mask；
 * - 区分文本帧和二进制帧；
 * - 校验 RSV、Opcode、控制帧和最短长度编码；
 * - 严格校验 HTTP Upgrade 握手。
 */
final class WebSocketClientProtocol
{
    public static function onConnect(
        AsyncTcpConnection $connection
    ): void {
        $key = base64_encode(random_bytes(16));

        $connection->context->laybotWsKey = $key;
        $connection->context->laybotWsHandshake = false;

        $path = $connection->context->laybotWsPath ?? '/';
        $host = $connection->context->laybotWsHost ?? '';
        $headers = $connection->context->laybotWsHeaders ?? [];

        $request = "GET {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Upgrade: websocket\r\n";
        $request .= "Connection: Upgrade\r\n";
        $request .= "Sec-WebSocket-Version: 13\r\n";
        $request .= "Sec-WebSocket-Key: {$key}\r\n";

        $origin = $connection->context->laybotWsOrigin ?? null;
        $protocol = $connection->context->laybotWsSubProtocol ?? null;

        if ($origin !== null) {
            $request .= "Origin: {$origin}\r\n";
        }

        if ($protocol !== null) {
            $request .= "Sec-WebSocket-Protocol: {$protocol}\r\n";
        }

        foreach ($headers as $name => $value) {
            foreach ((array)$value as $item) {
                $request .= "{$name}: {$item}\r\n";
            }
        }

        $connection->send($request . "\r\n", true);
    }

    public static function input(
        string $buffer,
        AsyncTcpConnection $connection
    ): int {
        if (empty($connection->context->laybotWsHandshake)) {
            $position = strpos($buffer, "\r\n\r\n");

            if ($position === false) {
                if (strlen($buffer) > 65_536) {
                    return self::protocolError(
                        $connection,
                        'WebSocket handshake headers too large'
                    );
                }

                return 0;
            }

            return $position + 4;
        }

        $bufferLength = strlen($buffer);

        if ($bufferLength < 2) {
            return 0;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);

        $final = ($first & 0x80) !== 0;
        $rsv = $first & 0x70;
        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) !== 0;
        $payloadLength = $second & 0x7f;
        $offset = 2;

        if ($rsv !== 0) {
            return self::protocolError(
                $connection,
                'unexpected WebSocket RSV bits'
            );
        }

        if ($masked) {
            return self::protocolError(
                $connection,
                'server WebSocket frame must not be masked'
            );
        }

        if (!in_array($opcode, [0, 1, 2, 8, 9, 10], true)) {
            return self::protocolError(
                $connection,
                "unsupported WebSocket opcode: {$opcode}"
            );
        }

        if ($payloadLength === 126) {
            if ($bufferLength < 4) {
                return 0;
            }

            $payloadLength = unpack(
                'nlength',
                substr($buffer, 2, 2)
            )['length'];

            if ($payloadLength < 126) {
                return self::protocolError(
                    $connection,
                    'non-minimal WebSocket length encoding'
                );
            }

            $offset = 4;
        } elseif ($payloadLength === 127) {
            if ($bufferLength < 10) {
                return 0;
            }

            $parts = unpack(
                'Nhigh/Nlow',
                substr($buffer, 2, 8)
            );

            if (
                ($parts['high'] & 0x80000000) !== 0
                || $parts['high'] !== 0
            ) {
                return self::protocolError(
                    $connection,
                    'WebSocket frame is too large'
                );
            }

            $payloadLength = $parts['low'];

            if ($payloadLength <= 65_535) {
                return self::protocolError(
                    $connection,
                    'non-minimal WebSocket length encoding'
                );
            }

            $offset = 10;
        }

        if ($opcode >= 8) {
            if (!$final || $payloadLength > 125) {
                return self::protocolError(
                    $connection,
                    'invalid WebSocket control frame'
                );
            }
        }

        $frameLength = $offset + $payloadLength;

        if ($frameLength > $connection->maxPackageSize) {
            return self::protocolError(
                $connection,
                'WebSocket frame exceeds configured size limit'
            );
        }

        return $bufferLength >= $frameLength
            ? $frameLength
            : 0;
    }

    public static function decode(
        string $buffer,
        AsyncTcpConnection $connection
    ): WebSocketHandshake|WebSocketFrame {
        if (empty($connection->context->laybotWsHandshake)) {
            return self::decodeHandshake($buffer, $connection);
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);

        $opcode = $first & 0x0f;
        $final = ($first & 0x80) !== 0;
        $length = $second & 0x7f;
        $offset = 2;

        if ($length === 126) {
            $length = unpack(
                'nlength',
                substr($buffer, 2, 2)
            )['length'];

            $offset = 4;
        } elseif ($length === 127) {
            $length = unpack(
                'Nhigh/Nlow',
                substr($buffer, 2, 8)
            )['low'];

            $offset = 10;
        }

        return new WebSocketFrame(
            opcode: $opcode,
            final: $final,
            payload: substr($buffer, $offset, $length)
        );
    }

    public static function encode(
        mixed $value,
        AsyncTcpConnection $connection
    ): string {
        if (!$value instanceof WebSocketOutboundFrame) {
            throw new \InvalidArgumentException(
                'WebSocketClientProtocol expects WebSocketOutboundFrame'
            );
        }

        if (!in_array(
            $value->opcode,
            [0x1, 0x2, 0x8, 0x9, 0xA],
            true
        )) {
            throw new \InvalidArgumentException(
                'unsupported outbound WebSocket opcode'
            );
        }

        $payload = $value->payload;
        $length = strlen($payload);

        if ($value->opcode >= 8 && $length > 125) {
            throw new \InvalidArgumentException(
                'WebSocket control payload exceeds 125 bytes'
            );
        }

        $first = chr(0x80 | $value->opcode);
        $mask = random_bytes(4);

        if ($length <= 125) {
            $header = $first . chr(0x80 | $length);
        } elseif ($length <= 65_535) {
            $header = $first
                . chr(0x80 | 126)
                . pack('n', $length);
        } else {
            $header = $first
                . chr(0x80 | 127)
                . pack('N2', 0, $length);
        }

        $masked = $payload;

        for ($index = 0; $index < $length; $index++) {
            $masked[$index] =
                $payload[$index] ^ $mask[$index % 4];
        }

        return $header . $mask . $masked;
    }

    private static function decodeHandshake(
        string $buffer,
        AsyncTcpConnection $connection
    ): WebSocketHandshake {
        [$head] = explode("\r\n\r\n", $buffer, 2);
        $lines = explode("\r\n", $head);
        $statusLine = array_shift($lines) ?? '';

        if (!preg_match(
            '#^HTTP/\d(?:\.\d)?\s+(\d{3})#',
            $statusLine,
            $matches
        )) {
            return new WebSocketHandshake(
                0,
                [],
                false,
                'invalid WebSocket handshake status line'
            );
        }

        $status = (int)$matches[1];
        $headers = [];

        foreach ($lines as $line) {
            $position = strpos($line, ':');

            if ($position === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $position)));
            $headers[$name][] = trim(substr($line, $position + 1));
        }

        if ($status !== 101) {
            return new WebSocketHandshake(
                $status,
                $headers,
                false,
                "WebSocket server returned HTTP {$status}"
            );
        }

        $upgrade = strtolower(
            implode(',', $headers['upgrade'] ?? [])
        );

        if (trim($upgrade) !== 'websocket') {
            return new WebSocketHandshake(
                $status,
                $headers,
                false,
                'missing or invalid Upgrade: websocket'
            );
        }

        $connectionTokens = array_map(
            'trim',
            explode(
                ',',
                strtolower(implode(
                    ',',
                    $headers['connection'] ?? []
                ))
            )
        );

        if (!in_array('upgrade', $connectionTokens, true)) {
            return new WebSocketHandshake(
                $status,
                $headers,
                false,
                'missing Connection: Upgrade'
            );
        }

        $expected = base64_encode(sha1(
            $connection->context->laybotWsKey
            . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
            true
        ));

        $actual = $headers['sec-websocket-accept'][0] ?? '';

        if (!hash_equals($expected, $actual)) {
            return new WebSocketHandshake(
                $status,
                $headers,
                false,
                'invalid Sec-WebSocket-Accept'
            );
        }

        $requestedProtocol =
            $connection->context->laybotWsSubProtocol ?? null;

        $selectedProtocol =
            $headers['sec-websocket-protocol'][0] ?? null;

        if (
            $selectedProtocol !== null
            && (
                $requestedProtocol === null
                || !hash_equals(
                    $requestedProtocol,
                    $selectedProtocol
                )
            )
        ) {
            return new WebSocketHandshake(
                $status,
                $headers,
                false,
                'server selected an unexpected WebSocket subprotocol'
            );
        }

        $connection->context->laybotWsHandshake = true;

        return new WebSocketHandshake(
            status: $status,
            headers: $headers,
            valid: true
        );
    }

    private static function protocolError(
        AsyncTcpConnection $connection,
        string $message
    ): int {
        $connection->context->laybotWsProtocolError = $message;
        return -1;
    }
}
