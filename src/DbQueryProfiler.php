<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Yiisoft\Db\Profiler\ContextInterface;
use Yiisoft\Db\Profiler\ProfilerInterface;

/**
 * `yiisoft/db` profiler that opens a CLIENT span per query. Set it on a
 * connection with `$connection->setProfiler(new DbQueryProfiler($tracer))`.
 *
 * `db.statement` carries the **parameterized** SQL (placeholders, not values)
 * from the profiler context — parameter values are never attached. Nested
 * begin/end pairs (connection then query) are tracked as a LIFO stack.
 *
 * The profiler context does not expose the driver, so pass the OTel semconv
 * `db.system` value for your connection (`mysql`, `postgresql`, `sqlite`, …)
 * explicitly; the default is the generic `sql`.
 *
 * @api
 */
final class DbQueryProfiler implements ProfilerInterface
{
    /** @var list<SpanInterface> */
    private array $spans = [];

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly string $dbSystem = 'sql',
    ) {}

    #[\Override]
    public function begin(string $token, array|ContextInterface $context = []): void
    {
        [$name, $attributes, $kind] = $this->describe($token, $context);

        $this->spans[] = $this->tracer->startSpan($name, $attributes, $kind);
    }

    #[\Override]
    public function end(string $token, array|ContextInterface $context = []): void
    {
        $span = array_pop($this->spans);

        if ($span === null) {
            return;
        }

        if ($context instanceof ContextInterface) {
            $data = $context->asArray();

            if (isset($data['exception']) && $data['exception'] instanceof \Throwable) {
                $span->recordException($data['exception']);
                $span->setStatus(SpanStatusCode::Error, $data['exception']->getMessage());
            }
        }

        $span->end();
    }

    /**
     * @return array{string, array<string, string>, TraceKind}
     */
    private function describe(string $token, array|ContextInterface $context): array
    {
        if (!$context instanceof ContextInterface) {
            return ['db.query', $this->queryAttributes($token), TraceKind::Client];
        }

        $data = $context->asArray();

        if (isset($data['sql']) && \is_string($data['sql'])) {
            return ['db.query', $this->queryAttributes($data['sql']), TraceKind::Client];
        }

        return ['db.' . $context->getType(), ['db.system' => $this->dbSystem], TraceKind::Client];
    }

    /**
     * @return array<string, string>
     */
    private function queryAttributes(string $sql): array
    {
        return [
            'db.system' => $this->dbSystem,
            'db.statement' => $sql,
            'db.operation' => $this->operation($sql),
        ];
    }

    private function operation(string $sql): string
    {
        if (preg_match('/^\s*(\w+)/', $sql, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return 'UNKNOWN';
    }
}
