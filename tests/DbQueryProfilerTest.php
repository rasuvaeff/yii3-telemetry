<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Rasuvaeff\Yii3Telemetry\DbQueryProfiler;
use Rasuvaeff\Yii3Telemetry\SpanStatusCode;
use Rasuvaeff\Yii3Telemetry\Tests\Support\FakeProfilerContext;
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
        Assert::same($this->tracer->spans[0]->getAttributes(), ['db.system' => 'sql']);
    }

    public function ignoresNonThrowableExceptionValue(): void
    {
        // ContextInterface::asArray() is untyped (array); a non-conforming
        // implementation could put a non-Throwable under 'exception'. The
        // isset()+instanceof guard must reject it rather than pass it to
        // Span::recordException(\Throwable), which would fatal.
        $context = new FakeProfilerContext('command', ['exception' => 'not a throwable']);

        $this->profiler->begin('SELECT 1', $context);
        $this->profiler->end('SELECT 1', $context);

        $span = $this->tracer->spans[0];
        Assert::same($span->getStatus()->code, SpanStatusCode::Unset);
        Assert::count($span->getRecordedExceptions(), 0);
    }

    public function ignoresNonStringSqlValue(): void
    {
        // Same defensive guard, this time on 'sql': a non-string value must
        // fall back to the type-based name/attributes, not reach
        // queryAttributes(string).
        $context = new FakeProfilerContext('command', ['sql' => 42]);

        $this->profiler->begin('SELECT 1', $context);
        $this->profiler->end('SELECT 1', $context);

        $span = $this->tracer->spans[0];
        Assert::same($span->getName(), 'db.command');
        Assert::same($span->getAttributes(), ['db.system' => 'sql']);
    }

    public function operationIgnoresLeadingNonWordText(): void
    {
        $context = new CommandContext('query', 'ctx', '/* hint */ SELECT 1', []);

        $this->profiler->begin('/* hint */ SELECT 1', $context);
        $this->profiler->end('/* hint */ SELECT 1', $context);

        Assert::same($this->tracer->spans[0]->getAttributes()['db.operation'], 'UNKNOWN');
    }

    public function operationIsUppercasedAndTrimmedOfLeadingWhitespace(): void
    {
        $context = new CommandContext('query', 'ctx', '  select 1', []);

        $this->profiler->begin('  select 1', $context);
        $this->profiler->end('  select 1', $context);

        Assert::same($this->tracer->spans[0]->getAttributes()['db.operation'], 'SELECT');
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

    public function explicitDbSystemOverridesGenericSql(): void
    {
        $profiler = new DbQueryProfiler($this->tracer, dbSystem: 'postgresql');
        $context = new CommandContext('query', 'ctx', 'SELECT 1', []);

        $profiler->begin('SELECT 1', $context);
        $profiler->end('SELECT 1', $context);

        Assert::same($this->tracer->spans[0]->getAttributes()['db.system'], 'postgresql');
    }
}
