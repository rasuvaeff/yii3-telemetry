<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

/**
 * Config-only default provider. Bind `TracerProviderInterface => NullTracerProvider`
 * in the application when no tracing backend is installed, and the {@see Tracer}
 * facade becomes a fully no-op tracer.
 *
 * @api
 */
final readonly class NullTracerProvider implements TracerProviderInterface
{
    #[\Override]
    public function getTracer(?string $name = null): TracerInterface
    {
        return NullTracer::instance();
    }
}
