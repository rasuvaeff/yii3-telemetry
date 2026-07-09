<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

/**
 * Ergonomic tracing facade. A single {@see trace()} opens a span, runs the
 * callback, and closes the span — no verbose span-builder dance.
 *
 * Frozen 1.0.0 contract for {@see trace()}:
 * - callback returns a value → span ends with its current status, value returned;
 * - callback throws → `recordException()`, status {@see SpanStatusCode::Error},
 *   span ends, the original exception is re-thrown (never swallowed);
 * - `$scoped === true` → the span is {@see currentSpan()} for the callback's
 *   duration and the previous span is restored afterwards;
 * - a nested `trace()` inherits the parent's `traceId`;
 * - a dropped/disabled span still runs the callback with a non-recording span;
 *   {@see currentSpan()} then returns that non-recording span, never `null`.
 *
 * @api
 */
interface TracerInterface
{
    /**
     * @template T
     *
     * @param callable(SpanInterface): T $callback
     * @param array<string, bool|int|float|string|array|null> $attributes
     *
     * @return T
     */
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        bool $scoped = true,
        TraceKind $traceKind = TraceKind::Internal,
    ): mixed;

    /**
     * The active span, or a non-recording span when none is active. Never `null`.
     */
    public function currentSpan(): SpanInterface;

    /**
     * The active trace context, or an invalid context when none is active.
     */
    public function getContext(): TraceContext;
}
