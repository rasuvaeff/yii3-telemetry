<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

/**
 * In-process recording span used by core tracers (e.g. {@see LogTracer}).
 * Backends ship their own {@see SpanInterface} wrapping a native span.
 *
 * Duration is measured with the monotonic clock; the start timestamp is taken
 * from the wall clock — the two are never mixed (see {@see ClockInterface}).
 * Exception: with an explicit `$startNanos` (a span that logically began in the
 * past, e.g. when the worker received the request) no monotonic anchor exists,
 * so the duration is wall-clock based and carries wall-clock precision.
 *
 * @api
 */
final class Span implements SpanInterface
{
    private readonly int $startWallNanos;
    private readonly ?int $startMonotonicNanos;
    private ?int $durationNanos = null;
    private bool $ended = false;
    private SpanStatus $status;

    /** @var array<string, bool|int|float|string|array|null> */
    private array $attributes = [];

    /** @var list<SpanEvent> */
    private array $events = [];

    /** @var list<\Throwable> */
    private array $recordedExceptions = [];

    /**
     * @param int|null $startNanos explicit unix-epoch wall-clock start in
     *        nanoseconds; `null` = now
     * @param (\Closure(self): void)|null $onEnd invoked exactly once when the
     *        span ends (used by {@see LogTracer} to log manually-started spans)
     */
    public function __construct(
        private string $name,
        private readonly TraceContext $traceContext,
        private readonly TraceKind $kind,
        private readonly ClockInterface $clock,
        ?int $startNanos = null,
        private readonly ?\Closure $onEnd = null,
    ) {
        if ($startNanos !== null && $startNanos < 0) {
            throw new Exception\InvalidArgumentException(\sprintf('Start nanos must be non-negative, got %d', $startNanos));
        }

        $this->startWallNanos = $startNanos ?? $this->wallNanos();
        $this->startMonotonicNanos = $startNanos === null ? $this->clock->monotonicNanos() : null;
        $this->status = SpanStatus::unset();
    }

    #[\Override]
    public function setAttribute(string $key, bool|int|float|string|array|null $value): void
    {
        if ($this->ended) {
            return;
        }

        $this->attributes[$key] = $value;
    }

    #[\Override]
    public function updateName(string $name): void
    {
        if ($this->ended) {
            return;
        }

        $this->name = $name;
    }

    #[\Override]
    public function setStatus(SpanStatusCode $code, ?string $description = null): void
    {
        if ($this->ended) {
            return;
        }

        $this->status = new SpanStatus($code, $description);
    }

    #[\Override]
    public function addEvent(string $name, array $attributes = []): void
    {
        if ($this->ended) {
            return;
        }

        $this->events[] = new SpanEvent($name, $attributes, $this->wallNanos());
    }

    #[\Override]
    public function recordException(\Throwable $exception): void
    {
        if ($this->ended) {
            return;
        }

        $this->recordedExceptions[] = $exception;
    }

    #[\Override]
    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->durationNanos = $this->startMonotonicNanos !== null
            ? $this->clock->monotonicNanos() - $this->startMonotonicNanos
            : max(0, $this->wallNanos() - $this->startWallNanos);

        if ($this->onEnd instanceof \Closure) {
            ($this->onEnd)($this);
        }
    }

    #[\Override]
    public function isRecording(): bool
    {
        return !$this->ended;
    }

    #[\Override]
    public function getTraceContext(): TraceContext
    {
        return $this->traceContext;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKind(): TraceKind
    {
        return $this->kind;
    }

    public function getStatus(): SpanStatus
    {
        return $this->status;
    }

    /**
     * @return array<string, bool|int|float|string|array|null>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return list<SpanEvent>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return list<\Throwable>
     */
    public function getRecordedExceptions(): array
    {
        return $this->recordedExceptions;
    }

    public function getStartWallNanos(): int
    {
        return $this->startWallNanos;
    }

    /**
     * Wall-clock-independent duration, available only after {@see end()}.
     */
    public function getDurationNanos(): ?int
    {
        return $this->durationNanos;
    }

    public function hasEnded(): bool
    {
        return $this->ended;
    }

    private function wallNanos(): int
    {
        return (int) $this->clock->now()->format('Uu') * 1000;
    }
}
