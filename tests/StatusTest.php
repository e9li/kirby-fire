<?php

namespace E9li\Fire\Tests;

use E9li\Fire\PagesCache;

class StatusTest extends TestCase
{
    public function testInactiveWithoutCacheConfig(): void
    {
        $this->app();

        $this->assertSame(['active' => false, 'count' => null], PagesCache::status());
    }

    public function testActiveWithEmptyCache(): void
    {
        $this->app([
            'options' => [
                'cache' => ['pages' => ['active' => true]],
            ],
        ]);

        $this->assertSame(['active' => true, 'count' => 0], PagesCache::status());
    }

    public function testHasDetectsCachedPages(): void
    {
        $this->app([
            'options' => [
                'cache' => ['pages' => ['active' => true]],
            ],
        ]);

        $home = site()->page('home');
        $this->assertFalse(PagesCache::has($home, null));

        kirby()->cache('pages')->set('home.latest.html', 'cached html');

        $this->assertTrue(PagesCache::has($home, null));
        // language entries are separate cache ids
        $this->assertFalse(PagesCache::has($home, 'de'));
    }

    public function testCountsFileCacheEntries(): void
    {
        $this->app([
            'options' => [
                'cache' => ['pages' => ['active' => true]],
            ],
        ]);

        // writing through the cache API lands in the real file cache root
        kirby()->cache('pages')->set('home.latest.html', 'cached html');
        kirby()->cache('pages')->set('about.latest.html', 'cached html');

        $this->assertSame(['active' => true, 'count' => 2], PagesCache::status());
    }
}
