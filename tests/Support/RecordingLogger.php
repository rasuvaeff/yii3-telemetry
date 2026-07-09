<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that keeps every record in memory for assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
