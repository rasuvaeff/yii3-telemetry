<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Telemetry\Exception\InvalidArgumentException;
use Rasuvaeff\Yii3Telemetry\NullSpan;
use Rasuvaeff\Yii3Telemetry\Span;
use Rasuvaeff\Yii3Telemetry\SpanEvent;
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
#[Covers(SpanEvent::class)]
#[Covers(InvalidArgumentException::class)]
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
        $span->addEvent('retry', ['attempt' => 1]);
        $span->recordException(new \RuntimeException('x'));
        $span->end();

        Assert::false($span->isRecording());
        Assert::false($span->getTraceContext()->isValid());
    }

    public function addEventRecordsTimestampedEvent(): void
    {
        $clock = new QueueClock(new \DateTimeImmutable('@3'), 1, 2);
        $span = new Span('op', $this->context(), TraceKind::Internal, $clock);

        $span->addEvent('retry', ['attempt' => 2]);

        Assert::count($span->getEvents(), 1);
        Assert::same($span->getEvents()[0]->name, 'retry');
        Assert::same($span->getEvents()[0]->attributes, ['attempt' => 2]);
        Assert::same($span->getEvents()[0]->wallNanos, 3_000_000_000);
    }

    public function addEventIsNoOpAfterEnd(): void
    {
        $span = $this->span();
        $span->addEvent('before');
        $span->end();

        $span->addEvent('after');

        Assert::count($span->getEvents(), 1);
        Assert::same($span->getEvents()[0]->name, 'before');
    }

    public function explicitStartNanosBackdatesAndUsesWallClockDuration(): void
    {
        $clock = new QueueClock(new \DateTimeImmutable('@2'));
        $span = new Span('op', $this->context(), TraceKind::Internal, $clock, startNanos: 500_000_000);

        Assert::same($span->getStartWallNanos(), 500_000_000);

        $span->end();

        Assert::same($span->getDurationNanos(), 1_500_000_000);
    }

    public function negativeStartNanosThrows(): void
    {
        try {
            new Span('op', $this->context(), TraceKind::Internal, new QueueClock(new \DateTimeImmutable('@0')), startNanos: -1);
            Assert::fail('expected an InvalidArgumentException');
        } catch (\Rasuvaeff\Yii3Telemetry\Exception\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('non-negative');
        }
    }

    public function zeroStartNanosIsAccepted(): void
    {
        $span = new Span('op', $this->context(), TraceKind::Internal, new QueueClock(new \DateTimeImmutable('@0')), startNanos: 0);

        Assert::same($span->getStartWallNanos(), 0);
    }

    public function explicitFutureStartNanosClampsDurationToZero(): void
    {
        $clock = new QueueClock(new \DateTimeImmutable('@2'));
        $span = new Span('op', $this->context(), TraceKind::Internal, $clock, startNanos: 3_000_000_000);

        $span->end();

        Assert::same($span->getDurationNanos(), 0);
    }

    public function onEndCallbackFiresExactlyOnce(): void
    {
        $calls = 0;
        $span = new Span(
            'op',
            $this->context(),
            TraceKind::Internal,
            new QueueClock(new \DateTimeImmutable('@0'), 1, 2),
            null,
            static function (Span $ended) use (&$calls): void {
                ++$calls;
                Assert::true($ended->hasEnded());
            },
        );

        $span->end();
        $span->end();

        Assert::same($calls, 1);
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
    public static function durationEqualsMonotonicDeltaGenerators(): array
    {
        return [
            'start' => Gen::intBetween(0, 1_000_000_000),
            'delta' => Gen::intBetween(0, 1_000_000),
        ];
    }

    /** @return iterable<string, array{int, int}> */
    public static function durationEqualsMonotonicDeltaExamples(): iterable
    {
        // A span that starts and ends within the same clock tick is the case
        // an implementation reaching for wall time gets wrong.
        yield 'instant span' => [0, 0];
        yield 'instant span at a large offset' => [1_000_000_000, 0];
        yield 'one nanosecond' => [0, 1];
    }

    #[Property(runs: 200)]
    public function attributeRoundTrips(string $key, string $value): void
    {
        $span = $this->span();
        $span->setAttribute($key, $value);

        Assert::same($span->getAttributes()[$key], $value);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function attributeRoundTripsGenerators(): array
    {
        return [
            'key' => Gen::stringAscii(),
            'value' => Gen::stringAscii(),
        ];
    }

    #[Property(runs: 200)]
    public function everyAttributeOfAMapSurvivesIndependently(array $attributes): void
    {
        $span = $this->span();

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        Classify::when($attributes === [], 'no attributes');
        Classify::when(\count($attributes) > 3, 'several attributes');

        // One key at a time already round-trips; what this adds is that
        // writing the next one does not disturb the ones already there.
        Assert::same($span->getAttributes(), $attributes);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function everyAttributeOfAMapSurvivesIndependentlyGenerators(): array
    {
        return [
            // Attributes are a string-keyed map of scalars in every exporter
            // this feeds; a dictionary generator says that directly instead of
            // rebuilding one from two lists.
            'attributes' => Gen::dictOf(
                Gen::stringFrom('abcdefghijklmnopqrstuvwxyz._', minLength: 1, maxLength: 12),
                // frequency, not oneOf: oneOf picks among literal values,
                // while the value type here is a union and each arm needs its
                // own generator.
                Gen::frequency([
                    [1, Gen::stringAscii()],
                    [1, Gen::int()],
                    [1, Gen::bool()],
                ]),
                maxSize: 8,
            ),
        ];
    }

    #[Property(runs: 200)]
    public function anEndedSpanAcceptsNoFurtherAttributes(string $key, string $before, string $after): void
    {
        $span = $this->span();
        $span->setAttribute($key, $before);
        $span->end();
        $span->setAttribute($key, $after);

        Classify::when($before === $after, 'indistinguishable write');

        // Attributes written after end() would reach an exporter that has
        // already shipped the span, so the write is dropped rather than
        // silently changing a record that is gone.
        Assert::same($span->getAttributes()[$key], $before);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function anEndedSpanAcceptsNoFurtherAttributesGenerators(): array
    {
        return [
            'key' => Gen::stringFrom('abcdefghijklmnopqrstuvwxyz.', minLength: 1, maxLength: 12),
            'before' => Gen::stringAscii(),
            'after' => Gen::stringAscii(),
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
