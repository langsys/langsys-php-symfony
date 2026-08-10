<?php

namespace Langsys\Symfony\Tests\Cache;

use Langsys\Symfony\Cache\Psr6CacheAdapter;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Ported from langsys/laravel-sdk's LaravelCacheAdapterTest. The load-bearing
 * property is that clear() only evicts what this adapter wrote: the pool
 * defaults to cache.app, which the host application also uses.
 */
class Psr6CacheAdapterTest extends PhpUnitTestCase
{
    private ArrayAdapter $pool;
    private Psr6CacheAdapter $cache;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter(storeSerialized: false);
        $this->cache = new Psr6CacheAdapter($this->pool);
    }

    public function testReturnsNullForAMissingKey(): void
    {
        $this->assertNull($this->cache->get('nope'));
    }

    public function testRoundTripsAValue(): void
    {
        $this->cache->set('catalog', ['Home' => 'Inicio']);

        $this->assertSame(['Home' => 'Inicio'], $this->cache->get('catalog'));
    }

    public function testHasReflectsPresence(): void
    {
        $this->assertFalse($this->cache->has('catalog'));

        $this->cache->set('catalog', 'x');

        $this->assertTrue($this->cache->has('catalog'));
    }

    public function testDeleteRemovesOnlyTheGivenKey(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        $this->cache->delete('a');

        $this->assertNull($this->cache->get('a'));
        $this->assertSame(2, $this->cache->get('b'));
    }

    public function testClearEvictsEverythingItWrote(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        $this->cache->clear();

        $this->assertNull($this->cache->get('a'));
        $this->assertNull($this->cache->get('b'));
    }

    public function testClearLeavesForeignPoolEntriesAlone(): void
    {
        // cache.app is shared with the host app — clearing the translation
        // catalog must not flush the application's own cache.
        $foreign = $this->pool->getItem('app.session')->set('keep me');
        $this->pool->save($foreign);
        $this->cache->set('catalog', 'x');

        $this->cache->clear();

        $this->assertTrue($this->pool->getItem('app.session')->isHit());
        $this->assertNull($this->cache->get('catalog'));
    }

    public function testClearAlsoDropsItsOwnIndex(): void
    {
        $this->cache->set('a', 1);
        $this->cache->clear();
        $this->cache->set('b', 2);

        // A stale index would resurrect 'a' as a clear() target forever.
        $this->cache->clear();

        $this->assertNull($this->cache->get('b'));
    }

    public function testSanitizesCharactersPsr6ForbidsInKeys(): void
    {
        // PSR-6 reserves {}()/\@: — the SDK's own keys contain several.
        $this->cache->set('translations:{es-ES}/nav', 'ok');

        $this->assertSame('ok', $this->cache->get('translations:{es-ES}/nav'));
    }

    public function testAppliesThePrefixToPoolKeys(): void
    {
        $cache = new Psr6CacheAdapter($this->pool, 'custom.');
        $cache->set('catalog', 'x');

        $this->assertTrue($this->pool->getItem('custom.catalog')->isHit());
    }

    public function testDistinctPrefixesDoNotCollide(): void
    {
        $one = new Psr6CacheAdapter($this->pool, 'one.');
        $two = new Psr6CacheAdapter($this->pool, 'two.');

        $one->set('catalog', 'from-one');
        $two->set('catalog', 'from-two');

        $this->assertSame('from-one', $one->get('catalog'));
        $this->assertSame('from-two', $two->get('catalog'));
    }

    public function testStoresFalseAndZeroWithoutTreatingThemAsMisses(): void
    {
        $this->cache->set('flag', false);
        $this->cache->set('count', 0);

        $this->assertFalse($this->cache->get('flag'));
        $this->assertSame(0, $this->cache->get('count'));
    }
}
