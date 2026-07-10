<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests\Support;

use Rasuvaeff\Yii3Telemetry\ClockInterface;
use Rasuvaeff\Yii3Telemetry\NullSpan;
use Rasuvaeff\Yii3Telemetry\Span;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\SystemClock;
use Rasuvaeff\Yii3Telemetry\TraceContext;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Rasuvaeff\Yii3Telemetry\TracerInterface;

/**
 * In-memory tracer that keeps every created {@see Span} (from both {@see trace()}
 * and {@see startSpan()}) so instrumentation tests can assert on span names,
 * attributes, and status.
 */
final class RecordingTracer implements TracerInterface
{
    /** @var list<Span> */
    public array $spans = [];

    /** @var list<Span> */
    private array $stack = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
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
        $span = $this->make($name, $attributes, $traceKind);

        if ($scoped) {
            $this->stack[] = $span;
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
                array_pop($this->stack);
            }
        }
    }

    /**
     * @param array<string, bool|int|float|string|array|null> $attributes
     */
    #[\Override]
    public function startSpan(
        string $name,
        array $attributes = [],
        TraceKind $traceKind = TraceKind::Internal,
        ?int $startNanos = null,
    ): SpanInterface {
        return $this->make($name, $attributes, $traceKind);
    }

    #[\Override]
    public function currentSpan(): SpanInterface
    {
        if ($this->stack === []) {
            return NullSpan::instance();
        }

        return $this->stack[array_key_last($this->stack)];
    }

    #[\Override]
    public function getContext(): TraceContext
    {
        return $this->currentSpan()->getTraceContext();
    }

    /**
     * @param array<string, bool|int|float|string|array|null> $attributes
     */
    private function make(string $name, array $attributes, TraceKind $kind): Span
    {
        $parent = $this->stack === [] ? null : $this->stack[array_key_last($this->stack)]->getTraceContext();

        $context = $parent !== null && $parent->isValid()
            ? new TraceContext($parent->traceId, bin2hex(random_bytes(8)), $parent->traceFlags)
            : new TraceContext(bin2hex(random_bytes(16)), bin2hex(random_bytes(8)));

        $span = new Span($name, $context, $kind, $this->clock);

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        $this->spans[] = $span;

        return $span;
    }
}
