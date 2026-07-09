<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Rasuvaeff\Yii3Telemetry\LogTracer;
use Rasuvaeff\Yii3Telemetry\NullTracer;
use Rasuvaeff\Yii3Telemetry\NullTracerProvider;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\Tests\Support\QueueClock;
use Rasuvaeff\Yii3Telemetry\Tests\Support\RecordingLogger;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Rasuvaeff\Yii3Telemetry\Tracer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Tracer::class)]
#[Covers(NullTracer::class)]
#[Covers(NullTracerProvider::class)]
#[Covers(LogTracer::class)]
final class TracerTest
{
    public function nullTracerRunsCallbackWithNonRecordingSpan(): void
    {
        $tracer = NullTracer::instance();
        $captured = null;

        $result = $tracer->trace('op', static function (SpanInterface $span) use (&$captured): int {
            $captured = $span;

            return 42;
        });

        Assert::same($result, 42);
        Assert::instanceOf($captured, SpanInterface::class);
        Assert::false($captured->isRecording());
        Assert::false($tracer->currentSpan()->isRecording());
        Assert::false($tracer->getContext()->isValid());
    }

    public function nullTracerRethrowsCallbackException(): void
    {
        try {
            NullTracer::instance()->trace('op', static function (): void {
                throw new \RuntimeException('boom');
            });
            Assert::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'boom');
        }
    }

    public function nullProviderYieldsNullTracer(): void
    {
        Assert::instanceOf((new NullTracerProvider())->getTracer(), NullTracer::class);
        Assert::instanceOf((new NullTracerProvider())->getTracer('named'), NullTracer::class);
    }

    public function facadeDelegatesToProviderTracer(): void
    {
        $tracer = new Tracer(new NullTracerProvider());

        Assert::same($tracer->trace('op', static fn(): int => 7), 7);
        Assert::false($tracer->currentSpan()->isRecording());
        Assert::false($tracer->getContext()->isValid());
    }

    public function logTracerLogsFinishedSpanAndReturnsValue(): void
    {
        $logger = new RecordingLogger();
        $tracer = new LogTracer($logger, new QueueClock(new \DateTimeImmutable('@0'), 1, 2));

        $result = $tracer->trace(
            'checkout',
            static function (SpanInterface $span): string {
                $span->setAttribute('user', 'u1');

                return 'done';
            },
            ['env' => 'test'],
        );

        Assert::same($result, 'done');
        Assert::count($logger->records, 1);

        $record = $logger->records[0];
        Assert::same($record['message'], 'span checkout');
        Assert::same($record['context']['status'], 'Unset');
        Assert::same($record['context']['duration_ns'], 1);
        Assert::same($record['context']['attributes'], ['env' => 'test', 'user' => 'u1']);
        Assert::same($record['context']['kind'], 'Internal');
    }

    public function logTracerRecordsExceptionAndRethrows(): void
    {
        $logger = new RecordingLogger();
        $tracer = new LogTracer($logger, new QueueClock(new \DateTimeImmutable('@0'), 1, 2));

        try {
            $tracer->trace('op', static function (): void {
                throw new \LogicException('nope');
            });
            Assert::fail('expected a LogicException');
        } catch (\LogicException $e) {
            Assert::same($e->getMessage(), 'nope');
        }

        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['context']['status'], 'Error');
        Assert::same($logger->records[0]['context']['exceptions'], ['LogicException: nope']);
    }

    public function scopedSpanIsCurrentDuringCallbackOnly(): void
    {
        $tracer = new LogTracer(new RecordingLogger(), new QueueClock(new \DateTimeImmutable('@0'), 1, 2));

        Assert::false($tracer->currentSpan()->isRecording());

        $tracer->trace('op', static function (SpanInterface $span) use ($tracer): void {
            Assert::same($tracer->currentSpan(), $span);
            Assert::true($tracer->currentSpan()->isRecording());
        });

        Assert::false($tracer->currentSpan()->isRecording());
    }

    public function unscopedSpanIsNotCurrent(): void
    {
        $tracer = new LogTracer(new RecordingLogger(), new QueueClock(new \DateTimeImmutable('@0'), 1, 2));

        $tracer->trace('op', static function (SpanInterface $span) use ($tracer): void {
            Assert::notSame($tracer->currentSpan(), $span);
            Assert::false($tracer->currentSpan()->isRecording());
        }, scoped: false);
    }

    public function nestedSpanInheritsParentTraceId(): void
    {
        $tracer = new LogTracer(new RecordingLogger(), new QueueClock(new \DateTimeImmutable('@0'), 1, 2, 3, 4));

        $parentTraceId = null;
        $childTraceId = null;
        $parentSpanId = null;
        $childSpanId = null;

        $tracer->trace('parent', static function (SpanInterface $parent) use ($tracer, &$parentTraceId, &$childTraceId, &$parentSpanId, &$childSpanId): void {
            $parentTraceId = $parent->getTraceContext()->traceId;
            $parentSpanId = $parent->getTraceContext()->spanId;

            $tracer->trace('child', static function (SpanInterface $child) use (&$childTraceId, &$childSpanId): void {
                $childTraceId = $child->getTraceContext()->traceId;
                $childSpanId = $child->getTraceContext()->spanId;
            });
        });

        Assert::same($childTraceId, $parentTraceId);
        Assert::notSame($childSpanId, $parentSpanId);
    }

    public function logTracerUsesSystemClockByDefault(): void
    {
        $logger = new RecordingLogger();
        $tracer = new LogTracer($logger);

        $tracer->trace('op', static fn(): null => null);

        Assert::count($logger->records, 1);
        Assert::true($logger->records[0]['context']['duration_ns'] >= 0);
    }

    public function startSpanReturnsADetachedRecordingSpan(): void
    {
        $tracer = new LogTracer(new RecordingLogger(), new QueueClock(new \DateTimeImmutable('@0'), 1, 2));

        $span = $tracer->startSpan('manual', ['k' => 'v'], TraceKind::Client);

        Assert::true($span->isRecording());
        // Not activated: it does not become the current span.
        Assert::false($tracer->currentSpan()->isRecording());

        $span->end();
        Assert::false($span->isRecording());
    }

    public function nullTracerStartSpanIsNonRecording(): void
    {
        Assert::false(NullTracer::instance()->startSpan('x')->isRecording());
    }

    public function facadeStartSpanDelegatesToProviderTracer(): void
    {
        Assert::false((new Tracer(new NullTracerProvider()))->startSpan('x')->isRecording());
    }
}
