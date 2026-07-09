<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Psr\Clock\ClockInterface as PsrClockInterface;
use Rasuvaeff\Yii3Telemetry\SystemClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(SystemClock::class)]
final class ClockTest
{
    private SystemClock $clock;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new SystemClock();
    }

    public function nowReturnsWallClockInstant(): void
    {
        Assert::instanceOf($this->clock->now(), \DateTimeImmutable::class);
    }

    public function isPsr20Compatible(): void
    {
        Assert::instanceOf($this->clock, PsrClockInterface::class);
    }

    public function monotonicIsPositive(): void
    {
        Assert::true($this->clock->monotonicNanos() > 0);
    }

    public function monotonicNeverRunsBackwards(): void
    {
        $first = $this->clock->monotonicNanos();
        $second = $this->clock->monotonicNanos();

        Assert::true($second >= $first);
    }
}
