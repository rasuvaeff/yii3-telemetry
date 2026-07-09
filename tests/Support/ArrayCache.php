<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * Minimal array-backed PSR-16 cache for tests.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->store[$key] ?? $default;
        }

        return $result;
    }

    #[\Override]
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->store[$key] = $value;
        }

        return true;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->store[$key]);
        }

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }
}
