<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client decorator that opens a CLIENT span around each outgoing request,
 * records HTTP attributes, marks 4xx/5xx as errors, and injects the current
 * trace context (`traceparent`) so the downstream service continues the trace.
 *
 * Only method / host / path are recorded — never the full URL — to avoid leaking
 * query strings or credentials.
 *
 * @api
 */
final readonly class HttpClientSpanDecorator implements ClientInterface
{
    private const int CLIENT_ERROR_THRESHOLD = 400;

    public function __construct(
        private ClientInterface $client,
        private TracerInterface $tracer,
        private TraceContextPropagator $propagator = new TraceContextPropagator(),
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->tracer->trace(
            name: 'HTTP ' . $request->getMethod(),
            callback: function (SpanInterface $span) use ($request): ResponseInterface {
                $span->setAttribute('http.request.method', $request->getMethod());
                $span->setAttribute('server.address', $request->getUri()->getHost());
                $span->setAttribute('url.path', $request->getUri()->getPath());

                $propagated = $this->propagator->inject($this->tracer->getContext(), $request);
                $response = $this->client->sendRequest($propagated);

                $status = $response->getStatusCode();
                $span->setAttribute('http.response.status_code', $status);

                if ($status >= self::CLIENT_ERROR_THRESHOLD) {
                    $span->setStatus(SpanStatusCode::Error, 'HTTP ' . $status);
                }

                return $response;
            },
            traceKind: TraceKind::Client,
        );
    }
}
