<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

/**
 * Immutable span status: a {@see SpanStatusCode} plus an optional description
 * (meaningful only for {@see SpanStatusCode::Error}, per the OTel spec).
 *
 * @api
 */
final readonly class SpanStatus
{
    public function __construct(
        public SpanStatusCode $code,
        public ?string $description = null,
    ) {}

    public static function unset(): self
    {
        return new self(SpanStatusCode::Unset);
    }

    public static function ok(): self
    {
        return new self(SpanStatusCode::Ok);
    }

    public static function error(?string $description = null): self
    {
        return new self(SpanStatusCode::Error, $description);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code
            && $this->description === $other->description;
    }
}
