<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Benchmarks;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Telemetry\TraceContext;
use Rasuvaeff\Yii3Telemetry\TraceContextPropagator;
use Testo\Bench;

final class TraceContextPropagatorBench
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const string SPAN_ID = 'b7ad6b7169203331';
    private const string TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    private static ?\Psr\Http\Message\ServerRequestInterface $incomingRequest = null;
    private static ?\Psr\Http\Message\RequestInterface $outgoingRequest = null;
    private static ?TraceContext $context = null;
    private static ?TraceContextPropagator $propagator = null;

    #[Bench(
        callables: [
            // Keep the comparison within extraction. The benchmark harness
            // always reports `current`; it must not compare extraction with
            // injection, which has different work and allocations.
            'repeat' => [self::class, 'extract'],
        ],
        calls: 5_000,
        iterations: 10,
        tolerance: \INF,
    )]
    public static function extract(): TraceContext
    {
        self::$incomingRequest ??= (new Psr17Factory())
            ->createServerRequest('GET', '/')
            ->withHeader('traceparent', self::TRACEPARENT);

        self::$propagator ??= new TraceContextPropagator();

        return self::$propagator->extract(self::$incomingRequest);
    }

    #[Bench(
        callables: [
            'repeat' => [self::class, 'inject'],
        ],
        calls: 5_000,
        iterations: 10,
        tolerance: \INF,
    )]
    public static function inject(): string
    {
        self::$outgoingRequest ??= (new Psr17Factory())->createRequest('GET', 'https://api.example');
        self::$context ??= new TraceContext(self::TRACE_ID, self::SPAN_ID, 1);

        self::$propagator ??= new TraceContextPropagator();

        return self::$propagator->inject(self::$context, self::$outgoingRequest)->getHeaderLine('traceparent');
    }
}
