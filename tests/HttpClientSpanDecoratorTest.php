<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Telemetry\HttpClientSpanDecorator;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\Tests\Support\RecordingTracer;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(HttpClientSpanDecorator::class)]
final class HttpClientSpanDecoratorTest
{
    private RecordingTracer $tracer;
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tracer = new RecordingTracer();
        $this->factory = new Psr17Factory();
    }

    public function opensClientSpanWithSafeHttpAttributes(): void
    {
        $decorator = new HttpClientSpanDecorator($this->client(200), $this->tracer);

        $response = $decorator->sendRequest(
            $this->factory->createRequest('GET', 'https://api.example/users?token=secret'),
        );

        Assert::same($response->getStatusCode(), 200);
        Assert::count($this->tracer->spans, 1);

        $span = $this->tracer->spans[0];
        Assert::same($span->getName(), 'HTTP GET');
        Assert::same($span->getKind(), TraceKind::Client);

        $attributes = $span->getAttributes();
        Assert::same($attributes['http.request.method'], 'GET');
        Assert::same($attributes['server.address'], 'api.example');
        Assert::same($attributes['url.path'], '/users'); // query dropped — no secret leak
        Assert::same($attributes['http.response.status_code'], 200);
    }

    public function injectsTraceparentIntoTheOutgoingRequest(): void
    {
        $client = $this->client(200);
        $decorator = new HttpClientSpanDecorator($client, $this->tracer);

        $decorator->sendRequest($this->factory->createRequest('GET', 'https://api.example/x'));

        Assert::true($client->captured?->hasHeader('traceparent') ?? false);
    }

    public function marksClientAndServerErrorsAsError(): void
    {
        $decorator = new HttpClientSpanDecorator($this->client(500), $this->tracer);

        $decorator->sendRequest($this->factory->createRequest('POST', 'https://api.example/orders'));

        $span = $this->tracer->spans[0];
        Assert::same($span->getStatus()->code, SpanStatusCode::Error);
        Assert::same($span->getStatus()->description, 'HTTP 500');
    }

    /**
     * @return ClientInterface&object{captured: ?RequestInterface}
     */
    private function client(int $status): ClientInterface
    {
        return new class ($status) implements ClientInterface {
            public ?RequestInterface $captured = null;

            public function __construct(private readonly int $status) {}

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new Response($this->status);
            }
        };
    }
}
