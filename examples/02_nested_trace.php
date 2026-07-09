<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Rasuvaeff\Yii3Telemetry\LogTracer;
use Rasuvaeff\Yii3Telemetry\SpanInterface;

require __DIR__ . '/../vendor/autoload.php';

$logger = new class extends AbstractLogger {
    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        printf("%s trace=%s span=%s\n", (string) $message, (string) $context['trace_id'], (string) $context['span_id']);
    }
};

$tracer = new LogTracer($logger);

// A nested trace() inherits the parent's traceId; the child gets its own spanId.
$tracer->trace('http.request', static function (SpanInterface $request) use ($tracer): void {
    $request->setAttribute('http.route', '/checkout');

    $tracer->trace('db.query', static function (SpanInterface $query): void {
        $query->setAttribute('db.statement', 'SELECT * FROM orders WHERE id = ?');
    });
});

echo "The two spans above share one trace id.\n";
