<?php

namespace Langsys\Symfony\Cache;

use Langsys\SDK\Cache\CacheInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Bridges the SDK's bespoke cache contract onto a PSR-6 pool so the
 * translation catalog lives in the application's configured cache
 * (cache.app by default, or any dedicated pool) instead of the SDK's own
 * file/redis drivers.
 *
 * clear() only evicts keys written through this adapter (tracked in an index
 * entry) — never the whole pool, which may be shared with the application.
 *
 * The SDK interface predates type declarations, so parameters stay untyped
 * to satisfy it.
 */
class Psr6CacheAdapter implements CacheInterface
{
    private const INDEX_KEY = '__key_index';

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly string $prefix = 'langsys.',
        private readonly int $defaultTtl = 3600,
    ) {
    }

    public function get($key)
    {
        $item = $this->pool->getItem($this->_poolKey($key));

        return $item->isHit() ? $item->get() : null;
    }

    public function set($key, $value, $ttl = 3600)
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $this->_indexKey($key);

        $item = $this->pool->getItem($this->_poolKey($key))->set($value);

        if ($ttl > 0) {
            $item->expiresAfter($ttl);
        }

        return $this->pool->save($item);
    }

    public function has($key)
    {
        return $this->pool->hasItem($this->_poolKey($key));
    }

    public function delete($key)
    {
        return $this->pool->deleteItem($this->_poolKey($key));
    }

    public function clear()
    {
        $index = $this->get(self::INDEX_KEY) ?? [];

        foreach ($index as $key) {
            $this->pool->deleteItem($this->_poolKey($key));
        }

        $this->pool->deleteItem($this->_poolKey(self::INDEX_KEY));

        return true;
    }

    private function _indexKey(string $key): void
    {
        if ($key === self::INDEX_KEY) {
            return;
        }

        $index = $this->get(self::INDEX_KEY) ?? [];

        if (!in_array($key, $index, true)) {
            $index[] = $key;
            // The index itself never expires — it must outlive every entry.
            $this->pool->save($this->pool->getItem($this->_poolKey(self::INDEX_KEY))->set($index));
        }
    }

    /** PSR-6 forbids {}()/\@: in keys; the SDK's keys and our prefix may contain them. */
    private function _poolKey(string $key): string
    {
        return str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', $this->prefix . $key);
    }
}
