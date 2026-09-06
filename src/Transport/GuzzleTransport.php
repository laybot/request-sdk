<?php
declare(strict_types=1);

namespace LayBot\Request\Transport;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\TransferStats;
use LayBot\Request\Contract\TransportInterface;
use LayBot\Request\DTO\PreparedRequest;
use LayBot\Request\DTO\Response;
use LayBot\Request\DTO\StreamResult;
use LayBot\Request\Enum\StreamTermination;
use LayBot\Request\Exception\CancelledException;
use LayBot\Request\Exception\ConnectionException;
use LayBot\Request\Exception\ConnectTimeoutException;
use LayBot\Request\Exception\RequestTimeoutException;
use LayBot\Request\Exception\ResponseTooLargeException;
use LayBot\Request\Exception\StreamCallbackException;
use LayBot\Request\Exception\StreamIdleTimeoutException;
use LayBot\Request\Exception\TlsException;
use LayBot\Request\Exception\UnexpectedEofException;
use LayBot\Request\Support\ExceptionFactory;
use LayBot\Request\Support\Header;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class GuzzleTransport implements TransportInterface
{
    private GuzzleClient $client;

    public function __construct(
        private readonly bool $verify = true
    ) {
        $this->client = new GuzzleClient([
            'http_errors' => false,
            'allow_redirects' => false,
            'verify' => $this->verify,
            'decode_content' => false,
        ]);
    }

    public function request(PreparedRequest $request): Response
    {
        $request->cancellation?->throwIfCancelled();

        $startedAt = hrtime(true);
        $effectiveUrl = $request->url;

        $options = $this->options($request);
        $options['timeout'] = $request->timeouts->request;

        $options['on_stats'] = static function (
            TransferStats $stats
        ) use (&$effectiveUrl): void {
            $effectiveUrl = (string)$stats->getEffectiveUri();
        };

        try {
            $response = $this->client->request(
                $request->method,
                $request->url,
                $options
            );

            $body = $request->sink !== null
                ? ''
                : $this->readLimited(
                    $response->getBody(),
                    $request->maxResponseBytes
                );

            $status = $response->getStatusCode();
            $headers = Header::normalize($response->getHeaders());

            if ($status < 200 || $status >= 300) {
                throw ExceptionFactory::http(
                    $request->method,
                    $request->url,
                    $status,
                    $headers,
                    $body
                );
            }

            $request->cancellation?->throwIfCancelled();

            return new Response(
                status: $status,
                headers: $headers,
                body: $body,
                url: $effectiveUrl,
                durationMs: self::elapsed($startedAt),
                protocolVersion: $response->getProtocolVersion(),
            );
        } catch (
        ResponseTooLargeException|CancelledException $error
        ) {
            throw $error;
        } catch (
        ConnectException|GuzzleRequestException $error
        ) {
            throw $this->mapNetworkError(
                $error,
                $request,
                false
            );
        }
    }

    public function stream(
        PreparedRequest $request,
        callable $onChunk
    ): StreamResult {
        $request->cancellation?->throwIfCancelled();

        $startedAt = hrtime(true);
        $effectiveUrl = $request->url;
        $bytes = 0;
        $body = null;
        $termination = StreamTermination::MESSAGE_COMPLETE;

        $options = $this->options($request);
        $options['stream'] = true;
        $options['timeout'] = $request->timeouts->request;

        if ($request->timeouts->idle > 0) {
            $options['curl'] = [
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME =>
                    max(1, (int)ceil($request->timeouts->idle)),
            ];
        }

        $options['on_stats'] = static function (
            TransferStats $stats
        ) use (&$effectiveUrl): void {
            $effectiveUrl = (string)$stats->getEffectiveUri();
        };

        try {
            $response = $this->client->request(
                $request->method,
                $request->url,
                $options
            );

            $status = $response->getStatusCode();
            $headers = Header::normalize($response->getHeaders());
            $body = $response->getBody();

            if ($status < 200 || $status >= 300) {
                $errorBody = $this->readLimited($body, 65_536);

                throw ExceptionFactory::http(
                    $request->method,
                    $request->url,
                    $status,
                    $headers,
                    $errorBody
                );
            }

            while (!$body->eof()) {
                $request->cancellation?->throwIfCancelled();

                $chunk = $body->read(8192);

                if ($chunk === '') {
                    continue;
                }

                $bytes += strlen($chunk);

                if ($bytes > $request->maxResponseBytes) {
                    throw new ResponseTooLargeException(
                        'stream response exceeds configured size limit'
                    );
                }

                try {
                    $continue = $onChunk($chunk);
                } catch (\Throwable $error) {
                    throw new StreamCallbackException(
                        'stream callback failed: '
                        . $error->getMessage(),
                        previous: $error
                    );
                }

                if ($continue === false) {
                    $termination =
                        StreamTermination::CALLBACK_STOP;
                    break;
                }
            }

            return new StreamResult(
                status: $status,
                headers: $headers,
                url: $effectiveUrl,
                termination: $termination,
                bytesReceived: $bytes,
                durationMs: self::elapsed($startedAt),
            );
        } catch (
        CancelledException|ResponseTooLargeException
        |StreamCallbackException $error
        ) {
            throw $error;
        } catch (
        ConnectException|GuzzleRequestException $error
        ) {
            throw $this->mapNetworkError(
                $error,
                $request,
                true
            );
        } finally {
            if ($body instanceof StreamInterface) {
                $body->close();
            }
        }
    }

    private function options(PreparedRequest $request): array
    {
        $options = [
            'headers' => $request->headers,
            'connect_timeout' => $request->timeouts->connect,
            'verify' => $this->verify,
            'decode_content' => false,
        ];

        if ($request->multipart !== null) {
            $options['multipart'] = $request->multipart;
        } elseif ($request->body !== '') {
            $options['body'] = $request->body;
        }

        if ($request->proxy !== null) {
            $options['proxy'] = $request->proxy;
        }

        if ($request->sink !== null) {
            $limit = $request->maxResponseBytes;

            $options['sink'] = $request->sink;

            $options['on_headers'] = static function (
                ResponseInterface $response
            ) use ($limit): void {
                $length = $response->getHeaderLine(
                    'Content-Length'
                );

                if (
                    $length !== ''
                    && ctype_digit($length)
                    && (int)$length > $limit
                ) {
                    throw new ResponseTooLargeException(
                        "download exceeds {$limit} bytes"
                    );
                }
            };

            $options['progress'] = static function (
                int $downloadTotal,
                int $downloadedBytes
            ) use ($limit): void {
                if (
                    $downloadTotal > $limit
                    || $downloadedBytes > $limit
                ) {
                    throw new ResponseTooLargeException(
                        "download exceeds {$limit} bytes"
                    );
                }
            };
        }

        return $options;
    }

    private function readLimited(
        StreamInterface $body,
        int $limit
    ): string {
        $result = '';

        while (!$body->eof()) {
            $remaining = $limit - strlen($result);

            if ($remaining <= 0) {
                throw new ResponseTooLargeException(
                    "response exceeds {$limit} bytes"
                );
            }

            $result .= $body->read(min(8192, $remaining));
        }

        return $result;
    }

    private function mapNetworkError(
        \Throwable $error,
        PreparedRequest $request,
        bool $stream
    ): \Throwable {
        $context = method_exists($error, 'getHandlerContext')
            ? $error->getHandlerContext()
            : [];

        $errno = (int)($context['errno'] ?? 0);
        $message = $error->getMessage();

        if (in_array(
            $errno,
            [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90],
            true
        )) {
            return new TlsException(
                message: 'TLS request failed: ' . $message,
                previous: $error,
                method: $request->method,
                url: $request->url
            );
        }

        if ($errno === 18 || preg_match(
                '/transfer closed|unexpected eof|connection reset/i',
                $message
            )) {
            return new UnexpectedEofException(
                message: 'HTTP response ended unexpectedly: ' . $message,
                previous: $error,
                method: $request->method,
                url: $request->url,
                retryable: true
            );
        }

        if ($errno === 28) {
            if ($error instanceof ConnectException) {
                return new ConnectTimeoutException(
                    message: 'connection timeout',
                    previous: $error,
                    method: $request->method,
                    url: $request->url,
                    retryable: true
                );
            }

            if ($stream && $request->timeouts->idle > 0) {
                return new StreamIdleTimeoutException(
                    message: 'stream idle timeout',
                    previous: $error,
                    method: $request->method,
                    url: $request->url,
                    retryable: true
                );
            }

            return new RequestTimeoutException(
                message: 'request timeout',
                previous: $error,
                method: $request->method,
                url: $request->url,
                retryable: true
            );
        }

        return new ConnectionException(
            message: 'request connection failed: ' . $message,
            previous: $error,
            method: $request->method,
            url: $request->url,
            retryable: true
        );
    }

    private static function elapsed(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
