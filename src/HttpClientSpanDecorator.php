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
 * Injection can be disabled or limited to an allowlist of hosts (see
 * {@see PropagationPolicy}); the span is recorded either way.
 *
 * Only method / host / path are recorded — never the full URL — to avoid leaking
 * query strings or credentials.
 *
 * @api
 */
final readonly class HttpClientSpanDecorator implements ClientInterface
{
    private PropagationPolicy $policy;

    /**
     * @param bool $propagate `false` = record the span but never inject `traceparent`
     * @param list<string> $propagateTo allowlist of hosts to inject into (exact host
     *        or leading `*.` wildcard, case-insensitive); empty = every host
     *
     * @throws Exception\InvalidArgumentException on an invalid `$propagateTo` entry
     */
    public function __construct(
        private ClientInterface $client,
        private TracerInterface $tracer,
        private TraceContextPropagator $propagator = new TraceContextPropagator(),
        bool $propagate = true,
        array $propagateTo = [],
    ) {
        $this->policy = new PropagationPolicy($propagate, $propagateTo);
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->tracer->trace(
            name: HttpSpanSupport::spanName($request),
            callback: function (SpanInterface $span) use ($request): ResponseInterface {
                foreach (HttpSpanSupport::requestAttributes($request) as $key => $value) {
                    $span->setAttribute($key, $value);
                }

                if ($this->policy->allows($request->getUri())) {
                    $request = $this->propagator->inject($this->tracer->getContext(), $request);
                }

                $response = $this->client->sendRequest($request);

                HttpSpanSupport::recordStatus($span, $response->getStatusCode());

                return $response;
            },
            traceKind: TraceKind::Client,
        );
    }
}
