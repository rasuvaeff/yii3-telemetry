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

    #[Bench(
        callables: [
            'inject' => [self::class, 'inject'],
        ],
        calls: 5_000,
        iterations: 10,
    )]
    public static function extract(): TraceContext
    {
        $request = (new Psr17Factory())
            ->createServerRequest('GET', '/')
            ->withHeader('traceparent', self::TRACEPARENT);

        return (new TraceContextPropagator())->extract($request);
    }

    public static function inject(): string
    {
        $request = (new Psr17Factory())->createRequest('GET', 'https://api.example');
        $context = new TraceContext(self::TRACE_ID, self::SPAN_ID, 1);

        return (new TraceContextPropagator())->inject($context, $request)->getHeaderLine('traceparent');
    }
}
