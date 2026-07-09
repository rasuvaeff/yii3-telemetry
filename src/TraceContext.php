<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Rasuvaeff\Yii3Telemetry\Exception\InvalidArgumentException;

/**
 * W3C Trace Context: the propagatable identity of a span. Fields mirror the OTel
 * `SpanContext` model so a backend adapter maps them without a lookup table.
 *
 * `traceFlags` is the raw W3C flags byte (bit 0 = sampled). `traceState` is the
 * opaque `tracestate` header value (empty when absent).
 *
 * @api
 */
final readonly class TraceContext
{
    private const string TRACE_ID_PATTERN = '/^[0-9a-f]{32}$/';
    private const string SPAN_ID_PATTERN = '/^[0-9a-f]{16}$/';
    private const string INVALID_TRACE_ID = '00000000000000000000000000000000';
    private const string INVALID_SPAN_ID = '0000000000000000';
    private const int FLAG_SAMPLED = 0x01;

    public function __construct(
        public string $traceId,
        public string $spanId,
        public int $traceFlags = self::FLAG_SAMPLED,
        public string $traceState = '',
    ) {
        if (preg_match(self::TRACE_ID_PATTERN, $traceId) !== 1) {
            throw new InvalidArgumentException(\sprintf('Invalid trace id "%s"', $traceId));
        }

        if (preg_match(self::SPAN_ID_PATTERN, $spanId) !== 1) {
            throw new InvalidArgumentException(\sprintf('Invalid span id "%s"', $spanId));
        }

        if ($traceFlags < 0 || $traceFlags > 0xFF) {
            throw new InvalidArgumentException(\sprintf('Trace flags out of range: %d', $traceFlags));
        }
    }

    public static function invalid(): self
    {
        return new self(self::INVALID_TRACE_ID, self::INVALID_SPAN_ID, 0);
    }

    /**
     * A context is valid when both ids are present (non-zero), per W3C.
     */
    public function isValid(): bool
    {
        return $this->traceId !== self::INVALID_TRACE_ID
            && $this->spanId !== self::INVALID_SPAN_ID;
    }

    public function isSampled(): bool
    {
        return ($this->traceFlags & self::FLAG_SAMPLED) === self::FLAG_SAMPLED;
    }

    public function equals(self $other): bool
    {
        return $this->traceId === $other->traceId
            && $this->spanId === $other->spanId
            && $this->traceFlags === $other->traceFlags
            && $this->traceState === $other->traceState;
    }
}
