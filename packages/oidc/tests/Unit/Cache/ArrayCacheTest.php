<?php

declare(strict_types=1);

namespace Acta\Oidc\Tests\Unit\Cache;

use Acta\Oidc\Cache\ArrayCache;
use Acta\Oidc\Tests\Fixture\FrozenClock;
use DateInterval;
use PHPUnit\Framework\TestCase;

final class ArrayCacheTest extends TestCase
{
    public function testValuesAreKeptUntilTheyExpire(): void
    {
        $clock = new FrozenClock();
        $cache = new ArrayCache($clock);

        self::assertTrue($cache->set('forever', ['a' => 1]));
        self::assertTrue($cache->set('minute', 'x', 60));
        self::assertTrue($cache->set('hour', 'y', new DateInterval('PT1H')));
        self::assertTrue($cache->set('gone', 'z', 0));

        self::assertSame(['a' => 1], $cache->get('forever'));
        self::assertTrue($cache->has('minute'));
        self::assertFalse($cache->has('gone'));
        self::assertSame('default', $cache->get('gone', 'default'));

        $clock->advance('+60 seconds');

        self::assertFalse($cache->has('minute'));
        self::assertNull($cache->get('minute'));
        self::assertSame('y', $cache->get('hour'));

        $clock->advance('+1 hour');

        self::assertSame(['forever' => ['a' => 1], 'hour' => null], $cache->getMultiple(['forever', 'hour']));
    }

    public function testEntriesAreReplacedDeletedAndCleared(): void
    {
        $cache = new ArrayCache();

        $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3], 60);
        $cache->set('a', 'new');
        // Overwriting with an expired lifetime removes the entry.
        $cache->set('b', 'expired', -1);

        self::assertSame(['a' => 'new', 'b' => null, 'c' => 3], $cache->getMultiple(['a', 'b', 'c']));

        self::assertTrue($cache->delete('a'));
        self::assertTrue($cache->deleteMultiple(['c', 'missing']));
        self::assertSame(['a' => 'x', 'c' => 'x'], $cache->getMultiple(['a', 'c'], 'x'));

        $cache->set('d', 4);
        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('d'));
    }
}
