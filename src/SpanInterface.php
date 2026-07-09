<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

/**
 * A single unit of work in a trace. Mutable during its lifetime; {@see end()}
 * freezes it. A non-recording span (dropped by sampling, or from a
 * {@see NullTracer}) silently ignores mutators and reports `isRecording() ===
 * false`.
 *
 * @api
 */
interface SpanInterface
{
    public function setAttribute(string $key, bool|int|float|string|array|null $value): void;

    public function updateName(string $name): void;

    public function setStatus(SpanStatusCode $code, ?string $description = null): void;

    public function recordException(\Throwable $exception): void;

    /**
     * Marks the span finished. Idempotent: a second call is a no-op.
     */
    public function end(): void;

    public function isRecording(): bool;

    public function getTraceContext(): TraceContext;
}
