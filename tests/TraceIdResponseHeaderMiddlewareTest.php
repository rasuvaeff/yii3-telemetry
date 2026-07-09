<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\Yii3Telemetry\Exception\InvalidArgumentException;
use Rasuvaeff\Yii3Telemetry\LogTracer;
use Rasuvaeff\Yii3Telemetry\NullTracer;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\TraceIdResponseHeaderMiddleware;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(TraceIdResponseHeaderMiddleware::class)]
final class TraceIdResponseHeaderMiddlewareTest
{
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function setsTraceIdHeaderInsideActiveTrace(): void
    {
        $tracer = new LogTracer(new NullLogger());
        $middleware = new TraceIdResponseHeaderMiddleware($tracer);

        $response = $tracer->trace(
            'root',
            fn(SpanInterface $span): ResponseInterface => $middleware
                ->process($this->factory->createServerRequest('GET', 'https://x/a'), $this->handler())
                ->withAddedHeader('X-Expected', $span->getTraceContext()->traceId),
        );

        Assert::same($response->getHeaderLine('X-Trace-Id'), $response->getHeaderLine('X-Expected'));
        Assert::true($response->getHeaderLine('X-Trace-Id') !== '');
    }

    public function leavesResponseUntouchedWithoutActiveTrace(): void
    {
        $middleware = new TraceIdResponseHeaderMiddleware(NullTracer::instance());

        $response = $middleware->process($this->factory->createServerRequest('GET', 'https://x/a'), $this->handler());

        Assert::false($response->hasHeader('X-Trace-Id'));
    }

    public function usesConfiguredHeaderName(): void
    {
        $tracer = new LogTracer(new NullLogger());
        $middleware = new TraceIdResponseHeaderMiddleware($tracer, headerName: 'Trace-Ref');

        $response = $tracer->trace(
            'root',
            fn(): ResponseInterface => $middleware->process(
                $this->factory->createServerRequest('GET', 'https://x/a'),
                $this->handler(),
            ),
        );

        Assert::true($response->hasHeader('Trace-Ref'));
        Assert::false($response->hasHeader('X-Trace-Id'));
    }

    public function throwsOnEmptyHeaderName(): void
    {
        try {
            new TraceIdResponseHeaderMiddleware(NullTracer::instance(), headerName: '');
            Assert::fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Header name');
        }
    }

    private function handler(): RequestHandlerInterface
    {
        return new readonly class ($this->factory) implements RequestHandlerInterface {
            public function __construct(
                private Psr17Factory $factory,
            ) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };
    }
}
