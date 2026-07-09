<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Telemetry\NullSpan;
use Rasuvaeff\Yii3Telemetry\Span;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\Tests\Support\QueueClock;
use Rasuvaeff\Yii3Telemetry\TraceContext;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Span::class)]
#[Covers(NullSpan::class)]
final class SpanTest
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const string SPAN_ID = 'b7ad6b7169203331';

    public function recordsMutationsWhileActive(): void
    {
        $span = $this->span();

        Assert::true($span->isRecording());
        Assert::same($span->getName(), 'checkout');
        Assert::same($span->getKind(), TraceKind::Server);
        Assert::null($span->getDurationNanos());

        $exception = new \RuntimeException('boom');
        $span->setAttribute('http.status', 200);
        $span->updateName('checkout.renamed');
        $span->setStatus(SpanStatusCode::Error, 'bad');
        $span->recordException($exception);

        Assert::same($span->getName(), 'checkout.renamed');
        Assert::same($span->getAttributes(), ['http.status' => 200]);
        Assert::same($span->getStatus()->code, SpanStatusCode::Error);
        Assert::same($span->getStatus()->description, 'bad');
        Assert::same($span->getRecordedExceptions(), [$exception]);
        Assert::same($span->getTraceContext()->traceId, self::TRACE_ID);
    }

    public function startsUnsetAndBecomesNonRecordingOnEnd(): void
    {
        $span = $this->span();

        Assert::same($span->getStatus()->code, SpanStatusCode::Unset);

        $span->end();

        Assert::false($span->isRecording());
        Assert::true($span->hasEnded());
    }

    public function startWallNanosDerivesFromClockNow(): void
    {
        $clock = new QueueClock(new \DateTimeImmutable('@1'), 5, 9);
        $span = new Span('op', $this->context(), TraceKind::Internal, $clock);

        Assert::same($span->getStartWallNanos(), 1_000_000_000);
    }

    public function endIsIdempotent(): void
    {
        $clock = new QueueClock(new \DateTimeImmutable('@0'), 10, 30, 999);
        $span = new Span('op', $this->context(), TraceKind::Internal, $clock);

        $span->end();
        $span->end();

        Assert::same($span->getDurationNanos(), 20);
    }

    public function mutatorsAreNoOpAfterEnd(): void
    {
        $span = $this->span();
        $span->setAttribute('a', 1);
        $span->setStatus(SpanStatusCode::Ok);
        $span->updateName('active');
        $span->end();

        $span->setAttribute('a', 2);
        $span->setAttribute('b', 3);
        $span->updateName('after');
        $span->setStatus(SpanStatusCode::Error);
        $span->recordException(new \RuntimeException('late'));

        Assert::same($span->getAttributes(), ['a' => 1]);
        Assert::same($span->getName(), 'active');
        Assert::same($span->getStatus()->code, SpanStatusCode::Ok);
        Assert::count($span->getRecordedExceptions(), 0);
    }

    public function nullSpanIsAnInertSingleton(): void
    {
        $span = NullSpan::instance();

        Assert::same(NullSpan::instance(), $span);
        Assert::false($span->isRecording());

        $span->setAttribute('a', 1);
        $span->updateName('x');
        $span->setStatus(SpanStatusCode::Error, 'e');
        $span->recordException(new \RuntimeException('x'));
        $span->end();

        Assert::false($span->isRecording());
        Assert::false($span->getTraceContext()->isValid());
    }

    #[Property(runs: 200)]
    public function durationEqualsMonotonicDelta(int $start, int $delta): void
    {
        $clock = new QueueClock(new \DateTimeImmutable('@0'), $start, $start + $delta);
        $span = new Span('op', $this->context(), TraceKind::Internal, $clock);
        $span->end();

        Assert::same($span->getDurationNanos(), $delta);
        Assert::true($span->getDurationNanos() >= 0);
    }

    /** @return array<string, ArbitraryInterface> */
    private function durationEqualsMonotonicDeltaGenerators(): array
    {
        return [
            'start' => Gen::intBetween(0, 1_000_000_000),
            'delta' => Gen::intBetween(0, 1_000_000),
        ];
    }

    #[Property(runs: 200)]
    public function attributeRoundTrips(string $key, string $value): void
    {
        $span = $this->span();
        $span->setAttribute($key, $value);

        Assert::same($span->getAttributes()[$key], $value);
    }

    /** @return array<string, ArbitraryInterface> */
    private function attributeRoundTripsGenerators(): array
    {
        return [
            'key' => Gen::stringAscii(),
            'value' => Gen::stringAscii(),
        ];
    }

    private function span(): Span
    {
        return new Span('checkout', $this->context(), TraceKind::Server, new QueueClock(new \DateTimeImmutable('@0'), 1, 2));
    }

    private function context(): TraceContext
    {
        return new TraceContext(self::TRACE_ID, self::SPAN_ID);
    }
}
