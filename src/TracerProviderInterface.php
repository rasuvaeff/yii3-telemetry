<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

/**
 * Supplies the active {@see TracerInterface}. Exactly one binding owns this
 * interface — a backend (e.g. `yii3-telemetry-otel`) or the application
 * (config-only `NullTracerProvider`). The core never binds it.
 *
 * @api
 */
interface TracerProviderInterface
{
    public function getTracer(?string $name = null): TracerInterface;
}
