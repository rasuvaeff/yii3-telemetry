<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use OpenTelemetry\API\Trace\StatusCode;
use Rasuvaeff\Yii3Telemetry\SpanStatus;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SpanStatus::class)]
#[Covers(SpanStatusCode::class)]
final class SpanStatusTest
{
    public function codesMirrorOtelStatusStrings(): void
    {
        Assert::same(SpanStatusCode::Unset->value, StatusCode::STATUS_UNSET);
        Assert::same(SpanStatusCode::Ok->value, StatusCode::STATUS_OK);
        Assert::same(SpanStatusCode::Error->value, StatusCode::STATUS_ERROR);
    }

    public function unsetFactoryHasNoDescription(): void
    {
        $status = SpanStatus::unset();

        Assert::same($status->code, SpanStatusCode::Unset);
        Assert::null($status->description);
    }

    public function okFactoryHasNoDescription(): void
    {
        $status = SpanStatus::ok();

        Assert::same($status->code, SpanStatusCode::Ok);
        Assert::null($status->description);
    }

    public function errorFactoryCarriesDescription(): void
    {
        $status = SpanStatus::error('boom');

        Assert::same($status->code, SpanStatusCode::Error);
        Assert::same($status->description, 'boom');
    }

    public function equalRequiresSameCodeAndDescription(): void
    {
        Assert::true(SpanStatus::error('x')->equals(SpanStatus::error('x')));
        Assert::false(SpanStatus::error('x')->equals(SpanStatus::error('y')));
        Assert::false(SpanStatus::ok()->equals(SpanStatus::unset()));
    }
}
