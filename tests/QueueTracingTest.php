<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use OpenTelemetry\API\Trace\Span as OtelSpan;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Telemetry\QueueTracing;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\Tests\Support\QueueClock;
use Rasuvaeff\Yii3Telemetry\Tests\Support\RecordingTracer;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(QueueTracing::class)]
final class QueueTracingTest
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const string SPAN_ID = 'b7ad6b7169203331';
    private const string TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    // 2026-10-03T12:00:00.250000Z
    private const int NOW_NANOS = 1_791_028_800_250_000_000;

    private RecordingTracer $tracer;
    private QueueTracing $queue;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tracer = new RecordingTracer();
        $this->queue = new QueueTracing(
            $this->tracer,
            clock: new QueueClock(new \DateTimeImmutable('2026-10-03T12:00:00.250000Z')),
        );
    }

    public function injectAddsTheCurrentContextAndTheEnqueueTimestamp(): void
    {
        $metadata = $this->tracer->trace('push', fn(SpanInterface $span): array => [
            $span->getTraceContext(),
            $this->queue->inject(['custom' => 'kept'], enqueuedAtNanos: 123),
        ]);

        [$context, $injected] = $metadata;

        Assert::same($injected['custom'], 'kept');
        Assert::same($injected[QueueTracing::ENQUEUED_AT_KEY], 123);
        Assert::same(
            $injected['traceparent'],
            \sprintf('00-%s-%s-%02x', $context->traceId, $context->spanId, $context->traceFlags),
        );
        Assert::false(isset($injected[QueueTracing::AVAILABLE_AT_KEY]));
    }

    public function injectDefaultsTheEnqueueTimeToNow(): void
    {
        $injected = $this->queue->inject([]);

        Assert::same($injected[QueueTracing::ENQUEUED_AT_KEY], self::NOW_NANOS);
    }

    public function injectWithoutAnActiveSpanAddsOnlyTheTimestamp(): void
    {
        $injected = $this->queue->inject(['a' => 1], 5);

        Assert::same($injected, ['a' => 1, QueueTracing::ENQUEUED_AT_KEY => 5]);
    }

    public function injectRecordsTheAvailableTimeForADelayedMessage(): void
    {
        $injected = $this->queue->inject([], 5, availableAtNanos: 900);

        Assert::same($injected[QueueTracing::AVAILABLE_AT_KEY], 900);
    }

    public function injectDropsAStaleAvailableTimeOnRepush(): void
    {
        $injected = $this->queue->inject([QueueTracing::AVAILABLE_AT_KEY => 900], 5);

        Assert::false(isset($injected[QueueTracing::AVAILABLE_AT_KEY]));
    }

    public function injectReplacesAStaleTraceparentWhenAContextIsActive(): void
    {
        $injected = $this->tracer->trace(
            'push',
            fn(SpanInterface $span): array => $this->queue->inject(['traceparent' => self::TRACEPARENT]),
        );

        Assert::true($injected['traceparent'] !== self::TRACEPARENT);
    }

    public function consumeOpensAConsumerSpanParentedToTheProducer(): void
    {
        $parent = null;

        $result = $this->queue->consume(
            [
                'traceparent' => self::TRACEPARENT,
                'tracestate' => 'vendor=1',
                QueueTracing::ENQUEUED_AT_KEY => self::NOW_NANOS - 2_000_000_000,
            ],
            'process email.send',
            static function (SpanInterface $span) use (&$parent): string {
                $parent = OtelSpan::getCurrent()->getContext();

                return 'handled';
            },
        );

        Assert::same($result, 'handled');
        Assert::notNull($parent);
        Assert::same($parent->getTraceId(), self::TRACE_ID);
        Assert::same($parent->getSpanId(), self::SPAN_ID);
        Assert::true($parent->isRemote());
        Assert::same($parent->getTraceState()?->get('vendor'), '1');

        $span = $this->tracer->spans[0];
        Assert::same($span->getName(), 'process email.send');
        Assert::same($span->getKind(), TraceKind::Consumer);
        Assert::true($span->hasEnded());
        Assert::same($span->getAttributes()['messaging.operation.type'], 'process');
        Assert::same($span->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE], 2_000_000_000);
    }

    public function consumeWithoutHeadersIsAFreshRootEvenInsideAnActiveSpan(): void
    {
        $inside = null;
        $outer = OtelSpan::wrap(
            \OpenTelemetry\API\Trace\SpanContext::create(self::TRACE_ID, self::SPAN_ID),
        );
        $scope = $outer->activate();

        try {
            $this->queue->consume(
                ['unrelated' => 'x'],
                'process job',
                static function () use (&$inside): void {
                    $inside = OtelSpan::getCurrent()->getContext();
                },
            );

            Assert::same(OtelSpan::getCurrent()->getContext()->getSpanId(), self::SPAN_ID);
        } finally {
            $scope->detach();
        }

        Assert::notNull($inside);
        Assert::false($inside->isValid());
        Assert::false(isset($this->tracer->spans[0]->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE]));
    }

    public function consumeTreatsAMalformedTraceparentAsNoParent(): void
    {
        $inside = null;

        $this->queue->consume(
            ['traceparent' => 'garbage'],
            'process job',
            static function () use (&$inside): void {
                $inside = OtelSpan::getCurrent()->getContext();
            },
        );

        Assert::notNull($inside);
        Assert::false($inside->isValid());
    }

    public function consumeRestoresTheOuterContextAfterwards(): void
    {
        $before = OtelSpan::getCurrent()->getContext()->getSpanId();

        $this->queue->consume(['traceparent' => self::TRACEPARENT], 'process job', static fn(): null => null);

        Assert::same(OtelSpan::getCurrent()->getContext()->getSpanId(), $before);
    }

    public function consumeRestoresTheOuterContextWhenTheHandlerThrows(): void
    {
        $before = OtelSpan::getCurrent()->getContext()->getSpanId();

        try {
            $this->queue->consume(['traceparent' => self::TRACEPARENT], 'process job', static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        Assert::same(OtelSpan::getCurrent()->getContext()->getSpanId(), $before);
    }

    public function consumeRecordsTheExceptionSetsErrorEndsTheSpanAndRethrows(): void
    {
        try {
            $this->queue->consume([], 'process job', static function (): void {
                throw new \RuntimeException('boom');
            });
            Assert::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'boom');
        }

        $span = $this->tracer->spans[0];
        Assert::true($span->hasEnded());
        Assert::same($span->getStatus()->code, SpanStatusCode::Error);
        Assert::count($span->getRecordedExceptions(), 1);
    }

    public function everyMessageGetsItsOwnEndedSpan(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->queue->consume([], 'process job', static fn(): int => $i);
        }

        Assert::count($this->tracer->spans, 3);

        foreach ($this->tracer->spans as $span) {
            Assert::true($span->hasEnded());
        }

        Assert::false($this->tracer->currentSpan()->isRecording());
    }

    public function delayedMessageWaitStartsAtTheScheduledTime(): void
    {
        $this->queue->consume(
            [
                QueueTracing::ENQUEUED_AT_KEY => self::NOW_NANOS - 60_000_000_000,
                QueueTracing::AVAILABLE_AT_KEY => self::NOW_NANOS - 1_500_000_000,
            ],
            'process job',
            static fn(): null => null,
        );

        Assert::same($this->tracer->spans[0]->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE], 1_500_000_000);
    }

    public function anEarlierScheduledTimeDoesNotShortenTheWaitFromEnqueue(): void
    {
        $this->queue->consume(
            [
                QueueTracing::ENQUEUED_AT_KEY => self::NOW_NANOS - 1_000,
                QueueTracing::AVAILABLE_AT_KEY => self::NOW_NANOS - 9_000,
            ],
            'process job',
            static fn(): null => null,
        );

        Assert::same($this->tracer->spans[0]->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE], 1_000);
    }

    public function availableTimeAloneStartsTheWait(): void
    {
        $this->queue->consume(
            [QueueTracing::AVAILABLE_AT_KEY => self::NOW_NANOS - 700],
            'process job',
            static fn(): null => null,
        );

        Assert::same($this->tracer->spans[0]->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE], 700);
    }

    public function aZeroEnqueueTimeIsAValidTimestamp(): void
    {
        $this->queue->consume([QueueTracing::ENQUEUED_AT_KEY => 0], 'process job', static fn(): null => null);
        $this->queue->consume([QueueTracing::ENQUEUED_AT_KEY => '0'], 'process job', static fn(): null => null);

        Assert::same($this->tracer->spans[0]->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE], self::NOW_NANOS);
        Assert::same($this->tracer->spans[1]->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE], self::NOW_NANOS);
    }

    public function aZeroAvailableTimeAloneIsAValidStart(): void
    {
        $this->queue->consume([QueueTracing::AVAILABLE_AT_KEY => 0], 'process job', static fn(): null => null);

        Assert::same($this->tracer->spans[0]->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE], self::NOW_NANOS);
    }

    public function aFutureEnqueueTimeClampsTheWaitToZero(): void
    {
        $this->queue->consume(
            [QueueTracing::ENQUEUED_AT_KEY => self::NOW_NANOS + 5_000],
            'process job',
            static fn(): null => null,
        );

        Assert::same($this->tracer->spans[0]->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE], 0);
    }

    public function numericStringTimestampsAreAccepted(): void
    {
        $this->queue->consume(
            [QueueTracing::ENQUEUED_AT_KEY => (string) (self::NOW_NANOS - 42)],
            'process job',
            static fn(): null => null,
        );

        Assert::same($this->tracer->spans[0]->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE], 42);
    }

    public function junkTimestampsAreIgnored(): void
    {
        foreach ([-5, 'abc', 1.5, null, [1], '12 ', '-3'] as $junk) {
            $this->queue->consume([QueueTracing::ENQUEUED_AT_KEY => $junk], 'process job', static fn(): null => null);
        }

        foreach ($this->tracer->spans as $span) {
            Assert::false(isset($span->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE]));
        }
    }

    public function callerAttributesAreAddedAndWin(): void
    {
        $this->queue->consume(
            [],
            'process job',
            static fn(): null => null,
            ['messaging.system' => 'yii-queue', 'messaging.operation.type' => 'receive', 'attempt' => 3],
        );

        $attributes = $this->tracer->spans[0]->getAttributes();
        Assert::same($attributes['messaging.system'], 'yii-queue');
        Assert::same($attributes['messaging.operation.type'], 'receive');
        Assert::same($attributes['attempt'], 3);
    }

    public function nonStringMetadataNeverBreaksHeaderExtraction(): void
    {
        $inside = null;

        $this->queue->consume(
            ['traceparent' => self::TRACEPARENT, 'attempts' => 2, 'nested' => ['a' => 'b']],
            'process job',
            static function () use (&$inside): void {
                $inside = OtelSpan::getCurrent()->getContext();
            },
        );

        Assert::notNull($inside);
        Assert::same($inside->getTraceId(), self::TRACE_ID);
    }

    public function headerNamesAreMatchedCaseInsensitively(): void
    {
        $inside = null;

        $this->queue->consume(
            ['Traceparent' => self::TRACEPARENT],
            'process job',
            static function () use (&$inside): void {
                $inside = OtelSpan::getCurrent()->getContext();
            },
        );

        Assert::notNull($inside);
        Assert::same($inside->getTraceId(), self::TRACE_ID);
    }

    #[Property(runs: 200)]
    public function queueTimeIsNeverNegativeAndEqualsNowMinusTheLatestOfEnqueueAndAvailable(
        int $enqueuedAgo,
        int $availableAgo,
    ): void {
        $queue = new QueueTracing(
            $tracer = new RecordingTracer(),
            clock: new QueueClock(new \DateTimeImmutable('2026-10-03T12:00:00.250000Z')),
        );

        $queue->consume(
            [
                QueueTracing::ENQUEUED_AT_KEY => self::NOW_NANOS - $enqueuedAgo,
                QueueTracing::AVAILABLE_AT_KEY => self::NOW_NANOS - $availableAgo,
            ],
            'process job',
            static fn(): null => null,
        );

        $wait = $tracer->spans[0]->getAttributes()[QueueTracing::QUEUE_TIME_ATTRIBUTE];

        Assert::same($wait, max(0, min($enqueuedAgo, $availableAgo)));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function queueTimeIsNeverNegativeAndEqualsNowMinusTheLatestOfEnqueueAndAvailableGenerators(): array
    {
        return [
            'enqueuedAgo' => Gen::intBetween(-1_000_000, 1_000_000_000_000),
            'availableAgo' => Gen::intBetween(-1_000_000, 1_000_000_000_000),
        ];
    }
}
