<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

/**
 * No-op tracer: runs the callback with a non-recording {@see NullSpan} and
 * returns its result. Exceptions propagate unchanged. This is the fully-dropped
 * end of the sampling contract — side effects run, nothing is recorded.
 *
 * @api
 */
final class NullTracer implements TracerInterface
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

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
    ): mixed {
        return $callback(NullSpan::instance());
    }

    #[\Override]
    public function currentSpan(): SpanInterface
    {
        return NullSpan::instance();
    }

    #[\Override]
    public function getContext(): TraceContext
    {
        return TraceContext::invalid();
    }
}
