<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Rasuvaeff\Yii3Telemetry\HttpClientSpanDecorator;
use Rasuvaeff\Yii3Telemetry\LogTracer;

require __DIR__ . '/../vendor/autoload.php';

$logger = new class extends AbstractLogger {
    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        printf("[%s] %s status=%s\n", (string) $level, (string) $message, (string) ($context['status'] ?? '?'));
    }
};

$tracer = new LogTracer($logger);

// Any PSR-18 client can be wrapped — here a stub returning 200.
$innerClient = new class implements ClientInterface {
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }
};

$client = new HttpClientSpanDecorator($innerClient, $tracer);

// The decorator opens an "HTTP GET" span, injects traceparent, records the status.
$client->sendRequest((new Psr17Factory())->createRequest('GET', 'https://api.example/users'));

echo "The client span above was recorded transparently.\n";
