<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests\Support;

use Rasuvaeff\Yii3Telemetry\ClockInterface;

/**
 * Deterministic {@see ClockInterface} for tests: `now()` is fixed, and
 * `monotonicNanos()` returns the queued values in order, repeating the last one
 * once the queue is drained.
 */
final class QueueClock implements ClockInterface
{
    /** @var non-empty-list<int> */
    private readonly array $monotonic;
    private int $cursor = 0;

    public function __construct(
        private readonly \DateTimeImmutable $now,
        int ...$monotonic,
    ) {
        $this->monotonic = $monotonic === [] ? [0] : $monotonic;
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    #[\Override]
    public function monotonicNanos(): int
    {
        $value = $this->monotonic[$this->cursor] ?? $this->monotonic[0];

        if ($this->cursor < \count($this->monotonic) - 1) {
            ++$this->cursor;
        }

        return $value;
    }
}
