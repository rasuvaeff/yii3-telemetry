<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

/**
 * Non-recording span returned when tracing is disabled or a span is dropped by
 * sampling. Every mutator is a no-op; {@see isRecording()} is `false`. Callbacks
 * still receive it (never `null`), so side effects run unchanged.
 *
 * @api
 */
final class NullSpan implements SpanInterface
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    #[\Override]
    public function setAttribute(string $key, bool|int|float|string|array|null $value): void {}

    #[\Override]
    public function updateName(string $name): void {}

    #[\Override]
    public function setStatus(SpanStatusCode $code, ?string $description = null): void {}

    #[\Override]
    public function recordException(\Throwable $exception): void {}

    #[\Override]
    public function end(): void {}

    #[\Override]
    public function isRecording(): bool
    {
        return false;
    }

    #[\Override]
    public function getTraceContext(): TraceContext
    {
        return TraceContext::invalid();
    }
}
