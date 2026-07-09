<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Telemetry\TraceContext;
use Rasuvaeff\Yii3Telemetry\TraceContextPropagator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(TraceContextPropagator::class)]
final class TraceContextPropagatorTest
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const string SPAN_ID = 'b7ad6b7169203331';
    private const string TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    private Psr17Factory $factory;
    private TraceContextPropagator $propagator;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->propagator = new TraceContextPropagator();
    }

    public function extractsValidTraceparentAndState(): void
    {
        $request = $this->factory->createServerRequest('GET', '/')
            ->withHeader('traceparent', self::TRACEPARENT)
            ->withHeader('tracestate', 'vendor=value');

        $context = $this->propagator->extract($request);

        Assert::true($context->isValid());
        Assert::same($context->traceId, self::TRACE_ID);
        Assert::same($context->spanId, self::SPAN_ID);
        Assert::same($context->traceFlags, 1);
        Assert::same($context->traceState, 'vendor=value');
    }

    public function extractReturnsInvalidWhenHeaderMissing(): void
    {
        $context = $this->propagator->extract($this->factory->createServerRequest('GET', '/'));

        Assert::false($context->isValid());
    }

    #[DataProvider('malformedProvider')]
    public function extractRejectsMalformedTraceparent(string $header): void
    {
        $request = $this->factory->createServerRequest('GET', '/')->withHeader('traceparent', $header);

        Assert::false($this->propagator->extract($request)->isValid());
    }

    public static function malformedProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'too few parts' => ['00-abc-def'];
        yield 'too many parts' => ['00-a-b-c-d'];
        yield 'non-hex trace id' => ['00-zzz7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'];
        yield 'short trace id' => ['00-0af7-b7ad6b7169203331-01'];
        yield 'non-hex span id' => ['00-0af7651916cd43dd8448eb211c80319c-zzzzzzzzzzzzzzzz-01'];
        yield 'zero trace id' => ['00-00000000000000000000000000000000-b7ad6b7169203331-01'];
        yield 'zero span id' => ['00-0af7651916cd43dd8448eb211c80319c-0000000000000000-01'];
        yield 'forbidden ff version' => ['ff-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'];
        yield 'non-hex flags' => ['00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-zz'];
    }

    public function injectsTraceparentWithoutStateByDefault(): void
    {
        $request = $this->propagator->inject(
            new TraceContext(self::TRACE_ID, self::SPAN_ID, 1),
            $this->factory->createRequest('GET', 'https://api.example'),
        );

        Assert::same($request->getHeaderLine('traceparent'), self::TRACEPARENT);
        Assert::false($request->hasHeader('tracestate'));
    }

    public function injectsTracestateWhenPresent(): void
    {
        $request = $this->propagator->inject(
            new TraceContext(self::TRACE_ID, self::SPAN_ID, 1, 'vendor=value'),
            $this->factory->createRequest('GET', 'https://api.example'),
        );

        Assert::same($request->getHeaderLine('tracestate'), 'vendor=value');
    }

    public function injectFormatsFlagsAsTwoHexDigits(): void
    {
        $request = $this->propagator->inject(
            new TraceContext(self::TRACE_ID, self::SPAN_ID, 255),
            $this->factory->createRequest('GET', 'https://api.example'),
        );

        Assert::string($request->getHeaderLine('traceparent'))->contains('-ff');
    }

    public function injectLeavesInvalidContextUntouched(): void
    {
        $request = $this->factory->createRequest('GET', 'https://api.example');

        $result = $this->propagator->inject(TraceContext::invalid(), $request);

        Assert::false($result->hasHeader('traceparent'));
        Assert::same($result, $request);
    }

    #[Property(runs: 300)]
    public function extractReversesInject(string $traceId, string $spanId, int $flags): void
    {
        $context = new TraceContext($traceId, $spanId, $flags);

        $injected = $this->propagator->inject($context, $this->factory->createRequest('GET', 'https://api.example'));
        $incoming = $this->factory->createServerRequest('GET', '/')
            ->withHeader('traceparent', $injected->getHeaderLine('traceparent'));

        Assert::true($this->propagator->extract($incoming)->equals($context));
    }

    /** @return array<string, ArbitraryInterface> */
    private function extractReversesInjectGenerators(): array
    {
        return [
            'traceId' => Gen::map(
                Gen::tuple(Gen::intBetween(0, PHP_INT_MAX), Gen::intBetween(1, PHP_INT_MAX)),
                static fn(array $halves): string => \sprintf('%016x%016x', $halves[0], $halves[1]),
            ),
            'spanId' => Gen::map(
                Gen::intBetween(1, PHP_INT_MAX),
                static fn(int $n): string => \sprintf('%016x', $n),
            ),
            'flags' => Gen::intBetween(0, 255),
        ];
    }
}
