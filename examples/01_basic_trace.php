<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Rasuvaeff\Yii3Telemetry\LogTracer;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\TraceKind;

require __DIR__ . '/../vendor/autoload.php';

$logger = new class extends AbstractLogger {
    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        printf("[%s] %s %s\n", (string) $level, (string) $message, (string) json_encode($context));
    }
};

$tracer = new LogTracer($logger);

$result = $tracer->trace(
    name: 'checkout.process',
    callback: static function (SpanInterface $span): string {
        $span->setAttribute('order.id', 'ORD-42');

        return 'confirmed';
    },
    attributes: ['user.id' => 'u-7'],
    traceKind: TraceKind::Internal,
);

echo "result: {$result}\n";
