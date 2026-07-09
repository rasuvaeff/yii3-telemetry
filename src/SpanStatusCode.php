<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use OpenTelemetry\API\Trace\StatusCode;

/**
 * Span status code mirroring OpenTelemetry {@see StatusCode}. Backing values are
 * the OTel status strings (`Unset`/`Ok`/`Error`), so `$code->value` maps to an
 * OTel status with no lookup.
 *
 * @api
 */
enum SpanStatusCode: string
{
    case Unset = StatusCode::STATUS_UNSET;
    case Ok = StatusCode::STATUS_OK;
    case Error = StatusCode::STATUS_ERROR;
}
