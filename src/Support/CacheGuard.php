<?php

namespace PeopleInside\PowCaptcha\Support;

use Illuminate\Contracts\Cache\Repository;

/**
 * Helpers on top of Flarum's cache with the semantics this extension needs:
 * fixed-window counters and an atomic "first time only" marker.
 */
class CacheGuard
{
    public function __construct(private readonly Repository $cache)
    {
    }

    /** Increments a fixed-window counter (the TTL is set when the key is created). */
    public function hit(string $key, int $ttl): int
    {
        if ($this->cache->add($key, 1, $ttl)) {
            return 1;
        }

        $value = $this->cache->increment($key);

        if (is_int($value)) {
            return $value;
        }

        return max(1, (int) $this->cache->get($key, 1));
    }

    public function count(string $key): int
    {
        return (int) $this->cache->get($key, 0);
    }

    /**
     * Returns true only for the first caller of a given key (single-use markers).
     *
     * `Repository::pull()` is get-then-forget and therefore NOT atomic: parallel
     * requests could all consume the same value. We use a real lock when the cache
     * store offers one, and fall back to the store's native `add()` otherwise.
     * Contention or lock failure is reported as "not first" (fail closed).
     */
    public function once(string $key, int $ttl): bool
    {
        $store = method_exists($this->cache, 'getStore') ? $this->cache->getStore() : null;

        if ($store !== null && method_exists($store, 'lock')) {
            $lock = $store->lock($key . ':lock', 5);

            if (! $lock->get()) {
                return false;
            }

            try {
                if ($this->cache->has($key)) {
                    return false;
                }

                $this->cache->put($key, 1, $ttl);

                return true;
            } finally {
                $lock->release();
            }
        }

        return (bool) $this->cache->add($key, 1, $ttl);
    }
}
