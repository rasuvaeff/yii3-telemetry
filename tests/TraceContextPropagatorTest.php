<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
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
        yield 'version 00 with extra fields' => ['00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01-extra'];
        // Exactly 3 parts (< 4) with a non-"00" version and well-formed
        // trace/span ids: parse() must return early on the part-count check
        // before the list-assignment leaves $flags null and reaches
        // preg_match(FLAGS_PATTERN, null), which would TypeError under strict_types.
        yield 'three parts, well-formed prefix' => ['01-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331'];
    }

    public function extractIgnoresTracestateWhenTraceparentInvalid(): void
    {
        $request = $this->factory->createServerRequest('GET', '/')
            ->withHeader('traceparent', '')
            ->withHeader('tracestate', 'vendor=value');

        $context = $this->propagator->extract($request);

        Assert::false($context->isValid());
        Assert::same($context->traceState, '');
    }

    public function futureVersionWithExtraFieldsIsAccepted(): void
    {
        $request = $this->factory->createServerRequest('GET', '/')
            ->withHeader('traceparent', '01-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01-future-field');

        $context = $this->propagator->extract($request);

        Assert::true($context->isValid());
        Assert::same($context->traceId, self::TRACE_ID);
        Assert::same($context->spanId, self::SPAN_ID);
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

    public function toHeadersCarriesTraceparentAndOptionalState(): void
    {
        $bare = $this->propagator->toHeaders(new TraceContext(self::TRACE_ID, self::SPAN_ID, 1));
        Assert::same($bare, ['traceparent' => self::TRACEPARENT]);

        $withState = $this->propagator->toHeaders(new TraceContext(self::TRACE_ID, self::SPAN_ID, 1, 'vendor=value'));
        Assert::same($withState, ['traceparent' => self::TRACEPARENT, 'tracestate' => 'vendor=value']);

        Assert::same($this->propagator->toHeaders(TraceContext::invalid()), []);
    }

    public function fromHeadersReversesToHeaders(): void
    {
        $context = new TraceContext(self::TRACE_ID, self::SPAN_ID, 1, 'vendor=value');

        Assert::true($this->propagator->fromHeaders($this->propagator->toHeaders($context))->equals($context));
    }

    public function fromHeadersMatchesNamesCaseInsensitively(): void
    {
        $context = $this->propagator->fromHeaders([
            'TraceParent' => self::TRACEPARENT,
            'TRACESTATE' => 'vendor=value',
        ]);

        Assert::true($context->isValid());
        Assert::same($context->traceState, 'vendor=value');
    }

    public function fromHeadersTreatsDuplicateTraceparentAsInvalid(): void
    {
        $context = $this->propagator->fromHeaders([
            'traceparent' => [self::TRACEPARENT, self::TRACEPARENT],
        ]);

        Assert::false($context->isValid());
    }

    public function fromHeadersReturnsInvalidWhenAbsent(): void
    {
        Assert::false($this->propagator->fromHeaders([])->isValid());
    }

    public function fromHeadersRejectsTraceIdWithTrailingNewline(): void
    {
        // PSR-7 rejects LF in header values upstream of extract(); for non-HTTP
        // carriers (queue messages, AMQP, gRPC metadata) a smuggled `\n` reaches
        // the pattern directly, and `\z` is what stops it becoming a trace id.
        $context = $this->propagator->fromHeaders([
            'traceparent' => '00-' . self::TRACE_ID . "\n" . '-' . self::SPAN_ID . '-01',
        ]);

        Assert::false($context->isValid());
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
    public static function extractReversesInjectGenerators(): array
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

    /** @return iterable<string, array{string, string, int}> */
    public static function extractReversesInjectExamples(): iterable
    {
        // Flags are a bit field carried as two hex digits: unset, sampled, and
        // every bit set are where a sprintf/parse pair drops leading zeros or
        // truncates.
        yield 'flags unset' => [self::TRACE_ID, self::SPAN_ID, 0x00];
        yield 'sampled' => [self::TRACE_ID, self::SPAN_ID, 0x01];
        yield 'all flag bits set' => [self::TRACE_ID, self::SPAN_ID, 0xFF];
        yield 'ids one bit above invalid' => [\str_repeat('0', 31) . '1', \str_repeat('0', 15) . '1', 0x01];
    }

    #[Property(runs: 300, timeoutMs: 1000)]
    public function extractIsIdempotentOverInjection(string $traceparent): void
    {
        $incoming = $this->factory->createServerRequest('GET', '/')
            ->withHeader('traceparent', $traceparent);

        $once = $this->propagator->extract($incoming);

        // An accepted header survives re-injection verbatim; a rejected one
        // propagates nothing at all, rather than half-parsing into a context a
        // downstream service would then carry. The two invalid contexts are
        // not compared field by field on purpose: a parse that failed on the
        // ids keeps the flags it read, `TraceContext::invalid()` has none, and
        // neither is ever put on the wire.
        $reinjected = $this->propagator->inject(
            $once,
            $this->factory->createRequest('GET', 'https://api.example'),
        );
        $twice = $this->propagator->extract(
            $this->factory->createServerRequest('GET', '/')
                ->withHeader('traceparent', $reinjected->getHeaderLine('traceparent')),
        );

        Classify::cover($once->isValid(), 'accepted', 20.0);
        Classify::cover(!$once->isValid(), 'rejected', 20.0);

        if ($once->isValid()) {
            Assert::true($twice->equals($once));
        } else {
            Assert::false($twice->isValid());
            Assert::same($reinjected->getHeaderLine('traceparent'), '');
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function extractIsIdempotentOverInjectionGenerators(): array
    {
        return [
            'traceparent' => Gen::frequency([
                // The W3C shape, spelled as the pattern the parser applies.
                [2, Gen::regex('00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}')],
                // Wrong version, extra fields, and a future version with a
                // trailing segment — the forward-compat branch.
                [1, Gen::regex('(ff|01|0)-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}(-[0-9a-f]{4})?')],
                // Free-form near misses over the same alphabet: truncated ids,
                // missing fields, empty string.
                [2, Gen::stringFrom('0123456789abcdef-', minLength: 0, maxLength: 60)],
            ]),
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function extractIsIdempotentOverInjectionExamples(): iterable
    {
        yield 'canonical header' => [self::TRACEPARENT];
        yield 'empty header' => [''];
        yield 'all-zero ids are not a valid context' => ['00-' . \str_repeat('0', 32) . '-' . \str_repeat('0', 16) . '-01'];
        yield 'version ff is reserved' => ['ff-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01'];
        yield 'version 00 forbids a fifth field' => ['00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01-extra'];
        yield 'truncated trace id' => ['00-0af7651916cd43dd-' . self::SPAN_ID . '-01'];
    }

    #[Property(runs: 200)]
    public function traceStateTravelsOnlyBesideAnAcceptedTraceparent(string $traceparent, string $traceState): void
    {
        $incoming = $this->factory->createServerRequest('GET', '/')
            ->withHeader('traceparent', $traceparent)
            ->withHeader('tracestate', $traceState);

        $context = $this->propagator->extract($incoming);

        Classify::cover($context->isValid(), 'accepted traceparent', 20.0);
        Classify::cover(!$context->isValid(), 'rejected traceparent', 20.0);

        // A tracestate without a trace to attach it to is vendor data with no
        // owner; carrying it forward would let a caller inject arbitrary
        // header content into every downstream request.
        Assert::same($context->traceState, $context->isValid() ? $traceState : '');
    }

    /** @return array<string, ArbitraryInterface> */
    public static function traceStateTravelsOnlyBesideAnAcceptedTraceparentGenerators(): array
    {
        return [
            'traceparent' => Gen::frequency([
                [1, Gen::regex('00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}')],
                [1, Gen::stringFrom('0123456789abcdef-', minLength: 0, maxLength: 60)],
            ]),
            // `vendor=value` pairs: the shape the W3C spec defines, kept
            // header-legal so a conforming PSR-7 message accepts it.
            'traceState' => Gen::regex('[a-z]{1,6}=[a-z0-9]{1,8}(,[a-z]{1,6}=[a-z0-9]{1,8}){0,2}'),
        ];
    }
}
