<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use OpenTelemetry\API\Trace\SpanKind;

/**
 * Span kind mirroring OpenTelemetry {@see SpanKind}. Backing values are the OTel
 * integer constants, so `$kind->value` maps to an OTel span kind with no lookup.
 *
 * @api
 */
enum TraceKind: int
{
    case Internal = SpanKind::KIND_INTERNAL;
    case Client = SpanKind::KIND_CLIENT;
    case Server = SpanKind::KIND_SERVER;
    case Producer = SpanKind::KIND_PRODUCER;
    case Consumer = SpanKind::KIND_CONSUMER;
}
