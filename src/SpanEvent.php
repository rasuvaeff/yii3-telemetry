<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

/**
 * A timestamped point-in-time annotation on a span (mirrors an OpenTelemetry
 * span event) — e.g. "retry", "cache.miss". Recorded via
 * {@see SpanInterface::addEvent()}.
 *
 * @api
 */
final readonly class SpanEvent
{
    /**
     * @param array<string, bool|int|float|string|array|null> $attributes
     * @param int $wallNanos unix-epoch wall-clock nanoseconds of the event
     */
    public function __construct(
        public string $name,
        public array $attributes,
        public int $wallNanos,
    ) {}
}
