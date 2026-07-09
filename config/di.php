<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Telemetry\Tracer;
use Rasuvaeff\Yii3Telemetry\TracerInterface;

// The core binds ONLY the facade. `TracerProviderInterface` is the swappable
// key and is owned by exactly one source — a backend (yii3-telemetry-otel) or
// the application (`TracerProviderInterface => NullTracerProvider`). Binding it
// here would collide with a backend (`yiisoft/config` "Duplicate key").
return [
    TracerInterface::class => Tracer::class,
    Tracer::class => Tracer::class,
];
