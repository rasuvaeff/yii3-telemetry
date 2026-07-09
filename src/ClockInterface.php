<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Psr\Clock\ClockInterface as PsrClockInterface;

/**
 * Extends PSR-20 with a monotonic reading. Two distinct clocks that MUST NOT be
 * mixed:
 *
 * - {@see now()} (PSR-20) — the wall clock, for a span's absolute start
 *   timestamp. Real precision is ~microsecond.
 * - {@see monotonicNanos()} — a monotonic counter (`hrtime`) for measuring
 *   durations. Not tied to the wall clock and never runs backwards.
 *
 * Being PSR-20-compatible, a {@see SystemClock} can also serve any consumer that
 * only needs `now()`.
 *
 * @api
 */
interface ClockInterface extends PsrClockInterface
{
    public function monotonicNanos(): int;
}
