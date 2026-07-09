<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Rasuvaeff\Yii3Telemetry\DbQueryProfiler;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\Tests\Support\RecordingTracer;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Profiler\Context\CommandContext;
use Yiisoft\Db\Profiler\Context\ConnectionContext;

#[Test]
#[Covers(DbQueryProfiler::class)]
final class DbQueryProfilerTest
{
    private RecordingTracer $tracer;
    private DbQueryProfiler $profiler;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tracer = new RecordingTracer();
        $this->profiler = new DbQueryProfiler($this->tracer);
    }

    public function tracesQueryWithParameterizedSqlNotRawValues(): void
    {
        $context = new CommandContext('query', 'ctx', 'SELECT * FROM users WHERE id = :id', [':id' => 42]);

        // The token is the raw SQL (values substituted); db.statement must use the
        // parameterized SQL from the context, never the token.
        $this->profiler->begin('SELECT * FROM users WHERE id = 42', $context);
        $this->profiler->end('SELECT * FROM users WHERE id = 42', $context);

        Assert::count($this->tracer->spans, 1);

        $span = $this->tracer->spans[0];
        Assert::same($span->getName(), 'db.query');
        Assert::same($span->getKind(), TraceKind::Client);
        Assert::same($span->getAttributes()['db.system'], 'sql');
        Assert::same($span->getAttributes()['db.statement'], 'SELECT * FROM users WHERE id = :id');
        Assert::same($span->getAttributes()['db.operation'], 'SELECT');
        Assert::true($span->hasEnded());
    }

    public function recordsQueryException(): void
    {
        $context = (new CommandContext('query', 'ctx', 'INSERT INTO t VALUES (1)', []))
            ->setException(new \RuntimeException('constraint failed'));

        $this->profiler->begin('INSERT INTO t VALUES (1)', $context);
        $this->profiler->end('INSERT INTO t VALUES (1)', $context);

        $span = $this->tracer->spans[0];
        Assert::same($span->getStatus()->code, SpanStatusCode::Error);
        Assert::same($span->getStatus()->description, 'constraint failed');
        Assert::count($span->getRecordedExceptions(), 1);
    }

    public function endsSpansInLifoOrder(): void
    {
        $outer = new CommandContext('query', 'ctx', 'SELECT 1', []);
        $inner = new CommandContext('query', 'ctx', 'SELECT 2', []);

        $this->profiler->begin('SELECT 1', $outer);
        $this->profiler->begin('SELECT 2', $inner);
        $this->profiler->end('SELECT 2', $inner->setException(new \RuntimeException('boom')));
        $this->profiler->end('SELECT 1', $outer);

        // The error belongs to the inner span (LIFO), not the outer one.
        Assert::same($this->tracer->spans[1]->getStatus()->code, SpanStatusCode::Error);
        Assert::same($this->tracer->spans[0]->getStatus()->code, SpanStatusCode::Unset);
    }

    public function namesNonQueryContextByType(): void
    {
        $context = new ConnectionContext('open');

        $this->profiler->begin('connection=main', $context);
        $this->profiler->end('connection=main', $context);

        Assert::same($this->tracer->spans[0]->getName(), 'db.connection');
    }

    public function fallsBackToTokenForArrayContext(): void
    {
        $this->profiler->begin('DELETE FROM sessions', []);
        $this->profiler->end('DELETE FROM sessions', []);

        $span = $this->tracer->spans[0];
        Assert::same($span->getName(), 'db.query');
        Assert::same($span->getAttributes()['db.statement'], 'DELETE FROM sessions');
        Assert::same($span->getAttributes()['db.operation'], 'DELETE');
    }

    public function endWithoutBeginIsSafe(): void
    {
        $this->profiler->end('SELECT 1');

        Assert::count($this->tracer->spans, 0);
    }
}
