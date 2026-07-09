<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Rasuvaeff\Yii3Telemetry\Exception\InvalidArgumentException;
use Rasuvaeff\Yii3Telemetry\TraceContext;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(TraceContext::class)]
final class TraceContextTest
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const string SPAN_ID = 'b7ad6b7169203331';

    public function storesFields(): void
    {
        $context = new TraceContext(self::TRACE_ID, self::SPAN_ID, 1, 'vendor=value');

        Assert::same($context->traceId, self::TRACE_ID);
        Assert::same($context->spanId, self::SPAN_ID);
        Assert::same($context->traceFlags, 1);
        Assert::same($context->traceState, 'vendor=value');
    }

    public function defaultsToSampledAndEmptyState(): void
    {
        $context = new TraceContext(self::TRACE_ID, self::SPAN_ID);

        Assert::true($context->isSampled());
        Assert::same($context->traceState, '');
    }

    public function invalidSentinelIsNotValid(): void
    {
        $context = TraceContext::invalid();

        Assert::false($context->isValid());
        Assert::false($context->isSampled());
    }

    public function realIdsAreValid(): void
    {
        Assert::true((new TraceContext(self::TRACE_ID, self::SPAN_ID))->isValid());
    }

    #[DataProvider('sampledProvider')]
    public function sampledReadsLowBit(int $flags, bool $sampled): void
    {
        Assert::same((new TraceContext(self::TRACE_ID, self::SPAN_ID, $flags))->isSampled(), $sampled);
    }

    public static function sampledProvider(): iterable
    {
        yield 'unsampled' => [0, false];
        yield 'sampled' => [1, true];
        yield 'sampled with other bits' => [3, true];
        yield 'other bits only' => [2, false];
    }

    public function equalsComparesAllFields(): void
    {
        $a = new TraceContext(self::TRACE_ID, self::SPAN_ID, 1, 's=1');

        Assert::true($a->equals(new TraceContext(self::TRACE_ID, self::SPAN_ID, 1, 's=1')));
        Assert::false($a->equals(new TraceContext(self::TRACE_ID, 'ffffffffffffffff', 1, 's=1')));
        Assert::false($a->equals(new TraceContext(self::TRACE_ID, self::SPAN_ID, 0, 's=1')));
        Assert::false($a->equals(new TraceContext(self::TRACE_ID, self::SPAN_ID, 1, 's=2')));
    }

    #[DataProvider('invalidFormatProvider')]
    public function rejectsMalformedIdsOrFlags(string $traceId, string $spanId, int $flags, string $needle): void
    {
        try {
            new TraceContext($traceId, $spanId, $flags);
            Assert::fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains($needle);
        }
    }

    public static function invalidFormatProvider(): iterable
    {
        yield 'short trace id' => ['abc', self::SPAN_ID, 1, 'Invalid trace id'];
        yield 'uppercase trace id' => [strtoupper(self::TRACE_ID), self::SPAN_ID, 1, 'Invalid trace id'];
        yield 'short span id' => [self::TRACE_ID, 'abc', 1, 'Invalid span id'];
        yield 'non-hex span id' => [self::TRACE_ID, 'zzzzzzzzzzzzzzzz', 1, 'Invalid span id'];
        yield 'flags too high' => [self::TRACE_ID, self::SPAN_ID, 256, 'Trace flags out of range'];
        yield 'flags negative' => [self::TRACE_ID, self::SPAN_ID, -1, 'Trace flags out of range'];
    }
}
