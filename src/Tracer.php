<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

/**
 * DI-bound tracing entry point. Resolves the active {@see TracerInterface} from
 * the injected {@see TracerProviderInterface} once and delegates to it, so the
 * active-span state stays consistent across {@see trace()} and
 * {@see currentSpan()}.
 *
 * @api
 */
final readonly class Tracer implements TracerInterface
{
    private TracerInterface $tracer;

    public function __construct(TracerProviderInterface $provider)
    {
        $this->tracer = $provider->getTracer();
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
        ?int $startNanos = null,
    ): mixed {
        return $this->tracer->trace($name, $callback, $attributes, $scoped, $traceKind, $startNanos);
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
        return $this->tracer->startSpan($name, $attributes, $traceKind, $startNanos);
    }

    #[\Override]
    public function currentSpan(): SpanInterface
    {
        return $this->tracer->currentSpan();
    }

    #[\Override]
    public function getContext(): TraceContext
    {
        return $this->tracer->getContext();
    }
}
