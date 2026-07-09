<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use OpenTelemetry\API\Trace\SpanKind;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(TraceKind::class)]
final class TraceKindTest
{
    public function coversEveryOtelSpanKind(): void
    {
        Assert::count(TraceKind::cases(), 5);
    }

    #[DataProvider('kindProvider')]
    public function backingValueMatchesOtelSpanKind(TraceKind $kind, int $otelKind): void
    {
        Assert::same($kind->value, $otelKind);
    }

    public static function kindProvider(): iterable
    {
        yield 'internal' => [TraceKind::Internal, SpanKind::KIND_INTERNAL];
        yield 'client' => [TraceKind::Client, SpanKind::KIND_CLIENT];
        yield 'server' => [TraceKind::Server, SpanKind::KIND_SERVER];
        yield 'producer' => [TraceKind::Producer, SpanKind::KIND_PRODUCER];
        yield 'consumer' => [TraceKind::Consumer, SpanKind::KIND_CONSUMER];
    }
}
