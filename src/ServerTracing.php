<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceState;

/**
 * Carrier-agnostic SERVER tracing: {@see serve()} runs a handler inside a
 * SERVER span for a call that did not come in as a PSR-7 request (a gRPC call
 * whose trace context is in the metadata, a Rapira dispatcher, a message RPC),
 * parented to the caller's span. It is the server-side sibling of
 * {@see QueueTracing::consume()}: the caller's context goes through the
 * OpenTelemetry context API and is detached in a `finally`; without a valid
 * header the span starts a fresh trace — never the previous call's, on a
 * long-lived worker. No transport is assumed: the metadata is a plain map.
 *
 * @api
 */
final readonly class ServerTracing
{
    public function __construct(
        private TracerInterface $tracer,
        private TraceContextPropagator $propagator = new TraceContextPropagator(),
    ) {}

    /**
     * Runs `$handler` inside a SERVER span for one incoming call. Call it once
     * per request, at the top of the server-side handling.
     *
     * @template T
     *
     * @param array<string, mixed> $headers the call's metadata (a gRPC metadata
     *        map, an AMQP header table, …) — only the W3C `traceparent` /
     *        `tracestate` entries are read; names are matched case-insensitively
     * @param string $name span name — a low-cardinality operation, e.g. `grpc tickets.hold`
     * @param callable(SpanInterface): T $handler
     * @param array<string, bool|int|float|string|array|null> $attributes extra span
     *        attributes (`rpc.system`, `rpc.method`, …); there are no defaults —
     *        what a server span says is the caller's business
     * @param TraceKind $kind span kind; a server span is the shape of an inbound
     *        call — pass another kind only for a special case
     *
     * @return T
     */
    public function serve(
        array $headers,
        string $name,
        callable $handler,
        array $attributes = [],
        TraceKind $kind = TraceKind::Server,
    ): mixed {
        $scope = $this->parentSpan($this->propagator->fromHeaders($this->headers($headers)))->activate();

        try {
            return $this->tracer->trace(
                name: $name,
                callback: $handler,
                attributes: $attributes,
                traceKind: $kind,
            );
        } finally {
            $scope->detach();
        }
    }

    private function parentSpan(TraceContext $context): \OpenTelemetry\API\Trace\SpanInterface
    {
        return OtelSpan::wrap(SpanContext::createFromRemoteParent(
            $context->traceId,
            $context->spanId,
            $context->traceFlags,
            $context->traceState === '' ? null : new TraceState($context->traceState),
        ));
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        $values = [];

        /** @var mixed $value */
        foreach ($headers as $key => $value) {
            if (\is_string($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
