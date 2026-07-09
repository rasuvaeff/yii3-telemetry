<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Rasuvaeff\Yii3Telemetry\Span;
use Rasuvaeff\Yii3Telemetry\Tests\Support\ArrayCache;
use Rasuvaeff\Yii3Telemetry\Tests\Support\RecordingTracer;
use Rasuvaeff\Yii3Telemetry\TracingCacheDecorator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(TracingCacheDecorator::class)]
final class TracingCacheDecoratorTest
{
    private RecordingTracer $tracer;
    private ArrayCache $inner;
    private TracingCacheDecorator $cache;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tracer = new RecordingTracer();
        $this->inner = new ArrayCache();
        $this->cache = new TracingCacheDecorator($this->inner, $this->tracer);
    }

    public function tracesSingleKeyOperations(): void
    {
        // PSR-16 reserves ":" — use dot-separated keys.
        $this->cache->set('user.1', 'alice');
        $value = $this->cache->get('user.1');
        $hit = $this->cache->has('user.1');
        $miss = $this->cache->has('user.404');

        Assert::same($value, 'alice');
        Assert::true($hit);
        Assert::false($miss);

        Assert::same($this->spanNames(), ['cache.set', 'cache.get', 'cache.has', 'cache.has']);

        $setSpan = $this->tracer->spans[0];
        Assert::same($setSpan->getAttributes()['cache.system'], 'psr16');
        Assert::same($setSpan->getAttributes()['cache.item.key'], 'user.1');

        Assert::same($this->tracer->spans[2]->getAttributes()['cache.hit'], true);
        Assert::same($this->tracer->spans[3]->getAttributes()['cache.hit'], false);
    }

    public function tracesBulkAndClearOperations(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2]);
        $this->cache->getMultiple(['a', 'b']);
        $this->cache->deleteMultiple(['a']);
        $this->cache->delete('b');
        $this->cache->clear();

        Assert::same(
            $this->spanNames(),
            ['cache.setMultiple', 'cache.getMultiple', 'cache.deleteMultiple', 'cache.delete', 'cache.clear'],
        );

        // The decorator forwards to the real cache.
        Assert::false($this->inner->has('a'));
        Assert::false($this->inner->has('b'));
    }

    /**
     * @return list<string>
     */
    private function spanNames(): array
    {
        return array_map(static fn(Span $span): string => $span->getName(), $this->tracer->spans);
    }
}
