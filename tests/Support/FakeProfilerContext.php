<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests\Support;

use Yiisoft\Db\Profiler\ContextInterface;

/**
 * `ContextInterface::asArray()` is untyped (`array`) — a third-party
 * implementation is free to return values that do not match what
 * `yiisoft/db`'s own `CommandContext`/`ConnectionContext` produce (e.g. a
 * non-`Throwable` under the `exception` key, a non-string under `sql`). This
 * double lets tests exercise `DbQueryProfiler`'s defensive `isset()`/type
 * checks against exactly that untrusted shape.
 */
final class FakeProfilerContext implements ContextInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $type,
        private readonly array $data,
    ) {}

    #[\Override]
    public function getType(): string
    {
        return $this->type;
    }

    #[\Override]
    public function asArray(): array
    {
        return $this->data;
    }
}
