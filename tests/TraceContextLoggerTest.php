<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Psr\Log\NullLogger;
use Rasuvaeff\Yii3Telemetry\LogTracer;
use Rasuvaeff\Yii3Telemetry\NullTracer;
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\Tests\Support\RecordingLogger;
use Rasuvaeff\Yii3Telemetry\TraceContextLogger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(TraceContextLogger::class)]
final class TraceContextLoggerTest
{
    private RecordingLogger $sink;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->sink = new RecordingLogger();
    }

    public function addsTraceAndSpanIdInsideActiveTrace(): void
    {
        $tracer = new LogTracer(new NullLogger());
        $logger = new TraceContextLogger($this->sink, $tracer);

        $tracer->trace('op', function (SpanInterface $span) use ($logger): void {
            $logger->info('inside');

            $context = $span->getTraceContext();
            Assert::same($this->sink->records[0]['context']['trace_id'], $context->traceId);
            Assert::same($this->sink->records[0]['context']['span_id'], $context->spanId);
        });

        Assert::same($this->sink->records[0]['message'], 'inside');
        Assert::same($this->sink->records[0]['level'], 'info');
    }

    public function passesRecordThroughWithoutActiveTrace(): void
    {
        $logger = new TraceContextLogger($this->sink, NullTracer::instance());

        $logger->error('outside', ['key' => 'value']);

        Assert::same($this->sink->records[0]['context'], ['key' => 'value']);
    }

    public function neverOverwritesExistingKeys(): void
    {
        $tracer = new LogTracer(new NullLogger());
        $logger = new TraceContextLogger($this->sink, $tracer);

        $tracer->trace('op', static function () use ($logger): void {
            $logger->info('log', ['trace_id' => 'caller-owned']);
        });

        Assert::same($this->sink->records[0]['context']['trace_id'], 'caller-owned');
        Assert::true(isset($this->sink->records[0]['context']['span_id']));
    }
}
