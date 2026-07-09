<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Telemetry\TraceContext;
use Rasuvaeff\Yii3Telemetry\TraceContextPropagator;

require __DIR__ . '/../vendor/autoload.php';

$propagator = new TraceContextPropagator();
$factory = new Psr17Factory();

// Service A: create a context and inject it into an OUTGOING client request.
$context = new TraceContext('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331', traceFlags: 1);
$outgoing = $propagator->inject($context, $factory->createRequest('GET', 'https://service-b/api'));

echo "A sends traceparent: {$outgoing->getHeaderLine('traceparent')}\n";

// Service B: extract the context from the INCOMING server request.
$incoming = $factory->createServerRequest('GET', '/api')
    ->withHeader('traceparent', $outgoing->getHeaderLine('traceparent'));
$received = $propagator->extract($incoming);

echo "B receives trace id: {$received->traceId}\n";
echo 'Same trace across the hop: ' . ($received->equals($context) ? 'yes' : 'no') . "\n";
