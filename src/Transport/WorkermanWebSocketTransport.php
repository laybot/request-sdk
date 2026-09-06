<?php
declare(strict_types=1);

namespace LayBot\Request\Transport;

use LayBot\Request\Contract\WebSocketConnectionInterface;
use LayBot\Request\Contract\WebSocketConnectorInterface;
use LayBot\Request\Contract\WebSocketListenerInterface;
use LayBot\Request\DTO\WebSocketRequest;
use LayBot\Request\Exception\ConfigurationException;
use LayBot\Request\Protocol\WebSocketClientProtocol;
use LayBot\Request\Support\Env;
use LayBot\Request\Support\Header;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Http\ProxyHelper;

final class WorkermanWebSocketTransport implements
    WebSocketConnectorInterface
{
    public function connectAsync(
        WebSocketRequest $request,
        WebSocketListenerInterface $listener
    ): WebSocketConnectionInterface {
        if (!Env::inWorkermanLoop()) {
            throw new ConfigurationException(
                'WebSocket requires an active Workerman event loop'
            );
        }

        $parts = parse_url($request->url);

        if ($parts === false || empty($parts['host'])) {
            throw new ConfigurationException(
                'invalid WebSocket URL'
            );
        }

        if (
            isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new ConfigurationException(
                'WebSocket URL user information and fragment are not allowed'
            );
        }

        $scheme = strtolower((string)$parts['scheme']);
        $secure = $scheme === 'wss';
        $host = (string)$parts['host'];
        $port = (int)($parts['port'] ?? ($secure ? 443 : 80));

        $path = $parts['path'] ?? '/';

        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        $context = [];

        if ($secure) {
            $context['ssl'] = [
                'verify_peer' => $request->verify,
                'verify_peer_name' => $request->verify,
                'allow_self_signed' => !$request->verify,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'disable_compression' => true,
            ];
        }

        if ($request->proxy !== null) {
            $context = ProxyHelper::applyProxyToContext(
                $context,
                $request->proxy
            );
        }

        $addressHost = str_contains($host, ':')
            ? '[' . trim($host, '[]') . ']'
            : $host;

        $native = new AsyncTcpConnection(
            "tcp://{$addressHost}:{$port}",
            $context
        );

        if ($secure) {
            $native->transport = 'ssl';
        }

        if ($request->proxy !== null) {
            ProxyHelper::setConnectionProxy(
                $native,
                $context
            );
        }

        $native->protocol = WebSocketClientProtocol::class;
        $native->maxPackageSize =
            $request->maxMessageBytes + 14;
        $native->maxSendBufferSize =
            $request->hardBufferLimit;

        $headers = Header::remove(
            $request->headers,
            'Host',
            'Connection',
            'Upgrade',
            'Sec-WebSocket-Key',
            'Sec-WebSocket-Version',
            'Sec-WebSocket-Protocol',
            'Origin'
        );

        foreach ($headers as $name => $value) {
            Header::assertSafe((string)$name, $value);
        }

        $hostHeader = in_array(
            [$secure, $port],
            [[true, 443], [false, 80]],
            true
        )
            ? $host
            : "{$host}:{$port}";

        $native->context->laybotWsPath = $path;
        $native->context->laybotWsHost = $hostHeader;
        $native->context->laybotWsHeaders = $headers;
        $native->context->laybotWsOrigin = $request->origin;
        $native->context->laybotWsSubProtocol =
            $request->subProtocol;

        $connection = new WorkermanWebSocketConnection(
            $native,
            $request,
            $listener
        );

        $connection->connect();

        return $connection;
    }
}
