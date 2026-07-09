<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * PSR-3 decorator that adds the active `trace_id` / `span_id` to every log
 * record's context, so logs correlate with traces in the backend (Tempo,
 * Jaeger). With no active valid trace context the record passes through
 * unchanged. Existing `trace_id` / `span_id` context keys are never overwritten.
 *
 * Wrap the application logger with it app-side:
 * `new TraceContextLogger($innerLogger, $tracer)`.
 *
 * @api
 */
final readonly class TraceContextLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private LoggerInterface $logger,
        private TracerInterface $tracer,
    ) {}

    /**
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log(mixed $level, \Stringable|string $message, array $context = []): void
    {
        $traceContext = $this->tracer->getContext();

        if ($traceContext->isValid()) {
            $context += [
                'trace_id' => $traceContext->traceId,
                'span_id' => $traceContext->spanId,
            ];
        }

        $this->logger->log($level, $message, $context);
    }
}
