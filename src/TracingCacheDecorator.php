<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache decorator that opens a span around every cache operation, tagging
 * it with `cache.system`, the item key, and (for reads) whether it was a hit.
 * Wrap any PSR-16 cache (e.g. `yiisoft/cache`) with it.
 *
 * @api
 */
final readonly class TracingCacheDecorator implements CacheInterface
{
    public function __construct(
        private CacheInterface $cache,
        private TracerInterface $tracer,
    ) {}

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->traced('cache.get', fn(): mixed => $this->cache->get($key, $default), $key);
    }

    #[\Override]
    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        return $this->traced('cache.set', fn(): bool => $this->cache->set($key, $value, $ttl), $key);
    }

    #[\Override]
    public function delete(string $key): bool
    {
        return $this->traced('cache.delete', fn(): bool => $this->cache->delete($key), $key);
    }

    #[\Override]
    public function clear(): bool
    {
        return $this->traced('cache.clear', fn(): bool => $this->cache->clear());
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->traced('cache.getMultiple', fn(): iterable => $this->cache->getMultiple($keys, $default));
    }

    #[\Override]
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        return $this->traced('cache.setMultiple', fn(): bool => $this->cache->setMultiple($values, $ttl));
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        return $this->traced('cache.deleteMultiple', fn(): bool => $this->cache->deleteMultiple($keys));
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->traced('cache.has', function (SpanInterface $span) use ($key): bool {
            $hit = $this->cache->has($key);
            $span->setAttribute('cache.hit', $hit);

            return $hit;
        }, $key);
    }

    /**
     * @template T
     *
     * @param callable(SpanInterface): T $operation
     *
     * @return T
     */
    private function traced(string $name, callable $operation, ?string $key = null): mixed
    {
        return $this->tracer->trace(
            name: $name,
            callback: static function (SpanInterface $span) use ($operation, $key): mixed {
                $span->setAttribute('cache.system', 'psr16');

                if ($key !== null) {
                    $span->setAttribute('cache.item.key', $key);
                }

                return $operation($span);
            },
            traceKind: TraceKind::Client,
        );
    }
}
