<?php

declare(strict_types=1);

namespace Acta\Oidc\Cache;

use Acta\Oidc\SystemClock;
use DateInterval;
use Override;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache living in the memory of the process.
 *
 * It is the default cache of the package: enough for a command-line tool or a
 * long-running worker. A PHP-FPM application should pass a shared cache instead, so
 * that the provider metadata, the keys and the tokens survive the request.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{mixed, int|null}> value and expiry timestamp */
    private array $entries = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $this->entries[$key][0] : $default;
    }

    #[Override]
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $now = $this->clock->now();
        $expiry = match (true) {
            $ttl === null => null,
            is_int($ttl) => $now->getTimestamp() + $ttl,
            default => $now->add($ttl)->getTimestamp(),
        };

        if ($expiry !== null && $expiry <= $now->getTimestamp()) {
            unset($this->entries[$key]);

            return true;
        }

        $this->entries[$key] = [$value, $expiry];

        return true;
    }

    #[Override]
    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    #[Override]
    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    #[Override]
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) || is_int($key)) {
                $this->set((string) $key, $value, $ttl);
            }
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    #[Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    #[Override]
    public function has(string $key): bool
    {
        if (!isset($this->entries[$key])) {
            return false;
        }

        $expiry = $this->entries[$key][1];

        if ($expiry !== null && $expiry <= $this->clock->now()->getTimestamp()) {
            unset($this->entries[$key]);

            return false;
        }

        return true;
    }
}
