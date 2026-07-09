<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

/**
 * Default {@see ClockInterface} backed by PHP's system clocks. 64-bit only:
 * epoch nanoseconds overflow `PHP_INT_MAX` on 32-bit builds.
 *
 * @api
 */
final readonly class SystemClock implements ClockInterface
{
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }

    #[\Override]
    public function monotonicNanos(): int
    {
        return hrtime(true);
    }
}
