<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceState;

/**
 * Queue-agnostic tracing primitives: a producer-side {@see inject()} and a
 * consumer-side {@see consume()}. They work on a plain message metadata map, so
 * any queue (yiisoft/queue, AMQP, SQS, a DB table) can be wired with a few lines
 * in its push/consume middlewares.
 *
 * Producer: {@see inject()} adds the W3C headers of the current context and the
 * enqueue timestamp (unix-epoch nanoseconds) to the metadata.
 *
 * Consumer: {@see consume()} runs the handler inside a CONSUMER span that
 * - is parented to the producer's span (a fresh root when the message has no
 *   valid headers — e.g. pushed before tracing was enabled). The parent is
 *   activated through the OpenTelemetry context API, so it takes effect with an
 *   OpenTelemetry backend (`yii3-telemetry-otel`); other tracers start a root;
 * - never nests under whatever is active in a long-running worker: it parents to
 *   the producer or becomes a root, never to the console-command span;
 * - covers the processing only (duration = handler time); time spent in the
 *   queue is the `messaging.message.queue_time_nanos` attribute — measured from
 *   the enqueue time, or from the scheduled time for a delayed message;
 * - is ended before `consume()` returns or throws; on an exception it records the
 *   exception, sets status Error and re-throws (the `trace()` contract). One span
 *   per message, so the next job of a long-running worker starts clean.
 *
 * @api
 */
final readonly class QueueTracing
{
    /** Metadata key: unix-epoch nanoseconds when the message was pushed. */
    public const string ENQUEUED_AT_KEY = 'telemetry.enqueued_at_nanos';

    /** Metadata key: unix-epoch nanoseconds when a delayed message becomes available. */
    public const string AVAILABLE_AT_KEY = 'telemetry.available_at_nanos';

    public const string QUEUE_TIME_ATTRIBUTE = 'messaging.message.queue_time_nanos';

    private const int NANOS_PER_SECOND = 1_000_000_000;
    private const int NANOS_PER_MICROSECOND = 1000;

    public function __construct(
        private TracerInterface $tracer,
        private TraceContextPropagator $propagator = new TraceContextPropagator(),
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * Returns the metadata with the current trace context and timestamps added;
     * every other key is kept. Call it from a push middleware.
     *
     * @param array<string, mixed> $metadata message metadata (headers) as the queue carries it
     * @param int|null $enqueuedAtNanos unix-epoch nanoseconds of the push; `null` = now
     * @param int|null $availableAtNanos for a delayed message, unix-epoch nanoseconds
     *        when it becomes available; wait time is then measured from that moment
     *
     * @return array<string, mixed>
     */
    public function inject(array $metadata, ?int $enqueuedAtNanos = null, ?int $availableAtNanos = null): array
    {
        unset($metadata[self::AVAILABLE_AT_KEY]);

        $metadata = [
            ...$metadata,
            ...$this->propagator->toHeaders($this->tracer->getContext()),
            self::ENQUEUED_AT_KEY => $enqueuedAtNanos ?? $this->nowNanos(),
        ];

        if ($availableAtNanos !== null) {
            $metadata[self::AVAILABLE_AT_KEY] = $availableAtNanos;
        }

        return $metadata;
    }

    /**
     * Runs `$handler` inside a CONSUMER span for one message. Call it from a
     * consume middleware, once per message.
     *
     * @template T
     *
     * @param array<string, mixed> $metadata the metadata the message arrived with
     * @param string $name span name — a low-cardinality operation, e.g. `process email.send`
     * @param callable(SpanInterface): T $handler
     * @param array<string, bool|int|float|string|array|null> $attributes extra span
     *        attributes (`messaging.system`, `messaging.destination.name`, a retry
     *        attempt, …); they win over the ones set here
     *
     * @return T
     */
    public function consume(array $metadata, string $name, callable $handler, array $attributes = []): mixed
    {
        $context = $this->propagator->fromHeaders($this->headers($metadata));
        $startNanos = $this->nowNanos();

        $spanAttributes = ['messaging.operation.type' => 'process'];
        $waitSince = $this->waitSince($metadata);

        if ($waitSince !== null) {
            $spanAttributes[self::QUEUE_TIME_ATTRIBUTE] = max(0, $startNanos - $waitSince);
        }

        $scope = $this->parentSpan($context)->activate();

        try {
            return $this->tracer->trace(
                name: $name,
                callback: $handler,
                attributes: [...$spanAttributes, ...$attributes],
                traceKind: TraceKind::Consumer,
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
     * @param array<string, mixed> $metadata
     *
     * @return array<string, string>
     */
    private function headers(array $metadata): array
    {
        $headers = [];

        /** @var mixed $value */
        foreach ($metadata as $key => $value) {
            if (\is_string($value)) {
                $headers[$key] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function waitSince(array $metadata): ?int
    {
        $enqueued = $this->nanos($metadata[self::ENQUEUED_AT_KEY] ?? null);
        $available = $this->nanos($metadata[self::AVAILABLE_AT_KEY] ?? null);

        return $enqueued === null
            ? $available
            : ($available === null ? $enqueued : max($enqueued, $available));
    }

    private function nanos(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (\is_string($value) && preg_match('/^(0|[1-9][0-9]{0,18})\z/', $value) === 1) {
            $nanos = (int) $value;

            return (string) $nanos === $value ? $nanos : null;
        }

        return null;
    }

    private function nowNanos(): int
    {
        $now = $this->clock->now();

        return $now->getTimestamp() * self::NANOS_PER_SECOND
            + \intval($now->format('u')) * self::NANOS_PER_MICROSECOND;
    }
}
