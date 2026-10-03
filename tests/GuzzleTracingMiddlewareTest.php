<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectionException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Rasuvaeff\Yii3Telemetry\GuzzleTracingMiddleware;
use Rasuvaeff\Yii3Telemetry\PropagationPolicy;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\Tests\Support\RecordingTracer;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(GuzzleTracingMiddleware::class)]
final class GuzzleTracingMiddlewareTest
{
    private RecordingTracer $tracer;

    /** @var list<RequestInterface> */
    private array $seen = [];

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tracer = new RecordingTracer();
        $this->seen = [];
    }

    public function syncRequestOpensAClientSpanWithSafeAttributes(): void
    {
        $client = $this->client([new Response(200)]);

        $response = $client->request('GET', 'https://api.example/users?token=secret');

        Assert::same($response->getStatusCode(), 200);
        Assert::count($this->tracer->spans, 1);

        $span = $this->tracer->spans[0];
        Assert::same($span->getName(), 'HTTP GET');
        Assert::same($span->getKind(), TraceKind::Client);
        Assert::true($span->hasEnded());
        Assert::same($span->getStatus()->code, SpanStatusCode::Unset);

        $attributes = $span->getAttributes();
        Assert::same($attributes['http.request.method'], 'GET');
        Assert::same($attributes['server.address'], 'api.example');
        Assert::same($attributes['url.path'], '/users');
        Assert::same($attributes['http.response.status_code'], 200);
    }

    public function injectsTraceparentOfTheClientSpan(): void
    {
        $this->client([new Response(200)])->request('GET', 'https://api.example/x');

        $span = $this->tracer->spans[0];
        $context = $span->getTraceContext();

        Assert::same(
            $this->seen[0]->getHeaderLine('traceparent'),
            \sprintf('00-%s-%s-%02x', $context->traceId, $context->spanId, $context->traceFlags),
        );
    }

    public function errorStatusMarksTheSpanLikeThePsr18Decorator(): void
    {
        $client = $this->client([new Response(500), new Response(399), new Response(400)], httpErrors: false);

        $client->request('GET', 'https://api.example/a');
        $client->request('GET', 'https://api.example/b');
        $client->request('GET', 'https://api.example/c');

        Assert::same($this->tracer->spans[0]->getStatus()->code, SpanStatusCode::Error);
        Assert::same($this->tracer->spans[0]->getStatus()->description, 'HTTP 500');
        Assert::same($this->tracer->spans[1]->getStatus()->code, SpanStatusCode::Unset);
        Assert::same($this->tracer->spans[2]->getStatus()->code, SpanStatusCode::Error);
    }

    public function asyncSpanEndsWhenThePromiseIsFulfilledNotWhenTheHandlerReturns(): void
    {
        $inner = new Promise();
        $handler = $this->stack(static fn(RequestInterface $request, array $options): PromiseInterface => $inner);

        $promise = $handler(new Request('GET', 'https://api.example/slow'), []);

        Assert::count($this->tracer->spans, 1);
        Assert::false($this->tracer->spans[0]->hasEnded());
        Assert::false(isset($this->tracer->spans[0]->getAttributes()['http.response.status_code']));

        $inner->resolve(new Response(201));
        $promise->wait();

        Assert::true($this->tracer->spans[0]->hasEnded());
        Assert::same($this->tracer->spans[0]->getAttributes()['http.response.status_code'], 201);
    }

    public function requestAsyncAndPoolEachGetTheirOwnSpan(): void
    {
        $client = $this->client([new Response(200), new Response(404), new Response(200)], httpErrors: false);

        $promise = $client->requestAsync('GET', 'https://api.example/one');
        Assert::count($this->tracer->spans, 1);
        $promise->wait();

        $requests = [new Request('GET', 'https://api.example/two'), new Request('GET', 'https://api.example/three')];
        $pool = new Pool($client, $requests, ['concurrency' => 2]);
        $pool->promise()->wait();

        Assert::count($this->tracer->spans, 3);

        foreach ($this->tracer->spans as $span) {
            Assert::true($span->hasEnded());
        }

        Assert::same($this->tracer->spans[1]->getStatus()->code, SpanStatusCode::Error);
        Assert::same($this->tracer->spans[2]->getStatus()->code, SpanStatusCode::Unset);
    }

    public function rejectedPromiseMarksTheSpanAsErrorAndRecordsTheException(): void
    {
        $request = new Request('GET', 'https://api.example/x');
        $client = $this->client([new ConnectException('connection refused', $request)]);

        try {
            $client->request('GET', 'https://api.example/x');
            Assert::fail('expected a ConnectException');
        } catch (ConnectException $e) {
            Assert::same($e->getMessage(), 'connection refused');
        }

        $span = $this->tracer->spans[0];
        Assert::true($span->hasEnded());
        Assert::same($span->getStatus()->code, SpanStatusCode::Error);
        Assert::same($span->getStatus()->description, 'connection refused');
        Assert::count($span->getRecordedExceptions(), 1);
        Assert::false(isset($span->getAttributes()['http.response.status_code']));
    }

    public function httpErrorsExceptionKeepsTheStatusCode(): void
    {
        $stack = new HandlerStack(new MockHandler([new Response(503)]));
        $stack->push(GuzzleTracingMiddleware::create($this->tracer), 'tracing');
        $stack->push(Middleware::httpErrors(), 'http_errors');

        try {
            (new Client(['handler' => $stack]))->request('GET', 'https://api.example/x');
            Assert::fail('expected a RequestException');
        } catch (RequestException $e) {
            Assert::same($e->getResponse()?->getStatusCode(), 503);
        }

        $span = $this->tracer->spans[0];
        Assert::true($span->hasEnded());
        Assert::same($span->getAttributes()['http.response.status_code'], 503);
        Assert::same($span->getStatus()->code, SpanStatusCode::Error);
        Assert::count($span->getRecordedExceptions(), 1);
    }

    public function statusErrorFromAnInnerHttpErrorsMiddlewareNeedsNoException(): void
    {
        $client = $this->client([new Response(503)], httpErrors: true);

        try {
            $client->request('GET', 'https://api.example/x');
            Assert::fail('expected a RequestException');
        } catch (RequestException $e) {
            Assert::same($e->getResponse()?->getStatusCode(), 503);
        }

        $span = $this->tracer->spans[0];
        Assert::same($span->getAttributes()['http.response.status_code'], 503);
        Assert::same($span->getStatus()->description, 'HTTP 503');
        Assert::count($span->getRecordedExceptions(), 0);
    }

    public function nonThrowableRejectionStillEndsTheSpanAsError(): void
    {
        $handler = $this->stack(static fn(): PromiseInterface => Create::rejectionFor('plain reason'));

        $promise = $handler(new Request('GET', 'https://api.example/x'), []);

        try {
            $promise->wait();
            Assert::fail('expected a rejection');
        } catch (RejectionException $e) {
            Assert::same($e->getReason(), 'plain reason');
        }

        Assert::true($this->tracer->spans[0]->hasEnded());
        Assert::same($this->tracer->spans[0]->getStatus()->code, SpanStatusCode::Error);
        Assert::count($this->tracer->spans[0]->getRecordedExceptions(), 0);
    }

    public function handlerThatThrowsSynchronouslyEndsTheSpanAndRethrows(): void
    {
        $handler = $this->stack(static function (): never {
            throw new \RuntimeException('handler blew up');
        });

        try {
            $handler(new Request('GET', 'https://api.example/x'), []);
            Assert::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'handler blew up');
        }

        $span = $this->tracer->spans[0];
        Assert::true($span->hasEnded());
        Assert::same($span->getStatus()->code, SpanStatusCode::Error);
        Assert::count($span->getRecordedExceptions(), 1);
    }

    public function propagationPolicyFalseLeavesHeadersUntouchedButKeepsTheSpan(): void
    {
        $this->client([new Response(200)], new PropagationPolicy(enabled: false))
            ->request('GET', 'https://api.example/x');

        Assert::count($this->tracer->spans, 1);
        Assert::false($this->seen[0]->hasHeader('traceparent'));
    }

    public function allowlistInjectsOnlyForMatchingHosts(): void
    {
        $client = $this->client(
            [new Response(200), new Response(200), new Response(200)],
            new PropagationPolicy(enabled: true, hosts: ['api.internal', '*.svc.local']),
        );

        $client->request('GET', 'https://api.internal/x');
        $client->request('GET', 'https://billing.svc.local/x');
        $client->request('GET', 'https://third-party.example/x');

        Assert::true($this->seen[0]->hasHeader('traceparent'));
        Assert::true($this->seen[1]->hasHeader('traceparent'));
        Assert::false($this->seen[2]->hasHeader('traceparent'));
        Assert::count($this->tracer->spans, 3);
    }

    public function pushedInsideARetryMiddlewareYieldsOneSpanPerAttempt(): void
    {
        $client = $this->retryingClient(tracingInsideRetry: true);

        $client->request('GET', 'https://api.example/x');

        Assert::count($this->tracer->spans, 2);
        Assert::same($this->tracer->spans[0]->getStatus()->code, SpanStatusCode::Error);
        Assert::same($this->tracer->spans[1]->getStatus()->code, SpanStatusCode::Unset);
        Assert::same($this->tracer->spans[1]->getAttributes()['http.response.status_code'], 200);
    }

    public function pushedOutsideARetryMiddlewareYieldsOneSpanPerLogicalCall(): void
    {
        $client = $this->retryingClient(tracingInsideRetry: false);

        $client->request('GET', 'https://api.example/x');

        Assert::count($this->tracer->spans, 1);
        Assert::same($this->tracer->spans[0]->getAttributes()['http.response.status_code'], 200);
        Assert::same($this->tracer->spans[0]->getStatus()->code, SpanStatusCode::Unset);
    }

    private function retryingClient(bool $tracingInsideRetry): Client
    {
        $stack = new HandlerStack(new MockHandler([new Response(500), new Response(200)]));
        $retry = Middleware::retry(
            static fn(int $retries, RequestInterface $request, ?\Psr\Http\Message\ResponseInterface $response): bool
                => $retries < 1 && $response instanceof \Psr\Http\Message\ResponseInterface && $response->getStatusCode() >= 500,
        );
        $tracing = GuzzleTracingMiddleware::create($this->tracer);

        if ($tracingInsideRetry) {
            $stack->push($retry, 'retry');
            $stack->push($tracing, 'tracing');
        } else {
            $stack->push($tracing, 'tracing');
            $stack->push($retry, 'retry');
        }

        return new Client(['handler' => $stack, 'http_errors' => false]);
    }

    /**
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $queue
     */
    private function client(array $queue, PropagationPolicy $policy = new PropagationPolicy(), bool $httpErrors = true): Client
    {
        $stack = new HandlerStack(new MockHandler($queue));

        if ($httpErrors) {
            $stack->push(Middleware::httpErrors(), 'http_errors');
        }

        $stack->push(GuzzleTracingMiddleware::create($this->tracer, $policy), 'tracing');
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->seen[] = $request;

            return $request;
        }), 'capture');

        return new Client(['handler' => $stack, 'http_errors' => $httpErrors]);
    }

    /**
     * @param callable(RequestInterface, array): PromiseInterface $inner
     *
     * @return callable(RequestInterface, array): PromiseInterface
     */
    private function stack(callable $inner): callable
    {
        return GuzzleTracingMiddleware::create($this->tracer)($inner);
    }
}
