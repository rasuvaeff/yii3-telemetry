<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use OpenTelemetry\API\Trace\Span as OtelSpan;
use Rasuvaeff\Yii3Telemetry\ServerTracing;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\Tests\Support\RecordingTracer;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ServerTracing::class)]
final class ServerTracingTest
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const string SPAN_ID = 'b7ad6b7169203331';
    private const string TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    private RecordingTracer $tracer;
    private ServerTracing $server;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tracer = new RecordingTracer();
        $this->server = new ServerTracing($this->tracer);
    }

    public function serveOpensAServerSpanParentedToTheCaller(): void
    {
        $parent = null;

        $result = $this->server->serve(
            ['traceparent' => self::TRACEPARENT, 'tracestate' => 'vendor=1'],
            'grpc tickets.hold',
            static function (SpanInterface $span) use (&$parent): string {
                $parent = OtelSpan::getCurrent()->getContext();

                return 'held';
            },
        );

        Assert::same($result, 'held');
        Assert::notNull($parent);
        Assert::same($parent->getTraceId(), self::TRACE_ID);
        Assert::same($parent->getSpanId(), self::SPAN_ID);
        Assert::true($parent->isRemote());
        Assert::same($parent->getTraceState()?->get('vendor'), '1');

        $span = $this->tracer->spans[0];
        Assert::same($span->getName(), 'grpc tickets.hold');
        Assert::same($span->getKind(), TraceKind::Server);
        Assert::true($span->hasEnded());
    }

    public function serveCarriesOnlyTheCallerAttributes(): void
    {
        $this->server->serve([], 'grpc tickets.hold', static fn(): null => null, ['rpc.system' => 'grpc']);

        Assert::same($this->tracer->spans[0]->getAttributes(), ['rpc.system' => 'grpc']);

        $this->server->serve([], 'grpc tickets.hold', static fn(): null => null);

        Assert::same($this->tracer->spans[1]->getAttributes(), []);
    }

    public function serveDefaultsTheKindToServer(): void
    {
        $this->server->serve([], 'grpc tickets.hold', static fn(): null => null);

        Assert::same($this->tracer->spans[0]->getKind(), TraceKind::Server);
    }

    public function serveHonoursAnExplicitKind(): void
    {
        $this->server->serve([], 'interceptor', static fn(): null => null, kind: TraceKind::Consumer);

        Assert::same($this->tracer->spans[0]->getKind(), TraceKind::Consumer);
    }

    public function serveWithoutHeadersIsAFreshRootEvenInsideAnActiveSpan(): void
    {
        $inside = null;
        $outer = OtelSpan::wrap(
            \OpenTelemetry\API\Trace\SpanContext::create(self::TRACE_ID, self::SPAN_ID),
        );
        $scope = $outer->activate();

        try {
            $this->server->serve(
                ['unrelated' => 'x'],
                'grpc tickets.hold',
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
    }

    public function aCallWithoutHeadersAfterOneWithThemStartsAFreshRootTrace(): void
    {
        $firstInsideContext = null;
        $secondInsideContext = null;

        $this->server->serve(
            ['traceparent' => self::TRACEPARENT],
            'grpc tickets.hold',
            static function () use (&$firstInsideContext): void {
                $firstInsideContext = OtelSpan::getCurrent()->getContext();
            },
        );

        // The activated remote parent of the first call is detached afterwards.
        Assert::false(OtelSpan::getCurrent()->getContext()->isValid());

        $this->server->serve(
            [],
            'grpc tickets.hold',
            static function () use (&$secondInsideContext): void {
                $secondInsideContext = OtelSpan::getCurrent()->getContext();
            },
        );

        Assert::notNull($firstInsideContext);
        Assert::same($firstInsideContext->getTraceId(), self::TRACE_ID);
        Assert::notNull($secondInsideContext);
        Assert::false($secondInsideContext->isValid());
        Assert::true($this->tracer->spans[1]->getTraceContext()->traceId !== self::TRACE_ID);
        Assert::true($this->tracer->spans[1]->hasEnded());
    }

    #[DataProvider('headersWithoutAValidTraceparentProvider')]
    public function serveWithoutAValidTraceparentIsAFreshRoot(array $headers): void
    {
        $inside = null;

        $this->server->serve(
            $headers,
            'grpc tickets.hold',
            static function () use (&$inside): void {
                $inside = OtelSpan::getCurrent()->getContext();
            },
        );

        Assert::notNull($inside);
        Assert::false($inside->isValid());
    }

    public static function headersWithoutAValidTraceparentProvider(): iterable
    {
        yield 'empty headers' => [[]];
        yield 'unrelated keys only' => [['unrelated' => 'x']];
        yield 'malformed traceparent' => [['traceparent' => 'garbage']];
        yield 'too few parts' => [['traceparent' => '00-0af7651916cd43dd8448eb211c80319c']];
        yield 'invalid version ff' => [['traceparent' => 'ff-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']];
        yield 'all-zero ids' => [['traceparent' => '00-00000000000000000000000000000000-0000000000000000-01']];
    }

    public function serveRestoresTheOuterContextAfterwards(): void
    {
        $before = OtelSpan::getCurrent()->getContext()->getSpanId();

        $this->server->serve(
            ['traceparent' => self::TRACEPARENT],
            'grpc tickets.hold',
            static fn(): null => null,
        );

        Assert::same(OtelSpan::getCurrent()->getContext()->getSpanId(), $before);
    }

    public function serveRestoresTheOuterContextWhenTheHandlerThrows(): void
    {
        $before = OtelSpan::getCurrent()->getContext()->getSpanId();

        try {
            $this->server->serve(['traceparent' => self::TRACEPARENT], 'grpc tickets.hold', static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        Assert::same(OtelSpan::getCurrent()->getContext()->getSpanId(), $before);
    }

    public function serveRecordsTheExceptionSetsErrorEndsTheSpanAndRethrows(): void
    {
        try {
            $this->server->serve([], 'grpc tickets.hold', static function (): void {
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

    public function everyCallGetsItsOwnEndedSpan(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->server->serve([], 'grpc tickets.hold', static fn(): int => $i);
        }

        Assert::count($this->tracer->spans, 3);

        foreach ($this->tracer->spans as $span) {
            Assert::true($span->hasEnded());
        }

        Assert::false($this->tracer->currentSpan()->isRecording());
    }

    public function nonStringMetadataNeverBreaksHeaderExtraction(): void
    {
        $inside = null;

        $this->server->serve(
            ['traceparent' => self::TRACEPARENT, 'deadline' => 120, 'peer' => ['s' => 'b']],
            'grpc tickets.hold',
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

        $this->server->serve(
            ['Traceparent' => self::TRACEPARENT],
            'grpc tickets.hold',
            static function () use (&$inside): void {
                $inside = OtelSpan::getCurrent()->getContext();
            },
        );

        Assert::notNull($inside);
        Assert::same($inside->getTraceId(), self::TRACE_ID);
    }

    #[DataProvider('remoteParentProvider')]
    public function traceFlagsOfTheRemoteParentAreKept(string $flags, int $expectedFlags): void
    {
        $parent = null;

        $this->server->serve(
            ['traceparent' => \sprintf('00-%s-%s-%s', self::TRACE_ID, self::SPAN_ID, $flags)],
            'grpc tickets.hold',
            static function () use (&$parent): void {
                $parent = OtelSpan::getCurrent()->getContext();
            },
        );

        Assert::notNull($parent);
        Assert::same($parent->getTraceFlags(), $expectedFlags);
    }

    public static function remoteParentProvider(): iterable
    {
        yield 'sampled' => ['01', 1];
        yield 'unsampled' => ['00', 0];
        yield 'highest flag byte' => ['ff', 255];
    }
}
