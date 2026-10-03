<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Psr\Http\Message\RequestInterface;

/**
 * Attribute and error-marking rules shared by every outgoing-HTTP
 * instrumentation ({@see HttpClientSpanDecorator}, {@see GuzzleTracingMiddleware}),
 * so they never drift apart.
 *
 * Only method / host / path are recorded — never the full URL — to avoid
 * leaking query strings or credentials.
 *
 * @internal
 */
final readonly class HttpSpanSupport
{
    private const int CLIENT_ERROR_THRESHOLD = 400;

    public static function spanName(RequestInterface $request): string
    {
        return 'HTTP ' . $request->getMethod();
    }

    /**
     * @return array<string, string>
     */
    public static function requestAttributes(RequestInterface $request): array
    {
        return [
            'http.request.method' => $request->getMethod(),
            'server.address' => $request->getUri()->getHost(),
            'url.path' => $request->getUri()->getPath(),
        ];
    }

    public static function recordStatus(SpanInterface $span, int $status): void
    {
        $span->setAttribute('http.response.status_code', $status);

        if ($status >= self::CLIENT_ERROR_THRESHOLD) {
            $span->setStatus(SpanStatusCode::Error, 'HTTP ' . $status);
        }
    }
}
