<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Rasuvaeff\Yii3Telemetry\NullTracerProvider;
use Rasuvaeff\Yii3Telemetry\Tracer;
use Rasuvaeff\Yii3Telemetry\TracerInterface;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * `config/di.php` and `config/params.php` are outside the cs/psalm/testo gate,
 * so this test guards the wiring contract directly.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function coreBindsOnlyTheFacade(): void
    {
        $di = $this->di();

        Assert::array($di)->hasKeys(Tracer::class, TracerInterface::class);
    }

    public function coreDoesNotBindTheSwappableProviderInterface(): void
    {
        $di = $this->di();

        Assert::array($di)->doesNotHaveKeys(TracerProviderInterface::class);
    }

    public function facadeBecomesNoOpWithNullProvider(): void
    {
        $tracer = new Tracer(new NullTracerProvider());

        $result = $tracer->trace('op', static fn(): int => 5);

        Assert::same($result, 5);
        Assert::false($tracer->currentSpan()->isRecording());
        Assert::false($tracer->getContext()->isValid());
    }

    public function paramsAreNamespaced(): void
    {
        /** @var array<string, mixed> $params */
        $params = require __DIR__ . '/../config/params.php';

        Assert::array($params)->hasKeys('rasuvaeff/yii3-telemetry');
    }

    /**
     * @return array<string, mixed>
     */
    private function di(): array
    {
        /** @var array<string, mixed> $di */
        $di = require __DIR__ . '/../config/di.php';

        return $di;
    }
}
