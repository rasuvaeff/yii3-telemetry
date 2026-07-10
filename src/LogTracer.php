<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Development tracer that records real {@see Span}s and logs each finished span
 * via PSR-3. No exporter, no backend — useful for local debugging and examples.
 *
 * Maintains the active-span stack, so nested {@see trace()} calls inherit the
 * parent's `traceId` and honour `scoped`.
 *
 * @api
 */
final class LogTracer implements TracerInterface
{
    /** @var list<Span> */
    private array $spanStack = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly string $logLevel = LogLevel::DEBUG,
    ) {}

    /**
     * @template T
     *
     * @param callable(SpanInterface): T $callback
     * @param array<string, bool|int|float|string|array|null> $attributes
     *
     * @return T
     */
    #[\Override]
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        bool $scoped = true,
        TraceKind $traceKind = TraceKind::Internal,
        ?int $startNanos = null,
    ): mixed {
        $span = $this->createSpan($name, $attributes, $traceKind, $startNanos);

        if ($scoped) {
            $this->spanStack[] = $span;
        }

        try {
            return $callback($span);
        } catch (\Throwable $exception) {
            $span->recordException($exception);
            $span->setStatus(SpanStatusCode::Error, $exception->getMessage());

            throw $exception;
        } finally {
            $span->end();

            if ($scoped) {
                array_pop($this->spanStack);
            }
        }
    }

    /**
     * Creates a detached recording span (not activated). Like every
     * {@see LogTracer} span it is logged when it ends — the caller's
     * {@see SpanInterface::end()} triggers the log record, so split begin/end
     * instrumentation (DB profiler, view listener) shows up too.
     *
     * @param array<string, bool|int|float|string|array|null> $attributes
     */
    #[\Override]
    public function startSpan(
        string $name,
        array $attributes = [],
        TraceKind $traceKind = TraceKind::Internal,
        ?int $startNanos = null,
    ): SpanInterface {
        return $this->createSpan($name, $attributes, $traceKind, $startNanos);
    }

    #[\Override]
    public function currentSpan(): SpanInterface
    {
        if ($this->spanStack === []) {
            return NullSpan::instance();
        }

        return $this->spanStack[array_key_last($this->spanStack)];
    }

    #[\Override]
    public function getContext(): TraceContext
    {
        return $this->currentSpan()->getTraceContext();
    }

    /**
     * @param array<string, bool|int|float|string|array|null> $attributes
     */
    private function createSpan(string $name, array $attributes, TraceKind $kind, ?int $startNanos): Span
    {
        $span = new Span(
            $name,
            $this->childContext(),
            $kind,
            $this->clock,
            $startNanos,
            fn(Span $ended) => $this->logSpan($ended),
        );

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        return $span;
    }

    private function childContext(): TraceContext
    {
        $parent = $this->spanStack === []
            ? null
            : $this->spanStack[array_key_last($this->spanStack)]->getTraceContext();

        if ($parent !== null && $parent->isValid()) {
            return new TraceContext(
                traceId: $parent->traceId,
                spanId: $this->generateSpanId(),
                traceFlags: $parent->traceFlags,
                traceState: $parent->traceState,
            );
        }

        return new TraceContext(
            traceId: $this->generateTraceId(),
            spanId: $this->generateSpanId(),
        );
    }

    private function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function logSpan(Span $span): void
    {
        $context = $span->getTraceContext();

        $this->logger->log($this->logLevel, \sprintf('span %s', $span->getName()), [
            'trace_id' => $context->traceId,
            'span_id' => $context->spanId,
            'kind' => $span->getKind()->name,
            'status' => $span->getStatus()->code->value,
            'duration_ns' => $span->getDurationNanos(),
            'attributes' => $span->getAttributes(),
            'events' => array_map(
                static fn(SpanEvent $event): string => $event->name,
                $span->getEvents(),
            ),
            'exceptions' => array_map(
                static fn(\Throwable $e): string => $e::class . ': ' . $e->getMessage(),
                $span->getRecordedExceptions(),
            ),
        ]);
    }
}
