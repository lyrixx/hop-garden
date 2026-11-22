<?php

namespace App\Tests\Unit\DataSource;

use App\DataSource\DataSourceCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class DataSourceCacheTest extends TestCase
{
    public function testAnAnswerIsComputedOnceUntilItExpires(): void
    {
        $clock = new MockClock('2026-12-01 07:00');
        $cache = new DataSourceCache(new ArrayAdapter(clock: $clock), false);
        $calls = 0;
        $compute = static function () use (&$calls): int {
            return ++$calls;
        };

        self::assertSame(1, $cache->get('trefle.search.rose', 60, $compute));
        $clock->modify('+59 seconds');
        self::assertSame(1, $cache->get('trefle.search.rose', 60, $compute));
        $clock->modify('+1 second');
        self::assertSame(2, $cache->get('trefle.search.rose', 60, $compute), 'Expired.');
    }

    public function testTheDemoModeNeverServesALiveAnswerNorTheOtherWayRound(): void
    {
        $pool = new ArrayAdapter();

        self::assertSame('live', (new DataSourceCache($pool, false))->get('trefle.search.rose', 60, static fn (): string => 'live'));
        self::assertSame('demo', (new DataSourceCache($pool, true))->get('trefle.search.rose', 60, static fn (): string => 'demo'));
        self::assertSame('live', (new DataSourceCache($pool, false))->get('trefle.search.rose', 60, static fn (): string => 'recomputed'));
    }
}
